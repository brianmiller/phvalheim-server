#!/bin/bash
source /opt/stateless/engine/includes/phvalheim-static.conf
#source /opt/stateful/config/phvalheim-backend.conf

#We sit and wait to make sure MySQL is really, really up. We can't do anything without a database.
dbCheck_output=$(/usr/bin/mysql -e "DROP DATABASE IF EXISTS testdb;CREATE DATABASE testdb;DROP DATABASE IF EXISTS testdb;" 2>&1)
dbCheck_exitstatus=$?

while [ ! $dbCheck_exitstatus = 0 ]; do
        echo "`date` [phvalheim] Waiting for MySQL to come up..."
        sleep 2
        dbCheck_output=$(/usr/bin/mysql -e "DROP DATABASE IF EXISTS testdb;CREATE DATABASE testdb;DROP DATABASE IF EXISTS testdb;" 2>&1)
        dbCheck_exitstatus=$?
done

if [ $dbCheck_exitstatus = 0 ]; then
        echo "`date` [NOTICE : phvalheim] MySQL is up and accepting connections..."
fi


#make sure we have an existing or fresh database to use
dbCheck_output=$(/usr/bin/mysql -e "use phvalheim;" 2>&1 |grep "Unknown database" > /dev/null 2>&1)
dbCheck_exitstatus=$?
if [ $dbCheck_exitstatus = 0 ]; then
        echo "`date` [NOTICE : phvalheim] PhValheim database is missing, creating a fresh database..."
        /opt/stateless/engine/tools/newdbMySQL.sh
else
        echo "`date` [NOTICE : phvalheim] Existing PhValheim database found, using it..."
fi


#make sure 1 row exists in systemstats 
dbCheck_systemstats=$(sql "SELECT COUNT(*) FROM systemstats")
if [ $dbCheck_systemstats -ne 1 ]; then
        # we need something, might as well use the cpu name.
        cpuModel=$(cat /proc/cpuinfo |grep "model name"|head -1|cut -d ":" -f2-|cut -d " " -f2-)
        /opt/stateless/engine/tools/sql "DELETE FROM systemstats"
        /opt/stateless/engine/tools/sql "INSERT INTO systemstats (cpuModel) VALUES ('$cpuModel')"
fi

if [ $dbCheck_systemstats -eq 1 ]; then
	# we need something, might as well use the cpu name.
        cpuModel=$(cat /proc/cpuinfo |grep "model name"|head -1|cut -d ":" -f2-|cut -d " " -f2-)
	/opt/stateless/engine/tools/sql "UPDATE systemstats SET cpuModel='$cpuModel'"
fi


# world prep: ensure all worlds are in a stopped state (fresh PhValheim start)
worldIds=$(SQL "SELECT id FROM worlds;")
for worldId in $worldIds; do
        worldName=$(SQL "SELECT name FROM worlds WHERE id='$worldId';")
        echo "`date` [phvalheim] Setting world '$worldName' to stopped..."
        SQL "UPDATE worlds SET mode='stopped' WHERE id='$worldId';"
done


# set all background process status indicators to 'idle' on new PhValheim start
echo "`date` [phvalheim] Setting initial background process indicators to 'idle'..."
SQL "UPDATE systemstats SET tsSyncLocalLastExecStatus='idle';"
SQL "UPDATE systemstats SET tsSyncRemoteLastExecStatus='idle';"
SQL "UPDATE systemstats SET worldBackupLastExecStatus='idle';"
SQL "UPDATE systemstats SET logRotateLastExecStatus='idle';"
SQL "UPDATE systemstats SET utilizationMonitorLastExecStatus='idle';"
