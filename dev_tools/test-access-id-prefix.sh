#!/bin/bash
# Oracle test: CITIZENS, ADMINS and BANNED must all end up holding the prefixed form.
#
# WHY: Valheim's ZNet.ListContainsId() matches ONLY the single-letter display-prefix form
# (Steam -> "V_<steamid64>"). A bare 17-digit id in any of these files matches nothing, and the
# player is refused with a misleading "Banned". See docs/RELEASE-2.40-DESIGN.md.
#
# Until 2026-09-10 the database and the admin UI kept the operator's ORIGINAL text and only the
# file got the prefix -- so the Access tab showed a value that Valheim would never match, which
# is exactly the confusion the prefix exists to end. Now the canonical form is what gets stored,
# shown, and written.
#
# A bare id is still ACCEPTED as input (it is what steamid.io and a profile URL give you) and
# upgraded. That is the case that keeps this honest: a test that only checked "V_ in, V_ out"
# would pass on an implementation that simply rejected everything else.
#
# Usage:  dev_tools/test-access-id-prefix.sh [container] [world]

CONTAINER="${1:-phvalheim-dev}"
WORLD="${2:-acltest}"
BASE="http://127.0.0.1:8081"
SAVEDIR="/opt/stateful/games/valheim/worlds/$WORLD/game/.config/unity3d/IronGate/Valheim"

pass=0; fail=0
check() {
    if [ "$2" = "1" ]; then pass=$((pass+1)); echo "  PASS  $1"
    else fail=$((fail+1)); echo "  FAIL  $1${3:+ -- $3}"; fi
}
sql() { docker exec "$CONTAINER" /opt/stateless/engine/tools/sql "$1"; }
api() { docker exec "$CONTAINER" curl -s -X POST "$BASE/adminAPI.php?action=$1" -H 'Content-Type: application/json' -d "$2" 2>/dev/null; }

ORIG=$(sql "SELECT IFNULL(public,0), IFNULL(CONCAT('@',citizens),'N'), IFNULL(CONCAT('@',admins),'N'), IFNULL(CONCAT('@',banned),'N') FROM worlds WHERE name='$WORLD'")
restore() {
    p=$(echo "$ORIG" | cut -f1)
    sql "UPDATE worlds SET public=$p, citizens=NULL, admins=NULL, banned=NULL WHERE name='$WORLD'" >/dev/null 2>&1
}
trap restore EXIT

echo "(container $CONTAINER, world \"$WORLD\")"

BARE=76561198000000101
PREFIXED=V_76561198000000101

echo
echo "A BARE SteamID64 is accepted and upgraded, in all three lists:"
r=$(api saveCitizens "{\"world\":\"$WORLD\",\"citizens\":\"$BARE\",\"public\":0}")
check "citizens save accepted" "$(echo "$r" | grep -q '"success":true' && echo 1 || echo 0)" "$r"
# saveAdmins / saveBanned, each with its OWN body key -- there is no generic saveAccessList
# endpoint. Calling one silently returns a top-level error, which looked like a product bug
# until I checked the real action names.
r=$(api saveAdmins "{\"world\":\"$WORLD\",\"admins\":\"$BARE\"}")
check "admins save accepted" "$(echo "$r" | grep -q '"success":true' && echo 1 || echo 0)" "$r"
r=$(api saveBanned "{\"world\":\"$WORLD\",\"banned\":\"$BARE\"}")
check "banned save accepted" "$(echo "$r" | grep -q '"success":true' && echo 1 || echo 0)" "$r"

echo
echo "...and every one is STORED prefixed, not as typed:"
row=$(sql "SELECT IFNULL(citizens,''), IFNULL(admins,''), IFNULL(banned,'') FROM worlds WHERE name='$WORLD'")
i=1
for col in citizens admins banned; do
    v=$(echo "$row" | cut -f$i); i=$((i+1))
    check "$col = $PREFIXED" "$([ "$v" = "$PREFIXED" ] && echo 1 || echo 0)" "got '$v'"
done

echo
echo "...and reaches the FILE prefixed (what Valheim actually reads):"
for f in permittedlist adminlist bannedlist; do
    docker exec "$CONTAINER" grep -q "^$PREFIXED\$" "$SAVEDIR/$f.txt" 2>/dev/null
    check "$f.txt contains $PREFIXED" "$([ $? -eq 0 ] && echo 1 || echo 0)"
    # The bare form must NOT also be present: Valheim ignores it, so it is dead weight that
    # looks like an entry.
    docker exec "$CONTAINER" grep -q "^$BARE\$" "$SAVEDIR/$f.txt" 2>/dev/null
    check "$f.txt has no unmatched bare id" "$([ $? -ne 0 ] && echo 1 || echo 0)"
done

echo
echo "An ALREADY-prefixed id survives unchanged (no double prefixing):"
# V_V_... would be silently unmatchable, and is the obvious way a naive "just prepend V_" fix
# breaks every existing list on its first re-save.
api saveCitizens "{\"world\":\"$WORLD\",\"citizens\":\"$PREFIXED\",\"public\":0}" >/dev/null
v=$(sql "SELECT citizens FROM worlds WHERE name='$WORLD'")
check "stays $PREFIXED" "$([ "$v" = "$PREFIXED" ] && echo 1 || echo 0)" "got '$v'"

echo
echo "Junk is still rejected, and the message names the accepted forms:"
r=$(api saveCitizens "{\"world\":\"$WORLD\",\"citizens\":\"notanid\",\"public\":0}")
check "rejected" "$(echo "$r" | grep -q '"success":false' && echo 1 || echo 0)" "$r"
check "message shows the V_ form" "$(echo "$r" | grep -q 'V_76561197960287930' && echo 1 || echo 0)" "$r"

echo
echo "CONTROL: a bare id is genuinely different from a prefixed one"
# If canonicalAccessId() were a no-op the upgrade assertions above would be vacuous -- they
# would 'pass' by comparing a value to itself.
check "the two forms are not the same string" "$([ "$BARE" != "$PREFIXED" ] && echo 1 || echo 0)"
v=$(sql "SELECT citizens FROM worlds WHERE name='$WORLD'")
check "and what is stored is NOT the bare input" "$([ "$v" != "$BARE" ] && echo 1 || echo 0)" "got '$v'"

echo
echo "$pass passed, $fail failed"
[ "$fail" -eq 0 ] || exit 1
