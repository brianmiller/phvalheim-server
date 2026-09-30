#!/bin/bash
# Oracle test: crossplay can be set on ANY world -- modded or vanilla (2.53).
#
# Replaces test-crossplay-vanilla-only.sh, which asserted the exact opposite. That file was
# correct when it was written and is kept only in git history; the assertion has now been
# correct in three directions, so what matters is the reason rather than the direction:
#
#   Scoped vanilla-only by mistake, then widened because crossplay is orthogonal to mods as
#   far as VALHEIM is concerned, then scoped BACK for a client reason -- QuickConnect's config
#   file is `world:host:port:password` and a PlayFab server has no host:port, so a modded
#   crossplay world could not be joined.
#
#   Widened again in 2.53 because the client was never what did the connecting on a modded
#   world. Its job is the mod payload and the BepInEx injection, and Launcher.cs's modded path
#   passes NO connect argument on any platform -- connection has always been a separate
#   in-game step. The player finishes in Valheim's "Join by code" box instead of in
#   QuickConnect's server list.
#
# The argv gate and the .running-options record live in test-startWorld-args.sh, which needs
# no container. This file covers the two WRITE paths, which are separate code and separately
# reachable: saveWorldOptions and createWorld.
#
# EVERY case here is paired with a control. An implementation that simply stores crossplay=1
# for everything passes half of these, and so does one that still forces it to 0 -- only the
# pairs can tell those apart from a correct one.
#
# Usage:  dev_tools/test-crossplay-any-world.sh [container] [world]

CONTAINER="${1:-phvalheim-dev}"
WORLD="${2:-acltest}"
BASE="http://127.0.0.1:8081"
REPO="$(cd "$(dirname "$0")/.." && pwd)"

pass=0; fail=0
check() {
    if [ "$2" = "1" ]; then pass=$((pass+1)); echo "  PASS  $1"
    else fail=$((fail+1)); echo "  FAIL  $1${3:+ -- $3}"; fi
}
sql() { docker exec "$CONTAINER" /opt/stateless/engine/tools/sql "$1"; }

# $1=vanilla $2=crossplay -- the two flags under test. Password/listed stay empty so a modded
# world is never asked for a combination it cannot have.
saveOptions() {
    docker exec "$CONTAINER" curl -s -X POST "$BASE/adminAPI.php?action=saveWorldOptions" \
        -H 'Content-Type: application/json' \
        -d "{\"world\":\"$WORLD\",\"vanilla\":$1,\"crossplay\":$2,\"listed\":0,\"password\":\"\",\"passwordPublic\":0,\"launchParams\":\"\"}" 2>/dev/null
}
storedCrossplay() { sql "SELECT IFNULL(crossplay,0) FROM worlds WHERE name='$WORLD'"; }
storedListed()    { sql "SELECT IFNULL(listed,0) FROM worlds WHERE name='$WORLD'"; }
storedPassword()  { sql "SELECT IFNULL(password,'') FROM worlds WHERE name='$WORLD'"; }
ok() { echo "$1" | grep -q '"success":true' && echo 1 || echo 0; }

ORIG=$(sql "SELECT IFNULL(vanilla,0), IFNULL(crossplay,0) FROM worlds WHERE name='$WORLD'")
trap 'sql "UPDATE worlds SET vanilla=$(echo "$ORIG" | cut -f1), crossplay=$(echo "$ORIG" | cut -f2) WHERE name='"'"'$WORLD'"'"'" >/dev/null 2>&1' EXIT

echo "(container $CONTAINER, world \"$WORLD\")"

echo
echo "saveWorldOptions: a MODDED world CAN have crossplay"
# The inversion. This is the one line that would still pass on 2.52 if the endpoint merely
# stopped rejecting the request while still storing 0 -- hence the stored-value assertion
# rather than an assertion about the response.
sql "UPDATE worlds SET vanilla=0, crossplay=0 WHERE name='$WORLD'" >/dev/null
r=$(saveOptions 0 1)
check "save succeeds" "$(ok "$r")" "$r"
check "crossplay stored as 1 on a modded world" "$([ "$(storedCrossplay)" = "1" ] && echo 1 || echo 0)" "stored $(storedCrossplay)"

echo
echo "saveWorldOptions: and can have it turned back OFF"
# CONTROL for the case above. Without it, an implementation that hardcodes crossplay=1 -- which
# is what removing the `$crossplay = 0` line badly would do -- passes and is undetectable.
r=$(saveOptions 0 0)
check "save succeeds" "$(ok "$r")" "$r"
check "crossplay stored as 0 on a modded world" "$([ "$(storedCrossplay)" = "0" ] && echo 1 || echo 0)" "stored $(storedCrossplay)"

echo
echo "saveWorldOptions: a VANILLA world still can"
# CONTROL: the 2.40 feature must be untouched. A change that moved the gate instead of
# removing it could easily invert it.
r=$(saveOptions 1 1)
check "save succeeds" "$(ok "$r")" "$r"
check "crossplay stored as 1 on a vanilla world" "$([ "$(storedCrossplay)" = "1" ] && echo 1 || echo 0)" "stored $(storedCrossplay)"

