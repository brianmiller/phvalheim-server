#!/usr/bin/env python3
"""The global sync lock, and the orphan-edge retry that makes a lost rebuild self-heal.

Both exist because of one production incident: two forced syncs of DIFFERENT sources ran
4 seconds apart, sailed past the per-SOURCE already_running() guard, both ran
DELETE + bulk INSERT on the one GLOBAL mod_deps table, and deadlocked. 668 versions lost
every dependency edge across 27 worlds -- and nothing would ever have repaired it, because
a version with zero edges is in neither of the incremental retry sets.

These drive the REAL take_global_lock() against REAL flock calls. flock is per open file
description, so two open() calls in one process contend exactly as two processes do --
which is what makes this testable without spawning anything.

Run: dev_tools/test-modsync-lock.py
"""

import fcntl
import os
import sys
import threading
import time

sys.path.insert(0, os.path.join(os.path.dirname(os.path.abspath(__file__)),
                                "..", "container", "engine", "tools"))

import modSync as ms  # noqa: E402

passed = failed = 0


def check(label, expected, actual):
    global passed, failed
    if expected == actual:
        passed += 1
        print(f"  PASS  {label}")
    else:
        failed += 1
        print(f"  FAIL  {label}\n          expected: {expected!r}\n          actual:   {actual!r}")


def clean():
    for p in (ms.RUN_LOCK, ms.WAIT_LOCK):
        try:
            os.unlink(p)
        except FileNotFoundError:
            pass


def hold(path):
    """Take the lock the way a separate process would, and hand back the handle."""
    fh = open(path, "w")
    fcntl.flock(fh, fcntl.LOCK_EX | fcntl.LOCK_NB)
    return fh


ms.log = lambda *a, **k: None          # quiet

# Fast-forward every wait, for the WHOLE file. Without this a regression that makes an
# automatic trigger queue does not fail -- it sits in the 900s wait loop and hangs the
# suite, which is a far worse signal than a red line. (It did exactly that once.)
_real_sleep = time.sleep
sleeps = [0]


def _fast_sleep(s):
    sleeps[0] += 1
    _real_sleep(0.01)


time.sleep = _fast_sleep

print("\nmodSync global lock tests\n")

# ---- nothing running: every trigger proceeds ------------------------------------------
clean()
for trig in ("cron", "manual", "boot"):
    fh = ms.take_global_lock(trig)
    check(f"{trig}: an idle server just runs", True, fh is not None)
    if fh:
        fh.close()

# ---- an AUTOMATIC trigger skips rather than queueing -----------------------------------
# Queueing a cron tick behind a running sync only repeats work that is happening now.
clean()
held = hold(ms.RUN_LOCK)
# Counting the sleeps, not just the return value: a run that QUEUES and then gives up
# after 900s also returns None, so None alone cannot tell "skipped" from "waited".
sleeps[0] = 0
check("cron: skipped while a sync is running", None, ms.take_global_lock("cron"))
check("...immediately, without queueing behind it", 0, sleeps[0])
sleeps[0] = 0
check("boot: skipped while a sync is running", None, ms.take_global_lock("boot"))
check("...immediately, without queueing behind it", 0, sleeps[0])
held.close()

# ---- a MANUAL trigger queues -----------------------------------------------------------
clean()
held = hold(ms.RUN_LOCK)
threading.Timer(0.15, held.close).start()          # the running sync finishes
got = ms.take_global_lock("manual")
check("manual: waits for the running sync, then runs", True, got is not None)

# The queue slot must be handed back the moment the queued run STARTS, not when it exits.
# Otherwise the next click cannot queue behind the run now in progress.
try:
    probe = open(ms.WAIT_LOCK, "w")
    fcntl.flock(probe, fcntl.LOCK_EX | fcntl.LOCK_NB)
    freed = True
    probe.close()
except OSError:
    freed = False
check("...and frees the queue slot as it starts, so the next click can queue", True, freed)
if got:
    got.close()

# ---- but only ONE deep --------------------------------------------------------------
# Ten clicks must not become ten full rebuilds; the queued run picks up the same catalogue.
clean()
held = hold(ms.RUN_LOCK)
queued = hold(ms.WAIT_LOCK)
check("manual: does not stack behind an already-queued run", None,
      ms.take_global_lock("manual"))
queued.close()
held.close()

# ---- releasing lets the next one in ----------------------------------------------------
clean()
first = ms.take_global_lock("cron")
check("a second run is blocked while the first holds it", None, ms.take_global_lock("cron"))
first.close()
second = ms.take_global_lock("cron")
check("...and admitted once it is released", True, second is not None)
if second:
    second.close()

# ---- the lock covers the WHOLE run, and is released no matter what ---------------------
src = open(os.path.join(os.path.dirname(os.path.abspath(__file__)),
                        "..", "container", "engine", "tools", "modSync.py")).read()
def find(needle, start=0):
    """index() that reports a missing anchor as a FAILURE, not a traceback."""
    try:
        return src.index(needle, start)
    except ValueError:
        return -1

take_at = find("lock = take_global_lock(args.trigger)")
loop_at = find("for s in todo:", max(take_at, 0))
check("main() calls take_global_lock at all", True, take_at >= 0)
check("main() takes the lock BEFORE the source loop", True, 0 <= take_at < loop_at)
check("...and releases it in a finally, so a crash cannot wedge the next run",
      True, "finally:\n        lock.close()" in src)
check("the per-source guard is kept -- it still serves its own purpose",
      True, "def already_running(source):" in src)

# ---- the orphan retry ------------------------------------------------------------------
# The bug this fixes is silent: the version is never retried, so the world installs the mod
# with none of its dependencies.
orph_at = find("AND NOT EXISTS (SELECT 1 FROM mod_deps d WHERE d.version_id = v.id)")
moved_at = find("if catalogue_moved:")
want_at = find("want = sorted(set(ids)")
check("zero-edge versions are collected for re-resolution", True, orph_at >= 0)
# Read the STATEMENT, not its position: gating it behind catalogue_moved leaves the text
# in the same place, so a positional check passes while the fix is disabled.
orph_stmt = src[src.rindex("orphans", 0, orph_at):orph_at]
check("...UNCONDITIONALLY, not only when the catalogue moved",
      True, orph_at > moved_at and "catalogue_moved" not in orph_stmt)
check("...and folded into the set that actually gets resolved",
      True, "set(orphans)" in src[want_at:want_at + 120])
check("...scoped to REACHABLE versions, so it cannot drag in history nothing can select",
      True, "{reachable}" in src[orph_at - 400:orph_at])

clean()
time.sleep = _real_sleep
print(f"\n  {passed} passed, {failed} failed")
sys.exit(1 if failed else 0)
