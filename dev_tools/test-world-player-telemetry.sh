#!/bin/bash
# Oracle test: a running modded world's player count is REAL -- written by the PhValheim
# Companion on the dedicated server, read by playerMonitor, shown by the dashboard (2.58).
# requires: container
#
# WHAT IT GUARDS. "The active player count should be REAL data, not inferred from the logs"
# (the maintainer, 2026-10-23). The Companion that ships in the image now writes the server's
# own roster (ZNet.GetPlayerList) every 5s to
#   <world>/game/BepInEx/data/PhValheim.Companion/player_stats.json
# and playerMonitor copies it into worlds.player_count / player_count_at / player_count_source.
# No synthetic file here: the fixture world is really started and the file must come from the
# game process. Then the world is stopped and the observation must go, because a count on a
# world that is not running is not information (it showed under an "Updating" pill).
#
# PRECONDITION the test establishes itself: the world's installed Companion must be the one in
# this image. installCustomModsConfigsPatchers() copies it at UPDATE time, so a world built by
# an older image still runs the old DLL; if the md5s differ the test updates the world first.
#
# Usage:  dev_tools/test-world-player-telemetry.sh [container] [stoppedModdedWorldWithMods]

C="${1:-phvalheim-dev}"
W="${2:-midgard}"
pass=0; fail=0
ok()  { echo "  PASS  $1"; pass=$((pass+1)); }
bad() { echo "  FAIL  $1"; [ -n "$2" ] && echo "        $2"; fail=$((fail+1)); }
sql() { docker exec "$C" mysql -N -e "$1" phvalheim 2>/dev/null; }
waitMode() { local want="$1" n="$2" i; for i in $(seq 1 "$n"); do [ "$(sql "SELECT mode FROM worlds WHERE name='$W'")" = "$want" ] && return 0; sleep 5; done; return 1; }

worldDir="/opt/stateful/games/valheim/worlds/$W/game"
statsFile="$worldDir/BepInEx/data/PhValheim.Companion/player_stats.json"
tickFile="$worldDir/BepInEx/data/PhValheim.TickMonitor/tick_stats.json"
imageDll="/opt/stateless/games/valheim/custom_plugins/PhValheimCompanion/PhValheimCompanion.dll"
worldDll="$worldDir/BepInEx/plugins/PhValheimCompanion/PhValheimCompanion.dll"

echo "=== real player count from the Companion ($C, world $W) ==="

mode=$(sql "SELECT mode FROM worlds WHERE name='$W'"); vanilla=$(sql "SELECT IFNULL(vanilla,0) FROM worlds WHERE name='$W'")
nmods=$(sql "SELECT COUNT(*) FROM world_mods WHERE world_id=(SELECT id FROM worlds WHERE name='$W')")
[ "$mode" = "stopped" ] && [ "$vanilla" = "0" ] && [ "${nmods:-0}" -ge 1 ] || { echo "HARNESS: $W must be a stopped modded world with mods (mode=$mode vanilla=$vanilla mods=$nmods)"; exit 2; }
docker exec "$C" supervisorctl status phvalheim | grep -q RUNNING || { echo "HARNESS: the engine is not running"; exit 2; }
cleanup() {
	sql "UPDATE worlds SET mode='stop' WHERE name='$W' AND mode='running'" >/dev/null
	waitMode stopped 36 || true
}
trap cleanup EXIT

# The Companion in the image writes telemetry; the one in the world must be that one.
imgMd5=$(docker exec "$C" md5sum "$imageDll" 2>/dev/null | cut -c1-32)
wMd5=$(docker exec "$C" md5sum "$worldDll" 2>/dev/null | cut -c1-32)
docker exec "$C" sh -c "tr -d '\\000' < '$imageDll' | grep -aq player_stats.json" && ok "the image's Companion carries the telemetry writer" || bad "the image's Companion carries the telemetry writer" "no player_stats.json string in $imageDll"
if [ "$imgMd5" != "$wMd5" ]; then
	echo "  (world's Companion ${wMd5:-missing} != image's $imgMd5: updating the world first)"
	sql "UPDATE worlds SET mode='update' WHERE name='$W'"
	sleep 10
	waitMode stopped 180 || { bad "the preparatory world update ended" "mode=$(sql "SELECT mode FROM worlds WHERE name='$W'")"; echo; echo "$pass passed, $fail failed"; exit 1; }
	wMd5=$(docker exec "$C" md5sum "$worldDll" 2>/dev/null | cut -c1-32)
fi
[ "$imgMd5" = "$wMd5" ] && ok "the world runs the image's Companion ($imgMd5)" || bad "the world runs the image's Companion" "world $wMd5 vs image $imgMd5"

# A stale file from an earlier session must not be mistaken for this one's.
docker exec "$C" rm -f "$statsFile"
t0=$(date +%s)
sql "UPDATE worlds SET mode='start' WHERE name='$W'"
waitMode running 60 && ok "the world started (mode running after $(( $(date +%s) - t0 ))s)" || { bad "the world started" "mode=$(sql "SELECT mode FROM worlds WHERE name='$W'")"; echo; echo "$pass passed, $fail failed"; exit 1; }

