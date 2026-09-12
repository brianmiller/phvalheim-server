#!/bin/bash
# Release gate: the version being shipped MUST have release notes for the admin UI's
# "What's New" modal.
#
# Without this, the modal silently shows nothing on an upgrade -- the operator gets no
# indication anything changed, which is the exact failure the modal exists to prevent.
# A missing entry is invisible at runtime, so it has to be caught here.
#
# Run: ./dev_tools/check-whatsnew.sh

REPO="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
NOTES="$REPO/container/nginx/www/includes/whatsnew.php"
DOCKERFILE="$REPO/Dockerfile"

version=$(grep -oP 'ENV phvalheimVersion=\K.*' "$DOCKERFILE" | tr -d '"' | tr -d "'" | head -1)
if [ -z "$version" ]; then
	echo "FAIL  could not read phvalheimVersion from $DOCKERFILE"
	exit 1
fi

if [ ! -f "$NOTES" ]; then
	echo "FAIL  $NOTES is missing"
	exit 1
fi

if ! php -l "$NOTES" > /dev/null 2>&1; then
	echo "FAIL  $NOTES is not valid PHP"
	php -l "$NOTES"
	exit 1
fi

# Ask PHP, not grep: the entry must survive as a real non-empty array under the key.
count=$(php -r "
	require '$NOTES';
	\$n = whatsNewNotes();
	\$v = '$version';
	if (!isset(\$n[\$v])) { echo -1; exit; }
	\$items = array_filter(array_map('trim', (array)\$n[\$v]), function(\$s){ return \$s !== ''; });
	echo count(\$items);
" 2>/dev/null)

if [ "$count" = "-1" ]; then
	echo "FAIL  no What's New entry for v$version"
	echo "      Add one to $NOTES before releasing:"
	echo "          '$version' => ['What changed, in one sentence an operator can act on.'],"
	exit 1
fi

if [ "$count" = "0" ] || [ -z "$count" ]; then
	echo "FAIL  the What's New entry for v$version is empty"
	exit 1
fi

# The modal is keyed on the running version, so a typo'd key shows nothing at runtime
# while still "existing" in the file. Confirm the resolver actually surfaces it.
shown=$(php -r "
	require '$NOTES';
	\$out = whatsNewSince('', '$version');
	echo isset(\$out['$version']) ? 'yes' : 'no';
" 2>/dev/null)

if [ "$shown" != "yes" ]; then
	echo "FAIL  whatsNewSince() does not surface v$version to a first-time upgrader"
	exit 1
fi

echo "PASS  v$version has $count What's New item(s), and they render on upgrade"
exit 0
