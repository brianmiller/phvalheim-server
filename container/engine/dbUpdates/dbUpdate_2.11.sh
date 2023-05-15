#!/bin/bash

# all dbUpdater scripts must be executable!

# is this update already applied?
sql "DESCRIBE worlds"|grep thunderstore_mods_deps > /dev/null 2>&1
if [ ! $? = 0 ]; then
	echo "`date` [NOTICE : phvalheim] Applying database schema update for phvalheim-server >=v2.11"

	## BEGIN UPDATE ##
	# add column
	sql "ALTER TABLE worlds ADD COLUMN thunderstore_mods_deps TEXT;"

	# set all worlds to "update" after database change
	sql "UPDATE worlds SET mode='update';"


	if [ ! $? = 0 ]; then
		# update failed to apply
		exit 1
	fi

	## END UPDATE ##
else
	# update is already applied
	exit 2
fi
