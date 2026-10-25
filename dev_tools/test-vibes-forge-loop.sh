#!/usr/bin/env bash
# requires: container
# Real streaming adapter -> nine file reads -> automatic compaction -> final response.
set -eu
C="${ORACLE_CONTAINER:-phvalheim-dev}"
REPO="$(cd "$(dirname "$0")/.." && pwd)"
TMP="/tmp/phv-forge-loop-$$.php"
trap 'docker exec "$C" rm -f "$TMP" >/dev/null' EXIT
docker cp "$REPO/dev_tools/fixtures/vibes-forge-loop.php" "$C:$TMP"
docker exec "$C" chmod 644 "$TMP"
docker exec -u phvalheim "$C" php "$TMP"
