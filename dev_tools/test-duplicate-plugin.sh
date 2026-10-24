#!/bin/bash
# Oracle test: a world drawing on BOTH catalogues must not install the same plugin twice.
#
# THE BUG THIS CATCHES: identity in `mods` is (source, owner, name), and Hexium mirrors some
# 600 Thunderstore packages under the same owner/name. A world whose picks (or their
# dependencies) reach the same package through both catalogues resolves TWO rows for it into
# its closure. They unzip into the same game/BepInEx tree, so the surviving version depends on
# unzip order, and the mod viewer lists the plugin twice. by_plugin() in worldMods.py collapses
# the closure to one row per (owner, name): an explicit pick beats a dependency, else newest
# version, else Thunderstore.
#
# WHY THIS TEST CAN SEE IT: it builds a world whose picks are the SAME package from both
# catalogues and asserts on the COUNT per owner/name in the install plan -- the thing that
# reaches disk. A test that only counted world_mods rows, or only checked the plan was
# non-empty, would pass with two rows in it.
#
# HISTORY: until 2026-10-22 this test used denikson/BepInExPack_Valheim as the duplicated
# package and asserted the plan contained it exactly ONCE. 2.44 took the loader out of the mod
# system altogether -- InstallAndUpdateBepInEx() puts it in place at engine start and the
# resolver never emits it -- so that assertion read 0 and failed on every release since while
# the collapse itself was fine. The loader's absence is now asserted as the 2.44 contract, and
# the duplicate hazard is exercised on a package that still goes through the resolver.
#
# Usage: dev_tools/test-duplicate-plugin.sh [container]
set -uo pipefail
CONTAINER="${1:-phvalheim-dev}"
WORLD="dupplugintest$$"
pass=0; fail=0
ok()   { pass=$((pass+1)); echo "  PASS  $1"; }
no()   { fail=$((fail+1)); echo "  FAIL  $1${2:+ -- $2}"; }
q()    { docker exec "$CONTAINER" mysql -uroot phvalheim -N -e "$1" 2>/dev/null; }

cleanup() {
    local wid
    wid=$(q "SELECT id FROM worlds WHERE name='$WORLD';")
    [ -n "$wid" ] && q "DELETE FROM world_mods WHERE world_id=$wid; DELETE FROM worlds WHERE id=$wid;"
}
trap cleanup EXIT

echo
echo "=== duplicate-plugin guard ($CONTAINER) ==="

