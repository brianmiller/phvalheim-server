#!/bin/bash
# requires: container
set -eu
repo=$(cd "$(dirname "$0")/.." && pwd)
container=${PHV_CONTAINER:-phvalheim-dev}
artifacts=${PHV_POLICY_ARTIFACTS:-/tmp/phv260-ui}
mkdir -p "$artifacts"
docker cp "$repo/dev_tools/config_policy_ui_fixture.py" "$container:/tmp/phv260-ui-fixture.py" >/dev/null
cleanup() { docker exec "$container" python3 /tmp/phv260-ui-fixture.py cleanup; }
trap cleanup EXIT
docker exec "$container" python3 /tmp/phv260-ui-fixture.py create > "$artifacts/fixture.json"
docker run --rm --network host -v "$repo":/repo:ro -v "$artifacts":/artifacts \
    mcr.microsoft.com/playwright:v1.47.0-jammy bash -c \
    'npm install --silent --prefix /tmp/pw playwright@1.47.0; NODE_PATH=/tmp/pw/node_modules node /repo/dev_tools/config-policy-browser.cjs'
