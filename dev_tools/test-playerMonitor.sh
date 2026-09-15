#!/bin/bash
#
# Oracle tests for engine/tools/playerMonitor's log parsing.
#
# Each case is a synthetic log with a KNOWN player count, chosen so that a broken parser
# gives a different answer than a correct one. That is the whole point -- an earlier version
# of this test would have passed against production logs while the parser was wrong, because
# the one world it checked happened to have a single (not doubled) disconnect and the bug
# was masked by the clamp at zero.
#
# Run: dev_tools/test-playerMonitor.sh

set -u

TOOL="$(dirname "$0")/../container/engine/tools/playerMonitor"
TMP=$(mktemp -d)
trap 'rm -rf "$TMP"' EXIT

pass=0
fail=0

# Run the parser's logic against one synthetic log. Stubs out the config source and the SQL
# writes so nothing touches a database.
runParser() {
	local logFile="$1" worldName="$2" crossplay="$3"

	sed -e 's#^source /opt/stateless.*#:#' \
	    -e "s#^logDir=.*#logDir=\"$(dirname "$logFile")\"#" \
	    -e "s#^worldRows=.*#worldRows=\$(printf '%s\\\\t%s' '$worldName' '$crossplay')#" \
	    -e 's#^\t\tSQL "UPDATE worlds SET player_count=0.*#\t\techo "COUNT=0 SOURCE=none"; continue#' \
	    -e 's#^\t\tSQL "UPDATE worlds SET player_count=\$count, player_count_at.*#\t\techo "COUNT=$count SOURCE=$source AT=$sqlStamp"#' \
	    -e 's#^\t\tSQL "UPDATE worlds SET player_count=\$count, player_count_source.*#\t\techo "COUNT=$count SOURCE=$source AT=none"#' \
	    "$TOOL" > "$TMP/parser.sh"

	bash "$TMP/parser.sh" 2>/dev/null
}

check() {
	local name="$1" expected="$2" actual="$3"
	if [ "$actual" = "$expected" ]; then
		echo "  PASS  $name"
		pass=$((pass + 1))
	else
		echo "  FAIL  $name"
		echo "          expected: $expected"
		echo "          actual:   $actual"
		fail=$((fail + 1))
	fi
}

echo "playerMonitor parser tests"
echo

# --- 1: crossplay world takes the LAST "now N player(s)" -----------------------------
cat > "$TMP/valheimworld_cross.log" <<'EOF'
09/15/2026 14:51:40: Player joined server "cross" that has join code 410153, now 1 player(s)
09/15/2026 15:31:54: Player joined server "cross" that has join code 410153, now 2 player(s)
09/15/2026 15:34:00: Player connection lost server "cross" that has join code 410153, now 1 player(s)
EOF
out=$(runParser "$TMP/valheimworld_cross.log" cross 1 | grep -oE 'COUNT=[0-9]+ SOURCE=[a-z]+')
check "crossplay: takes last absolute count" "COUNT=1 SOURCE=playfab" "$out"

# --- 2: crossplay timestamp comes from the LOG LINE, not from now --------------------
#
# If this ever reports the scan time, the staleness rule silently dies and auto-update
# waits forever on a world that emptied hours ago.
out=$(runParser "$TMP/valheimworld_cross.log" cross 1 | grep -oE 'AT=[0-9-]+ [0-9:]+')
check "crossplay: timestamp is the log line's" "AT=2026-09-15 15:34:00" "$out"

# --- 3: DOUBLED disconnect must decrement ONCE ---------------------------------------
#
# This is the regression guard. Valheim logs "Closing socket" twice per departure, the two
# copies differing only in the run of spaces after the timestamp. Heartbeat says 2, one
# player leaves => 1. A parser that fails to dedupe gets 0.
cat > "$TMP/valheimworld_dbl.log" <<'EOF'
09/15/2026 15:58:09:  Connections 2 ZDOS:2301143  sent:0 recv:699
09/15/2026 16:00:55:   Closing socket 76561190000000001
09/15/2026 16:00:55: Closing socket 76561190000000001
EOF
out=$(runParser "$TMP/valheimworld_dbl.log" dbl 0 | grep -oE 'COUNT=[0-9]+')
check "doubled 'Closing socket' decrements once" "COUNT=1" "$out"

# --- 4: an arrival after the heartbeat increments ------------------------------------
cat > "$TMP/valheimworld_arr.log" <<'EOF'
09/15/2026 15:58:09:  Connections 1 ZDOS:2301143  sent:0 recv:699
09/15/2026 16:00:55: Got connection SteamID 76561190000000002
EOF
out=$(runParser "$TMP/valheimworld_arr.log" arr 0 | grep -oE 'COUNT=[0-9]+ SOURCE=[a-z]+')
check "arrival after heartbeat increments" "COUNT=2 SOURCE=socket" "$out"

# --- 5: never go negative ------------------------------------------------------------
cat > "$TMP/valheimworld_neg.log" <<'EOF'
09/15/2026 15:58:09:  Connections 0 ZDOS:2301143  sent:0 recv:0
09/15/2026 16:00:55:   Closing socket 76561190000000003
09/15/2026 16:00:55: Closing socket 76561190000000003
EOF
out=$(runParser "$TMP/valheimworld_neg.log" neg 0 | grep -oE 'COUNT=[0-9]+')
check "count never goes negative" "COUNT=0" "$out"

# --- 6: bare "Connections N" (no ZDOS tail) is still read ----------------------------
cat > "$TMP/valheimworld_bare.log" <<'EOF'
09/15/2026 15:58:09:  Connections 0 ZDOS:2301143  sent:0 recv:0
09/15/2026 15:59:09: Connections 3
EOF
out=$(runParser "$TMP/valheimworld_bare.log" bare 0 | grep -oE 'COUNT=[0-9]+')
check "bare 'Connections N' shape is read" "COUNT=3" "$out"

# --- 7: a NON-crossplay world must ignore "now N player(s)" --------------------------
#
# And the mirror: a crossplay world must ignore "Connections N", which reads 0 with players
# connected. Getting this backwards reports every crossplay world as permanently empty.
cat > "$TMP/valheimworld_mix.log" <<'EOF'
09/15/2026 15:58:09:  Connections 2 ZDOS:2301143  sent:0 recv:699
09/15/2026 15:59:00: Player joined server "mix" that has join code 1, now 7 player(s)
EOF
out=$(runParser "$TMP/valheimworld_mix.log" mix 0 | grep -oE 'COUNT=[0-9]+ SOURCE=[a-z]+')
check "non-crossplay ignores playfab line" "COUNT=2 SOURCE=heartbeat" "$out"

out=$(runParser "$TMP/valheimworld_mix.log" mix 1 | grep -oE 'COUNT=[0-9]+ SOURCE=[a-z]+')
check "crossplay ignores Connections line" "COUNT=7 SOURCE=playfab" "$out"

# --- 8: no count line at all -> report nothing, not a confident zero ------------------
cat > "$TMP/valheimworld_empty.log" <<'EOF'
09/15/2026 15:58:09: Loading first scene!
EOF
out=$(runParser "$TMP/valheimworld_empty.log" empty 0 | grep -oE 'SOURCE=[a-z]+')
check "no count line -> source=none" "SOURCE=none" "$out"

echo
echo "  $pass passed, $fail failed"
[ "$fail" -eq 0 ]
