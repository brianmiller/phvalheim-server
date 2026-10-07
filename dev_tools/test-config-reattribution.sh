#!/bin/bash
# Oracle test: a config file whose owner became knowable later stops reading "Unattributed".
#
# THE BUG THIS EXISTS FOR
# mod_config_overrides.mod_id is ATTRIBUTION and it is written exactly once -- by attribute(),
# at the moment a row is saved. The exact half of that answer lives in mod_plugin_guids, which
# is populated when a mod is INSTALLED. So a row written before its world's GUIDs were known
# (every row the pre-2.55 legacy import created) stored NULL, and nothing ever revisited it.
#
# The settings still apply -- the table is keyed on the file, not the mod -- but the row files
# under "Unattributed" in the editor and is absent from its mod's Config badge, which counts
# the stored column: SELECT mod_id ... WHERE mod_id IS NOT NULL GROUP BY mod_id. Measured on a
# real world: flueno.SmartContainers.cfg, whose GUID now maps to an installed mod, read
# Unattributed and the badge undercounted.
#
# WHAT WOULD MAKE THIS TEST WORTHLESS, and the control for each
#
#  1. "It filled in the NULLs" is the WRONG assertion. NULL is a legitimate, permanent state
#     for three classes of file -- a custom_plugins/ drop, the loader, an operator file with no
#     catalogue entry (see the WHY block in dbUpdate_2.55.sh). A pass that filled every blank
#     would be the actual regression: it files settings under a mod that never reads them. T4
#     is that control and T3 is the ambiguous-GUID control; both assert NOTHING is written.
#
#  2. A pass that could overwrite an existing owner would silently move rows the operator has
#     already seen filed under a mod. T2 asserts the UPDATE still carries `mod_id IS NULL`, so
#     the guard is in the statement and not only in the row list it was handed.
#
#  3. A function nothing calls. T6/T7/T8 are the doors: materialise() calls it (so it heals on
#     the next update), the migration calls it on EVERY boot (not gated on the one-shot import
#     flag, because a GUID can become knowable at any later install), and --reattribute
#     dispatches. Each of those reports FAILURE if it cannot locate its target rather than
#     passing vacuously.
#
#   ./test-config-reattribution.sh
set -u

ROOT="$(cd "$(dirname "$0")/.." && pwd)"
MC="$ROOT/container/engine/tools/modConfigs.py"
ENGINE="$ROOT/container/engine/dbUpdates/dbUpdate_2.55.sh"
TMP="$(mktemp -d)"
trap 'rm -rf "$TMP"' EXIT

pass=0; fail=0
ok()  { echo "  PASS  $1"; pass=$((pass+1)); }
bad() { echo "  FAIL  $1"; echo "        $2"; fail=$((fail+1)); }

CFG="$TMP/config"
mkdir -p "$CFG"

# On disk, named after the GUID that wrote it -- the ordinary case.
cat > "$CFG/flueno.SmartContainers.cfg" <<'EOF'
## Settings file was created by plugin SmartContainers v1.4.0
## Plugin GUID: flueno.SmartContainers

[General]

# Setting type: Boolean
# Default value: true
Enabled = false
EOF

# A file whose NAME tells us nothing but whose HEADER names its GUID.
cat > "$CFG/legacy_handcopied.cfg" <<'EOF'
## Settings file was created by plugin Basement v1.0.2
## Plugin GUID: com.rolopogo.Basement

[Basement]

# Setting type: String
# Default value: None
Crafting Station = None
EOF

# An operator's drop in custom_plugins/: no catalogue entry, no world_mods row, never
# attributable. THIS IS THE CONTROL FILE -- its mod_id must stay NULL forever.
cat > "$CFG/ValheimFoodConfig.cfg" <<'EOF'
## Settings file was created by plugin ValheimFoodConfig v1.0.0
## Plugin GUID: ValheimFoodConfig

[Carrot]

# Setting type: Int32
# Default value: 1800
Duration = 3600
EOF

run_py() { python3 - "$MC" "$CFG" "$@"; }

