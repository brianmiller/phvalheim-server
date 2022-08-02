#!/bin/bash
source /opt/stateless/engine/includes/phvalheim.conf


if [ ! -d "$logsDir" ]; then
        echo "Logs directory missing, creating..."
        mkdir -p $logsDir
fi

function startSupervisord() {
	/usr/bin/supervisord -n -c /opt/stateless/supervisor.d/supervisord.conf
}


trap 'kill -TERM $PID' TERM INT
startSupervisord & 
PID=$!
wait $PID
trap - TERM INT
wait $PID
EXIT_STATUS=$?
