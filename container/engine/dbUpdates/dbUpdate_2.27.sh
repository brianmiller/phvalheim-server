#!/bin/bash

# all dbUpdater scripts must be executable!

# is this update already applied?
sql "DESCRIBE tsmods"|grep deps_uuids > /dev/null 2>&1
if [ ! $? = 0 ]; then
	echo "`date` [NOTICE : phvalheim] Applying database schema update for phvalheim-server >=v2.27"

	## BEGIN UPDATE ##

	# new column to store pre-resolved dependency UUIDs (space-separated moduuids)
	sql "ALTER TABLE tsmods ADD COLUMN deps_uuids TEXT DEFAULT NULL;"

	if [ ! $? = 0 ]; then
		# update failed to apply
		exit 1
	fi

	## END UPDATE ##
else
	# update is already applied
	exit 2
fi
