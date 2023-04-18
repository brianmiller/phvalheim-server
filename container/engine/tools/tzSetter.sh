#!/bin/bash

timezone=$(sql "SELECT timezone FROM settings LIMIT 1" 2>/dev/null)

if [ ! -f /usr/share/zoneinfo/$timezone ]; then
	echo "`date` [WARN : phvalheim] $timezone is an invalid timezone, setting to UTC..."
        ln -sf /usr/share/zoneinfo/Etc/UTC /etc/localtime
        export TZ="Etc/UTC"
else
        ln -sf /usr/share/zoneinfo/$timezone /etc/localtime
        export TZ="$timezone"
fi
