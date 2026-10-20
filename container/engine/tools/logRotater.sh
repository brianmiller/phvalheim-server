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
		cp -prfv $logFile $logFile.1  > /dev/null 2>&1
		echo "" > $logFile 
	fi
done

# The event log (2.56): keep 30 days, and never more than 100k rows. Operations go with
# their events. Runs here because this is the one housekeeping job that already runs
# every 15 minutes.
/opt/stateless/engine/tools/sql "DELETE FROM phv_events WHERE ts < NOW() - INTERVAL 30 DAY;" > /dev/null 2>&1
/opt/stateless/engine/tools/sql "DELETE FROM phv_events WHERE id < (SELECT id FROM (SELECT id FROM phv_events ORDER BY id DESC LIMIT 1 OFFSET 100000) AS keep_from);" > /dev/null 2>&1
/opt/stateless/engine/tools/sql "DELETE FROM phv_operations WHERE started < NOW() - INTERVAL 30 DAY;" > /dev/null 2>&1

# update the database with new timestamps
/opt/stateless/engine/tools/sql "UPDATE systemstats SET logRotaterLastRun=NOW();"
