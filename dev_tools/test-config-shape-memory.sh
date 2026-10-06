#!/bin/bash
# Oracle test: the Mod Configs editor survives a world update.
#
# THE BUG THIS EXISTS FOR
# The editor renders from the files in BepInEx/config, and purgeWorldModsConfigsPatchers()
# empties that directory on every update. A mod writes its config on its first Config.Bind(),
# and `mode='update'` always ends STOPPED, so right after an update the only files left are the
# few materialise() rebuilt from saved overrides. Measured on a real world: 26 mods with
# configs became 8. Nothing was lost -- the overrides are rows and they re-apply -- but the
# operator's editable surface collapsed, and an editor offering 8 of 29 mods reads as broken.
#
# 2.55 remembers each config file's TEXT before the purge takes it (mod_config_shapes) and the
# editor falls back to that for a file that is absent.
#
# WHAT WOULD MAKE THIS TEST WORTHLESS, and the controls for each
#
#  1. A test that only asserts "something was remembered" would pass against a snapshot that
#     remembered the THIN files materialise() writes. That is the real trap: on the second
#     update of a world nobody started in between, those stubs are all that is on disk, and
#     remembering them would overwrite a rich remembered shape with a bare key list -- losing
#     exactly the surface this feature exists to keep. So T2/T3 assert the stub is SKIPPED and
#     the documented file is kept, and T4 proves the remembered bytes still parse into the same
#     settings the live file had.
#
#  2. The snapshot must run BEFORE the purge. After it there is nothing left to read, and the
#     failure is SILENT: the snapshot succeeds, remembers nothing, and the editor collapses
#     exactly as it does today. No unit test can see that, so T7 asserts the order in the
#     engine by line number.
#
#  3. The fix must not re-introduce the bug it sits next to. materialise() was manufacturing
#     configs for mods the world does not have; a remembered shape outlives the file, so
#     without a prune a REMOVED mod would keep its settings on offer forever -- the display
#     side of the same mistake. T5 covers the prune, and T6 is its control: a file still on
#     disk is never forgotten, whatever the claim test infers about it.
#
#   ./test-config-shape-memory.sh
set -u

ROOT="$(cd "$(dirname "$0")/.." && pwd)"
MC="$ROOT/container/engine/tools/modConfigs.py"
TMP="$(mktemp -d)"
trap 'rm -rf "$TMP"' EXIT

pass=0; fail=0
ok()  { echo "  PASS  $1"; pass=$((pass+1)); }
bad() { echo "  FAIL  $1"; echo "        $2"; fail=$((fail+1)); }

# A world's config directory as BepInEx leaves it, plus one file as materialise() leaves it.
CFG="$TMP/config"
mkdir -p "$CFG"

# Written by BepInEx: every entry carries its type and its default.
cat > "$CFG/com.pipakin.SkillInjectorMod.cfg" <<'EOF'
## Settings file was created by plugin SkillInjectorMod v1.1.1
## Plugin GUID: com.pipakin.SkillInjectorMod

[General]

## Whether the patch should run on game launch.
# Setting type: Boolean
# Default value: true
Enabled = true

## How many points to grant.
# Setting type: Int32
# Default value: 5
# Acceptable value range: From 1 to 99
Points = 12
EOF

# Written by materialise() from saved overrides: no metadata on anything, because the mod has
# not booted with it. THIS IS THE CONTROL FILE.
cat > "$CFG/zolantris.ValheimRAFT.cfg" <<'EOF'
## Written by PhValheim from your saved settings.

[Sail]

MaxSailSpeed = 45
EOF

# The loader's own config, which is never a mod config.
echo '[Logging.Console]' > "$CFG/BepInEx.cfg"

# ---- 1. cfg_is_documented tells BepInEx's output from our own ---------------------------
python3 - "$MC" "$CFG" > "$TMP/doc.out" 2>&1 <<'PY'
import importlib.util, sys, os
spec = importlib.util.spec_from_file_location("mc", sys.argv[1])
mc = importlib.util.module_from_spec(spec); spec.loader.exec_module(mc)
d = sys.argv[2]
for name in ("com.pipakin.SkillInjectorMod.cfg", "zolantris.ValheimRAFT.cfg"):
    parsed = mc.parse_cfg(mc.read_text(os.path.join(d, name)))
    print(name, mc.cfg_is_documented(parsed))
