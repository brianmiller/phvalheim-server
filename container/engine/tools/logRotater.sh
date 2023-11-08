#!/bin/bash

# read max log size from database (default is 1000000 Bytes or 1 MB)
maxLogSize=$(/opt/stateless/engine/tools/sql "SELECT maxLogSize FROM settings")

# log files to rotate
logFiles=$(ls -a /opt/stateful/logs/*.log)

# assess and rotate
for logFile in $logFiles; do
	logSize=$(stat -c "%s" $logFile)

	if [ $logSize -gt $maxLogSize ]; then
		echo "`date` [NOTICE : logrotater] Rotating logs for $logFile... "
		cp -arfv $logFile $logFile.1  > /dev/null 2>&1
		echo "" > $logFile 
	fi
done

# update the database with new timestamps
/opt/stateless/engine/tools/sql "UPDATE systemstats SET logRotaterLastRun=NOW();"
