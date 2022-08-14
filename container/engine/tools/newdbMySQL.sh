#!/bin/bash

source /opt/stateless/engine/includes/phvalheim-static.conf
#source /opt/stateful/config/phvalheim-backend.conf


function newDB () {
echo "DROP DATABASE IF EXISTS phvalheim" | mysql
echo "CREATE DATABASE phvalheim" | mysql


SQL "
	create table worlds (\
	id INTEGER PRIMARY KEY NOT NULL AUTO_INCREMENT,\
	name TEXT,\
	ip TEXT,\
	port int,\
	seed TEXT,\
	thunderstore_mods TEXT,\
	thunderstore_mods_all TEXT,\
	custom_mods TEXT,\
	status TEXT,\
	mode TEXT,\
	pid TEXT,\
	citizens TEXT,\
	external_endpoint TEXT,\
	world_md5 TEXT,\
	timestamp DATETIME\
	);	
"


SQL "
	create table systemstats (\
	id INTEGER PRIMARY KEY NOT NULL AUTO_INCREMENT,\
	cpuModel TEXT,\
	cpuCores TEXT,\
	cpuTotalMhz int,\
	memTotal TEXT,\
	timestamp DATETIME\
	);
"


SQL "
        create table tsmods (\
        id INTEGER PRIMARY KEY NOT NULL AUTO_INCREMENT,\
        owner TEXT,\
        name TEXT,\
        url TEXT,\
        created DATETIME,\
        updated DATETIME,\
	moduuid TEXT,\
	versionuuid TEXT,\
	version TEXT,\
	deps TEXT,\
	version_date_created DATETIME\
        );
"

SQL "
	CREATE USER 'phvalheim_user'@'localhost' IDENTIFIED BY 'phvalheim_secretpassword';
	GRANT ALL ON phvalheim.* TO 'phvalheim_user'@'localhost';
	GRANT ALL ON phvalheim.* TO 'root'@'localhost';

"

}


function tsStoreSeed () {
	/usr/bin/mysql phvalheim < /etc/mysql/tsmods_seed.sql 
}

echo "`date` [NOTICE : mysqld] Creating PhValheim database..."
newDB

echo "`date` [NOTICE : phalheim] Seeding database with Thunderstore stuff..."
tsStoreSeed