# ---------------------------------------------------------------------------------------
# T1  a row whose filename stem is a GUID this world's mods declare gets attributed
# ---------------------------------------------------------------------------------------
out=$(run_py <<'PY'
import importlib.util, sys
spec = importlib.util.spec_from_file_location("mc", sys.argv[1])
mc = importlib.util.module_from_spec(spec); spec.loader.exec_module(mc)
CFG = sys.argv[2]

stmts = []
mc.server_config_dir = lambda w: CFG
mc.rows = lambda qy: [["flueno.SmartContainers.cfg"]] if "mod_config_overrides" in qy else []
mc.sql  = lambda s, fetch=False: (stmts.append(s), "")[1]

n = mc.reattribute_unowned("W", 7, {}, {"flueno.SmartContainers": 4213})
ups = [s for s in stmts if s.startswith("UPDATE mod_config_overrides")]
print("fixed:", n)
print("updates:", len(ups))
print("sets4213:", sum(1 for s in ups if "SET mod_id = 4213" in s))
print("scoped:", sum(1 for s in ups if "world_id = 7" in s
                     and "flueno.SmartContainers.cfg" in s))
print("guarded:", sum(1 for s in ups if "mod_id IS NULL" in s))
PY
)
got() { echo "$out" | sed -n "s/^$1: //p"; }
if [ "$(got fixed)" = "1" ] && [ "$(got sets4213)" = "1" ] && [ "$(got scoped)" = "1" ]; then
	ok "T1  a now-knowable GUID is attributed to its mod, scoped to the world and the file"
else
	bad "T1  the row was not attributed" "$out"
fi

# ---------------------------------------------------------------------------------------
# T2  the UPDATE itself refuses to overwrite an owner
# ---------------------------------------------------------------------------------------
if [ "$(got guarded)" = "1" ]; then
	ok "T2  the UPDATE repeats 'mod_id IS NULL' -- it cannot move a row that has an owner"
elif [ -z "$(got updates)" ]; then
	bad "T2  no UPDATE was emitted at all, so this check cannot tell whether an existing owner is safe -- treat it as failing" "$out"
else
	bad "T2  the UPDATE is unguarded: it would overwrite an existing mod_id" "$out"
fi

# ---------------------------------------------------------------------------------------
# T3  a GUID two of the world's mods declare is left alone, not guessed between
# ---------------------------------------------------------------------------------------
out3=$(run_py <<'PY'
import importlib.util, sys
spec = importlib.util.spec_from_file_location("mc", sys.argv[1])
mc = importlib.util.module_from_spec(spec); spec.loader.exec_module(mc)
CFG = sys.argv[2]

stmts = []
mc.server_config_dir = lambda w: CFG
mc.rows = lambda qy: [["flueno.SmartContainers.cfg"]] if "mod_config_overrides" in qy else []
mc.sql  = lambda s, fetch=False: (stmts.append(s), "")[1]

# guid_owners() drops a GUID two packages claim, so it never reaches attribute(). The
# catalogue holds two name-matches for the same handle, which is the other way to be
# ambiguous -- attribute() returns None for both.
n = mc.reattribute_unowned("W", 7, {"smartcontainers": 1, "fluenosmartcontainers": 2}, {})
print("fixed:", n)
print("updates:", len([s for s in stmts if s.startswith("UPDATE")]))
PY
)
f3=$(echo "$out3" | sed -n 's/^fixed: //p')
if [ -z "$f3" ]; then
	bad "T3  the pass did not run, so this check cannot tell whether an ambiguous owner is left alone -- treat it as failing" "$out3"
elif [ "$f3" = "0" ] && [ "$(echo "$out3" | sed -n 's/^updates: //p')" = "0" ]; then
	ok "T3  an ambiguous owner writes nothing -- a wrong owner is worse than none"
else
	bad "T3  an ambiguous file was attributed anyway" "$out3"
fi

# ---------------------------------------------------------------------------------------
# T4  CONTROL: a custom_plugins/ drop stays NULL -- this is not "fill in the blanks"
# ---------------------------------------------------------------------------------------
out4=$(run_py <<'PY'
import importlib.util, sys
spec = importlib.util.spec_from_file_location("mc", sys.argv[1])
mc = importlib.util.module_from_spec(spec); spec.loader.exec_module(mc)
CFG = sys.argv[2]

stmts = []
mc.server_config_dir = lambda w: CFG
mc.rows = lambda qy: [["ValheimFoodConfig.cfg"]] if "mod_config_overrides" in qy else []
mc.sql  = lambda s, fetch=False: (stmts.append(s), "")[1]

# A real world's live state: the mod IS installed and its settings DO apply, but it came from
# custom_plugins/ so it has no catalogue entry and no world_mods row to attribute it to.
n = mc.reattribute_unowned("W", 7, {"somethingelse": 9}, {"other.guid": 9})
print("fixed:", n)
print("updates:", len([s for s in stmts if s.startswith("UPDATE")]))
PY
)
f4=$(echo "$out4" | sed -n 's/^fixed: //p')
if [ -z "$f4" ]; then
	bad "T4  the pass did not run, so this check cannot tell whether a legitimate NULL is preserved -- treat it as failing" "$out4"
