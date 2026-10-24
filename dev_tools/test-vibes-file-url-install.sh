#!/bin/bash
# requires: container
#
# Oracle: the ONE engine seam Hugin's Vibes adds -- a `file://` download_url in installMods
# (container/engine/includes/0-functions.sh, the wget at ~line 953) is COPIED, with the same
# -s check and the same failure accounting as a failed wget. Design section 6.4.
#
# WHY NOT THE REAL FUNCTION. The smallest function that reaches the seam is
# downloadAndInstallTsModsForWorld(), and it needs a world row, worldMods.py --plan against the
# live catalogue, a BepInEx tree to unzip into and `--record-installed` writes to world_mods --
# i.e. a real world mutated by a test. The brief forbids touching worlds. So this test:
#   1. asserts STATICALLY, on the file the image ships, that the seam is where the design says
#      (exactly one wget in the install loop, guarded by a file:// branch that cp's), and
#   2. EXTRACTS that exact block from the shipped file and runs it in the image's own bash with
#      the loop's variables set, three times: a real file:// zip (copied, -s passes), a missing
#      file:// path (counted as a failure, nothing left behind) and an unreachable http URL (the
#      wget branch still runs and still fails properly).
# The block is cut from the shipped file at run time, so a rewrite of the seam is tested, not a
# copy of it. If the cut comes back empty the test fails loudly rather than passing on nothing.
#
# Usage: bash dev_tools/test-vibes-file-url-install.sh [container]   (default phvalheim-dev)

set -u
C="${1:-phvalheim-dev}"
REPO="$(cd "$(dirname "$0")/.." && pwd)"
F="$REPO/container/engine/includes/0-functions.sh"
pass=0; fail=0
ok()  { echo "  PASS  $1"; pass=$((pass+1)); }
bad() { echo "  FAIL  $1${2:+ -- $2}"; fail=$((fail+1)); }

docker ps --format '{{.Names}}' | grep -qx "$C" || { echo "FAIL  container $C is not running"; exit 1; }

# --- 1. static shape of the seam -----------------------------------------------------------
LOOP=$(awk '/#### Downloading \$modFileConstructed from \$modSource/{p=1} p{print} /modInstallFailures=\$\(\(modInstallFailures\+1\)\)/{if(p){exit}}' "$F")
[ -n "$LOOP" ] && ok "install loop download block found" || bad "install loop download block found"
[ "$(echo "$LOOP" | grep -c 'wget ')" = "1" ] && ok "exactly one wget in the block" || bad "exactly one wget" "$(echo "$LOOP" | grep -c 'wget ')"
echo "$LOOP" | grep -q 'modDownloadUrl#file://' && ok "a file:// branch guards it" || bad "file:// branch present"
echo "$LOOP" | grep -q 'cp -f "${modDownloadUrl#file://}" "$tsModsDir/$modFileConstructed"' && ok "the file:// branch cp's into the same destination" || bad "cp destination"
echo "$LOOP" | grep -q "Hugin's Vibes" && echo "$LOOP" | grep -q 'section 6.4' && ok "comment names Hugin's Vibes and the design section" || bad "seam comment"
echo "$LOOP" | grep -q '\[ ! -s "$tsModsDir/$modFileConstructed" \]' && ok "the -s check follows both branches" || bad "-s check present"
# The if/fi must sit between the log line and the $? check with nothing else in between, or $?
# would be the status of something other than cp/wget.
echo "$LOOP" | sed -n '/^[[:space:]]*fi$/,/if \[ \$? -ne 0 \]/p' | grep -vE '^\s*#|^\s*fi$|if \[ \$\? -ne 0 \]' | grep -q . \
  && bad "nothing runs between the cp/wget if/fi and the \$? check" || ok "nothing runs between the cp/wget if/fi and the \$? check"

# --- 2. run the extracted block in the image's bash ------------------------------------------
BLOCK=$(echo "$LOOP" | sed -n '/^[[:space:]]*if \[ "${modDownloadUrl#file:\/\/}"/,/modInstallFailures=\$((modInstallFailures+1))/p')
[ -n "$BLOCK" ] || { bad "seam block extracted (empty -- the seam moved; update the awk/sed anchors)"; echo; echo "$pass passed, $fail failed"; exit 1; }
# The block ends at the failure counter, mid-`if` (before the loop's `continue`); close it so it
# is a complete statement. modInstallFailures is the accounting the engine reads.
SCRIPT="$BLOCK
fi"
docker cp "$F" "$C:/tmp/vibes-seam-functions.sh" >/dev/null
# -i: without it docker exec gives the heredoc no stdin and the file is empty.
docker exec -i "$C" bash -c 'cat > /tmp/vibes-seam-block.sh' <<EOF
phvlog() { echo "[\$1] \${*:2}"; }
tsModsDir=/tmp/vibes-seam-\$\$; mkdir -p "\$tsModsDir"; cd /tmp
printf 'PK\003\004zipbytes' > /tmp/vibes-seam-src.zip
run() { # \$1 url \$2 filename
  modDownloadUrl="\$1"; modFileConstructed="\$2"; worldName=seamtest; modInstallFailures=0
$SCRIPT
  echo "RESULT url=\$1 failures=\$modInstallFailures exists=\$([ -s "\$tsModsDir/\$2" ] && echo yes || echo no)"
}
run "file:///tmp/vibes-seam-src.zip" good.zip
run "file:///tmp/does-not-exist.zip" missing.zip
run "http://127.0.0.1:9/nothing.zip" http.zip
rm -rf "\$tsModsDir" /tmp/vibes-seam-src.zip
EOF
OUT=$(docker exec "$C" bash /tmp/vibes-seam-block.sh 2>&1)
docker exec "$C" rm -f /tmp/vibes-seam-block.sh /tmp/vibes-seam-functions.sh
echo "$OUT" | grep -q 'RESULT url=file:///tmp/vibes-seam-src.zip failures=0 exists=yes' && ok "file:// of a real zip: copied, no failure counted" || bad "file:// real zip" "$OUT"
echo "$OUT" | grep -q 'RESULT url=file:///tmp/does-not-exist.zip failures=1 exists=no' && ok "file:// of a missing path: failure counted, nothing left behind" || bad "file:// missing" "$OUT"
echo "$OUT" | grep -q 'DOWNLOAD FAILED for missing.zip' && ok "missing file:// logs DOWNLOAD FAILED like a 404" || bad "missing file:// log line"
echo "$OUT" | grep -q 'RESULT url=http://127.0.0.1:9/nothing.zip failures=1 exists=no' && ok "http URL still takes the wget branch and fails properly" || bad "http branch" "$OUT"

echo; echo "$pass passed, $fail failed"
[ "$fail" -eq 0 ]
