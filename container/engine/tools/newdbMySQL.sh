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


# The `tsmods` table is NOT created here any more.
#
# A fresh install has no Thunderstore-only history to migrate, and the mod catalogue lives in
# `mods` / `mod_versions` / `mod_deps`, created by dbUpdates/dbUpdate_2.43.sh (which skips its
# migration step when tsmods is absent). Creating an empty legacy table on a new install would
# only invite something to read it.

SQL "
	CREATE USER 'phvalheim_user'@'localhost' IDENTIFIED BY 'phvalheim_secretpassword';
	GRANT ALL ON phvalheim.* TO 'phvalheim_user'@'localhost';
	GRANT ALL ON phvalheim.* TO 'root'@'localhost';

"

}


# tsSeeder() is gone. It downloaded a 14 MB tsmods_seed.sql dump from GitHub (falling back to
# a copy baked into the image) because the old sync took hours and a fresh install could not
# wait for it.
#
# It is not needed: a cold build of both catalogues from the live APIs takes about half a
# minute. The engine fires that sync on first boot when the catalogue is empty -- see
# pruneOrphanedWorldMods/seedModCatalogue in the engine entrypoint -- so a new install has
# mods a few seconds after it comes up, and they are current rather than as old as the dump.
#
# This also removes a supply-chain surface: the seed was fetched over the network from a
# GitHub raw URL and piped straight into `mysql` with no integrity check at all.


echo "`date` [NOTICE : mysqld] Creating PhValheim database..."
newDB
