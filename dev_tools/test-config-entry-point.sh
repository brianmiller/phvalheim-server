#!/bin/bash
# The Mod Configs page must be REACHABLE from a running world.
#
# 2.55 shipped the editor saying "Rebuilds the client payload for <world>. The world keeps
# running." while the only link to it was inside edit_world.php, which is gated behind
# "Edit Mods" -- a button that is disabled whenever the world is online. So the one state the
# feature was built for was the one state an operator could not reach it from. The engine was
# always right (`repackage` accepts mode IN ('running','stopped')); only the entry point was
# missing, which is why nothing server-side caught it.
#
# These assertions are written so they FAIL on the shipped 2.55 tree. Run with --self-test to
# prove that against git rather than trusting this comment.

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

    # 1. THE BUG. The online/running world card must offer a link to the configs page.
    #    Scoped to the online table's action group, not the whole file -- the offline card
    #    has always been able to reach it via Edit Mods, so a file-wide grep would pass
    #    on the broken tree and prove nothing.
    local onlineBlock
    onlineBlock=$(awk '/<table class="worlds-table" id="worldsTable">/,/<!-- Offline Worlds Section -->/' "$idx")
    local n
    n=$(printf '%s' "$onlineBlock" | grep -c 'world_configs.php')
    [ "$n" -ge 1 ] && ok "online world card links to world_configs.php" \
                   || bad "online world card links to world_configs.php" \
                          "at least one world_configs.php href in the online table (got $n)"

    # 2. The JS row template must enable it for running AND stopped, and only those.
    n=$(grep -c 'modConfigsButtonHtml(world, true)' "$idx")
    check "JS enables Configs in both the running and stopped branches" \
          "two modConfigsButtonHtml(world, true) calls" "$n" "2"

    n=$(grep -c 'modConfigsButtonHtml(world, false)' "$idx")
    check "JS disables Configs in the transitional branch" \
          "one modConfigsButtonHtml(world, false) call" "$n" "1"

    # 3. The 5-second poll must keep it live for a running world. Without this the button is
    #    correct on page load and wrong a few seconds later -- the exact failure mode that
    #    has bitten this dashboard before.
    if grep -q "world.mode === 'running' || world.mode === 'stopped'" "$idx"; then
        ok "the poll updater keeps Configs reachable while running"
    else
        bad "the poll updater keeps Configs reachable while running" \
            "a mod-configs refresh gated on running OR stopped"
    fi

    # 4. CONTROL -- the fix must not have loosened the gate next to it. Editing the mod LIST
    #    rebuilds the modpack and must still be refused while the world is up. Without this,
    #    "make the page reachable" could be satisfied by simply enabling Edit Mods, which
    #    would be a far worse bug than the one being fixed.
    if grep -q 'editModsBtn.outerHTML = `<span class="action-btn disabled" data-action="edit-mods">Edit Mods</span>`' "$idx"; then
        ok "CONTROL: Edit Mods is still disabled for a non-stopped world"
    else
        bad "CONTROL: Edit Mods is still disabled for a non-stopped world" \
            "the poll updater still disabling edit-mods when mode is not stopped"
    fi

    # 5. CONTROL -- a vanilla world has no mods and therefore no mod configs, in every branch.
    if grep -q 'if (world.vanilla)' "$idx" && \
       grep -q 'vanilla world — it runs no mods, so it has no mod configs' "$idx"; then
        ok "CONTROL: a vanilla world still gets no Configs link"
    else
        bad "CONTROL: a vanilla world still gets no Configs link" \
            "a vanilla branch in modConfigsButtonHtml"
    fi
}

if [ "$1" = "--self-test" ]; then
    # Prove the suite can see the bug: run it against the last commit, which is the shipped
    # 2.55 tree. A suite that passes on the broken code is not testing anything.
    tmp=$(mktemp -d)
    if ! git -C "$REPO" show HEAD:container/nginx/www/admin/index.php > "$tmp/index.php" 2>/dev/null; then
        echo "SELF-TEST SKIPPED: cannot read index.php from HEAD"
        rm -rf "$tmp"; exit 0
    fi
    echo "=== self-test: running the suite against HEAD (pre-fix) -- failures are EXPECTED ==="
    run_suite "$tmp/index.php"
    rm -rf "$tmp"
    echo
    if [ "$fail" -gt 0 ]; then
        echo "SELF-TEST OK: $fail assertion(s) correctly failed on the pre-fix tree."
        exit 0
    fi
    echo "SELF-TEST FAILED: the suite passed on the pre-fix tree, so it cannot see the bug."
    exit 1
fi

echo "=== Mod Configs entry point ==="
run_suite "$IDX"
echo
echo "$pass passed, $fail failed"
[ "$fail" -eq 0 ]
