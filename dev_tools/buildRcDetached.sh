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
# This used to count APOSTROPHES. The verify was one giant `sh -c \'...\'` argument, and a
# single apostrophe anywhere inside it -- including in a comment -- closed the string early,
# so the rest of the verify silently never ran. This script reported a clean build while
# skipping its last four checks exactly that way.
#
# That hazard is gone as of 2026-10-01: the payload is written to a file with a QUOTED
# heredoc and mounted into the container, so its contents are taken literally and an
# apostrophe is just a character. What replaced the hazard is this one:
#
#   a payload line that is EXACTLY the heredoc delimiter ends the heredoc early, and
#   everything after it becomes shell in THIS script instead of verify payload.
#
# Same shape of failure -- a silently truncated verify that still exits 0 -- so it gets the
# same treatment: count it, and refuse to build rather than discover it afterwards.
#
# The old apostrophe check was left in place for one build after the restructure and it
# FIRED, on a payload with no apostrophes in it: its awk anchored on the docker-run line,
# which the restructure moved to the bottom, so it counted the wrong region. A guard that
# outlives the thing it guards does not fail safe, it fails confusing.
badDelim=$(awk 'f && /^PHVVERIFYEOF$/{c++} /^cat > "\$VERIFY_SH" <<.PHVVERIFYEOF.$/{f=1} END{print c+0}' "$0")
if [ "${badDelim:-0}" -ne 1 ]; then
	echo "REFUSING TO BUILD: found $badDelim lines matching the heredoc delimiter, expected exactly 1."
	echo "A payload line equal to PHVVERIFYEOF truncates the verify silently."
	echo "=== done FAILED ==="
	exit 1
fi
echo "=== verify payload delimiter check: clean ==="

echo "=== building $IMAGE ==="
docker buildx build --network=host -t "$IMAGE" . || { echo "BUILD FAILED"; echo "=== done FAILED ==="; exit 1; }

echo "=== pushing ==="
docker push "$IMAGE" || { echo "PUSH FAILED"; echo "=== done FAILED ==="; exit 1; }

