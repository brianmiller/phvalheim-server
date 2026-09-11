#!/bin/bash
# Oracle test: the ADMIN dashboard's Launch button must respect a crossplay join link.
#
# THE BUG: admin/index.php and adminAPI.php both built a vanilla world's Launch href as an
# unconditional `+connect <host>:<port>`. Enabling crossplay makes Valheim open a PlayFab
# server, which cannot be joined by IP at all -- so the admin button failed silently while the
# public card (already fixed) worked. Two code paths, both wrong, and they are separate from
# the two public ones: four copies of the same decision.
#
# The backend is DERIVED from the world's log, so this test fabricates the log lines Valheim
# writes. That is the same source getWorldNetBackend()/getWorldJoinCode() read in production --
# the world does not need to be genuinely running for the decision under test to be real.
#
# THE CASE THAT MATTERS MOST is the last one: admin and public must return the SAME href for
# the same world. That is the property that actually broke, and a per-endpoint assertion would
# not have caught it -- each side looked self-consistent.
#
# Usage:  dev_tools/test-admin-crossplay-launch.sh [container] [world]

CONTAINER="${1:-phvalheim-dev}"
WORLD="${2:-northlands}"
LOG="/opt/stateful/logs/valheimworld_$WORLD.log"

pass=0; fail=0
check() {
    if [ "$2" = "1" ]; then pass=$((pass+1)); echo "  PASS  $1"
    else fail=$((fail+1)); echo "  FAIL  $1${3:+ -- $3}"; fi
}
sql() { docker exec "$CONTAINER" /opt/stateless/engine/tools/sql "$1"; }

ORIG=$(sql "SELECT mode, IFNULL(vanilla,0), IFNULL(crossplay,0) FROM worlds WHERE name='$WORLD'")
ORIG_MODE=$(echo "$ORIG" | cut -f1); ORIG_VAN=$(echo "$ORIG" | cut -f2); ORIG_CROSS=$(echo "$ORIG" | cut -f3)
docker exec "$CONTAINER" cp "$LOG" /tmp/orig.log 2>/dev/null
restore() {
    sql "UPDATE worlds SET mode='$ORIG_MODE', vanilla=$ORIG_VAN, crossplay=$ORIG_CROSS WHERE name='$WORLD'" >/dev/null 2>&1
    docker exec "$CONTAINER" sh -c "cp /tmp/orig.log '$LOG' 2>/dev/null || rm -f '$LOG'; rm -f /tmp/orig.log" 2>/dev/null
}
trap restore EXIT

# Ask the ADMIN poll payload what it would render.
adminHref() {
    docker exec "$CONTAINER" curl -s "http://127.0.0.1:8081/adminAPI.php?action=getWorlds" 2>/dev/null \
      | docker exec -i "$CONTAINER" php -r '
          $d = json_decode(stream_get_contents(STDIN), true);
          foreach (($d["worlds"] ?? []) as $w) {
              if ($w["name"] === $argv[1]) { echo $w["launchHref"] === null ? "NULL" : $w["launchHref"]; return; }
          }
          echo "WORLD-NOT-FOUND";
      ' -- "$WORLD"
}

setState() {  # $1=mode $2=vanilla $3=log body
    sql "UPDATE worlds SET mode='$1', vanilla=$2 WHERE name='$WORLD'" >/dev/null
    docker exec "$CONTAINER" sh -c "printf '%s\n' \"\$1\" > '$LOG'; chown phvalheim:phvalheim '$LOG'" -- "$3"
}

echo "(container $CONTAINER, world \"$WORLD\")"

echo
echo "Case 1: running vanilla CROSSPLAY world with a join code"
setState running 1 "Opened PlayFab server
Session \"$WORLD\" registered with join code 123456"
h=$(adminHref)
check 'admin Launch uses -joincode' "$(echo "$h" | grep -q 'steam://run/892970//-joincode 123456' && echo 1 || echo 0)" "$h"
check 'and does NOT use +connect' "$(echo "$h" | grep -q '+connect' && echo 0 || echo 1)" "$h"

echo
echo "Case 2: running vanilla NON-crossplay world"
# Must keep working. A fix that sent every vanilla world to -joincode would break the common case.
setState running 1 "Opened Steam server"
h=$(adminHref)
check 'admin Launch uses +connect' "$(echo "$h" | grep -q '+connect' && echo 1 || echo 0)" "$h"
check 'and does NOT use -joincode' "$(echo "$h" | grep -q 'joincode' && echo 0 || echo 1)" "$h"

echo
echo "Case 3: crossplay world up, but no join code registered yet"
# There is genuinely nothing to launch with. A link with an empty argument is worse than none.
setState running 1 "Opened PlayFab server"
h=$(adminHref)
check 'href is null so the UI can show a non-link state' "$([ "$h" = "NULL" ] && echo 1 || echo 0)" "$h"

echo
echo "Case 4: stopped world"
setState stopped 1 "Opened PlayFab server
Session registered with join code 999999"
h=$(adminHref)
check 'href is null (backend is unknowable when not running)' "$([ "$h" = "NULL" ] && echo 1 || echo 0)" "$h"

echo
echo "Case 5: a MODDED world still gets phvalheim://"
setState running 0 "Opened PlayFab server"
h=$(adminHref)
check 'modded world uses phvalheim://' "$(echo "$h" | grep -q '^phvalheim://' && echo 1 || echo 0)" "$h"

echo
echo "Case 6: THE REGRESSION -- admin and public must agree for the SAME world"
# This is the property that actually broke. Each endpoint looked self-consistent; they simply
# disagreed with each other, and only a cross-check catches that.
setState running 1 "Opened PlayFab server
Session \"$WORLD\" registered with join code 777888"
adminH=$(adminHref)
publicH=$(docker exec "$CONTAINER" php -r '
    chdir("/opt/stateless/nginx/www/public");
    require_once "/opt/stateless/nginx/www/includes/db_gets.php";
    $i = getVanillaJoinInfo($pdo, $argv[1], "valheim.example.com", 25001, true);
    echo $i["href"] === null ? "NULL" : $i["href"];
' -- "$WORLD" 2>/dev/null)
check 'both resolve to the same -joincode link' \
    "$([ -n "$adminH" ] && [ "${adminH#*-joincode }" = "${publicH#*-joincode }" ] && echo 1 || echo 0)" \
    "admin=$adminH shared=$publicH"

echo
echo "Control: the admin href actually CHANGES with the backend"
# If adminHref() were broken and always returned the same string, cases 1-5 could still line up
# by accident. This proves the value tracks the log.
setState running 1 "Opened Steam server"
a=$(adminHref)
setState running 1 "Opened PlayFab server
Session x registered with join code 555444"
b=$(adminHref)
check 'a Steam world and a PlayFab world give different links' \
    "$([ "$a" != "$b" ] && echo 1 || echo 0)" "steam=$a playfab=$b"

echo
echo "$pass passed, $fail failed"
[ "$fail" -eq 0 ] || exit 1
