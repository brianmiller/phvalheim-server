#!/bin/bash
# Everything mod-related must be REACHABLE from a world row, in the state it claims to work in.
#
# History, because each step here was a shipped bug:
#   2.55 shipped the mod-config editor saying "Rebuilds the client payload for <world>. The
#   world keeps running." while the only link to it was inside edit_world.php, behind an
#   "Edit Mods" button that is disabled whenever the world is online. The one state the feature
#   was built for was the one state an operator could not reach it from. The engine was always
#   right (`repackage` accepts mode IN ('running','stopped')); only the entry point was missing,
#   which is why nothing server-side caught it.
#   Then the Configs button that fixed it called escapeHtml() -- a function this file does not
#   define -- so the first mod row threw ReferenceError, the modal's own catch reported
#   "Error loading mod configs", and the endpoint was answering 200 the whole time.
#   Then the row carried THREE mod buttons (Edit Mods / Configs / View N) with three different
#   availability rules and no way to tell which rule was greying which button. 2.56 replaces
#   them with one Mods button and a two-card hub.
#
# This is the cheap structural gate: it greps, so it runs anywhere, including inside the image
# build. It cannot tell whether the page RUNS -- that is dev_tools/test-mods-hub.js, which
# drives a real browser and is the authority. Keep both: this one catches a deleted branch in
# a second, that one catches a page that renders and then throws.
#
# Run with --self-test to prove these assertions against git rather than trusting this comment.

REPO="$(cd -- "$(dirname -- "${BASH_SOURCE[0]}")/.." && pwd)"
IDX="$REPO/container/nginx/www/admin/index.php"

pass=0; fail=0
ok()  { echo "  PASS  $1"; pass=$((pass+1)); }
bad() { echo "  FAIL  $1"; echo "        expected: $2"; fail=$((fail+1)); }

check() { # desc, expected-desc, actual-count, wanted-count
    if [ "$3" = "$4" ]; then ok "$1"; else bad "$1" "$2 (want $4, got $3)"; fi
}

