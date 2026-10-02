#!/usr/bin/env python3
"""Per-mod install destinations: does the union actually propagate?

Unit-level on purpose. The thing worth testing here is the graph rule -- a dependency lands
on the union of its parents' sides -- and that is pure logic sitting behind two SQL calls.
Stubbing those runs it in milliseconds with a hand-built graph, so the awkward shapes (a
widening that has to travel two more levels, two catalogue copies of one plugin pulled in
from opposite sides) are actually reachable. They are not reachable from a live container
without contriving a mod catalogue to match.

EVERY assertion here is paired with its opposite, because the failure mode of a union is
that it returns True for everything. An implementation of walk_closure() that ignored the
flags entirely and answered [True, True] always would satisfy "the dep of a server-only mod
is on the server" -- so that assertion proves nothing on its own. The control is the other
half: it must ALSO be absent from the client. Same reason
dev_tools/test-companion-connect-capability.sh asserts both directions.

Run:  dev_tools/test-mod-destinations.py
"""

import importlib.util
import os
import re
import subprocess
import sys

HERE = os.path.dirname(os.path.abspath(__file__))
REPO = os.path.dirname(HERE)
WM_PATH = os.path.join(REPO, "container", "engine", "tools", "worldMods.py")
FUNCS_PATH = os.path.join(REPO, "container", "engine", "includes", "0-functions.sh")

spec = importlib.util.spec_from_file_location("worldMods", WM_PATH)
wm = importlib.util.module_from_spec(spec)
spec.loader.exec_module(wm)

FAILED = 0


def ok(msg):
    print(f"  \033[32mPASS\033[0m  {msg}")


def no(msg, why=""):
    global FAILED
    FAILED += 1
    print(f"  \033[31mFAIL\033[0m  {msg}" + (f"\n        {why}" if why else ""))


def check(cond, msg, why=""):
    ok(msg) if cond else no(msg, why)


# ---------------------------------------------------------------------------------------
# The fake catalogue.
#
# Mod ids and version ids are strings because that is what rows() hands back -- mysql
# --skip-column-names output split on tabs. Using ints here would let a comparison pass in
# the test that fails in production, which is its own class of lie.
# ---------------------------------------------------------------------------------------

def install_graph(edges, meta, versions=None):
    """Point worldMods at a hand-built graph. edges: {parent_mod: [dep_mod, ...]}."""
    vers = versions or {m: f"v{m}" for m in meta}
    owner_of_version = {v: m for m, v in vers.items()}

    def fake_effective_version(wid, mod_id):
        v = vers.get(mod_id)
        return (v, "1.0.0", f"http://example/{mod_id}.zip", False) if v else (None, None, None, False)

    def fake_rows(query):
        m = re.search(r"version_id IN \(([^)]*)\)", query)
        if m is None:
            raise AssertionError(f"unexpected query in stub: {query[:120]}")
        out = []
        for vid in [v.strip() for v in m.group(1).split(",") if v.strip()]:
            parent = owner_of_version.get(vid)
            for dep in edges.get(parent, []):
                out.append([vid, dep, f"{dep}-1.0.0"])
        return out

    wm.effective_version = fake_effective_version
    wm.rows = fake_rows
    return vers


def walk(picks, pick_dest, edges, meta):
    install_graph(edges, meta)
    deps, dest, missing = wm.walk_closure("1", picks, pick_dest)
    return deps, dest, missing


def as_pair(dest, mod):
    return tuple(dest.get(mod, ["absent", "absent"]))


print("\n=== 1. a dependency inherits its parent's side, and ONLY its parent's side ===\n")

# A is Server-only and depends on B. B must be on the server -- and the control that makes
# that assertion mean anything is that B must NOT be on the client.
META = {"A": ("thunderstore", "own", "A"), "B": ("thunderstore", "own", "B")}
deps, dest, _ = walk(["A"], {"A": [True, False]}, {"A": ["B"]}, META)
check(as_pair(dest, "B") == (True, False),
      "dep of a Server-only mod is server-only",
      f"got {as_pair(dest, 'B')}; a union that answers [True, True] for everything is the "
      f"bug this pairing exists to catch")

deps, dest, _ = walk(["A"], {"A": [False, True]}, {"A": ["B"]}, META)
check(as_pair(dest, "B") == (False, True),
      "dep of a Client-only mod is client-only (the mirror)")

deps, dest, _ = walk(["A"], {"A": [True, True]}, {"A": ["B"]}, META)
check(as_pair(dest, "B") == (True, True),
      "dep of a both-sides mod is on both -- today's behaviour, unchanged")


