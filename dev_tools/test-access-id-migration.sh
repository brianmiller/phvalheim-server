#!/bin/bash
# Oracle test for the 2.39 -> 2.40 access-id migration.
#
# Builds a genuine PRE-2.40 fixture (bare SteamID64s, the shape a 2.39 database actually
# has), runs the real migration, and asserts on what ends up IN THE DATABASE and IN THE
# FILES. It never asserts on the migration's own summary output -- that would pass whether
# or not the rows changed.
#
# Includes controls: a case that must fail if the migration is a no-op, and a check that
# the public UI's LIKE match still finds a player after the rewrite.
#
# Usage: dev_tools/test-access-id-migration.sh [container]

C="${1:-phvalheim-dev}"
pass=0; fail=0
check () { # $1=name $2=ok(1/0) $3=detail
	if [ "$2" = "1" ]; then pass=$((pass+1)); echo "  PASS  $1"
	else fail=$((fail+1)); echo "  FAIL  $1${3:+ -- $3}"; fi
}
q () { docker exec "$C" mysql -N -e "$1" 2>/dev/null; }

WORLD="idmigtest"

cleanup () { q "DELETE FROM phvalheim.worlds WHERE name='$WORLD';" >/dev/null 2>&1; }
trap cleanup EXIT
cleanup

echo
echo "Setting up a PRE-2.40 fixture (bare ids, exactly what 2.39 stored)"
# A deliberately mixed list: two bare Steam ids, one ALREADY prefixed (must not be
# double-prefixed), one console id in long form, and one piece of junk that must survive.
q "INSERT INTO phvalheim.worlds (name, citizens, admins, banned, public)
   VALUES ('$WORLD',
           '76561198000000001 76561198000000002 V_76561198000000003',
           'Steam_76561198000000004',
           '76561198000000005 not-an-id',
           0);"
before_citizens=$(q "SELECT citizens FROM phvalheim.worlds WHERE name='$WORLD';")
echo "  before: $before_citizens"

echo
echo "Case 0: CONTROL -- the fixture really is in the old format"
echo "$before_citizens" | grep -q "^76561198000000001" && ok=1 || ok=0
check "fixture starts with an UNPREFIXED id" "$ok" "$before_citizens"

echo
echo "Running the real migration"
docker exec "$C" php /opt/stateless/engine/tools/migrateAccessIds.php >/tmp/idmig.out 2>&1
echo "  exit=$?"

after_citizens=$(q "SELECT citizens FROM phvalheim.worlds WHERE name='$WORLD';")
after_admins=$(q "SELECT admins FROM phvalheim.worlds WHERE name='$WORLD';")
after_banned=$(q "SELECT banned FROM phvalheim.worlds WHERE name='$WORLD';")
echo "  after citizens: $after_citizens"
echo "  after admins  : $after_admins"
echo "  after banned  : $after_banned"

echo
echo "Case 1: bare Steam ids gain the V_ prefix"
[ "$after_citizens" = "V_76561198000000001 V_76561198000000002 V_76561198000000003" ] && ok=1 || ok=0
check "citizens fully canonicalised" "$ok" "got: $after_citizens"

echo
echo "Case 2: an ALREADY prefixed id is not double-prefixed"
echo "$after_citizens" | grep -q "V_V_" && bad=1 || bad=0
check "no V_V_ anywhere" "$([ $bad -eq 0 ] && echo 1 || echo 0)" "got: $after_citizens"

echo
echo "Case 3: long platform name -> display prefix"
[ "$after_admins" = "V_76561198000000004" ] && ok=1 || ok=0
check "Steam_x became V_x" "$ok" "got: $after_admins"

echo
echo "Case 4: an unparseable entry is KEPT, not dropped"
echo "$after_banned" | grep -q "not-an-id" && ok=1 || ok=0
check "junk entry survived the migration" "$ok" "got: $after_banned"
echo "$after_banned" | grep -q "V_76561198000000005" && ok=1 || ok=0
check "...and the real id beside it was still converted" "$ok" "got: $after_banned"

echo
echo "Case 5: idempotent -- running it again changes nothing"
docker exec "$C" php /opt/stateless/engine/tools/migrateAccessIds.php >/dev/null 2>&1
again=$(q "SELECT citizens FROM phvalheim.worlds WHERE name='$WORLD';")
[ "$again" = "$after_citizens" ] && ok=1 || ok=0
check "second run is a no-op" "$ok" "before=$after_citizens after=$again"

echo
echo "Case 6: the public UI still finds a player by BARE id after the rewrite"
# getMyWorlds() does: WHERE citizens LIKE '%<bare steamid>%'. V_7656... still contains
# 7656..., so the match must survive. If it did not, upgrading would hide every world
# from every player.
found=$(q "SELECT COUNT(*) FROM phvalheim.worlds WHERE name='$WORLD' AND citizens LIKE '%76561198000000001%';")
[ "$found" = "1" ] && ok=1 || ok=0
check "LIKE '%bare id%' still matches" "$ok" "count=$found"

echo
echo "Case 7: the one-time notice flag was set to SHOW"
flag=$(q "SELECT accessIdNoticeShown FROM phvalheim.settings;")
[ "$flag" = "0" ] && ok=1 || ok=0
check "accessIdNoticeShown = 0 after a real conversion" "$ok" "got: $flag"

echo
echo "Case 8: CONTROL -- a clean database does NOT arm the notice"
q "UPDATE phvalheim.settings SET accessIdNoticeShown = 1;"
cleanup   # remove the fixture so there is nothing left to convert
docker exec "$C" php /opt/stateless/engine/tools/migrateAccessIds.php >/dev/null 2>&1
flag2=$(q "SELECT accessIdNoticeShown FROM phvalheim.settings;")
[ "$flag2" = "1" ] && ok=1 || ok=0
check "flag stays 1 when nothing needed converting" "$ok" "got: $flag2"

echo
echo "$pass passed, $fail failed"
[ $fail -eq 0 ]
