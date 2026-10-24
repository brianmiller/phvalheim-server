#!/bin/bash
# Oracle: a Vibes package has EXACTLY Thunderstore's entries (tools/vibes/lib/package.php).
#
# The engine unzips whatever is in the zip into a world's BepInEx/. A stray bin/ tree, a .pdb,
# or a copied BepInEx.dll/UnityEngine.dll in plugins/ would land on every player; a missing
# icon or manifest makes the catalogue card blank. So the listing is compared as a whole, not
# probed for a few names: expected == actual, nothing more, nothing less.
#
# Standalone: builds a fake project under a temp data volume with a dummy DLL in bin/ plus a
# foreign DLL (BepInEx.dll) that must NOT ship and an extra library (Newtonsoft.Json.dll) that
# MUST, then runs the real vibesPackage() and lists the zip with unzip.
#
# Usage: bash dev_tools/test-vibes-package-layout.sh

set -u
REPO="$(cd "$(dirname "$0")/.." && pwd)"
ROOT="$(mktemp -d /tmp/vibes-pkg-XXXXXX)"
trap 'rm -rf "$ROOT"' EXIT
pass=0; fail=0
ok()   { echo "  PASS  $1"; pass=$((pass+1)); }
bad()  { echo "  FAIL  $1${2:+ -- $2}"; fail=$((fail+1)); }

P="$ROOT/projects/demo-mod"
mkdir -p "$P/.vibes/builds" "$P/bin/Release/net472" "$P/src" "$ROOT/downloads"
cat > "$P/manifest.json" <<'EOF'
{"name":"DemoMod","version_number":"0.3.1","website_url":"","description":"A demo.","dependencies":["denikson-BepInExPack_Valheim-5.4.2202"]}
EOF
echo "# DemoMod" > "$P/README.md"
echo "# Changelog" > "$P/CHANGELOG.md"
cp "$REPO/container/engine/tools/vibes/templates/basic-plugin/icon.png" "$P/icon.png"
echo '{"slug":"demo-mod","name":"DemoMod","namespace":"MyServer"}' > "$P/.vibes/project.json"
echo '{"ok":true,"errors":[],"warnings":0,"ms":1,"at":"now","configuration":"Release","log":null}' > "$P/.vibes/builds/last.json"
head -c 4096 /dev/urandom > "$P/bin/Release/net472/DemoMod.dll"
head -c 1024 /dev/urandom > "$P/bin/Release/net472/BepInEx.dll"            # must NOT ship
head -c 1024 /dev/urandom > "$P/bin/Release/net472/UnityEngine.CoreModule.dll" # must NOT ship
head -c 1024 /dev/urandom > "$P/bin/Release/net472/assembly_valheim.dll"    # must NOT ship
head -c 1024 /dev/urandom > "$P/bin/Release/net472/Newtonsoft.Json.dll"     # MUST ship (a real extra library)
head -c 512  /dev/urandom > "$P/bin/Release/net472/DemoMod.pdb"             # never
echo "<Project/>" > "$P/DemoMod.csproj"

