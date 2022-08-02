#!/bin/bash

#new deployment
if [ ! -d "/opt/stateful/mysql" ]; then
        mkdir -p /opt/stateful/mysql
	mkdir -p /opt/stateful/mysql/data
	mkdir -p /opt/stateful/mysql/temp
	mkdir -p /opt/stateful/mysql/lc-messages
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


#trap 'echo "Supervisor stop detected for mysqld"' SIGTERM
#/usr/bin/pidproxy /opt/stateful/mysql/mysqld.pid /usr/bin/mysqld_safe --log-error=/opt/stateful/logs/mysqld.log --init-file=/etc/mysql/init-file --user=mysql > /opt/stateful/logs/mysqld.log 2>&1


_term() {
  echo "Caught SIGTERM signal!"
  kill -TERM "$child" 2>/dev/null
}

trap _term SIGTERM

echo "Starting mysql FOOOOOOOOOOOOOOOOOOOOOO...";
/usr/bin/pidproxy /opt/stateful/mysql/mysqld.pid /usr/bin/mysqld_safe --log-error=/opt/stateful/logs/mysqld.log --init-file=/etc/mysql/init-file --user=mysql > /opt/stateful/logs/mysqld.log 2>&1 &

child=$!
wait "$child"
