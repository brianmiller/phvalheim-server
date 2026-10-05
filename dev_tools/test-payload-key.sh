#!/bin/bash
# Oracle test: engine/tools/payloadKey.py answers "are these the same MODS", not "are these
# the same bytes".
#
# This is the bug 2.55 shipped. The client's first question was "does the payload match",
# asked of world_md5 -- the md5 of the zip -- and a repackage rebuilds the zip, so the answer
# was no after every config edit and the 80 KB path was unreachable. Measured live on
# VikingOutlaws: three repackages, three different world_md5 values, three 573 MB downloads.
#
# Every assertion below is paired with a CONTROL that must come out the other way, because
# "the key did not change" is also what a broken key that always returns the same thing says.
#
#   ./test-payload-key.sh

set -u

TOOL="$(cd "$(dirname "$0")/.." && pwd)/container/engine/tools/payloadKey.py"
TMP="$(mktemp -d)"
trap 'rm -rf "$TMP"' EXIT

pass=0; fail=0
ok()  { echo "  PASS  $1"; pass=$((pass+1)); }
bad() { echo "  FAIL  $1"; echo "        $2"; fail=$((fail+1)); }

key() { python3 "$TOOL" "$1" 2>/dev/null; }

# ---- a payload that looks like the real thing -------------------------------------------
build_tree() {
	local root="$1"
	mkdir -p "$root/BepInEx/plugins" "$root/BepInEx/config" "$root/BepInEx/core" "$root/doorstop_libs"
	# A plugin big enough that its CRC is not trivially shared.
	head -c 40000 /dev/urandom > "$root/BepInEx/plugins/BigMod.dll"
	printf 'loader bytes\n'                      > "$root/BepInEx/core/BepInEx.dll"
	printf '[General]\nMaxSailSpeed = 30\n'      > "$root/BepInEx/config/BigMod.cfg"
	printf '[General]\nOther = 1\n'              > "$root/BepInEx/config/Other.cfg"
	printf 'doorstop\n'                          > "$root/doorstop_config.ini"
	printf 'lib\n'                               > "$root/doorstop_libs/libdoorstop.so"
}

zip_tree() {   # $1=tree  $2=output zip
	rm -f "$2"
	( cd "$1" && zip -q -r "$2" ./BepInEx ./doorstop_libs ./doorstop_config.ini )
}

build_tree "$TMP/a"
zip_tree "$TMP/a" "$TMP/one.zip"

K1="$(key "$TMP/one.zip")"
if [ -n "$K1" ]; then
	ok "it produces a key for a payload ($K1)"
else
	bad "it produces a key for a payload" "got nothing; every assertion below is meaningless"
	echo; echo "$pass passed, $fail failed"; exit 1
fi

# ---- 1. a REPACKAGE keeps the key, while the zip's md5 moves -----------------------------
# Reproduces what a repackage actually does: materialiseModConfigs rewrites BepInEx/config, so
# those entries get new timestamps (and usually new content) and the archive's bytes change
# while not one plugin was touched. That is why world_md5 moved three times on VikingOutlaws.
#
# The md5 assertion is the CONTROL. A fixture that re-zips a tree nobody touched produces a
# byte-identical archive -- zip stores each FILE's mtime, not the run's -- so "the key did not
# change" would prove nothing at all. Measured: that was this test's first version, and it
# passed while testing nothing.
sleep 1.1
touch "$TMP/a/BepInEx/config/BigMod.cfg"   # the repackage's fingerprint: config rewritten
zip_tree "$TMP/a" "$TMP/two.zip"
K2="$(key "$TMP/two.zip")"
M1="$(md5sum "$TMP/one.zip" | cut -d' ' -f1)"
M2="$(md5sum "$TMP/two.zip" | cut -d' ' -f1)"

if [ "$M1" != "$M2" ]; then
	ok "CONTROL: a repackage really does change the zip's md5 (world_md5 moves)"
else
	bad "CONTROL: a repackage changes the zip's md5" \
	    "both zips hashed $M1 -- this fixture cannot see the bug, so the next assertion proves nothing"
fi
if [ "$K1" = "$K2" ]; then
	ok "the key is UNCHANGED by that repackage"
else
	bad "the key is unchanged by that repackage" \
	    "$K1 vs $K2 -- a client would re-download 573 MB for a repackage that changed no mods"
