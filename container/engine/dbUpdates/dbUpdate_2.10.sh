#!/bin/bash

# all dbUpdater scripts must be executable!

# is this update already applied?
sql "DESCRIBE systemstats"|grep logRotaterLastRun > /dev/null 2>&1
if [ ! $? = 0 ]; then
	echo "`date` [NOTICE : phvalheim] Applying database schema update for phvalheim-server >=v2.10"

	## BEGIN UPDATE ##
	
	# create our new settings table
	sql "CREATE TABLE settings (\
	id INTEGER PRIMARY KEY NOT NULL AUTO_INCREMENT,\
	maxLogSize INT DEFAULT 1000000,\
	backupsToKeep INT DEFAULT 24,\
	steamApiKey TEXT\
	);"

	# add column to systemstats
	sql "ALTER TABLE systemstats ADD COLUMN logRotaterLastRun datetime;"
	sql "ALTER TABLE systemstats ADD COLUMN currentCpuUtilization INT;"
	sql "ALTER TABLE systemstats ADD COLUMN tsSyncLocalLastExecStatus TEXT;"
	sql "ALTER TABLE systemstats ADD COLUMN tsSyncRemoteLastExecStatus TEXT;"
	sql "ALTER TABLE systemstats ADD COLUMN worldBackupLastExecStatus TEXT;"
	sql "ALTER TABLE systemstats ADD COLUMN logRotateLastExecStatus TEXT;"
	sql "ALTER TABLE systemstats ADD COLUMN utilizationMonitorLastExecStatus TEXT;"

	sql "ALTER TABLE systemstats DROP COLUMN memFree;"
	sql "ALTER TABLE systemstats DROP COLUMN memTotal;"
	sql "ALTER TABLE systemstats DROP COLUMN cpuTotalMhz;"
	sql "ALTER TABLE systemstats DROP COLUMN cpuFreeMhz;"
	sql "ALTER TABLE systemstats DROP COLUMN timestamp;"

        # insert
        sql "INSERT into settings SET maxLogSize=1000000"
        sql "UPDATE settings SET backupsToKeep=24"
	sql "UPDATE systemstats SET tsSyncLocalLastExecStatus='idle'"
	sql "UPDATE systemstats SET tsSyncRemoteLastExecStatus='idle'"
	sql "UPDATE systemstats SET worldBackupLastExecStatus='idle'"
	sql "UPDATE systemstats SET logRotateLastExecStatus='idle'"
	sql "UPDATE systemstats SET utilizationMonitorLastExecStatus='idle'"


	if [ ! $? = 0 ]; then
		# update failed to apply
		exit 1
	fi

	## END UPDATE ##
else
	# update is already applied
	exit 2
fi
