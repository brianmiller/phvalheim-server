#!/bin/bash
# Oracle test: selecting the SAME plugin from both catalogues must not store two world_mods
# rows, and the operator must be told which copy was dropped.
#
# THE BUG THIS CATCHES: saveWorldModSelection() keyed its $wanted array by mod id, and both
# catalogues carry denikson/BepInExPack_Valheim as separate mods rows. Ticking both stored
# both. The INSTALL was fine -- install_rows() collapses per (owner, name) -- but every count
# that reads world_mods directly reported one too many:
#     admin/index.php:128 and adminAPI.php:769   world card modCount
#     admin/edit_world.php:279                   "Mods Running"
# So the UI claimed 8 mods running while 7 were on disk, and nothing told the operator their
# second pick had been overridden.
#
# WHY THIS TEST CAN SEE IT: it asserts on the world_mods ROW COUNT after a save, and on the
# count agreeing with the install plan. A test that only checked the install plan passes under
# the bug -- the plan was always correct; the stored selection was not.
#
# Usage: dev_tools/test-duplicate-plugin-save.sh [container]
set -uo pipefail
CONTAINER="${1:-phvalheim-dev}"
WORLD="dupsavetest$$"
pass=0; fail=0
ok() { pass=$((pass+1)); echo "  PASS  $1"; }
no() { fail=$((fail+1)); echo "  FAIL  $1${2:+ -- $2}"; }
q()  { docker exec "$CONTAINER" mysql -uroot phvalheim -N -e "$1" 2>/dev/null; }

cleanup() {
    local wid
    wid=$(q "SELECT id FROM worlds WHERE name='$WORLD';")
    [ -n "$wid" ] && q "DELETE FROM world_mods WHERE world_id=$wid; DELETE FROM worlds WHERE id=$wid;"
}
trap cleanup EXIT

echo
echo "=== duplicate-plugin save guard ($CONTAINER) ==="

# The same owner/name in BOTH catalogues. Without two such rows the test proves nothing.
read -r TS_ID HX_ID OWNER NAME <<<"$(q "
    SELECT t.id, h.id, t.owner, t.name
      FROM mods t JOIN mods h
        ON h.owner = t.owner AND h.name = t.name AND h.source <> t.source
     WHERE t.source='thunderstore' AND h.source='hexium'
     LIMIT 1;")"

if [ -z "${HX_ID:-}" ]; then
    echo "  SKIP  no owner/name exists in both catalogues (sync not run?)"
    exit 0
fi
ok "found $OWNER/$NAME in both catalogues (thunderstore=$TS_ID hexium=$HX_ID)"

q "INSERT INTO worlds (name, seed, public) VALUES ('$WORLD','testseed',0);"
WID=$(q "SELECT id FROM worlds WHERE name='$WORLD';")
[ -n "$WID" ] || { no "could not create test world"; exit 1; }

# Save BOTH copies through the real PHP path, not by writing rows directly -- the guard being
# tested lives in saveWorldModSelection().
OUT=$(docker exec "$CONTAINER" php -r "
    \$_SERVER['DOCUMENT_ROOT']='/opt/stateless/nginx/www';
    require '/opt/stateless/nginx/www/includes/phvalheim-frontend-config.php';
    require '/opt/stateless/nginx/www/includes/modcatalog.php';
    \$r = saveWorldModSelection(\$pdo, '$WORLD', [['id'=>$TS_ID],['id'=>$HX_ID]]);
    echo json_encode(\$r);
" 2>&1)

echo "$OUT" | grep -q '"ok":true' \
    && ok "the save succeeded" \
    || no "save failed" "$(echo "$OUT" | head -c 200)"

# ---- the oracle: what got STORED ----
ROWS=$(q "SELECT COUNT(*) FROM world_mods WHERE world_id=$WID AND is_dep=0;")
[ "$ROWS" = "1" ] && ok "exactly one selection row was stored (not two)" \
    || no "$ROWS selection rows stored for one plugin" \
          "$(q "SELECT GROUP_CONCAT(CONCAT(m.source,':',m.name)) FROM world_mods wm JOIN mods m ON m.id=wm.mod_id WHERE wm.world_id=$WID;")"

# The survivor must be the one install_rows() would pick, or the message lies about it.
WINNER=$(q "SELECT m.source FROM world_mods wm JOIN mods m ON m.id=wm.mod_id
             WHERE wm.world_id=$WID AND wm.is_dep=0 LIMIT 1;")
[ -n "$WINNER" ] && ok "the stored copy comes from '$WINNER'" || no "nothing was stored at all"

# The operator must be TOLD. A silent collapse is the same defect in a quieter form.
echo "$OUT" | grep -qi "was not added" \
    && ok "the response reports the dropped copy" \
    || no "the drop was silent" "$(echo "$OUT" | head -c 200)"

# ---- the counts must now agree with the install ----
docker exec "$CONTAINER" /opt/stateless/engine/tools/worldMods.py --world "$WORLD" --resolve >/dev/null 2>&1
PLAN=$(docker exec "$CONTAINER" /opt/stateless/engine/tools/worldMods.py --world "$WORLD" --plan 2>/dev/null | grep -c .)
STORED=$(q "SELECT COUNT(*) FROM world_mods WHERE world_id=$WID;")
# Guarded on being non-zero: 0 == 0 is true when the save failed outright, which passed
# this assertion while proving nothing.
[ "$PLAN" = "$STORED" ] && [ "${STORED:-0}" -gt 0 ] \
    && ok "world_mods ($STORED) matches what installs ($PLAN) -- the count the UI shows is right" \
    || no "world_mods says $STORED, install plan says $PLAN" "this is the inflated Mods Running count"

# ---- a NON-duplicate selection must be untouched ----
# The guard must not eat legitimately different mods that merely share an owner.
read -r A B <<<"$(q "SELECT GROUP_CONCAT(id) FROM (SELECT id FROM mods
                     WHERE source='thunderstore' AND owner='$OWNER' AND name<>'$NAME' LIMIT 2) x;" | tr ',' ' ')"
if [ -n "${B:-}" ]; then
    OUT2=$(docker exec "$CONTAINER" php -r "
        \$_SERVER['DOCUMENT_ROOT']='/opt/stateless/nginx/www';
        require '/opt/stateless/nginx/www/includes/phvalheim-frontend-config.php';
        require '/opt/stateless/nginx/www/includes/modcatalog.php';
        \$r = saveWorldModSelection(\$pdo, '$WORLD', [['id'=>$A],['id'=>$B]]);
        echo json_encode(\$r);
    " 2>&1)
    KEPT=$(q "SELECT COUNT(*) FROM world_mods WHERE world_id=$WID AND is_dep=0;")
    [ "$KEPT" = "2" ] && ok "two DIFFERENT mods by the same owner are both kept" \
        || no "the guard dropped a legitimate pick" "kept $KEPT of 2"
else
    echo "  note: no second mod by $OWNER to test the same-owner case"
fi

echo
echo "  $pass passed, $fail failed"
[ "$fail" -eq 0 ]
