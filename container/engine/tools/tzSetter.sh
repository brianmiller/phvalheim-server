#!/bin/bash

dbTimezone=$(sql "SELECT timezone FROM settings LIMIT 1" 2>/dev/null)


# if the database was set to an invalid timezone, set to UTC. Else, set to specified timezone
if [ ! -f "/usr/share/zoneinfo/$dbTimezone" ]; then

	# if the database is empty
	if [ -z "$dbTimezone" ]; then
		dbTimezone="Etc/UTC"
		echo "`date` [WARN : phvalheim] The database is set to an invalid time zone, setting to UTC..."
		export TZ="Etc/UTC"
	else
		# if the database is not empty but still invalid
	        echo "`date` [WARN : phvalheim] '$dbTimezone' is an invalid time zone, setting to UTC..."
		export TZ="Etc/UTC"
	fi

else

	# if the database is not empty and valid
	echo "`date` [NOTICE : phvalheim] Setting time zone to '$dbTimezone'..."
	export TZ="$dbTimezone"

fi


# update database
sql "UPDATE settings SET timezone='$TZ'" 2>/dev/null

# set it
ln -sf /usr/share/zoneinfo/$TZ /etc/localtime
