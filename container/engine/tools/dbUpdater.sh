#!/bin/bash

dbUpdateScripts=$(ls -v /opt/stateless/engine/dbUpdates/*.sh)

for dbUpdateScript in $dbUpdateScripts; do
	# Invoked with `bash`, not as a bare path.
	#
	# Running it as `$dbUpdateScript` needs the execute bit, and a migration committed
	# without one exits 126 -- which matched NEITHER branch below, so the loop logged
	# nothing at all and the engine carried on as if the schema were current. dbUpdate_2.45.sh
	# shipped that way in the first 2.45 RC: the ai_providers tables were simply absent and
	# the only symptom was the AI Helper reporting no providers configured.
	#
	# The mode of a file in git is not something a reviewer sees, so this removes the
	# dependency on it entirely rather than relying on everyone remembering chmod +x.
	bash "$dbUpdateScript"
	RESULT=$?
	if [ $RESULT = 0 ]; then
		echo "`date` [NOTICE : phvalheim] Database update successfully applied."
	elif [ $RESULT = 1 ]; then
		echo "`date` [NOTICE : phvalheim] Database update failed to apply."
	elif [ $RESULT = 2 ]; then
		# 2 is DELIBERATE and normal: the older scripts end with `exit 2` to mean
		# "already applied". Stay silent, exactly as before.
		#
		# The first cut of this else-branch treated every non-0/1 code as an error and
		# printed 13 ERROR lines on every single boot of a healthy server. That is worse
		# than the silence it replaced: the AI Helper read them, correctly believed its own
		# instructions, and reported "CRITICAL: Engine Database Update Failures" with
		# advice to hand-edit the database. Noise that looks like a fault is a fault.
		:
	else
		# What is left is a script that could not run AT ALL -- 126 (not executable),
		# 127 (interpreter missing), a signal. That is the silence worth breaking: it is
		# how dbUpdate_2.45.sh shipped non-executable and its tables were never created,
		# with nothing in the log to say so.
		echo "`date` [ERROR : phvalheim] Database update $(basename $dbUpdateScript) could not be run (exit $RESULT)."
	fi
done
