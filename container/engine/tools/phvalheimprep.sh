#!/bin/bash
source /opt/stateful/config/phvalheim-backend.conf

if [ ! -d "$worldsDirectoryRoot" ]; then
        echo " Worlds directory Root missing, creating..."
        mkdir -p $worldsDirectoryRoot
fi

if [ ! -d "$tsModsDir" ]; then
        echo " Thunderstore mods download directory missing, creating..."
        mkdir -p $tsModsDir
fi

if [ ! -d "$customModsDir" ]; then
        echo " Custom mods directory missing, creating..."
        mkdir -p $customModsDir
fi

#if [ ! -d "$logsDir" ]; then
#        echo "Logs directory missing, creating..."
#        mkdir -p $logsDir
#fi


#prep perms
useradd phvalheim > /dev/null 2>&1
chown phvalheim: /opt/stateful
chown phvalheim: /opt/stateful/games
chown phvalheim: /opt/stateful/logs
chown mysql: /opt/stateful/mysql
chown -R phvalheim: /tmp/dumps


#world prep: ensure all worlds are in a stopped state (fresh PhValheim start)
WORLDS=$(SQL "SELECT id FROM worlds;")
for WORLD in $WORLDS; do
        echo "Setting '$WORLD' to stopped..."
        SQL "UPDATE worlds SET mode='stopped' WHERE id='$WORLD';"
done