print("\n=== 2. two parents on opposite sides: the dep lands on BOTH ===\n")

# The case from the design doc: Jotunn pulled in by a Server-only networking mod and a
# Client-only UI mod. Either side missing it is a BepInEx load failure.
META2 = {"S": ("thunderstore", "own", "S"), "C": ("thunderstore", "own", "C"),
         "J": ("thunderstore", "own", "Jotunn")}
deps, dest, _ = walk(["S", "C"], {"S": [True, False], "C": [False, True]},
                     {"S": ["J"], "C": ["J"]}, META2)
check(as_pair(dest, "J") == (True, True),
      "shared dep of a Server-only and a Client-only parent is on both sides",
      f"got {as_pair(dest, 'J')}; whichever side is False is a plugin that will fail to load")

# Control: the same graph with both parents Server-only must NOT put it on the client.
deps, dest, _ = walk(["S", "C"], {"S": [True, False], "C": [True, False]},
                     {"S": ["J"], "C": ["J"]}, META2)
check(as_pair(dest, "J") == (True, False),
      "same shared dep with both parents Server-only stays off the client")


print("\n=== 3. the widening has to travel further than one level ===\n")

# This is the assertion a naive `if dep in seen: continue` walk fails, and it is the whole
# reason walk_closure re-queues a node whose destination grew.
#
#   pass 1:  A(server) -> B          B becomes server-only
#            D(client) -> E          E becomes client-only
#   pass 2:  B -> C                  C becomes server-only  (from B's NARROW flags)
#            E -> B                  B widens to both
#   pass 3:  B re-queued -> C        C must widen to both
#
# Without the re-queue the walk stops after pass 2 and C is server-only forever: a plugin
# missing from the client payload, with nothing anywhere saying why.
META3 = {k: ("thunderstore", "own", k) for k in "ABCDE"}
deps, dest, _ = walk(["A", "D"], {"A": [True, False], "D": [False, True]},
                     {"A": ["B"], "D": ["E"], "E": ["B"], "B": ["C"]}, META3)
check(as_pair(dest, "B") == (True, True),
      "a dep reached from both sides widens (direct)")
check(as_pair(dest, "C") == (True, True),
      "the widening reaches the dep's OWN dependency, one level further down",
      f"got {as_pair(dest, 'C')}; this is the assertion a seen-set-only walk fails")

# Control: cut the E -> B edge and C must go back to server-only. Without this, an
# implementation that widens everything to both passes the assertion above.
deps, dest, _ = walk(["A", "D"], {"A": [True, False], "D": [False, True]},
                     {"A": ["B"], "D": ["E"], "B": ["C"]}, META3)
check(as_pair(dest, "C") == (True, False),
      "with the widening edge removed, the grandchild is server-only again")


print("\n=== 4. a cycle terminates ===\n")

# Destinations only grow, and only twice per node, so a re-queue cannot loop forever. Worth
# an actual assertion rather than an argument: this is the change that made the walk
# re-entrant, and an infinite loop here hangs a world's rebuild.
META4 = {k: ("thunderstore", "own", k) for k in "XYZ"}
try:
    deps, dest, _ = walk(["X"], {"X": [True, False]},
                         {"X": ["Y"], "Y": ["Z"], "Z": ["X", "Y"]}, META4)
    check(as_pair(dest, "Y") == (True, False) and as_pair(dest, "Z") == (True, False),
          "a dependency cycle resolves without hanging or widening spuriously",
          f"Y={as_pair(dest, 'Y')} Z={as_pair(dest, 'Z')}")
except RecursionError as e:
    no("a dependency cycle resolves without hanging", str(e))


print("\n=== 5. an unresolvable dependency is still reported ===\n")

# 2.43 behaviour that must survive the rewrite: a dep_string with no dep_mod_id is a mod we
# do not have, and it is collected rather than dropped.
def fake_rows_missing(query):
    return [["vA", "NULL", "SomeOne-MissingMod-1.0.0"]]


install_graph({}, {"A": ("thunderstore", "own", "A")})
wm.rows = fake_rows_missing
deps, dest, missing = wm.walk_closure("1", ["A"], {"A": [True, True]})
check(missing == ["SomeOne-MissingMod-1.0.0"],
      "an unresolvable dependency is collected for the WARN, not silently dropped",
      f"got {missing}")


print("\n=== 6. fold_by_plugin: catalogue copies of one plugin share a destination ===\n")

