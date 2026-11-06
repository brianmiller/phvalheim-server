#!/bin/bash
# The source PR alone does not ship a fix: pin the tested binary delivered by this server.
set -eu
ROOT=$(cd "$(dirname "$0")/.." && pwd)
DLL=${PHV_COMPANION_DLL:-$ROOT/container/games/valheim/custom_plugins/PhValheimCompanion/PhValheimCompanion.dll}
EXPECTED=8705a9b960a48956e1756e116c8feccb4c8891dce92a71bcc42e54332e6dfa54
ACTUAL=$(sha256sum "$DLL" | cut -d ' ' -f1)
[ "$ACTUAL" = "$EXPECTED" ] || { echo "FAIL: server bundles an unverified Companion ($ACTUAL); expected click-only revision $EXPECTED"; exit 1; }
echo 'PASS: server bundles the tested click-only Companion'
