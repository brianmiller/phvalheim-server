#!/bin/bash
# requires: container
# Isolated shipping PHP/HTTP tests; no world, provider or settings changes in the dev DB.
set -eu
REPO=$(cd -- "$(dirname -- "${BASH_SOURCE[0]}")/.." && pwd)
C=${ORACLE_CONTAINER:-phvalheim-dev}
IMAGE=$(docker inspect "$C" --format '{{.Image}}')
docker run --rm --entrypoint php --user phvalheim --group-add "$(stat -c %g "$REPO")" -e LIMITS_QUICK="${LIMITS_QUICK:-}" -v "$REPO:/work:ro" "$IMAGE" /work/dev_tools/fixtures/vibes-limits.php