fi

# A plugin's TIMESTAMP is not its content either -- the key is CRC plus size, nothing else.
sleep 1.1
touch "$TMP/a/BepInEx/plugins/BigMod.dll"
zip_tree "$TMP/a" "$TMP/touched.zip"
KT="$(key "$TMP/touched.zip")"
if [ "$KT" = "$K1" ]; then
	ok "touching a plugin without changing it leaves the key alone"
else
	bad "touching a plugin leaves the key alone" "$K1 -> $KT: the key is reading timestamps"
fi

# ---- 2. a CONFIG edit does not move the key ---------------------------------------------
printf '[General]\nMaxSailSpeed = 45\n' > "$TMP/a/BepInEx/config/BigMod.cfg"
zip_tree "$TMP/a" "$TMP/cfg.zip"
KC="$(key "$TMP/cfg.zip")"
if [ "$KC" = "$K1" ]; then
	ok "a config edit leaves the key alone (the whole point: 80 KB, not 573 MB)"
else
	bad "a config edit leaves the key alone" "$K1 -> $KC"
fi

# ---- 3. a PLUGIN change DOES move it ----------------------------------------------------
# Without this the key could be a constant and assertions 1-2 would still pass.
head -c 40000 /dev/urandom > "$TMP/a/BepInEx/plugins/BigMod.dll"
zip_tree "$TMP/a" "$TMP/mod.zip"
KM="$(key "$TMP/mod.zip")"
if [ "$KM" != "$K1" ]; then
	ok "changing a plugin's CONTENT moves the key"
else
	bad "changing a plugin's content moves the key" "still $KM -- the key is blind to the mods"
fi

# ---- 4. adding and removing a mod both move it ------------------------------------------
cp "$TMP/a/BepInEx/plugins/BigMod.dll" "$TMP/a/BepInEx/plugins/Added.dll"
zip_tree "$TMP/a" "$TMP/add.zip"
KA="$(key "$TMP/add.zip")"
if [ "$KA" != "$KM" ]; then
	ok "adding a mod moves the key"
else
	bad "adding a mod moves the key" "still $KA"
fi

rm -f "$TMP/a/BepInEx/plugins/Added.dll" "$TMP/a/BepInEx/plugins/BigMod.dll"
zip_tree "$TMP/a" "$TMP/del.zip"
KD="$(key "$TMP/del.zip")"
if [ "$KD" != "$KA" ] && [ "$KD" != "$KM" ]; then
	ok "removing mods moves the key"
else
	bad "removing mods moves the key" "$KD matched an earlier state"
fi

# ---- 5. a config-only payload has no mod identity ---------------------------------------
# The config archive itself must not yield a key -- if it did, and anything ever stamped
# mods_md5 from the wrong file, every client would think it held the whole modpack.
mkdir -p "$TMP/conly/BepInEx/config"
printf '[General]\nX = 1\n' > "$TMP/conly/BepInEx/config/Only.cfg"
rm -f "$TMP/conly.zip"
( cd "$TMP/conly" && zip -q -r "$TMP/conly.zip" ./BepInEx/config )
if ! python3 "$TOOL" "$TMP/conly.zip" > /dev/null 2>&1; then
	ok "a config-only archive yields NO key (nothing but config in it)"
else
	bad "a config-only archive yields no key" "it returned $(key "$TMP/conly.zip")"
fi

# ---- 6. failures are silent and non-zero, never a plausible value ------------------------
printf 'not a zip at all' > "$TMP/junk.zip"
if ! python3 "$TOOL" "$TMP/junk.zip" > /dev/null 2>&1 && [ -z "$(key "$TMP/junk.zip")" ]; then
	ok "a corrupt payload yields nothing and a non-zero exit"
else
	bad "a corrupt payload yields nothing" \
	    "it printed '$(key "$TMP/junk.zip")' -- the engine would stamp that as a real key"
fi
if ! python3 "$TOOL" "$TMP/nope.zip" > /dev/null 2>&1; then
	ok "a missing payload yields a non-zero exit"
else
	bad "a missing payload yields a non-zero exit" "it succeeded"
fi

echo
echo "$pass passed, $fail failed"
[ "$fail" -eq 0 ] || exit 1