OUT=$(php -d display_errors=stderr -r '
define("VIBES_ROOT", $argv[1]);
require $argv[2] . "/dev_tools/vibes-test-lib.php";
vibesTestLoad("package");
$r = vibesPackage("demo-mod");
echo json_encode($r);
' "$ROOT" "$REPO" 2>"$ROOT/stderr") || true
[ -s "$ROOT/stderr" ] && bad "php ran clean" "$(head -3 "$ROOT/stderr")"
SUCCESS=$(echo "$OUT" | php -r 'echo json_decode(stream_get_contents(STDIN), true)["success"] ?? "" ? "1" : "0";')
[ "$SUCCESS" = "1" ] && ok "vibesPackage() succeeded" || bad "vibesPackage() succeeded" "$OUT"

ZIP="$P/dist/MyServer-DemoMod-0.3.1.zip"
[ -s "$ZIP" ] && ok "zip named <Namespace>-<Name>-<version>.zip in dist/" || bad "zip exists at $ZIP"

EXPECTED=$(printf '%s\n' CHANGELOG.md README.md icon.png manifest.json plugins/DemoMod.dll plugins/Newtonsoft.Json.dll | sort)
ACTUAL=$(unzip -Z1 "$ZIP" 2>/dev/null | sort)
if [ "$EXPECTED" = "$ACTUAL" ]; then ok "zip entries are EXACTLY Thunderstore's (+ the one real extra DLL)"; else bad "zip entries exact" "got: $(echo "$ACTUAL" | tr '\n' ' ')"; fi
echo "$ACTUAL" | grep -q '^plugins/BepInEx.dll$' && bad "BepInEx.dll excluded" || ok "BepInEx.dll (framework) excluded"
echo "$ACTUAL" | grep -q 'UnityEngine' && bad "Unity assembly excluded" || ok "UnityEngine.CoreModule.dll excluded"
echo "$ACTUAL" | grep -q 'assembly_valheim' && bad "game assembly excluded" || ok "assembly_valheim.dll excluded"
echo "$ACTUAL" | grep -q '\.pdb$' && bad "pdb excluded" || ok "no .pdb"
echo "$ACTUAL" | grep -q '/$' && bad "no directory entries" || ok "no directory entries (zip -D)"
unzip -p "$ZIP" manifest.json | grep -q '"version_number":"0.3.1"' && ok "manifest.json content is the project's" || bad "manifest content"
[ "$(unzip -p "$ZIP" plugins/DemoMod.dll | wc -c)" = "4096" ] && ok "plugins/DemoMod.dll is the built DLL (4096 bytes)" || bad "DLL bytes"

# Without a CHANGELOG the entry simply is not there -- no empty placeholder.
rm "$P/CHANGELOG.md" "$ZIP"
php -r 'define("VIBES_ROOT", $argv[1]); require $argv[2] . "/dev_tools/vibes-test-lib.php"; vibesTestLoad("package"); vibesPackage("demo-mod");' "$ROOT" "$REPO" >/dev/null 2>&1
unzip -Z1 "$ZIP" 2>/dev/null | grep -q CHANGELOG && bad "optional CHANGELOG omitted when absent" || ok "optional CHANGELOG omitted when absent"

# A failed last build refuses to package a stale DLL.
echo '{"ok":false,"errors":[{"message":"x"}],"warnings":0,"ms":1,"at":"now","configuration":"Release","log":null}' > "$P/.vibes/builds/last.json"
R=$(php -r 'define("VIBES_ROOT", $argv[1]); require $argv[2] . "/dev_tools/vibes-test-lib.php"; vibesTestLoad("package"); echo json_encode(vibesPackage("demo-mod"));' "$ROOT" "$REPO" 2>/dev/null)
echo "$R" | grep -q '"success":false' && echo "$R" | grep -qi 'last build failed' && ok "refuses to package after a failed build" || bad "refuses after failed build" "$R"

# A wrong-size icon is refused before any zip is written.
echo '{"ok":true,"errors":[],"warnings":0,"ms":1,"at":"now","configuration":"Release","log":null}' > "$P/.vibes/builds/last.json"
rm -f "$ZIP"; printf '\x89PNG\r\n\x1a\n\0\0\0\x0dIHDR\0\0\0\x40\0\0\0\x40\x08\x06\0\0\0\0\0\0\0' > "$P/icon.png"
R=$(php -r 'define("VIBES_ROOT", $argv[1]); require $argv[2] . "/dev_tools/vibes-test-lib.php"; vibesTestLoad("package"); echo json_encode(vibesPackage("demo-mod"));' "$ROOT" "$REPO" 2>/dev/null)
echo "$R" | grep -q '256x256' && [ ! -e "$ZIP" ] && ok "64x64 icon refused, no zip written" || bad "icon size gate" "$R"

echo; echo "$pass passed, $fail failed"
[ "$fail" -eq 0 ]