PY
exp=$'com.pipakin.SkillInjectorMod.cfg True\nzolantris.ValheimRAFT.cfg False'
if [ "$(cat "$TMP/doc.out")" = "$exp" ]; then
	ok "cfg_is_documented: BepInEx's file yes, a materialise() stub NO"
else
	bad "cfg_is_documented" "got:
$(sed 's/^/          /' "$TMP/doc.out")
        want:
$(echo "$exp" | sed 's/^/          /')"
fi

# ---- 2/3/4. what gets remembered, and that it is still usable ---------------------------
python3 - "$MC" "$CFG" > "$TMP/keep.out" 2>&1 <<'PY'
import importlib.util, sys, os, tempfile
spec = importlib.util.spec_from_file_location("mc", sys.argv[1])
mc = importlib.util.module_from_spec(spec); spec.loader.exec_module(mc)

live = sys.argv[2]
keep, skipped = mc.shapes_to_remember(live)
print("kept", ",".join(n for n, _ in keep))
print("skipped", skipped)

# T4: the remembered BYTES, parsed back the way the editor parses them -- through parse_dir on
# a directory built from what was stored. If the snapshot kept the wrong thing, or truncated
# it, these entries stop matching the live file's.
d = tempfile.mkdtemp()
for name, text in keep:
    with open(os.path.join(d, name), "w", encoding="utf-8") as fh:
        fh.write(text)

def shape(tree):
    out = []
    for f in mc.parse_dir("w", {}, tree, None)["files"]:
        for e in f["entries"]:
            out.append((f["file"], e["section"], e["key"], e["value"],
                        e["type"], e["default"], e["has_default"]))
    return sorted(out)

want = [r for r in shape(live) if r[0] == "com.pipakin.SkillInjectorMod.cfg"]
print("roundtrip", shape(d) == want)
print("entries", len(want))
PY
got_kept=$(grep '^kept ' "$TMP/keep.out" | cut -d' ' -f2-)
if [ "$got_kept" = "com.pipakin.SkillInjectorMod.cfg" ]; then
	ok "only the documented file is remembered -- the stub and BepInEx.cfg are not"
else
	bad "which files are remembered" "kept '$got_kept'
        A stub remembered here OVERWRITES a richer shape from the previous update, which is
        the whole surface this feature keeps. BepInEx.cfg must never be touched (2.49)."
fi
if [ "$(grep '^skipped ' "$TMP/keep.out" | cut -d' ' -f2)" = "1" ]; then
	ok "the skipped stub is counted, not silently dropped"
else
	bad "the stub is counted as skipped" "$(cat "$TMP/keep.out")"
fi
if [ "$(grep '^roundtrip ' "$TMP/keep.out" | cut -d' ' -f2)" = "True" ] \
   && [ "$(grep '^entries ' "$TMP/keep.out" | cut -d' ' -f2)" = "2" ]; then
	ok "a remembered shape parses back to the live file's settings, types and defaults"
else
	bad "remembered bytes round-trip through parse_dir" "$(cat "$TMP/keep.out")
        This is the assertion that proves the editor can actually RENDER from memory."
fi

# ---- 5/6. the prune, and its control ----------------------------------------------------
# A remembered shape outlives the file, so a mod the operator REMOVED must stop being offered.
# But a file on disk is never forgotten, whatever the claim test infers.
python3 - "$MC" "$TMP" > "$TMP/prune.out" 2>&1 <<'PY'
import importlib.util, sys, os
spec = importlib.util.spec_from_file_location("mc", sys.argv[1])
mc = importlib.util.module_from_spec(spec); spec.loader.exec_module(mc)

