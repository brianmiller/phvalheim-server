#!/bin/bash
# Oracle tests for the 2.55 mod config editor.
#
# THE BUGS THESE CATCH
#
# 1. A WHOLE-FILE override silently loses settings across a mod update. That is what
#    custom_configs/ did before 2.55: copying an old file over a freshly generated one drops
#    any setting the new version ADDED, resurrects any setting it REMOVED, and makes "what did
#    the operator actually change?" unanswerable. T4 asserts the per-key store does none of
#    those three.
#
# 2. A REWRITE THAT REFORMATS. The materialiser must change only the value span of a line.
#    An early version captured the whitespace after the `=` but not before it, so
#    `Weird   =    7` came back as `Weird=    99`. Nothing broke -- BepInEx reads either form --
#    so no functional test could see it; the file simply stopped matching the mod's own output
#    on exactly the lines the operator had touched. T2 asserts byte identity everywhere except
#    the value, which is the only assertion that can see it. Counting how many overrides
#    applied said "3" both before and after the fix.
#
# 3. IMPORTING UNTOUCHED DEFAULTS. importWorld.sh copies an imported world's ENTIRE
#    BepInEx/config tree into custom_configs/, so such a world looks exactly like an operator
#    who hand-copied hundreds of files when almost all of them are untouched defaults. Import
#    them as overrides and that world is frozen at its import-time defaults forever. T5
#    asserts a file whose every value equals its documented default yields ZERO rows.
#
# 4. IMPORTING THE ENGINE'S OWN FILES. ZeroBandwidth.CustomSeed.cfg is computed by the engine
#    from worlds.seed, and BepInEx.cfg is the loader's config -- 2.49 swept that one and
#    silenced the world log and the client console together. T7 asserts both are neither
#    imported nor moved, because installCustomModsConfigsPatchers() must keep distributing them.
#
# 5. A SERVER-ONLY VALUE REACHING THE CLIENT. T9 asserts a server_only row is written to the
#    server tree and NOT to the client staging tree -- the tree the client payload is zipped
#    from. Carries a control: a non-server_only row in the same run must reach both, or the
#    test would pass just as well against a materialiser that wrote nothing to the client.
#
# Runs entirely locally against container/engine/tools/modConfigs.py. No container, no
# database: the DB-touching parts are stubbed so these stay runnable on a clean checkout.
#
# Usage: dev_tools/test-mod-config-editor.sh
set -uo pipefail
cd "$(dirname "$0")/.." || exit 1
TOOL="container/engine/tools/modConfigs.py"
[ -f "$TOOL" ] || { echo "FAIL: $TOOL not found"; exit 1; }

echo
echo "=== 2.55 mod config editor oracles ==="

python3 - "$TOOL" <<'PYEOF'
import importlib.util, json, os, re, shutil, sys, tempfile

spec = importlib.util.spec_from_file_location("mc", sys.argv[1])
mc = importlib.util.module_from_spec(spec)
spec.loader.exec_module(mc)

P = F = 0
def ok(m):
    global P; P += 1; print(f"  PASS  {m}")
def no(m, d=""):
    global F; F += 1; print(f"  FAIL  {m}" + (f" -- {d}" if d else ""))
def chk(cond, m, d=""):
    ok(m) if cond else no(m, d)

# ---- T1: the parser reads BepInEx's self-describing metadata ------------------------
CFG = """## Settings file was created by plugin Test Mod v1.2
## Plugin GUID: com.example.testmod

[General]

## Enable it.
# Setting type: Boolean
# Default value: true
Enabled = true

## Ranged.
# Setting type: Single
# Default value: 1
# Acceptable value range: From 0 to 10
Multiplier = 2.5

[Advanced: Deep]

# Setting type: Mode
# Default value: Auto
# Acceptable values: Auto, Manual, Off
Mode = Manual

## Spaces in the key.
# Setting type: String
# Default value: hi
Greeting Text = hi

## No documented default.
# Setting type: String
Mystery = whatever
"""
p = mc.parse_cfg(CFG)
e = {(x["section"], x["key"]): x for x in p["entries"]}
chk(p["plugin"] == "Test Mod", "parser reads the plugin name", p["plugin"])
chk(p["guid"] == "com.example.testmod", "parser reads the plugin GUID", p["guid"])
chk(len(p["entries"]) == 5, "parser found all 5 entries", len(p["entries"]))
chk(e[("General", "Enabled")]["type"] == "Boolean", "reads '# Setting type:'")
chk(e[("General", "Enabled")]["default"] == "true", "reads '# Default value:'")
chk(e[("General", "Multiplier")]["range"] == ["0", "10"], "reads an acceptable value RANGE")
chk(e[("Advanced: Deep", "Mode")]["acceptable"] == ["Auto", "Manual", "Off"],
    "reads an acceptable values LIST")
