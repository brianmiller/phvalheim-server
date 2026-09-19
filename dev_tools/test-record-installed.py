#!/usr/bin/env python3
"""Oracle tests for worldMods.record_installed().

This is the ONLY writer of world_mods.installed_version_id, and updateChecker's entire
answer to "are this world's mods up to date?" rests on what it writes. The interesting
cases are not "did it store the number" -- they are the three-way distinction it has to
preserve, because every way of collapsing it puts back a bug 2.47 already shipped once:

  a mod that LANDED          -> record the version we installed
  a mod that FAILED          -> leave the row completely alone. Its previous copy is still
                                sitting in BepInEx/plugins, so the old value is still true.
                                Clearing it would report "unknown" for a mod we can see.
  a mod NOT IN THE PLAN      -> installed_at set, version NULL. "We looked, it is not
                                installed" -- which is NOT the same as "nobody has looked",
                                and reading it as the latter parks a freshly rebuilt world
                                on "waiting for data" forever.

Each test drives the real function with a fake sql() and asserts on the statements it
emits, so a rewrite that produces the right values by the wrong route still has to satisfy
the distinction above.

Run: dev_tools/test-record-installed.py
"""

import os
import re
import sys

sys.path.insert(0, os.path.join(os.path.dirname(os.path.abspath(__file__)),
                                "..", "container", "engine", "tools"))

import worldMods as wm  # noqa: E402

pass_count = 0
fail_count = 0


def check(label, expected, actual):
    global pass_count, fail_count
    if expected == actual:
        pass_count += 1
        print(f"  PASS  {label}")
    else:
        fail_count += 1
        print(f"  FAIL  {label}\n          expected: {expected!r}\n          actual:   {actual!r}")


# A world with four mods in world_mods. Three are in the install plan; the fourth is a
# duplicate plugin that by_plugin() collapsed away, so install_rows() never returns it.
PLAN = [
    {"mod_id": "10", "version_id": "101", "name": "EpicLoot"},
    {"mod_id": "11", "version_id": "111", "name": "Jotunn"},
    {"mod_id": "12", "version_id": "121", "name": "SmartContainers"},
]


def run(installed_ids, plan=PLAN):
    """Call record_installed with everything below it stubbed; return the SQL it emitted."""
    emitted = []
    real_sql, real_world_id, real_install_rows = wm.sql, wm.world_id, wm.install_rows
    wm.sql = lambda stmt, fetch=False: emitted.append(" ".join(stmt.split())) or ""
    wm.world_id = lambda name: "7"
    wm.install_rows = lambda wid, warn=None: list(plan)
    try:
        wm.record_installed("w", installed_ids)
    finally:
        wm.sql, wm.world_id, wm.install_rows = real_sql, real_world_id, real_install_rows
    return emitted


def recorded_for(stmts, mod_id):
    """The installed_version_id written for one mod, or None if it was never touched."""
    for s in stmts:
        m = re.search(r"SET installed_version_id=(\S+?),\s*installed_at=NOW\(\)\s+"
                      r"WHERE world_id=7 AND mod_id=" + mod_id + r"\b", s)
        if m:
            return m.group(1)
    return None


def sweep(stmts):
    """The single statement that clears rows outside the plan."""
    for s in stmts:
        if "installed_version_id=NULL" in s:
            return s
    return ""


print("\nworldMods.record_installed tests\n")

# --- everything installed cleanly -----------------------------------------------------
stmts = run(["10", "11", "12"])
check("a mod that landed records the version that was installed", "101",
      recorded_for(stmts, "10"))
check("...for every mod in the plan", ["101", "111", "121"],
      [recorded_for(stmts, m) for m in ("10", "11", "12")])

# --- one mod failed -------------------------------------------------------------------
#
# The load-bearing case. SmartContainers' download 404'd, so the installer left it out of
# the list. Its old copy is still on disk and its recorded version is still true.
stmts = run(["10", "11"])
check("a mod that failed to install is not recorded as installed", None,
      recorded_for(stmts, "12"))
