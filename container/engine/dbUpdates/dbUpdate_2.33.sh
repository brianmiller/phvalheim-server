#!/bin/bash

# all dbUpdater scripts must be executable!

# is this update already applied?
sql "DESCRIBE settings"|grep analyticsEnabled > /dev/null 2>&1
if [ ! $? = 0 ]; then
	echo "`date` [NOTICE : phvalheim] Applying database schema update for phvalheim-server >=v2.33"

	## BEGIN UPDATE ##

	# Analytics opt-in toggle (enabled by default)
	sql "ALTER TABLE settings ADD COLUMN analyticsEnabled TINYINT DEFAULT 1;"

	# Persistent UUID for this installation (generated on first startup)
	sql "ALTER TABLE settings ADD COLUMN analyticsUUID VARCHAR(36) DEFAULT '';"

	if [ ! $? = 0 ]; then
		# update failed to apply
		exit 1
	fi

	## END UPDATE ##
else
	# update is already applied
	exit 2
fi
