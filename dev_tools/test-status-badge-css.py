#!/usr/bin/env python3
"""Oracle test for the status pill styling, and for the CSS mistake that broke it.

2.47 inserted `@keyframes auProgress` into the MIDDLE of this selector list:

    .status-badge.start,
    .status-badge.stop,
    ... ,
    .status-badge.deleting,
    @keyframes auProgress { ... }        <-- here
    .status-badge.backup { background: ...; animation: status-pulse ...; }

A selector list that ends on a comma followed by an at-rule is not recoverable: the parser
throws the whole thing away, declarations included. `.backup` happened to be the last
selector, so it survived as its own valid rule -- which is why Backup pulsed orange while
Updating, Starting, Stopping, Creating and Deleting all silently went flat grey.

Nothing reports this. The stylesheet still loads, no console warning, no failed request, and
the pill still renders -- just in the base grey. It was found by a person looking at a screen
during a real update, which is exactly the kind of thing a test should be catching instead.

Run: dev_tools/test-status-badge-css.py
"""

import os
import re
import sys

CSS = os.path.join(os.path.dirname(os.path.abspath(__file__)),
                   "..", "container", "nginx", "www", "css", "phvalheimStyles.css")

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


def parse(path):
    """Top-level rules -> ({selector: declarations}, [selector lists containing an at-rule]).

    Deliberately NOT a regex over the whole file. The bug is structural -- an at-rule where a
    selector belongs -- so the test has to walk brace depth the way a parser does, or it
    cannot see the thing it exists to catch.
    """
    src = re.sub(r"/\*.*?\*/", "", open(path).read(), flags=re.S)
    decls, bad, buf, i = {}, [], "", 0
    while i < len(src):
        if src[i] == "{":
            sel, buf = buf.strip(), ""
            depth, j = 1, i + 1
            while j < len(src) and depth:
                if src[j] == "{":
                    depth += 1
                elif src[j] == "}":
                    depth -= 1
                j += 1
            body = src[i + 1:j - 1]
            if sel.lstrip().startswith("@"):
                pass                      # a real at-rule, fine
            elif "@" in sel:
                bad.append(" ".join(sel.split())[:100])
            else:
                for s in sel.split(","):
                    decls[s.strip()] = decls.get(s.strip(), "") + body
            i = j
            continue
        buf += src[i]
        i += 1
    return decls, bad


print("\nstatus badge CSS\n")
decls, bad = parse(CSS)

# The structural check. Catches this mistake anywhere in the file, not just on status badges.
check("no selector list contains an at-rule", [], bad)

# Every transitional state must actually carry the orange + pulse. Checking `.backup` alone
# would have passed throughout the entire time this was broken -- it is the one that survived.
for state in ("start", "stop", "starting", "stopping", "create", "update",
              "delete", "creating", "updating", "deleting", "backup"):
    body = decls.get(f".status-badge.{state}", "")
    check(f".status-badge.{state} is styled and pulses", (True, True),
          ("background" in body, "animation" in body))

# The keyframes both of those animations name must exist, or the animation silently no-ops.
raw = open(CSS).read()
for kf in ("status-pulse", "auProgress", "pulse"):
    check(f"@keyframes {kf} is defined", True,
          re.search(r"@keyframes\s+" + kf + r"\s*\{", raw) is not None)

# Running and stopped are NOT transitional and must not pulse -- a permanently throbbing
# "Running" pill on every row would be worse than the bug this file guards.
for state in ("running", "stopped"):
    body = decls.get(f".status-badge.{state}", "")
    check(f".status-badge.{state} does not pulse", True, "animation: none" in body)

print(f"\n  {pass_count} passed, {fail_count} failed")
sys.exit(1 if fail_count else 0)
