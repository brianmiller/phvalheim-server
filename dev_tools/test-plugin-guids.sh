#!/bin/bash
# Oracle test: pluginGuids.py reads a plugin's GUID out of an assembly, and does NOT invent one.
#
# THE BUG THIS EXISTS FOR
# A mod's config file is named after its BepInEx plugin GUID -- an author-chosen string -- while
# the catalogue knows the package name. attribute() could only compare the two and hope, so it
# missed every mod where the author disagreed with the package: on one real world, 4 of 58
# configs, including package SkillInjector declaring GUID com.pipakin.SkillInjectorMod.
#
# WHAT WOULD MAKE THIS TEST WORTHLESS
# A text search for "com.something" finds the right answer in the right DLL and ALSO finds
# another mod's GUID inside a soft-dependency check -- `PluginInfos.ContainsKey("com.jotunn
# .jotunn")` -- which would attribute that mod's config to whichever package mentioned it.
# So most of the assertions below are NEGATIVE: blobs that look almost right must yield
# nothing. A test that only proved "it finds the GUID" would pass against exactly the
# implementation this one is here to rule out.
#
#   ./test-plugin-guids.sh
set -u

TOOL="$(cd "$(dirname "$0")/.." && pwd)/container/engine/tools/pluginGuids.py"
TMP="$(mktemp -d)"
trap 'rm -rf "$TMP"' EXIT

pass=0; fail=0
ok()  { echo "  PASS  $1"; pass=$((pass+1)); }
bad() { echo "  FAIL  $1"; echo "        $2"; fail=$((fail+1)); }

# Builds a byte blob the way a .NET custom-attribute blob is laid out:
#   01 00  <len>guid  <len>name  <len>version  00 00
# plus whatever padding/decoys the caller asks for. Python, because this is byte surgery.
mkblob() { python3 - "$@" ; }

# ---- 1. the real shape is found ---------------------------------------------------------
mkblob > "$TMP/good.bin" <<'PY'
import sys
def ser(s):
    b = s.encode(); assert len(b) < 0x80
    return bytes([len(b)]) + b
blob = b"\x01\x00" + ser("com.pipakin.SkillInjectorMod") + ser("SkillInjectorMod") + ser("1.1.1") + b"\x00\x00"
# Surrounded by noise, as it is in a real assembly.
sys.stdout.buffer.write(b"MZ" + b"\x00" * 64 + b"random text here" + blob + b"\xff" * 32)
PY
got=$(python3 "$TOOL" --dll "$TMP/good.bin")
if [ "$got" = "$(printf 'com.pipakin.SkillInjectorMod\tSkillInjectorMod\t1.1.1')" ]; then
	ok "it reads guid, name and version from a BepInPlugin blob"
else
	bad "it reads a BepInPlugin blob" "got '$got'"
fi

# ---- 2. NEGATIVE: a bare GUID mention is not a declaration ------------------------------
# This is the soft-dependency case, and the whole reason the tool parses a blob instead of
# grepping for text.
mkblob > "$TMP/mention.bin" <<'PY'
import sys
sys.stdout.buffer.write(b"MZ" + b"\x00"*32
    + b"com.jotunn.jotunn"          # a plain string reference, as a dep check compiles to
    + b"\x00\x00" + b"\xab"*16)
PY
got=$(python3 "$TOOL" --dll "$TMP/mention.bin")
if [ -z "$got" ]; then
	ok "NEGATIVE: a plain GUID string in the file yields nothing"
else
	bad "NEGATIVE: a plain GUID mention yields nothing" \
	    "got '$got' -- every mod that soft-depends on another would steal its config"
fi

# ---- 3. NEGATIVE: three strings are not enough; the third must be a version -------------
mkblob > "$TMP/notversion.bin" <<'PY'
import sys
def ser(s):
    b=s.encode(); return bytes([len(b)])+b
# A plausible three-string attribute that is NOT BepInPlugin, e.g. an AssemblyMetadata or a
# custom [Documentation(a,b,c)].
sys.stdout.buffer.write(b"\x01\x00" + ser("com.example.thing") + ser("Thing") + ser("not-a-version") + b"\x00\x00")
PY
got=$(python3 "$TOOL" --dll "$TMP/notversion.bin")
if [ -z "$got" ]; then
	ok "NEGATIVE: a three-string attribute without a version yields nothing"
else
	bad "NEGATIVE: third argument must look like a version" "got '$got'"
fi

# ---- 4. NEGATIVE: named arguments mean it is not BepInPlugin ----------------------------
# The trailing 00 00 is the named-argument count. Without this check, the first three strings
# of a LONGER attribute would be read as a plugin declaration.
mkblob > "$TMP/named.bin" <<'PY'
import sys
def ser(s):
    b=s.encode(); return bytes([len(b)])+b
sys.stdout.buffer.write(b"\x01\x00" + ser("com.example.plug") + ser("Plug") + ser("1.0.0")
                        + b"\x01\x00" + ser("Extra"))   # one named argument follows
PY
got=$(python3 "$TOOL" --dll "$TMP/named.bin")
if [ -z "$got" ]; then
	ok "NEGATIVE: an attribute with named arguments yields nothing"