# One package carried by BOTH catalogues, with at least one version in each. Picked by
# (owner, name) so the test does not depend on catalogue ids. The loader is excluded because
# it is not a mod any more (2.44) -- it is asserted separately below.
PAIR=$(q "SELECT CONCAT(a.id,' ',b.id,' ',a.owner,' ',a.name) FROM mods a
          JOIN mods b ON b.owner=a.owner AND b.name=a.name AND b.source='hexium'
          WHERE a.source='thunderstore' AND a.name<>'BepInExPack_Valheim'
            AND EXISTS (SELECT 1 FROM mod_versions WHERE mod_id=a.id)
            AND EXISTS (SELECT 1 FROM mod_versions WHERE mod_id=b.id)
          ORDER BY a.id LIMIT 1;")
if [ -z "$PAIR" ]; then
    echo "  SKIP  no package is carried by both catalogues with versions (sync not run?)"
    exit 0
fi
read -r TS_MOD HX_MOD OWNER NAME <<<"$PAIR"
ok "found $OWNER/$NAME in both catalogues (thunderstore $TS_MOD, hexium $HX_MOD)"

q "INSERT INTO worlds (name, seed, public) VALUES ('$WORLD','testseed',0);"
WID=$(q "SELECT id FROM worlds WHERE name='$WORLD';")
[ -n "$WID" ] || { no "could not create test world"; exit 1; }
# Both copies as explicit picks: the hardest case for the collapse, since neither is "just a
# dependency" it can prefer the other over.
q "INSERT INTO world_mods (world_id, mod_id, is_dep) VALUES ($WID,$TS_MOD,0),($WID,$HX_MOD,0);"

docker exec "$CONTAINER" /opt/stateless/engine/tools/worldMods.py \
    --world "$WORLD" --resolve >/tmp/dup-resolve.$$ 2>&1
PLAN=$(docker exec "$CONTAINER" /opt/stateless/engine/tools/worldMods.py \
    --world "$WORLD" --plan 2>/dev/null)
[ -n "$PLAN" ] && ok "install plan is non-empty" || no "install plan is empty" "$(head -3 /tmp/dup-resolve.$$ | tr '\n' ' ')"

# ---- the oracle: what reaches disk ----
ROWS=$(echo "$PLAN" | awk -F'\t' -v o="$OWNER" -v n="$NAME" '$2==o && $3==n' | wc -l)
[ "$ROWS" -eq 1 ] && ok "install plan contains exactly one $OWNER/$NAME" \
    || no "install plan has $ROWS $OWNER/$NAME rows" \
          "$(echo "$PLAN" | awk -F'\t' -v o="$OWNER" -v n="$NAME" '$2==o && $3==n{print $1"/"$2"/"$3" "$4}' | tr '\n' ' ')"

# No plugin at all may appear twice.
DUPS=$(echo "$PLAN" | awk -F'\t' 'NF{print $2"/"$3}' | sort | uniq -d)
[ -z "$DUPS" ] && ok "no owner/name appears twice in the install plan" \
               || no "duplicated plugins in plan" "$(echo "$DUPS" | tr '\n' ' ')"

# The kept copy must be installable: a collapse that picks a row with no URL would
# silently drop the plugin instead of duplicating it.
URL=$(echo "$PLAN" | awk -F'\t' -v o="$OWNER" -v n="$NAME" '$2==o && $3==n{print $5}' | head -1)
case "$URL" in
    http*) ok "the surviving $NAME row has a download URL" ;;
    *)     no "surviving $NAME row has no usable URL" "'$URL'" ;;
esac

# 2.44 contract: the loader is installed by the engine, never by the resolver. A plan that
# names it would install a second, possibly older, copy over the engine's.
BEP_LINES=$(echo "$PLAN" | awk -F'\t' '$3=="BepInExPack_Valheim"' | wc -l)
[ "$BEP_LINES" -eq 0 ] && ok "the BepInEx loader is not in the plan (engine-installed since 2.44)" \
    || no "install plan names the BepInEx loader $BEP_LINES time(s); the resolver must not emit it"

# ---- the viewer must agree with the plan ----
docker exec "$CONTAINER" /opt/stateless/engine/tools/worldMods.py \
    --world "$WORLD" --viewer-json >/dev/null 2>&1
# Counted against the "name" field only. A bare grep for the string also matches the
# package_url of every entry, which reports 2x the real count and cannot ever read 1.
VIEW_N_NAME=$(q "SELECT JSON_LENGTH(JSON_SEARCH(modsViewer,'all','$NAME',NULL,'\$[*].name'))
                 FROM worlds WHERE id=$WID;")
[ "$VIEW_N_NAME" = "1" ] && ok "mod viewer lists $NAME once" \
    || no "mod viewer lists $NAME ${VIEW_N_NAME:-NULL} times (this is what the UI showed)"

PLAN_N=$(echo "$PLAN" | grep -c .)
VIEW_N=$(q "SELECT JSON_LENGTH(modsViewer) FROM worlds WHERE id=$WID;")
[ "$PLAN_N" = "$VIEW_N" ] && ok "viewer and plan agree on the mod count ($PLAN_N)" \
    || no "viewer shows $VIEW_N mods, plan installs $PLAN_N"

rm -f /tmp/dup-resolve.$$
echo
echo "  $pass passed, $fail failed"
[ "$fail" -eq 0 ]
