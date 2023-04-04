#!/bin/bash

# all dbUpdater scripts must be executable!

# is this update already applied?
sql "DESCRIBE systemstats"|grep tsSyncRemoteLastRun > /dev/null 2>&1
if [ ! $? = 0 ]; then
	echo "`date` [NOTICE : phvalheim] Applying database schema update for phvalheim-server >=v2.9"

	## BEGIN UPDATE ##
	
	# special case. this column is added during new database deployments. this is for existing deployments without the column
	sql "DESCRIBE systemstats"|grep tsUpdated > /dev/null 2>&1
	if [ ! $? = 0 ]; then
		sql "ALTER TABLE systemstats ADD COLUMN tsUpdated datetime;"
	fi

	sql "ALTER TABLE systemstats ADD COLUMN tsSyncLocalLastRun datetime;"
	sql "ALTER TABLE systemstats ADD COLUMN tsSyncRemoteLastRun datetime;"
	sql "ALTER TABLE systemstats ADD COLUMN worldBackupLastRun datetime;"
	sql "ALTER TABLE systemstats ADD COLUMN utilizationMonitorLastRun datetime;"

	if [ ! $? = 0 ]; then
		# update failed to apply
		exit 1
	fi

	## END UPDATE ##
else
	# update is already applied
	exit 2
fi
