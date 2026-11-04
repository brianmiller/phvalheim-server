#!/bin/bash
# Source before creating a disposable server. Never use a post-start SQL opt-out.
_phv_fixture_dir=$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)
[ -f "$_phv_fixture_dir/fixtures/analytics-disabled.sh" ] || return 1
PHV_TEST_ANALYTICS_ARGS=(--mount "type=bind,source=$_phv_fixture_dir/fixtures/analytics-disabled.sh,target=/opt/stateless/engine/tools/pushAnalytics.sh,readonly")
unset _phv_fixture_dir
