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

echo
echo "$PASS passed, $FAIL failed"
[ "$FAIL" -eq 0 ]
