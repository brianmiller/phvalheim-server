#!/bin/bash

# all dbUpdater scripts must be executable!

echo "`date` [NOTICE : phvalheim] Applying database schema update for phvalheim-server >=v2.42"

## BEGIN UPDATE ##

# --- settings table: one-shot "What's New" modal ---
#
# Stores the version whose release notes were last dismissed. A VERSION STRING, not a
# boolean, and that is the whole design: every script in dbUpdates/ runs on EVERY boot
# (dbUpdater.sh has no version gate), so a "shown" flag would need re-arming on each
# upgrade and an unconditional UPDATE re-raises it on every restart -- the trap
# dbUpdate_2.40.sh documents at length. Comparing stored-version against running-version
# is self-arming: dismissing sets them equal, and the next upgrade makes them differ
# again with no migration needed. 2.43 and everything after it needs no code here.
#
# Empty = never shown. On an upgrade that is what we want: the operator sees the notes for
# the version they just moved to.
sql "DESCRIBE settings"|awk '{print $1}'|grep -qx "whatsNewShownVersion" > /dev/null 2>&1
if [ ! $? = 0 ]; then
	echo "`date` [NOTICE : phvalheim] Adding settings.whatsNewShownVersion"
	sql "ALTER TABLE settings ADD COLUMN whatsNewShownVersion VARCHAR(16) DEFAULT '';"

	# A fresh install has nothing to be told what is new ABOUT -- it has never run an
	# older version. Mark the running version as seen so the wizard is not immediately
	# followed by a modal describing changes the operator never experienced.
	#
	# Worlds are the upgrade signal here, matching dbUpdate_2.40.sh. setupComplete is
	# not usable: the engine's env-var migration writes it on first boot and the
	# ordering against dbUpdater.sh is not guaranteed.
	worldCount=$(sql "SELECT COUNT(*) FROM worlds")
	case "$worldCount" in
		''|*[!0-9]*) worldCount=0 ;;
	esac
	#
	# Guarded on a non-empty version: writing '' would mean "never shown", which is the
	# exact opposite of what this branch is for.
	if [ "$worldCount" -eq 0 ] && [ -n "$phvalheimVersion" ]; then
		echo "`date` [NOTICE : phvalheim] Fresh install - marking v${phvalheimVersion} release notes as seen"
		sql "UPDATE settings SET whatsNewShownVersion = '${phvalheimVersion}';"
	fi
fi

## END UPDATE ##

exit 0
