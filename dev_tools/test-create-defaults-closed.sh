#!/bin/bash
# Oracle test: a world created the way the engine creates one must come up CLOSED.
#
# THE BUG: db_sets.php inserts only (mode,status,name,external_endpoint,seed). It never wrote
# `public` or `citizens`, so every new world inherited the column default -- public=0, meaning
# "enforce the access list" -- with an empty list. Valheim ignores an empty permitted list, so
# the world booted WIDE OPEN while its Access tab called it restricted. That is where the
# production world Jotunheimdallingus came from.
#
# WHY IT INSERTS THE ROW DIRECTLY: driving the real create endpoint would kick off a full
# SteamCMD install (~GBs, minutes) and leave a half-built world behind. The row is inserted
# with EXACTLY the column list db_sets.php uses, so the state under test is identical -- and
# the state, not the installer, is what decides who can connect. The insert is checked against
# the real statement below so it cannot quietly drift from it.
#
# Usage:  dev_tools/test-create-defaults-closed.sh [container]

CONTAINER="${1:-phvalheim-dev}"
REPO="$(cd "$(dirname "$0")/.." && pwd)"
WORLD="ztest_create_$$"
SAVEDIR="/opt/stateful/games/valheim/worlds/$WORLD/game/.config/unity3d/IronGate/Valheim"
SENTINEL="V_76561197960265728"

pass=0; fail=0
check() {
    if [ "$2" = "1" ]; then pass=$((pass+1)); echo "  PASS  $1"
    else fail=$((fail+1)); echo "  FAIL  $1${3:+ -- $3}"; fi
}
sql() { docker exec "$CONTAINER" /opt/stateless/engine/tools/sql "$1"; }

cleanup() {
    sql "DELETE FROM worlds WHERE name='$WORLD'" >/dev/null 2>&1
    docker exec "$CONTAINER" rm -rf "/opt/stateful/games/valheim/worlds/$WORLD" 2>/dev/null
}
trap cleanup EXIT

echo "(container $CONTAINER, scratch world \"$WORLD\")"

echo
echo "Guard: the insert used here still matches the real one in db_sets.php"
# If db_sets.php starts setting `public` itself, this test's premise is stale and it must be
# revisited rather than silently continuing to test a shape the code no longer uses.
real=$(grep -c "INSERT INTO worlds (mode,status,name,external_endpoint,seed)" "$REPO/container/nginx/www/includes/db_sets.php")
check "db_sets.php still inserts exactly those five columns" "$([ "$real" = "1" ] && echo 1 || echo 0)" \
    "the create INSERT changed -- re-check what this test simulates"

echo
echo "A world created with no access columns set:"
sql "DELETE FROM worlds WHERE name='$WORLD'" >/dev/null 2>&1
sql "INSERT INTO worlds (mode,status,name,external_endpoint,seed) VALUES ('create','Down','$WORLD','test.invalid','1234')" >/dev/null

row=$(sql "SELECT IFNULL(public,'NULL'), IFNULL(citizens,'NULL') FROM worlds WHERE name='$WORLD'")
gotPublic=$(echo "$row" | cut -f1)
gotCit=$(echo "$row" | cut -f2)
check "inherits public=0 (= access list ENFORCED)" "$([ "$gotPublic" = "0" ] && echo 1 || echo 0)" "got '$gotPublic'"
check "and an empty citizens list" "$([ "$gotCit" = "NULL" ] || [ -z "$gotCit" ] && echo 1 || echo 0)" "got '$gotCit'"

docker exec "$CONTAINER" mkdir -p "$SAVEDIR"
docker exec "$CONTAINER" /opt/stateless/games/valheim/scripts/syncAccessLists.sh "$WORLD" >/dev/null 2>&1

echo
echo "...must still render a CLOSED permitted list:"
docker exec "$CONTAINER" grep -q "^$SENTINEL\$" "$SAVEDIR/permittedlist.txt" 2>/dev/null
check "the fail-closed placeholder is present" "$([ $? -eq 0 ] && echo 1 || echo 0)" \
    "a newly created world would be joinable by ANYONE"

n=$(docker exec "$CONTAINER" sh -c "grep -c '^[^/]' '$SAVEDIR/permittedlist.txt' 2>/dev/null; true" | tr -d '[:space:]')
check "exactly one entry, so Valheim enforces the list" "$([ "$n" = "1" ] && echo 1 || echo 0)" "found $n"

echo
echo "Control: the same world switched to OPEN renders no entries"
# Proves the closed result above comes from the access model and not from the script emitting
# the placeholder unconditionally -- which would lock out every deliberately-open world.
sql "UPDATE worlds SET public=1 WHERE name='$WORLD'" >/dev/null
docker exec "$CONTAINER" /opt/stateless/games/valheim/scripts/syncAccessLists.sh "$WORLD" >/dev/null 2>&1
n=$(docker exec "$CONTAINER" sh -c "grep -c '^[^/]' '$SAVEDIR/permittedlist.txt' 2>/dev/null; true" | tr -d '[:space:]')
check "open world renders 0 entries" "$([ "$n" = "0" ] && echo 1 || echo 0)" "found $n"

echo
echo "Guard: the create endpoint now sets the access model explicitly"
# The placeholder makes the DEFAULT safe; this makes the stored row say what the operator
# actually picked, so the Access tab is not merely coincidentally correct.
c=$(grep -c "setPublic(\$pdo, \$world, \$accessOpen ? 1 : 0)" "$REPO/container/nginx/www/admin/adminAPI.php")
check "createWorldJson calls setPublic with the chosen model" "$([ "$c" = "1" ] && echo 1 || echo 0)"
d=$(grep -c "isset(\$input\['accessOpen'\]) ? (int)\$input\['accessOpen'\] : 0" "$REPO/container/nginx/www/admin/adminAPI.php")
check "and an ABSENT accessOpen field defaults to restricted" "$([ "$d" = "1" ] && echo 1 || echo 0)"

echo
echo "$pass passed, $fail failed"
echo "NOTE: the create endpoint is exercised statically here, not end to end -- driving it for"
echo "real means a full SteamCMD world install. The rendered-state cases above ARE end to end."
[ "$fail" -eq 0 ] || exit 1
