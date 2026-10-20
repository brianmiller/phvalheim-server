#!/bin/bash
source /opt/stateless/engine/includes/phvalheim-static.conf
#source /opt/stateful/config/phvalheim-backend.conf

if [ ! -d "$worldsDirectoryRoot" ]; then
        phvlog NOTICE "Worlds directory Root missing, creating..."
        mkdir -p $worldsDirectoryRoot
fi

if [ ! -d "$tsModsDir" ]; then
        phvlog NOTICE "Thunderstore mods download directory missing, creating..."
        mkdir -p $tsModsDir
fi

# The tsWIP scratch directory was created here. It held the old sync's JSON chunks;
# modSync.py streams in memory and writes no intermediate files.

if [ ! -d "$backupDir" ]; then
	phvlog NOTICE "Backup directory missing, creating..."
        mkdir -p $backupDir
fi

if [ ! -d "$worldSupervisorConfigs" ]; then
        phvlog NOTICE "World supervisor config directory missing, creating..."
        mkdir -p $worldSupervisorConfigs
fi



# prep perms
useradd phvalheim > /dev/null 2>&1
chown phvalheim: /opt/stateful
chown -R phvalheim: /opt/stateful/games
chown -R phvalheim: /opt/stateful/logs
chown -R mysql:mysql /opt/stateful/mysql
chown -R phvalheim: /tmp/dumps
chown -R phvalheim: $backupDir
chmod 600 /etc/cron.d/*
