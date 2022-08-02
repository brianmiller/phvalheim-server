#!/bin/bash

source /opt/stateless/engine/phvalheim.conf

rm $DB > /dev/null 2>&1

function newDB () {
sqlite3 $DB <<EOF
create table worlds (\
id INTEGER PRIMARY KEY,\
name TEXT,\
ip TEXT,\
port int,\
seed TEXT,\
thunderstore_mods TEXT,\
nexus_mods TEXT,\
custom_mods TEXT,\
status TEXT,\
mode TEXT,\
pid TEXT,\
external_endpoint TEXT,\
timestamp DATETIME\
);	

create table system (\
id INTEGER PRIMARY KEY,\
cpuModel TEXT,\
cpuCores TEXT,\
cpuTotalMhz int,\
memTotal TEXT,\
timestamp DATETIME\
);


EOF
}


function addWorld () {
sqlite3 $DB <<EOF
INSERT INTO worlds (status,name,port,external_endpoint) VALUES ('Up','Thrudheim','4015','valheim.phospher.com');
INSERT INTO worlds (status,name,port,external_endpoint) VALUES ('Down','Bolverk','4002','valheim.phospher.com');

EOF
sqlite3 $DB ".schema"
sqlite3 $DB "select * from worlds;"
}






if [ ! -f "$DB" ]; then
	echo "PhValheim database is missing, creating..."
	newDB
	addWorld
	chown -R phvalheim: /opt/stateful
else
	echo "PhValheim database found, using exisiting database..."
fi



