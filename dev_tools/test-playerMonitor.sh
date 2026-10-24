#!/bin/bash
# Oracle test: playerMonitor reads the Companion's player_stats.json -- and ONLY that (2.58).
#
# WHAT IT GUARDS. The count is real now: the PhValheim Companion on each modded world's
# dedicated server writes its roster to <world>/game/BepInEx/data/PhValheim.Companion/
# player_stats.json every 5s and playerMonitor copies player_count out of it. The rules that
# make that safe are all here:
#
#   - a FRESH file with a numeric count is an observation (source 'companion', time = mtime);
#   - a STALE file, a missing file, unparseable JSON or a non-numeric count is UNKNOWN
#     (count 0, player_count_at NULL, source 'none') -- never a confident zero, because a
#     recent zero is the one thing updateApplier's isIdle() accepts as permission to restart
#     a world;
#   - the freshness window is PHV_PLAYER_STATS_MAX_AGE (default 60s) and nothing else;
#   - there is NO switch any more (2.58 retired settings.playerCountsEnabled): a fresh file
#     is always read;
#   - a world that is not running loses whatever it last observed (the dashboard showed a
#     count under an "Updating" pill, 2026-10-23).
#
# Until 2.58 this test exercised a log parser (PlayFab "now N player(s)" lines, "Connections N"
# heartbeats, socket arithmetic). That code is gone -- the maintainer's verdict was real data or
# nothing -- and so are those cases. The live half is test-world-player-telemetry.sh, which
# starts a real world and watches the Companion write the file.
#
# HOW. The script is run with its config source, its database writes and its paths rewritten by
# sed: SQL writes become echo lines this test can read, the stats path points into a temp dir.

set -u
TOOL="$(dirname "$0")/../container/engine/tools/playerMonitor"
TMP=$(mktemp -d)
trap 'rm -rf "$TMP"' EXIT
pass=0
fail=0

# Run the monitor against one synthetic world. $1 world name, $2 enabled (1/0), $3 max age
# (empty = the script's default). The stats file is $TMP/stats/<world>.json; the world log is
# $TMP/valheimworld_<world>.log (may be absent: the count must not depend on it).
runMon() {
	local worldName="$1" enabled="${2:-1}" maxAge="${3:-}"
	mkdir -p "$TMP/stats"
	sed -e 's#^source /opt/stateless.*#:#' \
	    -e "s#^countsEnabled=.*#countsEnabled=$enabled#" \
	    -e "s#^logDir=.*#logDir=\"$TMP\"#" \
	    -e "s#^worldRows=.*#worldRows=\$(printf '%s\\\\t%s' '$worldName' '1')#" \
	    -e "s#^\tstatsFile=.*#\tstatsFile=\"$TMP/stats/$worldName.json\"#" \
	    -e 's#^SQL "UPDATE worlds SET player_count=0, player_count_at=NULL, player_count_source=.none. WHERE mode<>.running.*#echo "NONRUNNING-CLEAR"#' \
	    -e 's#^\t\tSQL "UPDATE worlds SET player_count=0, player_count_at=NULL.*#\t\techo "COUNT=0 SOURCE=none"#' \
	    -e 's#^\tSQL "UPDATE worlds SET player_count=\$count, player_count_at=.\$sqlStamp., player_count_source=.companion.*#\techo "COUNT=$count SOURCE=companion AT=$sqlStamp"#' \
	    -e 's#^\t\tSQL "UPDATE worlds SET join_code=.\$joinCode.*#\t\techo "JOINCODE=$joinCode"#' \
	    "$TOOL" > "$TMP/mon.sh"
	if [ -n "$maxAge" ]; then PHV_PLAYER_STATS_MAX_AGE="$maxAge" bash "$TMP/mon.sh" 2>/dev/null
	else bash "$TMP/mon.sh" 2>/dev/null; fi
}

check() {
	local name="$1" expected="$2" actual="$3"
	if [ "$actual" = "$expected" ]; then
		echo "  PASS  $name"; pass=$((pass + 1))
	else
		echo "  FAIL  $name"; echo "          expected: $expected"; echo "          actual:   $actual"; fail=$((fail + 1))
	fi
}

stats() { # $1 world, $2 json body, $3 optional "touch -d" age spec
	mkdir -p "$TMP/stats"; printf '%s\n' "$2" > "$TMP/stats/$1.json"
	[ -n "${3:-}" ] && touch -d "$3" "$TMP/stats/$1.json"
}
countLine() { grep -oE 'COUNT=[0-9]+ SOURCE=[a-z]+( AT=[0-9-]+ [0-9:]+)?' | head -1; }

echo "playerMonitor: the Companion's player_stats.json is the count"
echo

# The harness must be able to SEE its own rewrites: the sed anchors above track the script's
# exact lines, and a drifted anchor turns every case below into a silent pass-by-absence.
grep -q 'echo "COUNT=$count SOURCE=companion' "$TMP/mon.sh" 2>/dev/null || runMon probe >/dev/null
check "harness: the count write is rewritten (anchor still matches)" "1" "$(grep -c 'echo "COUNT=$count SOURCE=companion' "$TMP/mon.sh")"
check "harness: the unknown write is rewritten (anchor still matches)" "2" "$(grep -c 'echo "COUNT=0 SOURCE=none"' "$TMP/mon.sh")"
check "harness: the stats path is rewritten" "1" "$(grep -c "statsFile=\"$TMP/stats/" "$TMP/mon.sh")"

