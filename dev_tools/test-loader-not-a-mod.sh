#!/bin/bash
# Oracle test: the BepInEx loader is not a selectable mod, and never becomes a dependency row.
#
# THE BUG THIS CATCHES: the loader is installed by InstallAndUpdateBepInEx() at engine start,
# unconditionally and always latest -- selecting or pinning it never did anything. But the
# catalogue carries THREE loader rows and every mod declares a dependency on one, so 2.43's
# resolution added a loader row to every modded world. Because Hexium mods resolve to Hexium's
# copy, the picker hung a yellow "dependency (deselected)" badge on a row the operator could
# not act on -- reading as "something required is missing" when nothing was.
#
# WHY THIS TEST CAN SEE IT: it selects a HEXIUM mod (the case that produced the badge), runs
# the real resolve, and asserts no loader row exists in world_mods, the plan or the viewer. A
# test using a Thunderstore mod, or one that only checked the install plan, would miss it --
# and a test that merely greps for the filter would pass on a filter that is never reached.
#
# Usage: dev_tools/test-loader-not-a-mod.sh [container]
set -uo pipefail
CONTAINER="${1:-phvalheim-dev}"
WORLD="loadertest$$"
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
echo "=== the mod loader is not a mod ($CONTAINER) ==="

# Precondition: the loader must exist in the catalogue in more than one copy, or there was
# never anything to be confused by.
LOADERS=$(q "SELECT COUNT(*) FROM mods WHERE name LIKE 'BepInExPack%';")
[ "${LOADERS:-0}" -ge 2 ] \
    && ok "catalogue carries $LOADERS loader rows (the source of the confusion)" \
    || { echo "  SKIP  fewer than 2 loader rows; nothing for this test to see"; exit 0; }

