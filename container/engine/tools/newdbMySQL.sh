#!/bin/bash

source /opt/stateless/engine/includes/phvalheim.conf


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


function addWorld () {
SQL " 
	INSERT INTO worlds (status,name,port,external_endpoint) VALUES ('Up','Thrudheim','4015','valheim.phospher.com');
	INSERT INTO worlds (status,name,port,external_endpoint) VALUES ('Down','Bolverk','4002','valheim.phospher.com');
"
}


echo "Creating PhValheim database..."
newDB
#addWorld
#SQL "DESCRIBE worlds;"
#SQL "DESCRIBE systemstats;"
#SQL "SELECT user FROM mysql.user;"

#echo
#echo
#SQL "SELECT * FROM worlds;"
