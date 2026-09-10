#!/bin/bash
# Oracle test: what actually stops a world being created open-but-labelled-restricted.
#
# HISTORY, because this file's premise inverted once and the reason matters:
#
#   db_sets.php inserts only (mode,status,name,external_endpoint,seed). It never wrote `public`
#   or `citizens`, so every new world inherited the column default -- public=0, "enforce the
#   access list" -- with an empty list. Valheim applies permittedlist.txt only when it has
#   ENTRIES, so the world booted WIDE OPEN while its Access tab called it restricted. That is
#   where the production world Jotunheimdallingus came from.
#
#   A placeholder entry was added at render time to make that state fail closed, and this test
#   asserted the rendered list came out CLOSED. The placeholder was then dropped (the chosen ID
#   turned out not to be as obviously unowned as claimed). So an enforced-empty list is once
#   again genuinely OPEN, and the protection is entirely UP-FRONT: creation demands a player ID,
#   and saving an empty enforced list is refused.
#
# This file therefore tests the guards that really exist, and asserts the honest outcome of the
# state they prevent -- rather than continuing to claim a closure that no longer happens.
#
# Usage:  dev_tools/test-create-access-guards.sh [container]

CONTAINER="${1:-phvalheim-dev}"
REPO="$(cd "$(dirname "$0")/.." && pwd)"
WORLD="ztest_create_$$"
SAVEDIR="/opt/stateful/games/valheim/worlds/$WORLD/game/.config/unity3d/IronGate/Valheim"

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
echo "Guard: the insert this simulates still matches the real one in db_sets.php"
real=$(grep -c "INSERT INTO worlds (mode,status,name,external_endpoint,seed)" "$REPO/container/nginx/www/includes/db_sets.php")
check "db_sets.php still inserts exactly those five columns" "$([ "$real" = "1" ] && echo 1 || echo 0)" \
    "the create INSERT changed -- re-check what this test simulates"

echo
echo "The bare-insert state (what a world used to be born as):"
sql "DELETE FROM worlds WHERE name='$WORLD'" >/dev/null 2>&1
sql "INSERT INTO worlds (mode,status,name,external_endpoint,seed) VALUES ('create','Down','$WORLD','test.invalid','1234')" >/dev/null
row=$(sql "SELECT IFNULL(public,'NULL'), IFNULL(citizens,'NULL') FROM worlds WHERE name='$WORLD'")
check "still inherits public=0 (= access list ENFORCED)" "$([ "$(echo "$row" | cut -f1)" = "0" ] && echo 1 || echo 0)" "got '$row'"

docker exec "$CONTAINER" mkdir -p "$SAVEDIR"
out=$(docker exec "$CONTAINER" /opt/stateless/games/valheim/scripts/syncAccessLists.sh "$WORLD" 2>&1)

echo
echo "...renders an OPEN list, and says so at the top of its voice:"
# The honest assertion. Nothing closes this state any more, so the test must not pretend
# otherwise -- it verifies the operator is TOLD, which is the protection that exists.
n=$(docker exec "$CONTAINER" sh -c "grep -c '^[^/]' '$SAVEDIR/permittedlist.txt' 2>/dev/null; true" | tr -d '[:space:]')
check "permittedlist.txt has 0 entries (Valheim will not restrict)" "$([ "$n" = "0" ] && echo 1 || echo 0)" "found $n"
check "a WARNING is logged" "$(echo "$out" | grep -q 'WARNING' && echo 1 || echo 0)" "$out"
check "and it says ANYONE CAN JOIN, not that the world is closed" \
    "$(echo "$out" | grep -q 'ANYONE CAN JOIN' && echo 1 || echo 0)" "$out"

echo
echo "Control: a deliberately OPEN world renders the same list but does NOT warn"
# Proves the warning tracks the access MODE and is not printed for every empty file. Without
# this, a script that warned unconditionally would pass everything above.
sql "UPDATE worlds SET public=1 WHERE name='$WORLD'" >/dev/null
out=$(docker exec "$CONTAINER" /opt/stateless/games/valheim/scripts/syncAccessLists.sh "$WORLD" 2>&1)
check "open world is silent" "$(echo "$out" | grep -q 'ANYONE CAN JOIN' && echo 0 || echo 1)" "$out"

echo
echo "The guards that actually prevent this state:"
src="$REPO/container/nginx/www/admin/adminAPI.php"
c=$(grep -c "A restricted world needs at least one player ID" "$src")
check "createWorld refuses a restricted world with no player ID" "$([ "$c" = "1" ] && echo 1 || echo 0)" "found $c"
c=$(grep -c "it would let everyone in rather than nobody" "$src")
check "saveCitizens refuses an empty enforced list, with correct wording" "$([ "$c" = "1" ] && echo 1 || echo 0)" "found $c"
c=$(grep -c "setPublic(\$pdo, \$world, \$accessOpen ? 1 : 0)" "$src")
check "createWorld records the chosen access model explicitly" "$([ "$c" = "1" ] && echo 1 || echo 0)" "found $c"
c=$(grep -c "isset(\$input\['accessOpen'\]) ? (int)\$input\['accessOpen'\] : 0" "$src")
check "an ABSENT accessOpen defaults to restricted" "$([ "$c" = "1" ] && echo 1 || echo 0)" "found $c"

echo
echo "No placeholder is written anywhere"
# The dropped placeholder must stay dropped: it is the kind of thing that gets reintroduced by
# someone re-reading the old commit message.
c=$(grep -rc "76561197960265728" "$REPO/container/" 2>/dev/null | grep -v ':0$' | wc -l)
check "no injected placeholder ID in the shipped tree" "$([ "$c" = "0" ] && echo 1 || echo 0)" "found in $c file(s)"

echo
echo "$pass passed, $fail failed"
echo "NOTE: the create endpoint's validation is asserted statically here; it is exercised for"
echo "real by test-create-requires-steamid.js. The rendered-state cases above ARE end to end."
[ "$fail" -eq 0 ] || exit 1
