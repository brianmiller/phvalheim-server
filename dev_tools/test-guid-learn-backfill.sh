#!/bin/bash
# Oracle test: every world's plugin GUIDs get learned -- not only the ones rebuilt since 2.55.
#
# THE REPORT (Discord, 2026-10-19)
# Grantapher-ValheimPlus_Grantapher_Temporary's config, org.bepinex.plugins.valheim_plus.cfg,
# sat under "Unmatched config files" with 471 settings (166 changed) while the mod itself was
# listed as having "no config file yet". The settings applied and reached players; only the
# attribution was missing.
#
# The DLL declares [BepInPlugin("org.bepinex.plugins.valheim_plus", ...)] -- exactly the file
# name -- and pluginGuids.py reads it correctly. What failed is WHEN it is read: only after a
# full mod install or an "Apply to players". A world installed before 2.55 has its zips cached
# and its GUIDs unknown, and name matching cannot rescue a fork whose package name
# (valheimplusgrantaphertemporary) resembles nothing in the file. Reproduced in a container:
# the same file is Unmatched without the GUID row and attributed with it.
#
# THE FIX, and what each case below pins
#   1. learn() reads a package's cached zip                              (unchanged path)
#   2. ...and when the zip is GONE, its installed copy under
#      BepInEx/plugins/<package name>/ instead of silently skipping it   (new)
#   3. control: no zip and no installed copy learns nothing -- so case 2 is the fallback
#      working, not something else answering
#   4. dbUpdate_2.56.sh learns for EVERY world on boot, and BEFORE it re-attributes --
#      re-attributing first would run with nothing new to work from
#
# Each new decision has a mutation control: the tool is copied, one line is broken, and the case
# it claims to cover must fail. A control that silently fails to apply is reported, not passed.
#
#   ./test-guid-learn-backfill.sh
set -u

ROOT="$(cd "$(dirname "$0")/.." && pwd)"
SRC="$ROOT/container/engine/tools/pluginGuids.py"
MIG="$ROOT/container/engine/dbUpdates/dbUpdate_2.56.sh"
TMP="$(mktemp -d)"
trap 'rm -rf "$TMP"' EXIT

pass=0; fail=0
ok()  { echo "  PASS  $1"; pass=$((pass+1)); }
bad() { echo "  FAIL  $1"; echo "        $2"; fail=$((fail+1)); }

# ---- fixture: three packages on one world ---------------------------------------------------
#   mod 1  zip cached                         declares a.guid
#   mod 2  zip GONE, installed copy on disk   declares org.bepinex.plugins.valheim_plus
#   mod 3  zip GONE, nothing installed        (nothing to learn from)
python3 - "$TMP" <<'PY'
import os, sys, zipfile
t = sys.argv[1]
def ser(s):
    b = s.encode(); assert len(b) < 0x80
    return bytes([len(b)]) + b
def dll(guid, name, ver="1.0.0"):
    return (b"MZ" + b"\x00" * 64 + b"\x01\x00" + ser(guid) + ser(name) + ser(ver)
            + b"\x00\x00" + b"\xff" * 16)
os.makedirs(f"{t}/cache", exist_ok=True)
with zipfile.ZipFile(f"{t}/cache/thunderstore-a-PkgA-1.0.0.zip", "w") as z:
    z.writestr("BepInEx/plugins/PkgA.dll", dll("a.guid", "Pkg A"))
inst = f"{t}/worlds/w/game/BepInEx/plugins/ValheimPlus_Grantapher_Temporary"
os.makedirs(inst, exist_ok=True)
open(f"{inst}/ValheimPlus.dll", "wb").write(dll("org.bepinex.plugins.valheim_plus", "Valheim Plus", "0.10.3.0"))
PY

# A stand-in worldMods.py: the real plan's 11 tab-separated fields, field 6 the cache name.
mkdir -p "$TMP/tools"
cat > "$TMP/tools/worldMods.py" <<'PY'
import sys
rows = [
    ("thunderstore", "a", "PkgA", "1.0.0", "u", "thunderstore-a-PkgA-1.0.0.zip", "latest", "0", "1", "1", "1"),
    ("thunderstore", "Grantapher", "ValheimPlus_Grantapher_Temporary", "10.3.0", "u",
     "thunderstore-Grantapher-ValheimPlus_Grantapher_Temporary-10.3.0.zip", "latest", "0", "2", "1", "1"),
    ("thunderstore", "c", "PkgC", "1.0.0", "u", "thunderstore-c-PkgC-1.0.0.zip", "latest", "0", "3", "1", "1"),
]
for r in rows:
    print("\t".join(r))
PY

