#!/bin/sh
# Re-arm the admin UI's one-shot notices so they can be tested again.
#
# Every one of these fires exactly once and then sets a flag, which is correct for operators and
# useless for testing: once you have seen a notice you cannot see it again without hand-editing
# the database. This puts the flags back.
#
# Usage:
#   dev_tools/resetNotices.sh <container>                 # show current state, change nothing
#   dev_tools/resetNotices.sh <container> connect         # re-arm one
#   dev_tools/resetNotices.sh <container> all             # re-arm all of them
#
#   dev_tools/resetNotices.sh 37648-phvalheim1 connect
#
# Reload the admin UI afterwards. Nothing needs restarting -- the gate is read per page load.
#
# SAFE ON A LIVE SERVER: it only ever touches these flag columns. It does not alter a world, a
# mod, an access list or a setting that changes behaviour. The worst case is an operator seeing
# a dialog they have already dismissed.

set -e

CONTAINER="$1"
WHICH="$2"

if [ -z "$CONTAINER" ]; then
	sed -n '2,20p' "$0" | sed 's/^# \{0,1\}//'
	exit 1
fi

# name:column:ARMED VALUE:what it says
#
# The armed value is per-notice and not decoration. Most of these are booleans where 0 means
# "show me"; clientUpdate is a TRI-state (0 not triggered, 1 pending, 2 dismissed) and arming it
# means 1. Writing 0 there would say "nothing has happened yet", which is not the same thing and
# would make the next world update raise it again.
NOTICES='connect:connectNoticeShown:0:how players join your modded worlds has changed
clientUpdate:clientUpdateNoticeState:1:your players must update the PhValheim client (fires on the first world UPDATE)
hugin:huginNoticeShown:0:Hugin can act on your server
accessid:accessIdNoticeShown:0:access IDs were migrated to the V_ prefix
accessswitch:accessSwitchNoticeShown:0:the access model switch
migration:migrationNoticeShown:0:env vars were migrated into the database'

sql() { docker exec "$CONTAINER" /opt/stateless/engine/tools/sql "$1"; }

docker inspect "$CONTAINER" >/dev/null 2>&1 || { echo "no such container: $CONTAINER"; exit 1; }

# A column that does not exist means that migration has not run in this container. Say so rather
# than letting the UPDATE fail with a SQL error that reads like a broken script.
have_column() {
	sql "DESCRIBE settings" 2>/dev/null | awk '{print $1}' | grep -qx "$1"
}

echo
printf '%-14s %-26s %s\n' NOTICE COLUMN STATE
echo "---------------------------------------------------------------"
echo "$NOTICES" | while IFS=: read -r name col armed desc; do
	if have_column "$col"; then
		v=$(sql "SELECT IFNULL(MAX($col),0) FROM settings" 2>/dev/null | tr -d '[:space:]')
		# Read against the notice's OWN armed value rather than assuming 0 means show.
		if [ "$v" = "$armed" ]; then
			state="ARMED (will show)"
		elif [ "$col" = "clientUpdateNoticeState" ]; then
			case "$v" in
				0) state="not triggered yet (update a world to raise it)" ;;
				2) state="seen (dismissed)" ;;
				*) state="? ($v)" ;;
			esac
		else
			state="seen (dismissed)"
		fi
	else
		state="column absent -- migration not run here"
	fi
	printf '%-14s %-26s %s\n' "$name" "$col" "$state"
done
echo

[ -z "$WHICH" ] && { echo "Nothing changed. Pass a notice name, or 'all', to re-arm."; exit 0; }

# The What's New modal is NOT in the list above and is not reset here. It is keyed on a VERSION
# (settings.whatsNewShownVersion), not a boolean, so re-arming it means choosing which version to
# pretend was last seen -- and clearing it replays every release's notes, not this one's. Handle
# it deliberately:
#   sql "UPDATE settings SET whatsNewShownVersion = '2.52'"    # replay 2.53's notes only

echo "$NOTICES" | while IFS=: read -r name col armed desc; do
	if [ "$WHICH" = "all" ] || [ "$WHICH" = "$name" ]; then
		if have_column "$col"; then
			sql "UPDATE settings SET $col = $armed"
			echo "  re-armed $name ($col = $armed) -- $desc"
		else
			echo "  SKIPPED $name: settings.$col does not exist in this container"
		fi
	fi
done

# The subshell above cannot set `changed` in this shell, so re-derive it rather than report a
# success that may not have happened.
if [ "$WHICH" != "all" ] && ! echo "$NOTICES" | grep -q "^$WHICH:"; then
	echo "  unknown notice '$WHICH'. Valid: all, $(echo "$NOTICES" | cut -d: -f1 | tr '\n' ' ')"
	exit 1
fi

echo
echo "Reload the admin UI (8081). No restart needed."
