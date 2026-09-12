#!/bin/bash
# Oracle test: a world drawing on BOTH catalogues must not install the same plugin twice.
#
# THE BUG THIS CATCHES: identity in `mods` is (source, owner, name), and modSync resolves
# each mod's dependency to its OWN catalogue's copy -- deliberately, so a Hexium mod gets
# Hexium's download URL. Both catalogues carry denikson/BepInExPack_Valheim (9154
# Thunderstore mods point at Thunderstore's copy, 840 Hexium mods at Hexium's), so a world
# with one Hexium mod plus the three Thunderstore mods every world gets resolves TWO
# BepInEx rows into its closure. They unzip into the same game/BepInEx tree, so the
# surviving version depends on unzip order, and the mod viewer lists the plugin twice.
#
# WHY THIS TEST CAN SEE IT: it builds a world whose picks span both catalogues and asserts
# on the COUNT OF DISTINCT owner/name in the install plan -- the thing that reaches disk.
# A test that only counted world_mods rows, or only checked the plan was non-empty, would
# pass with two BepInEx entries in it.
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

# A Thunderstore mod and a Hexium mod that BOTH depend on BepInEx. Picked by
# (source, owner, name) so the test does not depend on catalogue ids.
TS_MOD=$(q "SELECT m.id FROM mods m JOIN mod_versions v ON v.mod_id=m.id AND v.source_rank=0
            JOIN mod_deps d ON d.version_id=v.id JOIN mods dm ON dm.id=d.dep_mod_id
            WHERE m.source='thunderstore' AND dm.name='BepInExPack_Valheim' LIMIT 1;")
HX_MOD=$(q "SELECT m.id FROM mods m JOIN mod_versions v ON v.mod_id=m.id AND v.source_rank=0
            JOIN mod_deps d ON d.version_id=v.id JOIN mods dm ON dm.id=d.dep_mod_id
            WHERE m.source='hexium' AND dm.name='BepInExPack_Valheim' LIMIT 1;")

if [ -z "$TS_MOD" ] || [ -z "$HX_MOD" ]; then
    echo "  SKIP  catalogue has no mixed-source BepInEx dependants (sync not run?)"
    exit 0
fi
ok "found a Thunderstore ($TS_MOD) and a Hexium ($HX_MOD) mod that both need BepInEx"

# Both catalogues must genuinely carry the plugin, or the test proves nothing.
SRCS=$(q "SELECT COUNT(DISTINCT source) FROM mods WHERE name='BepInExPack_Valheim';")
[ "$SRCS" -ge 2 ] && ok "BepInExPack_Valheim exists in $SRCS catalogues" \
                  || no "only $SRCS catalogue carries BepInExPack_Valheim; test is blind"

q "INSERT INTO worlds (name, seed, public) VALUES ('$WORLD','testseed',0);"
WID=$(q "SELECT id FROM worlds WHERE name='$WORLD';")
[ -n "$WID" ] || { no "could not create test world"; exit 1; }
q "INSERT INTO world_mods (world_id, mod_id, is_dep) VALUES ($WID,$TS_MOD,0),($WID,$HX_MOD,0);"

docker exec "$CONTAINER" /opt/stateless/engine/tools/worldMods.py \
    --world "$WORLD" --resolve >/tmp/dup-resolve.$$ 2>&1
PLAN=$(docker exec "$CONTAINER" /opt/stateless/engine/tools/worldMods.py \
    --world "$WORLD" --plan 2>/dev/null)

# ---- the oracle: what reaches disk ----
BEP_LINES=$(echo "$PLAN" | awk -F'\t' '$3=="BepInExPack_Valheim"' | wc -l)
[ "$BEP_LINES" -eq 1 ] && ok "install plan contains exactly one BepInExPack_Valheim" \
    || no "install plan has $BEP_LINES BepInExPack_Valheim rows" \
          "$(echo "$PLAN" | awk -F'\t' '$3=="BepInExPack_Valheim"{print $1"/"$2"/"$3" "$4}' | tr '\n' ' ')"

# No plugin at all may appear twice -- BepInEx is just the one that always does.
DUPS=$(echo "$PLAN" | awk -F'\t' 'NF{print $2"/"$3}' | sort | uniq -d)
[ -z "$DUPS" ] && ok "no owner/name appears twice in the install plan" \
               || no "duplicated plugins in plan" "$(echo "$DUPS" | tr '\n' ' ')"

# The kept copy must be installable: a collapse that picks a row with no URL would
# silently drop the plugin instead of duplicating it.
BEP_URL=$(echo "$PLAN" | awk -F'\t' '$3=="BepInExPack_Valheim"{print $5}' | head -1)
case "$BEP_URL" in
    http*) ok "the surviving BepInEx row has a download URL" ;;
    *)     no "surviving BepInEx row has no usable URL" "'$BEP_URL'" ;;
esac

# Both of the operator's OWN picks must survive -- the collapse must not eat a real pick.
for m in "$TS_MOD" "$HX_MOD"; do
    nm=$(q "SELECT name FROM mods WHERE id=$m;")
    echo "$PLAN" | awk -F'\t' -v n="$nm" '$3==n' | grep -q . \
        && ok "explicit pick '$nm' survives the collapse" \
        || no "explicit pick '$nm' was dropped from the plan"
done

# ---- the viewer must agree with the plan ----
docker exec "$CONTAINER" /opt/stateless/engine/tools/worldMods.py \
    --world "$WORLD" --viewer-json >/dev/null 2>&1
# Counted against the "name" field only. A bare grep for the string also matches the
# package_url of every entry, which reports 2x the real count and cannot ever read 1.
VIEW_BEP=$(q "SELECT JSON_LENGTH(JSON_SEARCH(modsViewer,'all','BepInExPack_Valheim',NULL,'\$[*].name'))
              FROM worlds WHERE id=$WID;")
[ "$VIEW_BEP" = "1" ] && ok "mod viewer lists BepInExPack_Valheim once" \
    || no "mod viewer lists BepInExPack_Valheim $VIEW_BEP times (this is what the UI showed)"

PLAN_N=$(echo "$PLAN" | grep -c .)
VIEW_N=$(q "SELECT JSON_LENGTH(modsViewer) FROM worlds WHERE id=$WID;")
[ "$PLAN_N" = "$VIEW_N" ] && ok "viewer and plan agree on the mod count ($PLAN_N)" \
    || no "viewer shows $VIEW_N mods, plan installs $PLAN_N"

echo
echo "  $pass passed, $fail failed"
[ "$fail" -eq 0 ]
