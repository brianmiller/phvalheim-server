#!/bin/bash
source /opt/stateless/engine/includes/phvalheim-static.conf   # phvlog (2.56)

# if the provided timezone is invalid, set it to UTC
if [ ! -f "/usr/share/zoneinfo/$TZ" ]; then
	phvlog WARN "The environment variable for timezone (TZ) is missing or invalid, setting to UTC..."
                export TZ="Etc/UTC"
fi


# set it
export TZ
echo $TZ > /etc/timezone
ln -sf /usr/share/zoneinfo/$TZ /etc/localtime