else
	bad "NEGATIVE: named arguments disqualify the blob" "got '$got'"
fi

# ---- 5. NEGATIVE: binary garbage that decodes as text is rejected ------------------------
mkblob > "$TMP/garbage.bin" <<'PY'
import sys, os
sys.stdout.buffer.write(b"\x01\x00\x10" + bytes(range(1, 17)) + b"\x00\x00" + os.urandom(64))
PY
got=$(python3 "$TOOL" --dll "$TMP/garbage.bin")
if [ -z "$got" ]; then
	ok "NEGATIVE: control characters are not an identifier"
else
	bad "NEGATIVE: control characters rejected" "got '$got'"
fi

# ---- 6. a package shipping TWO plugins reports both -------------------------------------
# The case no name match can ever handle: one package, several configs.
mkblob > "$TMP/two.bin" <<'PY'
import sys
def ser(s):
    b=s.encode(); return bytes([len(b)])+b
def blob(g,n,v): return b"\x01\x00"+ser(g)+ser(n)+ser(v)+b"\x00\x00"
sys.stdout.buffer.write(b"pad"+blob("a.b.One","One","1.0")+b"pad"+blob("a.b.Two","Two","2.0.1"))
PY
got=$(python3 "$TOOL" --dll "$TMP/two.bin" | wc -l)
if [ "$got" = "2" ]; then
	ok "both plugins in one assembly are reported"
else
	bad "both plugins in one assembly are reported" "got $got line(s)"
fi

# ---- 7. a zip is read, and the loader's own pack is skipped ------------------------------
# A mod zip that bundles BepInExPack must not claim the loader's GUIDs -- that would attribute
# BepInEx.cfg to whichever mod happened to bundle it.
mkdir -p "$TMP/z/plugins" "$TMP/z/BepInExPack_Valheim/BepInEx/core"
cp "$TMP/good.bin" "$TMP/z/plugins/Mod.dll"
cp "$TMP/two.bin"  "$TMP/z/BepInExPack_Valheim/BepInEx/core/Loader.dll"
( cd "$TMP/z" && zip -q -r "$TMP/mod.zip" . )
got=$(python3 "$TOOL" --zip "$TMP/mod.zip")
if [ "$(echo "$got" | wc -l)" = "1" ] && echo "$got" | grep -q SkillInjectorMod; then
	ok "a zip reports its own plugin and skips a bundled BepInEx pack"
else
	bad "a zip reports its plugin and skips the loader pack" "got '$got'"
fi

# ---- 8. non-DLL members are not read ----------------------------------------------------
# A .cfg or README that happens to contain the blob bytes is not a declaration.
mkdir -p "$TMP/z2"
cp "$TMP/good.bin" "$TMP/z2/README.txt"
( cd "$TMP/z2" && zip -q -r "$TMP/nodll.zip" . )
if [ -z "$(python3 "$TOOL" --zip "$TMP/nodll.zip")" ]; then
	ok "NEGATIVE: only .dll members are read"
else
	bad "NEGATIVE: only .dll members are read" "it read a non-assembly file"
fi

# ---- 9. an unreadable zip is silent, not fatal ------------------------------------------
printf 'not a zip' > "$TMP/broken.zip"
python3 "$TOOL" --zip "$TMP/broken.zip" > "$TMP/out" 2>/dev/null
if [ ! -s "$TMP/out" ]; then
	ok "a corrupt zip yields no rows (install must not fail over it)"
else
	bad "a corrupt zip yields no rows" "printed '$(cat "$TMP/out")'"
fi

# ---- 10. attribute() prefers the GUID, and still falls back without one -----------------
# The payoff. These four cases are the real world's four unmatched files, by shape:
# a GUID that disagrees with the package name, a filename-is-the-GUID with no header, a
# second plugin from one package, and no GUID map at all (a world not yet packaged).
MC="$(cd "$(dirname "$0")/.." && pwd)/container/engine/tools/modConfigs.py"
python3 - "$MC" > "$TMP/attr.out" 2>&1 <<'PY'
import importlib.util, sys
spec = importlib.util.spec_from_file_location("mc", sys.argv[1])
mc = importlib.util.module_from_spec(spec); spec.loader.exec_module(mc)

# Package "SkillInjector" is mod 7; the author called the plugin SkillInjectorMod. The name
# match CANNOT see this -- that is the bug.
cat = {"skillinjector": 7, "pipakinskillinjector": 7}
guids = {"com.pipakin.SkillInjectorMod": 7, "a.b.Two": 9}

hdr = {"plugin": "SkillInjectorMod", "guid": "com.pipakin.SkillInjectorMod", "entries": []}
print("guid_wins", mc.attribute("com.pipakin.SkillInjectorMod.cfg", hdr, cat, guids))
print("name_only", mc.attribute("com.pipakin.SkillInjectorMod.cfg", hdr, cat, None))

# No parsable header, but BepInEx named the file after the GUID, so it is still answerable.
print("stem_guid", mc.attribute("a.b.Two.cfg", {"plugin": None, "guid": None}, cat, guids))

