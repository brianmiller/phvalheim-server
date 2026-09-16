#!/bin/bash
# Tests for the admin UI's one-shot "What's New" modal resolver.
#
# The modal is invisible when it misbehaves -- a wrong verdict either shows release notes
# on every page load forever, or never shows them at all and the operator never learns
# what changed. Neither announces itself, so the selection logic is tested directly.
#
# Run: ./dev_tools/test-whatsnew.sh

REPO="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
NOTES="$REPO/container/nginx/www/includes/whatsnew.php"

PASS=0
FAIL=0
ok(){ echo "  PASS  $1"; PASS=$((PASS+1)); }
no(){ echo "  FAIL  $1"; FAIL=$((FAIL+1)); }

# versions shown for (shownVersion, currentVersion), newest first, comma separated
resolve(){
	php -r "
		require '$NOTES';
		\$notes = ['2.41'=>['a'], '2.42'=>['b'], '2.43'=>['c'], '2.99'=>['future']];
		echo implode(',', array_keys(whatsNewSince('$1', '$2', \$notes)));
	" 2>/dev/null
}

expect(){
	local desc="$1" got="$2" want="$3"
	if [ "$got" = "$want" ]; then ok "$desc"; else no "$desc (got '$got', want '$want')"; fi
}

echo "What's New resolver"

# A database that has never shown the modal sees ONLY what it just upgraded to --
# not every historical release at once.
expect "first-ever upgrade shows only the running version" "$(resolve '' '2.42')" "2.42"

# The normal case: one upgrade, one set of notes.
expect "2.41 -> 2.42 shows 2.42" "$(resolve '2.41' '2.42')" "2.42"

# Skipping a release must not skip its notes: everything AFTER the seen version, and the
# seen version's own notes are not replayed.
expect "2.41 -> 2.43 shows 2.43 and 2.42, newest first" "$(resolve '2.41' '2.43')" "2.43,2.42"

# Already dismissed on this version -- the modal must stay gone across page loads and
# restarts. This is the check that catches a modal that reappears forever.
expect "already seen means nothing to show" "$(resolve '2.42' '2.42')" ""

# Notes may be written before their release ships; they must not leak early.
expect "notes newer than the running version stay hidden" "$(resolve '2.42' '2.43')" "2.43"

# A version with no env (getenv returned false) must not produce a modal.
expect "unknown running version shows nothing" "$(resolve '2.41' '')" ""

# --- the shipped notes, not the synthetic ones ---
if php -l "$NOTES" > /dev/null 2>&1; then
	ok "whatsnew.php is valid PHP"
else
	no "whatsnew.php is not valid PHP"
fi

