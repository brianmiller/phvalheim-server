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
  ver=0; [ "${phvalheimVersion:-}" = "2.43" ] && ver=1
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
  dk=$(grep -c "worldMods.py --world" /opt/stateless/engine/includes/0-functions.sh)
  dl=$(grep -c "tsModDownloadUrl/\$modAuthor" /opt/stateless/engine/includes/0-functions.sh)
  dm=$(grep -c "requiredMods=" /opt/stateless/engine/includes/phvalheim-static.conf)
  dn=$(grep -c "requiredTsMods=" /opt/stateless/engine/includes/phvalheim-static.conf)
  echo "2.43 install path: worldMods calls=$dk (want 3)  stale url template=$dl (want 0)"
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
  # No apostrophes: this whole block is inside sh -c SINGLE quotes, so "case 'getModSyncLog'"
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
  gd=$(grep -c "RESULT = 0" /opt/stateless/engine/includes/0-functions.sh)
  # the loader is not a mod
  ge=$(grep -c "function loaderExclusionSql" /opt/stateless/nginx/www/includes/modcatalog.php)
  gf=$(grep -c "def is_loader" /opt/stateless/engine/tools/worldMods.py)
  gg=$(ls /opt/stateless/engine/dbUpdates/dbUpdate_2.44.sh 2>/dev/null | wc -l)
  # generateModViewerJson must take its world as an argument, not a leaked global
  gh=$(grep -c "refusing to guess" /opt/stateless/engine/includes/0-functions.sh)
  # printenv filtered to valid shell identifiers
  gi=$(grep -c "A-Za-z_" /opt/stateless/engine/phvalheim)
  gj=$(echo "$phvalheimVersion")
  echo "2.44 unzip guard fixed=$ga (want 1)  old fatal test gone=$gb (want 0)"
  echo "2.44 bepinex guard fixed=$gc (want 1)  old test gone=$gd (want 0)"
  echo "2.44 loader excl php=$ge py=$gf migration=$gg (want 1/1/1)"
  echo "2.44 viewer arg guard=$gh (want 1)  printenv filter=$gi (want >0)"
  echo "2.44 image version=$gj (want 2.44)"
  echo "2.43 log api=$ey lib=$ez pane=$fa css=$fb follows-new-run=$fc (want >0 each)"

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
    && [ "$gh" = "1" ] && [ "$gi" -gt 0 ] && [ "$gj" = "2.44" ] \
    && echo "IMAGE VERIFY OK" || echo "IMAGE VERIFY FAILED"
'

echo "=== digest ==="
docker inspect --format '{{index .RepoDigests 0}}' "$IMAGE"
echo "=== done $(date -u) ==="
