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

exec > "$LOG" 2>&1
echo "=== started $(date -u) ==="
cd "$REPO" || exit 1

echo "=== building $IMAGE ==="
docker buildx build --network=host -t "$IMAGE" . || { echo "BUILD FAILED"; echo "=== done FAILED ==="; exit 1; }

echo "=== pushing ==="
docker push "$IMAGE" || { echo "PUSH FAILED"; echo "=== done FAILED ==="; exit 1; }

echo "=== verifying the fixes are INSIDE the pushed image ==="
# Trust bytes in the image, not the build output.
docker run --rm --entrypoint sh "$IMAGE" -c '
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
  # NO single quotes in these echoes -- the whole block is inside sh -c '...', so one
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
  ver=0; [ "${phvalheimVersion:-}" = "2.41" ] && ver=1
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
  echo "version=$ver (want 1)"
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
    && echo "IMAGE VERIFY OK" || echo "IMAGE VERIFY FAILED"
'

echo "=== digest ==="
docker inspect --format '{{index .RepoDigests 0}}' "$IMAGE"
echo "=== done $(date -u) ==="
