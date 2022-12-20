#!/bin/bash

#is this update already applied?
sql "DESCRIBE settings" > /dev/null 2>&1 
if [ ! $? = 0 ]; then
	echo "`date` [NOTICE : phvalheim] Applying database schema update for phvalheim-server >=v1.9"

	## BEGIN UPDATE ##

	sql "CREATE TABLE settings(
		ID INT NOT NULL AUTO_INCREMENT,
		PRIMARY KEY ( ID ),
		steamAPIKey TEXT,
		defaultSeed TEXT,
		backupsToKeep INT		
	);"

	sql "CREATE TABLE admins(
                PRIMARY KEY ( steamID ),
		steamID VARCHAR(64)
	);"

	## END UPDTE ##

fi
