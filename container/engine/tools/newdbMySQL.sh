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
	status TEXT,\
	mode TEXT,\
	pid TEXT,\
	citizens TEXT,\
	external_endpoint TEXT,\
	world_md5 TEXT,\
	date_deployed DATETIME,\
	date_updated DATETIME,\
	currentMemory TEXT,\
	currentCPU TEXT\
	);	
"


SQL "
	create table systemstats (\
	id INTEGER PRIMARY KEY NOT NULL AUTO_INCREMENT,\
	cpuModel TEXT,\
	cpuCores TEXT,\
	cpuTotalMhz TEXT,\
	cpuFreeMhz TEXT,\
	memTotal TEXT,\
	memFree TEXT,\
	timestamp DATETIME,\
	tsUpdated DATETIME\
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


function tsSeeder () {
	echo "`date` [NOTICE : phvalheim] Downloading latest Thunderstore database seed from GitHub..."
	/usr/bin/wget -q https://github.com/brianmiller/phvalheim-server/raw/master/container/mysql/tsmods_seed.sql -O /opt/stateful/.tsmods_update.sql
	downloadedSize=$(/usr/bin/stat -c %s /opt/stateful/.tsmods_update.sql)
	if [ $downloadedSize -lt 30000 ]; then
		echo "`date` [ERROR : phvalheim] Could not download remote database seed, using packaged seed..."
		/usr/bin/mysql phvalheim < /etc/mysql/tsmods_seed.sql
	else
		/usr/bin/mysql phvalheim < /opt/stateful/.tsmods_update.sql
	fi

	/opt/stateless/engine/tools/sql "INSERT INTO systemstats SET tsUpdated=NOW();"
}


echo "`date` [NOTICE : mysqld] Creating PhValheim database..."
newDB

echo "`date` [NOTICE : phvalheim] Seeding database with Thunderstore stuff..."
tsSeeder
