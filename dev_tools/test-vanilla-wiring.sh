#!/bin/bash
#
# Structural guard for the vanilla-world UI wiring.
#
# Several of these settings have to be applied in more than one place, and missing one
# fails silently rather than visibly:
#
#   * the HEALTH bar is rendered in THREE spots (online PHP row, offline PHP row, JS row)
#   * Edit Mods is rendered in three spots AND re-enabled by updateActionButtons() on
#     every 5s poll, so PHP-only gating gets quietly undone a moment after page load
#   * the password appears in both the server-rendered card and the AJAX JSON payload
#
# This is a structural check, not a behavioural one -- it proves the wiring is present
# in every path, not that the rendered page looks right. Verify the actual UI in a browser.
#
# Run:  ./dev_tools/test-vanilla-wiring.sh
#
cd "$(dirname "$0")/.." || exit 1
pass=0; fail=0
ck(){ if [ "$2" -eq "$3" ] 2>/dev/null; then echo "  PASS: $1"; pass=$((pass+1)); else echo "  FAIL: $1 (got '$2', want $3)"; fail=$((fail+1)); fi; }

A=container/nginx/www/public/authenticated.php
P=container/nginx/www/public/api.php
I=container/nginx/www/admin/index.php
M=container/nginx/www/admin/adminAPI.php
N=container/nginx/www/admin/new_world.php
E=container/nginx/www/admin/edit_world.php
C=container/nginx/www/css/phvalheimStyles.css

echo "1. Type: unmodded"
ck "card renders 'unmodded'"        "$(grep -c '>unmodded<' $A)" 1
ck "old wording gone"               "$(grep -c 'no mods needed' $A)" 0

echo "2. Password visibility"
ck "authenticated.php gates"        "$(grep -c 'getPasswordPublic' $A)" 1
ck "api.php gates"                  "$(grep -c 'getPasswordPublic' $P)" 1
ck "migration column"               "$(grep -c 'password_public' container/engine/dbUpdates/dbUpdate_2.40.sh)" 1
ck "getter exists"                  "$(grep -c 'function getPasswordPublic' container/nginx/www/includes/db_gets.php)" 1
ck "setter exists"                  "$(grep -c 'function setPasswordPublic' container/nginx/www/includes/db_sets.php)" 1
ck "modal toggle wired"             "$(grep -c 'settingsPasswordPublicToggle' $I)" 2

echo "3. HEALTH removed for vanilla"
ck "PHP HEALTH blocks gated"        "$(grep -c 'tick health comes from the TickMonitor' $I)" 2
ck "JS HEALTH block gated"          "$(grep -c 'world.vanilla ?' $I)" 1

echo "4. Edit Mods gated"
ck "vanilla in index.php data"      "$(grep -c "'vanilla' => " $I)" 1
ck "vanilla in adminAPI data"       "$(grep -c "'vanilla' => " $M)" 2
ck "PHP offline row gated"          "$(grep -c 'is a vanilla world . it runs no mods' $I)" 3
ck "edit_world.php guard"           "$(grep -c 'getVanilla' $E)" 1
ck "mods purged on switch"          "$(grep -c "deleteAllWorldMods(\$pdo, \$world);" $M)" 2

echo "5/6. Offline dim + copy"
ck "badge dim emitted"              "$(grep -c 'vanilla-badge-dimmed' $A)" 1
ck "badge dim styled"               "$(grep -c 'vanilla-badge-dimmed' $C)" 1
ck "copy handler"                   "$(grep -c 'function copyVanillaPassword' $A)" 1
ck "copy link rendered"             "$(grep -c 'copyVanillaPassword' $A)" 2
ck "old reveal class gone (css)"    "$(grep -c 'vanilla-password-reveal' $C)" 0
ck "old reveal class gone (php)"    "$(grep -c 'vanilla-password-reveal' $A)" 0
ck "new action class styled"        "$(grep -c "vanilla-password-action" $C)" 3

echo "8. AJAX poll must not clobber vanilla server-rendered state"
# This class of bug has bitten three times: the 5s refresh overwrites correct
# server-rendered markup a few seconds after page load, so the page looks right
# on load and wrong immediately after. Every field the poll writes that also
# exists on the vanilla card needs a vanilla-aware branch.
ck "poll uses steam:// for vanilla"  "$(grep -c 'world.vanilla && world.connection' $A)" 1
ck "no unconditional phvalheim:// "  "$(grep -c 'launchLink.href = `phvalheim' $A)" 0
ck "api sends connection.steamUrl"   "$(grep -c "'steamUrl'" $P)" 1
ck "api sends vanilla flag"          "$(grep -c "'vanilla' =>" $P)" 1
ck "api seed mirrors renderer"       "$(grep -c 'generated on first start' $P)" 1
ck "renderer has the same string"    "$(grep -c 'generated on first start' $A)" 1
ck "vanilla link: steam url + Launch!" "$(grep -cF 'vanillaSteamUrl' $A)" 2
ck "no 'Join!' label left"           "$(grep -c '>Join!<' $A)" 0

echo "7. Seed hidden for vanilla"
ck "seedField id"                   "$(grep -c 'id="seedField"' $N)" 1
ck "seedField toggled"              "$(grep -c "seedField').toggle" $N)" 1
ck "vanilla seed notice"            "$(grep -c 'vanillaSeedNotice' $N)" 2

echo
echo "$pass passed, $fail failed"
[ "$fail" -eq 0 ]