mc.WORLDS_ROOT = os.path.join(sys.argv[2], "worlds")
cfgdir = os.path.join(mc.WORLDS_ROOT, "W", "game", "BepInEx", "config")
os.makedirs(cfgdir, exist_ok=True)
# On disk: a config whose mod nothing can vouch for. It must survive the prune anyway.
open(os.path.join(cfgdir, "still.here.cfg"), "w").write("[A]\nx = 1\n")

remembered = ["com.pipakin.SkillInjectorMod.cfg", "zolantris.ValheimRAFT.cfg", "still.here.cfg"]
statements = []
mc.rows = lambda q: [[n] for n in remembered] if "mod_config_shapes" in q else []
mc.sql = lambda s, fetch=False: statements.append(s) or ""

cat   = {"skillinjector": 7}                  # the world installs package SkillInjector
guids = {"com.pipakin.SkillInjectorMod": 7}   # which declares this GUID
dropped = mc.forget_unreadable_shapes("W", 1, cat, guids, set())
print("dropped", dropped)
for s in statements:
    print("stmt", "DELETE" if s.startswith("DELETE") else "?",
          [n for n in remembered if n in s])
PY
if grep -q '^dropped 1$' "$TMP/prune.out" \
   && grep -q "stmt DELETE \['zolantris.ValheimRAFT.cfg'\]" "$TMP/prune.out"; then
	ok "a remembered shape for a mod the world no longer has is forgotten"
else
	bad "the prune drops an unclaimed remembered shape" "$(cat "$TMP/prune.out")"
fi
if ! grep -q "still.here.cfg" "$TMP/prune.out"; then
	ok "CONTROL: a file still ON DISK is never forgotten, claimed or not"
else
	bad "CONTROL: a live file is never forgotten" "$(cat "$TMP/prune.out")
        Its own existence outranks any inference about it; dropping it would collapse the
        surface this feature exists to keep."
fi

# And the prune must sit ABOVE materialise's no-overrides return, or a world with no override
# rows at all keeps offering removed mods' settings forever.
ord=$(python3 - "$MC" <<'PY'
import re, sys
src = open(sys.argv[1]).read().splitlines()
start = next(i for i, l in enumerate(src) if l.startswith("def materialise"))
end = next((i for i in range(start + 1, len(src)) if re.match(r"^(def |# ---)", src[i])), len(src))
body = src[start:end]
prune = next((i for i, l in enumerate(body) if "forget_unreadable_shapes(" in l), None)
ret = next((i for i, l in enumerate(body) if "no overrides to apply" in l), None)
print("ok" if prune is not None and ret is not None and prune < ret else f"bad {prune} {ret}")
PY
)
if [ "$ord" = "ok" ]; then
	ok "materialise() prunes BEFORE its no-overrides early return"
else
	bad "the prune runs for a world with no overrides" \
	    "got '$ord'. forget_unreadable_shapes() is below the early return, so a world with no
        override rows never prunes -- and that is exactly the world whose only remembered
        shapes are for mods it no longer has."
fi

# ---- 7. ORDERING: the snapshot must precede the purge ------------------------------------
# The one failure this suite cannot catch any other way: run it after the purge and it succeeds
# while remembering nothing.
snapLine=$(grep -n 'snapshotModConfigs "\$worldName"' "$ROOT/container/engine/phvalheim" \
           | head -1 | cut -d: -f1)
purgeLine=$(awk -F: '$1 > '"${snapLine:-0}"' {print $1; exit}' \
            <(grep -n 'purgeWorldModsConfigsPatchers "\$worldName"' "$ROOT/container/engine/phvalheim"))
if [ -n "${snapLine:-}" ] && [ -n "${purgeLine:-}" ] && [ "$snapLine" -lt "$purgeLine" ]; then
	ok "the engine snapshots (line $snapLine) BEFORE it purges (line $purgeLine)"
else
	bad "snapshot precedes purge in container/engine/phvalheim" \
	    "snapshot at '${snapLine:-none}', next purge at '${purgeLine:-none}'.
        After the purge the config directory is empty, so the snapshot would remember nothing
        and report success."
fi