elif [ "$f4" = "0" ] && [ "$(echo "$out4" | sed -n 's/^updates: //p')" = "0" ]; then
	ok "T4  a local-plugin config keeps its legitimate NULL owner"
else
	bad "T4  an unattributable file was given an owner -- it would file under a mod that never reads it" "$out4"
fi

# ---------------------------------------------------------------------------------------
# T5  the file's own header is read, not just its name
# ---------------------------------------------------------------------------------------
out5=$(run_py <<'PY'
import importlib.util, sys
spec = importlib.util.spec_from_file_location("mc", sys.argv[1])
mc = importlib.util.module_from_spec(spec); spec.loader.exec_module(mc)
CFG = sys.argv[2]

stmts = []
mc.server_config_dir = lambda w: CFG
mc.rows = lambda qy: [["legacy_handcopied.cfg"]] if "mod_config_overrides" in qy else []
mc.sql  = lambda s, fetch=False: (stmts.append(s), "")[1]

n = mc.reattribute_unowned("W", 7, {}, {"com.rolopogo.Basement": 88})
print("fixed:", n)
print("sets88:", sum(1 for s in stmts if "SET mod_id = 88" in s))
PY
)
if [ "$(echo "$out5" | sed -n 's/^fixed: //p')" = "1" ] \
   && [ "$(echo "$out5" | sed -n 's/^sets88: //p')" = "1" ]; then
	ok "T5  a file whose NAME is not a GUID is attributed from its header"
else
	bad "T5  the header GUID was not used -- only BepInEx-named files would ever be fixed" "$out5"
fi

# ---------------------------------------------------------------------------------------
# T6  DOOR: materialise() calls it, so a world heals on its next update
# ---------------------------------------------------------------------------------------
body=$(python3 - "$MC" <<'PY'
import sys, re
src = open(sys.argv[1]).read().splitlines()
start = next((i for i, l in enumerate(src) if l.startswith("def materialise(")), None)
if start is None:
    print("NOFUNC"); raise SystemExit
end = next((i for i in range(start + 1, len(src))
            if src[i].startswith("def ") or src[i].startswith("# ---")), len(src))
print("CALLS" if any("reattribute_unowned(" in l for l in src[start:end]) else "ABSENT")
PY
)
case "$body" in
	CALLS)  ok "T6  materialise() calls reattribute_unowned -- the pass has a door" ;;
	NOFUNC) bad "T6  could not locate 'def materialise(' -- this check cannot tell whether the pass is wired in, so treat it as failing" "$MC" ;;
	*)      bad "T6  materialise() never calls reattribute_unowned: a world that is never re-saved stays Unattributed forever" "$body" ;;
esac

# ---------------------------------------------------------------------------------------
# T7  DOOR: the migration runs it on every boot, NOT behind the one-shot import flag
# ---------------------------------------------------------------------------------------
gate=$(python3 - "$ENGINE" <<'PY'
import sys
src = open(sys.argv[1]).read().splitlines()
call = next((i for i, l in enumerate(src) if "--reattribute --all" in l), None)
if call is None:
    print("NOCALL"); raise SystemExit
# Which conditional block is it in? Walk back for the nearest unclosed `if`.
depth = 0
for l in reversed(src[:call]):
    s = l.strip()
    if s == "fi":
        depth += 1
    elif s.startswith("if "):
        if depth == 0:
            print("GATED:" + s[:60]); break
        depth -= 1
else:
    print("UNGATED")
PY
)
case "$gate" in
	UNGATED) ok "T7  the migration re-attributes on every boot -- a GUID learned by a later install is still picked up" ;;
	NOCALL)  bad "T7  the migration never calls --reattribute --all, so an upgrade fixes nothing until a world is updated" "$ENGINE" ;;
	*)       bad "T7  the re-attribution is inside a conditional, so it stops running once that flag is set" "$gate" ;;
esac

# ---------------------------------------------------------------------------------------
# T8  DOOR: --reattribute is a real CLI mode and reaches reattribute_world
# ---------------------------------------------------------------------------------------
if grep -q '"--reattribute"' "$MC" && grep -q "if args.reattribute:" "$MC" \
   && grep -q "reattribute_world(name)" "$MC"; then
	ok "T8  --reattribute parses and dispatches"
else
	bad "T8  --reattribute is not reachable from the command line" \
	    "the migration's call would exit non-zero on every boot"
fi

echo
echo "  $pass passed, $fail failed"
[ "$fail" -eq 0 ] || exit 1