# Both catalogues carry the same plugin. Whichever copy by_plugin() keeps, the files land in
# the same BepInEx tree -- so the survivor has to satisfy the parents of all of them.
foldMeta = {"T": ("thunderstore", "denikson", "Jotunn"),
            "H": ("hexium", "denikson", "Jotunn"),
            "O": ("thunderstore", "someone", "Other")}
folded = wm.fold_by_plugin({"T": [True, False], "H": [False, True], "O": [False, True]},
                           foldMeta)
check(tuple(folded["T"]) == (True, True) and tuple(folded["H"]) == (True, True),
      "two catalogue copies of one plugin both carry the union",
      f"T={folded['T']} H={folded['H']}")
check(tuple(folded["O"]) == (False, True),
      "an unrelated plugin is NOT widened by the fold",
      f"got {folded['O']}; a fold that widens everything is the same lie as a union that "
      f"returns True always")

# Idempotent, because resolve() and install_rows() both call it.
again = wm.fold_by_plugin(dict(folded), foldMeta)
check(again == folded, "fold_by_plugin is idempotent")


print("\n=== 7. both switches off is preserved, not quietly widened ===\n")

# The picker cannot produce this -- it unticks the mod instead -- but if a row ever holds it,
# the walk must not invent a destination. This is the strongest control in the file: it is
# the one case where the correct answer is False on BOTH sides.
deps, dest, _ = walk(["A"], {"A": [False, False]}, {"A": ["B"]}, META)
check(as_pair(dest, "A") == (False, False),
      "a pick with neither switch set keeps neither",
      f"got {as_pair(dest, 'A')}")
check(as_pair(dest, "B") == (False, False),
      "its dependency inherits nothing either")


print("\n=== 8. the plan TSV and the install loop agree on how many columns exist ===\n")

# `read` assigns its LAST variable every remaining field, so a column added to the plan
# without a matching variable in 0-functions.sh does not go missing -- it gets glued onto
# mod_id, which then goes into --record-installed and into SQL. Nothing about that failure
# looks like a parsing error. This asserts the contract across the two languages so the next
# person to add a column cannot get away with only doing half of it.
wm.install_rows = lambda wid, warn=None: [{
    "mod_id": 7, "is_dep": "0", "source": "thunderstore", "owner": "own", "name": "A",
    "page": "", "version_id": "1", "version": "1.0.0", "url": "http://e/a.zip",
    "pinned": False, "deploy_server": True, "deploy_client": False}]
wm.world_id = lambda name: "1"

import io
import contextlib

buf = io.StringIO()
with contextlib.redirect_stdout(buf):
    wm.plan("w")
planLine = buf.getvalue().strip()
planFields = len(planLine.split("\t"))

readLine = ""
with open(FUNCS_PATH, encoding="utf-8") as fh:
    for ln in fh:
        if "read -r modSource" in ln:
            readLine = ln
            break
readVars = len(re.findall(r"\bmod[A-Za-z]+", readLine.split("read -r", 1)[1])) if readLine else 0

check(readLine != "", "found the install loop's read in 0-functions.sh",
      "the cross-language check below is vacuous without it -- fix the pattern")
check(planFields == readVars and planFields > 0,
      f"plan emits {planFields} columns and the install loop reads {readVars} variables",
      "mismatched: read glues the extra columns onto the LAST variable, which is mod_id")
check("\t1\t0" == planLine[-4:],
      "the two destination flags are the last two columns, in server-then-client order",
      f"line ends '{planLine[-12:]}'")


print("\n=== 9. the migration defaults both columns to 1 ===\n")

# DEFAULT 1 is what makes every world that already exists install byte-identically. A
# migration that defaulted to 0 would empty every payload on the next rebuild.
mig = os.path.join(REPO, "container", "engine", "dbUpdates", "dbUpdate_2.53.sh")
with open(mig, encoding="utf-8") as fh:
    migText = fh.read()
check(migText.count("TINYINT NOT NULL DEFAULT 1") >= 1 and "deploy_server" in migText
      and "deploy_client" in migText,
      "dbUpdate_2.53.sh adds both columns NOT NULL DEFAULT 1")
check("DEFAULT 0" not in migText.split("deploy_server")[-1],
      "neither destination column defaults to 0")
check(subprocess.run(["bash", "-n", mig], capture_output=True).returncode == 0,
      "dbUpdate_2.53.sh parses")

print()
if FAILED:
    print(f"\033[31m{FAILED} check(s) failed\033[0m\n")
    sys.exit(1)
print("\033[32mall checks passed\033[0m\n")
