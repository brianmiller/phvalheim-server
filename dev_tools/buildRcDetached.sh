#!/bin/bash
# Build + tag + push :rc in ONE detached process.
#
# Why detached: a build started from an agent session dies with "context canceled"
# when the session recycles mid-build. setsid + a stable log file survives that.
#
# Why a cached build rather than buildImage.sh's --no-cache + system prune: the
# COPY layers invalidate on the changed files anyway, and the real check is
# verifying the markers INSIDE the pushed image (done at the end here), not
# trusting the build flags. It is also far less disk I/O on this host.
#
#   setsid nohup dev_tools/buildRcDetached.sh > /dev/null 2>&1 &
#   tail -f /tmp/phvalheim-rc-build.log

set -u
LOG=/tmp/phvalheim-rc-build.log
REPO=/mnt/wopr/development/brian/phvalheim-server
IMAGE=theoriginalbrian/phvalheim-server:rc

# Release promotion. Set to the extra tags this build should ALSO become, e.g.
#
#   EXTRA_TAGS="2.45 latest" setsid nohup dev_tools/buildRcDetached.sh >/dev/null 2>&1 &
#
# They are pushed only after the in-image verify prints IMAGE VERIFY OK, and the verify
# is what decides -- not the build exit status, because the verify deliberately reports
# failure with an echo rather than a non-zero exit so the log always shows every marker.
# Promoting by rebuilding rather than `docker tag`-ing a previous :rc is deliberate too:
# a retag would ship whatever :rc happened to contain, which is not necessarily this
# commit. Same source, same image, all three tags.
EXTRA_TAGS="${EXTRA_TAGS:-}"

exec > "$LOG" 2>&1
echo "=== started $(date -u) ==="
cd "$REPO" || exit 1

# Self-check before anything expensive.
#
# The image verify at the bottom is one giant `sh -c '...'` argument. A single apostrophe
# anywhere inside it -- INCLUDING in a comment -- closes the string early; the shell then
# reinterprets the remainder and the rest of the verify silently never runs. This script
# has already reported a clean build while skipping its last four checks that way, and
# `bash -n` cannot catch it because the result is still valid shell.
#
# Counting them here turns an invisible failure into a refusal to build.
# Anchored on the docker-run line itself, NOT on the substring "--entrypoint sh": this
# check's own source line contains that substring, so a looser pattern matches HERE and
# counts the wrong region -- which it did on the first attempt, reporting 4 apostrophes
# in a payload that had none.
apos=$(awk '/^docker run --rm -e EXPECT_VER=/{f=1} f&&/^.$/{exit} f' "$0" | tr -cd "'" | wc -c)
# 1 = the opening quote on the docker-run line. Anything more is inside the payload.
if [ "$apos" -ne 1 ]; then
	echo "REFUSING TO BUILD: the sh -c verify payload contains $((apos - 1)) apostrophe(s)."
	echo "One apostrophe truncates the verify silently. Remove them, including in comments."
	echo "=== done FAILED ==="
	exit 1
fi
echo "=== verify payload apostrophe check: clean ==="

echo "=== building $IMAGE ==="
docker buildx build --network=host -t "$IMAGE" . || { echo "BUILD FAILED"; echo "=== done FAILED ==="; exit 1; }

echo "=== pushing ==="
docker push "$IMAGE" || { echo "PUSH FAILED"; echo "=== done FAILED ==="; exit 1; }

