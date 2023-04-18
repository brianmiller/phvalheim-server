#!/bin/bash

# if the provided timezone is invalid, set it to UTC
if [ ! -f "/usr/share/zoneinfo/$TZ" ]; then
                echo "`date` [WARN : phvalheim] The environment timezone is missing or invalid, setting to UTC..."
                export TZ="Etc/UTC"
fi


# set it
export TZ
echo $TZ > /etc/timezone
ln -sf /usr/share/zoneinfo/$TZ /etc/localtime

