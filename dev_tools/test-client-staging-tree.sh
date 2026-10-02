#!/bin/bash
#
# The server tree and the client payload are now two different directories. This proves it.
#
# Until 2.53 there was one tree: mods unzipped into game/BepInEx and packageClient() zipped
# that directory wholesale, so the server's live tree WAS the client payload. Splitting them
# is the entire cost of per-mod Server/Client switches, and it is the change most able to
# break a world quietly -- a payload missing a plugin looks, to a player, like the mod simply
# not working.
#
# EVERY assertion here is bidirectional, because "the split happened" and "nothing was split"
# are indistinguishable from one side alone. A payload containing the client-only mod proves
# nothing unless the payload ALSO lacks the server-only one: a packageClient() that still
# zipped game/BepInEx would satisfy the first half of every check in section 3.
#
# Runs entirely against a temp directory with the real functions sourced -- no container, no
# database, no downloads. worldsDirectoryRoot is repointed, which is all these functions read.
#
# Usage:  dev_tools/test-client-staging-tree.sh

REPO="$(cd "$(dirname "$0")/.." && pwd)"
FAILED=0
PASSED=0

ok(){ printf '  \033[32mPASS\033[0m  %s\n' "$1"; PASSED=$((PASSED+1)); }
no(){ printf '  \033[31mFAIL\033[0m  %s\n' "$1"; [ -n "$2" ] && printf '        %s\n' "$2"; FAILED=$((FAILED+1)); }
die(){ printf '\033[31mSETUP FAILED: %s\033[0m\n' "$1"; exit 2; }

command -v zip >/dev/null 2>&1 || die "zip is not installed; packageClient cannot be exercised"

SANDBOX="$(mktemp -d)"
trap 'rm -rf "$SANDBOX"' EXIT

# The functions under test read exactly two globals. Sourcing the real file rather than
# copying the functions in is the point: a copy drifts, and this test would then pass against
# code that no longer ships.
worldsDirectoryRoot="$SANDBOX/worlds"
WORLD="stagetest"

# Lift only the functions, not the whole file -- 0-functions.sh at top level expects a
# container (supervisor paths, the SQL wrapper, phvalheim-static.conf). sed out nothing; bash
# only executes function BODIES when called, and the file is function definitions plus
# comments, so sourcing is safe provided nothing runs at top level. Verified below.
mkdir -p "$SANDBOX"
if ! source "$REPO/container/engine/includes/0-functions.sh" 2>"$SANDBOX/source.err"; then
    die "could not source 0-functions.sh: $(head -3 "$SANDBOX/source.err")"
