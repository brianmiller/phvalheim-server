#!/bin/bash

# all dbUpdater scripts must be executable!

# is this update already applied?
sql "DESCRIBE worlds"|grep trophyeikthyr > /dev/null 2>&1
if [ ! $? = 0 ]; then
	echo "`date` [NOTICE : phvalheim] Applying database schema update for phvalheim-server >=v2.7"

	## BEGIN UPDATE ##
	
	sql "ALTER TABLE worlds ADD COLUMN trophyeikthyr bool DEFAULT 0;"
	sql "ALTER TABLE worlds ADD COLUMN trophytheelder bool DEFAULT 0;"
	sql "ALTER TABLE worlds ADD COLUMN trophybonemass bool DEFAULT 0;"
	sql "ALTER TABLE worlds ADD COLUMN trophydragonqueen bool DEFAULT 0;"
	sql "ALTER TABLE worlds ADD COLUMN trophygoblinking bool DEFAULT 0;"
	sql "ALTER TABLE worlds ADD COLUMN trophyseekerqueen bool DEFAULT 0;"
	
	if [ ! $? = 0 ]; then
		# update failed to apply
		exit 1
	fi

	## END UPDATE ##
else
	# update is already applied
	exit 2
fi