# ---- 8. the editor actually consults the remembered shapes -------------------------------
# A remembered shape nobody reads is the "shipped without a door" failure: all of the above
# passes and the operator still sees 8 of 29.
MCP="$ROOT/container/nginx/www/includes/modconfigs.php"
# `foreach (` matters: the bare call expression also matches the function's own DEFINITION, so
# without it this passes for a function nothing ever calls.
if grep -qF 'foreach (modConfigRememberedTree($pdo, $world, $worldId, $onDisk) as $f)' "$MCP"; then
	ok "modConfigEditorPayload() merges the remembered shapes"
else
	bad "the editor payload reads mod_config_shapes" \
	    "nothing calls modConfigRememberedTree(), so the editor still renders from disk alone"
fi
if grep -q 'if (isset($onDisk\[$r\[.cfg_file.\]\])) { continue; }' "$MCP"; then
	ok "a file on disk takes precedence over its remembered copy"
else
	bad "disk wins over memory" \
	    "without this the editor could render a stale shape over a live file"
fi
# The remembered shape must never be written back into the world's tree -- that is the
# pre-2.55 custom_configs/ behaviour this release exists to undo, and it would put the OLD
# version's `# Default value:` lines under every modified-from-default badge.
#
# Asserted by enumerating what modConfigs.py writes to a file AT ALL, rather than by grepping
# for the absence of a word: an absence test passes just as happily when the thing it was
# looking for has been renamed.
writes=$(python3 - "$MC" <<'PY'
import re, sys
src = open(sys.argv[1]).read()
# `fh.write(X)` inside a `with open(..., "w")`. Only the argument matters here.
print(",".join(sorted(set(re.findall(r"fh\.write\(([^)]*)\)", src)))))
PY
)
# `(ovs` and not `(ovs)` because the pattern above stops at the first closing paren. Left as
# the pattern's own output rather than tidied into something prettier that no longer matches.
if [ "$writes" = "new_text,render_new_cfg(ovs" ]; then
	ok "nothing writes a remembered shape back onto a world's tree"
else
	bad "remembered bytes stay out of the tree" \
	    "modConfigs.py writes these to disk: '$writes'
        Expected exactly 'new_text,render_new_cfg(ovs' -- the surgical rewrite and the
        sparse file built from override ROWS. A remembered cfg_text appearing here is the
        pre-2.55 whole-file restore coming back."
fi

# ---- 9. the migration creates the table --------------------------------------------------
if grep -q 'tableExists mod_config_shapes' "$ROOT/container/engine/dbUpdates/dbUpdate_2.55.sh" \
   && grep -q 'UNIQUE KEY uk_world_cfg (world_id, cfg_file)' \
        "$ROOT/container/engine/dbUpdates/dbUpdate_2.55.sh"; then
	ok "dbUpdate_2.55.sh creates mod_config_shapes with its uniqueness constraint"
else
	bad "the migration creates mod_config_shapes" \
	    "without the UNIQUE key the upsert cannot work and every update appends a row"
fi
# Collation: cfg_file here is compared against mod_config_overrides.cfg_file, and a
# mixed-collation comparison is a hard error in MySQL, not a silent mismatch.
if [ "$(grep -c 'cfg_file  *VARCHAR(160) COLLATE utf8mb4_0900_as_cs' \
          "$ROOT/container/engine/dbUpdates/dbUpdate_2.55.sh")" = "2" ]; then
	ok "mod_config_shapes.cfg_file matches mod_config_overrides.cfg_file exactly"
else
	bad "cfg_file length and collation match between the two tables" \
	    "got $(grep -c 'cfg_file  *VARCHAR(160) COLLATE utf8mb4_0900_as_cs' \
	      "$ROOT/container/engine/dbUpdates/dbUpdate_2.55.sh") of 2 declarations"
fi

# ---- 10. PHP parses ----------------------------------------------------------------------
for f in "$MCP" "$ROOT/container/nginx/www/admin/world_configs.php"; do
	if out=$(php -l "$f" 2>&1); then
		ok "php -l $(basename "$f")"
	else
		bad "php -l $(basename "$f")" "$out"
	fi
done

echo
echo "$pass passed, $fail failed"
[ "$fail" -eq 0 ] || exit 1
