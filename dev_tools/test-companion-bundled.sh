#!/bin/bash
#
# The Companion ships in the image (2.53+), and QuickConnect must not be retired before it can
# actually replace it.
#
# Replaces test-companion-connect-capability.sh, which tested a catalogue probe -- resolve the
# Companion in `mods`, compare its newest version against a minimum, with a sort -V helper and
# a prerelease carve-out. Bundling the Companion deleted all of that: the server and the mod
# are one artifact now, so the question "can it connect" is a property of the build.
#
# The assertion that matters most here is the SEQUENCING one. A required mod missing from the
# catalogue only produces a [WARN] from mergeRequiredTsMods() and the world is then built
# without it. So flipping companionProvidesConnect to 1 before the Companion can genuinely
# connect does not fail loudly -- it produces modded worlds with no way to join them, and a
# warning in a log nobody reads. This file fails if the flag is on while the Companion's
# connect support is still absent.
#
# Usage:  dev_tools/test-companion-bundled.sh

REPO="$(cd "$(dirname "$0")/.." && pwd)"
CONF="$REPO/container/engine/includes/phvalheim-static.conf"
FUNCS="$REPO/container/engine/includes/0-functions.sh"
ENGINE="$REPO/container/engine/phvalheim"
IMPORT="$REPO/container/games/valheim/scripts/importWorld.sh"
DLL="$REPO/container/games/valheim/custom_plugins/PhValheimCompanion/PhValheimCompanion.dll"
FAILED=0
PASSED=0

pass(){ printf '  \033[32mPASS\033[0m  %s\n' "$1"; PASSED=$((PASSED+1)); }
fail(){ printf '  \033[31mFAIL\033[0m  %s\n' "$1"; [ -n "$2" ] && printf '        %s\n' "$2"; FAILED=$((FAILED+1)); }

echo
echo "=== 1. the Companion is bundled, not resolved from a catalogue ==="

requiredMods=$(grep '^requiredMods=' "$CONF" | cut -d '"' -f2)
[ -n "$requiredMods" ] || { echo "FATAL: requiredMods not found in $CONF"; exit 1; }

case "$requiredMods" in
    *PhValheimCompanion*) fail "the Companion is still in requiredMods" \
        "bundled AND catalogue-resolved means two copies in BepInEx/plugins with the same GUID; one silently fails to load" ;;
    *) pass "the Companion is NOT in requiredMods" ;;
esac
# Control: requiredMods must still contain something, or the check above passes on an empty
# string and proves nothing about the Companion specifically.
case "$requiredMods" in
    *serverblankpassword*) pass "requiredMods still carries serverblankpassword (the list is not simply empty)" ;;
    *) fail "requiredMods no longer contains serverblankpassword" "the check above is vacuous against an empty list" ;;
esac

[ -f "$DLL" ] && pass "PhValheimCompanion.dll is in the repo at the path the Dockerfile copies" \
    || fail "missing $DLL" "the image would build and every world would then exit 1 on install"

grep -q 'COPY container/games/valheim/custom_plugins/PhValheimCompanion ' "$REPO/Dockerfile" \
    && pass "the Dockerfile copies the Companion into /opt/stateless" \
    || fail "Dockerfile has no COPY for the Companion" "the DLL would be in git and absent from the image"

grep -q 'systemPluginsSourceDir/PhValheimCompanion' "$FUNCS" \
    && pass "installSystemPlugins installs it into the world" \
    || fail "installSystemPlugins does not install the Companion"

# It must fail LOUDLY, matching CustomSeed. A soft failure here builds worlds that look fine
# and have no server-side half.
companionBlock=$(awk '/# Install the PhValheim Companion/,/chown -R phvalheim/' "$FUNCS")
case "$companionBlock" in
    *"exit 1"*) pass "a missing Companion DLL is a hard failure, not a warning" ;;
    *)          fail "installSystemPlugins does not exit on a missing Companion DLL" \
                     "it would build joinless worlds and say nothing" ;;
