#!/bin/bash
# Oracle test: the engine's event log (2.56) -- phvlog, operations, and the conversion.
#
# WHY: every action PhValheim took went into one shared text file as an untyped line. 2.56
# routes them through phvlog(), which prints the classic line AND records an event row tagged
# with the ambient world (PHV_WORLD) and operation (PHV_OP). This pins:
#   1. phvlog prints the same stdout line shape the log viewer colours, with the tool's name
#   2. the row carries source, level (mapped from the tag), world, op and the message
#   3. SQL escaping: a quote, a backslash and a $(...) in a message reach the row verbatim and
#      never break out of the literal (the message is interpolated once, into a quoted string)
#   4. a database that is down costs the row, never the step (exit 0, stdout line still printed)
#   5. phvOpBegin/phvOpEnd open and close an operation and scope PHV_OP to it
#   6. NOTHING in the engine or its tools still logs with a raw echo of the old form, and the
#      main loop opens an operation for every command kind and closes it at the loop's end
#
# The mysql client is replaced by a stub on PATH that records the SQL it was given, so the
# assertions read what WOULD have been executed. Mutation controls break the escaping and the
# level mapping and must fail.
#
# Usage: bash dev_tools/test-event-log.sh

set -u
ROOT="$(cd "$(dirname "$0")/.." && pwd)"
E="$ROOT/container/engine"
TMP="$(mktemp -d)"
trap 'rm -rf "$TMP"' EXIT
pass=0; fail=0
check() { if [ "$2" = "$3" ]; then pass=$((pass+1)); echo "  PASS  $1"; else fail=$((fail+1)); echo "  FAIL  $1"; echo "          want: $2"; echo "          got:  $3"; fi; }

# --- a mysql stub: appends every -e statement to a file; fails when asked to ---
mkdir -p "$TMP/bin/usr/bin"
cat > "$TMP/bin/usr/bin/mysql" <<'EOF'
#!/bin/bash
[ -n "${MYSQL_STUB_FAIL:-}" ] && exit 1
while [ $# -gt 0 ]; do if [ "$1" = "-e" ]; then shift; printf '%s\n' "$1" >> "$MYSQL_STUB_LOG"; fi; shift; done
exit 0
EOF
chmod +x "$TMP/bin/usr/bin/mysql"

# The helper hardcodes /usr/bin/mysql; run the cases in a copy of the conf with that path
# pointed at the stub. (Sourcing the real conf would also need the real database.)
run_case() {   # $1 = name, stdin = bash snippet; prints stdout; SQL lands in $TMP/$1.sql
	local name="$1" conf="$TMP/conf-$1.sh"
	sed "s#/usr/bin/mysql#$TMP/bin/usr/bin/mysql#g" "${CONF_SRC:-$E/includes/phvalheim-static.conf}" \
		| awk '/^function SQL\(\)/{p=1} p' > "$conf"    # from SQL() onward: the helpers, none of the paths above
	MYSQL_STUB_LOG="$TMP/$name.sql" bash -c "source '$conf'; $(cat)" 2>"$TMP/$name.err"
}

echo
echo "-- 1/2. phvlog: the stdout line and the row --"
out=$(run_case basic <<'EOF'
export PHV_WORLD="Midgard"
export PHV_LOG_SOURCE="updateApplier"
phvlog NOTICE "Update command received for world 'Midgard':"
phvlog WARNING "disk is 97% full"
phvlog FAIL "could not package"
phvlog INFO "detail line"
EOF
)
check 'stdout keeps the classic shape, with the tool as the source' "1" "$(printf '%s\n' "$out" | grep -c "^[A-Z][a-z][a-z] .* \[NOTICE : updateApplier\] Update command received for world 'Midgard':$")"
check 'WARNING and FAIL tags are printed as given' "2" "$(printf '%s\n' "$out" | grep -cE '\[(WARNING|FAIL) : updateApplier\]')"
sql="$TMP/basic.sql"
check 'four rows inserted' "4" "$(grep -c '^INSERT INTO phv_events' "$sql")"
check 'the row has source, level, world, NULL op and the message' "1" "$(grep -cF "VALUES ('updateApplier', 'notice', 'Midgard', NULL, 'Update command received for world \\'Midgard\\':', NULL)" "$sql")"
check 'WARNING maps to warn, FAIL to error, INFO to notice' "warn error notice" "$(sed -n '2,4p' "$sql" | sed -E "s/.*VALUES \('[^']*', '([a-z]+)'.*/\1/" | tr '\n' ' ' | sed 's/ $//')"

echo
echo "-- 3. escaping --"
run_case escape <<'EOF' > /dev/null
export PHV_WORLD="O'Neil"
phvlog ERROR "it's a \\ backslash and a $(echo hi) and a '); DROP TABLE phv_events; --"
EOF
sql="$TMP/escape.sql"
check 'one statement, not two (no breakout)' "1" "$(grep -c '^INSERT' "$sql"; true)"
check "the world's quote is escaped" "1" "$(grep -cF "'O\\'Neil'" "$sql")"
check 'the message quote, backslash and $(...) are literal in the SQL' "1" "$(grep -cF "it\\'s a \\\\ backslash and a hi and a \\'); DROP TABLE phv_events; --" "$sql")"
check 'the DROP never became its own statement' "0" "$(grep -c '^DROP' "$sql"; true)"

