#!/bin/bash
# Oracle test: a mod that unzips WITH WARNINGS must count as installed, not as MISSING.
#
# THE BUG THIS CATCHES (github #82, and three separate user reports that were all this):
# `unzip` exit 1 means "one or more warning errors, but processing completed successfully
# anyway". 0-functions.sh treated anything but 0 and 11 as fatal. A mod zipped on Windows
# stores entries with backslash separators -- PONEIS/SmartContainers ships
# `plugins\SmartContainers.dll` -- so unzip extracts it correctly, warns, and returns 1.
#
# The blast radius was far bigger than a wrong log line:
#   modInstallFailures++  ->  downloadAndInstallTsModsForWorld returns 1
#     ->  phvalheim skips packageClient        (client download frozen at last good build)
#     ->  phvalheim skips generateModViewerJson (mod viewer frozen -- "my edits do nothing")
#     ->  world set mode=stopped, status=failed (world down)
# One cosmetic warning from one mod froze an entire world's mod list.
#
# WHY THIS TEST CAN SEE IT: it builds a real backslash-packed zip, runs the exact guard
# expression from the source, and asserts BOTH that the guard passes AND that the DLL is on
# disk. A test that only checked the exit code would not prove the file extracted; a test
# that only checked the file would pass even while the guard wrongly condemned it.
#
# Usage: dev_tools/test-unzip-warning-not-failure.sh [container]
set -uo pipefail
CONTAINER="${1:-phvalheim-dev}"
pass=0; fail=0
ok() { pass=$((pass+1)); echo "  PASS  $1"; }
no() { fail=$((fail+1)); echo "  FAIL  $1${2:+ -- $2}"; }

echo
echo "=== unzip warnings are not install failures ($CONTAINER) ==="

# ---- the guard expression must live in the source in its fixed form ----
SRC=container/engine/includes/0-functions.sh
grep -q 'unzipResult -gt 1' "$SRC" \
    && ok "plugin guard accepts exit 1 (-gt 1)" \
    || no "plugin guard still rejects exit 1" "$(grep -n 'unzipResult -ne 0' "$SRC" | head -1)"
grep -q 'RESULT -le 1' "$SRC" \
    && ok "BepInEx guard accepts exit 1 (-le 1)" \
    || no "BepInEx guard still requires exit 0" "$(grep -n 'RESULT = 0' "$SRC" | head -1)"

# ---- and it must behave correctly on a REAL backslash-packed zip ----
# Built here rather than downloaded: the fixture must not depend on a third party continuing
# to publish a Windows-packed zip. python's zipfile writes the entry name verbatim, which is
# exactly the condition unzip warns about.
docker exec -i "$CONTAINER" bash <<'SH' > /tmp/uzt-result 2>&1
set -u
rm -rf /tmp/uzt; mkdir -p /tmp/uzt/src
echo "fake dll bytes" > /tmp/uzt/src/payload
# create_system = 0 (MS-DOS/Windows) is the load-bearing part. unzip decides an archive
# "appears to use backslashes as path separators" from the zip's HOST-OS field, not from the
# entry name -- so a backslash name written with python's default create_system=3 (Unix) is
# taken literally, produces a file called "plugins\TestMod.dll", and exits 0. That fixture
# looks right and reproduces nothing. With create_system=0, unzip converts the separator,
# warns, and returns 1: the real condition.
python3 -c "
import zipfile
z = zipfile.ZipFile('/tmp/uzt/backslash.zip','w')
for nm, data in [('plugins\\\\TestMod.dll', b'fake dll bytes'), ('manifest.json', b'{}')]:
    zi = zipfile.ZipInfo(nm)
    zi.create_system = 0
    z.writestr(zi, data)
z.close()
"
d=/tmp/uzt/out; rm -rf "$d"; mkdir -p "$d"
unzip -o /tmp/uzt/backslash.zip -x config/* core/* patchers/* BepInExPack_Valheim/* \
      README.md icon.png manifest.json -d "$d" > /tmp/uzt/log 2>&1
r=$?
echo "EXITCODE=$r"
echo "DLLCOUNT=$(find "$d" -path '*/plugins/TestMod.dll' | wc -l)"
echo "WARNED=$(grep -c backslash /tmp/uzt/log || true)"
if [ $r -gt 1 ] && [ $r -ne 11 ]; then echo "NEWGUARD=fail"; else echo "NEWGUARD=pass"; fi
if [ $r -ne 0 ] && [ $r -ne 11 ]; then echo "OLDGUARD=fail"; else echo "OLDGUARD=pass"; fi
SH

get() { grep -m1 "^$1=" /tmp/uzt-result | cut -d= -f2; }
EXITCODE=$(get EXITCODE); DLLCOUNT=$(get DLLCOUNT); WARNED=$(get WARNED)
NEWGUARD=$(get NEWGUARD); OLDGUARD=$(get OLDGUARD)

# The fixture has to actually reproduce the condition, or the rest proves nothing.
[ "${WARNED:-0}" -ge 1 ] && ok "fixture really is backslash-packed (unzip warned)" \
    || no "fixture did not trigger the warning; test is blind" "$(head -3 /tmp/uzt-result)"
[ "${EXITCODE:-x}" = "1" ] && ok "unzip returned 1 (warning, not error)" \
    || no "unzip returned ${EXITCODE:-?}, expected 1" "$(head -5 /tmp/uzt-result)"

# The two assertions that matter, together.
[ "${DLLCOUNT:-0}" -ge 1 ] && ok "the DLL was extracted despite the warning" \
    || no "nothing extracted -- then exit 1 WOULD be a real failure"
[ "$NEWGUARD" = "pass" ] && ok "the fixed guard treats it as installed" \
    || no "the fixed guard still condemns it"

# Proof the test is an oracle: the OLD guard must fail this fixture.
[ "$OLDGUARD" = "fail" ] && ok "the old guard condemned it (confirms this test sees the bug)" \
    || no "the old guard also passed; this fixture cannot detect the regression"

rm -f /tmp/uzt-result
echo
echo "  $pass passed, $fail failed"
[ "$fail" -eq 0 ]