chk(("Advanced: Deep", "Greeting Text") in e, "handles a key containing spaces")
chk(("Advanced: Deep", "Mode") in e, "handles a section name containing a colon")
# The distinction the whole legacy import rests on.
chk(e[("Advanced: Deep", "Mystery")]["has_default"] is False,
    "has_default is FALSE when the file documents no default")
chk(e[("General", "Enabled")]["has_default"] is True,
    "has_default is TRUE when it does")

# ---- T2: the rewrite is surgical ----------------------------------------------------
SP = "[General]\n# Default value: 5\nWeird   =    7\n\n# Default value: x\nTight=x\n"
ovs = [{"section": "General", "ckey": "Weird", "cvalue": "99", "server_only": 0},
       {"section": "General", "ckey": "Tight", "cvalue": "y", "server_only": 0}]
new, applied, orphans = mc.apply_to_text(SP, ovs)
old_l, new_l = SP.splitlines(True), new.splitlines(True)
chk(len(old_l) == len(new_l), "rewrite does not change the line count")
changed = [i for i, (a, b) in enumerate(zip(old_l, new_l)) if a != b]
chk(changed == [2, 5], "only the two value lines changed", str(changed))
# THE assertion that caught the reformatting bug. Spacing on BOTH sides of the `=`.
spacing_ok = True
for i in changed:
    a = re.match(r"^(.*?)(\s*)=(\s*)(.*)$", old_l[i].rstrip("\n"))
    b = re.match(r"^(.*?)(\s*)=(\s*)(.*)$", new_l[i].rstrip("\n"))
    if (a.group(1), a.group(2), a.group(3)) != (b.group(1), b.group(2), b.group(3)):
        spacing_ok = False
chk(spacing_ok, "key text and the spacing either side of '=' are byte-identical",
    repr(new_l[2]))

# ---- T3: a key not yet in the file is INJECTED --------------------------------------
#
# This asserted the OPPOSITE until 2.55 shipped and broke: it required that an absent key be
# reported and NOT written. That rule is why every override was inert on a world update --
# the purge empties the config dir and the mod writes its file afterwards, so "absent" is the
# normal case, not an error. BepInEx adopts a stored value when the plugin binds the key,
# which is exactly how the pre-2.55 custom_configs/ copy worked.
new3, applied3, injected3 = mc.apply_to_text(
    SP, [{"section": "General", "ckey": "Ghost", "cvalue": "1", "server_only": 0}])
chk([o["ckey"] for o in injected3] == ["Ghost"], "a key absent from the file is reported injected")
chk("Ghost = 1" in new3, "the absent key IS written into the file")
v3 = {(e["section"], e["key"]): e["value"] for e in mc.parse_cfg(new3)["entries"]}
chk(v3.get(("General", "Ghost")) == "1", "the injected key parses back in the right section")
chk(v3.get(("General", "Weird")) == "7" and v3.get(("General", "Tight")) == "x",
    "injection leaves the file's existing values alone")

# A section that does not exist yet is created, not silently dropped.
new3b, _, inj3b = mc.apply_to_text(
    SP, [{"section": "BrandNew", "ckey": "Setting", "cvalue": "9", "server_only": 0}])
v3b = {(e["section"], e["key"]): e["value"] for e in mc.parse_cfg(new3b)["entries"]}
chk(v3b.get(("BrandNew", "Setting")) == "9", "a key in a NEW section creates that section")
chk(len(mc.parse_cfg(new3b)["entries"]) == 3, "the new section did not clobber existing entries")