echo
echo "saveWorldOptions: switching vanilla -> modded PRESERVES crossplay"
# The direction that reversed meaning in 2.53. It used to be the dangerous one -- a world that
# gained mods had to lose crossplay or it would keep opening a PlayFab server the client could
# not reach. Now it is simply a setting that survives, and a leftover `if (!$vanilla)` clearing
# it would show up here and nowhere else.
r=$(saveOptions 0 1)
check "save succeeds" "$(ok "$r")" "$r"
check "crossplay survives the switch to modded" "$([ "$(storedCrossplay)" = "1" ] && echo 1 || echo 0)" "stored $(storedCrossplay)"

echo
echo "saveWorldOptions: a modded world STILL cannot be listed or password-protected"
# CONTROL on the blast radius. Crossplay came out of the `if (!$vanilla)` block; listing and
# password did not, because a modded world is started -public 0 with no -password and is gated
# by the CITIZENS list. Taking too much out of that block would be invisible without this.
r=$(docker exec "$CONTAINER" curl -s -X POST "$BASE/adminAPI.php?action=saveWorldOptions" \
    -H 'Content-Type: application/json' \
    -d "{\"world\":\"$WORLD\",\"vanilla\":0,\"crossplay\":1,\"listed\":1,\"password\":\"hunter2secret\",\"passwordPublic\":0,\"launchParams\":\"\"}" 2>/dev/null)
check "listed forced to 0 on a modded world" "$([ "$(storedListed)" = "0" ] && echo 1 || echo 0)" "stored $(storedListed)"
check "password forced empty on a modded world" "$([ -z "$(storedPassword)" ] && echo 1 || echo 0)" "stored '$(storedPassword)'"
check "...while crossplay on the SAME request is kept" "$([ "$(storedCrossplay)" = "1" ] && echo 1 || echo 0)" "stored $(storedCrossplay)"

echo
echo "createWorld: the second write path is ungated too"
# createWorldJson() has its own setCrossplay() call, separate code from saveWorldOptions and
# separately reachable. Asserted on the SOURCE rather than by creating a world, because
# creating one installs Valheim -- minutes of SteamCMD for a one-line check.
#
# A NEGATIVE, per docs/RELEASING.md: assert the old gated form is GONE. "setCrossplay exists"
# would pass on an image that still had the gated call.
c=$(grep -c 'setCrossplay($pdo, $world, ($isVanilla && !empty($vanillaOptions\[.crossplay.\])) ? 1 : 0)' "$REPO/container/nginx/www/admin/adminAPI.php")
check "the isVanilla-gated setCrossplay call is gone" "$([ "$c" = "0" ] && echo 1 || echo 0)" "found $c"
c=$(grep -c 'setCrossplay($pdo, $world, !empty($vanillaOptions\[.crossplay.\]) ? 1 : 0)' "$REPO/container/nginx/www/admin/adminAPI.php")
check "and the ungated one is in its place" "$([ "$c" = "1" ] && echo 1 || echo 0)" "found $c"

echo
echo "the forms no longer hide or untick the control"
# Both negatives. The create form used to untick #worldCrossplay when vanilla was unticked, so
# a hidden-but-ticked box could not POST crossplay:1. With the control always visible that line
# would now silently clear an operator's choice every time they toggled Vanilla.
c=$(grep -c "\$('#worldCrossplay').prop('checked', false)" "$REPO/container/nginx/www/admin/new_world.php")
check "new_world.php no longer unticks crossplay on toggle" "$([ "$c" = "0" ] && echo 1 || echo 0)" "found $c"
c=$(grep -c "crossplay.style.display = checked" "$REPO/container/nginx/www/admin/index.php")
check "the Settings > Options row is no longer display-gated" "$([ "$c" = "0" ] && echo 1 || echo 0)" "found $c"
# And the payload must send the operator's actual choice rather than an isVanilla-ANDed one.
c=$(grep -c "crossplay: (isVanilla && \$('#worldCrossplay')" "$REPO/container/nginx/www/admin/new_world.php")
check "the create payload no longer ANDs crossplay with isVanilla" "$([ "$c" = "0" ] && echo 1 || echo 0)" "found $c"

echo
echo "the disclaimers exist"
# The caveat is the feature here as much as the flag is: a modded crossplay world lets console
# players in and they cannot load mods. Anchored on wording that only the warning has.
c=$(grep -c "CANNOT run mods" "$REPO/container/games/valheim/scripts/startWorld.sh")
check "startWorld.sh logs the console mods caveat" "$([ "$c" -ge 1 ] && echo 1 || echo 0)" "found $c"
for f in admin/index.php admin/new_world.php; do
    c=$(grep -c 'crossplayModdedWarning' "$REPO/container/nginx/www/$f")
    check "$f carries the modded crossplay warning" "$([ "$c" -ge 2 ] && echo 1 || echo 0)" "found $c"
done
c=$(grep -c "Join by code" "$REPO/container/nginx/www/public/authenticated.php")
check "the public card explains Join by code" "$([ "$c" -ge 2 ] && echo 1 || echo 0)" "found $c"

echo
echo "$pass passed, $fail failed"
[ "$fail" -eq 0 ] || exit 1