check("...and is not cleared either -- its previous copy is still on disk", True,
      "mod_id NOT IN" in sweep(stmts) and "12" in sweep(stmts).split("NOT IN")[1])
check("...while the mods that did land are still recorded", ["101", "111"],
      [recorded_for(stmts, m) for m in ("10", "11")])

# --- a mod no longer in the plan ------------------------------------------------------
#
# Mod 13 is in world_mods but not in install_rows() -- a collapsed duplicate, or a loader
# row. It must claim nothing, but it must also not look unexamined: installed_at is set so
# updateChecker can tell "known not installed" from "never recorded". Writing NULL to both
# is the tempting simplification and it is wrong.
stmts = run(["10", "11", "12"])
s = sweep(stmts)
check("rows outside the plan are cleared", True, "installed_version_id=NULL" in s)
check("...but stamped as examined, not left looking unrecorded", True,
      "installed_at=NOW()" in s)
check("...and the sweep spares every mod in the plan", True,
      all(m in s.split("NOT IN")[1] for m in ("10", "11", "12")))

# --- nothing landed at all ------------------------------------------------------------
#
# Every download failed. The rows must not be wiped -- whatever was on disk before still
# is -- but the sweep still has to run, because a mod REMOVED from the world's list has to
# stop claiming to be installed.
stmts = run([])
check("a totally failed install records nothing", [None, None, None],
      [recorded_for(stmts, m) for m in ("10", "11", "12")])
check("...but still sweeps rows outside the plan", True, bool(sweep(stmts)))

# --- a world with no mods at all ------------------------------------------------------
#
# Vanilla, or a world whose mods were all removed. With no plan there is nothing to spare,
# so the sweep must cover the whole world rather than emit an empty NOT IN () -- which is
# a MySQL syntax error, and would abort the statement silently from a backgrounded engine.
stmts = run([], plan=[])
s = sweep(stmts)
check("a world with no mods still clears its rows", True, bool(s))
check("...without emitting an empty NOT IN ()", False, "NOT IN ()" in s)

# --- junk from the shell --------------------------------------------------------------
#
# The id list arrives as a string built by string concatenation in bash. A trailing comma,
# an empty field or a stray word must not become part of an id, and an id that was never
# in the plan must not be honoured -- the installer cannot install what it was not asked to.
stmts = run(["10", "", "oops", "99"])
check("a mod id that was never planned is ignored", None, recorded_for(stmts, "99"))
check("...and junk does not stop the real ids being recorded", "101",
      recorded_for(stmts, "10"))
check("no statement contains a non-numeric id", True,
      not any("oops" in s for s in stmts))

# --- the plan TSV the installer reads -------------------------------------------------
#
# record_installed() is only ever as good as the ids the installer hands it, and those come
# out of `--plan` and through a bash `read -r ... modId`. Two things have to hold: mod_id
# has to BE there, and it has to be APPENDED -- four scripts pull fields 1-5 out of this
# TSV with awk, and inserting a column anywhere else silently shifts the download URL into
# the filename slot.
import io                                                                  # noqa: E402
from contextlib import redirect_stdout                                     # noqa: E402

PLAN_ROW = [{"mod_id": "10", "version_id": "101", "source": "thunderstore",
             "owner": "RandyKnapp", "name": "EpicLoot", "version": "0.9.9",
             "url": "https://example/EpicLoot-0.9.9.zip", "page": "p",
             "is_dep": "0", "pinned": False}]

_real_world_id, _real_install_rows = wm.world_id, wm.install_rows
wm.world_id = lambda name: "7"
wm.install_rows = lambda wid, warn=None: list(PLAN_ROW)
buf = io.StringIO()
try:
    with redirect_stdout(buf):
        wm.plan("w")
