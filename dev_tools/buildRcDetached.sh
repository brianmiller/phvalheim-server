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
  echo "modSelectionCard=$a (want 2)"
  echo "Clearing world md5sum=$b (want 1)"
  echo "modded-payload WARNING=$c (want 1)"
  # 2 = the wrapper <div id="modSelectionArea"> + the comment explaining why the
  # toggle moved off it. A THIRD would mean a live $(...).toggle() came back.
  echo "modSelectionArea mentions=$d (want 2: the div + the comment)"
  [ "$a" = "2" ] && [ "$b" = "1" ] && [ "$c" = "1" ] && echo "IMAGE VERIFY OK" || echo "IMAGE VERIFY FAILED"
'

echo "=== digest ==="
docker inspect --format '{{index .RepoDigests 0}}' "$IMAGE"
echo "=== done $(date -u) ==="