# Run learn() from a copy of the tool (optionally mutated), with the database replaced by a
# recorder. Prints the (mod_id, guid) pairs it would INSERT, one per line, sorted.
run_learn() {
	local src="$1"
	cp "$src" "$TMP/tools/pluginGuids.py"
	python3 - "$TMP" <<'PY'
import importlib.util, re, sys
t = sys.argv[1]
spec = importlib.util.spec_from_file_location("pg", f"{t}/tools/pluginGuids.py")
pg = importlib.util.module_from_spec(spec); spec.loader.exec_module(pg)
pg.TS_MODS_DIR = f"{t}/cache"
pg.WORLDS_ROOT = f"{t}/worlds"
inserted = []
pg.rows = lambda q: []
def sql(stmt, fetch=False):
    m = re.search(r"VALUES \((\d+), '([^']*)'", stmt)
    if m: inserted.append(f"{m.group(1)} {m.group(2)}")
    return ""
pg.sql = sql
pg.learn("w")
print("\n".join(sorted(inserted)))
PY
}

echo
echo "pluginGuids.py --learn: every package, cached or not"

got=$(run_learn "$SRC")
case "$got" in *"1 a.guid"*) ok "1. a cached zip is read (mod 1 -> a.guid)" ;;
	*) bad "1. a cached zip is read" "got: $got" ;; esac
case "$got" in *"2 org.bepinex.plugins.valheim_plus"*) ok "2. zip GONE: learned from the installed copy (mod 2 -> org.bepinex.plugins.valheim_plus)" ;;
	*) bad "2. zip gone: learned from the installed copy" "got: $got" ;; esac
case "$got" in *"3 "*) bad "3. control: nothing to learn from yields nothing" "got: $got" ;;
	*) ok "3. control: no zip and no installed copy learns nothing for mod 3" ;; esac
[ "$(printf '%s\n' "$got" | grep -c .)" = "2" ] \
	&& ok "   exactly two rows -- nothing invented" \
	|| bad "   exactly two rows" "got: $got"

echo
echo "mutation controls -- each must break the case it covers"
mutate() {  # label, find, replace, the line that must then be ABSENT from the output
	local label="$1" find="$2" repl="$3" gone="$4" n
	n=$(python3 -c 'import sys; print(open(sys.argv[1]).read().count(sys.argv[2]))' "$SRC" "$find")
	if [ "$n" != "1" ]; then bad "mutation applies exactly once: $label" "found $n -- the control is vacuous"; return; fi
	python3 -c 'import sys; s=open(sys.argv[1]).read(); open(sys.argv[2],"w").write(s.replace(sys.argv[3], sys.argv[4]))' \
		"$SRC" "$TMP/mut.py" "$find" "$repl"
	got=$(run_learn "$TMP/mut.py")
	case "$got" in *"$gone"*) bad "$label => case 2 breaks" "still learned '$gone'" ;;
		*) ok "$label => case 2 breaks" ;; esac
}
mutate "no installed-copy fallback" \
	'declared = guids_in_zip(path) if path else guids_installed(world, pkg_name)' \
	'declared = guids_in_zip(path) if path else []' \
	"2 org.bepinex.plugins.valheim_plus"
mutate "a package with no cached zip is dropped from the plan (the old world_zips)" \
	'out.append((int(mod_id), path if os.path.isfile(path) else None, name))' \
	'out.append((int(mod_id), path, name)) if os.path.isfile(path) else None' \
	"2 org.bepinex.plugins.valheim_plus"

echo
echo "dbUpdate_2.56.sh: learn for every world, THEN re-attribute"
# Order checked on the command lines themselves, never on comments that mention them.
order_ok() {
	local f="$1" l r
	l=$(grep -nE '^if ! /opt/stateless/engine/tools/pluginGuids.py --all --learn; then' "$f" | head -1 | cut -d: -f1)
	r=$(grep -nE '^if ! /opt/stateless/engine/tools/modConfigs.py --reattribute --all; then' "$f" | tail -1 | cut -d: -f1)
	[ -n "$l" ] && [ -n "$r" ] && [ "$l" -lt "$r" ]
}
order_ok "$MIG" && ok "4. the boot backfill learns (--all --learn) before it re-attributes" \
	|| bad "4. learn before re-attribute in dbUpdate_2.56.sh" "missing, or in the wrong order"
# Control: the same check on a copy with the learn step removed must fail.
grep -vE '^if ! /opt/stateless/engine/tools/pluginGuids.py --all --learn; then' "$MIG" > "$TMP/mig.sh"
order_ok "$TMP/mig.sh" && bad "   control: the order check can fail" "it passed with the learn step deleted" \
	|| ok "   control: with the learn step deleted, the order check fails"

echo
echo "  $pass passed, $fail failed"
[ "$fail" -eq 0 ]
