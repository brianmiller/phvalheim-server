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

print(f"\n  {pass_count} passed, {fail_count} failed")
sys.exit(1 if fail_count else 0)
