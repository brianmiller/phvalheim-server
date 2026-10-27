#!/bin/bash
# requires: container
set -eu
repo=$(cd "$(dirname "$0")/.." && pwd)
container=${PHV_CONTAINER:-phvalheim-dev}
docker cp "$repo/dev_tools/config_policy_oracle.py" "$container:/tmp/phv-test-config-policy.py" >/dev/null
docker exec "$container" python3 /tmp/phv-test-config-policy.py
docker exec "$container" rm -f /tmp/phv-test-config-policy.py