# inject=False is still available for anything that wants the old read-only behaviour.
new3c, _, miss3c = mc.apply_to_text(
    SP, [{"section": "General", "ckey": "Ghost", "cvalue": "1", "server_only": 0}], inject=False)
chk(new3c == SP and [o["ckey"] for o in miss3c] == ["Ghost"],
    "inject=False leaves the file byte-identical and reports the miss")

# ---- T4: directive #1 -- survival across a mod update ------------------------------
V2 = ("[General]\n# Default value: true\nEnabled = true\n\n"
      "# Default value: 20\nMaxStack = 20\n\n"
      "# Default value: false\nNewFeature = false\n")
ovs4 = [{"section": "General", "ckey": "Enabled", "cvalue": "false", "server_only": 0},
        {"section": "General", "ckey": "MaxStack", "cvalue": "64", "server_only": 0},
        {"section": "General", "ckey": "LegacyKey", "cvalue": "mine", "server_only": 0}]
out4, ap4, or4 = mc.apply_to_text(V2, ovs4)
v4 = {(x["section"], x["key"]): x["value"] for x in mc.parse_cfg(out4)["entries"]}
chk(v4[("General", "Enabled")] == "false", "an override survives a mod update")
chk(v4[("General", "MaxStack")] == "64",
    "an override survives even when the mod CHANGED that setting's default")
chk(v4[("General", "NewFeature")] == "false",
    "a setting the new version ADDED arrives at its own new default")
chk([o["ckey"] for o in or4] == ["LegacyKey"],
    "a setting the new version REMOVED is reported, not silently dropped")
# It IS written back now, and that is intentional: nothing binds it, so BepInEx keeps it as an
# orphaned entry and it has no effect. discover() is what tells the operator about it, using
# the metadata test -- a bound key gains `# Setting type:`, an orphan never does. Refusing to
# write it was what made the whole feature inert on a world update.
chk(v4.get(("General", "LegacyKey")) == "mine",
    "the removed setting is written back as an inert orphan, not dropped")

# ---- T5-T8: the legacy import ------------------------------------------------------
root = tempfile.mkdtemp()
try:
    mc.WORLDS_ROOT = root
    W = "importtest"
    cc = os.path.join(root, W, "custom_configs")
    ccs = os.path.join(root, W, "custom_configs_secure")
    os.makedirs(cc); os.makedirs(ccs)

    def w(d, n, s): open(os.path.join(d, n), "w").write(s)
    # every value == its documented default (what importWorld.sh leaves behind)
    w(cc, "untouched.cfg", "[General]\n# Default value: true\nEnabled = true\n\n"
                           "# Default value: 10\nMaxStack = 10\n")
    # exactly one key genuinely changed
    w(cc, "edited.cfg", "[General]\n# Default value: true\nEnabled = false\n\n"
                        "# Default value: 10\nMaxStack = 10\n")
    # no documented defaults -> no baseline -> must be flagged, not guessed
    w(cc, "nobaseline.cfg", "[General]\nSomething = 42\n")
    # engine-owned and loader-owned
    w(cc, "ZeroBandwidth.CustomSeed.cfg", "[CustomSeed]\ncustom_seed = theseed\n")
    w(cc, "BepInEx.cfg", "[Logging.Console]\nEnabled = true\n")
    # server-only
    w(ccs, "secure.cfg", "[Auth]\n# Default value: \nToken = sometoken\n")

    inserts = []
    mc.world_id = lambda n: 7
    mc.world_catalogue = lambda wid: {}
    mc.sql = lambda stmt, fetch=False: inserts.append(stmt) or ""
    mc.import_legacy_world(W)
    blob = "\n".join(inserts)

    chk("untouched.cfg" not in blob,
        "T5 a file of untouched defaults imports ZERO rows (the importWorld.sh trap)")
    chk(blob.count("edited.cfg") == 1,
        "T5 exactly one row from the file with one changed key", blob.count("edited.cfg"))
    chk("'Enabled', 'false'" in blob.replace('"', "'") or "'false'" in blob,
        "T5 the changed value is the one imported")
    chk("legacy-review" in blob and "nobaseline.cfg" in blob,
        "T6 a key with no documented default is imported as origin='legacy-review'")
    chk("CustomSeed" not in blob, "T7 the engine's seed config is NOT imported")
    chk("BepInEx.cfg" not in blob, "T7 the loader's config is NOT imported")
    chk(os.path.isfile(os.path.join(cc, "ZeroBandwidth.CustomSeed.cfg")),
        "T7 the seed file is LEFT IN PLACE for the engine to keep distributing")
    chk(os.path.isfile(os.path.join(cc, "BepInEx.cfg")),
        "T7 the loader config is LEFT IN PLACE")
    chk(re.search(r"'secure\.cfg'.*?,\s*1,\s*0,", blob, re.S) is not None,
        "T8 a custom_configs_secure file imports with server_only=1")
    chk(os.path.isfile(os.path.join(cc, mc.IMPORTED_DIR, "edited.cfg")),
        "T8 a consumed file is parked in .imported-pre-2.55/ so its stale defaults "
        "stop being copied in underneath the overrides")
    chk(os.path.isfile(os.path.join(cc, "untouched.cfg")),
        "T8 a file nothing was taken from is left where it was")
