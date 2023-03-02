#!/bin/bash

dbUpdateScripts=$(ls /opt/stateless/engine/dbUpdates/*.sh)

for dbUpdateScript in $dbUpdateScripts; do
	$dbUpdateScript
	RESULT=$?
	if [ $RESULT = 0 ]; then
		echo "`date` [NOTICE : phvalheim] Database update successfully applied."
	elif [ $RESULT = 1 ]; then
		echo "`date` [NOTICE : phvalheim] Database update failed to apply."
	fi
done