finally:
    wm.world_id, wm.install_rows = _real_world_id, _real_install_rows

fields = buf.getvalue().strip().split("\t")
check("the plan carries mod_id so the installer can report back", "10", fields[-1])
check("...appended, leaving the first eight columns where awk expects them",
      ["thunderstore", "RandyKnapp", "EpicLoot", "0.9.9",
       "https://example/EpicLoot-0.9.9.zip"], fields[0:5])
check("...and the row is exactly nine fields", 9, len(fields))

# --- a VANILLA world --------------------------------------------------------------------
# Converting a world to vanilla purges its mod files and skips the install path, but leaves
# its world_mods rows behind. Reproduced on a live server: every row stayed installed_at
# NULL, the Updates tab read "5 mods have no recorded installed version", and Rebuild Mods
# could never clear it because every rebuild took the same skip. A vanilla world plans
# NOTHING, so every row must go through the not-in-plan sweep and be recorded as known
# NOT installed.
def run_vanilla(installed_ids="", plan=PLAN):
    emitted = []
    real_sql, real_world_id = wm.sql, wm.world_id
    real_install_rows, real_rows = wm.install_rows, wm.rows
    wm.sql = lambda stmt, fetch=False: emitted.append(" ".join(stmt.split())) or ""
    wm.world_id = lambda name: "7"
    wm.rows = lambda query: [["1"]] if "vanilla" in query else []
    # If the plan is consulted at all for a vanilla world, these rows would be excluded
    # from the sweep -- which is exactly the bug.
    wm.install_rows = lambda wid, warn=None: list(plan)
    try:
        wm.record_installed("w", installed_ids)
    finally:
        wm.sql, wm.world_id = real_sql, real_world_id
        wm.install_rows, wm.rows = real_install_rows, real_rows
    return emitted


stmts = run_vanilla()
check("vanilla: no mod is claimed as installed", [None, None, None],
      [recorded_for(stmts, m) for m in ("10", "11", "12")])
check("vanilla: the sweep covers EVERY row, with no mod_id exclusion",
      True, "installed_version_id=NULL" in sweep(stmts) and "NOT IN" not in sweep(stmts))
check("vanilla: and it stamps installed_at, so the rows read known-not-installed "
      "rather than unknown", True, "installed_at=NOW()" in sweep(stmts))

# The inverse: a NON-vanilla world must still exclude its planned mods from that sweep,
# or a real install would be wiped out by the very next line.
stmts = run(["10", "11", "12"])
# `wanted` is a set, so the id ORDER in the NOT IN list is arbitrary -- compare as a set,
# not as a string. (Asserting the literal text passed by luck of iteration order once.)
excluded = re.search(r"NOT IN \(([^)]*)\)", sweep(stmts))
check("non-vanilla: the sweep still excludes the planned mods",
      {"10", "11", "12"},
      set(excluded.group(1).split(",")) if excluded else set())

# --- the caller ---------------------------------------------------------------------------
# record_installed only helps a vanilla world if the vanilla path actually CALLS it. That
# call is the difference between "known not installed" and a permanent "waiting for data",
# so pin it here rather than trusting the engine to keep it.
ENGINE = os.path.join(os.path.dirname(os.path.abspath(__file__)),
                      "..", "container", "engine", "phvalheim")
with open(ENGINE) as fh:
    engine_src = fh.read()

check("the vanilla path calls --record-installed", True,
      '--record-installed ""' in engine_src)

skip_at = engine_src.find("is vanilla -- skipping")
record_at = engine_src.find('--record-installed ""')
viewer_at = engine_src.find('generateModViewerJson "$worldName"', skip_at)
check("...after the purge and before the viewer is rebuilt, i.e. inside the vanilla branch",
      True, -1 < skip_at < record_at < viewer_at)

print(f"\n  {pass_count} passed, {fail_count} failed")
sys.exit(1 if fail_count else 0)