echo "=== verifying the fixes are INSIDE the pushed image ==="
# Trust bytes in the image, not the build output.
EXPECT_VER=$(sed -n 's/^ENV phvalheimVersion=//p' Dockerfile | head -1)
echo "=== expecting image version $EXPECT_VER (read from Dockerfile) ==="
# The verify payload is written to a FILE and mounted, not passed as an argv string.
#
# It used to be one `sh -c '...'` argument. On 2026-10-01 it crossed 128 KiB -- the Linux
# cap on a SINGLE argv entry, which is separate from and much lower than the total ARG_MAX --
# and docker died with "Argument list too long". The build still pushed :rc, printed a
# digest and exited 0: the verify simply never ran, and every marker in this file gated
# nothing. That is the same silent-pass failure the markers exist to prevent, arriving by a
# different door.
#
# A mounted file has no size limit worth worrying about. The heredoc is QUOTED, so nothing
# is expanded while writing it -- $EXPECT_VER and every $(...) are expanded by the sh inside
# the container, exactly as they were when this was an argv string.
#
# Apostrophes are now safe in the payload. The old rule existed only because of the single
# quoting; it is kept in the marker comments anyway, because reverting this would silently
# reinstate the hazard.
VERIFY_SH=/tmp/phvalheim-rc-verify.sh
cat > "$VERIFY_SH" <<'PHVVERIFYEOF'
  a=$(grep -c "modSelectionCard" /opt/stateless/nginx/www/admin/new_world.php)
  b=$(grep -c "Clearing world md5sum" /opt/stateless/engine/includes/0-functions.sh)
  c=$(grep -c "No client payload found for modded world" /opt/stateless/engine/phvalheim)
  # The wrapper div itself, anchored. This used to be a bare count of "modSelectionArea"
  # with a printed (want 2) that deliberately included the comment explaining why the toggle
  # moved off it -- a number that any future comment edit would have broken, and which was
  # never in the gate at all, so it asserted nothing either way.
  d=$(grep -c "<div id=\"modSelectionArea\">" /opt/stateless/nginx/www/admin/new_world.php)
  # NEGATIVE, which is the thing actually worth asserting: no live toggle came back onto it.
  d2=$(grep -cE "[(]..modSelectionArea..[)][.]toggle[(]" /opt/stateless/nginx/www/admin/new_world.php)
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
  echo "modSelectionArea div=$d (want 1)  NEGATIVE live toggle back=$d2 (want 0)"
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
  # THREE sites as of 2.47: a world that will not start, one that does not come up in time,
  # and one that will not STOP for an update. Was 2 until the update branch gained its own.
  o=$(grep -c "Marking it broken" /opt/stateless/engine/phvalheim)
  p=$(grep -c "exit 1" /opt/stateless/engine/phvalheim)
  echo "idHelpDisclosure=$i (want 2)  old banner=$j (want 0)"
  echo "pv-list-lookup=$k (want 3)  .pv-disclosure css rules=$l (want >0)"
  echo "accessSwitchNoticeShown ui=$m (want 2) migration=$n (want 4)"
  # HISTORICAL: this used to be one sh -c QUOTED argument, where a single apostrophe -- even
  # inside a comment -- closed the quote early and silently skipped every later check. That is
  # exactly how this script once reported a clean build while skipping its last four checks.
  # Since 2026-10-01 the body is written to $VERIFY_SH through a QUOTED heredoc
  # (<<'PHVVERIFYEOF') and run as a file, so apostrophes are safe and several appear below.
  # The surviving rule is the one the badDelim guard at the top enforces: no payload line may
  # be exactly PHVVERIFYEOF.
  # Crossplay join code, and the offline-world stats sweep.
  # Count the DEFINITION, not every mention: getVanillaJoinInfo() now calls this too, so a bare
  # string count went to 2 and failed the verify on an image that was perfectly correct.
  q=$(grep -c "function getWorldJoinCode" /opt/stateless/nginx/www/includes/db_gets.php)
  # 2.53 moved both of these UP by one, and the numbers are NOT relaxed to >= for it: crossplay
  # became available on modded worlds, so the public card grew a second join-code cell (with its
  # own copy link) and api.php grew a second connection block carrying a joinCode. The vanilla
  # occurrence each of these was written for is still in there; it is now one of two. The 2.53
  # block pins the modded half separately (yy, yac), so if either total drifts again it is a
  # real change and not this one.
  r=$(grep -c "copyVanillaJoinCode" /opt/stateless/nginx/www/public/authenticated.php)
  s=$(grep -c "joinCode" /opt/stateless/nginx/www/public/api.php)
  t=$(grep -c "clearUnreportedWorlds" /opt/stateless/nginx/www/admin/index.php)
  echo "engine marks-broken=$o (want 3)  engine exit-1 count=$p (want 0)"
  # A crossplay world launches with -joincode. Match the URL itself, not the bare word --
  # the comments explaining all this mention "-joincode" nine times.
  u=$(grep -cF "steam://run/892970//-joincode" /opt/stateless/nginx/www/public/authenticated.php)
  v=$(grep -cF "steam://run/892970//-joincode" /opt/stateless/nginx/www/public/api.php)
  echo "getWorldJoinCode=$q (want 1)  copyVanillaJoinCode=$r (want 4, was 3 before 2.53)  api joinCode=$s (want 2, was 1 before 2.53)"
  echo "clearUnreportedWorlds=$t (want 2)"
  # The join path must follow the RUNNING backend, not the crossplay column.
  w=$(grep -c "function getWorldNetBackend" /opt/stateless/nginx/www/includes/db_gets.php)
  x=$(grep -c "connection.playfab" /opt/stateless/nginx/www/public/authenticated.php)
  # DELIBERATELY 0 since the crossplay-join-modal change. -joincode joins Valheim with no
  # character selected, so the client falls back to its Odev (Developer) profile, and the card
  # now opens a how-to-join modal instead. If either goes back to 1 the dead launch URL has
  # returned. Do NOT re-baseline them to whatever the image contains.
  # NOTE: the apostrophe ban that used to apply here is OBSOLETE -- the body is a quoted
  # heredoc written to a file since 2026-10-01, not an sh -c argument. Kept as a pointer
  # because the old warning is repeated in several places and all of them meant this.
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
  # Crossplay was vanilla-only here. 2.53 REVERSED that, so ba and bd have flipped from
  # want-1 to want-0: they now assert the two gates are GONE rather than present. Kept in place
  # rather than deleted, because a marker that changes direction is the clearest record there
  # is that the behaviour changed deliberately -- and the 2.53 block asserts the same two
  # things from the other side (ya, yh), so neither gate can come back unnoticed.
  #
  # bb and bc were 2 each, and that WAS luck, as the comment here used to half-admit. bb's
  # second hit was a mention of #crossplayRow inside a comment in toggleVanillaFields(), and
  # the access-control work rewrote that comment -- so a count of raw occurrences dropped to 1
  # and failed the build over prose. bb now counts the id ATTRIBUTE, which is the thing that
  # has to exist. What the ids are USED for is pinned by the 2.53 UI negatives (yp, yq, yr).
  #
  # The old NO APOSTROPHES rule for this section no longer applies: the verify body is a
  # quoted heredoc run from a file, not an sh -c argument. See the note at the top.
  ba=$(grep -c "crossplay set but is MODDED" /opt/stateless/games/valheim/scripts/startWorld.sh)
  bb=$(grep -c 'id="crossplayRow"' /opt/stateless/nginx/www/admin/index.php)
  bc=$(grep -c "crossplayOption" /opt/stateless/nginx/www/admin/new_world.php)
  bd=$(grep -c "isVanilla && !empty(.vanillaOptions..crossplay..)" /opt/stateless/nginx/www/admin/adminAPI.php)
  echo "crossplay NOW ANY WORLD: launch gate gone=$ba (want 0, was 1 before 2.53)  settings row=$bb (want 1, now the id attribute only)  create option=$bc (want 2)  createWorld gate gone=$bd (want 0, was 1 before 2.53)"
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
  # bk and bl went UP by one earlier in 2.53 when the modded card gained a hint row, and are
  # now back DOWN to their original values because that row was removed again -- it repeated
  # what the CROSSPLAY pill and the join code beside it already said, and it rendered unstyled
  # because .vanilla-hint sizing is scoped `.catbox-vanilla .vanilla-hint` and a modded card is
  # a plain .catbox.
  #
  # So: exactly ONE hint row (the vanilla card keeps its own) and TWO slack spacers. Restored
  # to the pre-2.53 numbers rather than left at the inflated ones, because a marker that still
  # expects a row the product no longer has is a marker that fails on correct code.
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
  # 2.53 moved these from a hardcoded game/BepInEx path to $treeRoot, because the install
  # loop now runs once per destination tree. Re-anchored on the new form rather than dropped:
  # unzip -d still creates only the LAST path component, so a missing parent still means
  # EVERY plugin unzip fails silently into a world that boots with no mods.
  am=$(grep -c "mkdir -p \$treeRoot/BepInEx/plugins" /opt/stateless/engine/includes/0-functions.sh)
  an=$(grep -c "unzipResult -ne 11" /opt/stateless/engine/includes/0-functions.sh)
  mkline=$(grep -n "mkdir -p \$treeRoot/BepInEx/plugins" /opt/stateless/engine/includes/0-functions.sh | head -1 | cut -d: -f1)
  uzline=$(grep -n "BepInEx/plugins/\$modName/" /opt/stateless/engine/includes/0-functions.sh | head -1 | cut -d: -f1)
  ao=0; [ -n "$mkline" ] && [ -n "$uzline" ] && [ "$mkline" -lt "$uzline" ] && ao=1
  echo "plugins mkdir=$am (want 1)  tolerates exit 11=$an (want 1)  mkdir before unzip=$ao (want 1)"
  # Both admin launch paths must go through the shared helper, and the unconditional +connect
  # must be GONE from both -- checking only that the helper is called would pass on an image
  # where one path still built its own href.
  ap=$(grep -c "function getVanillaJoinInfo" /opt/stateless/nginx/www/includes/db_gets.php)
  # RE-ANCHORED in 2.53, and the old form was a false pass rather than a false fail. A bare
  # "getVanillaJoinInfo(" count over index.php was 3: one real call and TWO comment mentions.
  # It was therefore mostly measuring its own prose -- rewording a comment in 2.53 dropped it
  # to 2 and failed the verify on an image whose call site was untouched and correct. Anchored
  # on the leading ternary now, which only the real call has. 1 before 2.53 and 1 after, so the
  # re-anchoring is not hiding a change.
  aq=$(grep -cE "^[[:space:]]*\? getVanillaJoinInfo\(" /opt/stateless/nginx/www/admin/index.php)
  ar=$(grep -c "getVanillaJoinInfo(" /opt/stateless/nginx/www/admin/adminAPI.php)
  as=$(grep -c "function launchButtonHtml" /opt/stateless/nginx/www/admin/index.php)
  at=$(grep -hcE "^[[:space:]]*\? .steam://run/892970//\+connect ." /opt/stateless/nginx/www/admin/index.php /opt/stateless/nginx/www/admin/adminAPI.php | paste -sd+ | bc)
  echo "shared join helper=$ap (want 1)  admin render=$aq (want 1, re-anchored in 2.53)  admin poll=$ar (want 2)"
  echo "js null-href guard=$as (want 1)  stale unconditional +connect=$at (want 0)"

  # ---- 2.41 ----------------------------------------------------------------------------
  # The old NO APOSTROPHES rule no longer applies here -- quoted heredoc, run from a file.
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
  # A world may run with no password; what it cannot do is run LISTED without one. Both
  # surfaces gate the listing control.
  #
  # Comment lines are stripped before counting. 2.53 added a fourth real call site (crossplay
  # also blocks listing, so flipping that switch has to re-evaluate the row) AND a comment
  # naming the function, which would have pushed a raw count to 5 and failed the build on prose.
  # Three of these counts have now moved for comment edits rather than behaviour; strip first.
  bx=$(grep -v '^[[:space:]]*//' /opt/stateless/nginx/www/admin/index.php | grep -c "syncListedAvailability")
  by=$(grep -c "syncListedAvailability" /opt/stateless/nginx/www/admin/new_world.php)
  echo "listing gated: settings=$bx (want 4, was 3 before 2.53 added the crossplay re-check)  create=$by (want 2)"
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
  # The old NO APOSTROPHES rule no longer applies here -- quoted heredoc, run from a file.
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
  # EVERY path that deletes a world must clear it first. That was 2 paths through 2.49
  # (normal delete AND failed deployment); 2.50 removed the failed-deployment one, which
  # deleted a world because a chown returned non-zero, so there is now exactly 1.
  #
  # This is a real count change, not a relaxed number: the assertion is still "every
  # delete path clears world_mods", and the REASON it is 1 is pinned separately by the
  # 2.50 negative marker vb (create branch deletes row=0). If a second delete path ever
  # comes back, this goes to 2 and vb goes to 1, and both have to be explained.
  dz=$(grep -c "deleteWorldModRows" /opt/stateless/engine/phvalheim)
  ea=$(grep -c "function deleteWorldModRows" /opt/stateless/engine/includes/0-functions.sh)
  eb=$(grep -c "function pruneOrphanedWorldMods" /opt/stateless/engine/includes/0-functions.sh)
  ec=$(grep -c "^pruneOrphanedWorldMods" /opt/stateless/engine/phvalheim)
  # The vanilla switch purge has to reach world_mods too, or it reports success and then
  # builds the world with its full mod list anyway.
  ed=$(grep -c "DELETE wm FROM world_mods" /opt/stateless/nginx/www/includes/db_sets.php)
  echo "2.43 orphan guard: delete calls=$dz (want 1, was 2 before 2.50)  fn=$ea sweep fn=$eb boot sweep=$ec (want 1 each)"
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
  # The old NO APOSTROPHES rule no longer applies here -- quoted heredoc, run from a file.
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
  # The old NO APOSTROPHES rule no longer applies here -- quoted heredoc, run from a file.
  fm=$(grep -o seedModCatalogue /opt/stateless/engine/includes/0-functions.sh \
       /opt/stateless/engine/phvalheim 2>/dev/null | wc -l)
  # The sync must NOT be forced (a full refetch on every container restart) and must use
  # trigger=boot (trigger=cron obeys modSyncIntervalHours and would silently skip).
  # Anchor on the INVOCATION, not the bare string. This was `grep -c "trigger boot"`, which
  # also matched the comment above the call site that 2.47 added to explain the flag -- so it
  # read 2 against a printed (want 1) while its own gate was the looser `-gt 0`, and the log
  # showed a mismatch on an image that was perfectly correct. Marker rot, not a regression.
  fn=$(grep -cE "modSync.py .*--trigger boot" /opt/stateless/engine/includes/0-functions.sh)
  # fo anchored on the literal string "setsid /opt/stateless/engine/tools/modSync.py" until
  # 2.52 put an su between the two halves. The grep then matched nothing, the pipe counted
  # nothing, and fo read 0 -- its own want value -- so it went on passing while asserting
  # NOTHING about --force. Re-anchored on the line that actually launches the sync, which is
  # what it always meant. Keep this counting the LAUNCH, not any fixed prefix of it.
  fo=$(grep -A1 -E "modSync.py --source all --trigger boot" \
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
  # The old NO APOSTROPHES rule no longer applies here -- quoted heredoc, run from a file.
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
  # The old NO APOSTROPHES rule no longer applies here -- quoted heredoc, run from a file.
  # (The dots standing in for quotes below are left as they were: `.` matches the quote fine,
  # and rewriting working patterns buys nothing.)
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
  # jb was assigned here and then never printed and never gated -- a probe that ran on every
  # build and could not fail. Its siblings ja and jc were both in the chain, so it was simply
  # dropped from it. Wired up below at its real value.
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
  #
  # RE-POINTED in 2.53. This counted "crossplay is OFF", the modded branch that said the flag
  # had been refused -- and 2.53 deletes that branch, because a modded world now gets
  # -crossplay. The PRINCIPLE is what this marker is for, and it survives: both remaining
  # messages still lead with the effective setting rather than the stored one. So count those
  # instead, 2 of them, one per branch. Re-pointing rather than deleting, because the reported
  # bug this came from was about wording and the wording is still a thing that can regress.
  # ky below is untouched and still reads 1: whatsnew entries are historical and 2.46 keeps its.
  kw=$(grep -c "crossplay is ON" /opt/stateless/games/valheim/scripts/startWorld.sh)
  # ...and the operator has to be TOLD, or the fix is invisible to the person who reported it.
  ky=$(grep -c "crossplay is OFF" /opt/stateless/nginx/www/includes/whatsnew.php)
  # Anchored on echo, NOT the bare phrase: the comment above the fix QUOTES the old wording
  # to explain why it changed, so a bare count is 1 on correct source. This script has been
  # tripped by markers counting their own prose before.
  kx=$(grep -c "echo.*has crossplay set" /opt/stateless/games/valheim/scripts/startWorld.sh)
  echo "crossplay line: leads-with-outcome=$kw (want 2, re-pointed in 2.53)  old-misleading-wording=$kx (want 0)  whatsnew=$ky (want 1)"

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
  # A world converted to vanilla keeps its world_mods rows while the vanilla path skips the
  # whole mod install, so without these every row stays installed_at NULL and the Updates
  # tab waits for data forever -- Rebuild Mods takes the same skip. Reproduced live.
  qc=$(grep -c "IFNULL(vanilla,0) FROM worlds WHERE id=" /opt/stateless/engine/tools/updateChecker.py)
  qd=$(grep -c "IFNULL(vanilla,0) FROM worlds WHERE id=" /opt/stateless/engine/tools/worldMods.py)
  # Counted, not anchored: the call ends in a line continuation, so a $-anchored pattern
  # matched nothing and reported a missing fix that was present.
  qe=$(grep -c -- "--record-installed" /opt/stateless/engine/phvalheim)
  qf=$(grep -c "isVanilla else install_rows" /opt/stateless/engine/tools/worldMods.py)
  # The "Update started..." banner had no path that ever took it down: reported from a real
  # server with the job finished and the banner still up. Setters stamp, the refresh retires
  # a stamped banner once the world is idle, and every error path unstamps so its message
  # survives. The age gate is what keeps it from racing the engine 2s tick.
  qg=$(grep -c "dataset.transientAt = String" /opt/stateless/nginx/www/admin/index.php)
  qh=$(grep -c "delete status.dataset.transientAt" /opt/stateless/nginx/www/admin/index.php)
  qi=$(grep -c "delete actionStatus.dataset.transientAt" /opt/stateless/nginx/www/admin/index.php)
  qj=$(grep -c "transientAt, 10)) > 10000" /opt/stateless/nginx/www/admin/index.php)
  # Two forced syncs of DIFFERENT sources deadlocked on the one global mod_deps table and
  # cost a production server 668 versions of dependency edges. One lock for the whole run,
  # and zero-edge versions rejoin the retry set so a lost rebuild heals itself.
  qk=$(grep -c "def take_global_lock" /opt/stateless/engine/tools/modSync.py)
  ql=$(grep -c "lock = take_global_lock" /opt/stateless/engine/tools/modSync.py)
  qm=$(grep -c "already queued behind it" /opt/stateless/engine/tools/modSync.py)
  qn=$(grep -c "NOT EXISTS (SELECT 1 FROM mod_deps d" /opt/stateless/engine/tools/modSync.py)
  echo "2.47 sync lock: fn=$qk (want 1)  used in main=$ql (want 1)  queue depth 1=$qm (want 1)  orphan retry=$qn (want 1)"
  echo "2.47 update banner: setters stamp=$qg (want 2)  errors unstamp=$qh (want 4)  rule=$qi (want 1)  age gate=$qj (want 1)"
  echo "2.47 vanilla mods: checker guard=$qc (want 1)  recorder guard=$qd (want 1)  empty plan=$qf (want 1)  engine calls it=$qe (want 1)"
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

  # ---- 2.48: restore put the world back where the server reads it (issue #89) ------
  # worldRestore had NO markers at all before this release, so none of this was verified
  # in the image. The headline marker is a NEGATIVE: the old probe was
  #   grep -q "worlds_local/"
  # which matches BOTH archive layouts, so every 2.38+ backup was misread as pre-2.38 and
  # unpacked one world tree too deep, leaving the -savedir empty and Valheim generating a
  # fresh world. Asserting the new anchored probe exists is not enough -- it passes just as
  # well on an image that still has the unanchored one somewhere beside it.
  #
  # No apostrophes and no "$" in these patterns. The whole verify payload is one
  # single-quoted sh -c argument, so a lone apostrophe -- even in a comment like this one --
  # truncates the verify silently; the guard at the top of this script refuses the build for
  # it. And a "$" in a double-quoted grep argument would expand here instead of matching.
  # "." stands in for both.
  ra=$(grep -c "archiveListingIsLegacy" /opt/stateless/engine/tools/worldRestore)
  rb=$(grep -c "grep -q .worlds_local/." /opt/stateless/engine/tools/worldRestore)
  rc_=$(grep -c "worlds_local/|^worlds_local/" /opt/stateless/engine/tools/worldRestore)
  # ra counts the definition plus BOTH call sites -- the plain-tar branch and the zstd
  # eval branch. A fix applied to only one of them still restores .tar.zst backups wrong.
  echo "2.48 restore probe: fn+both call sites=$ra (want 3)  anchored regex=$rc_ (want 1)"
  echo "2.48 restore probe NEGATIVE: unanchored grep gone=$rb (want 0)"

  # step 5b, the recovery path for worlds the old bug already buried. Keyed on the Unity
  # save path appearing TWICE in a row, which a real world directory can never contain.
  # rf is a NEGATIVE against the narrower condition this was first written with
  # (...$unityRel/$unityRel/worlds_local): it only matched ONE level of nesting, so a world
  # wrecked by two successive bad restores was silently left broken.
  rd=$(grep -c "unityRel/.unityRel" /opt/stateless/engine/tools/worldRestore)
  re=$(grep -c "liftTmp" /opt/stateless/engine/tools/worldRestore)
  rf=$(grep -c "unityRel/.unityRel/worlds_local" /opt/stateless/engine/tools/worldRestore)
  ri=$(grep -c "liftCount. -lt 5" /opt/stateless/engine/tools/worldRestore)
  rj=$(grep -c "mv -t ..worldDir" /opt/stateless/engine/tools/worldRestore)
  rg=$(grep -c "2.48. => ." /opt/stateless/nginx/www/includes/whatsnew.php)
  echo "2.48 nesting repair: doubled-path condition=$rd (want 1)  bounded=$ri (want 1)  swap=$re (want 6)/$rj (want 1)"
  echo "2.48 nesting repair NEGATIVE: single-level-only condition=$rf (want 0)"
  echo "2.48 whatsnew entry=$rg (want 1)"

  # ---- 2.49: the loader config the mod-config purge was deleting -------------------
  # BepInEx/config/BepInEx.cfg is the LOADER.s config, not a mod config. The purge that
  # clears mod configs on every world rebuild swept it too, and nothing put it back, so
  # the world booted on BepInEx stock defaults where [Logging.Console] is false. That one
  # setting feeds BOTH symptoms: the plugin lines the world log gets from BepInEx stdout,
  # and the console window on the client -- packageClient zips ./BepInEx whole, so a
  # server with no cfg ships a client with no cfg.
  #
  # The headline marker is the NEGATIVE. Asserting the new scoped find exists proves
  # nothing on its own: the old unconditional rm -rf could still be sitting beside it,
  # and it ran first.
  #
  # Same quoting rule as the 2.48 block above -- no apostrophes, no "." that is really a
  # dollar sign. "." stands in for a literal quote.
  sa=$(grep -c "ensureBepInExLoaderConfig" /opt/stateless/engine/includes/0-functions.sh)
  sb=$(grep -c "ensureBepInExLoaderConfig" /opt/stateless/engine/phvalheim)
  sc=$(grep -c "rm -rf .*BepInEx/config" /opt/stateless/engine/includes/0-functions.sh)
  sd=$(grep -c "mindepth 1" /opt/stateless/engine/includes/0-functions.sh)
  se=$(grep -c "bepinex_default.cfg" /opt/stateless/engine/includes/0-functions.sh)
  sf=$(grep -c "2.49. => ." /opt/stateless/nginx/www/includes/whatsnew.php)
  # sb is the call site in the engine loop. It must sit AFTER installCustomModsConfigsPatchers
  # and BEFORE packageClient -- a call in the wrong place verifies as present and still
  # ships a client payload with no cfg in it.
  # -A8, not -A4: the call carries a four-line comment, so it lands at +6. The first RC of
  # 2.49 reported this as 0 against an image whose ordering was correct -- a marker whose
  # window is too small is a false failure, and the next person reads it as a real one.
  sg=$(grep -A8 "installCustomModsConfigsPatchers ." /opt/stateless/engine/phvalheim | grep -c "ensureBepInExLoaderConfig")
  echo "2.49 loader cfg: helper+refs=$sa (want 3)  call site=$sb (want 1)  ordered before packaging=$sg (want 1)"
  echo "2.49 loader cfg: purge keeps it=$sd (want 1)  pack stash=$se (want 2)"
  echo "2.49 loader cfg NEGATIVE: unconditional rm -rf of BepInEx/config gone=$sc (want 0)"
  echo "2.49 whatsnew entry=$sf (want 1)"

  # ---- 2.49: Game DNS never reached quick_connect_servers.cfg ----------------------
  # worlds.external_endpoint was stamped at CREATION and never updated -- two INSERTs and
  # zero UPDATEs in the whole tree -- while the Steam launch string reads gameDNS live.
  # So the two join paths disagreed the moment an operator edited Game DNS.
  #
  # The headline marker is the NEGATIVE, sh below: the frozen read must survive at exactly
  # ONE site, the empty-gameDNS fallback. Two means the primary read came back and the
  # positives above would still pass.
  sh_=$(grep -c "SELECT external_endpoint FROM worlds" /opt/stateless/engine/phvalheim)
  si=$(grep -c "worldHost=..gameDNS" /opt/stateless/engine/phvalheim)
  sj=$(grep -c "UPDATE worlds SET external_endpoint=" /opt/stateless/engine/phvalheim)
  sk=$(grep -c "worldHost=..gameDNS" /opt/stateless/games/valheim/scripts/importWorld.sh)
  # The notice itself: overlay, the element the new hostname is written into, the dismiss
  # handler, and the guard that only fires it when the value actually CHANGED. Without that
  # last one it would nag on every unrelated settings save.
  sl=$(grep -c "gameDnsNoticeOverlay" /opt/stateless/nginx/www/admin/index.php)
  sm=$(grep -c "_ssOriginalGameDNS" /opt/stateless/nginx/www/admin/index.php)
  echo "2.49 gameDNS: live read=$si (want 1)  refresh=$sj (want 1)  import fixed=$sk (want 1)"
  echo "2.49 gameDNS notice: overlay refs=$sl (want 3)  change guard=$sm (want 2)"
  echo "2.49 gameDNS NEGATIVE: frozen read is fallback only=$sh_ (want 1)"
  # The engine runs for weeks, so the startup `export gameDNS=...` is NOT a live value, and
  # the loop-top `source /etc/environment` re-imposes the boot-time copy every pass. The
  # first 2.49 RC had every marker above passing and still wrote the OLD hostname into a
  # freshly updated world: it read the startup variable. Two read sites = startup + loop.
  sn=$(grep -c "SELECT gameDNS FROM settings" /opt/stateless/engine/phvalheim)
  echo "2.49 gameDNS live refresh: read sites=$sn (want 2)"

  # ---- 2.50: a failed steamcmd update was reported as a success ---------------------
  # InstallAndUpdateValheim judged a steamcmd run by [ -f valheim_server.x86_64 ] alone.
  # steamcmd reports a partial update as state 0x6, which is FullyInstalled|UpdateRequired --
  # the binary is on disk either way, so that test answered the same whether the update had
  # worked or not. It also set steamcmdSuccess=true, which exited the retry loop on attempt
  # 1, so the five retries never ran for the one fault they were written for.
  #
  # The HEADLINE marker is the NEGATIVE, tc: no bare binary-existence test may set the
  # success flag again. The positives below all pass on an image that also still has the
  # old path sitting beside the new one.
  tc=$(grep -A1 "valheim_server.x86_64.*]; then" /opt/stateless/engine/includes/0-functions.sh | grep -c "steamcmdSuccess=true")
  # Anchored on the assignment, NOT the bare word: the comment explaining the fix names
  # steamcmdSuccess=true while explaining the bug, and a marker that counts its own prose
  # is a marker that breaks on the next comment edit. Exactly 2 = verified, and unverifiable.
  ta=$(grep -cE "^(function valheimInstallVerdict|[[:space:]]+valheimInstallVerdict )" /opt/stateless/engine/includes/0-functions.sh)
  tb=$(grep -cE "^(function valheimAppStateFlags|[[:space:]]+(flags|stateFlags)=.\(valheimAppStateFlags)" /opt/stateless/engine/includes/0-functions.sh)
  tg=$(grep -cE "^[[:space:]]+steamcmdSuccess=true" /opt/stateless/engine/includes/0-functions.sh)
  # StateFlags is a bitmask, so the fix cannot be "is it 6". 4011 is every bit meaning
  # not-done; without it, state 12 (update queued) and 36 (files missing) verify as clean.
  # This number IS the fix -- a build with the function present but this mask missing is
  # the half-fix that the first cut of the patch actually was.
  td=$(grep -c "10#.flags & 4011" /opt/stateless/engine/includes/0-functions.sh)
  th=$(grep -c "could NOT be verified" /opt/stateless/engine/includes/0-functions.sh)
  te=$(grep -c "disk: .dfLine" /opt/stateless/engine/includes/0-functions.sh)
  tf=$(grep -c "2.50. => ." /opt/stateless/nginx/www/includes/whatsnew.php)
  echo "2.50 install verdict NEGATIVE: bare binary-existence success gone=$tc (want 0)"
  echo "2.50 install verdict: fn def+call=$ta (want 2)  stateflags def+reads=$tb (want 3)"
  echo "2.50 install verdict: success sites=$tg (want 2)  unverifiable third state=$th (want 1)"
  echo "2.50 install verdict: bad-bit mask 4011=$td (want 1)  disk diagnostic=$te (want 1)"
  echo "2.50 whatsnew entry=$tf (want 1)"

  # ---- 2.50: escalating self-repair between steamcmd attempts ----------------------
  # The world SAVE lives INSIDE the game dir (-savedir is game/.config/unity3d/...), and
  # the mod loader is game/BepInEx. The obvious shape for self-healing -- wipe the game
  # dir and reinstall -- would delete every world save on the server.
  #
  # The HEADLINE marker is uc, and it is a NEGATIVE: the repair function must not so much
  # as NAME player data. ub pins the number of rm statements inside it, so a sixth one
  # cannot be added without this gate going red and someone re-reading the path list.
  uc=$(awk "/^function healSteamcmdState/,/^}/" /opt/stateless/engine/includes/0-functions.sh | grep -cE "unity3d|BepInEx|savedir")
  ub=$(awk "/^function healSteamcmdState/,/^}/" /opt/stateless/engine/includes/0-functions.sh | grep -cE "^ +rm -[rf]")
  ue=$(awk "/^function healSteamcmdState/,/^}/" /opt/stateless/engine/includes/0-functions.sh | grep -c "refusing")
  ua=$(grep -cE "^(function healSteamcmdState|[[:space:]]+healSteamcmdState )" /opt/stateless/engine/includes/0-functions.sh)
  ud=$(grep -c "healLevel -gt 4" /opt/stateless/engine/includes/0-functions.sh)
  # NEGATIVE: the old fixed cleanup must be gone, or the loop repeats one repair 5 times.
  uf=$(grep -c "Clean up Steam directory before retry" /opt/stateless/engine/includes/0-functions.sh)
  echo "2.50 self-heal NEGATIVE: repair never names player data=$uc (want 0)"
  echo "2.50 self-heal NEGATIVE: old fixed cleanup gone=$uf (want 0)"
  echo "2.50 self-heal: fn def+call=$ua (want 2)  rm statements=$ub (want 5)"
  echo "2.50 self-heal: escalation cap=$ud (want 1)  empty-world guard=$ue (want 1)"

  # ---- 2.50: world deployment was gated on a chown exit status --------------------
  # The create branch ran `chown -R; RESULT=$?` and, when that was non-zero, deleted the
  # worlds row and rm -rf-ed the directory. chown reports whether it could change every
  # file it walked, which is a different question from whether the world deployed -- one
  # unchownable file destroyed a good deployment, and the branch also handles CLONES,
  # whose directory arrives already holding a copied save.
  #
  # Every marker here reads the engine with COMMENTS STRIPPED. The fix carries comments
  # that quote the removed code verbatim, so a grep over raw source matches the prose
  # explaining the bug and then reports the bug as still present. That is exactly what
  # happened when this test was first written -- three false failures in one run.
  vcode=$(grep -vE "^[[:space:]]*#" /opt/stateless/engine/phvalheim)
  vspan=$(echo "$vcode" | awk "/Deploying new world/,/Delete command received/")
  va=$(grep -cE "^(function worldDirIsPrepared|[[:space:]]+deployMissing=.\(worldDirIsPrepared)" /opt/stateless/engine/includes/0-functions.sh /opt/stateless/engine/phvalheim | awk -F: "{s+=\$2} END{print s}")
  vb=$(echo "$vspan" | grep -c "DELETE FROM worlds")
  vc=$(echo "$vspan" | grep -c "rm -rf")
  vd=$(echo "$vspan" | grep -c "mode=.broken.")
  ve=$(echo "$vspan" | grep -c "RESULT=")
  echo "2.50 deploy verdict NEGATIVE: create branch deletes row=$vb rm -rf=$vc (want 0/0)"
  echo "2.50 deploy verdict NEGATIVE: chown exit status gates deploy=$ve (want 0)"
  echo "2.50 deploy verdict: fn def+call=$va (want 2)  marks broken=$vd (want 1)"

  # ---- 2.51: Flatpak in the client download popover --------------------------------
  # Two markers together are the oracle, and neither works alone: wb proves exactly one
  # flatpak link exists in the whole file, wa proves the one that exists is inside a
  # Linux branch. Drop either and a link pasted into the Windows branch passes.
  #
  # The awk span matches BOTH Linux branches in the file (the header title and the link
  # block) because awk restarts a range pattern. That is harmless: the header block holds
  # no hrefs, so the count is unchanged, and pinning the span to one of them would break
  # the next time the file is reordered.
  wcode=$(grep -vE "^[[:space:]]*(//|#)" /opt/stateless/nginx/www/includes/clientDownloadButton.php)
  wlin=$(echo "$wcode" | awk "/if\(.operatingSystem == .Linux.\)/,/^[[:space:]]*}[[:space:]]*$/")
  wa=$(echo "$wlin" | grep -c "x86_64.flatpak")
  wb=$(echo "$wcode" | grep -c "x86_64.flatpak")
  # The version gate. A dead link for every pre-2.0.13 client tag is what this stops, and
  # it is invisible until someone raises clientVersionsToRender. wd counts the guard, the
  # define and the use: three references, no more and no less.
  wc=$(echo "$wcode" | grep -c "version_compare")
  wd=$(echo "$wcode" | grep -c "PHVALHEIM_CLIENT_FIRST_FLATPAK")
  # The icon has to be IN the image, not just in the repo. images/ rides in on the same
  # COPY as the php, so a missing file here means the asset was never committed.
  we=$(ls /opt/stateless/nginx/www/images/flatpak.svg 2>/dev/null | wc -l)
  # we above only proves the file is THERE. The first 2.51 release candidate shipped a
  # flatpak.svg that was present, the right size, owned correctly and completely undrawable:
  # the header comment ended with a doubled hyphen, which XML forbids inside a comment, so
  # every browser refused it and the popover showed a blank gap. Existence was a non-oracle.
  # ws parses the file the way a browser would. wt/wu sweep every svg in the image and derive
  # their own expected value, so the pair cannot rot as artwork is added or removed.
  ws=$(python3 -c "import xml.dom.minidom,sys; xml.dom.minidom.parse(sys.argv[1]); print(1)" /opt/stateless/nginx/www/images/flatpak.svg 2>/dev/null)
  wt=$(for f in /opt/stateless/nginx/www/images/*.svg; do python3 -c "import xml.dom.minidom,sys; xml.dom.minidom.parse(sys.argv[1])" "$f" 2>/dev/null && echo x; done | wc -l)
  wu=$(ls /opt/stateless/nginx/www/images/*.svg | wc -l)
  wf=$(grep -c "2.51. => ." /opt/stateless/nginx/www/includes/whatsnew.php)
  echo "2.51 flatpak link: in Linux branch=$wa (want 1)  in whole file=$wb (want 1)"
  echo "2.51 flatpak gate: version_compare=$wc (want 1)  floor const refs=$wd (want 3)"
  echo "2.51 flatpak icon in image=$we (want 1)  whatsnew entry=$wf (want 1)"
  echo "2.51 flatpak icon PARSES=$ws (want 1)  all svgs well-formed=$wt of $wu"

  # ---- 2.51: named labels, icon spacing, and the Flatpak instructions modal --------
  # wg is the NEGATIVE and the real one: every icon said Download, so a label that failed
  # to change leaves the word behind. wh alone would pass with four of the five renamed.
  # Note the dot in versionLabel.> -- that character is a single quote in the source, and a
  # single quote anywhere in this payload truncates the whole verify.
  wg=$(echo "$wcode" | grep -c "versionLabel.>Download")
  wh=$(echo "$wcode" | grep -cE "versionLabel.>(Windows|Universal|Ubuntu|Fedora|Flatpak)<")
  wi=$(echo "$wcode" | grep -c "client_download_cell")
  wj=$(echo "$wcode" | grep -c "return openFlatpakInstall(this)")
  wacode=$(grep -vE "^[[:space:]]*(//|#)" /opt/stateless/nginx/www/public/authenticated.php)
  wk=$(echo "$wacode" | grep -c "flatpakInstallModal")
  wl=$(echo "$wacode" | grep -c "var FLATPAK_CMDS")
  # wm is the single-source guard. The command blocks must be EMPTY in the markup and filled
  # from FLATPAK_CMDS when the modal opens, so the text on screen and the text on the
  # clipboard cannot differ. Type a command into the markup and this drops below 4.
  wm=$(echo "$wacode" | grep -oE "id=.fpCmd[A-Za-z]*.></code>" | wc -l)
  wn=$(echo "$wacode" | grep -c "copyFlatpakCmd(this,")
  wo=$(echo "$wacode" | grep -c "flatpakDownloadBtn")
  # The feature dies SILENTLY without this. Bootstrap sanitizes popover html by default and
  # its allowList drops event handlers, so the onclick would be stripped and the Flatpak icon
  # would quietly go back to being a plain download with no instructions anywhere.
  wp=$(echo "$wacode" | grep -c "sanitize: false")
  wq=$(grep -c "flatpakInstallModal.modal" /opt/stateless/nginx/www/css/phvalheimStyles.css)
  wr=$(grep -c "td.client_download_cell" /opt/stateless/nginx/www/css/phvalheimStyles.css)
  echo "2.51 labels NEGATIVE: icons still saying Download=$wg (want 0)"
  echo "2.51 labels: named=$wh (want 5)  spaced cells=$wi (want 5)  css cell rule=$wr (want 1)"
  echo "2.51 modal: refs=$wk (want 4)  cmd table=$wl (want 1)  copy btns=$wn (want 4)  dl btn=$wo (want 2)"
  echo "2.51 modal single-source: empty cmd blocks=$wm (want 4)"
  echo "2.51 modal wiring: icon onclick=$wj (want 1)  popover sanitize off=$wp (want 2)  css=$wq (want 1)"

  # ---- 2.52: cross-user /tmp state -------------------------------------------------
  # STILL no apostrophes below, comments included. The whole body is inside sh -c and one
  # closes it early, silently skipping every check after it.
  #
  # xa/xb are the release. They ARE the 2.47-2.51 bug: open(LOCK, w) needs write permission
  # to truncate, so whichever uid ran first owned the file in sticky /tmp and locked the
  # other out for good. Negatives, because a correct open_lock() sitting beside a leftover
  # open(...,w) call site is still broken and every positive marker would pass.
  xsrc=/opt/stateless/engine/tools/modSync.py
  xa=$(grep -c "open(RUN_LOCK, .w.)" $xsrc)
  xb=$(grep -c "open(WAIT_LOCK, .w.)" $xsrc)
  xc=$(grep -c "def open_lock" $xsrc)
  xd=$(grep -c "open_lock(RUN_LOCK)" $xsrc)
  xe=$(grep -c "open_lock(WAIT_LOCK)" $xsrc)
  # O_CREAT must appear EXACTLY once and O_RDONLY-alone twice: the plain open is tried
  # first and retried after a create. Passing O_CREAT on the normal path reintroduces the
  # half of this bug that only shows up in the reverse uid direction -- Linux sticky-dir
  # hardening (fs.protected_regular) refuses O_CREAT on an existing file in /tmp owned by
  # someone who is neither the caller nor the directory owner, and CAP_DAC_OVERRIDE does
  # NOT bypass it, so even root is refused. xg going to 1 is that regression.
  xf=$(grep -c "os.O_RDONLY | os.O_CREAT" $xsrc)
  xg=$(grep -c "os.open(path, os.O_RDONLY)" $xsrc)
  # One user for all three triggers. xi is the negative: the un-su-ed launch must be GONE,
  # not merely joined by a new one. Note 2.43 marker fo was re-anchored in the same pass --
  # it had been keyed to the exact string xi now wants zero of, so it would have kept
  # passing while checking nothing.
  xfn=/opt/stateless/engine/includes/0-functions.sh
  xh=$(grep -cE "setsid su phvalheim -s /bin/sh -c ./opt/stateless/engine/tools/modSync.py --source all --trigger boot." $xfn)
  xi=$(grep -c "setsid /opt/stateless/engine/tools/modSync.py" $xfn)
  # The sweep. Every fixed-path /tmp file written by a tool that can run as either uid.
  xj=$(grep -c "phvalheim_analytics_payload.json" /opt/stateless/engine/tools/pushAnalytics.sh)
  xk=$(grep -c "phvalheim_analytics.tmp" /opt/stateless/engine/tools/pushAnalytics.sh)
  xl=$(grep -c "mktemp /tmp/phvalheim_analytics_payload" /opt/stateless/engine/tools/pushAnalytics.sh)
  # The three lock files: read-only flock, never a write-open or a pidfile. xm/xn/xo are
  # the negatives that matter; the old forms are what wedge.
  #
  # COMMENTS ARE STRIPPED FIRST, and that is not cosmetic. Each of these three files now
  # carries a comment explaining which old form it replaced, quoting that form verbatim --
  # so the bare greps read 1 against a want of 0 on a tree that was perfectly correct.
  # Caught in the repo dry-run. The probe was wrong, not the code.
  xua=$(grep -vE "^[[:space:]]*#" /opt/stateless/engine/tools/updateApplier)
  xba=$(grep -vE "^[[:space:]]*#" /opt/stateless/engine/tools/worldBackup)
  xra=$(grep -vE "^[[:space:]]*#" /opt/stateless/engine/tools/worldRestore)
  xm=$(echo "$xua" | grep -c "exec 9>")
  xn=$(echo "$xba" | grep -c "echo \$\$ >")
  xo=$(echo "$xra" | grep -c "echo \$\$ >")
  xp=0
  echo "$xua" | grep -q "exec 9<" && xp=$((xp + 1))
  echo "$xba" | grep -q "exec 9<" && xp=$((xp + 1))
  echo "$xra" | grep -q "exec 9<" && xp=$((xp + 1))
  # worldBackup must NOT rm its lock on exit any more: unlinking a file another run is
  # about to open gives two holders on two inodes, which is not a lock at all.
  xq=$(echo "$xba" | grep -c "trap .rm -f \$LOCK_FILE. EXIT")
  # The cross-user oracle has to be IN the repo image-side check as a file that exists and
  # parses; a suite nobody can run is not a guard.
  xr=0; bash -n /opt/stateless/engine/tools/modSync.py 2>/dev/null; python3 -m py_compile $xsrc 2>/dev/null && xr=1
  xs=$(grep -c "2.52. => ." /opt/stateless/nginx/www/includes/whatsnew.php)
  echo "2.52 NEGATIVES lock write-open: RUN=$xa WAIT=$xb (want 0/0)  un-su-ed boot launch=$xi (want 0)"
  echo "2.52 open_lock: def=$xc RUN=$xd WAIT=$xe (want 1/1/1)  O_CREAT=$xf (want 1)  plain O_RDONLY=$xg (want 2)"
  echo "2.52 boot sync as phvalheim=$xh (want 1)"
  echo "2.52 analytics NEGATIVES: fixed payload path=$xj fixed resp path=$xk (want 0/0)  mktemp=$xl (want 1)"
  echo "2.52 lock sweep NEGATIVES: updateApplier exec 9>=$xm backup pidfile=$xn restore pidfile=$xo (want 0/0/0)"
  echo "2.52 lock sweep: tools on read-only flock=$xp (want 3)  backup rm-on-exit=$xq (want 0)"
  echo "2.52 modSync compiles=$xr (want 1)  whatsnew entry=$xs (want 1)"

  # ---- 2.53: crossplay on modded worlds --------------------------------------------
  # STILL no apostrophes below, comments included -- the whole body is inside sh -c and one
  # closes it early, silently skipping every check after it. Note that the FILES grepped are
  # full of apostrophes (PHP string literals); that is fine, only this script cannot have them.
  # Hence the dot-for-quote patterns throughout, same as the 2.52 block above.
  #
  # The release removes a gate that existed in FIVE places, so the headline markers are all
  # NEGATIVES: four of the five can be removed while the fifth still forces crossplay off, and
  # every positive marker passes in that state. ya is the one that decides the feature.
  ysw=/opt/stateless/games/valheim/scripts/startWorld.sh
  yfn=/opt/stateless/nginx/www/admin/adminAPI.php
  yix=/opt/stateless/nginx/www/admin/index.php
  ynw=/opt/stateless/nginx/www/admin/new_world.php
  ydb=/opt/stateless/nginx/www/includes/db_gets.php
  yau=/opt/stateless/nginx/www/public/authenticated.php
  yap=/opt/stateless/nginx/www/public/api.php

  # The argv gate. ya: the compound isCrossplay-AND-isVanilla condition must be GONE.
  # .+ around each variable, not a single dot: the shell test reads [ "$isCrossplay" -- a
  # quote AND a dollar sit before the name, so a single dot matches neither. The first cut of
  # this marker read 0 against the OLD tree as well as the new one, i.e. it could not fail and
  # was verifying nothing. Checked in both directions before being trusted.
  ya=$(grep -cE "^if \[ .+isCrossplay.+&& \[ .+isVanilla" $ysw)
  # yb: and so must the refusal notice. A build that passes -crossplay while still logging
  # that it refused to is half-applied, and the log is what an operator reads.
  yb=$(grep -c "crossplay is OFF" $ysw)
  yc=$(grep -c "CANNOT run mods" $ysw)
  # The .running-options half, which was the easy half to miss. effectiveCrossplay=1 used to
  # sit INSIDE the if-isVanilla block; a modded world would then be handed -crossplay and
  # record crossplay=0, and every UI reads that file for a LIVE world. Anchored on INDENTATION:
  # yd wants the assignment at column 0 (outside the block), ye wants zero indented copies.
  # Counting the string alone cannot tell the two positions apart, and the position IS the fix.
  # .+ rather than . between the words: the shell test reads [ "$isCrossplay" = "1" ], so there
  # are TWO characters before the variable name, a quote and a dollar. A single dot matched
  # neither position and reported 0 against a tree that was correct -- caught in the repo dry
  # run, where the probe was wrong and the code was not.
  yd=$(grep -cE "^\[ .+isCrossplay.+effectiveCrossplay=1" $ysw)
  ye=$(grep -cE "^[[:space:]]+\[ .+isCrossplay.+effectiveCrossplay=1" $ysw)
  # yf has REVERSED, the same way ba and bd did earlier in this release.
  #
  # It used to assert that listing and password STAYED vanilla-only, by pinning
  # effectiveListed= as INDENTED (inside the isVanilla branch). Access control is now decoupled
  # from world type, so that line is unindented and yf asserts the old gate is GONE. Kept
  # pointing at the indented form rather than deleted: a marker that changes direction is the
  # clearest record that the behaviour changed on purpose, and cou/cov below pin the new
  # position from the other side, so neither the gate nor the line can move back unnoticed.
  yf=$(grep -cE "^[[:space:]]+effectiveListed=.isListed" $ysw)

  # The two API write paths. Both negatives: the forced-off assignment and the gated call.
  yg=$(grep -cE "^[[:space:]]+.crossplay = 0;" $yfn)
  yh=$(grep -c "setCrossplay(.pdo, .world, (.isVanilla && !empty" $yfn)
  yi=$(grep -c "setCrossplay(.pdo, .world, !empty" $yfn)

  # The launch decision. getModdedJoinInfo is the new shared function: 1 definition plus the
  # two admin call sites that used to hold an inline literal. yk is the negative -- that
  # literal, which ignored crossplay entirely and was correct only while crossplay could not
  # be set on a modded world.
  yj=$(grep -c "getModdedJoinInfo" $ydb)
  yk=$(grep -c "getModdedJoinInfo(.pdo, .row\[.name.\], .launchString, .isRunning)" $yix)
  yl=$(grep -c "getModdedJoinInfo(.pdo, .row\[.name.\], .launchString, .isRunning)" $yfn)
  # .+ for the same reason as ya: in the PHP source the URL is a quoted literal concatenated
  # onto the variable, so the gap between them is a quote, a dot and two spaces. Spelling that
  # out one character at a time gave a marker that matched neither tree. The literal is NOT
  # quoted here on purpose -- it carries two apostrophes, and this block is inside sh -c.
  ym=$(grep -cE ".href. => .phvalheim://.+launchString, .playfab. => false" $yix)
  yn=$(grep -cE ".href. => .phvalheim://.+launchString, .playfab. => false" $yfn)
  # A modded crossplay world MUST keep a launchable href -- the client still installs the mods.
  # yo pins the guard that makes href unconditional there; if this ever becomes an isOnline
  # gate copied from the vanilla function, every modded world silently loses its link.
  yo=$(grep -c "if (!.isOnline || !worldIsPlayFab(.pdo, .world, .isOnline))" $ydb)

  # The UI gates, all negatives. Each of these three would silently override the operator.
  yp=$(grep -c "crossplay.style.display = checked" $yix)
  yq=$(grep -c "#worldCrossplay.).prop(.checked., false)" $ynw)
  yr=$(grep -c "crossplay: (isVanilla && " $ynw)

  # The disclaimers. The caveat IS the feature here as much as the flag is: a modded crossplay
  # world admits console players who cannot load mods at all. 2 each = the element plus the
  # code that shows or hides it.
  ys2=$(grep -c "crossplayModdedWarning" $yix)
  yt=$(grep -c "crossplayModdedWarning" $ynw)
  # Comments stripped before counting. This is the THIRD marker in this release to break on
  # prose rather than behaviour (see bb and bx): the access-control work added a comment in
  # syncModdedPasswordNote() that names syncCrossplayWarning(), pushing a raw count to 5. A
  # count of a function name is a count of mentions unless you say otherwise.
  yu=$(grep -v '^[[:space:]]*//' $yix | grep -c "syncCrossplayWarning")
  yv=$(grep -c "syncCrossplayWarning" $ynw)
  # The styles the warning and the code chip depend on. 2.51 shipped an icon that existed and
  # could not be drawn, so presence of the markup is not presence of the feature.
  ycss=/opt/stateless/nginx/www/css/phvalheimStyles.css
  yw=$(grep -c "^\.join-code-chip {" $ycss)
  yx=$(grep -c "^\.pv-callout {" $ycss)

  # The public card and its 5s poll. The poll is what clobbered a correct server-rendered card
  # before: isPlayFab had to stop being gated on world.vanilla, or the join code on a modded
  # world would be right on load and frozen forever after.
  yy=$(grep -c "moddedJoinCodeRow" $yau)
  yz=$(grep -c "const isPlayFab = " $yau)
  yaa=$(grep -c "joinCodeEl && isPlayFab" $yau)
  # NEGATIVE: the old vanilla-gated derivation must be gone.
  yab=$(grep -c "isCrossplay = !!(world.vanilla && world.connection" $yau)
  # api.php has to emit a connection block for a MODDED world now, or the poll has no code to
  # refresh from. Anchored on the steamUrl NULL line that only the modded branch carries.
  yac=$(grep -cE "^[[:space:]]+.steamUrl.[[:space:]]+=> NULL," $yap)
  yad=$(grep -c "2.53. => ." /opt/stateless/nginx/www/includes/whatsnew.php)

  # ---- 2.53: QuickConnect retirement and the join-path notice -----------------------
  #
  # STILL NO APOSTROPHES, comments included. Note especially that there is no awk below:
  # a single-quoted awk program inside this sh -c block closes it early, which is the trap
  # documented further up this file. grep with a bounded -A window instead.
  #
  # The retirement is DERIVED from the catalogue, never assumed. If any of these read the
  # wrong way, the failure mode is a modded world that starts normally and cannot be
  # joined -- with a [WARN] in the log as the only evidence. Both directions are pinned,
  # because a marker set that only checks that QuickConnect is gone passes on exactly the
  # unconditional removal this whole mechanism exists to prevent.
  zconf=/opt/stateless/engine/includes/phvalheim-static.conf
  zfun=/opt/stateless/engine/includes/0-functions.sh
  zeng=/opt/stateless/engine/phvalheim
  zimp=/opt/stateless/games/valheim/scripts/importWorld.sh
  zidx=/opt/stateless/nginx/www/admin/index.php
  zmig=/opt/stateless/engine/dbUpdates/dbUpdate_2.53.sh

  # NEGATIVE: QuickConnect must no longer be in requiredMods...
  za=$(grep -c "^requiredMods=.*QuickConnect" $zconf)
  # ...but must still exist as the fallback, or the not-yet-capable path installs nothing.
  zb=$(grep -c "^legacyConnectMods=.*QuickConnect" $zconf)
  # NEGATIVE: the Companion is BUNDLED from 2.53, so it must not also be catalogue-resolved.
  # Both at once puts two DLLs with the same BepInEx GUID in plugins/ and one fails to load.
  zc=$(grep -c "^requiredMods=.*PhValheimCompanion" $zconf)
  # The flag that replaced the whole catalogue probe.
  zd=$(grep -c "^companionProvidesConnect=" $zconf)
  # The DLL is actually IN THE IMAGE. The Dockerfile COPY can be right while the file is
  # absent from the build context, and installSystemPlugins exits 1 on every world if so.
  ze=0; [ -s /opt/stateless/games/valheim/custom_plugins/PhValheimCompanion/PhValheimCompanion.dll ] && ze=1

  # NEGATIVE: versionAtLeast and the catalogue probe are gone with the bundling. Left as a
  # marker rather than deleted so a revert that quietly restores them is visible here.
  zf=$(grep -c "^function versionAtLeast()" $zfun)
  zg=$(grep -c "^function companionSupportsConnect()" $zfun)
  # installSystemPlugins installs it, and fails HARD when the image does not carry it.
  zh=$(grep -c "systemPluginsSourceDir/PhValheimCompanion" $zfun)
  zi=$(grep -A30 "# Install the PhValheim Companion" $zfun | grep -c "exit 1")
  # NEGATIVE: no Newtonsoft alongside the Companion. Bundled, a second copy of that assembly
  # in plugins/ beside another mod own copy is a load-order lottery that fails at runtime.
  zj=$(ls /opt/stateless/games/valheim/custom_plugins/PhValheimCompanion/ 2>/dev/null | grep -ci newtonsoft)

  # Both writers of quick_connect_servers.cfg must consult the same verdict.
  zk=$(grep -c "companionSupportsConnect" $zeng)
  zl=$(grep -c "companionSupportsConnect" $zimp)
  # ...and the writer itself must stay unconditional: test-gamedns-quickconnect.sh lifts
  # it and calls it directly, so a self-gate would neuter that test rather than fail it.
  zm=$(grep -A4 "^function createQuickConnectConfig()" $zfun | grep -c "companionSupportsConnect")

  # The one-shot notice, all four sites.
  zn=$(grep -c "connectNoticeShown" $zmig)
  zo=$(grep -cE "connectNoticeShown.\] \?\? 1" /opt/stateless/nginx/www/includes/config_env_puller.php)
  # ?? 1 in the MARKUP too. null == 0 is true in PHP, so without it a server that has not
  # run the migration gets this dialog on every page load forever.
  zp=$(grep -c "connectNoticeShown ?? 1" $zidx)
  zq=$(grep -cE "case .dismissConnectNotice.:" /opt/stateless/nginx/www/admin/adminAPI.php)
  zr=$(grep -c "action=dismissConnectNotice" $zidx)
  # Seeded once, INDENTED inside the column-missing branch. A hoisted copy lands at less
  # nesting and fails here.
  zs=$(grep -cE "^[[:space:]][[:space:]]+sql .UPDATE settings SET connectNoticeShown = 1" $zmig)
  # The operator MUST be told that updating a world stops it.
  zt=$(grep -c "Updating a world stops it" $zidx)

  # ---- 2.53 UI repairs (found in testing on 37648-phvalheim1) ------------------------
  zauth=/opt/stateless/nginx/www/public/authenticated.php

  # The dashboard poll must delete the previous join-code chip before re-rendering the
  # launch button. launchButtonHtml() returns the anchor AND the chip as one string, but
  # the poll finds only the anchor, so without this the row gained another copy of the
  # join code every 5 seconds. Declaration plus the guarded remove.
  zu=$(grep -c "staleChip" $zidx)

  # Exactly TWO card-slack rows in the public page, one per card type. The crossplay
  # modded hint row briefly carried its own, on top of the one the card body already
  # emits, which made a crossplay card a whole blank row taller than every other card.
  #
  # Anchored on the MARKUP, not on the bare string: a plain count reads 3 because the
  # comment explaining this names card-slack too, so it would have been measuring its own
  # prose -- the same mistake aq made earlier in this release.
  zv=$(grep -cE "colspan=2 class=.card-slack.></td>" $zauth)

  echo "2.53 UI repairs: poll chip removal=$zu (want 2)  card-slack rows=$zv (want 2)"

  # ---- 2.53 per-mod install destinations (design doc section 8) ----------------------
  #
  # NO APOSTROPHES anywhere in this block. The whole verify payload runs inside sh -c with a
  # single-quoted body, so one apostrophe truncates every check after it -- silently, while
  # still printing IMAGE VERIFY OK. Patterns use . rather than $ or quotes for the same
  # reason: an unescaped $ would be expanded by the outer shell before grep ever sees it.
  zwm=/opt/stateless/engine/tools/worldMods.py
  zbk=/opt/stateless/engine/tools/worldBackup

  # Schema. DEFAULT 1 on both is what makes an existing world install byte-identically.
  cza=$(grep -c "for destCol in deploy_server deploy_client" $zmig)
  czb=$(grep -c "TINYINT NOT NULL DEFAULT 1" $zmig)

  # The union propagation, and the single derivation both --resolve and --plan share.
  czc=$(grep -c "^def walk_closure" $zwm)
  czd=$(grep -c "^def fold_by_plugin" $zwm)
  cze=$(grep -c "^def closure" $zwm)
  # The re-queue that carries a widening further down the subtree. Without it a dep reached
  # from both sides widens but its OWN dependencies do not, and a plugin goes missing from a
  # payload with nothing anywhere saying why.
  czf=$(grep -c "elif widened:" $zwm)
  # The plan emits both flags as its last two columns...
  czg=$(grep -cE "r..deploy_client.. else" $zwm)
  # ...and the install loop has a variable for each. read assigns its LAST variable every
  # remaining field, so a column without a variable gets GLUED onto mod_id.
  czh=$(grep -c "modDeployServer modDeployClient" $zfun)

  # The client staging tree.
  czi=$(grep -c "^function clientStagingRoot()" $zfun)
  czj=$(grep -c "^function prepareClientStaging()" $zfun)
  czk=$(grep -c "^function modTargetTrees()" $zfun)
  czl=$(grep -cE "prepareClientStaging .+worldName" $zfun)
  # The purge must sweep BOTH trees or a deselected client-only mod ships forever.
  czm=$(grep -A30 "^function purgeWorldModsConfigsPatchers()" $zfun | grep -c "for treeRoot in")
  # NEGATIVE: packageClient must no longer cd into the servers live game directory.
  czn=$(grep -cE "cd /opt/stateful/games/valheim/worlds/.+/game" $zfun)
  # The derived staging tree stays out of backups; it is rebuilt on every world update.
  czo=$(grep -cE "exclude=.+client" $zbk)

  echo "2.53 per-mod schema: deploy cols=$cza (want 1)  default 1=$czb (want 1)"
  echo "2.53 per-mod union: walk_closure=$czc (want 1)  fold_by_plugin=$czd (want 1)  closure=$cze (want 1)  widen re-queue=$czf (want 1)"
  echo "2.53 per-mod plan: flags emitted=$czg (want 1)  read loop vars=$czh (want 1)"
  echo "2.53 staging tree: root=$czi (want 1)  prepare=$czj (want 1)  targets=$czk (want 1)  prepare called=$czl (want 1)  purge both=$czm (want 1)"
  echo "2.53 STAGING NEGATIVE: packageClient still cds into game=$czn (want 0)"
  echo "2.53 staging: backup excludes it=$czo (want 1)"

  # ---- 2.53 mod picker switches (design doc section 8.2) -----------------------------
  zpnew=/opt/stateless/nginx/www/admin/new_world.php
  zped=/opt/stateless/nginx/www/admin/edit_world.php
  zpmc=/opt/stateless/nginx/www/includes/modcatalog.php

  # Both picker pages carry their own copy of this JS. A change applied to one and not the
  # other is invisible until an operator uses the other page, so every count here is 2.
  czp=$(grep -c "var destSet = {};" $zpnew $zped | grep -c ":1")
  czq=$(grep -c "title: .Installs on." $zpnew $zped | grep -c ":1")
  czr=$(grep -c "on(.change., ..dest-toggle" $zpnew $zped | grep -c ":1")
  # The column holds controls, so it must not be sortable.
  czs=$(grep -c "orderable: false, targets: .0, 5." $zpnew $zped | grep -c ":1")
  # getSelectedMods must SEND the flags, or the switches are decoration.
  czt=$(grep -c "server: ..d.0., client: ..d.1." $zpnew $zped | grep -c ":1")

  # The PHP round trip.
  czu=$(grep -c "IFNULL(wm.deploy_server,1) AS deploy_server" $zpmc)
  czv=$(grep -c "deploy_server, deploy_client)" $zpmc)
  # NEGATIVE-ish: an absent flag must default to TRUE. aiactions.php posts bare mod ids
  # through this same function, so a falsy default would install those mods nowhere.
  czw=$(grep -c "array_key_exists(.server., .m) ? (bool).m..server.. : true" $zpmc)

  # ---------------------------------------------------------------------------
  # 2.53 Companion connect support, checked in the SHIPPED DLL.
  #
  # These read the binary that is actually in the image, not the source tree that built it.
  # That distinction is the whole reason the block exists: the Companion's shipping artifact is
  # a committed binary, the project builds Debug by default, and the file it ships lives under
  # bin/Release -- so it is entirely possible to write the connect code, build it, see "Build
  # succeeded", and copy nothing. That very mistake was made while writing this release: the
  # Debug output went to bin/Debug/net472 while the bundled copy stayed at the previous
  # Release build.
  #
  # grep -a rather than strings(1), because binutils is not installed in the image and a
  # marker that silently cannot run is worse than no marker.
  #
  # The NUL strip is not cosmetic. .NET keeps TYPE and MEMBER names as UTF-8 but string
  # LITERALS as UTF-16, so a plain ASCII grep finds LaunchPayload and silently fails to find
  # "--phvalheim-launch" or "ProceedJoinRequest" -- which are the literals that matter most,
  # because they are the transport name and the reflected method. The first draft of this
  # block wanted 1 and got 0 for all three, and a dry run against the real DLL is what caught
  # it. Dropping NUL bytes turns UTF-16 ASCII back into plain text and reads both heaps.
  zdll=/opt/stateless/games/valheim/custom_plugins/PhValheimCompanion/PhValheimCompanion.dll
  zdlltxt=/tmp/phv-companion-strings.txt
  tr -d '\000' < $zdll > $zdlltxt 2>/dev/null

  # Normalised to 0/1 with -q, not counted with -c. grep -c counts matching LINES, and in a
  # binary the "lines" are wherever a 0x0a happens to fall -- ConnectFlow came back 2 purely
  # because its two mentions straddled one. A presence check must answer presence.
  cna=0; grep -aq "LaunchPayload" $zdlltxt && cna=1
  cnb=0; grep -aq "ConnectFlow" $zdlltxt && cnb=1
  cnc=0; grep -aq "ConnectDialog" $zdlltxt && cnc=1
  # The transport name, which must match phvalheim-client's CompanionArgName exactly. A
  # mismatch shows no dialog and logs nothing -- the Companion simply concludes it was not
  # launched by PhValheim.
  cnd=0; grep -aq -- "--phvalheim-launch" $zdlltxt && cnd=1
  # ProceedJoinRequest is reached by REFLECTION, so its name survives only as a string literal
  # in the DLL. If this is 0 the connect path was rewritten to call something directly, which
  # is the publicizer trap waiting to happen.
  cne=0; grep -aq "ProceedJoinRequest" $zdlltxt && cne=1
  # Likewise the private UnifiedPopup label field.
  cnf=0; grep -aq "yesText" $zdlltxt && cnf=1

  # The justification fix. The dialog body arrives JUSTIFIED, which is what made a bulleted
  # mod list look wrong, and the fix reflects UnifiedPopup.bodyText to set TopLeft alignment.
  # The alignment VALUE cannot be checked here -- TextAlignmentOptions is an enum and compiles
  # to an integer, leaving no string behind -- but the reflected field name does survive, so
  # this catches the fix being dropped from the shipped DLL.
  cnk=0; grep -aq "bodyText" $zdlltxt && cnk=1

  # The join code reader must match ANY join-code mention and take the last, not just the
  # registration line.
  #
  # Valheim re-reports a sticky code from the PlayFab lobby entity when it registers, then
  # mints a replacement a second later. Reading only "registered with join code" handed
  # players 537586 for test123 while the game itself was using 284283 -- wrong in the public
  # UI, the admin dashboard AND the Launch link, because all seven call sites come through
  # getWorldJoinCode(). The NEGATIVE is the one that matters: the narrow pattern must be gone,
  # not merely joined by a broader one, or whichever matches first wins again.
  # QuickConnect retirement, in the migration.
  #
  # The NEGATIVE is the one that matters and it is not obvious: there are FOUR different
  # QuickConnect packages in the live catalogue, from four different owners (bdew,
  # HouseAtreides, ValheimEnjoyers, GillianAprils). PhValheim only ever installed bdew's, per
  # legacyConnectMods. A DELETE matching on name alone would strip three unrelated operators'
  # deliberate picks, which is exactly why identity in this project is (source, owner, name)
  # and never the name on its own. qcb fails if the owner guard is ever dropped.
  qca=$(grep -c "quickConnectRetired" $zmig)
  qcb=$(grep -c "m.owner = 'bdew' AND m.name = 'QuickConnect'" $zmig)
  # One-shot, not a policy. Without the flag being SET the delete would run on every boot and
  # silently re-strip a QuickConnect the operator had deliberately added back.
  qcc=$(grep -c "UPDATE settings SET quickConnectRetired = 1" $zmig)

  zgets=/opt/stateless/nginx/www/includes/db_gets.php
  cnl=$(grep -c "preg_match_all('/join code (.d{4,10})/'" $zgets)
  cnm=$(grep -c "registered with join code (.d{4,10})" $zgets)

  # Size floor. The last pre-connect Companion was 10,752 bytes; the build with the DNS fix is
  # 32,256. The shipping artifact is a committed binary and the project builds Debug by default
  # while shipping from bin/Release, so "built it, copied nothing" is a real and easy mistake --
  # it was made twice while writing this release.
  #
  # Raised from 20000 to 30000 deliberately. The second time, the stale bundled copy was the
  # 29,184-byte build from one commit earlier: it cleared a 20 KB floor, carried every string
  # literal every other marker greps for, and was the SAME SIZE as the new build to the byte in
  # an `ls`. Only the hashes differed. A floor between the two builds is the cheapest thing
  # that can see that, which is why this number is specific and not round.
  cng=0; [ "$(wc -c < $zdll 2>/dev/null || echo 0)" -gt 30000 ] && cng=1

  # The IP:PORT (non-crossplay) join fix.
  #
  # FejdStartup.JoinServer()'s dedicated branch calls GetServerIPAsync and then transitions to
  # the main scene WITHOUT waiting for the callback, so ZNet.SetServerHost has not run yet. It
  # only works when the resolve answers synchronously, which happens on a warm DNS cache -- and
  # arriving from our dialog instead of the server list, the cache is cold. The player was
  # bounced back to the main menu with no error. The fix pre-resolves gameDNS through Valheim's
  # own resolver before handing the join over.
  #
  # cnn is the resolve call itself; a direct call leaves the member name in metadata.
  cnn=0; grep -aq "GetServerIPAsync" $zdlltxt && cnn=1
  # cno is the coroutine. This one matters more than it looks: the fix is ONLY a fix because it
  # can wait, and waiting needs the coroutine form. Collapse it back to a plain method and every
  # other marker here still passes while the join is broken again exactly as before. The
  # compiler emits the state machine as <JoinByAddressRoutine>d__n, so the name survives.
  cno=0; grep -aq "JoinByAddressRoutine" $zdlltxt && cno=1
  # cnp is the watchdog that clears ConnectFlow.Connecting when Valheim drops the player back on
  # the main menu. Without it a failed join hides the dialog AND the reopen button permanently --
  # which is the half of Brian's report that is independent of the join itself.
  cnp=0; grep -aq "NoticeMainMenu" $zdlltxt && cnp=1

  # The dialog layout.
  #
  # The YesNoPopup panel is a fixed size and its body does NOT clip, so a body a few lines too
  # tall draws the world name over the "PhValheim" header and hides the closing sentence behind
  # the buttons. That shipped once. Two things keep it fixed and both have to be in the DLL:
  #
  # cnq -- the <align=left> tag. Horizontal alignment is carried IN THE TEXT rather than only on
  # the TMP component, because the component-level set demonstrably did not take and the result
  # was a centred, ragged table. A tag cannot be lost to a reflection failure.
  #
  # All four use grep -F. These patterns contain <, =, % and / and are matched literally; a
  # regex read of "<pos=26%>" is not what anyone writing this line intends.
  cnq=0; grep -aqF -- "<align=left>" $zdlltxt && cnq=1
  # cnr -- the <pos=> column stop that makes it a table rather than a paragraph. Padding with
  # spaces cannot do this: the body font is proportional, so padded labels drift per row.
  cnr=0; grep -aqF -- "<pos=26%>" $zdlltxt && cnr=1
  # cns -- BuildBodyText, which is the seam dev_tools/test-dialog-layout.sh renders through. If
  # it is renamed or inlined the layout silently becomes untestable again, which is how the
  # overflowing version got out in the first place.
  cns=0; grep -aqF -- "BuildBodyText" $zdlltxt && cns=1

  # The scrolling mod list and the failure notice.
  #
  # cnu -- ModListView, the scrolling list. It builds its OWN object tree under the popup
  # rather than re-parenting Valheim's bodyText, because the popup is a shared singleton and a
  # missed teardown would leave every later confirm dialog in the session broken.
  cnu=0; grep -aqF -- "PhValheimModList" $zdlltxt && cnu=1
  # cnv -- the failure notice. Every failure path used to end at the BepInEx log and nowhere
  # else, so a failed join just made the dialog silently reappear. ConnectFlow.LastFailure is
  # what the player now reads.
  cnv=0; grep -aqF -- "LastFailure" $zdlltxt && cnv=1
  # cnw -- the panel scale reaches popupUIParent, which is PRIVATE in the real assembly and so
  # survives only as a reflected string literal. If this is 0 the dialog is back to its old
  # size with no other symptom.
  cnw=0; grep -aqF -- "popupUIParent" $zdlltxt && cnw=1

  # cnx -- the tighter budget that applies while the scrolling list is up. The list owns the
  # bottom of the body rect, so the summary rows only have the top; checking those layouts
  # against the full-panel budget passed a body that then rendered straight THROUGH the list,
  # which is what the second screenshot showed. Its absence means the two cases have been
  # conflated again.
  cnx=0; grep -aqF -- "BodyLineBudgetWithList" $zdlltxt && cnx=1
  # cny -- the anchored layout. The list is placed with normalized anchors on the parent rect,
  # NOT by measuring the body text: that measurement ran before the popup was laid out, came
  # back short, and put the list on top of the closing sentence and past the bottom of the
  # panel. StretchBottom is the anchored form.
  cny=0; grep -aqF -- "StretchBottom" $zdlltxt && cny=1

  # The admin Launch button for a vanilla crossplay world, and the join-code modal it opens.
  # BOTH render paths must have it -- the server renders the row and the 5s poll replaces it,
  # so a button in only one appears and then vanishes.
  zjd=$(grep -c "showJoinCodeModal(this)" /opt/stateless/nginx/www/admin/index.php)
  zje=$(grep -c "id=.joinCodeModalOverlay." /opt/stateless/nginx/www/admin/index.php)

  echo "2.53 admin vanilla launch: modal openers=$zjd (want 2)  modal present=$zje (want 1)"

  # cnz -- the body style is applied AFTER UnifiedPopup.Push. Styling the popup while its
  # GameObject is still inactive is why the body stayed vertically centred for two rounds of
  # testing: the alignment write did not survive the object being enabled, the text hung down
  # into the mod list's strip, and the list looked misplaced when it was the text that was.
  # ApplyBodyStyle is the post-push method.
  cnz=0; grep -aqF -- "ApplyBodyStyle" $zdlltxt && cnz=1
  # cob -- the explicit vertical axis plus the reserved bottom margin. Vertical alignment is
  # the axis that actually went wrong and no rich-text tag can reach it; the margin is the
  # geometric backstop so the text cannot occupy the list's strip even if alignment is ignored.
  cob=0; grep -aqF -- "set_verticalAlignment" $zdlltxt && cob=1
  coc=0; grep -aqF -- "set_margin" $zdlltxt && coc=1

  echo "2.53 dialog vertical fix: styled after push=$cnz (want 1)  vAlign=$cob (want 1)  reserved margin=$coc (want 1)"

  # cod/coe/cof -- the content-size decoupling. PanelScale is a localScale on the popup root, so
  # it multiplies the panel art, the header, the body, the list AND both buttons together.
  # Raising it twice (1.28 -> 1.75) grew everything in step and bought zero extra content room,
  # which is why the dialog read as cramped at every size. Content sizes are now screen-space
  # constants divided by PanelScale.
  #
  # The constants themselves are compile-time folded and leave NO symbol in the DLL, so there is
  # nothing to grep for them -- these markers pin the METHOD and the two reflected members that
  # only this work introduced. ApplyChromeStyle is the post-push header/button styler;
  # headerText and buttonRightText are reached by reflection from nowhere else in the mod.
  coe=0; grep -aqF -- "ApplyChromeStyle" $zdlltxt && coe=1
  cof=0; grep -aqF -- "buttonRightText" $zdlltxt && cof=1
  cog=0; grep -aqF -- "headerText" $zdlltxt && cog=1

  # cod -- the admin row's wrap rule. The join-code chip is a LABEL, not an .action-btn, so
  # reflowActionGroups() never counts its width; it came straight out of the button row's budget
  # and pushed Start, Stop and Logs behind the "..." menu on every crossplay world at every
  # width measured. Matched on the full selector, not the bare class: `.join-code-chip` alone
  # already appears several times in this stylesheet and would pass with the fix removed.
  cod=$(grep -c "action-group:has(.join-code-chip)" /opt/stateless/nginx/www/css/phvalheimStyles.css)

  echo "2.53 dialog content size: chrome styler=$coe (want 1)  button text reflected=$cof (want 1)  header reflected=$cog (want 1)"
  echo "2.53 admin row overflow: chip wrap rule=$cod (want 1)"

  # coh -- the mod list's measured-geometry log line. Every size in ModListView is a const and
  # is folded away at compile time, so there is no symbol for the list height, the overshoot or
  # the scrollbar mode to grep for. This log string is emitted by the rebuilt Build() and by
  # nothing else, so it stands in for the whole of that rework -- and it is the line that
  # answers, from a world's log, whether the list was actually taller than its viewport.
  coh=0; grep -aqF -- "scrollable=" $zdlltxt && coh=1

  # The dashboard column rebalance. Configure held 40% (default) and 41% (the 1024-1366
  # breakpoint) while showing two links, and both the World and Actions columns wrapped.
  # TWO markers because there are TWO rule sets: fixing only the default one leaves every
  # window between 1024 and 1366 still wrapping, and a single marker would have passed anyway.
  # That is the same mistake as editing one of four render sites.
  coi=$(grep -c "width: 29%" /opt/stateless/nginx/www/css/phvalheimStyles.css)
  coj=$(grep -c "width: 34%" /opt/stateless/nginx/www/css/phvalheimStyles.css)
  # NEGATIVES: the two old Configure widths. These are the shape of the bug, and they are the
  # only two occurrences of either literal in the stylesheet -- verified before they were used.
  cok=$(grep -c "width: 40%" /opt/stateless/nginx/www/css/phvalheimStyles.css)
  com=$(grep -c "width: 41%" /opt/stateless/nginx/www/css/phvalheimStyles.css)
  # The world name must not break mid-word. Matched on the scoped selector, not on `nowrap`,
  # which appears all over this stylesheet and would pass with the rule deleted.
  con=$(grep -c "nth-child(2) .world-name" /opt/stateless/nginx/www/css/phvalheimStyles.css)

  echo "2.53 ADMIN COLUMN NEGATIVES: old Configure 40%=$cok (want 0)  old Configure 41%=$com (want 0)"
  echo "2.53 admin columns: actions default=$coi (want 1)  actions narrow=$coj (want 1)  name nowrap=$con (want 1)"
  echo "2.53 mod list geometry: measured log=$coh (want 1)"

  # cop/coq -- the scroll fix and the panel geometry nudges.
  #
  # cop: the mod list's content height is now COMPUTED from the known line count instead of read
  # from TMP_Text.preferredHeight at build time. The old read ran before layout had given the
  # rect a width, came back about one viewport tall, and ScrollRect clamps travel to
  # (content - viewport) -- so the bar appeared, its handle filled the track, and 34 mods would
  # not scroll. "lineH=" is emitted only by the computed path; "tmpPreferred=" logs what the old
  # reading would have said, side by side, so the next report can tell the two apart.
  cop=0; grep -aqF -- "lineH=" $zdlltxt && cop=1
  # coq: the header lift, body-rect lift and button pull all log through this one line.
  coq=0; grep -aqF -- "body rect " $zdlltxt && coq=1

  echo "2.53 dialog scroll + spacing: computed content height=$cop (want 1)  rect geometry log=$coq (want 1)"

  # cor -- a deliberate disconnect must not be reported as a failed connection. Nothing cleared
  # Connecting on success, so it stayed true for the whole session and the menu-return watchdog
  # fired on the player's own logout. The constants are folded away, so this pins the log line
  # the success branch emits -- which exists only on that branch.
  cor=0; grep -aqF -- "treating it as a disconnect" $zdlltxt && cor=1

  # cos -- the OBSERVER, which is the half that was missing. The first fix put the "did we get
  # in?" check in ConnectDialog.Update, which lives on FejdStartup's GameObject and is destroyed
  # on the way into a world -- so it could not run during the only window that mattered, and the
  # bug shipped unchanged with a passing test. JoinSentry is a DontDestroyOnLoad object that
  # survives the scene load; this pins its GameObject name, which exists only if it was built.
  cos=0; grep -aqF -- "PhValheimJoinSentry" $zdlltxt && cos=1

  echo "2.53 disconnect vs failure: success branch=$cor (want 1)  join observer=$cos (want 1)"

  # --- 2.53 access control decoupled from world type ---------------------------------
  #
  # Every marker here is anchored on CODE, not on the comments that explain it. The first
  # drafts of cot and coz matched prose -- "-public 0" and "hammertime" both still appear in
  # this release, in the comments saying why they are gone -- so they would have passed on a
  # file where the behaviour had been reverted and only the explanation survived.

  # cot -- NEGATIVE: the hardcoded `-public 0` for modded worlds must be GONE from the argv
  # build. Anchored on `set --` so the explanatory comments that still say "-public 0" cannot
  # satisfy it.
  cot=$(grep -cE '^[[:space:]]*set -- "\$@" -public 0' /opt/stateless/games/valheim/scripts/startWorld.sh)

  # cpa -- and -public is still passed EXPLICITLY, for every world. This is the dangerous half:
  # FejdStartup.ParseServerArguments initialises its public flag to TRUE and only overwrites it
  # when the argument carries a value, so losing this line would list every world AND switch on
  # Valheim's password validation for all of them. A deletion here is silent at build time.
  cpa=$(grep -c '^set -- "\$@" -public "\$isListed"$' /opt/stateless/games/valheim/scripts/startWorld.sh)

  # cou -- listing and the password hash are recorded for ANY world, which is expressed by the
  # INDENTATION: unindented means outside the isVanilla branch they used to live in. Recorded
  # empty for a modded world, the restart-pending check compares a real password against "" and
  # answers "nothing changed" forever.
  cou=$(grep -c '^effectiveListed=\$isListed$' /opt/stateless/games/valheim/scripts/startWorld.sh)
  cov=$(grep -c '^if \[ -n "\$worldPasswordDb" \]; then$' /opt/stateless/games/valheim/scripts/startWorld.sh)

  # cow -- NEGATIVE: serverblankpassword is out of the requiredMods ASSIGNMENT. Matching the
  # bare word would hit the comment above it explaining the removal.
  cow=$(grep -c '^requiredMods=.*serverblankpassword' $zconf)
  cox=$(grep -c '^requiredMods=""$' $zconf)

  # coy -- the two password rules Valheim enforces that this project did not. Both are live
  # bugs before 2.53: a password inside the SEED makes a listed world Application.Quit() into a
  # supervisor restart loop, and a '?' shifts every later field of the positional launch payload.
  coy=$(grep -cF 'Password cannot be part of the world seed' /opt/stateless/nginx/www/admin/adminAPI.php)
  coz=$(grep -cF 'Password cannot contain a question mark' /opt/stateless/nginx/www/admin/adminAPI.php)

  # cpb -- the one-time retirement, both halves: the flag that makes it one-time, and the
  # generated password. Without the second, a modded world can never be listed.
  cpb=$(grep -cF 'UPDATE settings SET blankPasswordRetired = 1' $zmig)
  # cpc has MOVED FILE, which is the record that the generation moved with it. It asserted the
  # migration writes a password -- correct until that turned out to lock players out of worlds
  # still on QuickConnect, and now the thing cpm forbids. It now asserts the generation exists
  # in its new home, so the pair cannot both be satisfied by deleting the feature: cpm says it
  # is not in the migration, cpc says it IS in the update path.
  cpc=$(grep -cF 'UPDATE worlds SET password' $zfun)

  # cpd/cpe -- NEGATIVE: the `$vanilla ? ... : "hammertime"` ternary is gone from BOTH launch
  # string builders. Two sites, because index.php renders its own copy for the dashboard and
  # only db_gets.php was obvious; leaving one behind would pre-fill the WRONG password through
  # the Companion on exactly the worlds this release gives a password to.
  cpd=$(grep -cE '\$password *= *\$vanilla *\?' /opt/stateless/nginx/www/includes/db_gets.php)
  cpe=$(grep -cE '\$password *= *\$vanilla *\?' $zidx)

  # cpf -- listed+crossplay is refused at the form on BOTH write paths (create and save).
  # Valheim does not error on the combination; it lists a PlayFab server nowhere and says
  # nothing, so the UI is the only thing that can report it.
  cpf=$(grep -cF 'A crossplay world cannot be listed in the server browser' /opt/stateless/nginx/www/admin/adminAPI.php)

  echo "2.53 access decoupled: -public 0 gone=$cot (want 0)  explicit -public=$cpa (want 1)  listed recorded=$cou (want 1)  pw hash blocks=$cov (want 2)"
  echo "2.53 access decoupled: blankpw in requiredMods=$cow (want 0)  requiredMods empty=$cox (want 1)  seed rule=$coy (want 1)  qmark rule=$coz (want 1)"
  # cpg -- NEGATIVE, and this is the one that actually shipped broken. savedWorldOptions() is a
  # MIRROR of startWorld.sh's gating, used by the restart-pending badge. startWorld.sh lost its
  # vanilla gates on listed/password; the mirror kept them, so every modded world with a
  # password reported "password" pending on every poll and NO restart could clear it --
  # restarting only re-confirms the running side. Three of four live worlds on :rc.
  #
  # It is the SECOND time this file lagged startWorld.sh in 2.53 (crossplay was the first), so
  # the gate is pinned out of existence rather than pinned to a value. test-restart-pending-
  # mirror.sh is the end-to-end half: it runs the real startWorld.sh and the real comparison.
  cpg=$(grep -c '\$vanilla === 1' /opt/stateless/nginx/www/includes/db_gets.php)

  echo "2.53 access decoupled: retire flag=$cpb (want 1)  pw generated in UPDATE path=$cpc (want 1, moved out of the migration)  hammertime gets=$cpd (want 0)  hammertime idx=$cpe (want 0)  listed+crossplay refused=$cpf (want 2)"
  echo "2.53 access decoupled: restart-pending mirror vanilla gates=$cpg (want 0, was 2 and shipped broken)"

  # cph/cpi/cpj -- the password reveal row must be styled on a MODDED card too.
  #
  # The modded card reuses the vanilla card's classes, but five of the six rules were scoped
  # `.catbox-vanilla .vanilla-password-*` and a modded card is a plain `.catbox`. The row
  # inherited nothing: 14px lowercase "showcopy" with no spacing, against the vanilla card's
  # 11.52px uppercase "SHOW COPY". Shipped in :rc and spotted by Brian on sight -- no marker or
  # test could see it, because the markup was identical and correct and only the CSS selector
  # was wrong. test-password-row-styling.js measures computed style on the real rendered page.
  zcss=/opt/stateless/nginx/www/css/phvalheimStyles.css
  cph=$(grep -c 'catbox-vanilla .vanilla-password' $zcss)
  cpi=$(grep -c '^\.vanilla-password-action {$' $zcss)
  cpj=$(grep -c '^\.card_dimmed \.vanilla-password-action {$' $zcss)

  echo "2.53 password row styling: still vanilla-scoped=$cph (want 0, was 5)  unscoped action rule=$cpi (want 1)  unscoped dimmed rule=$cpj (want 1)"

  # cpk/cpl -- the one-shot connect notice must not describe a CATALOGUE lookup.
  #
  # It told operators QuickConnect "will no longer be installed once a Companion that can do the
  # job is available in your mod catalogue" -- true of the design, false of what shipped, since
  # the Companion is inside this image. It asked them to wait for something already done.
  #
  # Both greps strip HTML comments and collapse whitespace, and both are necessary:
  #   - the comment added next to the fix repeats the wrong phrases verbatim, so an unstripped
  #     grep reports the broken text present on a FIXED file (the third time prose beat a
  #     marker in this release -- see bb, bx, yu above);
  #   - the original wraps "your mod / catalogue" across a line, so an uncollapsed grep finds
  #     nothing and reports a BROKEN file clean. Each failure hides the opposite answer.
  cpk=$(awk '/<!--/{c=1} !c{print} /-->/{c=0}' $zidx | tr '\n' ' ' | tr -s ' ' | grep -c 'ships inside PhValheim')
  cpl=$(awk '/<!--/{c=1} !c{print} /-->/{c=0}' $zidx | tr '\n' ' ' | tr -s ' ' | grep -c 'available in your mod catalogue')

  echo "2.53 connect notice wording: ships-inside stated=$cpk (want 1)  old catalogue claim=$cpl (want 0, was 1)"

  # cpm/cpn/cpo -- WHEN a modded world gets its generated password. This shipped wrong.
  #
  # Generated in the migration, i.e. at UPGRADE, it password protected every modded world at its
  # next RESTART -- while the world was still running QuickConnect and its players were still on
  # a client that cannot forward a password. Everyone was locked out of a world the operator had
  # not touched, and it broke the promise the rest of 2.53 makes: nothing changes until you
  # update a world. It belongs in the UPDATE path, with the other things that update changes.
  #
  # cpm is the negative and is anchored on the `sql "UPDATE ...` CALL, not the bare phrase: the
  # migration still explains in a comment why it does not do this.
  cpm=$(grep -cE '^[[:space:]]*sql "UPDATE worlds SET password' $zmig)
  cpn=$(grep -c '^function ensureModdedWorldPassword()' $zfun)
  cpo=$(grep -c 'ensureModdedWorldPassword "\$worldName"' $zeng)

  echo "2.53 password timing: migration writes one=$cpm (want 0, was 1 and shipped)  update-path fn=$cpn (want 1)  engine calls it=$cpo (want 1)"

  # cpp..cpt -- "your players must update the client", raised by the FIRST world update.
  #
  # A TRI-state, because "not triggered" and "dismissed" are different answers and a boolean
  # holds one of them. cpq and cpt are the two halves that keep it honest:
  #   cpq -- the promotion is scoped `WHERE state = 0`, so a DISMISSED notice cannot be revived
  #          by the next world update. Unscoped, the operator meets the dialog once per world.
  #   cpt -- dismiss must not write 0. Zero means "not triggered", so dismissing that way re-arms
  #          it. Both failures look like a correct boolean until a second world is updated.
  cpp=$(grep -c 'ADD COLUMN clientUpdateNoticeState TINYINT NOT NULL DEFAULT 0' $zmig)
  cpq=$(grep -c 'SET clientUpdateNoticeState = 1 WHERE clientUpdateNoticeState = 0' $zfun)
  cpr=$(grep -c 'noticeClientUpdateRequired "\$worldName"' $zeng)
  cps=$(grep -c 'UPDATE settings SET clientUpdateNoticeState = 2' /opt/stateless/nginx/www/admin/adminAPI.php)
  cpt=$(grep -c 'UPDATE settings SET clientUpdateNoticeState = 0' /opt/stateless/nginx/www/admin/adminAPI.php)

  echo "2.53 client-update notice: column=$cpp (want 1)  promote scoped to 0=$cpq (want 1)  engine fires it=$cpr (want 1)  dismiss writes 2=$cps (want 1)  dismiss writes 0=$cpt (want 0)"

  # cpu..cpy -- the client manifest, and the Companion notice that reads it.
  #
  # The server half writes a manifest into the payload so the Companion can name the world
  # when no --phvalheim-launch argument arrived. The client half is in a dll, so these check
  # the dll's own string table rather than source that may not have been rebuilt -- the
  # bundled dll going stale is the realistic failure, not the bash going missing.
  #
  # cpw is the ORDER check, and it is the one with teeth. Written after the zip, the manifest
  # ships in the NEXT payload and describes the world as it was one update ago -- a bug that
  # looks exactly like a server that forgot to update.
  #
  # cpy is a NEGATIVE on the dll: the notice must never claim the player's app is out of
  # date. That state has two causes -- an old app, or a Steam launch of an install PhValheim
  # set up -- and nothing in the game process can tell them apart.
  cpu=$(grep -c '^clientMinVersion="' $zconf)
  cpv=$(grep -c '^function writeClientManifest()' $zfun)

  mancall=$(awk -v s="$(grep -n '^function packageClient()' $zfun | head -1 | cut -d: -f1)" \
                'NR>s && /writeClientManifest "\$worldName"/{print NR; exit}' $zfun)
  manzip=$(awk -v s="$(grep -n '^function packageClient()' $zfun | head -1 | cut -d: -f1)" \
               'NR>s && /^[[:space:]]*zip "\$zipPath" -r/{print NR; exit}' $zfun)
  cpw=0; [ -n "$mancall" ] && [ -n "$manzip" ] && [ "$mancall" -lt "$manzip" ] && cpw=1

  # NEGATIVE: no password may be written into the manifest. argv carries the password for the
  # life of one process; this file lives on every player's disk until the install is replaced.
  cpx=$(awk '/^function writeClientManifest\(\)/,/^}/' $zfun | grep -ciE 'echo "password=|SELECT password')

  cpy=0; grep -aq "is out of date" $zdlltxt && cpy=1
  cpz=0; grep -aqF -- "phvalheim-world.cfg" $zdlltxt && cpz=1
  cqa=0; grep -aqF -- "Nothing handed Valheim a world to join." $zdlltxt && cqa=1
  cqb=0; grep -aqF -- "ShowLaunchHelp" $zdlltxt && cqb=1

  # NEGATIVE: no client-download button. settings.phvalheimClientURL is ONE url and its
  # default has been a Windows .exe since dbUpdate_2.31, so on Linux or macOS that button
  # handed the player the wrong installer. Dropped on Brian's call; this stops it coming back
  # by accident. Checks the dll's string table, so it fails whether the button is restored in
  # the Companion or the url is put back into the manifest.
  cqc=0; grep -aqF -- "Get the app" $zdlltxt && cqc=1
  cqd=$(awk '/^function writeClientManifest\(\)/,/^}/' $zfun | grep -c 'clientUrl=')

  echo "2.53 client manifest: minVersion const=$cpu (want 1)  writer=$cpv (want 1)  written before zip=$cpw (want 1)  password in manifest=$cpx (want 0)  dll reads it=$cpz (want 1)  dll has the notice=$cqa (want 1)  dll has the off switch=$cqb (want 1)  dll says 'out of date'=$cpy (want 0)  download button=$cqc (want 0)  clientUrl in manifest=$cqd (want 0)"

  # cqe..cqh -- the dialog's chrome, and the reopen button that reaches it.
  #
  # All four read the DLL IN THE IMAGE, which is the only copy that matters: the Companion is
  # built in a separate repo and COPIED into container/games/valheim/custom_plugins, so a
  # rebuilt dll that was never copied across leaves the server image shipping the old one. That
  # is not hypothetical -- test-client-manifest.sh failed on exactly that this release.
  #
  # cqe is a NEGATIVE now, and it is the most important marker in this block.
  #
  # PhValheimPanelBackdrop was PanelSkin's inserted background quad. PanelSkin found the panel's
  # art by taking the largest Image under the popup, which is a FULL-SCREEN overlay -- so it
  # shipped a full-screen box with a cyan border and no text, and Brian's verdict was "much
  # worse". It is reverted. This marker fails the build if it comes back, because the next
  # attempt must target the panel's background by NAME against the tree PanelTree now logs,
  # not by guessing at sizes again.
  cqe=0; grep -aqF -- "PhValheimPanelBackdrop" $zdlltxt && cqe=1

  # cqf is the one with teeth. The reopen button drew and never received a click for four
  # rounds, and then the native replacement silently failed to be created at all because its
  # one route to a template was a single reflected field read. This is the fallback route's own
  # message, so it can only be present if FindTemplate is still in the dll.
  cqf=0; grep -aqF -- "no usable template anywhere" $zdlltxt && cqf=1

  # cqg: the palette is PhValheim's. Anchored on --text-primary, the body prose colour, which
  # is what survived the reskin revert -- the panel's own --bg-primary is deliberately gone
  # (see cqe). Theme.cs is the only place a hex lives, so this fails if that file is bypassed.
  cqg=0; grep -aqF -- "#f1f5f9" $zdlltxt && cqg=1

  # cqh is a NEGATIVE, and it is the retired-colour control that already caught two sites this
  # release. #E8D9A0 was the Valheim-parchment cream the dialog used before the theming pass;
  # present again means something reintroduced a second palette, and the one that renders is
  # whichever the code happens to read.
  cqh=0; grep -aqF -- "#E8D9A0" $zdlltxt && cqh=1

  # cqi: the panel tree must be REPORTED. The reskin is reverted, so the only thing standing
  # between the next attempt and another full-screen box is knowing the real Image tree.
  cqi=0; grep -aqF -- "PhValheim panel tree:" $zdlltxt && cqi=1

  # cqj: the reopen button's predicate must be the PURE, TESTABLE one.
  #
  # The button failed on a real client four times. The cause was one term in an inline
  # expression -- `&& !_shown` -- which was always false after a close, so MenuButton.Ensure was
  # never called once. The IL reachability test could not see it: the call was there, the branch
  # was not. WantsReopenButton exists so a truth table can drive the decision directly, and its
  # presence in the image is what guarantees the decision is still testable.
  cqj=0; grep -aqF -- "WantsReopenButton" $zdlltxt && cqj=1

  # cqk: the cloned menu button must DISABLE THE HANDLER IT INHERITED.
  #
  # The clone is a copy of a live Valheim menu button, and Brian reported that clicking it took
  # him to character selection -- it was running Valheim's handler as well as ours. The old code
  # called onClick.RemoveAllListeners(), and an IL check asserting that call PASSED the whole
  # time: RemoveAllListeners clears only RUNTIME listeners, while Valheim's are PERSISTENT ones
  # serialized in the prefab. Present was not effective.
  #
  # SetPersistentListenerState is the only runtime way to switch those off, so its presence in
  # the image is the thing worth gating on.
  cqk=0; grep -aqF -- "SetPersistentListenerState" $zdlltxt && cqk=1

  # cql: the menu entry's two-colour label. Magenta is Brian's explicit choice and is NOT in the
  # stylesheet, so the palette drift check cannot cover it -- this is what does.
  cql=0; grep -aqF -- "#ff00ff" $zdlltxt && cql=1

  echo "2.53 dialog chrome: inserted backdrop=$cqe (want 0)  button fallback route=$cqf (want 1)  text-primary=$cqg (want 1)  retired cream=$cqh (want 0)  panel tree logged=$cqi (want 1)  testable button predicate=$cqj (want 1)  inherited handler off=$cqk (want 1)  magenta label=$cql (want 1)"

  # NEGATIVE: MaxModsListed must be GONE. It was a cap on the NUMBER of mods listed, and a
  # count cap cannot hold a height budget -- twelve short names and twelve long ones are the
  # same count and a very different number of rendered lines. That is precisely how the body
  # overflowed. The replacement caps by character width instead (ModLineBudgetChars).
  #
  # Chosen because it is unmatchable by anything legitimate: no other identifier contains it,
  # unlike a bullet or a stray "   . " pattern, which would match half the assembly and make
  # the marker fire on a correct build.
  cnt=0; grep -aqF -- "MaxModsListed" $zdlltxt && cnt=1

  # NEGATIVE: SetServerToJoin must NOT appear. It is the wrong door -- it writes m_joinServer,
  # which OnCharacterStart then overwrites from m_queuedJoinServer, so the player would pick a
  # character and land in the world list with no error. Reading the IL is what found that;
  # this marker is what stops it being "fixed" back. Checked against the NUL-stripped text so
  # it is a real negative and not a grep that could never have matched anyway.
  cnh=0; grep -aq "SetServerToJoin" $zdlltxt && cnh=1

  # The flag, re-anchored after the real-client smoke test passed on 2026-10-01.
  #
  # These two markers previously wanted the OPPOSITE: marker present (1) and the flag at 0.
  # That was correct while the connect path was written but unproven. Flipping the flag without
  # moving them would have failed the build -- which is the point of them -- so they are
  # updated to the new reality rather than deleted. The pending marker must now be GONE and the
  # flag must be 1; either one drifting back means QuickConnect is being installed again, or
  # the declaration and the flag have fallen out of step.
  cni=$(grep -c "COMPANION CONNECT PENDING SMOKE TEST" $zconf)
  cnj=$(grep -c '^companionProvidesConnect="1"' $zconf)

  # The mod picker blocker. `var neededDeps = {}` is declared inside rebuildTables(), and
  # destinationCell() is a SIBLING function, not a nested one -- so reading it there threw
  # ReferenceError. Only the UNCHECKED branch reads it, and a catalogue of thousands of mods is
  # almost entirely unchecked, so the first row threw, the exception escaped the AJAX success
  # handler, and the picker's spinner span forever. World creation and editing were both dead.
  #
  # Nothing cheap could see it: php -l is clean because it is a runtime error, node --check is
  # clean on the source AND the served page, and the admin API answered valid JSON in 500 ms.
  # So it gets pinned here. The NEGATIVE is the one that matters -- the two-argument definition
  # must be GONE, not merely accompanied by a three-argument one, since a stray leftover copy
  # would shadow or replace the fixed one depending on source order.
  zpa=$(grep -c "function destinationCell(uuid, isChecked, neededDeps)" /opt/stateless/nginx/www/admin/new_world.php /opt/stateless/nginx/www/admin/edit_world.php | awk -F: '{s+=$2} END{print s}')
  zpb=$(grep -c "function destinationCell(uuid, isChecked)" /opt/stateless/nginx/www/admin/new_world.php /opt/stateless/nginx/www/admin/edit_world.php | awk -F: '{s+=$2} END{print s}')
  # And the guarded read inside it. Passing the argument but dereferencing it unguarded would
  # throw TypeError on any caller that omitted it -- a different exception in the same place,
  # with the same spinner.
  zpc=$(grep -c "if (neededDeps && neededDeps\[uuid\])" /opt/stateless/nginx/www/admin/new_world.php /opt/stateless/nginx/www/admin/edit_world.php | awk -F: '{s+=$2} END{print s}')

  # The admin UI showed no join code for a VANILLA crossplay world.
  #
  # getVanillaJoinInfo() returns href = NULL for one permanently and by design -- there is no
  # address and no Companion (design doc section 13). The launch cell branched on href first and
  # rendered "starting..." for the NULL case, so the code never appeared, while the modded world
  # in the row above showed its code fine. The code is the ONLY way into a vanilla crossplay
  # world, so this was the one thing that branch must not hide.
  #
  # BOTH files, because the server renders the row once and the 5s poll REPLACES it: a branch
  # present in only one of them is a chip that appears and then vanishes seconds later.
  # The WAITING state keeps the Launch affordance in place, disabled, rather than swapping in a
  # differently-worded control. A vanilla crossplay world that briefly had no Launch button at
  # all is what Brian reported twice; a label that changes out from under the operator reads as
  # the button having disappeared. Both render paths say "Launch" in every running case now, so
  # the marker counts the PENDING CHIP instead -- 3: two PHP waiting branches (vanilla and
  # modded) plus the single shared JS chip builder, which is why it is 3 and not 4. The old "join by code" label is gone, which zjf asserts.
  zja=$(grep -c "code waiting" /opt/stateless/nginx/www/admin/index.php)
  # NEGATIVE: the old label must not come back. It is the shape of the bug, not a style choice.
  zjf=$(grep -c "join by code" /opt/stateless/nginx/www/admin/index.php)
  # The shared JS chip builder. Three call sites now go through it so they cannot disagree about
  # what a present vs absent code looks like.
  zjb=$(grep -c "function joinCodeChipHtml" /opt/stateless/nginx/www/admin/index.php)
  # NEGATIVE: the no-href branch must no longer be an unconditional "starting...". If this is 0
  # the branch has been collapsed back and vanilla crossplay worlds are hiding their code again.
  # The array subscript sits between the key and the operator, so the literal is
  # launchJoinCode'] !== NULL -- a dot stands in for the quote because a bare apostrophe here
  # is still legal but needlessly hard to read. Two occurrences: the no-href branch this fix
  # added, and the pre-existing modded-crossplay branch.
  zjc=$(grep -c "launchJoinCode.. !== NULL" /opt/stateless/nginx/www/admin/index.php)

  echo "2.53 ADMIN JOIN CODE NEGATIVE: old join-by-code label=$zjf (want 0)"
  echo "2.53 admin join code (vanilla crossplay): pending chips=$zja (want 3)  shared chip fn=$zjb (want 1)  guarded branch=$zjc (want 2)"

  echo "2.53 PICKER BLOCKER NEGATIVES: two-arg destinationCell still present=$zpb (want 0)"
  echo "2.53 picker blocker: neededDeps passed in=$zpa (want 2)  guarded read=$zpc (want 2)"

  echo "2.53 COMPANION CONNECT NEGATIVES: SetServerToJoin in dll=$cnh (want 0)"
  echo "2.53 companion connect dll: payload=$cna (want 1)  flow=$cnb (want 1)  dialog=$cnc (want 1)  argname=$cnd (want 1)"
  echo "2.53 companion connect reflection: ProceedJoinRequest=$cne (want 1)  yesText=$cnf (want 1)  bodyText=$cnk (want 1)  dll over 30k=$cng (want 1)"
  echo "2.53 companion ip:port join: pre-resolve=$cnn (want 1)  coroutine=$cno (want 1)  menu-return watchdog=$cnp (want 1)"
  echo "2.53 DIALOG LAYOUT NEGATIVES: MaxModsListed count-cap still present=$cnt (want 0)"
  echo "2.53 dialog layout: align tag=$cnq (want 1)  column stop=$cnr (want 1)  testable seam=$cns (want 1)"
  echo "2.53 dialog UX: scrolling mod list=$cnu (want 1)  failure notice=$cnv (want 1)  bigger panel=$cnw (want 1)"
  echo "2.53 dialog UX: list-mode budget=$cnx (want 1)  anchored list layout=$cny (want 1)"
  echo "2.53 COMPANION CONNECT NEGATIVES: pending marker still present=$cni (want 0)"
  echo "2.53 companion connect gating: flag flipped to 1=$cnj (want 1)"
  echo "2.53 JOIN CODE NEGATIVES: narrow registered-only regex still present=$cnm (want 0)"
  echo "2.53 join code reader: any-mention regex=$cnl (want 1)"
  echo "2.53 quickconnect retirement: flag col=$qca (want >0)  owner-scoped delete=$qcb (want 2)  one-shot set=$qcc (want 1)"

  echo "2.53 picker (both pages): destSet=$czp (want 2)  column=$czq (want 2)  handler=$czr (want 2)  unsortable=$czs (want 2)  flags sent=$czt (want 2)"
  echo "2.53 picker PHP: selection reads cols=$czu (want 1)  save writes cols=$czv (want 1)  absent means both=$czw (want 1)"

  echo "2.53 QC RETIREMENT NEGATIVES: in requiredMods=$za (want 0)  gated on server version=$zj (want 0)  self-gating writer=$zm (want 0)"
  echo "2.53 QC retirement: legacy fallback=$zb (want 1)  connect flag=$zd (want 1)  companion dll in image=$ze (want 1)"
  echo "2.53 BUNDLING NEGATIVES: companion in requiredMods=$zc (want 0)  versionAtLeast alive=$zf (want 0)  newtonsoft shipped=$zj (want 0)"
  echo "2.53 bundling: companionSupportsConnect=$zg (want 1)  installSystemPlugins installs it=$zh (want 1)  hard exit if missing=$zi (want 1)"
  echo "2.53 call sites gated: engine=$zk (want 1)  importWorld=$zl (want 1)"
  echo "2.53 notice sites: migration=$zn (want 4)  puller ?? 1=$zo (want 1)  markup ?? 1=$zp (want 1)  dismiss case=$zq (want 1)  dismiss call=$zr (want 1)"
  echo "2.53 notice: seeding nested=$zs (want 1)  stop warning=$zt (want 1)"

  echo "2.53 HEADLINE NEGATIVES: argv gate gone=$ya (want 0)  refusal notice gone=$yb (want 0)"
  echo "2.53 HEADLINE NEGATIVES: api force-off gone=$yg (want 0)  gated setCrossplay gone=$yh (want 0)"
  echo "2.53 startWorld: console caveat=$yc (want 1)  running-options unindented=$yd (want 1) indented=$ye (want 0)"
  echo "2.53 startWorld blast radius: old vanilla-only listing gate gone=$yf (want 0, was 1 before access control was decoupled)"
  echo "2.53 api: ungated setCrossplay=$yi (want 1)"
  echo "2.53 launch: getModdedJoinInfo def=$yj (want 1)  admin call sites=$yk/$yl (want 1/1)"
  echo "2.53 launch NEGATIVES: inline modded literal gone=$ym/$yn (want 0/0)"
  echo "2.53 launch: modded href unconditional guard=$yo (want 1)"
  echo "2.53 UI NEGATIVES: row display-gate=$yp untick=$yq payload isVanilla-AND=$yr (want 0/0/0)"
  echo "2.53 disclaimers: warning el index/new=$ys2/$yt (want 2/2)  sync fn=$yu/$yv (want 4/3)"
  echo "2.53 styles: join-code-chip=$yw (want 1)  pv-callout=$yx (want 1)"
  echo "2.53 public card: joincode row=$yy (want 3)  isPlayFab=$yz (want 1)  poll uses it=$yaa (want 1)"
  echo "2.53 public NEGATIVE: vanilla-gated isCrossplay gone=$yab (want 0)  api modded block=$yac (want 1)"
  echo "2.53 whatsnew entry=$yad (want 1)"

  echo "2.46 world state: helper=$na/$nb (want 1/1)  calls ctx=$nc actions=$nd diag=$ne (want 5/3/2)  stateText=$ni (want 3)"
  echo "2.46 world state NEGATIVES: stale status reads w=$nf r=$ng row=$nh (want 0/0/0)"
  echo "2.45 openai negotiation: completion_tokens=$is reasoning=$ja loops=$jc (want >0)  stream err body=$it/$iu (want 1/1)"
  echo "2.45 openai negotiation: retry closures=$jb (want 2)"
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
    && [ "$m" = "2" ] && [ "$n" = "4" ] && [ "$o" = "3" ] && [ "$p" = "0" ] \
    && [ "$q" = "1" ] && [ "$r" = "4" ] && [ "$s" = "2" ] && [ "$t" = "2" ] \
    && [ "$u" = "0" ] && [ "$v" = "0" ] && [ "$w" = "1" ] && [ "$x" = "1" ] \
    && [ "$y" = "1" ] && [ "$z" = "1" ] \
    && [ "$aa" = "0" ] && [ "$ab" = "0" ] && [ "$ac" = "1" ] && [ "$ad" = "2" ] && [ "$ae" = "0" ] \
    && [ "$af" = "1" ] && [ "$ag" = "10" ] && [ "$ah" = "5" ] && [ "$ai" = "4" ] \
    && [ "$aj" = "1" ] && [ "$ak" = "3" ] && [ "$al" = "2" ] \
    && [ "$am" = "1" ] && [ "$an" = "1" ] && [ "$ao" = "1" ] \
    && [ "$ap" = "1" ] && [ "$aq" = "1" ] && [ "$ar" = "2" ] && [ "$as" = "1" ] && [ "$at" = "0" ] \
    && [ "$au" = "1" ] && [ "$av" = "3" ] && [ "$aw" = "1" ] && [ "$ax" = "1" ] && [ "$ay" = "0" ] && [ "$az" = "3" ] \
    && [ "$ba" = "0" ] && [ "$bb" = "1" ] && [ "$bc" = "2" ] && [ "$bd" = "0" ] \
    && [ "$be" = "1" ] && [ "$bf" = "1" ] && [ "$bg" = "0" ] && [ "$bh" = "1" ] && [ "$bi" = "3" ] && [ "$bj" = "1" ] && [ "$bk" = "1" ] \
    && [ "$bl" = "2" ] && [ "$bm" = "1" ] && [ "$bn" = "1" ] && [ "$bo" = "2" ] \
    && [ "$ver" = "1" ] \
    && [ "$bp" = "1" ] && [ "$bq" = "1" ] && [ "$br" = "1" ] && [ "$bs" = "1" ] && [ "$bt" = "1" ] \
    && [ "$bu" = "7" ] && [ "$bv" = "1" ] && [ "$bw" = "1" ] \
    && [ "$bx" = "4" ] && [ "$by" = "2" ] \
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
    && [ "$dz" = "1" ] && [ "$ea" = "1" ] && [ "$eb" = "1" ] && [ "$ec" = "1" ] && [ "$ed" = "1" ] \
    && [ "$ee" = "0" ] && [ "$ef" = "0" ] && [ "$eg" = "1" ] && [ "$eh" = "0" ] \
    && [ "$ei" = "0" ] && [ "$ej" = "0" ] && [ "$ek" = "0" ] && [ "$el" = "0" ] && [ "$em" = "0" ] \
    && [ "$en" = "1" ] && [ "$eo" = "3" ] && [ "$ep" = "0" ] && [ "$er" = "0" ] \
    && [ "$es" -gt 0 ] && [ "$et" -gt 0 ] && [ "$eu" -gt 0 ] && [ "$ev" -gt 0 ] \
    && [ "$ew" -gt 0 ] && [ "$ex" -gt 0 ] \
    && [ "$ey" -gt 0 ] && [ "$ez" -gt 0 ] && [ "$fa" -gt 0 ] && [ "$fb" -gt 0 ] && [ "$fc" -gt 0 ] \
    && [ "$fd" -gt 0 ] && [ "$fe" -gt 0 ] && [ "$ff" -gt 0 ] && [ "$fg" -gt 0 ] \
    && [ "$fh" = "0" ] && [ "$fi_" -gt 0 ] && [ "$fj" -gt 0 ] \
    && [ "$fk" = "1" ] && [ "$fl" = "1" ] && [ "$fm" = "0" ] \
    && [ "$fn" = "1" ] && [ "$fo" = "0" ] \
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
    && [ "$xa" = "0" ] && [ "$xb" = "0" ] && [ "$xi" = "0" ] \
    && [ "$xc" = "1" ] && [ "$xd" = "1" ] && [ "$xe" = "1" ] \
    && [ "$xf" = "1" ] && [ "$xg" = "2" ] && [ "$xh" = "1" ] \
    && [ "$xj" = "0" ] && [ "$xk" = "0" ] && [ "$xl" = "1" ] \
    && [ "$xm" = "0" ] && [ "$xn" = "0" ] && [ "$xo" = "0" ] \
    && [ "$xp" = "3" ] && [ "$xq" = "0" ] \
    && [ "$xr" = "1" ] && [ "$xs" = "1" ] \
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
    && [ "$kw" = "2" ] && [ "$kx" = "0" ] && [ "$ky" = "1" ] \
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
    && [ "$qc" = "1" ] && [ "$qd" = "1" ] && [ "$qe" = "1" ] && [ "$qf" = "1" ] \
    && [ "$qg" = "2" ] && [ "$qh" = "4" ] && [ "$qi" = "1" ] && [ "$qj" = "1" ] \
    && [ "$qk" = "1" ] && [ "$ql" = "1" ] && [ "$qm" = "1" ] && [ "$qn" = "1" ] \
    && [ "$px" = "1" ] && [ "$py" = "1" ] && [ "$pz" = "1" ] && [ "$qa" = "0" ] && [ "$qb" = "1" ] \
    && [ "$ra" = "3" ] && [ "$rb" = "0" ] && [ "$rc_" = "1" ] \
    && [ "$rd" = "1" ] && [ "$re" = "6" ] && [ "$rf" = "0" ] && [ "$ri" = "1" ] && [ "$rj" = "1" ] \
    && [ "$rg" = "1" ] \
    && [ "$d" = "1" ] && [ "$d2" = "0" ] && [ "$jb" = "2" ] \
    && [ "$tc" = "0" ] && [ "$ta" = "2" ] && [ "$tb" = "3" ] \
    && [ "$tg" = "2" ] && [ "$th" = "1" ] && [ "$td" = "1" ] \
    && [ "$te" = "1" ] && [ "$tf" = "1" ] \
    && [ "$uc" = "0" ] && [ "$uf" = "0" ] && [ "$ua" = "2" ] \
    && [ "$ub" = "5" ] && [ "$ud" = "1" ] && [ "$ue" = "1" ] \
    && [ "$vb" = "0" ] && [ "$vc" = "0" ] && [ "$ve" = "0" ] \
    && [ "$va" = "2" ] && [ "$vd" = "1" ] \
    && [ "$wa" = "1" ] && [ "$wb" = "1" ] && [ "$wc" = "1" ] && [ "$wd" = "3" ] \
    && [ "$we" = "1" ] && [ "$wf" = "1" ] && [ "$ws" = "1" ] && [ "$wt" = "$wu" ] \
    && [ "$wg" = "0" ] && [ "$wh" = "5" ] && [ "$wi" = "5" ] && [ "$wj" = "1" ] \
    && [ "$wk" = "4" ] && [ "$wl" = "1" ] && [ "$wm" = "4" ] && [ "$wn" = "4" ] \
    && [ "$wo" = "2" ] && [ "$wp" = "2" ] && [ "$wq" = "1" ] && [ "$wr" = "1" ] \
    && [ "$xa" = "0" ] && [ "$xb" = "0" ] && [ "$xi" = "0" ] \
    && [ "$xc" = "1" ] && [ "$xd" = "1" ] && [ "$xe" = "1" ] && [ "$xf" = "1" ] && [ "$xg" = "2" ] \
    && [ "$xh" = "1" ] && [ "$xj" = "0" ] && [ "$xk" = "0" ] && [ "$xl" = "1" ] \
    && [ "$xm" = "0" ] && [ "$xn" = "0" ] && [ "$xo" = "0" ] && [ "$xp" = "3" ] && [ "$xq" = "0" ] \
    && [ "$xr" = "1" ] && [ "$xs" = "1" ] \
    && [ "$ya" = "0" ] && [ "$yb" = "0" ] && [ "$yg" = "0" ] && [ "$yh" = "0" ] \
    && [ "$yc" = "1" ] && [ "$yd" = "1" ] && [ "$ye" = "0" ] && [ "$yf" = "0" ] \
    && [ "$yi" = "1" ] && [ "$yj" = "1" ] && [ "$yk" = "1" ] && [ "$yl" = "1" ] \
    && [ "$ym" = "0" ] && [ "$yn" = "0" ] && [ "$yo" = "1" ] \
    && [ "$yp" = "0" ] && [ "$yq" = "0" ] && [ "$yr" = "0" ] \
    && [ "$ys2" = "2" ] && [ "$yt" = "2" ] && [ "$yu" = "4" ] && [ "$yv" = "3" ] \
    && [ "$yw" = "1" ] && [ "$yx" = "1" ] \
    && [ "$yy" = "3" ] && [ "$yz" = "1" ] && [ "$yaa" = "1" ] && [ "$yab" = "0" ] \
    && [ "$yac" = "1" ] && [ "$yad" = "1" ] \
    && [ "$za" = "0" ] && [ "$zm" = "0" ] \
    && [ "$zc" = "0" ] && [ "$zf" = "0" ] && [ "$zj" = "0" ] \
    && [ "$zb" = "1" ] && [ "$zd" = "1" ] && [ "$ze" = "1" ] \
    && [ "$zg" = "1" ] && [ "$zh" = "1" ] && [ "$zi" = "1" ] \
    && [ "$zk" = "1" ] && [ "$zl" = "1" ] \
    && [ "$zn" = "4" ] && [ "$zo" = "1" ] && [ "$zp" = "1" ] && [ "$zq" = "1" ] && [ "$zr" = "1" ] \
    && [ "$zs" = "1" ] && [ "$zt" = "1" ] \
    && [ "$zu" = "2" ] && [ "$zv" = "2" ] \
    && [ "$czn" = "0" ] \
    && [ "$cza" = "1" ] && [ "$czb" = "1" ] \
    && [ "$czc" = "1" ] && [ "$czd" = "1" ] && [ "$cze" = "1" ] && [ "$czf" = "1" ] \
    && [ "$czg" = "1" ] && [ "$czh" = "1" ] \
    && [ "$czi" = "1" ] && [ "$czj" = "1" ] && [ "$czk" = "1" ] && [ "$czl" = "1" ] && [ "$czm" = "1" ] \
    && [ "$czo" = "1" ] \
    && [ "$czp" = "2" ] && [ "$czq" = "2" ] && [ "$czr" = "2" ] && [ "$czs" = "2" ] && [ "$czt" = "2" ] \
    && [ "$czu" = "1" ] && [ "$czv" = "1" ] && [ "$czw" = "1" ] \
    && [ "$zpb" = "0" ] \
    && [ "$zpa" = "2" ] && [ "$zpc" = "2" ] \
    && [ "$cnh" = "0" ] \
    && [ "$cna" = "1" ] && [ "$cnb" = "1" ] && [ "$cnc" = "1" ] && [ "$cnd" = "1" ] \
    && [ "$cne" = "1" ] && [ "$cnf" = "1" ] && [ "$cng" = "1" ] && [ "$cnk" = "1" ] \
    && [ "$cnn" = "1" ] && [ "$cno" = "1" ] && [ "$cnp" = "1" ] \
    && [ "$cnt" = "0" ] \
    && [ "$cnq" = "1" ] && [ "$cnr" = "1" ] && [ "$cns" = "1" ] \
    && [ "$cnu" = "1" ] && [ "$cnv" = "1" ] && [ "$cnw" = "1" ] \
    && [ "$cnx" = "1" ] && [ "$cny" = "1" ] \
    && [ "$cnz" = "1" ] && [ "$cob" = "1" ] && [ "$coc" = "1" ] \
    && [ "$coe" = "1" ] && [ "$cof" = "1" ] && [ "$cog" = "1" ] && [ "$cod" = "1" ] \
    && [ "$coh" = "1" ] && [ "$coi" = "1" ] && [ "$coj" = "1" ] && [ "$con" = "1" ] \
    && [ "$cok" = "0" ] && [ "$com" = "0" ] \
    && [ "$cop" = "1" ] && [ "$coq" = "1" ] && [ "$cor" = "1" ] && [ "$cos" = "1" ] \
    && [ "$cot" = "0" ] && [ "$cpa" = "1" ] && [ "$cou" = "1" ] && [ "$cov" = "2" ] \
    && [ "$cow" = "0" ] && [ "$cox" = "1" ] && [ "$coy" = "1" ] && [ "$coz" = "1" ] \
    && [ "$cpb" = "1" ] && [ "$cpc" = "1" ] && [ "$cpd" = "0" ] && [ "$cpe" = "0" ] \
    && [ "$cpf" = "2" ] && [ "$cpg" = "0" ] \
    && [ "$cph" = "0" ] && [ "$cpi" = "1" ] && [ "$cpj" = "1" ] \
    && [ "$cpk" = "1" ] && [ "$cpl" = "0" ] \
    && [ "$cpm" = "0" ] && [ "$cpn" = "1" ] && [ "$cpo" = "1" ] \
    && [ "$cpp" = "1" ] && [ "$cpq" = "1" ] && [ "$cpr" = "1" ] && [ "$cps" = "1" ] && [ "$cpt" = "0" ] \
    && [ "$cpu" = "1" ] && [ "$cpv" = "1" ] && [ "$cpw" = "1" ] && [ "$cpx" = "0" ] \
    && [ "$cpy" = "0" ] && [ "$cpz" = "1" ] && [ "$cqa" = "1" ] && [ "$cqb" = "1" ] \
    && [ "$cqc" = "0" ] && [ "$cqd" = "0" ] \
    && [ "$cqe" = "0" ] && [ "$cqf" = "1" ] && [ "$cqg" = "1" ] && [ "$cqh" = "0" ] \
    && [ "$cqi" = "1" ] && [ "$cqj" = "1" ] && [ "$cqk" = "1" ] && [ "$cql" = "1" ] \
    && [ "$zjd" = "2" ] && [ "$zje" = "1" ] \
    && [ "$zja" = "3" ] && [ "$zjf" = "0" ] && [ "$zjb" = "1" ] && [ "$zjc" = "2" ] \
    && [ "$cni" = "0" ] && [ "$cnj" = "1" ] \
    && [ "$cnm" = "0" ] \
    && [ "$cnl" = "1" ] \
    && [ "$qca" -gt 0 ] && [ "$qcb" = "2" ] && [ "$qcc" = "1" ] \
    && echo "IMAGE VERIFY OK" || echo "IMAGE VERIFY FAILED"
PHVVERIFYEOF

# Refuse to proceed on a truncated payload rather than verify a fraction of it and pass.
verifyBytes=$(wc -c < "$VERIFY_SH")
echo "=== verify payload is $verifyBytes bytes (mounted, not argv) ==="
if [ "$verifyBytes" -lt 10000 ]; then
  echo "IMAGE VERIFY FAILED -- the verify payload is only $verifyBytes bytes; it did not assemble"
  echo "=== done FAILED ==="
  exit 1
fi

docker run --rm -e EXPECT_VER="$EXPECT_VER" \
  -v "$VERIFY_SH":/phvalheim-verify.sh:ro \
  --entrypoint sh "$IMAGE" /phvalheim-verify.sh

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
