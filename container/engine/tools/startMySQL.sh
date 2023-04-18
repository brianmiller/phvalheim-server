#!/bin/bash

#new deployment
if [ ! -d "/opt/stateful/mysql" ]; then
        mkdir -p /opt/stateful/mysql
	mkdir -p /opt/stateful/mysql/data
	mkdir -p /opt/stateful/mysql/temp
	mkdir -p /opt/stateful/mysql/lc-messages
        touch /opt/stateful/logs/mysqld.log
	chown mysql:mysql /opt/stateful/logs/mysqld.log
	chown -R mysql:mysql /opt/stateful/mysql
	/usr/sbin/mysqld --initialize --init-file=/etc/mysql/init-file --user=mysql
fi

if [ ! -d "/run/mysqld" ]; then
	mkdir -p /run/mysqld
fi


touch /opt/stateful/logs/mysqld.log

#own all the things, then start
chown -R mysql:mysql /opt/stateful/mysql
chown -R mysql:mysql /opt/stateful/logs/mysqld.log
chown -R mysql:phvalheim /var/run/mysqld
chmod -R 770 /var/run/mysqld


_term() {
  echo "`date` [NOTICE : mysqld] Caught SIGTERM signal!"
  kill -TERM "$child" 2>/dev/null
}

trap _term SIGTERM


# if timezone is not set via docker env, set it to UTC
if [ "$TZ" = "" ]; then
	echo "`date` [WARN : mysqld] Timezone var 'TZ' is empty, settigng to UTC..."
	export TZ="Etc/UTC"
fi

# convert timezone to offset
tzOffset=$(/usr/bin/date +%:z)


echo "`date` [NOTICE: mysqld] Starting mysql...";
/usr/bin/pidproxy /opt/stateful/mysql/mysqld.pid /usr/bin/mysqld_safe --log-error=/opt/stateful/logs/mysqld.log --init-file=/etc/mysql/init-file --user=mysql --default-time-zone=$tzOffset > /opt/stateful/logs/mysqld.log 2>&1 &

child=$!
wait "$child"
