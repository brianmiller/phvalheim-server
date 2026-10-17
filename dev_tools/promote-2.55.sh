#!/bin/bash
# Promote 2.55: rebuild and tag :2.55 + :latest, but ONLY if the in-image verify passes.
#
# WHY A WRAPPER AND NOT AN INLINE ENV PREFIX. The documented invocation is
#   EXTRA_TAGS="2.55 latest" setsid nohup dev_tools/buildRcDetached.sh &
# and launching that through this session's tooling silently lost the variable: the process
# started with EXTRA_TAGS unset (checked in /proc/<pid>/environ) and then died with a
# zero-byte log. An unset EXTRA_TAGS does not fail -- buildRcDetached.sh just builds :rc and
# promotes nothing, which looks exactly like a successful release from the outside.
#
# So the export lives in a file, where it can be read back and cannot be lost in transit.
#
# This is NOT a retag. `docker tag`-ing a tested :rc into a version ships whatever :rc
# happened to contain at that moment; the builder re-runs the marker gate against the pushed
# image and leaves the extra tags alone if it fails. See docs/RELEASING.md.
set -u
cd "$(cd -- "$(dirname -- "${BASH_SOURCE[0]}")/.." && pwd)" || exit 1

export EXTRA_TAGS="2.55 latest"
echo "=== promote wrapper: EXTRA_TAGS='$EXTRA_TAGS' ==="
exec bash dev_tools/buildRcDetached.sh