finally:
    shutil.rmtree(root, ignore_errors=True)

# ---- T9: server_only never reaches the client tree ---------------------------------
root = tempfile.mkdtemp()
try:
    mc.WORLDS_ROOT = root
    W = "mattest"
    srv = os.path.join(root, W, "game", "BepInEx", "config")
    cli = os.path.join(root, W, "client", "BepInEx", "config")
    os.makedirs(srv); os.makedirs(cli)
    BODY = ("[Auth]\n# Default value: \nToken = PLACEHOLDER\n\n"
            "# Default value: 1\nTickRate = 1\n")
    for d in (srv, cli):
        open(os.path.join(d, "mod.cfg"), "w").write(BODY)

    mc.world_id = lambda n: 7
    mc.load_overrides = lambda wid: {"mod.cfg": [
        # server-only: must land on the server tree only
        {"cfg_file": "mod.cfg", "section": "Auth", "ckey": "Token",
         "cvalue": "REALSECRET", "server_only": 1, "locked": 0, "origin": "operator"},
        # CONTROL: not server-only, must land on BOTH. Without this, a materialiser that
        # wrote nothing at all to the client tree would pass the assertion below.
        {"cfg_file": "mod.cfg", "section": "Auth", "ckey": "TickRate",
         "cvalue": "7", "server_only": 0, "locked": 0, "origin": "operator"},
    ]}
    mc.materialise(W)
    s = open(os.path.join(srv, "mod.cfg")).read()
    c = open(os.path.join(cli, "mod.cfg")).read()
    chk("REALSECRET" in s, "T9 a server_only value IS written to the server tree")
    chk("REALSECRET" not in c,
        "T9 a server_only value is NOT written to the client staging tree")
    chk("TickRate = 7" in s and "TickRate = 7" in c,
        "T9 CONTROL: a non-server_only value reaches BOTH trees")
finally:
    shutil.rmtree(root, ignore_errors=True)

