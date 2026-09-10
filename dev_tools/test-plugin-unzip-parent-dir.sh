#!/bin/bash
# Oracle test: installing a plugin must not depend on BepInEx/plugins already existing.
#
# THE BUG (production world 'modtest4', 2026-09-10):
#
#   unzip -d creates only the LAST path component. It does NOT create missing parents, and
#   exits 2 ("cannot create extraction directory") when one is absent.
#
#   The BepInEx pack ships BepInEx/config/ and BepInEx/core/ but NOT BepInEx/plugins/ or
#   BepInEx/patchers/. So on a freshly created world those two parents never existed, every
#   plugin unzip failed with exit 2, and 0-functions.sh discarded the output -- giving a world
#   with zero mods while every log line read "Installing...".
#
#   This is DETERMINISTIC, not a race. It hits every newly created modded world. It is the same
#   root cause as 'Jotunheimdallingus' starting with 0 of its 14 mods, which was reported as a
#   race condition.
#
# WHAT MAKES THIS AN ORACLE: case 1 reproduces the exact failing shell behaviour, so it fails
# against the unfixed tree and passes after. Case 3 pins the "no matching files" exit code that
# the fix must NOT treat as an error -- without it, a guard that flagged every non-zero exit
# would mark every modded world broken, which is a worse bug than the one being fixed.
#
# Usage:  dev_tools/test-plugin-unzip-parent-dir.sh [container]

CONTAINER="${1:-phvalheim-dev}"
REPO="$(cd "$(dirname "$0")/.." && pwd)"

pass=0; fail=0
check() {
    if [ "$2" = "1" ]; then pass=$((pass+1)); echo "  PASS  $1"
    else fail=$((fail+1)); echo "  FAIL  $1${3:+ -- $3}"; fi
}

# Build a mod zip shaped like a real Thunderstore one: plugins/, config/, and metadata.
docker exec "$CONTAINER" sh -c '
    rm -rf /tmp/oracle && mkdir -p /tmp/oracle/src/plugins /tmp/oracle/src/config
    echo dll  > /tmp/oracle/src/plugins/Fake.dll
    echo cfg  > /tmp/oracle/src/config/fake.cfg
    echo "{}" > /tmp/oracle/src/manifest.json
    cd /tmp/oracle/src && zip -qr /tmp/oracle/fake-mod.zip .
    # And a BepInEx-pack-shaped zip: everything under BepInExPack_Valheim/, no plugins at all.
    rm -rf /tmp/oracle/psrc && mkdir -p /tmp/oracle/psrc/BepInExPack_Valheim/BepInEx/core
    echo core > /tmp/oracle/psrc/BepInExPack_Valheim/BepInEx/core/Core.dll
    cd /tmp/oracle/psrc && zip -qr /tmp/oracle/fake-pack.zip .
' 2>/dev/null

echo "(container $CONTAINER)"

echo
echo "Case 1: THE BUG -- extracting into a path whose parent does not exist"
# Exactly what line 299 does on a fresh world: -d .../BepInEx/plugins/<modName>/ where
# .../BepInEx/plugins does not exist yet.
out=$(docker exec "$CONTAINER" sh -c '
    rm -rf /tmp/oracle/w
    mkdir -p /tmp/oracle/w/BepInEx/core          # what the pack really leaves behind
    unzip -o -qq /tmp/oracle/fake-mod.zip -x config/* core/* patchers/* BepInExPack_Valheim/* README.md icon.png manifest.json \
        -d /tmp/oracle/w/BepInEx/plugins/FakeMod/ >/dev/null 2>&1
    echo "exit=$?"
    [ -f /tmp/oracle/w/BepInEx/plugins/FakeMod/plugins/Fake.dll ] && echo "installed=yes" || echo "installed=no"
')
check 'unzip alone FAILS when the parent is missing (this is the bug)' \
    "$(echo "$out" | grep -q 'exit=2' && echo 1 || echo 0)" "$out"
check 'and nothing is installed' \
    "$(echo "$out" | grep -q 'installed=no' && echo 1 || echo 0)" "$out"

echo
echo "Case 2: THE FIX -- mkdir -p the parent first"
out=$(docker exec "$CONTAINER" sh -c '
    rm -rf /tmp/oracle/w
    mkdir -p /tmp/oracle/w/BepInEx/core
    mkdir -p /tmp/oracle/w/BepInEx/plugins       # the fix
    unzip -o -qq /tmp/oracle/fake-mod.zip -x config/* core/* patchers/* BepInExPack_Valheim/* README.md icon.png manifest.json \
        -d /tmp/oracle/w/BepInEx/plugins/FakeMod/ >/dev/null 2>&1
    echo "exit=$?"
    [ -f /tmp/oracle/w/BepInEx/plugins/FakeMod/plugins/Fake.dll ] && echo "installed=yes" || echo "installed=no"
')
check 'unzip succeeds' "$(echo "$out" | grep -q 'exit=0' && echo 1 || echo 0)" "$out"
check 'and the plugin lands on disk' \
    "$(echo "$out" | grep -q 'installed=yes' && echo 1 || echo 0)" "$out"

echo
echo "Case 3: a BepInEx-pack zip legitimately extracts NO plugins -- exit 11, not an error"
# The pack holds only BepInExPack_Valheim/*, which the plugin command excludes. If the new
# failure counter treated every non-zero exit as a failure, this would mark EVERY modded world
# broken -- a worse bug than the one being fixed.
out=$(docker exec "$CONTAINER" sh -c '
    rm -rf /tmp/oracle/w && mkdir -p /tmp/oracle/w/BepInEx/plugins
    unzip -o -qq /tmp/oracle/fake-pack.zip -x config/* core/* patchers/* BepInExPack_Valheim/* README.md icon.png manifest.json \
        -d /tmp/oracle/w/BepInEx/plugins/Pack/ >/dev/null 2>&1
    echo "exit=$?"
')
check 'exits 11 ("no matching files"), which the fix must tolerate' \
    "$(echo "$out" | grep -q 'exit=11' && echo 1 || echo 0)" "$out"

echo
echo "Case 4: the engine source actually creates the parents before unzipping"
src="$REPO/container/engine/includes/0-functions.sh"
for d in plugins patchers; do
    c=$(grep -c "mkdir -p \$worldsDirectoryRoot/\$worldName/game/BepInEx/$d" "$src")
    check "0-functions.sh mkdir -p ...BepInEx/$d" "$([ "$c" = "1" ] && echo 1 || echo 0)" "found $c"
done
# The mkdir must come BEFORE the plugin unzip, or it fixes nothing.
mk=$(grep -n "mkdir -p \$worldsDirectoryRoot/\$worldName/game/BepInEx/plugins" "$src" | head -1 | cut -d: -f1)
uz=$(grep -n "BepInEx/plugins/\$modName/" "$src" | head -1 | cut -d: -f1)
check "and does so BEFORE the plugin unzip (line $mk < $uz)" \
    "$([ -n "$mk" ] && [ -n "$uz" ] && [ "$mk" -lt "$uz" ] && echo 1 || echo 0)"

# 11 must be tolerated in the source, not just in principle.
t=$(grep -c 'unzipResult -ne 11' "$src")
check "the failure guard tolerates exit 11" "$([ "$t" = "1" ] && echo 1 || echo 0)" "found $t"

docker exec "$CONTAINER" rm -rf /tmp/oracle 2>/dev/null

echo
echo "$pass passed, $fail failed"
[ "$fail" -eq 0 ] || exit 1
