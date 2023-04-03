#!/bin/bash

# pull and convert remote timestamp to epoch of tsmods_seed.sql from GitHub
remoteLastChanged=$(curl -s "https://api.github.com/repos/brianmiller/phvalheim-server/commits?path=container%2Fmysql/%2Ftsmods_seed.sql&page=1&per_page=1"|jq -r '.[0].commit.committer.date')
remoteLastChanged=$(date --date="$remoteLastChanged" +"%s")


# pull and convert local timestamp to epoch from local MySQL database
localLastChanged=$(sql "SELECT UPDATE_TIME FROM information_schema.tables WHERE TABLE_SCHEMA = 'phvalheim' AND TABLE_NAME = 'tsmods';")
localLastChanged=$(date --date="$localLastChanged" +"%s")


# if either timestamps cannot be determined, log it and exit
if [ -z $remoteLastChanged ] && [ -z $localLastChanged ]; then
	echo "Error: Could not determine one or more timestamp(s)."
	exit 1
fi

# test, make the remote version appear to be newer
#remoteLastChanged="6666666666666"

# if remote is newer than local, update local
if [ $remoteLastChanged -gt $localLastChanged ]; then

	echo "`date` [NOTICE : phvalheim] Remote Thunderstore database is newer than the local database."

	# if a sync is currently running, kill it.
	previousPID=$(cat /tmp/tsSync.pid)
	ps -p $previousPID > /dev/null 2>&1
	RESULT=$?
	if [ "$RESULT" = 0 ]; then
        	echo "`date` [NOTICE: phvalheim] A previously scheduled Thunderstore sync is running, we're stopping it and pulling from GitHub instead..."
        	kill -9 $previousPID
	fi

	echo "`date` [NOTICE : phvalheim] Downloading a newer copy of the Thunderstore database from GitHub..."
	/usr/bin/wget -q https://github.com/brianmiller/phvalheim-server/raw/master/container/mysql/tsmods_seed.sql -O /opt/stateful/.tsmods_update.sql 

	echo "`date` [NOTICE : phvalheim] Updating local Thunderstore database..."
	/usr/bin/mysql phvalheim < /opt/stateful/.tsmods_update.sql		
	
fi