echo
echo "-- 4. a database that is down --"
out=$(MYSQL_STUB_FAIL=1 run_case down <<'EOF'
set -e
phvlog NOTICE "still printed"
echo "step continued rc=$?"
EOF
)
check 'the stdout line is still printed' "1" "$(printf '%s\n' "$out" | grep -c 'still printed')"
check 'the engine step continues under set -e' "1" "$(printf '%s\n' "$out" | grep -c 'step continued rc=0')"

echo
echo "-- 5. operations --"
out=$(run_case ops <<'EOF'
export PHV_WORLD="Midgard"
phvOpBegin update
echo "op=$PHV_OP"
phvlog NOTICE "inside"
phvOpEnd failed "world marked broken"
echo "after=${PHV_OP:-unset}"
phvlog NOTICE "outside"
EOF
)
sql="$TMP/ops.sql"
op=$(printf '%s\n' "$out" | sed -n 's/^op=//p')
check 'phvOpBegin exports an op id' "1" "$([ -n "$op" ] && echo 1 || echo 0)"
check 'an operation row is opened with world, kind and source' "1" "$(grep -cF "INSERT INTO phv_operations (op_id, world, kind, source) VALUES ('$op', 'Midgard', 'update', 'phvalheim')" "$sql")"
check 'the event inside carries the op id' "1" "$(grep -cF "'Midgard', '$op', 'inside'" "$sql")"
check 'phvOpEnd closes it with the outcome and summary' "1" "$(grep -cF "SET finished=NOW(3), outcome='failed', summary='world marked broken' WHERE op_id='$op'" "$sql")"
check '  and clears PHV_OP' "1" "$(printf '%s\n' "$out" | grep -c '^after=unset$')"
check 'the event after it has no op' "1" "$(grep -cF "'Midgard', NULL, 'outside'" "$sql")"
run_case mark <<'EOF' > /dev/null
phvWorldSessionMark "Nowhere"
EOF
check 'the session mark records the Valheim log offset as detail' "1" "$(grep -cF '{"session":true,"log_offset":0}' "$TMP/mark.sql")"