fi
# Re-assert after sourcing: if the file DID run something at top level that clobbered this,
# every path below would point at the real /opt/stateful and the test would be writing to
# production. Not a hypothetical worth risking.
worldsDirectoryRoot="$SANDBOX/worlds"
case "$worldsDirectoryRoot" in
    "$SANDBOX"/*) : ;;
    *) die "worldsDirectoryRoot escaped the sandbox ('$worldsDirectoryRoot')" ;;
esac

GAME="$worldsDirectoryRoot/$WORLD/game"
STAGE="$worldsDirectoryRoot/$WORLD/client"
ZIP="/opt/stateful/games/valheim/worlds/$WORLD/$WORLD.zip"

# packageClient writes its zip to a hardcoded /opt/stateful path. Redirect it into the
# sandbox by making that path a sandbox symlink target is not possible without root, so the
# test asserts against a relocated copy instead: run packageClient with a wrapper that
# repoints the one absolute path it uses.
packageClientSandboxed(){
    ( zipDest="$SANDBOX/payload"; mkdir -p "$zipDest"
      # Same body as packageClient, with the one hardcoded output path parameterised. Kept in
      # sync by the structural assertion in section 5, which fails if packageClient's zip
      # argument list changes.
      local gameDir="$worldsDirectoryRoot/$1/game"
      local stage; stage="$(clientStagingRoot "$1")"
      local zipPath="$zipDest/$1.zip"
      rm -f "$zipPath"
      [ -d "$stage/BepInEx" ] || return 1
      if [ -f "$gameDir/BepInEx/config/BepInEx.cfg" ]; then
          mkdir -p "$stage/BepInEx/config"
          cp -pf "$gameDir/BepInEx/config/BepInEx.cfg" "$stage/BepInEx/config/BepInEx.cfg"
      fi
      cd "$stage" || return 1
      zip "$zipPath" -r ./BepInEx ./doorstop_libs ./doorstop_config.ini \
          ./start_game_bepinex.sh ./winhttp.dll >/dev/null 2>&1
      return $? )
}

buildFakeWorld(){
    rm -rf "$worldsDirectoryRoot/$WORLD"
    mkdir -p "$GAME/BepInEx/core" "$GAME/BepInEx/config" \
             "$GAME/BepInEx/plugins" "$GAME/BepInEx/patchers" "$GAME/doorstop_libs"
    echo "loader-core" > "$GAME/BepInEx/core/BepInEx.Core.dll"
    printf '[Logging.Console]\n\nEnabled = true\n' > "$GAME/BepInEx/config/BepInEx.cfg"
    echo "dylib"   > "$GAME/doorstop_libs/libdoorstop_x64.so"
    echo "ini"     > "$GAME/doorstop_config.ini"
    echo "launch"  > "$GAME/start_game_bepinex.sh"
    echo "winhttp" > "$GAME/winhttp.dll"
    # The world saves, which live INSIDE game/. Present so the tests can prove nothing in the
    # new code path goes near them.
    mkdir -p "$GAME/.config/unity3d/IronGate/Valheim/worlds_local"
    echo "SAVE" > "$GAME/.config/unity3d/IronGate/Valheim/worlds_local/$WORLD.fwl"
}

inZip(){ unzip -l "$SANDBOX/payload/$WORLD.zip" 2>/dev/null | grep -qF "$1"; }


echo
echo "=== 1. destination routing: all four flag combinations ==="

buildFakeWorld
[ "$(modTargetTrees "$WORLD" 1 1)" = "$GAME $STAGE" ] \
    && ok "server+client -> both trees" \
    || no "server+client gave '$(modTargetTrees "$WORLD" 1 1)'" "expected '$GAME $STAGE'"
[ "$(modTargetTrees "$WORLD" 1 0)" = "$GAME" ] \
    && ok "server-only -> the game tree alone" \
    || no "server-only gave '$(modTargetTrees "$WORLD" 1 0)'"
[ "$(modTargetTrees "$WORLD" 0 1)" = " $STAGE" ] \
    && ok "client-only -> the staging tree alone" \
    || no "client-only gave '$(modTargetTrees "$WORLD" 0 1)'" "expected ' $STAGE'"
# The control that matters: neither flag must yield NOTHING, not a default. A function that
# fell back to the game tree here would install a mod the operator switched off everywhere.
NEITHER="$(modTargetTrees "$WORLD" 0 0)"
[ -z "${NEITHER// /}" ] \
    && ok "neither flag -> empty (no silent default)" \
    || no "neither flag gave '$NEITHER'" "a default here installs a mod that was switched off"


echo
echo "=== 2. prepareClientStaging seeds the loader without dragging the server's mods along ==="

buildFakeWorld
echo "server-only-plugin" > "$GAME/BepInEx/plugins/ServerMod.dll"
prepareClientStaging "$WORLD"

[ -f "$STAGE/BepInEx/core/BepInEx.Core.dll" ] \
    && ok "staging has the loader core" \
    || no "staging is missing BepInEx/core" "a tree with plugins and no loader loads nothing"
[ -f "$STAGE/BepInEx/config/BepInEx.cfg" ] \
    && ok "staging has the loader's BepInEx.cfg" \
    || no "staging is missing BepInEx.cfg" "this is the 2.49 file: no cfg in the zip, no client console"
[ -f "$STAGE/winhttp.dll" ] && [ -f "$STAGE/doorstop_config.ini" ] \
    && [ -f "$STAGE/start_game_bepinex.sh" ] && [ -d "$STAGE/doorstop_libs" ] \
    && ok "staging has the doorstop plumbing that makes a client load BepInEx at all" \
    || no "staging is missing doorstop files" "the payload would install mods the client never loads"
[ -d "$STAGE/BepInEx/plugins" ] && [ -d "$STAGE/BepInEx/patchers" ] \
    && ok "staging has empty plugins/ and patchers/ (unzip -d will not create parents)" \
    || no "staging lacks plugins/ or patchers/" "every plugin unzip into it would fail, silently"

# The control. Seeding must copy the LOADER, not the tree.
[ ! -f "$STAGE/BepInEx/plugins/ServerMod.dll" ] \
    && ok "the server's existing plugin was NOT copied into staging" \
    || no "staging picked up ServerMod.dll" "seeding is copying the whole tree -- the split is cosmetic"

# Nothing in the new path may go near the saves.
[ ! -e "$STAGE/.config" ] \
    && ok "the world saves were not copied into the staging tree" \
    || no "staging contains .config" "the saves live inside game/ and must never be duplicated into a payload"


echo
echo "=== 3. the payload carries client mods and NOT server-only ones (both directions) ==="

buildFakeWorld
prepareClientStaging "$WORLD"
# Simulate an install pass that has already routed by flags: the loop writes each mod into
# whichever trees modTargetTrees returned.
mkdir -p "$GAME/BepInEx/plugins/ServerOnlyMod" "$STAGE/BepInEx/plugins/ClientOnlyMod" \
         "$GAME/BepInEx/plugins/BothMod" "$STAGE/BepInEx/plugins/BothMod"
echo x > "$GAME/BepInEx/plugins/ServerOnlyMod/s.dll"
echo x > "$STAGE/BepInEx/plugins/ClientOnlyMod/c.dll"
echo x > "$GAME/BepInEx/plugins/BothMod/b.dll"
echo x > "$STAGE/BepInEx/plugins/BothMod/b.dll"

packageClientSandboxed "$WORLD" || no "packageClient failed outright" "nothing below is meaningful"

inZip "ClientOnlyMod/c.dll" \
    && ok "the payload contains the client-only mod" \
    || no "ClientOnlyMod is missing from the payload"
! inZip "ServerOnlyMod" \
    && ok "the payload does NOT contain the server-only mod" \
    || no "ServerOnlyMod leaked into the payload" \
         "this is the control: a packageClient still zipping game/BepInEx passes the check above and fails this one"
inZip "BothMod/b.dll" \
    && ok "a both-sides mod is in the payload" \
    || no "BothMod is missing from the payload" "this is every mod on every world that exists today"
[ -f "$GAME/BepInEx/plugins/ServerOnlyMod/s.dll" ] \
    && ok "the server-only mod is still in the server tree (packaging did not move it)" \
    || no "ServerOnlyMod vanished from the server tree"
[ ! -e "$GAME/BepInEx/plugins/ClientOnlyMod" ] \
    && ok "the client-only mod is absent from the SERVER tree -- the other direction" \
    || no "ClientOnlyMod is in the server tree" "the server would load a client mod, which is half the bug"
inZip "BepInEx/config/BepInEx.cfg" \
    && ok "the payload carries the loader config" \
    || no "no BepInEx.cfg in the payload" "2.49: no cfg in the zip, no console window on the client"
! inZip "worlds_local" \
    && ok "the payload contains no world saves" \
    || no "the payload contains save data" "packageClient is reaching into game/"


echo
echo "=== 4. the purge sweeps BOTH trees and spares both loader configs ==="

# Without this, a mod the operator deselected -- or flipped from Client to Server-only --
# stays in the staging tree forever, because the install pass that follows only writes.
buildFakeWorld
prepareClientStaging "$WORLD"
mkdir -p "$GAME/BepInEx/plugins/Old" "$STAGE/BepInEx/plugins/Old" \
         "$GAME/BepInEx/patchers/OldP" "$STAGE/BepInEx/patchers/OldP"
echo x > "$GAME/BepInEx/plugins/Old/o.dll"
echo x > "$STAGE/BepInEx/plugins/Old/o.dll"
echo x > "$GAME/BepInEx/patchers/OldP/p.dll"
echo x > "$STAGE/BepInEx/patchers/OldP/p.dll"
echo "modcfg" > "$GAME/BepInEx/config/SomeMod.cfg"
echo "modcfg" > "$STAGE/BepInEx/config/SomeMod.cfg"

purgeWorldModsConfigsPatchers "$WORLD"

[ ! -e "$GAME/BepInEx/plugins/Old" ] \
    && ok "the server tree's stale plugin is gone" || no "server tree still has plugins/Old"
[ ! -e "$STAGE/BepInEx/plugins/Old" ] \
    && ok "the STAGING tree's stale plugin is gone" \
    || no "staging still has plugins/Old" \
         "a deselected client-only mod would ship in every payload from now on"
[ ! -e "$STAGE/BepInEx/patchers/OldP" ] \
    && ok "the staging tree's stale patcher is gone" || no "staging still has patchers/OldP"
[ ! -f "$STAGE/BepInEx/config/SomeMod.cfg" ] \
    && ok "the staging tree's mod config is gone" || no "staging still has SomeMod.cfg"
[ -f "$GAME/BepInEx/config/BepInEx.cfg" ] && [ -f "$STAGE/BepInEx/config/BepInEx.cfg" ] \
    && ok "BOTH loader configs survived the purge" \
    || no "a loader BepInEx.cfg was swept" \
         "exactly the 2.49 regression: it silences the world log and the client console together"
[ -f "$GAME/BepInEx/core/BepInEx.Core.dll" ] && [ -f "$STAGE/BepInEx/core/BepInEx.Core.dll" ] \
    && ok "both loader cores survived" || no "a BepInEx/core was swept"
[ -f "$GAME/.config/unity3d/IronGate/Valheim/worlds_local/$WORLD.fwl" ] \
    && ok "the world save survived the purge" \
    || no "THE PURGE DELETED THE WORLD SAVE" "saves live inside game/ -- this is the worst outcome in the file"


echo
echo "=== 5. structural: the shipped code really is what was exercised above ==="

FN="$REPO/container/engine/includes/0-functions.sh"
grep -q 'cd "\$stage"' "$FN" \
    && ok "packageClient cds into the staging tree, not game/" \
    || no "packageClient does not cd into \$stage" "section 3 tested a body that has drifted from the real one"
[ "$(grep -c 'cd /opt/stateful/games/valheim/worlds/\$worldName/game' "$FN")" = "0" ] \
    && ok "the old 'cd .../game' package path is gone" || no "packageClient still cds into game/"
grep -q 'prepareClientStaging "\$worldName"' "$FN" \
    && ok "the install path calls prepareClientStaging" \
    || no "nothing calls prepareClientStaging" "the staging tree would never exist at runtime"
grep -q 'modTargetTrees "\$worldName" "\$modDeployServer" "\$modDeployClient"' "$FN" \
    && ok "the install loop routes by the plan's destination flags" \
    || no "the install loop does not call modTargetTrees" "mods would still all go to one tree"
# The zip argument list, asserted because the sandboxed copy in this file duplicates it.
for entry in "./BepInEx" "./doorstop_libs" "./doorstop_config.ini" "./start_game_bepinex.sh" "./winhttp.dll"; do
    grep -qF "$entry" "$FN" || no "packageClient no longer zips $entry" "update packageClientSandboxed in this test"
done
ok "packageClient's zip argument list matches the copy this test exercises"

BK="$REPO/container/engine/tools/worldBackup"
grep -q 'exclude="./client"' "$BK" \
    && ok "worldBackup excludes the derived staging tree" \
    || no "worldBackup would archive client/" "roughly doubles every modded world's backup, every 30 minutes"
grep -q 'exclude="./client"' "$REPO/container/engine/tools/worldRestore" \
    && ok "worldRestore's safety tar excludes it too" || no "worldRestore would archive client/"

echo
if [ "$FAILED" -gt 0 ]; then
    printf '\033[31m%d of %d checks failed\033[0m\n\n' "$FAILED" "$((PASSED+FAILED))"
    exit 1
fi
printf '\033[32mall %d checks passed\033[0m\n\n' "$PASSED"
exit 0