run_suite() {
    local idx="$1"
    local n

    # 1. THE ORIGINAL BUG. The online/running world card must offer a mods entry point.
    #    Scoped to the online table's action group, not the whole file -- the offline card
    #    has always been able to reach it, so a file-wide grep would pass on the broken tree
    #    and prove nothing.
    local onlineBlock
    onlineBlock=$(awk '/<table class="worlds-table" id="worldsTable">/,/<!-- Offline Worlds Section -->/' "$idx")
    n=$(printf '%s' "$onlineBlock" | grep -c 'showModsHub')
    [ "$n" -ge 1 ] && ok "online world card opens the Mods hub" \
                   || bad "online world card opens the Mods hub" \
                          "at least one showModsHub() call in the online table (got $n)"

    # 2. One door, not four. The three old actions must be gone from EVERY render site --
    #    there are five (two PHP cards, three JS branches) plus the poll updater, and a
    #    leftover in any one of them makes a button appear or vanish five seconds after load.
    n=$(grep -cE 'data-action="(edit-mods|mod-configs|view-mods)"' "$idx")
    check "the three old mod buttons are gone from every render site" \
          "no edit-mods / mod-configs / view-mods actions left" "$n" "0"

    # 3. The hub needs its three parts: the button helper, the card renderer, the installed
    #    list. All three are called from markup or from each other, so a missing one is a
    #    ReferenceError at click time, not a load-time error.
    local missing=""
    for fn in modsButtonHtml showModsHub renderModsHubCards loadModsHubInstalled closeModsHub; do
        grep -q "function $fn" "$idx" || missing="$missing $fn"
    done
    if [ -z "$missing" ]; then
        ok "the hub's five functions all exist"
    else
        bad "the hub's five functions all exist" "missing:$missing"
    fi

    # 4. The button must be rendered by the JS row template in all three mode branches, and
    #    by the poll updater. Two enabled (running, stopped), one disabled (transitional).
    n=$(grep -c 'modsButtonHtml(world, true)' "$idx")
    check "JS enables Mods in both the running and stopped branches" \
          "two modsButtonHtml(world, true) calls" "$n" "2"

    n=$(grep -c 'modsButtonHtml(world, false)' "$idx")
    check "JS disables Mods in the transitional branch" \
          "one modsButtonHtml(world, false) call" "$n" "1"

    # 5. The 5-second poll must keep it live for a RUNNING world. Without this the button is
    #    correct on page load and wrong a few seconds later -- the exact failure mode that has
    #    bitten this dashboard before.
    if grep -q "world.mode === 'running' || world.mode === 'stopped'" "$idx"; then
        ok "the poll updater keeps Mods reachable while running"
    else
        bad "the poll updater keeps Mods reachable while running" \
            "a mods refresh gated on running OR stopped"
    fi

    # 6. CONTROL -- the consolidation must not have loosened the gate it absorbed. Editing the
    #    mod LIST calls updateWorld(), and mode='update' ALWAYS ends stopped, so doing it under
    #    a live world drops every connected player. The hub must still refuse it for anything
    #    but a stopped world. Without this, "one Mods button" could be satisfied by simply
    #    making both cards always available, which would be a far worse bug than the one fixed.
    if grep -q "const stopped = mode === 'stopped';" "$idx" && \
       grep -q "card(stopped," "$idx"; then
        ok "CONTROL: the Mod Catalog card is still gated on a stopped world"
    else
        bad "CONTROL: the Mod Catalog card is still gated on a stopped world" \
            "renderModsHubCards deriving `stopped` and gating the catalogue card on it"
    fi

    # 7. CONTROL -- and the OTHER card must stay open while running. Gating both would put back
    #    the original 2.55 bug in a new place.
    if grep -q "const live    = mode === 'running' || mode === 'stopped';" "$idx" && \
       grep -q "card(live," "$idx"; then
        ok "CONTROL: the Mod Configs card is still open on a running world"
    else
        bad "CONTROL: the Mod Configs card is still open on a running world" \
            "renderModsHubCards deriving \`live\` and gating the configs card on it"
    fi

    # 8. CONTROL -- a vanilla world has no mods, in every branch.
    if grep -q 'if (world.vanilla)' "$idx" && \
       grep -q 'vanilla world — it runs no mods' "$idx"; then
        ok "CONTROL: a vanilla world still gets no Mods link"
    else
        bad "CONTROL: a vanilla world still gets no Mods link" \
            "a vanilla branch in modsButtonHtml"
    fi

    # 9. The picker's JS must only call names that EXIST. It shipped calling escapeHtml(),
    #    which is not defined in this file -- so the first mod row threw ReferenceError and the
    #    modal's own catch reported it to the operator as "Error loading mod configs", with the
    #    endpoint answering 200 and correct JSON the whole time. Every other assertion here
    #    passed on that tree, because they all grep for strings and a string cannot tell you
    #    whether the code around it can run.
    if node "$REPO/dev_tools/check-configs-picker-js.js" "$idx" --control > /tmp/cfgjs.$$ 2>&1; then
        ok "the hub and picker JS call only defined names (with its own control)"
    else
        bad "the hub and picker JS call only defined names (with its own control)" \
            "$(cat /tmp/cfgjs.$$)"
    fi
    rm -f /tmp/cfgjs.$$
}

if [ "$1" = "--self-test" ]; then
    # Prove the suite can see the regression: run it against the last commit. A suite that
    # passes on the previous tree is not testing this change.
    tmp=$(mktemp -d)
    if ! git -C "$REPO" show HEAD:container/nginx/www/admin/index.php > "$tmp/index.php" 2>/dev/null; then
        echo "SELF-TEST SKIPPED: cannot read index.php from HEAD"
        rm -rf "$tmp"; exit 0
    fi
    echo "=== self-test: running the suite against HEAD -- failures are EXPECTED ==="
    run_suite "$tmp/index.php"
    rm -rf "$tmp"
    echo
    if [ "$fail" -gt 0 ]; then
        echo "SELF-TEST OK: $fail assertion(s) correctly failed on the previous tree."
        exit 0
    fi
    echo "SELF-TEST FAILED: the suite passed on the previous tree, so it cannot see the change."
    exit 1
fi

echo "=== Mods entry point ==="
run_suite "$IDX"
echo
echo "$pass passed, $fail failed"
[ "$fail" -eq 0 ]
