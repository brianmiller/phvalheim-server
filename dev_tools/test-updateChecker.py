#!/usr/bin/env python3
"""Oracle tests for updateChecker's parsing.

Both cases below are ones a plausible-looking implementation gets wrong, and both were
actually got wrong first time:

  1. "public" appears seven times in real app_info_print output -- once per depot inside
     that depot's "manifests" section, and once under "branches". Only the last has a
     buildid. Anchoring on "public" alone finds a depot block and reports nothing.
  2. "buildid" appears once per branch, so the first match in the file is not necessarily
     the live one.

Run: dev_tools/test-updateChecker.py
"""

import json
import os
import sys

sys.path.insert(0, os.path.join(os.path.dirname(os.path.abspath(__file__)),
                                "..", "container", "engine", "tools"))

import updateChecker as uc  # noqa: E402

# Trimmed from real steamcmd output for app 896660: two depot manifest blocks that also
# use the key "public", followed by the branches section. Tabs preserved deliberately.
SAMPLE = '''"896660"
{
	"depots"
	{
		"896661"
		{
			"manifests"
			{
				"public"
				{
					"gid"		"7604377918839582995"
					"size"		"67863136"
					"download"		"24200000"
				}
			}
		}
		"896662"
		{
			"manifests"
			{
				"public"
				{
					"gid"		"2135377918839582995"
					"size"		"11863136"
					"download"		"4200000"
				}
			}
		}
		"branches"
		{
			"public"
			{
				"buildid"		"25253791"
				"timeupdated"		"1789132104"
			}
			"default_old"
			{
				"buildid"		"25185644"
				"description"		"Previous stable"
			}
			"default_pre1_0"
			{
				"buildid"		"21981590"
				"description"		"Last stable build before 1.0"
			}
		}
	}
}
'''

pass_count = 0
fail_count = 0


def check(name, expected, actual):
    global pass_count, fail_count
    if actual == expected:
        print(f"  PASS  {name}")
        pass_count += 1
    else:
        print(f"  FAIL  {name}")
        print(f"          expected: {expected!r}")
        print(f"          actual:   {actual!r}")
        fail_count += 1


print("updateChecker parser tests\n")

# The whole point: skip six depot "public" blocks, land on the branch one.
check("public branch buildid, past the depot blocks", "25253791",
      uc.parse_public_buildid(SAMPLE))

# Must not return default_old, which is the buildid our test world actually had installed.
check("does not return default_old", True,
      uc.parse_public_buildid(SAMPLE) != "25185644")

check("no branches section -> empty, not a guess", "",
      uc.parse_public_buildid('"896660"\n{\n\t"depots"\n\t{\n\t}\n}\n'))

check("empty input -> empty", "", uc.parse_public_buildid(""))

# A branches section whose public block somehow carries no buildid must report nothing
# rather than falling through to another branch's number.
check("public block with no buildid -> empty", "",
      uc.parse_public_buildid('"branches"\n{\n\t"public"\n\t{\n\t\t"timeupdated" "1"\n\t}\n}\n'))

# --- steamcmd must be given a writable HOME ------------------------------------------
#
# This is the regression guard for the bug that made every world report "up to date"
# forever in production. updateChecker runs as the phvalheim user from cron and from the
# admin API, and that user's default HOME is not writable. steamcmd bootstraps into
# $HOME/.local and $HOME/.steam, so without an explicit HOME it dies before printing any
# app info and available_buildid() returns "".
#
# It passed every test and every manual run beforehand because those were done as root.
captured = {}


class FakeResult:
    def __init__(self, stdout):
        self.returncode = 0
        self.stdout = stdout
        self.stderr = ""


def fake_run(cmd, **kwargs):
    # This stands in for BOTH the steamcmd call and the mysql calls the checker makes
    # around it, so it has to look like a finished process either way -- returncode
    # included, or sql() raises before the assertion under test is ever reached.
    if cmd and str(cmd[0]).endswith("steamcmd"):
        captured["env"] = kwargs.get("env")
        captured["cmd"] = cmd
        return FakeResult(SAMPLE)
    return FakeResult("")


_real_run = uc.subprocess.run
uc.subprocess.run = fake_run
try:
    # 0 = never use the cache, so the steamcmd path is definitely exercised.
    uc.available_buildid(0)
finally:
    uc.subprocess.run = _real_run

check("steamcmd is given an explicit HOME", True,
      captured.get("env", {}) is not None and "HOME" in (captured.get("env") or {}))
check("that HOME is the steam home, not the inherited one", uc.STEAM_HOME,
      (captured.get("env") or {}).get("HOME"))

# --- a mod snapshot with no versions is UNKNOWN, not "up to date" ---------------------
#
# Regression guard for the second instance of the same bug as the buildid one: missing data
# rendered as the reassuring answer. worlds.modsViewer only carries per-mod versions from
# 2.43; older entries are {name,url,uuid}. The first version of mod_updates() skipped those
# with `if not have: continue`, so every one of them produced a count of 0 and a green "up
# to date". Measured on a real server: 28 of 35 worlds.

LEGACY = json.dumps([{"name": "EpicLoot", "url": "x", "uuid": "1"},
                     {"name": "Jotunn", "url": "y", "uuid": "2"}])
MODERN = json.dumps([{"name": "EpicLoot", "owner": "RandyKnapp", "source": "thunderstore",
                      "version": "0.9.0", "pinned": False}])


def with_fake_db(viewer, catalogue, fn):
    real_one, real_rows = uc.one, uc.rows
    uc.one = lambda qy: viewer
    uc.rows = lambda qy: catalogue
    try:
        return fn()
    finally:
        uc.one, uc.rows = real_one, real_rows


cat = [["thunderstore", "RandyKnapp", "EpicLoot", "0.9.9"]]

count, stale, err = with_fake_db(LEGACY, cat, lambda: uc.mod_updates("w"))
check("legacy snapshot reports an error, not a clean bill", True, bool(err))
check("legacy snapshot does not claim updates either", 0, count)

count, stale, err = with_fake_db(MODERN, cat, lambda: uc.mod_updates("w"))
check("modern snapshot detects the newer version", 1, count)
check("modern snapshot reports no error", "", err)

count, stale, err = with_fake_db("", cat, lambda: uc.mod_updates("w"))
check("no mods at all is an honest zero, not an error", (0, ""), (count, err))

print(f"\n  {pass_count} passed, {fail_count} failed")
sys.exit(1 if fail_count else 0)