# ---- T10: THE POST-PURGE PATH -- the case the shipped 2.55 got wrong --------------
#
# THE BUG THIS CATCHES, and it shipped: purgeWorldModsConfigsPatchers() empties
# BepInEx/config on every world update, and almost no mod ships a config/ inside its zip. So
# when materialise runs during an update the config directory is EMPTY -- the mod writes its
# file later, on first load, from its own defaults. The first cut of materialise only rewrote
# files that already existed, so it logged "0 applied, 78 not applicable" and the operator's
# settings were replaced by defaults on every single update.
#
# WHY THE ORIGINAL SUITE COULD NOT SEE IT: every materialise test here pre-created the target
# file, which is the one state in which the broken version works. The live test did the same
# -- it exercised a plain world START, where the previous boot had left a file behind. The
# "not present" lines were in the output and read as benign.
#
# This test starts from an EMPTY directory, which is what an update actually hands it.
root = tempfile.mkdtemp()
try:
    mc.WORLDS_ROOT = root
    W = "purgetest"
    srv = os.path.join(root, W, "game", "BepInEx", "config")
    cli = os.path.join(root, W, "client", "BepInEx", "config")
    os.makedirs(srv); os.makedirs(cli)
    # Nothing in either directory -- exactly the state after the purge.
    chk(os.listdir(srv) == [], "setup: the server config dir starts EMPTY (post-purge)")

    mc.world_id = lambda n: 9
    mc.load_overrides = lambda wid: {"Azumatt.AzuClock.cfg": [
        {"cfg_file": "Azumatt.AzuClock.cfg", "section": "1 - General",
         "ckey": "Clock Font Color", "cvalue": "1500FF",
         "server_only": 0, "locked": 0, "origin": "operator"},
        {"cfg_file": "Azumatt.AzuClock.cfg", "section": "1 - General",
         "ckey": "Clock Font Size", "cvalue": "18",
         "server_only": 0, "locked": 0, "origin": "legacy"},
        {"cfg_file": "Azumatt.AzuClock.cfg", "section": "Clock",
         "ckey": "Clock String", "cvalue": "<b>{0}</b>",
         "server_only": 1, "locked": 0, "origin": "legacy"},
    ]}
    mc.materialise(W)

    spath = os.path.join(srv, "Azumatt.AzuClock.cfg")
    cpath = os.path.join(cli, "Azumatt.AzuClock.cfg")
    chk(os.path.isfile(spath), "T10 the server config file was CREATED from the saved settings")
    chk(os.path.isfile(cpath), "T10 the client staging file was CREATED too")

    sv = {(e["section"], e["key"]): e["value"] for e in mc.parse_cfg(mc.read_text(spath))["entries"]}
    chk(sv.get(("1 - General", "Clock Font Color")) == "1500FF",
        "T10 the operator's colour survived a post-purge update", str(sv))
    chk(sv.get(("1 - General", "Clock Font Size")) == "18", "T10 and so did the font size")
    chk(sv.get(("Clock", "Clock String")) == "<b>{0}</b>",
        "T10 a value in a second section landed in that section")
    chk(len(sv) == 3, "T10 exactly the three saved settings, nothing invented", str(len(sv)))

    cv = {(e["section"], e["key"]): e["value"] for e in mc.parse_cfg(mc.read_text(cpath))["entries"]}
    chk(("Clock", "Clock String") not in cv,
        "T10 the server_only setting did NOT reach the created client file")
    chk(cv.get(("1 - General", "Clock Font Color")) == "1500FF",
        "T10 CONTROL: the non-server_only setting DID reach the client file")

    # Now the mod boots and rewrites the file with its own documentation, keeping the stored
    # values. Re-running materialise must be a no-op on content, not a duplicate-append.
    # All three keys bound, as the mod would leave them once it has adopted the stored values.
    # An earlier version of this fixture omitted the [Clock] section, so re-running correctly
    # injected it and the "changes nothing" assertion failed -- the test was wrong, not the
    # code. The point of this check is the no-op case, so the fixture has to be the state a
    # booted mod actually produces.
    booted = (
        "## Settings file was created by plugin AzuClock v1.0\n\n[1 - General]\n\n"
        "# Setting type: Int32\n# Default value: 24\nClock Font Size = 18\n\n"
        "# Setting type: Color\n# Default value: FFFFFFFF\nClock Font Color = 1500FF\n\n"
        "[Clock]\n\n"
        "# Setting type: String\n# Default value: {0}\nClock String = <b>{0}</b>\n")
    open(spath, "w").write(booted)
    mc.materialise(W)
    after = mc.read_text(spath)
    chk(after == booted, "T10 re-running on the mod's own regenerated file changes nothing")
    chk(after.count("Clock Font Color") == 1, "T10 no duplicate key was appended")
finally:
    shutil.rmtree(root, ignore_errors=True)

print()
print(f"=== {P} passed, {F} failed ===")
sys.exit(1 if F else 0)
PYEOF
rc=$?
echo
[ $rc -eq 0 ] && echo "ALL MOD CONFIG EDITOR ORACLES PASSED" || echo "MOD CONFIG EDITOR ORACLES FAILED"
exit $rc
