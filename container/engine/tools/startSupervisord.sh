#!/bin/bash
source /opt/stateless/engine/includes/phvalheim-static.conf   # phvlog (2.56)
logsDir="/opt/stateful/logs"

# Only Unix sockets and short-lived diagnostic challenges cross this mount.
# Connector credentials live in the optional companion container's private volume.
install -d -m 0775 -o phvalheim -g phvalheim /run/phvalheim-remote

if [ ! -d "$logsDir" ]; then
        phvlog NOTICE "Logs directory missing, creating..."
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
