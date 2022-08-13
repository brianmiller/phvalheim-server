#!/bin/bash
logsDir="/opt/stateful/logs"

if [ ! -d "$logsDir" ]; then
        echo "`date` [NOTICE : phvalheim] Logs directory missing, creating..."
        mkdir -p $logsDir
fi

function startSupervisord() {
	/usr/bin/supervisord -n -c /etc/supervisor/supervisord.conf
}


trap 'kill -TERM $PID' TERM INT
startSupervisord & 
PID=$!
wait $PID
trap - TERM INT
wait $PID
EXIT_STATUS=$?
