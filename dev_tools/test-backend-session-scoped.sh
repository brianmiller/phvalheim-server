#!/bin/bash
# Oracle test: the join method is read from the CURRENT session, and falls back to the column.
#
# THE BUG (2026-09-10): Valheim takes ~30 seconds from process start to "Opened <backend>
# server" -- it loads the world first. Measured on production:
#
#   16:49:54  Opened Steam server          <- previous session
#   16:52:01  Starting to load scene       <- restarted WITH crossplay
#   16:52:34  Opened PlayFab server        <- 33 seconds later
#
# getWorldNetBackend() searched the whole log tail, so during that window the LAST match was
# the previous session's `Opened Steam server`. A world restarted into crossplay reported
# `steam` and its card offered a +connect link that could not work. Same hazard in
# getWorldJoinCode(): a restarted world would hand out the PREVIOUS session's code.
#
# Two changes under test:
#   1. Both readers scope to the text after the last "Starting to load scene: start.unity".
#   2. worldIsPlayFab() falls back to the crossplay COLUMN when the session has logged nothing
#      yet -- which is what makes a restart look instant instead of wrong for half a minute.
#
# Usage:  dev_tools/test-backend-session-scoped.sh [container] [world]
#   The world's log is saved and restored.

CONTAINER="${1:-phvalheim-dev}"
WORLD="${2:-northlands}"
LOG="/opt/stateful/logs/valheimworld_$WORLD.log"

pass=0; fail=0
check() {
    if [ "$2" = "1" ]; then pass=$((pass+1)); echo "  PASS  $1"
    else fail=$((fail+1)); echo "  FAIL  $1${3:+ -- $3}"; fi
}
sql() { docker exec "$CONTAINER" /opt/stateless/engine/tools/sql "$1"; }

ORIG_CROSS=$(sql "SELECT IFNULL(crossplay,0) FROM worlds WHERE name='$WORLD'")
docker exec "$CONTAINER" sh -c "cp '$LOG' /tmp/bs.bak 2>/dev/null; true"
restore() {
    sql "UPDATE worlds SET crossplay=$ORIG_CROSS WHERE name='$WORLD'" >/dev/null 2>&1
    docker exec "$CONTAINER" sh -c "cp /tmp/bs.bak '$LOG' 2>/dev/null || rm -f '$LOG'; rm -f /tmp/bs.bak"
}
trap restore EXIT

writeLog() { docker exec "$CONTAINER" sh -c "printf '%s\n' \"\$1\" > '$LOG'; chown phvalheim:phvalheim '$LOG'" -- "$1"; }

# Ask PHP directly -- these are pure log/DB readers, no HTTP needed.
backend()  { docker exec "$CONTAINER" php -r 'require_once "/opt/stateless/nginx/www/includes/db_gets.php"; $b = getWorldNetBackend($argv[1]); echo $b === NULL ? "NULL" : $b;' -- "$WORLD" 2>/dev/null; }
joincode() { docker exec "$CONTAINER" php -r 'require_once "/opt/stateless/nginx/www/includes/db_gets.php"; $c = getWorldJoinCode($argv[1]); echo $c === NULL ? "NULL" : $c;' -- "$WORLD" 2>/dev/null; }
isPlayFab(){ docker exec "$CONTAINER" php -r 'require_once "/opt/stateless/nginx/www/includes/db_gets.php"; echo worldIsPlayFab($pdo, $argv[1]) ? "yes" : "no";' -- "$WORLD" 2>/dev/null; }

SCENE='09/11/2026 00:00:00: Loading: Starting to load scene: start.unity (169d7618616154c03be07e9ad3af5893)'

echo "(container $CONTAINER, world \"$WORLD\")"

echo
echo "THE BUG: restarted into crossplay, new session has not logged its backend yet"
# The previous session's Steam line is still in the tail. Scoping to the current session is
# what stops it winning.
writeLog "09/11/2026 00:00:00: Opened Steam server
some later chatter from the old session
$SCENE
09/11/2026 00:00:05: loading world"
check "backend is NULL, not the previous session's steam" "$([ "$(backend)" = "NULL" ] && echo 1 || echo 0)" "got $(backend)"
sql "UPDATE worlds SET crossplay=1 WHERE name='$WORLD'" >/dev/null
check "worldIsPlayFab falls back to the column -> yes" "$([ "$(isPlayFab)" = "yes" ] && echo 1 || echo 0)" "got $(isPlayFab)"

echo
echo "Once the session logs PlayFab, that is used"
writeLog "09/11/2026 00:00:00: Opened Steam server
$SCENE
09/11/2026 00:00:34: Opened PlayFab server"
check "backend = playfab" "$([ "$(backend)" = "playfab" ] && echo 1 || echo 0)" "got $(backend)"

echo
echo "THE OPPOSITE CASE: column says crossplay but the world has NOT restarted"
# The running session is still Steam and IS serving +connect. The session must win here, or the
# card would tell players to use a join code that does not exist. This is the case the whole
# "follow the running backend" rule exists for, and a naive column-only fix would break it.
writeLog "$SCENE
09/11/2026 00:00:34: Opened Steam server"
check "backend = steam (the session wins over the column)" "$([ "$(backend)" = "steam" ] && echo 1 || echo 0)" "got $(backend)"
check "worldIsPlayFab = no, despite crossplay=1" "$([ "$(isPlayFab)" = "no" ] && echo 1 || echo 0)" "got $(isPlayFab)"

echo
echo "Join code comes from the current session only"
writeLog "09/11/2026 00:00:00: Session \"$WORLD\" registered with join code 111111
$SCENE
09/11/2026 00:00:34: Opened PlayFab server
09/11/2026 00:00:36: Session \"$WORLD\" registered with join code 222222"
check "returns the new code" "$([ "$(joincode)" = "222222" ] && echo 1 || echo 0)" "got $(joincode)"

writeLog "09/11/2026 00:00:00: Session \"$WORLD\" registered with join code 111111
$SCENE
09/11/2026 00:00:34: Opened PlayFab server"
check "and NULL rather than the dead previous code" "$([ "$(joincode)" = "NULL" ] && echo 1 || echo 0)" "got $(joincode)"

echo
echo "A long-running world whose session marker has scrolled out of the tail"
# No marker at all: every Opened line still visible must belong to the current session, so the
# whole tail is the right answer. Treating a missing marker as an error would lose detection
# for exactly the worlds that have been up longest.
writeLog "09/11/2026 00:00:34: Opened PlayFab server
lots of later chatter with no scene marker"
check "backend still resolves = playfab" "$([ "$(backend)" = "playfab" ] && echo 1 || echo 0)" "got $(backend)"

echo
echo "CONTROL: the reader is not simply returning a constant"
writeLog "$SCENE
09/11/2026 00:00:34: Opened Steam server"
a=$(backend)
writeLog "$SCENE
09/11/2026 00:00:34: Opened PlayFab server"
b=$(backend)
check "steam and playfab logs give different answers" "$([ "$a" != "$b" ] && echo 1 || echo 0)" "both '$a'"

echo
echo "$pass passed, $fail failed"
[ "$fail" -eq 0 ] || exit 1