# A HEXIUM mod that depends on the loader -- the exact case that showed the yellow badge.
HX_MOD=$(q "SELECT m.id FROM mods m
              JOIN mod_versions v ON v.mod_id=m.id AND v.source_rank=0
              JOIN mod_deps d ON d.version_id=v.id
              JOIN mods dm ON dm.id=d.dep_mod_id
             WHERE m.source='hexium' AND dm.name LIKE 'BepInExPack%'
               AND m.name NOT LIKE 'BepInExPack%' LIMIT 1;")
[ -n "$HX_MOD" ] && ok "found a Hexium mod depending on the loader (id $HX_MOD)" \
                 || { no "no Hexium mod depends on the loader"; exit 1; }

q "INSERT INTO worlds (name, seed, public) VALUES ('$WORLD','testseed',0);"
WID=$(q "SELECT id FROM worlds WHERE name='$WORLD';")
[ -n "$WID" ] || { no "could not create test world"; exit 1; }
q "INSERT INTO world_mods (world_id, mod_id, is_dep) VALUES ($WID,$HX_MOD,0);"

docker exec "$CONTAINER" /opt/stateless/engine/tools/worldMods.py --world "$WORLD" --resolve >/tmp/lt.$$ 2>&1
docker exec "$CONTAINER" /opt/stateless/engine/tools/worldMods.py --world "$WORLD" --viewer-json >/dev/null 2>&1
PLAN=$(docker exec "$CONTAINER" /opt/stateless/engine/tools/worldMods.py --world "$WORLD" --plan 2>/dev/null)

# ---- the oracle ----
ROWS=$(q "SELECT COUNT(*) FROM world_mods wm JOIN mods m ON m.id=wm.mod_id
           WHERE wm.world_id=$WID AND m.name LIKE 'BepInExPack%';")
[ "$ROWS" = "0" ] && ok "no loader row in world_mods after resolve" \
    || no "$ROWS loader row(s) written to world_mods" "this is what produced the yellow badge"

echo "$PLAN" | awk -F'\t' '$3 ~ /^BepInExPack/' | grep -q . \
    && no "the install plan wants to install the loader" \
    || ok "the install plan does not include the loader"

VIEW=$(q "SELECT COALESCE(JSON_LENGTH(JSON_SEARCH(modsViewer,'all','BepInExPack%',NULL,'\$[*].name')),0)
            FROM worlds WHERE id=$WID;")
[ "$VIEW" = "0" ] && ok "the mod viewer does not list the loader" \
    || no "the viewer lists the loader $VIEW time(s)"

# The world must still have its real mod -- the exclusion must not eat the selection.
echo "$PLAN" | grep -q . && ok "the world still has an install plan ($(echo "$PLAN" | grep -c .) row(s))" \
    || no "the plan is empty; the exclusion ate the whole selection"

# resolve() should SAY it skipped the loader, so the behaviour is discoverable in the log.
grep -qi "mod loader" /tmp/lt.$$ \
    && ok "resolve explains that the loader is engine-installed" \
    || no "resolve skipped it silently" "$(head -2 /tmp/lt.$$ | tr '\n' ' ')"

# ---- the picker must not be offered the loader ----
OFFERED=$(docker exec "$CONTAINER" php -r "
    \$_SERVER['DOCUMENT_ROOT']='/opt/stateless/nginx/www';
    require '/opt/stateless/nginx/www/includes/phvalheim-frontend-config.php';
    require '/opt/stateless/nginx/www/includes/modcatalog.php';
    \$n = 0;
    foreach (catalogMods(\$pdo) as \$m) { if (stripos(\$m['name'],'BepInExPack')===0) \$n++; }
    echo \$n;
" 2>&1)
[ "$OFFERED" = "0" ] && ok "catalogMods() does not offer the loader" \
    || no "catalogMods() still offers the loader ($OFFERED row(s))"

# And no dependency edge may point at it, or the picker computes badges for a hidden row.
EDGES=$(docker exec "$CONTAINER" php -r "
    \$_SERVER['DOCUMENT_ROOT']='/opt/stateless/nginx/www';
    require '/opt/stateless/nginx/www/includes/phvalheim-frontend-config.php';
    require '/opt/stateless/nginx/www/includes/modcatalog.php';
    \$ids = [];
    foreach (\$pdo->query(\"SELECT id FROM mods WHERE name LIKE 'BepInExPack%'\") as \$r) \$ids[(int)\$r['id']]=1;
    \$n = 0;
    foreach (catalogDeps(\$pdo) as \$deps) foreach (\$deps as \$d) if (isset(\$ids[\$d])) \$n++;
    echo \$n;
" 2>&1)
[ "$EDGES" = "0" ] && ok "catalogDeps() has no edges pointing at the loader" \
    || no "$EDGES dependency edge(s) still point at the loader"

# A stale browser tab posting the loader back must be dropped, not stored.
LOADER_ID=$(q "SELECT id FROM mods WHERE name LIKE 'BepInExPack%' LIMIT 1;")
docker exec "$CONTAINER" php -r "
    \$_SERVER['DOCUMENT_ROOT']='/opt/stateless/nginx/www';
    require '/opt/stateless/nginx/www/includes/phvalheim-frontend-config.php';
    require '/opt/stateless/nginx/www/includes/modcatalog.php';
    saveWorldModSelection(\$pdo, '$WORLD', [['id'=>$HX_MOD],['id'=>$LOADER_ID]]);
" >/dev/null 2>&1
STALE=$(q "SELECT COUNT(*) FROM world_mods wm JOIN mods m ON m.id=wm.mod_id
            WHERE wm.world_id=$WID AND m.name LIKE 'BepInExPack%';")
[ "$STALE" = "0" ] && ok "a stale tab posting the loader back is dropped" \
    || no "the loader was stored via saveWorldModSelection ($STALE row(s))"

rm -f /tmp/lt.$$
echo
echo "  $pass passed, $fail failed"
[ "$fail" -eq 0 ]