# The game loads for a while after 'running'; give the Companion up to 3 minutes.
for i in $(seq 1 36); do docker exec "$C" test -f "$statsFile" && break; sleep 5; done
if docker exec "$C" test -f "$statsFile"; then
	ok "the Companion wrote player_stats.json ($(( $(date +%s) - t0 ))s after start)"
else
	bad "the Companion wrote player_stats.json within 3 minutes" "$(docker exec "$C" sh -c "tr -d '\\000' < /opt/stateful/logs/valheimworld_$W.log | grep -aiE 'PlayerTelemetry|Companion' | tail -3")"
	echo; echo "$pass passed, $fail failed"; exit 1
fi
body=$(docker exec "$C" cat "$statsFile")
echo "  file: $(echo "$body" | tr -d '\n' | cut -c1-160)"
echo "$body" | jq -e '.source=="companion" and (.player_count|type)=="number" and (.players|type)=="array" and (.version|test("^1\\.0\\.[5-9]"))' >/dev/null 2>&1 \
	&& ok "the file is the documented shape (source companion, numeric player_count, players array, version >= 1.0.5)" \
	|| bad "the file is the documented shape" "$(echo "$body" | tr -d '\n' | cut -c1-200)"
[ "$(echo "$body" | jq -r '.player_count')" = "0" ] && ok "an empty world reports player_count 0 (no fixture client is connected)" || bad "an empty world reports 0" "$(echo "$body" | jq -r '.player_count')"

# It keeps writing: the mtime must move.
m1=$(docker exec "$C" stat -c %Y "$statsFile"); sleep 12; m2=$(docker exec "$C" stat -c %Y "$statsFile")
[ "$m2" -gt "$m1" ] && ok "the file is rewritten every few seconds (mtime moved $((m2-m1))s in 12s)" || bad "the file is rewritten every few seconds" "mtime did not move"

# Cross-check against the other server-side plugin's view of the same server.
tcount=$(docker exec "$C" jq -r '.player_count' "$tickFile" 2>/dev/null)
[ "$tcount" = "0" ] && ok "control: TickMonitor's tick_stats.json agrees (player_count 0)" || echo "  NOTE  tick_stats.json player_count=${tcount:-absent} (TickMonitor not comparable here)"

# playerMonitor turns it into the row the dashboard reads.
docker exec -u phvalheim "$C" /opt/stateless/engine/tools/playerMonitor >/dev/null 2>&1
row=$(sql "SELECT IFNULL(player_count,-1), IFNULL(player_count_source,''), IFNULL(TIMESTAMPDIFF(SECOND, player_count_at, NOW()),-1) FROM worlds WHERE name='$W'" | tr '\t' '|')
case "$row" in 0\|companion\|*) ok "playerMonitor recorded count 0 from source 'companion'" ;; *) bad "playerMonitor recorded count 0 from source 'companion'" "row=$row" ;; esac
age=${row##*|}
[ "$age" -ge 0 ] && [ "$age" -le 120 ] && ok "the observation time is the file's (${age}s old)" || bad "the observation time is current" "age=$age"

# The dashboard's server-rendered row shows it, as a real zero.
html=$(docker exec "$C" php -r 'echo @file_get_contents("http://127.0.0.1:8081/index.php");' 2>/dev/null)
rowHtml=$(echo "$html" | python3 -c 'import sys,re; h=sys.stdin.read(); m=re.search(r"<tr data-world=\"%s\"[^>]*>(.*?)</tr>" % re.escape(sys.argv[1]), h, re.S); print(m.group(1) if m else "")' "$W")
echo "$rowHtml" | grep -qE 'class="status-players world-players-value"[^>]*><svg class="status-players-glyph".*</svg>0<' && ok "the dashboard pill reads 'Running [player glyph] 0' (server render)" || bad "the dashboard pill reads 'Running [player glyph] 0'" "$(echo "$rowHtml" | grep -oE 'status-badge[^<]*<[^<]*<[^<]*' | head -1 | tr -s ' ' | cut -c1-160)"

# Stop: the observation must go, not linger.
sql "UPDATE worlds SET mode='stop' WHERE name='$W'"
waitMode stopped 36 && ok "the world stopped" || bad "the world stopped" "mode=$(sql "SELECT mode FROM worlds WHERE name='$W'")"
docker exec -u phvalheim "$C" /opt/stateless/engine/tools/playerMonitor >/dev/null 2>&1
row=$(sql "SELECT IFNULL(player_count,-1), IFNULL(player_count_at,'NULL') FROM worlds WHERE name='$W'" | tr '\t' '|')
[ "$row" = "0|NULL" ] && ok "after the stop the observation is cleared (count 0, time NULL)" || bad "after the stop the observation is cleared" "row=$row"
html=$(docker exec "$C" php -r 'echo @file_get_contents("http://127.0.0.1:8081/index.php");' 2>/dev/null)
echo "$html" | python3 -c 'import sys,re; h=sys.stdin.read(); m=re.search(r"<tr data-world=\"%s\"[^>]*>(.*?)</tr>" % re.escape(sys.argv[1]), h, re.S); sys.exit(0 if m and "status-players" not in m.group(1) else 1)' "$W" \
	&& ok "the stopped world's pill carries no count" || bad "the stopped world's pill carries no count"

echo
echo "$pass passed, $fail failed"
[ "$fail" = 0 ]