# Every shipped entry must be a non-empty list of non-empty strings, or the modal
# renders an empty bullet list.
bad=$(php -r "
	require '$NOTES';
	\$bad = [];
	foreach (whatsNewNotes() as \$v => \$items) {
		if (!is_array(\$items) || count(\$items) === 0) { \$bad[] = \$v; continue; }
		foreach (\$items as \$i) { if (trim((string)\$i) === '') { \$bad[] = \$v; break; } }
	}
	echo implode(',', \$bad);
" 2>/dev/null)
if [ -z "$bad" ]; then
	ok "every shipped release entry has real content"
else
	no "empty release notes for: $bad"
fi


# --- the always-available What's New button (2.47) -------------------------------------
#
# index.php resolves TWO values, and the difference between them is the whole feature:
#
#   $whatsNewAuto = whatsNewSince($shown, $current)  -- unseen notes; non-empty auto-opens
#   $whatsNew     = $whatsNewAuto ?: whatsNewSince('', $current)  -- what the modal CONTAINS
#
# Before this, the modal was only emitted into the DOM when there were unseen notes, so once
# you clicked "Got it" there was no way to read the notes for the version you were running.
# The bug this guards against is the obvious simplification -- rendering the modal from
# $whatsNewAuto alone -- which puts a button in the header that opens nothing.
echo
echo "What's New button"

# Runs index.php's OWN resolution, lifted out of the file and evaluated.
#
# NOT a re-implementation of it. The first version of this test mirrored the two lines here
# instead, which meant it passed happily while index.php was reverted to the broken
# behaviour -- a test that cannot see the bug it was written for. Extracting and evaluating
# the real statements is what makes it an oracle.
#
# Echoes "<auto versions>|<modal content versions>".
INDEX="$REPO/container/nginx/www/admin/index.php"
resolveBoth(){
	php -r "
		require '$NOTES';
		\$setupComplete = 2;
		\$whatsNewShownVersion = '$1';
		\$phvalheimVersion = '$2';
		\$src = file_get_contents('$INDEX');
		if (!preg_match('/\\\$whatsNewAuto = .*?;\s*\\\$whatsNew = .*?;/s', \$src, \$m)) {
			echo 'NO-RESOLVE-BLOCK-IN-INDEX'; exit;
		}
		eval(\$m[0]);
		echo implode(',', array_keys(\$whatsNewAuto)) . '|' . implode(',', array_keys(\$whatsNew));
	" 2>/dev/null
}

# Real shipped versions, because the extracted statements call whatsNewSince() with two
# arguments and so use the real notes. 2.45/2.46/2.47 are history and cannot change.
#
# THE case the feature exists for. Nothing unseen, so no auto-open -- but the modal must
# still contain the running version's notes, or the header button opens nothing. This is the
# assertion that fails if the fallback is ever simplified away.
expect "dismissed: no auto-open, but the notes are still there" \
	"$(resolveBoth '2.47' '2.47')" "|2.47"

# An upgrade still auto-opens, and still shows everything since the last dismissal rather
# than just the newest release.
expect "upgrade 2.45 -> 2.47: auto-opens with both versions" \
	"$(resolveBoth '2.45' '2.47')" "2.47,2.46|2.47,2.46"

# First ever run: auto-opens with only the running version, and the button agrees.
expect "first-ever upgrade: auto-opens with the running version only" \
	"$(resolveBoth '' '2.46')" "2.46|2.46"

# No version at all -- neither value may produce a modal, and index.php hides the button
# when the content is empty, so the button cannot open nothing.
expect "unknown running version: no modal, no button" \
	"$(resolveBoth '2.46' '')" "|"

# A version that ships no notes. check-whatsnew.sh makes this impossible for a real release,
# but a work-in-progress bump hits it, and the button must hide rather than open an empty box.
# 9.98/9.99 rather than the next real version, so this does not start failing when it ships.
expect "version with no notes: no modal, no button" \
	"$(resolveBoth '9.98' '9.99')" "|"

# --- the wiring in index.php ------------------------------------------------------------

# The modal must be gated on $whatsNew (has content) and NOT on $whatsNewAuto (has unseen
# content). Gating on the latter is exactly the bug being fixed.
if grep -q 'if (!empty(\$whatsNew)):' "$INDEX"; then
	ok "modal is rendered whenever there are notes, not only unseen ones"
else
	no "modal is not gated on \$whatsNew"
fi

# Auto-open is the ONLY thing that may depend on there being unseen notes.
if grep -q "empty(\$whatsNewAuto) ? '' : ' show'" "$INDEX"; then
	ok "auto-open is gated on unseen notes"
else
	no "auto-open is not gated on \$whatsNewAuto"
fi

# The button must be hidden when there is nothing to open.
btnGuard=$(grep -c 'id="whatsNewBtn"' "$INDEX")
if [ "$btnGuard" = "1" ] && grep -B4 'id="whatsNewBtn"' "$INDEX" | grep -q 'if (!empty(\$whatsNew)):'; then
	ok "header button is hidden when there are no notes"
else
	no "header button is not guarded by \$whatsNew"
fi

# Closing an already-acknowledged modal must not depend on a round trip: a failed request
# would otherwise leave a dialog the operator opened themselves stuck open.
if grep -q 'if (!window.whatsNewPending)' "$INDEX"; then
	ok "closing an acknowledged modal needs no server round trip"
else
	no "close path does not short-circuit on whatsNewPending"
fi

# ...but an UNACKNOWLEDGED one must still persist before closing, or it reappears forever.
if grep -A8 'if (!window.whatsNewPending)' "$INDEX" | grep -q "action=dismissWhatsNew"; then
	ok "an unacknowledged modal still records the dismissal"
else
	no "the dismissal is no longer recorded"
fi

echo
echo "$PASS passed, $FAIL failed"
[ "$FAIL" -eq 0 ]
