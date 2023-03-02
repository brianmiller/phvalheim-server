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
