#!/bin/bash
# Oracle test: crossplay can only be set on a VANILLA world.
#
# WHY (2026-09-10): -crossplay makes Valheim open a PlayFab server, which is reached by join
# code and has no host:port at all. The PhValheim client reaches a MODDED world through
# QuickConnect, whose config file is `world:host:port:password`. So a modded crossplay world
# starts perfectly and simply cannot be joined by the client.
#
# Scoped back to vanilla until the client can launch with -joincode (planned for 2.0.13).
# Note this is a CLIENT limitation, not a Valheim one -- see test-startWorld-args.sh, where the
# same assertion has now been correct in both directions.
#
# The argv gate lives in test-startWorld-args.sh. This file covers the two WRITE paths, which
# are separate code and separately reachable: saveWorldOptions and createWorld.
#
# Usage:  dev_tools/test-crossplay-vanilla-only.sh [container] [world]

CONTAINER="${1:-phvalheim-dev}"
WORLD="${2:-acltest}"
BASE="http://127.0.0.1:8081"
REPO="$(cd "$(dirname "$0")/.." && pwd)"

pass=0; fail=0
check() {
    if [ "$2" = "1" ]; then pass=$((pass+1)); echo "  PASS  $1"
    else fail=$((fail+1)); echo "  FAIL  $1${3:+ -- $3}"; fi
}
sql() { docker exec "$CONTAINER" /opt/stateless/engine/tools/sql "$1"; }
saveOptions() {
    docker exec "$CONTAINER" curl -s -X POST "$BASE/adminAPI.php?action=saveWorldOptions" \
        -H 'Content-Type: application/json' \
        -d "{\"world\":\"$WORLD\",\"vanilla\":$1,\"crossplay\":$2,\"listed\":0,\"password\":\"\",\"passwordPublic\":0,\"launchParams\":\"\"}" 2>/dev/null
}
storedCrossplay() { sql "SELECT IFNULL(crossplay,0) FROM worlds WHERE name='$WORLD'"; }

ORIG=$(sql "SELECT IFNULL(vanilla,0), IFNULL(crossplay,0) FROM worlds WHERE name='$WORLD'")
trap 'sql "UPDATE worlds SET vanilla=$(echo "$ORIG" | cut -f1), crossplay=$(echo "$ORIG" | cut -f2) WHERE name='"'"'$WORLD'"'"'" >/dev/null 2>&1' EXIT

echo "(container $CONTAINER, world \"$WORLD\")"

echo
echo "saveWorldOptions: a MODDED world cannot have crossplay"
# Enforced server-side, not just hidden in the UI -- the endpoint is reachable directly, and
# hiding a control does not stop anything from POSTing to it.
sql "UPDATE worlds SET vanilla=0, crossplay=0 WHERE name='$WORLD'" >/dev/null
r=$(saveOptions 0 1)
check "save succeeds (it is forced off, not rejected)" "$(echo "$r" | grep -q '"success":true' && echo 1 || echo 0)" "$r"
check "crossplay stored as 0" "$([ "$(storedCrossplay)" = "0" ] && echo 1 || echo 0)" "stored $(storedCrossplay)"

echo
echo "saveWorldOptions: a VANILLA world still can"
# THE CONTROL. Without it, an implementation that simply forced crossplay to 0 for every world
# would pass every other case here while quietly removing the feature.
r=$(saveOptions 1 1)
check "save succeeds" "$(echo "$r" | grep -q '"success":true' && echo 1 || echo 0)" "$r"
check "crossplay stored as 1" "$([ "$(storedCrossplay)" = "1" ] && echo 1 || echo 0)" "stored $(storedCrossplay)"

echo
echo "saveWorldOptions: switching vanilla -> modded clears an existing crossplay flag"
# The dangerous direction: a world that legitimately had crossplay, then gains mods. If the
# flag survived, the world would keep opening a PlayFab server the client cannot reach.
r=$(saveOptions 0 1)
check "crossplay cleared on the way to modded" "$([ "$(storedCrossplay)" = "0" ] && echo 1 || echo 0)" "stored $(storedCrossplay)"

echo
echo "createWorld and the forms agree with the endpoint"
c=$(grep -c 'setCrossplay($pdo, $world, ($isVanilla && !empty($vanillaOptions\[.crossplay.\])) ? 1 : 0)' "$REPO/container/nginx/www/admin/adminAPI.php")
check "createWorldJson gates crossplay on isVanilla" "$([ "$c" = "1" ] && echo 1 || echo 0)" "found $c"
c=$(grep -c 'crossplayOption' "$REPO/container/nginx/www/admin/new_world.php")
check "the create form hides the control for modded worlds" "$([ "$c" = "2" ] && echo 1 || echo 0)" "found $c"
# Hidden is not enough: a hidden-but-ticked box would keep POSTing crossplay:1.
c=$(grep -c "prop('checked', false)" "$REPO/container/nginx/www/admin/new_world.php")
check "and unticks it rather than only hiding it" "$([ "$c" -ge 1 ] && echo 1 || echo 0)" "found $c"
c=$(grep -c 'crossplayRow' "$REPO/container/nginx/www/admin/index.php")
check "the Settings > Options row is gated too" "$([ "$c" = "2" ] && echo 1 || echo 0)" "found $c"

echo
echo "$pass passed, $fail failed"
[ "$fail" -eq 0 ] || exit 1