esac


echo
echo "=== 2. the built DLL carries no Newtonsoft dependency ==="

# Bundled, a Newtonsoft reference means shipping a second copy of Newtonsoft.Json.dll into
# BepInEx/plugins beside whatever another mod bundled. Two versions of one assembly in a
# single plugin folder is a load-order lottery that fails at runtime.
if command -v strings >/dev/null 2>&1 && [ -f "$DLL" ]; then
    newtonsoft=$(strings -a "$DLL" | grep -ci newtonsoft)
    # THE CONTROL. If this is 0 the probe is broken -- a compressed or otherwise unreadable
    # binary returns 0 for everything, including the thing we are trying to prove is absent.
    control=$(strings -a "$DLL" | grep -c BepInEx)
    if [ "${control:-0}" -eq 0 ]; then
        fail "control string 'BepInEx' not found in the DLL" \
             "the probe cannot read this binary, so the Newtonsoft result below means nothing"
    elif [ "${newtonsoft:-1}" -eq 0 ]; then
        pass "no Newtonsoft reference in PhValheimCompanion.dll (control present: $control hits)"
    else
        fail "PhValheimCompanion.dll still references Newtonsoft ($newtonsoft hits)" \
             "it would need Newtonsoft.Json.dll shipped alongside it"
    fi
else
    fail "could not probe the DLL" "strings is unavailable or the DLL is missing"
fi


echo
echo "=== 3. QuickConnect is retired by a FLAG, and the flag is honest ==="

flag=$(grep '^companionProvidesConnect=' "$CONF" | cut -d '"' -f2)
legacy=$(grep '^legacyConnectMods=' "$CONF" | cut -d '"' -f2)

case "$flag" in
    0|1) pass "companionProvidesConnect is a plain 0/1 (currently $flag)" ;;
    *)   fail "companionProvidesConnect is '$flag'" "expected 0 or 1" ;;
esac
case "$legacy" in
    *QuickConnect*) pass "legacyConnectMods still names QuickConnect (the fallback exists)" ;;
    *)              fail "legacyConnectMods no longer names QuickConnect" \
                         "with the flag at 0 there would be no join path at all" ;;
esac

# The predicate, lifted and driven both ways. One direction proves nothing: a function that
# always returned false would satisfy "flag 0 -> not capable".
eval "$(awk '/^function companionSupportsConnect\(\)/{d=1} d{print; if(/^}/)exit}' "$FUNCS")"
declare -f companionSupportsConnect >/dev/null || {
    echo "FATAL: could not lift companionSupportsConnect() -- this section tests nothing"; exit 1; }

companionProvidesConnect=1
if companionSupportsConnect; then pass "flag=1 -> capable"; else fail "flag=1 should be capable"; fi
companionProvidesConnect=0
if companionSupportsConnect; then fail "flag=0 should NOT be capable"; else pass "flag=0 -> not capable"; fi
companionProvidesConnect=""
if companionSupportsConnect; then fail "an unset flag should NOT be capable"; else pass "unset flag -> not capable (fails safe)"; fi

# THE SEQUENCING GUARD.
#
# The flag may only be 1 once the Companion genuinely connects. Checked against the mod
# source, not against anybody's intention: if the shipped DLL has no connect code, a 1 here
# means worlds get neither QuickConnect nor a working Companion.
realFlag=$(grep '^companionProvidesConnect=' "$CONF" | cut -d '"' -f2)
companionRepo="$REPO/../phvalheim-companion"
connectEvidence=0
if [ -d "$companionRepo" ]; then
    connectEvidence=$(grep -rl "joincode\|JoinCode\|ServerJoinData\|ConnectToServer" \
        --include=*.cs "$companionRepo" 2>/dev/null | grep -c .)