echo
echo "-- 6. the conversion is complete and the main loop is wired --"
raw=0
for f in "$E/phvalheim" "$E/includes/0-functions.sh" "$E"/tools/*; do
	[ -f "$f" ] || continue; head -1 "$f" | grep -q bash || continue
	n=$(grep -cE 'echo "(`date`|\$\(date\)) \[([A-Z]+ ?:)? ?phvalheim\]' "$f"); raw=$((raw+n))
	[ "$n" -gt 0 ] && echo "          raw in $(basename "$f"): $n"
done
check 'no raw "echo date [LEVEL : phvalheim]" line remains in the engine or its bash tools' "0" "$raw"
check 'the engine converted lines are many (the conversion did not silently skip)' "1" "$([ "$(grep -c '^\s*phvlog ' "$E/includes/0-functions.sh")" -ge 80 ] && echo 1 || echo 0)"
check 'every converted file sources the conf that defines phvlog' "0" "$(for f in "$E"/tools/*; do [ -f "$f" ] && grep -q 'phvlog ' "$f" && ! grep -q 'phvalheim-static.conf' "$f" && echo "$f"; done | wc -l)"
check 'the main loop exports the world and opens an operation per command kind' "1" "$(grep -cF 'create|delete|stop|start|update|repackage)' "$E/phvalheim")"
check '  with PHV_WORLD exported before it' "1" "$(grep -cF 'export PHV_WORLD="$worldName"' "$E/phvalheim")"
check '  and closes it with an outcome at the end of the loop body (deleted, broken, failed, ok)' "4" "$(grep -cE 'phvOpEnd (ok|failed) ' "$E/phvalheim")"
check '  a start records the Valheim session offset' "1" "$(grep -cF 'phvWorldSessionMark "$worldName"' "$E/phvalheim")"
check '  operations left open by a restart are closed at boot' "1" "$(grep -cF "outcome='interrupted'" "$E/phvalheim")"
check 'the tools name themselves' "4" "$(grep -l '^PHV_LOG_SOURCE=' "$E/tools/updateApplier" "$E/tools/worldBackup" "$E/tools/worldRestore" "$E/tools/playerMonitor" 2>/dev/null | wc -l)"
check 'the migration creates both tables' "2" "$(grep -cE 'CREATE TABLE phv_(events|operations)' "$E/dbUpdates/dbUpdate_2.56.sh")"
check 'retention runs from the rotater' "2" "$(grep -c 'DELETE FROM phv_events' "$E/tools/logRotater.sh")"

echo
echo "-- mutation controls -- each must break the case it covers --"
mut() {   # $1 label, $2 find, $3 replace, $4 case snippet, $5 grep -F pattern that the UNBROKEN run matches once
	local src="$E/includes/phvalheim-static.conf" m="$TMP/mut-$RANDOM.conf"
	if [ "$(grep -cF -- "$2" "$src")" != "1" ]; then fail=$((fail+1)); echo "  FAIL  mutation applies exactly once: $1"; return; fi
	python3 - "$src" "$m" "$2" "$3" <<'PY'
import sys; s=open(sys.argv[1]).read(); open(sys.argv[2],'w').write(s.replace(sys.argv[3], sys.argv[4]))
PY
	local name="mut$RANDOM"
	CONF_SRC="$m" run_case "$name" <<<"$4" > /dev/null
	check "$1 => breaks" "0" "$(grep -cF -- "$5" "$TMP/$name.sql"; true)"
}
mut "no quote escaping" "printf '%s' \"\$1\" | sed -e 's/\\\\/\\\\\\\\/g' -e \"s/'/\\\\\\\\'/g\"" "printf '%s' \"\$1\"" \
    'phvlog NOTICE "it'"'"'s"' "'it\\'s'"
mut "WARNING not mapped to warn" "WARN|WARNING)      lv=warn ;;" "WARN)              lv=warn ;;" \
    'phvlog WARNING "x"' "'warn'"
mut "op id never cleared" "	unset PHV_OP" "	:" \
    'export PHV_WORLD=w; phvOpBegin start; phvOpEnd ok done; phvlog NOTICE after' "NULL, 'after'"

echo
echo "$pass passed, $fail failed"
[ "$fail" -eq 0 ]