echo "=== verifying the fixes are INSIDE the pushed image ==="
# Trust bytes in the image, not the build output.
EXPECT_VER=$(sed -n 's/^ENV phvalheimVersion=//p' Dockerfile | head -1)
echo "=== expecting image version $EXPECT_VER (read from Dockerfile) ==="
docker run --rm -e EXPECT_VER="$EXPECT_VER" --entrypoint sh "$IMAGE" -c '
  a=$(grep -c "modSelectionCard" /opt/stateless/nginx/www/admin/new_world.php)
  b=$(grep -c "Clearing world md5sum" /opt/stateless/engine/includes/0-functions.sh)
  c=$(grep -c "No client payload found for modded world" /opt/stateless/engine/phvalheim)
  d=$(grep -c "modSelectionArea" /opt/stateless/nginx/www/admin/new_world.php)
  # The Access-tab rename. Check the NEW names are in and the OLD ones are gone --
  # counting only the new id would pass on an image that still carried both.
  e=$(grep -c "settingsAccessListToggle" /opt/stateless/nginx/www/admin/index.php)
  f=$(grep -c "settingsPublicToggle" /opt/stateless/nginx/www/admin/index.php)
  # Match the switch ROW LABEL, not the bare strings. The upgrade notice quotes the old
  # name on purpose ("Public World is now Use Access List"), so a bare count of either
  # string says nothing about what the switch is actually labelled.
  g=$(grep -c "pv-row-label\">Use Access List" /opt/stateless/nginx/www/admin/index.php)
  h=$(grep -c "pv-row-label\">Public World" /opt/stateless/nginx/www/admin/index.php)
  echo "modSelectionCard=$a (want 2)"
  echo "Clearing world md5sum=$b (want 1)"
  echo "modded-payload WARNING=$c (want 1)"
  # 2 = the wrapper <div id="modSelectionArea"> + the comment explaining why the
  # toggle moved off it. A THIRD would mean a live $(...).toggle() came back.
  echo "modSelectionArea mentions=$d (want 2: the div + the comment)"
  # The Access-tab refactor: ONE shared ID-help disclosure instead of three copies
  # of the banner, and per-list lookup buttons. The css must ship too -- the markup
  # alone renders an unstyled <details>, which looks like nothing was done.
  i=$(grep -c "idHelpDisclosure" /opt/stateless/nginx/www/admin/index.php)
  j=$(grep -c "Easiest way to get" /opt/stateless/nginx/www/admin/index.php)
  k=$(grep -c "pv-list-lookup" /opt/stateless/nginx/www/admin/index.php)
  l=$(grep -c "\.pv-disclosure" /opt/stateless/nginx/www/css/phvalheimStyles.css)
  echo "settingsAccessListToggle=$e (want 3)  settingsPublicToggle=$f (want 0)"
  echo "switch labelled Use Access List=$g (want 1)  still labelled Public World=$h (want 0)"
  # The switch-inversion upgrade notice, and the engine no longer dying on one bad world.
  # The engine check is a NEGATIVE: `exit 1` must be GONE from the whole file. Checking only
  # that the new message is present would pass on an image that had both.
  m=$(grep -c "accessSwitchNoticeShown" /opt/stateless/nginx/www/admin/index.php)
  n=$(grep -c "accessSwitchNoticeShown" /opt/stateless/engine/dbUpdates/dbUpdate_2.40.sh)
  o=$(grep -c "Marking it broken" /opt/stateless/engine/phvalheim)
  p=$(grep -c "exit 1" /opt/stateless/engine/phvalheim)
  echo "idHelpDisclosure=$i (want 2)  old banner=$j (want 0)"
  echo "pv-list-lookup=$k (want 3)  .pv-disclosure css rules=$l (want >0)"
  echo "accessSwitchNoticeShown ui=$m (want 2) migration=$n (want 4)"
  # NO single quotes in these echoes -- the whole block is inside sh -c QUOTES, so one
  # apostrophe closes it early and the rest of the verify silently never runs. That is
  # exactly how this script reported a clean build while skipping its last four checks.
  # Crossplay join code, and the offline-world stats sweep.
  # Count the DEFINITION, not every mention: getVanillaJoinInfo() now calls this too, so a bare
  # string count went to 2 and failed the verify on an image that was perfectly correct.
  q=$(grep -c "function getWorldJoinCode" /opt/stateless/nginx/www/includes/db_gets.php)
  r=$(grep -c "copyVanillaJoinCode" /opt/stateless/nginx/www/public/authenticated.php)
  s=$(grep -c "joinCode" /opt/stateless/nginx/www/public/api.php)
  t=$(grep -c "clearUnreportedWorlds" /opt/stateless/nginx/www/admin/index.php)
  echo "engine marks-broken=$o (want 2)  engine exit-1 count=$p (want 0)"
  # A crossplay world launches with -joincode. Match the URL itself, not the bare word --
  # the comments explaining all this mention "-joincode" nine times.
  u=$(grep -cF "steam://run/892970//-joincode" /opt/stateless/nginx/www/public/authenticated.php)
  v=$(grep -cF "steam://run/892970//-joincode" /opt/stateless/nginx/www/public/api.php)
  echo "getWorldJoinCode=$q (want 1)  copyVanillaJoinCode=$r (want 3)  api joinCode=$s (want 1)"
  echo "clearUnreportedWorlds=$t (want 2)"
  # The join path must follow the RUNNING backend, not the crossplay column.
  w=$(grep -c "function getWorldNetBackend" /opt/stateless/nginx/www/includes/db_gets.php)
  x=$(grep -c "connection.playfab" /opt/stateless/nginx/www/public/authenticated.php)
  # DELIBERATELY 0 since the crossplay-join-modal change. -joincode joins Valheim with no
  # character selected, so the client falls back to its Odev (Developer) profile, and the card
  # now opens a how-to-join modal instead. If either goes back to 1 the dead launch URL has
  # returned. Do NOT re-baseline them to whatever the image contains.
  # NOTE: this whole verify body is inside sh -c SINGLE quotes. No apostrophes, no single
  # quotes, not even in a comment -- one of either ends the block early, every later check is
  # skipped, and the leftover greps run against the HOST where these paths do not exist.
  cl=$(grep -c "showCrossplayJoin" /opt/stateless/nginx/www/public/authenticated.php)
  cm=$(grep -c "crossplayJoinModal" /opt/stateless/nginx/www/public/authenticated.php)
  cn=$(grep -c "crossplay-join-dialog" /opt/stateless/nginx/www/css/phvalheimStyles.css)
  # TWO href NULLs are correct: the offline-world early return and the crossplay return.
  # If the crossplay launch URL is ever restored this drops to 1, so the count still catches it.
  co=$(grep -cE "href.+=> NULL," /opt/stateless/nginx/www/includes/db_gets.php)
  cq=$(grep -cF "steam://run/892970//-joincode" /opt/stateless/nginx/www/includes/db_gets.php)
  echo "joincode launch url GONE: card=$u (want 0)  api=$v (want 0)  db_gets=$cq (want 0)"
  # The modal MUST outrank the backdrop. The page carries a pre-bootstrap .modal rule at
  # z-index 1050 that makes .modal itself the dim layer; with the backdrop also at 1050 the
  # backdrop painted over the dialog, so it looked dimmed and Close could not be clicked.
  cr=$(grep -c "crossplayJoinModal.modal" /opt/stateless/nginx/www/css/phvalheimStyles.css)
  echo "crossplay modal: opener=$cl (want 3)  markup=$cm (want 4)  css=$cn (want 1)  null href=$co (want 2)"
  echo "crossplay modal stacking override=$cr (want 1)"
  # OPEN is a pill like every other one now. The muted STYLE is what made it look different, so
  # check the css, not the php -- the php still mentions the old class name in a comment.
  cs=$(grep -c "vanilla-badge-muted" /opt/stateless/nginx/www/css/phvalheimStyles.css)
  ct=$(grep -c "accent-primary" /opt/stateless/nginx/www/css/phvalheimStyles.css)
  echo "pill styling: muted rule gone=$cs (want 0)  accent still used=$ct (want >0)"
  # Analytics payload must never be handed to jq on the command line: Linux caps one argv entry
  # at 128 KiB, so a server with enough worlds x mods got "Argument list too long" and every
  # push failed. Large JSON goes through files now.
  cu=$(grep -c "slurpfile worlds" /opt/stateless/engine/tools/pushAnalytics.sh)
  cv=$(grep -c "slurpfile mods" /opt/stateless/engine/tools/pushAnalytics.sh)
  cw=$(grep -v "^#" /opt/stateless/engine/tools/pushAnalytics.sh | grep -c "argjson worlds")
  echo "analytics payload by file: worlds=$cu (want 1)  mods=$cv (want 1)  stale argjson=$cw (want 0)"
  echo "getWorldNetBackend=$w (want 1)  poll keys off connection.playfab=$x (want 1)"
  # The empty-access-list work: the save-time refusal, and the start-time warning for worlds
  # that predate it. Match the WARNING text, not the word "empty" -- this file discusses empty
  # lists in several comments and a bare word count would pass on an image with none of this.
  # Both strings flipped TWICE: reworded when a fail-closed placeholder made "lets everyone in"
  # false, then back when the placeholder was dropped. They are matched on the CONSEQUENCE
  # clause deliberately -- that is the part that has to stay true, and $ae below asserts the
  # other wording is gone, so an image carrying both cannot pass.
  y=$(grep -c "it would let everyone in rather than nobody" /opt/stateless/nginx/www/admin/adminAPI.php)
  z=$(grep -cF "ANYONE CAN JOIN" /opt/stateless/games/valheim/scripts/syncAccessLists.sh)
  echo "empty-list save refusal=$y (want 1)  start-time warning=$z (want 1)"
  # The fail-closed placeholder was TRIED and DROPPED. Assert it is gone from both writers:
  # it is exactly the kind of thing that gets reintroduced by someone reading the old commit.
  aa=$(grep -cF "76561197960265728" /opt/stateless/games/valheim/scripts/syncAccessLists.sh)
  ab=$(grep -cF "76561197960265728" /opt/stateless/nginx/www/includes/accesslists.php)
  ac=$(grep -c "setPublic(\$pdo, \$world, \$accessOpen ? 1 : 0)" /opt/stateless/nginx/www/admin/adminAPI.php)
  ad=$(grep -c "accessModel" /opt/stateless/nginx/www/admin/new_world.php)
  ae=$(grep -c "nobody at all would be able to join" /opt/stateless/nginx/www/admin/adminAPI.php)
  echo "placeholder GONE: shell=$aa (want 0)  php=$ab (want 0)"
  echo "create sets access model=$ac (want 1)  who-can-join radios=$ad (want 2)  stale wording=$ae (want 0)"
  # A restricted world must demand a first player id, the player page must show the player
  # their own id, and Settings must warn on an enforced-but-empty list. The id validation is
  # checked SERVER side -- the form check alone would leave the endpoint open.
  af=$(grep -c "A restricted world needs at least one player ID" /opt/stateless/nginx/www/admin/adminAPI.php)
  ag=$(grep -c "accessFirstId" /opt/stateless/nginx/www/admin/new_world.php)
  ah=$(grep -c "steamid-self" /opt/stateless/nginx/www/public/authenticated.php)
  ai=$(grep -c "\.steamid-self" /opt/stateless/nginx/www/css/phvalheimStyles.css)
  aj=$(grep -c "function writeToClipboard" /opt/stateless/nginx/www/public/authenticated.php)
  ak=$(grep -c "emptyAccessListOverlay" /opt/stateless/nginx/www/admin/index.php)
  al=$(grep -c "maybeWarnEmptyAccessList" /opt/stateless/nginx/www/admin/index.php)
  echo "create demands id: server=$af (want 1)  form=$ag (want 10)"
  echo "self steamid: markup=$ah (want 5)  css=$ai (want 4)  shared clipboard=$aj (want 1)"
  echo "empty-list modal: overlay=$ak (want 3)  predicate=$al (want 2)"
  # Access IDs are stored and shown in the V_ form. The NEGATIVE is the important one: the old
  # digits-only regex must be GONE from both the create endpoint and the create form, or the
  # V_ value the form now tells you to paste is rejected by the thing validating it.
  au=$(grep -cF "valid[] = \$canonical" /opt/stateless/nginx/www/includes/accesslists.php)
  av=$(grep -c "steamAccessID" /opt/stateless/nginx/www/public/authenticated.php)
  aw=$(grep -A14 "^\.steamid-self {" /opt/stateless/nginx/www/css/phvalheimStyles.css | grep -c "white-space: nowrap")
  ax=$(grep -c "placeholder=\"V_76561197960287930\"" /opt/stateless/nginx/www/admin/new_world.php)
  ay=$(grep -hc "\^\[0-9\]{17}\$" /opt/stateless/nginx/www/admin/adminAPI.php /opt/stateless/nginx/www/admin/new_world.php | paste -sd+ | bc)
  az=$(grep -c "canonicalFirstId" /opt/stateless/nginx/www/admin/adminAPI.php)
  echo "canonical stored=$au (want 1)  player-page V_ id=$av (want 3)  id does not wrap=$aw (want 1)"
  echo "create example is V_=$ax (want 1)  stale digits-only regex=$ay (want 0)  create canonicalises=$az (want 3)"
  # Crossplay is vanilla-only again. The launch gate is the one that matters -- the UI gates
  # are cosmetic without it -- so match something only the GATED version contains, rather than
  # the word crossplay, which appears either way.
  #
  # NO APOSTROPHES anywhere in this section, including in comments: the whole block is inside
  # sh -c and a single quote closes it early, silently skipping every later check. That trap is
  # called out at the top of this file and it still caught me twice here.
  ba=$(grep -c "crossplay set but is MODDED" /opt/stateless/games/valheim/scripts/startWorld.sh)
  bb=$(grep -c "crossplayRow" /opt/stateless/nginx/www/admin/index.php)
  bc=$(grep -c "crossplayOption" /opt/stateless/nginx/www/admin/new_world.php)
  bd=$(grep -c "isVanilla && !empty(.vanillaOptions..crossplay..)" /opt/stateless/nginx/www/admin/adminAPI.php)
  echo "crossplay vanilla-only: launch gate=$ba (want 1)  settings row=$bb (want 2)  create option=$bc (want 2)  createWorld gate=$bd (want 1)"
  # World cards online-first, and the player id on its own line. The stale ORDER BY is a
  # NEGATIVE: the PHP sort would mask its return, so nothing would visibly break.
  be=$(grep -c "function sortWorldsOnlineFirst" /opt/stateless/nginx/www/includes/db_gets.php)
  bf=$(grep -c "sortWorldsOnlineFirst(.getMyWorlds, .worldIsOnline)" /opt/stateless/nginx/www/public/authenticated.php)
  bg=$(grep -c "ORDER BY currentMemory" /opt/stateless/nginx/www/includes/db_gets.php)
  bh=$(grep -A6 "^\.steamid-self {" /opt/stateless/nginx/www/css/phvalheimStyles.css | grep -c "display: block")
  echo "card order: sorter=$be (want 1)  caller=$bf (want 1)  stale ORDER BY=$bg (want 0)  id on own line=$bh (want 1)"
  # Card tables MUST stay height=100% -- that is what makes the cards roomy. Removing it
  # collapsed every card to its content and the UI came out squashed. The name/Launch/hint rows
  # are pinned in CSS instead, so they stop claiming a share of the slack. 3 = header + 2 cards.
  bi=$(grep -c "table width=100% height=100%" /opt/stateless/nginx/www/public/authenticated.php)
  bj=$(grep -c "catbox th.card_worldLaunch" /opt/stateless/nginx/www/css/phvalheimStyles.css)
  bk=$(grep -c "vanilla-hint. colspan=2" /opt/stateless/nginx/www/public/authenticated.php)
  echo "card layout: full-height tables=$bi (want 3)  header rows pinned=$bj (want 1)  hint in table=$bk (want 1)"
  # Row spacing, session-scoped backend detection, and the dropped Server row.
  bl=$(grep -c "card-slack" /opt/stateless/nginx/www/public/authenticated.php)
  bm=$(grep -c "function phvCurrentSessionTail" /opt/stateless/nginx/www/includes/db_gets.php)
  bn=$(grep -c "function worldIsPlayFab" /opt/stateless/nginx/www/includes/db_gets.php)
  bo=$(grep -c "serverRow" /opt/stateless/nginx/www/public/authenticated.php)
  echo "row slack rows=$bl (want 2)  session tail=$bm (want 1)  worldIsPlayFab=$bn (want 1)  serverRow=$bo (want 2)"
  # The plugin parents must be created BEFORE the unzip that needs them, and exit 11 must stay
  # tolerated -- treating it as failure would mark every modded world broken.
  am=$(grep -c "mkdir -p \$worldsDirectoryRoot/\$worldName/game/BepInEx/plugins" /opt/stateless/engine/includes/0-functions.sh)
  an=$(grep -c "unzipResult -ne 11" /opt/stateless/engine/includes/0-functions.sh)
  mkline=$(grep -n "mkdir -p \$worldsDirectoryRoot/\$worldName/game/BepInEx/plugins" /opt/stateless/engine/includes/0-functions.sh | head -1 | cut -d: -f1)
  uzline=$(grep -n "BepInEx/plugins/\$modName/" /opt/stateless/engine/includes/0-functions.sh | head -1 | cut -d: -f1)
  ao=0; [ -n "$mkline" ] && [ -n "$uzline" ] && [ "$mkline" -lt "$uzline" ] && ao=1
  echo "plugins mkdir=$am (want 1)  tolerates exit 11=$an (want 1)  mkdir before unzip=$ao (want 1)"
  # Both admin launch paths must go through the shared helper, and the unconditional +connect
  # must be GONE from both -- checking only that the helper is called would pass on an image
  # where one path still built its own href.
  ap=$(grep -c "function getVanillaJoinInfo" /opt/stateless/nginx/www/includes/db_gets.php)
  aq=$(grep -c "getVanillaJoinInfo(" /opt/stateless/nginx/www/admin/index.php)
  ar=$(grep -c "getVanillaJoinInfo(" /opt/stateless/nginx/www/admin/adminAPI.php)
  as=$(grep -c "function launchButtonHtml" /opt/stateless/nginx/www/admin/index.php)
  at=$(grep -hcE "^[[:space:]]*\? .steam://run/892970//\+connect ." /opt/stateless/nginx/www/admin/index.php /opt/stateless/nginx/www/admin/adminAPI.php | paste -sd+ | bc)
  echo "shared join helper=$ap (want 1)  admin render=$aq (want 3)  admin poll=$ar (want 2)"
  echo "js null-href guard=$as (want 1)  stale unconditional +connect=$at (want 0)"

  # ---- 2.41 ----------------------------------------------------------------------------
  # Still NO APOSTROPHES below, comments included. The whole block is inside sh -c and one
  # quote ends it early, silently skipping every later check.
  # The Dockerfile is not copied into the image, so read the ENV it set. This is the value
  # dbUpdater and the admin UI actually see.
  # Compared against EXPECT_VER passed in from the Dockerfile, NOT a literal. This was
  # pinned to 2.43 and duly failed the 2.44 build on an image that was correct -- a marker
  # that has to be hand-edited every release is a marker that cries wolf every release.
  ver=0; [ "${phvalheimVersion:-}" = "${EXPECT_VER:-}" ] && ver=1
  # A live world is described by what it was STARTED with, not by the saved columns. The
  # snapshot writer and all four readers have to ship together -- the readers alone would fall
  # back to the database for every world and the bug would look fixed while being present.
  bp=$(grep -c "running-options" /opt/stateless/games/valheim/scripts/startWorld.sh)
  bq=$(grep -c "function runningWorldOptions" /opt/stateless/nginx/www/includes/db_gets.php)
  br=$(grep -c "function savedWorldOptions" /opt/stateless/nginx/www/includes/db_gets.php)
  bs=$(grep -c "function effectiveWorldOptions" /opt/stateless/nginx/www/includes/db_gets.php)
  bt=$(grep -c "function worldRestartPending" /opt/stateless/nginx/www/includes/db_gets.php)
  bu=$(grep -c "restartPending" /opt/stateless/nginx/www/admin/index.php)
  bv=$(grep -c "restartPending" /opt/stateless/nginx/www/admin/adminAPI.php)
  bw=$(grep -c "restart-pending-badge" /opt/stateless/nginx/www/css/phvalheimStyles.css)
  echo "version=$phvalheimVersion matches Dockerfile=$EXPECT_VER -> $ver (want 1)"
  echo "runtime snapshot: writer=$bp (want 1)  readers=$bq/$br/$bs/$bt (want 1 each)"
  echo "restart pending: ui=$bu (want 7)  poll=$bv (want 1)  css=$bw (want 1)"
  # A vanilla world may run with no password; what it cannot do is run LISTED without one.
  # Both surfaces gate the listing control.
  bx=$(grep -c "syncListedAvailability" /opt/stateless/nginx/www/admin/index.php)
  by=$(grep -c "syncListedAvailability" /opt/stateless/nginx/www/admin/new_world.php)
  echo "listing gated: settings=$bx (want 3)  create=$by (want 2)"
  # Access pills. The removed one is a NEGATIVE: counting only the new pills would pass on an
  # image that still carried the old IN SERVER BROWSER pill alongside them.
  bz=$(grep -c "function accessBadges" /opt/stateless/nginx/www/public/authenticated.php)
  ca=$(grep -c "accessBadge(.published." /opt/stateless/nginx/www/public/authenticated.php)
  cb=$(grep -c "accessBadge(.password." /opt/stateless/nginx/www/public/authenticated.php)
  cc=$(grep -c ">in server browser<" /opt/stateless/nginx/www/public/authenticated.php)
  echo "access pills: helper=$bz (want 1)  published=$ca (want 1)  password=$cb (want 1)  stale listed pill=$cc (want 0)"
  # One switch size everywhere. The override being GONE is the check that matters -- the base
  # rule shrinking while a 44x24 override survived is exactly the bug being fixed.
  cd=$(grep -c "pv-panel .switch" /opt/stateless/nginx/www/css/phvalheimStyles.css)
  ce=$(grep -A5 "^\.switch {" /opt/stateless/nginx/www/css/phvalheimStyles.css | grep -c "width: 32px")
  cf=$(grep -c "slider::after" /opt/stateless/nginx/www/css/phvalheimStyles.css)
  echo "switches: stale override=$cd (want 0)  base 32px=$ce (want 1)  hit-area pseudo=$cf (want 2)"
  # The self-hosted font. OFL.txt is a LICENCE CONDITION, not tidiness -- it must ship with
  # the files. The malformed spacer td that never closed its tag is a negative.
  cg=$(ls /opt/stateless/nginx/www/css/fonts/*.woff2 2>/dev/null | wc -l)
  ch=$(ls /opt/stateless/nginx/www/css/fonts/OFL.txt 2>/dev/null | wc -l)
  ci=$(grep -c "JetBrainsMono-" /opt/stateless/nginx/www/css/phvalheimStyles.css)
  cj=$(grep -c "td style=.height: 12px;." /opt/stateless/nginx/www/public/authenticated.php)
  ck=$(grep -c "card-gap" /opt/stateless/nginx/www/public/authenticated.php)
  echo "font: woff2=$cg (want 3)  OFL=$ch (want 1)  face rules=$ci (want 3)"
  echo "spacer: malformed td=$cj (want 0)  card-gap cell=$ck (want 2)"

  # ---- 2.43: the multi-source mod catalogue ---------------------------------------------
  # STILL no apostrophes below, comments included -- the whole body is inside sh -c and one
  # quote ends it early, silently skipping every check after it.
  #
  # python3 is FIRST because everything else here depends on it. The mod catalogue sync and a
  # world mod resolution are both Python, and before 2.43 python3 was in the image only as a
  # transitive dependency of software-properties-common. If this is 0 the whole mod system is
  # gone and no other check would say so.
  da=0; command -v python3 >/dev/null 2>&1 && da=1
  db=0; [ -x /opt/stateless/engine/tools/modSync.py ] && db=1
  dc=0; [ -x /opt/stateless/engine/tools/worldMods.py ] && dc=1
  dd=0; [ -x /opt/stateless/engine/dbUpdates/dbUpdate_2.43.sh ] && dd=1
  # Both scripts must actually COMPILE in the image. A syntax error would otherwise only
  # surface at the first cron tick, hours after the build reported success.
  de=0; python3 -m py_compile /opt/stateless/engine/tools/modSync.py 2>/dev/null && de=1
  df=0; python3 -m py_compile /opt/stateless/engine/tools/worldMods.py 2>/dev/null && df=1
  echo "2.43 python: interpreter=$da (want 1)  modSync=$db worldMods=$dc migration=$dd (want 1 each)"
  echo "2.43 python compiles: modSync=$de worldMods=$df (want 1 each)"

  # The new cron must be in, and BOTH old Thunderstore crons out. Checking only that modSync
  # is present would pass on an image still running the 12-hourly bash re-parse alongside it.
  dg=$(ls /etc/cron.d/modSync 2>/dev/null | wc -l)
  dh=$(ls /etc/cron.d/tsSyncLocalParse /etc/cron.d/tsSyncRemoteParse 2>/dev/null | wc -l)
  echo "2.43 cron: modSync=$dg (want 1)  stale ts crons=$dh (want 0)"

  # Case-SENSITIVE identity columns. Without as_cs the unique key treats Iron_ModPack and
  # Iron_Modpack as one row and they overwrite each other on every sync -- 22 such pairs exist
  # on Thunderstore. 6 = owner/name on mods, version on mod_versions, and the migration joins.
  di=$(grep -c "utf8mb4_0900_as_cs" /opt/stateless/engine/dbUpdates/dbUpdate_2.43.sh)
  dj=$(grep -c "content_hash" /opt/stateless/engine/dbUpdates/dbUpdate_2.43.sh)
  echo "2.43 collation: as_cs mentions=$di (want >0)  content_hash=$dj (want >0)"

  # The install path must use the STORED download_url. The old template only ever worked for
  # Thunderstore -- Hexium serves from cdn.hexium.gg behind an opaque numeric path -- so the
  # stale template being GONE is the check that matters.
  #
  # Counted BY MODE, not as a bare count of the tool name. 2.43 cares that these three
  # specific invocations exist; a bare count also moves whenever a later release adds a call
  # site, which is what happened in 2.47 (--record-installed) -- and the wrong fix is to
  # relax this number to absorb it, because then the 2.43 line no longer asserts anything
  # about 2.43. The total call-site count lives in the 2.47 block instead.
  dk=$(grep -cE "worldMods.py --world .+(--resolve|--plan|--viewer-json)" /opt/stateless/engine/includes/0-functions.sh)
  dl=$(grep -c "tsModDownloadUrl/\$modAuthor" /opt/stateless/engine/includes/0-functions.sh)
  dm=$(grep -c "requiredMods=" /opt/stateless/engine/includes/phvalheim-static.conf)
  dn=$(grep -c "requiredTsMods=" /opt/stateless/engine/includes/phvalheim-static.conf)
  echo "2.43 install path: resolve/plan/viewer calls=$dk (want 3)  stale url template=$dl (want 0)"
  echo "2.43 required mods by owner/name=$dm (want 1)  stale uuid list=$dn (want 0)"

  # The picker and the panel. Pills for BOTH sources must be styled or the source marker is
  # invisible, and modcatalog.php is what every endpoint now reads through.
  do_=$(ls /opt/stateless/nginx/www/includes/modcatalog.php 2>/dev/null | wc -l)
  dp=$(grep -c "src-pill.src-ts" /opt/stateless/nginx/www/css/phvalheimStyles.css)
  dq=$(grep -c "src-pill.src-hex" /opt/stateless/nginx/www/css/phvalheimStyles.css)
  dr=$(grep -c "modSourceFilter" /opt/stateless/nginx/www/admin/new_world.php)
  ds=$(grep -c "modSourceFilter" /opt/stateless/nginx/www/admin/edit_world.php)
  dt=$(grep -c "modSyncPanel" /opt/stateless/nginx/www/admin/index.php)
  # The picker must key on mods.id, not the source uuid: 600 package uuids exist in BOTH
  # catalogues, so a uuid key renders one checkbox for two different mods.
  du=$(grep -hc "mod.moduuid" /opt/stateless/nginx/www/admin/new_world.php /opt/stateless/nginx/www/admin/edit_world.php | paste -sd+ | bc)
  echo "2.43 catalogue lib=$do_ (want 1)  pill css ts=$dp hex=$dq (want >0 each)"
  echo "2.43 source filter: new=$dr edit=$ds (want >0 each)  sync panel=$dt (want >0)"
  echo "2.43 stale moduuid keying=$du (want 0)"

  # The version selector and the pin column, on both forms.
  dv=$(grep -hc "mod-version" /opt/stateless/nginx/www/admin/new_world.php /opt/stateless/nginx/www/admin/edit_world.php | paste -sd+ | bc)
  dw=$(grep -c "getModVersions" /opt/stateless/nginx/www/admin/adminAPI.php)
  dx=$(grep -c "pin_version_id" /opt/stateless/nginx/www/includes/modcatalog.php)
  echo "2.43 version selector refs=$dv (want >0)  versions endpoint=$dw (want >0)  pin column=$dx (want >0)"

  # Release notes for THIS version, or the upgrade modal shows nothing.
  dy=$(grep -c "2.43. => \[" /opt/stateless/nginx/www/includes/whatsnew.php)
  echo "2.43 whatsnew entry=$dy (want 1)"

  # world_mods is keyed on worlds.id, so a delete that leaves its rows behind is not just
  # untidy: InnoDB recomputes AUTO_INCREMENT as MAX(id)+1 on restart, so the next world
  # created can be handed a recycled id and inherit the deleted world mod selection.
  # BOTH delete paths must clear it (normal delete AND failed deployment), hence 2.
  dz=$(grep -c "deleteWorldModRows" /opt/stateless/engine/phvalheim)
  ea=$(grep -c "function deleteWorldModRows" /opt/stateless/engine/includes/0-functions.sh)
  eb=$(grep -c "function pruneOrphanedWorldMods" /opt/stateless/engine/includes/0-functions.sh)
  ec=$(grep -c "^pruneOrphanedWorldMods" /opt/stateless/engine/phvalheim)
  # The vanilla switch purge has to reach world_mods too, or it reports success and then
  # builds the world with its full mod list anyway.
  ed=$(grep -c "DELETE wm FROM world_mods" /opt/stateless/nginx/www/includes/db_sets.php)
  echo "2.43 orphan guard: delete calls=$dz (want 2)  fn=$ea sweep fn=$eb boot sweep=$ec (want 1 each)"
  echo "2.43 vanilla purge clears world_mods=$ed (want 1)"

  # ---- the tsSync removal: NEGATIVES ----------------------------------------------------
  # All of these are "must be zero". A positive check that the new sync exists would pass
  # perfectly well on an image still carrying the old one alongside it, which is the state
  # that actually causes trouble -- two syncs writing the same tables.
  ee=$(ls /opt/stateless/engine/tools/tsSyncLocalParse.sh \
          /opt/stateless/engine/tools/tsSyncLocalParseMultithreaded.sh \
          /opt/stateless/engine/tools/tsSyncRemoteParse.sh \
          /opt/stateless/engine/tools/tsPrune.sh \
          /opt/stateless/engine/tools/tsModDepGetter.sh \
          /opt/stateless/engine/tools/modLookup.sh \
          /opt/stateless/engine/tools/exportTsModsSeed.sh 2>/dev/null | wc -l)
  ef=$(ls -d /opt/stateless/engine/tools/ts_wip /opt/stateless/nginx/www/todelete 2>/dev/null | wc -l)
  # tsSeeder fetched a 14MB dump from GitHub straight into mysql with no integrity check.
  eg=$(grep -c "tsSeeder" /opt/stateless/engine/tools/newdbMySQL.sh)
  eh=$(grep -c "create table tsmods" /opt/stateless/engine/tools/newdbMySQL.sh)
  echo "2.43 old sync scripts gone=$ee (want 0)  scratch/graveyard dirs gone=$ef (want 0)"
  echo "2.43 tsSeeder call gone=$eg (want 1: the comment only)  fresh-install tsmods gone=$eh (want 0)"

  # The sidebar nav item and the three helpers it drove. Checked in the SOURCE here; the
  # browser oracle (dev_tools/test-modsync-panel.js) checks the rendered page.
  ei=$(grep -c "tsSyncTool\|tsSyncStop\|tsSyncIcon" /opt/stateless/nginx/www/admin/index.php)
  ej=$(grep -c "function confirmThunderstoreSync\|function stopThunderstoreSync\|function updateTsSyncStatus" /opt/stateless/nginx/www/admin/index.php)
  ek=$(grep -c "manual_ts_sync_start" /opt/stateless/nginx/www/admin/index.php)
  el=$(grep -c "function stopTsSyncJson\|case .stopTsSync." /opt/stateless/nginx/www/admin/adminAPI.php)
  em=$(grep -c "ss-thunderstore_chunk_size\|ss-thunderstore_local_sync" /opt/stateless/nginx/www/admin/index.php)
  echo "2.43 sidebar sync gone: ids=$ei helpers=$ej handler=$ek api=$el settings=$em (want 0 each)"

  # Mod counts must come from world_mods. Reading the legacy columns reported 0 for every
  # world -- a wrong number that looks exactly like a correct one.
  # en is 1, not 0: one comment in db_gets.php names the legacy column to explain why the
  # counts no longer read it. The assertion that matters is ep -- no live tsmods query.
  en=$(grep -c "thunderstore_mods" /opt/stateless/nginx/www/includes/db_gets.php)
  eo=$(grep -c "FROM world_mods" /opt/stateless/nginx/www/includes/db_gets.php)
  ep=$(grep -c "FROM tsmods" /opt/stateless/nginx/www/includes/db_gets.php)
  er=$(grep -c "FROM tsmods" /opt/stateless/engine/tools/worldBackup)
  echo "2.43 counts from world_mods: legacy col refs=$en (want 1: a comment) joins=$eo (want 3) tsmods=$ep (want 0)"
  echo "2.43 backup manifest tsmods read gone=$er (want 0)"

  # ---- per-catalogue live sync log -------------------------------------------------------
  es=$(grep -c "CREATE TABLE mod_sync_log" /opt/stateless/engine/dbUpdates/dbUpdate_2.43.sh)
  et=$(grep -c "phase_timings" /opt/stateless/engine/dbUpdates/dbUpdate_2.43.sh)
  # The engine must RECORD lines, not just print them, or the pane has nothing to show.
  eu=$(grep -c "def record" /opt/stateless/engine/tools/modSync.py)
  ev=$(grep -c "INSERT INTO mod_sync_log" /opt/stateless/engine/tools/modSync.py)
  # _CURRENT_RUN must be cleared in a finally, or one catalogue log absorbs the next.
  ew=$(grep -c "_CURRENT_RUN = None" /opt/stateless/engine/tools/modSync.py)
  ex=$(grep -c "def prune_logs" /opt/stateless/engine/tools/modSync.py)
  echo "2.43 log table=$es timings col=$et (want >0)  record fn=$eu insert=$ev reset=$ew prune=$ex"

  # The API and the pane.
  # No apostrophes: this whole block is inside sh -c SINGLE quotes, so a quoted case label
  # closes it early and the grep matches nothing. That is the trap called out at the top of
  # this file, and it caught this line on its first run -- reporting a perfectly good image
  # as broken.
  ey=$(grep -c getModSyncLog /opt/stateless/nginx/www/admin/adminAPI.php)
  ez=$(grep -c "function modSyncLog" /opt/stateless/nginx/www/includes/modcatalog.php)
  fa=$(grep -c "logPaneHtml\|pollModSyncLog" /opt/stateless/nginx/www/admin/index.php)
  fb=$(grep -c "pre.ms-log" /opt/stateless/nginx/www/css/phvalheimStyles.css)
  # The poll must NOT pin itself to a known run id -- that latches the pane onto the first
  # run it sees and it then silently describes the wrong sync forever.
  fc=$(grep -c "Deliberately WITHOUT runId" /opt/stateless/nginx/www/admin/index.php)

  # duplicate-plugin collapse (one BepInEx for a world spanning both catalogues)
  fd=$(grep -c "def by_plugin" /opt/stateless/engine/tools/worldMods.py)
  fe=$(grep -c "def install_rows" /opt/stateless/engine/tools/worldMods.py)
  ff=$(grep -c "def version_key" /opt/stateless/engine/tools/worldMods.py)
  # viewer_json must go through install_rows, not world_mods directly -- reading the table
  # is what listed the plugin twice.
  fg=$(grep -c "install_rows(wid)" /opt/stateless/engine/tools/worldMods.py)
  # NEGATIVE: the pre-prune counts line must no longer claim a removal
  fh=$(grep -c "mods +{len(new_m)} ~{len(chg_m)} -{len(gone_m)}" /opt/stateless/engine/tools/modSync.py)
  fi_=$(grep -c "delisted by the source" /opt/stateless/engine/tools/modSync.py)
  fj=$(grep -c "removed {len(gone_m)} mod(s)" /opt/stateless/engine/tools/modSync.py)

  # boot sync: both catalogues on every start, no longer empty-catalogue-only
  fk=$(grep -c "^function syncModCatalogue()" /opt/stateless/engine/includes/0-functions.sh)
  fl=$(grep -c "^syncModCatalogue" /opt/stateless/engine/phvalheim)
  # NEGATIVE: the empty-only seeder name must be gone from BOTH the function and the caller,
  # or the engine calls a name that no longer exists and the catalogue never syncs at boot.
  # grep -o + wc, NOT awk: a single-quoted awk program inside this sh -c block closes the
  # outer quote, so $2 reaches the OUTER bash and dies on set -u. Same trap as the rest of
  # this file -- no single quotes anywhere in here.
  fm=$(grep -o seedModCatalogue /opt/stateless/engine/includes/0-functions.sh \
       /opt/stateless/engine/phvalheim 2>/dev/null | wc -l)
  # The sync must NOT be forced (a full refetch on every container restart) and must use
  # trigger=boot (trigger=cron obeys modSyncIntervalHours and would silently skip).
  fn=$(grep -c "trigger boot" /opt/stateless/engine/includes/0-functions.sh)
  fo=$(grep -A1 "setsid /opt/stateless/engine/tools/modSync.py" \
       /opt/stateless/engine/includes/0-functions.sh | grep -c -- "--force")
  echo "2.43 boot sync fn=$fk caller=$fl (want 1/1)  old seeder refs=$fm (want 0)"
  echo "2.43 boot sync trigger=$fn (want 1)  forced=$fo (want 0)"

  # mod picker: a toggle must not send the operator back to the top of the list
  fp=$(grep -c "function redrawInPlace" /opt/stateless/nginx/www/admin/new_world.php)
  fq=$(grep -c "function redrawInPlace" /opt/stateless/nginx/www/admin/edit_world.php)
  # NEGATIVE: a bare draw() is draw(true) and resets paging -- it must be gone from BOTH.
  fr=$(grep -o "rows.add(activeRows).draw();" /opt/stateless/nginx/www/admin/new_world.php \
       /opt/stateless/nginx/www/admin/edit_world.php 2>/dev/null | wc -l)
  fs=$(grep -o "rows.add(allRows).draw();" /opt/stateless/nginx/www/admin/new_world.php \
       /opt/stateless/nginx/www/admin/edit_world.php 2>/dev/null | wc -l)
  ft=$(grep -c "mod-checkbox\[type=.checkbox.\]" /opt/stateless/nginx/www/css/phvalheimStyles.css)
  echo "2.43 picker redrawInPlace new=$fp edit=$fq (want 1/1)  bare draw() left=$fr/$fs (want 0/0)"
  echo "2.43 picker checkbox css rules=$ft (want >0)"

  # duplicate-plugin save guard: one selection row per (owner, name)
  fu=$(grep -c "One selection per PLUGIN" /opt/stateless/nginx/www/includes/modcatalog.php)
  fv=$(grep -c "was not added" /opt/stateless/nginx/www/includes/modcatalog.php)
  # Ranking must match install_rows(), so the warning names the copy that actually installs.
  fw=$(grep -c "version_compare" /opt/stateless/nginx/www/includes/modcatalog.php)
  # NEGATIVE: a case-insensitive key would merge the 22 real owner/name pairs that differ
  # only in capitalisation, silently dropping a mod the operator legitimately chose.
  fx=$(grep -c "strtolower" /opt/stateless/nginx/www/includes/modcatalog.php)
  echo "2.43 dup-plugin save guard=$fu msg=$fv rank=$fw (want >0)  strtolower=$fx (want 0)"

  # ---- 2.44 ----
  # unzip exit 1 is a SUCCESS code. NEGATIVE markers: the old fatal tests must be gone, or a
  # backslash-packed mod still freezes the world.
  ga=$(grep -c "unzipResult -gt 1" /opt/stateless/engine/includes/0-functions.sh)
  gb=$(grep -c "unzipResult -ne 0" /opt/stateless/engine/includes/0-functions.sh)
  gc=$(grep -c "RESULT -le 1" /opt/stateless/engine/includes/0-functions.sh)
  # Anchored to "if", and the dollar is a wildcard dot: a bare RESULT = 0 also matches the
  # SteamCMD retry loop (while [ $RESULT = 0 ]) at the top of this file, which is unrelated
  # and correct -- that made this marker fail on a perfectly good image. A literal dollar
  # would also be expanded by the inner sh, which has no RESULT set.
  gd=$(grep -cE "if \[ .RESULT = 0 \]" /opt/stateless/engine/includes/0-functions.sh)
  # the loader is not a mod
  ge=$(grep -c "function loaderExclusionSql" /opt/stateless/nginx/www/includes/modcatalog.php)
  gf=$(grep -c "def is_loader" /opt/stateless/engine/tools/worldMods.py)
  gg=$(ls /opt/stateless/engine/dbUpdates/dbUpdate_2.44.sh 2>/dev/null | wc -l)
  # generateModViewerJson must take its world as an argument, not a leaked global
  gh=$(grep -c "refusing to guess" /opt/stateless/engine/includes/0-functions.sh)
  # printenv filtered to valid shell identifiers
  gi=$(grep -c "A-Za-z_" /opt/stateless/engine/phvalheim)
  echo "2.44 unzip guard fixed=$ga (want 1)  old fatal test gone=$gb (want 0)"
  echo "2.44 bepinex guard fixed=$gc (want 1)  old test gone=$gd (want 0)"
  echo "2.44 loader excl php=$ge py=$gf migration=$gg (want 1/1/1)"
  echo "2.44 viewer arg guard=$gh (want 1)  printenv filter=$gi (want >0)"
  echo "2.43 log api=$ey lib=$ez pane=$fa css=$fb follows-new-run=$fc (want >0 each)"

  # ---- 2.45: the provider-agnostic AI Helper (issue #83) -------------------------
  #
  # NO APOSTROPHES anywhere below. The whole verify is inside sh -c ...  and one
  # apostrophe closes it early, silently skipping every check after it. That is how this
  # script once reported a clean build while running none of its last four markers.
  #
  # The headline markers are NEGATIVE. 2.44 held three hardcoded model tables and a
  # validator that silently rewrote an unrecognised model; an image that still carried
  # them would pass any count of the new files alone.
  ha=$(ls /opt/stateless/nginx/www/includes/aiproviders.php /opt/stateless/nginx/www/includes/aicontext.php /opt/stateless/nginx/www/includes/aidiagnose.php /opt/stateless/nginx/www/admin/aiStream.php 2>/dev/null | wc -l)
  # Existence is NOT enough. The first 2.45 RC shipped this file mode 660; dbUpdater.sh
  # ran it as a bare path, got exit 126, logged nothing because it matches neither of its
  # two branches, and the tables were silently never created. Test that it is EXECUTABLE.
  hb=$(test -x /opt/stateless/engine/dbUpdates/dbUpdate_2.45.sh && echo 1 || echo 0)
  # And that dbUpdater can no longer swallow a non-0/1 exit code from any migration.
  hv=$(grep -c "could not be run" /opt/stateless/engine/tools/dbUpdater.sh)
  hw=$(grep -c "bash \"\$dbUpdateScript\"" /opt/stateless/engine/tools/dbUpdater.sh)
  # The 2.44 dispatcher and its model tables must be GONE from adminAPI.php.
  # Match the DEFINITION. The 2.45 header comment in adminAPI.php names both of these dead
  # functions deliberately, so a bare string count says they are still there when they are not.
  hc=$(grep -c "function aiHelperDispatch" /opt/stateless/nginx/www/admin/adminAPI.php)
  hd=$(grep -c "allowedModels" /opt/stateless/nginx/www/admin/adminAPI.php)
  he=$(grep -c "function getOllamaModels" /opt/stateless/nginx/www/admin/adminAPI.php)
  # No model identifier may appear ANYWHERE in the AI sources, comments included. The
  # shipped image has no PHP tokenizer handy, so this is the blunt version of the guard
  # in dev_tools/test-ai-helper.sh -- which is why the comments in those files are
  # written to discuss the bug without ever naming a model.
  hf=$(cat /opt/stateless/nginx/www/includes/aiproviders.php /opt/stateless/nginx/www/includes/aicontext.php /opt/stateless/nginx/www/includes/aidiagnose.php /opt/stateless/nginx/www/admin/aiStream.php | grep -vE "^[[:space:]]*(\*|//|#)" | grep -cEi "gpt-[0-9o]|claude-(opus|sonnet|haiku)|gemini-[0-9]|llama-?[0-9]")
  # Live discovery per kind: the four endpoints must all be reachable in the code.
  # /api/tags is the NATIVE Ollama discovery endpoint. The dedicated kind is gone, so this
  # must now be ABSENT -- a leftover would mean the native adapter came back.
  hg=$(grep -c "api/tags" /opt/stateless/nginx/www/includes/aiproviders.php)
  hh=$(grep -c "v1beta/models" /opt/stateless/nginx/www/includes/aiproviders.php)
  hi=$(grep -c "generateContent" /opt/stateless/nginx/www/includes/aiproviders.php)
  # adminAPI must actually pull the new libraries in, or every AI action fatals.
  # Anchor on require_once: the header comment cites both paths in prose as well.
  hj=$(grep -c "require_once .*includes/aiproviders.php" /opt/stateless/nginx/www/admin/adminAPI.php)
  hk=$(grep -c "require_once .*includes/aicontext.php" /opt/stateless/nginx/www/admin/adminAPI.php)
  # The legacy settings columns must have no live readers left. A migration that changes
  # no read sites is how the 2.43 world-card mod counts broke: the columns still hold
  # plausible values, so a leftover reader returns a believable wrong answer.
  hl=$(grep -c "openaiApiKey" /opt/stateless/engine/tools/pushAnalytics.sh)
  hm=$(grep -c "ai_providers" /opt/stateless/engine/tools/pushAnalytics.sh)
  hn=$(grep -c "setup-openaiApiKey" /opt/stateless/nginx/www/admin/setup.php)
  # config_env_puller must no longer BUILD the aiKeys array. Counting the string alone
  # would pass on the comment that explains why it is gone, so match the assignment.
  ho=$(grep -c "aiKeys = \[" /opt/stateless/nginx/www/includes/config_env_puller.php)
  # The UI half: panel, wizard and diagnostics styling all have to ship together. The
  # markup alone renders an unstyled panel, which reads as nothing having been done.
  hp=$(grep -c "aiStream.php" /opt/stateless/nginx/www/admin/index.php)
  hq=$(grep -c "aiWizardOverlay" /opt/stateless/nginx/www/admin/index.php)
  hr=$(grep -c "ai-diag-evidence" /opt/stateless/nginx/www/css/phvalheimStyles.css)
  hs=$(grep -c "ai-wiz-step" /opt/stateless/nginx/www/css/phvalheimStyles.css)
  # The wizard opens from Server Settings, so it must stack above it. At the base
  # z-index it renders behind its own dim layer and cannot be dismissed.
  ht=$(grep -c "aiWizardOverlay\" style=\"z-index:1060" /opt/stateless/nginx/www/admin/index.php)
  hu=$(grep -c "2.45" /opt/stateless/nginx/www/includes/whatsnew.php)
  # The wizard must ask for a model BEFORE it tests one, and nothing may select a model
  # out of the discovered list. Test-before-Model forces the round trip to invent a probe
  # target, and the only thing it can invent is models[0] -- which on a live Gemini account
  # is a preview tier that refuses systemInstruction, failing a good key over a model the
  # operator never chose. Both halves ship or neither does.
  #
  # NOTE: every quote below is written as a dot. This whole verify payload is carried
  # inside a single-quoted sh -c string, so ONE literal apostrophe -- even in a comment --
  # closes it and silently skips every remaining check. bash -n cannot see it.
  hx=$(grep -c "Credentials., .Model., .Test." /opt/stateless/nginx/www/admin/index.php)
  hy=$(grep -vE "^[[:space:]]*(\*|//|#)" /opt/stateless/nginx/www/includes/aiproviders.php | grep -cE "models.{0,3}\[0\]")
  hz=$(grep -c "case .discoverAiModels." /opt/stateless/nginx/www/admin/adminAPI.php)
  # And the helper must leave a trace on disk when it fails. Anchor on the definition.
  ia=$(grep -c "function aiLog" /opt/stateless/nginx/www/includes/aiproviders.php)
  # A stopped world is not a broken one: the scan must consult world status, and the
  # persona must say so. Without both, eleven deliberately-stopped test worlds read as an
  # outage. Quotes as dots -- see the note above.
  # 2.46 re-anchored this. It read "running = aiTruthy", which pinned the marker to the
  # BUG -- aiTruthy on the status column, which is Down even for a running world. The
  # intent was always "the scan consults whether the world is running", so it now anchors
  # on the predicate that actually answers that.
  ib=$(grep -c "running = aiWorldIsRunning" /opt/stateless/nginx/www/includes/aidiagnose.php)
  ic=$(grep -c "running && count(.starts)" /opt/stateless/nginx/www/includes/aidiagnose.php)
  id=$(grep -c "A STOPPED WORLD IS NOT A BROKEN WORLD" /opt/stateless/nginx/www/includes/aicontext.php)
  ie=$(grep -c "LIVE STATE" /opt/stateless/nginx/www/includes/aicontext.php)
  # And the tool trace must stack, not collapse into a one-character ribbon.
  # Scoped to the .ai-trace-row rule ONLY. Counting break-all across the whole stylesheet
  # caught six unrelated rules and failed a clean build -- the marker was wrong, not the code.
  if_=$(grep -c "flex-direction: column" /opt/stateless/nginx/www/css/phvalheimStyles.css)
  # Match the DECLARATION, not the word. The rule carries a comment explaining why break-all
  # was wrong, and a bare "break-all" search counted that comment -- prose failing a clean
  # build for the second time today.
  ig=$(awk "/^\.ai-trace-row/,/^}/" /opt/stateless/nginx/www/css/phvalheimStyles.css | grep -c "word-break: break-all")
  # Hugin. The mascot is the progress indicator, so the SVG, the states and the working
  # strip all have to ship together -- any one missing leaves a blinking caret and a
  # 17-second silence, which is the thing being fixed.
  # Newer OpenAI models reject max_tokens; the adapter must be able to ask the other way.
  is=$(grep -c "max_completion_tokens" /opt/stateless/nginx/www/includes/aiproviders.php)
  # A no-argument tool call must replay as {} -- json_encode of an empty PHP array is "[]",
  # a JSON list, and a strict gateway rejects it. All three adapters need the object cast.
  jp=$(grep -c "json_encode((object).c..arguments..)" /opt/stateless/nginx/www/includes/aiproviders.php)
  jq=$(grep -cE "input. => .object..c..arguments" /opt/stateless/nginx/www/includes/aiproviders.php)
  jr=$(grep -cE "args. => .object..c..arguments" /opt/stateless/nginx/www/includes/aiproviders.php)
  # Capability negotiation: the loop plus both known quirks must ship together.
  ja=$(grep -c "reasoning_effort" /opt/stateless/nginx/www/includes/aiproviders.php)
  jb=$(grep -c "function (\$res) {" /opt/stateless/nginx/www/includes/aiproviders.php)
  jc=$(grep -c "try <= count(\$quirks)" /opt/stateless/nginx/www/includes/aiproviders.php)
  # A streamed error body must be captured, or every streaming failure is a bare status code
  # and neither adapter retry can ever fire. Anchor on the buffer, not the prose.
  it=$(grep -c "errBody .= .chunk" /opt/stateless/nginx/www/includes/aiproviders.php)
  iu=$(grep -c "onChunk ? .errBody" /opt/stateless/nginx/www/includes/aiproviders.php)
  # Searchable model picker: 130 models do not fit a native select.
  iv=$(grep -c "aiModelPickRender" /opt/stateless/nginx/www/admin/index.php)
  # Switching provider type must re-apply the new type defaults, not keep the first pick.
  jd=$(grep -c "next === w.kind" /opt/stateless/nginx/www/admin/index.php)
  je=$(grep -c "w.label    === prev.label" /opt/stateless/nginx/www/admin/index.php)
  # Presets for the openai_compatible catch-all (vLLM, LM Studio, Ollama /v1 ...).
  jf=$(grep -c "ai-wiz-preset" /opt/stateless/nginx/www/admin/index.php)
  jg=$(grep -c "presets" /opt/stateless/nginx/www/includes/aiproviders.php)
  # The dedicated ollama kind must be GONE (it is a preset now), the native adapter with
  # it, and dbUpdate_2.45.sh must carry the one-shot conversion for rows that already
  # exist. All three ship together or an upgraded install keeps a provider nothing can
  # dispatch -- the 2.43 world-card regression in a new costume.
  jh=$(grep -c "ollama. => \[" /opt/stateless/nginx/www/includes/aiproviders.php)
  ji=$(grep -c "function aiChatOllama" /opt/stateless/nginx/www/includes/aiproviders.php)
  jj=$(grep -c "kind = .ollama." /opt/stateless/engine/dbUpdates/dbUpdate_2.45.sh)
  jk=$(grep -c "addProvider openai_compatible .Ollama." /opt/stateless/engine/dbUpdates/dbUpdate_2.45.sh)
  # A silent rewrite of a URL the operator typed is how "why is this pointing there" starts
  # weeks later, so the conversion raises a one-shot notice. Flag, reader, modal and the
  # dismiss endpoint all ship together or the dialog is unclosable / never appears.
  jl=$(grep -c "aiOllamaNotice" /opt/stateless/engine/dbUpdates/dbUpdate_2.45.sh)
  jm=$(grep -c "aiOllamaNotice" /opt/stateless/nginx/www/includes/config_env_puller.php)
  jn=$(grep -c "aiOllamaNoticeOverlay" /opt/stateless/nginx/www/admin/index.php)
  jo=$(grep -c "function dismissAiOllamaNoticeJson" /opt/stateless/nginx/www/admin/adminAPI.php)
  # Starred models pin to the top. The star must also stop its click bubbling to the row,
  # or pinning a model silently selects it and closes the popup.
  ix=$(grep -c "function aiFavToggle" /opt/stateless/nginx/www/admin/index.php)
  iy=$(grep -c "ai-modelpick-star" /opt/stateless/nginx/www/css/phvalheimStyles.css)
  iz=$(grep -c "e.stopPropagation" /opt/stateless/nginx/www/admin/index.php)
  iw=$(grep -c "ai-modelpick-row" /opt/stateless/nginx/www/css/phvalheimStyles.css)
  ih=$(grep -c "function aiHuginNode" /opt/stateless/nginx/www/admin/index.php)
  # One drawing only: the helper that renders it, and no JS re-description of the paths.
  ip=$(grep -c "function huginSvg" /opt/stateless/nginx/www/includes/hugin.php)
  iq=$(grep -c "Ask Hugin" /opt/stateless/nginx/www/admin/index.php)
  ir=$(grep -c "hg-glint" /opt/stateless/nginx/www/admin/index.php)
  ii=$(grep -c "ai-working" /opt/stateless/nginx/www/admin/index.php)
  ij=$(grep -c "AI_TOOL_PHRASE" /opt/stateless/nginx/www/admin/index.php)
  ik=$(grep -c "ai-panel-hugin" /opt/stateless/nginx/www/admin/index.php)
  il=$(grep -cE "^\.ai-hugin" /opt/stateless/nginx/www/css/phvalheimStyles.css)
  im=$(grep -c "hgFlap" /opt/stateless/nginx/www/css/phvalheimStyles.css)
  # Reduced motion must be honoured, and the timer must be cleared on every exit path.
  in_=$(grep -c "prefers-reduced-motion" /opt/stateless/nginx/www/css/phvalheimStyles.css)
  io=$(grep -c "clearInterval(timer)" /opt/stateless/nginx/www/admin/index.php)
  echo "2.45 tool args as objects: openai=$jp anthropic=$jq gemini=$jr (want 1 each)"

  # Hugin acts. The action layer, its schema, the confirm card and the telemetry all have to
  # be present TOGETHER -- any one of them missing produces a half-working assistant that
  # still looks fine until an operator tries to change something.
  ka=$(grep -c "..tier.. *=>" /opt/stateless/nginx/www/includes/aiactions.php)
  kb=$(grep -c "ai_proposals" /opt/stateless/engine/dbUpdates/dbUpdate_2.45.sh)
  kc=$(grep -c "ai_usage" /opt/stateless/engine/dbUpdates/dbUpdate_2.45.sh)
  kd=$(grep -c "tool_capability" /opt/stateless/engine/dbUpdates/dbUpdate_2.45.sh)
  # The card, its styling, and the endpoint that applies it.
  ke=$(grep -c "aiRenderProposals" /opt/stateless/nginx/www/admin/index.php)
  kf=$(grep -c "ai-proposal-apply" /opt/stateless/nginx/www/css/phvalheimStyles.css)
  kg=$(grep -c "applyAiProposal" /opt/stateless/nginx/www/admin/adminAPI.php)
  # A NEGATIVE: the token must never be handed to the model. If this is not zero, a
  # confirmation token can end up quoted into the chat transcript.
  kh=$(grep -c "token. => .token" /opt/stateless/nginx/www/includes/aiactions.php)
  # Degradation, playbooks, and the UTF-8 carry that stops streamed text vanishing.
  ki=$(grep -c "no_tools" /opt/stateless/nginx/www/includes/aiproviders.php)
  kj=$(grep -c "aiDegradedNotice" /opt/stateless/nginx/www/admin/index.php)
  kk=$(grep -c "OPERATING PROCEDURES" /opt/stateless/nginx/www/includes/aicontext.php)
  kl=$(grep -c "aiUtf8Carry" /opt/stateless/nginx/www/admin/aiStream.php)
  # Telemetry, and the capability card that is generated rather than written down.
  km=$(grep -c "ai_tools_used" /opt/stateless/engine/tools/pushAnalytics.sh)
  kn=$(grep -c "ai_capability" /opt/stateless/engine/tools/pushAnalytics.sh)
  ko=$(grep -c "aiCapabilityCard" /opt/stateless/nginx/www/includes/aiactions.php)
  kp=$(grep -c "aiShowCapabilities" /opt/stateless/nginx/www/admin/index.php)
  echo "actions=$ka (want 12)  schema: proposals=$kb usage=$kc capability=$kd"
  echo "card: render=$ke css=$kf endpoint=$kg  token-leaked-to-model=$kh (want 0)"
  echo "degrade: no_tools=$ki notice=$kj playbooks=$kk utf8carry=$kl"
  echo "telemetry: tools_used=$km capability=$kn  capcard: php=$ko js=$kp"

  # The meet-Hugin one-shot. All four pieces or none: the column, the variable that reads
  # it, the markup, and the endpoint that clears it. A missing config_env_puller line leaves
  # the variable UNDEFINED, and PHP compares null == 0 as true -- so the dialog would greet
  # the operator on every single page load forever.
  kq=$(grep -c "huginNoticeShown" /opt/stateless/engine/dbUpdates/dbUpdate_2.45.sh)
  kr=$(grep -c "huginNoticeShown" /opt/stateless/nginx/www/includes/config_env_puller.php)
  ks=$(grep -c "huginNoticeOverlay" /opt/stateless/nginx/www/admin/index.php)
  kt=$(grep -c "dismissHuginNotice" /opt/stateless/nginx/www/admin/adminAPI.php)
  # The null-safe read, and the raven sized for the dialog rather than the 34px default.
  ku=$(grep -c "huginNoticeShown ?? 1" /opt/stateless/nginx/www/admin/index.php)
  kv=$(grep -c "ai-hugin.hugin-hello" /opt/stateless/nginx/www/css/phvalheimStyles.css)
  echo "meet-hugin: column=$kq var=$kr markup=$ks endpoint=$kt nullsafe=$ku css=$kv"

  # The crossplay log line must LEAD with the outcome. It previously opened "has crossplay
  # set but is MODDED", which is what a reader scanning a modded world log took away --
  # reported as "the world log says crossplay is enabled".
  kw=$(grep -c "crossplay is OFF" /opt/stateless/games/valheim/scripts/startWorld.sh)
  # ...and the operator has to be TOLD, or the fix is invisible to the person who reported it.
  ky=$(grep -c "crossplay is OFF" /opt/stateless/nginx/www/includes/whatsnew.php)
  # Anchored on echo, NOT the bare phrase: the comment above the fix QUOTES the old wording
  # to explain why it changed, so a bare count is 1 on correct source. This script has been
  # tripped by markers counting their own prose before.
  kx=$(grep -c "echo.*has crossplay set" /opt/stateless/games/valheim/scripts/startWorld.sh)
  echo "crossplay line: leads-with-outcome=$kw (want 1)  old-misleading-wording=$kx (want 0)  whatsnew=$ky (want 1)"

  # ---- 2.45: what Hugin is told about passwords ----------------------------------
  # STILL no apostrophes below, comments included -- one ends the sh -c block early and
  # every check after it is silently skipped.
  #
  # The NEGATIVE is the one that matters. password_public is a TINYINT display flag, and
  # redacting it as a credential reported set--redacted for BOTH 0 and 1: the boolean was
  # destroyed and a second password invented, which a model then described to an operator
  # as a password for the public view. No such password exists. Counting only the new keys
  # would pass on an image that still carried the old redaction loop alongside them.
  # The dots in the pattern match the quotes -- a literal one would close this block.
  la=$(grep -cE "foreach \(\[.password., .password_public.\] as" /opt/stateless/nginx/www/includes/aicontext.php)
  lb=$(grep -c "function aiWorldHasPassword" /opt/stateless/nginx/www/includes/aicontext.php)
  lc=$(grep -c "has_password" /opt/stateless/nginx/www/includes/aicontext.php)
  # A password is applied to VANILLA worlds only, so has_password alone still misleads.
  ld=$(grep -c "password_in_effect" /opt/stateless/nginx/www/includes/aicontext.php)
  le=$(grep -c "show_password_on_public_card" /opt/stateless/nginx/www/includes/aicontext.php)
  # The vanilla/modded password rule lived ONLY in OPERATING PROCEDURES, which is omitted
  # for a model that cannot act -- so a read-only Hugin was never told it. It is a fact
  # about this server, so it has to sit in DOMAIN FACTS where every Hugin sees it.
  lf=$(grep -c "A PASSWORD ONLY APPLIES TO A VANILLA WORLD" /opt/stateless/nginx/www/includes/aicontext.php)
  echo "2.45 hugin passwords: stale redaction loop=$la (want 0)  helper=$lb (want 1)"
  echo "2.45 hugin passwords: has_password=$lc (want 3)  in_effect=$ld (want 3)  display flag=$le (want 2)  domain fact=$lf (want 1)"

  # ---- 2.46: the Hugin panel against a small model --------------------------------
  # STILL no apostrophes below, comments included -- one closes the sh -c block early and
  # every later check is silently skipped.
  #
  # The working strip is pinned with position:sticky. Anchored on the comment that explains
  # WHY rather than on "position: sticky", which appears elsewhere in this stylesheet.
  ma=$(grep -c "PINNED TO THE BOTTOM" /opt/stateless/nginx/www/css/phvalheimStyles.css)
  # The footer had no rule at all, which is the whole bug. Paired with a NEGATIVE: the
  # inline flex that used to stand in for it must be gone, or it wins on specificity.
  mb=$(grep -c "^\.mods-modal-footer" /opt/stateless/nginx/www/css/phvalheimStyles.css)
  mc=$(grep -c "mods-modal-footer. style=" /opt/stateless/nginx/www/admin/index.php)
  # Table rendering needs BOTH the parser and the styles. The markup alone renders an
  # unstyled borderless table, which reads barely better than the literal pipes it replaced.
  md=$(grep -c "ai-table" /opt/stateless/nginx/www/admin/index.php)
  me=$(grep -c "ai-table" /opt/stateless/nginx/www/css/phvalheimStyles.css)
  mf=$(grep -c "ai-hr\|ai-quote" /opt/stateless/nginx/www/css/phvalheimStyles.css)
  mg=$(grep -c "ai-h1\|ai-h2" /opt/stateless/nginx/www/css/phvalheimStyles.css)
  # Narration: the client drops prose that preceded a tool call, the prompt asks for none,
  # and the server strips what is left. The NEGATIVE is the one that matters -- the old
  # take-it-only-if-longer guard would discard the strip and keep the narration.
  mh=$(grep -c "TEXT BEFORE A TOOL CALL IS NOT THE ANSWER" /opt/stateless/nginx/www/admin/index.php)
  mi=$(grep -c "ev.content.length . acc.length" /opt/stateless/nginx/www/admin/index.php)
  mj=$(grep -c "function aiStripNarration" /opt/stateless/nginx/www/includes/aicontext.php)
  mk=$(grep -c "aiStripNarration(" /opt/stateless/nginx/www/includes/aicontext.php)
  ml=$(grep -c "DO NOT NARRATE YOUR OWN PROCESS" /opt/stateless/nginx/www/includes/aicontext.php)
  # Default provider: its own one-field endpoint, reachable from the settings list.
  mm=$(grep -c "function aiProviderSetDefault" /opt/stateless/nginx/www/includes/aiproviders.php)
  mn=$(grep -c "setDefaultAiProvider" /opt/stateless/nginx/www/admin/adminAPI.php)
  mo=$(grep -c "data-default" /opt/stateless/nginx/www/admin/index.php)
  mp=$(grep -c "2.46. => \[" /opt/stateless/nginx/www/includes/whatsnew.php)
  echo "2.46 strip pinned=$ma (want 1)  footer rule=$mb (want 1)  stale inline footer=$mc (want 0)"
  echo "2.46 tables: parser=$md (want 1)  css=$me (want 5)  hr/quote css=$mf (want 2)  heading levels=$mg (want 2)"
  echo "2.46 narration: client drop=$mh (want 1)  stale length guard=$mi (want 0)  fn=$mj (want 1)  calls=$mk (want 2)  prompt rule=$ml (want 1)"
  echo "2.46 default provider: fn=$mm (want 1)  endpoint=$mn (want 1)  button=$mo (want 3)  whatsnew=$mp (want 1)"

  # Running-ness comes from `mode`, never `status`. On the real box `status` is the string
  # Down for EVERY world, running ones included, so the old check was permanently false:
  # Hugin was told 0 running, stop_world refused everything as already stopped, and
  # diagnostics treated a live world log as history.
  #
  # The NEGATIVES are the ones that matter. Counting the new helper would pass on a file
  # that still had the status reads alongside it. The dots stand in for the quotes -- a
  # literal one would close this sh -c block. The prose in the aicontext header says
  # aiTruthy($row, ...) deliberately, so it does not match these $w/$r/$p forms.
  na=$(grep -c "function aiWorldIsRunning" /opt/stateless/nginx/www/includes/aicontext.php)
  nb=$(grep -c "function aiWorldStateText" /opt/stateless/nginx/www/includes/aicontext.php)
  nc=$(grep -c "aiWorldIsRunning(" /opt/stateless/nginx/www/includes/aicontext.php)
  nd=$(grep -c "aiWorldIsRunning(" /opt/stateless/nginx/www/includes/aiactions.php)
  ne=$(grep -c "aiWorldIsRunning(" /opt/stateless/nginx/www/includes/aidiagnose.php)
  nf=$(grep -hc "aiTruthy(.w, .status.)" /opt/stateless/nginx/www/includes/aicontext.php /opt/stateless/nginx/www/includes/aiactions.php /opt/stateless/nginx/www/includes/aidiagnose.php | paste -sd+ | bc)
  ng=$(grep -hc "aiTruthy(.r, .status.)" /opt/stateless/nginx/www/includes/aicontext.php /opt/stateless/nginx/www/includes/aiactions.php /opt/stateless/nginx/www/includes/aidiagnose.php | paste -sd+ | bc)
  nh=$(grep -hc "aiTruthy(.p\[.row.\]" /opt/stateless/nginx/www/includes/aicontext.php /opt/stateless/nginx/www/includes/aiactions.php /opt/stateless/nginx/www/includes/aidiagnose.php | paste -sd+ | bc)
  ni=$(grep -c "aiWorldStateText(" /opt/stateless/nginx/www/includes/aicontext.php)
  # 2.47 -- player counts, automatic updates, and installed-version tracking. This block was
  # missing entirely until now: the first 2.47 release candidates verified only against 2.40
  # to 2.46 markers, so nothing in the whole feature was ever checked inside the image.
  #
  # The three engine tools have to BE there and be executable. A missing cron tool is silent:
  # the columns simply never move and every world reads waiting for data forever.
  oa=$(ls /opt/stateless/engine/tools/playerMonitor /opt/stateless/engine/tools/updateChecker.py /opt/stateless/engine/tools/updateApplier 2>/dev/null | grep -c .)
  ob=$(find /opt/stateless/engine/tools -name "playerMonitor" -perm -u+x | grep -c .)
  oc=$(find /opt/stateless/engine/tools -name "updateApplier" -perm -u+x | grep -c .)
  # /etc/cron.d, NOT /opt/stateless/cron.d -- the Dockerfile COPYs container/cron.d/* to
  # /etc/cron.d/ and there is no cron.d under /opt/stateless at all. The first version of this
  # check looked in the stateless tree, found nothing, and reported the three cron entries
  # missing from an image that had all three.
  od=$(ls /etc/cron.d/ 2>/dev/null | grep -c "playerMonitor\|updateChecker\|updateApplier")
  # steamcmd needs an explicit HOME. It runs as the phvalheim user, whose inherited home is
  # not writable, and without this it dies before printing anything -- which rendered as a
  # green up to date over a world thousands of builds behind.
  oe=$(grep -c "STEAM_HOME" /opt/stateless/engine/tools/updateChecker.py)
  # Player detection splits by crossplay. The non-crossplay heartbeat reads 0 while someone is
  # connected to a crossplay world, so using it everywhere reports every crossplay world empty.
  of=$(grep -c "now N player" /opt/stateless/engine/tools/playerMonitor)
  # Closing socket is logged TWICE per departure, the copies differing only in the run of
  # spaces after the timestamp. Squeezing before the dedupe is the whole fix.
  og=$(grep -c "tr -s" /opt/stateless/engine/tools/playerMonitor)
  echo "2.47 tools: present=$oa (want 3)  exec pm=$ob applier=$oc (want 1/1)  cron=$od (want 3)"
  echo "2.47 checker steam home=$oe (want >0)  crossplay split=$of (want >0)  socket squeeze=$og (want >0)"
  # Installed-version tracking. The NEGATIVE is the important one: updateChecker must not read
  # worlds.modsViewer at all. That column is a display cache whose versions come from the live
  # catalogue, so comparing it against the catalogue compares a number with itself and can only
  # ever answer up to date. An image with both the new query and the old read would pass a
  # positive-only check.
  #
  # Count a READ, not a mention: the docstring names modsViewer twice explaining why it is
  # not read, and a bare word count would fail on the correct image. A SELECT is the thing
  # that would actually reintroduce the bug.
  oh=$(grep -c "installed_version_id" /opt/stateless/engine/tools/updateChecker.py)
  oi=$(grep -c "SELECT.*modsViewer" /opt/stateless/engine/tools/updateChecker.py)
  oj=$(grep -c "def record_installed" /opt/stateless/engine/tools/worldMods.py)
  # Anchored on the trailing-comma strip, which only the real invocation has -- the word
  # record-installed also appears in the comment four lines above it.
  ok=$(grep -c "modsInstalledIds%," /opt/stateless/engine/includes/0-functions.sh)
  # 2.47 ADDED a fourth worldMods call site, so 2.47 is where the total is asserted. The 2.43
  # block counts its own three by mode and is deliberately blind to this one. Splitting them
  # means a dropped --record-installed fails HERE, naming the release that owns it, instead of
  # showing up as a 2.43 line that is off by one for no stated reason.
  ow=$(grep -c "worldMods.py --world" /opt/stateless/engine/includes/0-functions.sh)
  ox=$(grep -cE "worldMods.py --world .+\\\\$" /opt/stateless/engine/includes/0-functions.sh)
  ol=$(grep -c "installed_version_id" /opt/stateless/engine/dbUpdates/dbUpdate_2.47.sh)
  om=$(grep -c "installed_at" /opt/stateless/engine/dbUpdates/dbUpdate_2.47.sh)
  # The plan TSV must carry mod_id, or the installer has nothing to report back with.
  on_=$(grep -c "str(r\[.mod_id.\])" /opt/stateless/engine/tools/worldMods.py)
  oo=$(grep -c "modId" /opt/stateless/engine/includes/0-functions.sh)
  echo "2.47 installed versions: checker reads=$oh (want >0)  checker reads modsViewer=$oi (want 0)"
  echo "2.47 recorder: fn=$oj (want 1)  installer calls=$ok (want 1)  migration cols=$ol/$om (want >0 each)"
  echo "2.47 worldMods call sites: total=$ow (want 4)  the new line-continued one=$ox (want 1)"
  echo "2.47 plan carries mod_id: tsv=$on_ (want 1)  bash reads it=$oo (want >0)"
  # The Updates tab. ONE Mods row, not two -- a leftover unconditional block drew a second one
  # in red could-not-check styling, and on a never-checked world a green up to date sat one
  # line under the muted waiting for data. Two contradictory answers to the same question.
  op=$(grep -c "rows.push(\[.Mods." /opt/stateless/nginx/www/admin/index.php)
  oq=$(grep -c "neverChecked" /opt/stateless/nginx/www/admin/index.php)
  # Rebuild Mods is bound in JS, NOT interpolated into an onclick. escapeHtmlBasic turns an
  # apostrophe into an entity, the parser turns it back before JS sees it, and a world named
  # with one would render a button that throws. So: the data attribute must be there and
  # rebuildWorldMods must never appear inside an onclick. 2 = the markup and the querySelector
  # that binds to it; either one missing is a button that does nothing.
  or_=$(grep -c "data-rebuild-mods" /opt/stateless/nginx/www/admin/index.php)
  os=$(grep -c "function rebuildWorldMods" /opt/stateless/nginx/www/admin/index.php)
  ot=$(grep -c "onclick=.rebuildWorldMods" /opt/stateless/nginx/www/admin/index.php)
  # Progress phases and the Active Worlds pill.
  ou=$(grep -c "UPDATE_PHASES" /opt/stateless/nginx/www/admin/index.php)
  ov=$(grep -c "update_phase" /opt/stateless/engine/tools/updateApplier)
  echo "2.47 updates tab: Mods rows=$op (want 1)  never-checked gate=$oq (want >0)"
  echo "2.47 rebuild btn: attr=$or_ (want 2)  handler=$os (want 1)  inline onclick=$ot (want 0)"
  echo "2.47 phases: ui=$ou (want >0)  applier writes=$ov (want >0)"
  # The always-available release-notes button. The NEGATIVE is the one that matters: the modal
  # must be gated on whatsNew (there are notes) and NOT on whatsNewAuto (there are UNSEEN
  # notes). Gating on the latter is the bug -- the dialog was only built when something was
  # unseen, so after clicking Got it there was no way to re-read the running version notes,
  # and a header button would have opened nothing.
  oy=$(grep -c "whatsNew = !empty(.whatsNewAuto)" /opt/stateless/nginx/www/admin/index.php)
  oz=$(grep -c "whatsNewSince(.., .phvalheimVersion)" /opt/stateless/nginx/www/admin/index.php)
  pa=$(grep -c "id=.whatsNewBtn." /opt/stateless/nginx/www/admin/index.php)
  pb=$(grep -c "function openWhatsNew" /opt/stateless/nginx/www/admin/index.php)
  pc=$(grep -c "window.whatsNewPending" /opt/stateless/nginx/www/admin/index.php)
  pd=$(grep -c "whatsnew-btn" /opt/stateless/nginx/www/css/phvalheimStyles.css)
  # The old single-purpose dismiss handler is gone; closeWhatsNew replaced it.
  pe=$(grep -c "onclick=.dismissWhatsNew()." /opt/stateless/nginx/www/admin/index.php)
  echo "2.47 whatsnew btn: fallback=$oy/$oz (want 1/1)  button=$pa (want 1)  opener=$pb (want 1)"
  echo "2.47 whatsnew btn: pending flag=$pc (want >0)  css=$pd (want >0)  stale dismiss onclick=$pe (want 0)"
  # The transitional status pills. 2.47 dropped @keyframes auProgress into the MIDDLE of
  # their selector list, which makes the parser discard the whole rule -- Updating, Starting,
  # Stopping, Creating and Deleting all went flat grey, silently, and only .backup survived
  # because it was the last selector and so became its own valid rule.
  #
  # pf asserts the list still runs straight into .backup with no at-rule between. pg asserts
  # auProgress is defined somewhere. Both are needed: moving the keyframes out without
  # rejoining the list would leave the pills grey and still pass pg.
  pf=$(grep -A1 "status-badge.deleting," /opt/stateless/nginx/www/css/phvalheimStyles.css | grep -c "status-badge.backup {")
  # The DEFINITION, with its brace -- the comment warning about this bug names auProgress too,
  # so a bare word count is 2 on the correct file.
  pg=$(grep -c "@keyframes auProgress {" /opt/stateless/nginx/www/css/phvalheimStyles.css)
  echo "2.47 status pills: transitional list intact=$pf (want 1)  auProgress defined=$pg (want 1)"
  # The auto-update stop path. updateApplier runs from cron as the phvalheim user, which
  # cannot reach supervisor at all -- its config is 0660 root:root and its socket 0700
  # root:root. The old bare supervisorctl call discarded that error, so the world was never
  # stopped and steamcmd rewrote the game tree under a live server. The NEGATIVE is the load
  # bearing check: matched on the invocation path, since the comments explaining the fix name
  # supervisorctl several times.
  ph=$(grep -c "/usr/bin/supervisorctl" /opt/stateless/engine/tools/updateApplier)
  pi_=$(grep -c "UPDATE worlds SET mode=.stop. WHERE" /opt/stateless/engine/tools/updateApplier)
  pj=$(grep -c "pgrep -f" /opt/stateless/engine/tools/updateApplier)
  pk=$(grep -c "if ! stopWorldAndWait" /opt/stateless/engine/tools/updateApplier)
  # A skipped backup must not read as a taken one.
  pl=$(grep -c "exit 75" /opt/stateless/engine/tools/worldBackup)
  pm=$(grep -c "backupStatus. -eq 75" /opt/stateless/engine/tools/updateApplier)
  # InstallAndUpdateValheim must not end on a chown, whose status became its return value.
  pn=$(grep -c "if ! chown -R phvalheim:" /opt/stateless/engine/includes/0-functions.sh)
  echo "2.47 autoupdate stop: supervisorctl calls=$ph (want 0)  via mode=$pi_ (want 1)  pgrep=$pj (want 1)  guarded=$pk (want 1)"
  echo "2.47 autoupdate safety: backup skip=$pl/$pm (want 1/1)  chown not the return value=$pn (want 1)"
  # The world has to come back up. The engine update path ends with mode=stopped on purpose,
  # so the applier must start it for EVERY scope -- it used to sit in an else against the
  # mods branch, which meant the default scope stopped a world and never restarted it.
  po=$(grep -c "Start the world again, for EVERY scope" /opt/stateless/engine/tools/updateApplier)
  pp=$(grep -c "if ! waitForEngineUpdate" /opt/stateless/engine/tools/updateApplier)
  echo "2.47 autoupdate restart: start for every scope=$po (want 1)  waits for rebuild=$pp (want 1)"
  # The orphan reaper must not SIGKILL a process supervisor owns. The world program is
  # autorestart=true, so an unexpected SIGKILL is respawned and the next 2s tick kills the new
  # pid -- a loop with no exit, reported from a real server. Ask supervisor first, and only
  # reap when something is actually alive.
  pq=$(grep -c "Stopping it through supervisor" /opt/stateless/engine/phvalheim)
  # THREE sites: the reaper, and the update branch twice (is it running, and did it stop).
  # This said 1 and failed a build on correct code after the update branch grew two more.
  pr=$(grep -c "if worldProcessRunning" /opt/stateless/engine/phvalheim)
  pw=$(grep -c "RUNNING|STARTING|BACKOFF" /opt/stateless/engine/phvalheim)
  # NEGATIVES. Nothing writes worlds.pid, so both guards that read it always answered
  # not-running: one let steamcmd rewrite a live world, the other declared a still-running
  # world stopped. Neither may come back.
  ps_=$(grep -c "ps -p .worldPID" /opt/stateless/engine/phvalheim)
  pt=$(grep -c "SELECT pid FROM worlds" /opt/stateless/engine/phvalheim)
  pu=$(grep -c "^function worldProcessRunning" /opt/stateless/engine/includes/0-functions.sh)
  pv=$(grep -c "worldProcessRunning ..worldName." /opt/stateless/engine/phvalheim)
  echo "2.47 reaper: asks supervisor=$pq (want 1)  guarded=$pr (want 3)  states=$pw (want 1)"
  # Nothing that sets mode=update stops the world first, so the engine must do it. Refusing
  # instead left mode=update set and the 2s loop reprinted the refusal forever.
  px=$(grep -c "Stopping it for the update" /opt/stateless/engine/phvalheim)
  py=$(grep -c "wasRunning=1" /opt/stateless/engine/phvalheim)
  pz=$(grep -c "wasRunning. = " /opt/stateless/engine/phvalheim)
  qa=$(grep -c "Stop the world before updating" /opt/stateless/engine/phvalheim)
  qb=$(grep -c "would not stop within 180s" /opt/stateless/engine/phvalheim)
  echo "2.47 update stop: stops it=$px (want 1)  remembers=$py (want 1)  restarts=$pz (want 1)  unstoppable=$qb (want 1)"
  echo "2.47 update NEGATIVE: spin-forever refusal=$qa (want 0)"
  echo "2.47 reaper NEGATIVES: dead pid guard=$ps_ (want 0)  reads worlds.pid=$pt (want 0)  helper=$pu (want 1)  call sites=$pv (want 5)"
  echo "2.46 world state: helper=$na/$nb (want 1/1)  calls ctx=$nc actions=$nd diag=$ne (want 5/3/2)  stateText=$ni (want 3)"
  echo "2.46 world state NEGATIVES: stale status reads w=$nf r=$ng row=$nh (want 0/0/0)"
  echo "2.45 openai negotiation: completion_tokens=$is reasoning=$ja loops=$jc (want >0)  stream err body=$it/$iu (want 1/1)"
  echo "2.45 wizard: kind-change=$jd/$je presets=$jf/$jg (want >0 each)"
  echo "2.45 ollama removal: kind=$jh adapter=$ji (want 0/0)  migration: convert=$jj legacy=$jk (want >0/1)"
  echo "2.45 ollama notice: migration=$jl reader=$jm modal=$jn dismiss=$jo (want >0 each)"
  echo "2.45 model picker: js=$iv css=$iw  stars: toggle=$ix css=$iy stopProp=$iz (want >0 each)"
  echo "2.45 hugin: node=$ih partial=$ip ask-btn=$iq no-js-artwork=$ir strip=$ii phrases=$ij header=$ik css rules=$il flap=$im reduced-motion=$in_ timer-clear=$io"
  echo "2.45 stopped-world: scan status=$ib restart scoped=$ic persona=$id live state=$ie (want 1 each)"
  echo "2.45 trace layout: column rules=$if_ (want >0)  break-all left=$ig (want 0)"
  echo "2.45 wizard model-before-test=$hx (want 1)  auto-picks=$hy (want 0)  discovery endpoint=$hz (want 1)  aiLog=$ia (want 1)"
  echo "2.45 new files=$ha (want 4)  migration executable=$hb (want 1)"
  echo "2.45 dbUpdater reports unrunnable=$hv (want 1)  invokes via bash=$hw (want 1)"
  echo "2.45 old dispatcher gone=$hc (want 0)  model tables gone=$hd (want 0)  ollama fn gone=$he (want 0)"
  echo "2.45 model ids in AI sources=$hf (want 0)"
  echo "2.45 discovery: native ollama=$hg (want 0)  gemini=$hh generateContent filter=$hi (want >0)"
  echo "2.45 adminAPI includes: providers=$hj context=$hk (want 1 each)"
  echo "2.45 legacy readers: analytics old=$hl (want 0) new=$hm (want >0)  setup fields gone=$hn (want 0)  aiKeys array gone=$ho (want 0)"
  echo "2.45 ui: stream=$hp wizard=$hq diag css=$hr wiz css=$hs zindex=$ht (want >0 each)"
  echo "2.45 whatsnew entry=$hu (want >0)"

  [ "$a" = "2" ] && [ "$b" = "1" ] && [ "$c" = "1" ] \
    && [ "$e" = "3" ] && [ "$f" = "0" ] && [ "$g" = "1" ] && [ "$h" = "0" ] \
    && [ "$i" = "2" ] && [ "$j" = "0" ] && [ "$k" = "3" ] && [ "$l" -gt 0 ] \
    && [ "$m" = "2" ] && [ "$n" = "4" ] && [ "$o" = "2" ] && [ "$p" = "0" ] \
    && [ "$q" = "1" ] && [ "$r" = "3" ] && [ "$s" = "1" ] && [ "$t" = "2" ] \
    && [ "$u" = "0" ] && [ "$v" = "0" ] && [ "$w" = "1" ] && [ "$x" = "1" ] \
    && [ "$y" = "1" ] && [ "$z" = "1" ] \
    && [ "$aa" = "0" ] && [ "$ab" = "0" ] && [ "$ac" = "1" ] && [ "$ad" = "2" ] && [ "$ae" = "0" ] \
    && [ "$af" = "1" ] && [ "$ag" = "10" ] && [ "$ah" = "5" ] && [ "$ai" = "4" ] \
    && [ "$aj" = "1" ] && [ "$ak" = "3" ] && [ "$al" = "2" ] \
    && [ "$am" = "1" ] && [ "$an" = "1" ] && [ "$ao" = "1" ] \
    && [ "$ap" = "1" ] && [ "$aq" = "3" ] && [ "$ar" = "2" ] && [ "$as" = "1" ] && [ "$at" = "0" ] \
    && [ "$au" = "1" ] && [ "$av" = "3" ] && [ "$aw" = "1" ] && [ "$ax" = "1" ] && [ "$ay" = "0" ] && [ "$az" = "3" ] \
    && [ "$ba" = "1" ] && [ "$bb" = "2" ] && [ "$bc" = "2" ] && [ "$bd" = "1" ] \
    && [ "$be" = "1" ] && [ "$bf" = "1" ] && [ "$bg" = "0" ] && [ "$bh" = "1" ] && [ "$bi" = "3" ] && [ "$bj" = "1" ] && [ "$bk" = "1" ] \
    && [ "$bl" = "2" ] && [ "$bm" = "1" ] && [ "$bn" = "1" ] && [ "$bo" = "2" ] \
    && [ "$ver" = "1" ] \
    && [ "$bp" = "1" ] && [ "$bq" = "1" ] && [ "$br" = "1" ] && [ "$bs" = "1" ] && [ "$bt" = "1" ] \
    && [ "$bu" = "7" ] && [ "$bv" = "1" ] && [ "$bw" = "1" ] \
    && [ "$bx" = "3" ] && [ "$by" = "2" ] \
    && [ "$bz" = "1" ] && [ "$ca" = "1" ] && [ "$cb" = "1" ] && [ "$cc" = "0" ] \
    && [ "$cd" = "0" ] && [ "$ce" = "1" ] && [ "$cf" = "2" ] \
    && [ "$cg" = "3" ] && [ "$ch" = "1" ] && [ "$ci" = "3" ] && [ "$cj" = "0" ] && [ "$ck" = "2" ] \
    && [ "$cl" = "3" ] && [ "$cm" = "4" ] && [ "$cn" = "1" ] && [ "$co" = "2" ] && [ "$cq" = "0" ] \
    && [ "$cr" = "1" ] && [ "$cs" = "0" ] && [ "$ct" -gt 0 ] \
    && [ "$cu" = "1" ] && [ "$cv" = "1" ] && [ "$cw" = "0" ] \
    && [ "$da" = "1" ] && [ "$db" = "1" ] && [ "$dc" = "1" ] && [ "$dd" = "1" ] \
    && [ "$de" = "1" ] && [ "$df" = "1" ] \
    && [ "$dg" = "1" ] && [ "$dh" = "0" ] \
    && [ "$di" -gt 0 ] && [ "$dj" -gt 0 ] \
    && [ "$dk" = "3" ] && [ "$dl" = "0" ] && [ "$dm" = "1" ] && [ "$dn" = "0" ] \
    && [ "$do_" = "1" ] && [ "$dp" -gt 0 ] && [ "$dq" -gt 0 ] \
    && [ "$dr" -gt 0 ] && [ "$ds" -gt 0 ] && [ "$dt" -gt 0 ] && [ "$du" = "0" ] \
    && [ "$dv" -gt 0 ] && [ "$dw" -gt 0 ] && [ "$dx" -gt 0 ] && [ "$dy" = "1" ] \
    && [ "$dz" = "2" ] && [ "$ea" = "1" ] && [ "$eb" = "1" ] && [ "$ec" = "1" ] && [ "$ed" = "1" ] \
    && [ "$ee" = "0" ] && [ "$ef" = "0" ] && [ "$eg" = "1" ] && [ "$eh" = "0" ] \
    && [ "$ei" = "0" ] && [ "$ej" = "0" ] && [ "$ek" = "0" ] && [ "$el" = "0" ] && [ "$em" = "0" ] \
    && [ "$en" = "1" ] && [ "$eo" = "3" ] && [ "$ep" = "0" ] && [ "$er" = "0" ] \
    && [ "$es" -gt 0 ] && [ "$et" -gt 0 ] && [ "$eu" -gt 0 ] && [ "$ev" -gt 0 ] \
    && [ "$ew" -gt 0 ] && [ "$ex" -gt 0 ] \
    && [ "$ey" -gt 0 ] && [ "$ez" -gt 0 ] && [ "$fa" -gt 0 ] && [ "$fb" -gt 0 ] && [ "$fc" -gt 0 ] \
    && [ "$fd" -gt 0 ] && [ "$fe" -gt 0 ] && [ "$ff" -gt 0 ] && [ "$fg" -gt 0 ] \
    && [ "$fh" = "0" ] && [ "$fi_" -gt 0 ] && [ "$fj" -gt 0 ] \
    && [ "$fk" = "1" ] && [ "$fl" = "1" ] && [ "$fm" = "0" ] \
    && [ "$fn" -gt 0 ] && [ "$fo" = "0" ] \
    && [ "$fp" = "1" ] && [ "$fq" = "1" ] && [ "$fr" = "0" ] && [ "$fs" = "0" ] \
    && [ "$ft" -gt 0 ] \
    && [ "$fu" -gt 0 ] && [ "$fv" -gt 0 ] && [ "$fw" -gt 0 ] && [ "$fx" = "0" ] \
    && [ "$ga" = "1" ] && [ "$gb" = "0" ] && [ "$gc" = "1" ] && [ "$gd" = "0" ] \
    && [ "$ge" = "1" ] && [ "$gf" = "1" ] && [ "$gg" = "1" ] \
    && [ "$gh" = "1" ] && [ "$gi" -gt 0 ] \
    && [ "$ha" = "4" ] && [ "$hb" = "1" ] && [ "$hv" = "1" ] && [ "$hw" = "1" ] \
    && [ "$hc" = "0" ] && [ "$hd" = "0" ] && [ "$he" = "0" ] && [ "$hf" = "0" ] \
    && [ "$hg" = "0" ] && [ "$hh" -gt 0 ] && [ "$hi" -gt 0 ] \
    && [ "$hj" = "1" ] && [ "$hk" = "1" ] \
    && [ "$hl" = "0" ] && [ "$hm" -gt 0 ] && [ "$hn" = "0" ] && [ "$ho" = "0" ] \
    && [ "$hp" -gt 0 ] && [ "$hq" -gt 0 ] && [ "$hr" -gt 0 ] && [ "$hs" -gt 0 ] && [ "$ht" -gt 0 ] \
    && [ "$hu" -gt 0 ] \
    && [ "$hx" = "1" ] && [ "$hy" = "0" ] && [ "$hz" = "1" ] && [ "$ia" = "1" ] \
    && [ "$is" -gt 0 ] && [ "$jp" = "1" ] && [ "$jq" = "1" ] && [ "$jr" = "1" ] && [ "$ja" -gt 0 ] && [ "$jc" = "2" ] && [ "$it" = "1" ] && [ "$iu" = "1" ] && [ "$iv" -gt 0 ] && [ "$iw" -gt 0 ] \
    && [ "$jd" = "1" ] && [ "$je" = "1" ] && [ "$jf" -gt 0 ] && [ "$jg" -gt 0 ] && [ "$jh" = "0" ] && [ "$ji" = "0" ] && [ "$jj" -gt 0 ] && [ "$jk" = "1" ] \
    && [ "$jl" -gt 0 ] && [ "$jm" -gt 0 ] && [ "$jn" -gt 0 ] && [ "$jo" = "1" ] && [ "$ix" = "1" ] && [ "$iy" -gt 0 ] && [ "$iz" -gt 0 ] && [ "$ih" = "1" ] && [ "$ip" = "1" ] && [ "$iq" -gt 0 ] && [ "$ir" = "0" ] && [ "$ii" -gt 0 ] && [ "$ij" -gt 0 ] && [ "$ik" -gt 0 ] && [ "$il" -gt 0 ] && [ "$im" -gt 0 ] && [ "$in_" -gt 0 ] && [ "$io" -gt 0 ] \
    && [ "$ib" = "1" ] && [ "$ic" = "1" ] && [ "$id" = "1" ] && [ "$ie" = "1" ] && [ "$if_" -gt 0 ] && [ "$ig" = "0" ] \
    && [ "$ka" = "12" ] && [ "$kb" -gt 0 ] && [ "$kc" -gt 0 ] && [ "$kd" -gt 0 ] \
    && [ "$ke" -gt 0 ] && [ "$kf" -gt 0 ] && [ "$kg" -gt 0 ] && [ "$kh" = "0" ] \
    && [ "$ki" -gt 0 ] && [ "$kj" -gt 0 ] && [ "$kk" -gt 0 ] && [ "$kl" -gt 0 ] \
    && [ "$km" -gt 0 ] && [ "$kn" -gt 0 ] && [ "$ko" -gt 0 ] && [ "$kp" -gt 0 ] \
    && [ "$kq" -gt 0 ] && [ "$kr" -gt 0 ] && [ "$ks" -gt 0 ] && [ "$kt" -gt 0 ] \
    && [ "$ku" -gt 0 ] && [ "$kv" -gt 0 ] \
    && [ "$kw" = "1" ] && [ "$kx" = "0" ] && [ "$ky" = "1" ] \
    && [ "$la" = "0" ] && [ "$lb" = "1" ] && [ "$lc" = "3" ] \
    && [ "$ld" = "3" ] && [ "$le" = "2" ] && [ "$lf" = "1" ] \
    && [ "$ma" = "1" ] && [ "$mb" = "1" ] && [ "$mc" = "0" ] \
    && [ "$md" = "1" ] && [ "$me" = "5" ] && [ "$mf" = "2" ] && [ "$mg" = "2" ] \
    && [ "$mh" = "1" ] && [ "$mi" = "0" ] && [ "$mj" = "1" ] && [ "$mk" = "2" ] && [ "$ml" = "1" ] \
    && [ "$mm" = "1" ] && [ "$mn" = "1" ] && [ "$mo" = "3" ] && [ "$mp" = "1" ] \
    && [ "$na" = "1" ] && [ "$nb" = "1" ] && [ "$nc" = "5" ] && [ "$nd" = "3" ] && [ "$ne" = "2" ] \
    && [ "$nf" = "0" ] && [ "$ng" = "0" ] && [ "$nh" = "0" ] && [ "$ni" = "3" ] \
    && [ "$oa" = "3" ] && [ "$ob" = "1" ] && [ "$oc" = "1" ] && [ "$od" = "3" ] \
    && [ "$oe" -gt 0 ] && [ "$of" -gt 0 ] && [ "$og" -gt 0 ] \
    && [ "$oh" -gt 0 ] && [ "$oi" = "0" ] && [ "$oj" = "1" ] && [ "$ok" = "1" ] \
    && [ "$ol" -gt 0 ] && [ "$om" -gt 0 ] && [ "$on_" = "1" ] && [ "$oo" -gt 0 ] \
    && [ "$ow" = "4" ] && [ "$ox" = "1" ] \
    && [ "$op" = "1" ] && [ "$oq" -gt 0 ] \
    && [ "$or_" = "2" ] && [ "$os" = "1" ] && [ "$ot" = "0" ] \
    && [ "$ou" -gt 0 ] && [ "$ov" -gt 0 ] \
    && [ "$oy" = "1" ] && [ "$oz" = "1" ] && [ "$pa" = "1" ] && [ "$pb" = "1" ] \
    && [ "$pc" -gt 0 ] && [ "$pd" -gt 0 ] && [ "$pe" = "0" ] \
    && [ "$pf" = "1" ] && [ "$pg" = "1" ] \
    && [ "$ph" = "0" ] && [ "$pi_" = "1" ] && [ "$pj" = "1" ] && [ "$pk" = "1" ] \
    && [ "$pl" = "1" ] && [ "$pm" = "1" ] && [ "$pn" = "1" ] \
    && [ "$po" = "1" ] && [ "$pp" = "1" ] \
    && [ "$pq" = "1" ] && [ "$pr" = "3" ] && [ "$pw" = "1" ] \
    && [ "$ps_" = "0" ] && [ "$pt" = "0" ] && [ "$pu" = "1" ] && [ "$pv" = "5" ] \
    && [ "$px" = "1" ] && [ "$py" = "1" ] && [ "$pz" = "1" ] && [ "$qa" = "0" ] && [ "$qb" = "1" ] \
    && echo "IMAGE VERIFY OK" || echo "IMAGE VERIFY FAILED"
'

echo "=== digest ==="
docker inspect --format '{{index .RepoDigests 0}}' "$IMAGE"

# Promote, but only on a verified image.
#
# The verify above reports its result with an echo, not an exit status, so the ONLY honest
# signal is the marker in this log. Grepping for it means a build whose verify failed --
# or whose verify was truncated and never ran -- cannot become :latest.
if [ -n "$EXTRA_TAGS" ]; then
	if grep -q "IMAGE VERIFY OK" "$LOG"; then
		for t in $EXTRA_TAGS; do
			dst="${IMAGE%:*}:$t"
			echo "=== promoting -> $dst ==="
			docker tag "$IMAGE" "$dst"    || { echo "TAG FAILED $dst"; echo "=== done FAILED ==="; exit 1; }
			docker push "$dst"            || { echo "PUSH FAILED $dst"; echo "=== done FAILED ==="; exit 1; }
			docker inspect --format "{{index .RepoDigests 0}}" "$dst"
		done
	else
		echo "NOT PROMOTING: the image did not verify, so $EXTRA_TAGS were left alone."
		echo "=== done FAILED ==="
		exit 1
	fi
fi
echo "=== done $(date -u) ==="