fi
# There are THREE states here, not two, and collapsing them is what the first version of this
# check got wrong. Connect code can be:
#
#   absent              -> flag must be 0
#   written, unproven   -> flag must be 0, and that must be DECLARED
#   proven on a client  -> flag must be 1
#
# The middle state is real and unavoidable: the Companion compiles against publicized
# assemblies, so a green build proves nothing and the only oracle is a real Valheim client.
# Demanding the flag flip the moment the code exists would force shipping an unverified join
# path; allowing 0 silently would let someone forget to flip it after it IS verified, leaving
# QuickConnect installed for nothing.
#
# So the middle state has to be written down. The marker below is that declaration, and this
# check enforces that the marker and the flag agree -- which means the pending state cannot be
# reached by accident and cannot be left behind quietly.
pendingMarker="COMPANION CONNECT PENDING SMOKE TEST"
pendingDeclared=0
grep -q "$pendingMarker" "$CONF" && pendingDeclared=1

if [ "$realFlag" = "1" ] && [ "${connectEvidence:-0}" -eq 0 ]; then
    fail "companionProvidesConnect=1 but no connect code found in the Companion source" \
         "this ships modded worlds with NO join path -- QuickConnect removed, Companion unable to replace it"
elif [ "$realFlag" = "1" ] && [ "$pendingDeclared" -eq 1 ]; then
    fail "companionProvidesConnect=1 but '$pendingMarker' is still in $CONF" \
         "either the smoke test passed (remove the marker) or it did not (set the flag back to 0)"
elif [ "$realFlag" = "0" ] && [ "${connectEvidence:-0}" -gt 0 ] && [ "$pendingDeclared" -eq 0 ]; then
    fail "the Companion has connect code but companionProvidesConnect is still 0" \
         "either flip the flag, or declare why not with a '$pendingMarker' comment next to it"
elif [ "$realFlag" = "0" ] && [ "$pendingDeclared" -eq 1 ]; then
    pass "connect code present, flag held at 0 and declared pending a real-client smoke test"
else
    pass "the flag ($realFlag) matches the Companion's actual connect support ($connectEvidence file(s))"
fi


echo
echo "=== 4. both QuickConnect call sites are still gated ==="

for f in "$ENGINE" "$IMPORT"; do
    n=$(grep -c 'createQuickConnectConfig' "$f")
    g=$(grep -c 'companionSupportsConnect' "$f")
    if [ "$n" -eq 0 ]; then
        fail "$(basename "$f") no longer calls createQuickConnectConfig" \
             "with the flag at 0 this world gets no join config"
    elif [ "$g" -gt 0 ]; then
        pass "$(basename "$f") gates createQuickConnectConfig on companionSupportsConnect"
    else
        fail "$(basename "$f") calls createQuickConnectConfig WITHOUT the capability check"
    fi
done

# createQuickConnectConfig() itself must stay an unconditional writer -- the check belongs at
# the call sites. Gating it internally would neuter test-gamedns-quickconnect.sh, which drives
# it directly to prove a Game DNS change reaches the cfg.
body=$(awk '/^function createQuickConnectConfig\(\)/{d=1} d{print; if(/^}/)exit}' "$FUNCS")
case "$body" in
    *companionSupportsConnect*) fail "createQuickConnectConfig() gates itself" \
        "this neuters test-gamedns-quickconnect.sh, which calls it directly" ;;
    *)                          pass "createQuickConnectConfig() is still an unconditional writer" ;;
esac

# mergeRequiredTsMods must append the legacy mod while the flag is off.
merge=$(awk '/^function mergeRequiredTsMods\(\)/,/^}/' "$FUNCS")
case "$merge" in
    *legacyConnectMods*) pass "mergeRequiredTsMods appends legacyConnectMods when not capable" ;;
    *)                   fail "mergeRequiredTsMods no longer references legacyConnectMods" ;;
esac

echo
if [ "$FAILED" -gt 0 ]; then
    printf '\033[31m%d of %d checks failed\033[0m\n\n' "$FAILED" "$((PASSED+FAILED))"
    exit 1
fi
printf '\033[32mall %d checks passed\033[0m\n\n' "$PASSED"
exit 0
