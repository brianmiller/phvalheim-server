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
  g=$(grep -c "Use Access List" /opt/stateless/nginx/www/admin/index.php)
  h=$(grep -c "Public World" /opt/stateless/nginx/www/admin/index.php)
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
  echo "\"Use Access List\"=$g (want 3)  \"Public World\"=$h (want 0)"
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
  echo "engine 'Marking it broken'=$o (want 2)  engine 'exit 1'=$p (want 0)"
  [ "$a" = "2" ] && [ "$b" = "1" ] && [ "$c" = "1" ] \
    && [ "$e" = "3" ] && [ "$f" = "0" ] && [ "$g" = "3" ] && [ "$h" = "0" ] \
    && [ "$i" = "2" ] && [ "$j" = "0" ] && [ "$k" = "3" ] && [ "$l" -gt 0 ] \
    && [ "$m" = "2" ] && [ "$n" = "4" ] && [ "$o" = "2" ] && [ "$p" = "0" ] \
    && echo "IMAGE VERIFY OK" || echo "IMAGE VERIFY FAILED"
'

echo "=== digest ==="
docker inspect --format '{{index .RepoDigests 0}}' "$IMAGE"
echo "=== done $(date -u) ==="