# A GUID the world's catalogue does not know falls through to the name match, which here
# succeeds on the stem.
print("fallthrough", mc.attribute("SkillInjector.cfg", {"plugin": None, "guid": "x.y.z"}, cat, guids))

# Ambiguity: guid_owners() drops a GUID two packages claim, so attribution must say "unknown"
# rather than pick one.
print("ambiguous", mc.attribute("d.e.f.cfg", {"plugin": None, "guid": "d.e.f"}, cat, {}))
PY
exp=$'guid_wins 7\nname_only None\nstem_guid 9\nfallthrough 7\nambiguous None'
if [ "$(cat "$TMP/attr.out")" = "$exp" ]; then
	ok "attribute(): guid wins, filename-as-guid works, unknown guid falls back, ambiguity is None"
else
	bad "attribute() precedence" "got:
$(sed 's/^/          /' "$TMP/attr.out")
        want:
$(echo "$exp" | sed 's/^/          /')"
fi
# The CONTROL is `name_only None` above: it is the pre-fix behaviour, so if it ever starts
# returning 7 the fixture has stopped reproducing the bug and the first case proves nothing.

# ---- 11. guids_on_disk() reads the world's own assemblies -------------------------------
# This is what covers the engine-installed plugins: the Companion and anything from
# custom_plugins/ have no catalogue row at all.
mkdir -p "$TMP/tree/plugins/SomeMod" "$TMP/tree/patchers" "$TMP/tree/core"
cp "$TMP/good.bin" "$TMP/tree/plugins/SomeMod/SomeMod.dll"
cp "$TMP/two.bin"  "$TMP/tree/patchers/Patch.dll"
cp "$TMP/two.bin"  "$TMP/tree/core/BepInEx.dll"      # loader: must be ignored
got=$(python3 - "$TOOL" "$TMP/tree" <<'PY'
import importlib.util, sys
spec = importlib.util.spec_from_file_location("pg", sys.argv[1])
pg = importlib.util.module_from_spec(spec); spec.loader.exec_module(pg)
print(",".join(sorted(pg.guids_on_disk(sys.argv[2]))))
PY
)
if [ "$got" = "a.b.One,a.b.Two,com.pipakin.SkillInjectorMod" ]; then
	ok "guids_on_disk reads plugins/ and patchers/ and ignores core/"
else
	bad "guids_on_disk reads plugins and patchers, not core" "got '$got'"
fi

# ---- 12. the manufactured-config fix, with its control ----------------------------------
# materialise() invents a cfg file from saved rows when the file is missing -- correct for a
# mod that has not run yet, wrong for a mod the world does not have. On a real world that
# re-created zolantris.ValheimRAFT.cfg on every update, for a RAFT that is not installed,
# and shipped it to every player: 40 such creations in one log.
#
# The CONTROL is `unrun_mod True`. It is the case the fix must not break, and a fix that
# simply stopped creating files would fail it while passing every other assertion here.
python3 - "$MC" > "$TMP/claim.out" 2>&1 <<'PY'
import importlib.util, sys
spec = importlib.util.spec_from_file_location("mc", sys.argv[1])
mc = importlib.util.module_from_spec(spec); spec.loader.exec_module(mc)

cat  = {"skillinjector": 7}                       # the world has package SkillInjector
guids = {"com.pipakin.SkillInjectorMod": 7}       # which declares this GUID
disk = {"posixone_PhValheimCompanion"}            # engine-installed, in no catalogue

# The mod is installed but has not written its config yet -- MUST still be created.
print("unrun_mod", mc.cfg_is_claimed("com.pipakin.SkillInjectorMod.cfg", cat, guids, disk))
# Engine-installed plugin, no catalogue row, no learned GUID: the assembly on disk is the
# only evidence there is.
print("companion", mc.cfg_is_claimed("posixone_PhValheimCompanion.cfg", cat, guids, disk))
# The bug: rows for a mod this world does not have.
print("orphan", mc.cfg_is_claimed("zolantris.ValheimRAFT.cfg", cat, guids, disk))
# Knowing nothing is not evidence of absence -- fail open.
print("no_knowledge", mc.cfg_is_claimed("zolantris.ValheimRAFT.cfg", {}, {}, set()))
PY
exp=$'unrun_mod True\ncompanion True\norphan False\nno_knowledge True'
if [ "$(cat "$TMP/claim.out")" = "$exp" ]; then
	ok "cfg_is_claimed: unrun mod yes, engine plugin yes, orphan NO, unknown fails open"
else
	bad "cfg_is_claimed decisions" "got:
$(sed 's/^/          /' "$TMP/claim.out")
        want:
$(echo "$exp" | sed 's/^/          /')"
fi

# And materialise must actually consult it, on the create path.
if grep -q "if not cfg_is_claimed(cfg_file, catalogue, guids, disk_guids):" \
     "$(dirname "$MC")/modConfigs.py"; then
	ok "materialise() gates the create branch on cfg_is_claimed"
else
	bad "materialise() gates the create branch" "the decision function is never called"
fi

echo
echo "$pass passed, $fail failed"
[ "$fail" -eq 0 ] || exit 1