# 1. fresh file, numeric count -> the observation, timestamped from the file
stats fresh '{"timestamp_utc":"x","source":"companion","version":"1.0.5","player_count":2,"players":[{"name":"A","character_id":"1:2"},{"name":"B","character_id":"3:4"}]}'
expectAt=$(date -d "@$(stat -c %Y "$TMP/stats/fresh.json")" "+%Y-%m-%d %H:%M:%S")
check "fresh file -> count 2 from companion, time = file mtime" "COUNT=2 SOURCE=companion AT=$expectAt" "$(runMon fresh | countLine)"

# 2. zero is a real observation when the file is fresh (an empty running world)
stats zero '{"player_count":0,"players":[]}'
check "fresh file with 0 -> a REAL zero (source companion)" "COUNT=0 SOURCE=companion AT=$(date -d "@$(stat -c %Y "$TMP/stats/zero.json")" "+%Y-%m-%d %H:%M:%S")" "$(runMon zero | countLine)"

# 3. stale file -> unknown, not the stale number and not a zero observation
stats stale '{"player_count":4,"players":[]}' '5 minutes ago'
check "stale file (5 min) -> unknown" "COUNT=0 SOURCE=none" "$(runMon stale | countLine)"

# 4. the window is PHV_PLAYER_STATS_MAX_AGE: 90s old is stale by default, current under 120
stats aged '{"player_count":3,"players":[]}' '90 seconds ago'
check "90s-old file -> unknown under the default window (60s)" "COUNT=0 SOURCE=none" "$(runMon aged | countLine)"
check "90s-old file -> read under PHV_PLAYER_STATS_MAX_AGE=120 (the window is the gate)" "COUNT=3 SOURCE=companion AT=$(date -d "@$(stat -c %Y "$TMP/stats/aged.json")" "+%Y-%m-%d %H:%M:%S")" "$(runMon aged 1 120 | countLine)"

# 5. missing file -> unknown
check "no file -> unknown" "COUNT=0 SOURCE=none" "$(runMon nofile | countLine)"

# 6. broken file -> unknown
stats broken '{"player_count": '
check "unparseable JSON -> unknown" "COUNT=0 SOURCE=none" "$(runMon broken | countLine)"
stats text '{"player_count":"two","players":[]}'
check "non-numeric count -> unknown" "COUNT=0 SOURCE=none" "$(runMon text | countLine)"

# 7. the count does not depend on the world LOG (no valheimworld_<w>.log exists for any case above)
check "control: no world log existed for the fresh case, and the count was still read" "0" "$(ls "$TMP"/valheimworld_fresh.log 2>/dev/null | wc -l)"

# 8. there is no opt-out any more: the script never consults a switch (2.58 retired it)
stats gate '{"player_count":5,"players":[]}'
check "no switch: the script does not mention countsEnabled/playerCountsEnabled" "0" "$(grep -c 'countsEnabled' "$TOOL")"
check "gate ON: the same file is read (control)" "COUNT=5 SOURCE=companion AT=$(date -d "@$(stat -c %Y "$TMP/stats/gate.json")" "+%Y-%m-%d %H:%M:%S")" "$(runMon gate 1 | countLine)"

# 9. the join code still comes from the log, and is NOT gated by the opt-out
printf '%s\n' '10/23/2026 12:00:00: Starting to load scene: start.unity' \
               '10/23/2026 12:00:30: Session "jc" with join code 654321 is registered' > "$TMP/valheimworld_jc.log"
check "join code is written from the log" "JOINCODE=654321" "$(runMon jc 1 | grep -oE 'JOINCODE=[0-9]+')"

# 10. non-running worlds lose their last observation, every tick, before the loop
check "the non-running clear runs each tick" "NONRUNNING-CLEAR" "$(runMon fresh | grep -oE 'NONRUNNING-CLEAR' | head -1)"

# 11. the unknown write really nulls the timestamp (the raw SQL text, not the echo stand-in)
sed -e 's#^source /opt/stateless.*#SQL() { echo "SQL $*"; }#' \
    -e 's#^countsEnabled=.*#countsEnabled=1#' \
    -e "s#^logDir=.*#logDir=\"$TMP\"#" \
    -e "s#^\tstatsFile=.*#\tstatsFile=\"$TMP/stats/nofile.json\"#" \
    -e "s#^worldRows=.*#worldRows=\$(printf '%s\\\\t%s' 'nofile' '0')#" \
    "$TOOL" > "$TMP/raw.sh"
out=$(bash "$TMP/raw.sh" 2>/dev/null | grep "player_count_source='none'" | grep "name='nofile'")
check "unknown write: player_count_at=NULL in the real SQL" "1" "$(echo "$out" | grep -c "player_count_at=NULL")"

echo
echo "  $pass passed, $fail failed"
[ "$fail" -eq 0 ]
