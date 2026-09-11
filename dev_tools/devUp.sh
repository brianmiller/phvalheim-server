#!/bin/bash
# Recreate the local dev container, optionally with the Steam-auth bypass on.
#
#   dev_tools/devUp.sh                      # normal: real Steam login required
#   dev_tools/devUp.sh 76561198000000001    # bypass: render the public UI as that player
#
# The bypass is a DEVELOPMENT-ONLY flag read from the environment and nothing else; a page
# served with it on carries a red banner saying so. See dev_tools/test-dev-auth-bypass.sh for
# what keeps it safe. Never pass it to anything reachable from outside your machine.
#
# The data volume is named and is NOT touched, so recreating loses nothing.
set -eu

DEV_ID="${1:-}"
NAME=phvalheim-dev
IMAGE=theoriginalbrian/phvalheim-server:rc
VOLUME=phvalheim-dev-data

if [ -n "$DEV_ID" ] && ! echo "$DEV_ID" | grep -qE '^[0-9]{17}$'; then
	echo "ERROR: '$DEV_ID' is not a 17-digit SteamID64 -- the bypass would silently stay off." >&2
	exit 1
fi

env_args=""
[ -n "$DEV_ID" ] && env_args="-e phvalheimDevSteamID=$DEV_ID"

docker rm -f "$NAME" >/dev/null 2>&1 || true
# shellcheck disable=SC2086
docker run -d --name "$NAME" --restart unless-stopped \
	-p 8080-8081:8080-8081/tcp \
	-p 25000-25010:25000-25010/udp \
	-v "$VOLUME":/opt/stateful \
	$env_args \
	"$IMAGE" >/dev/null

echo "$NAME up on http://$(hostname -I | awk '{print $1}'):8080  (admin :8081)"
[ -n "$DEV_ID" ] && echo "STEAM AUTH BYPASSED as $DEV_ID -- the page will say so in red."
exit 0
