#!/bin/bash

# all dbUpdater scripts must be executable!

# is this update already applied?
sql "DESCRIBE worlds"|grep beta > /dev/null 2>&1
if [ ! $? = 0 ]; then
        echo "`date` [NOTICE : phvalheim] Applying database schema update for phvalheim-server >=v2.16"

        ## BEGIN UPDATE ##
        # add column
        sql "ALTER TABLE worlds ADD COLUMN beta TINYINT;"

        # insert default setting
        sql "UPDATE worlds SET beta='0';"

        # exit
        if [ ! $? = 0 ]; then
                # update failed to apply
                exit 1
        fi
        ## END UPDATE ##
else
        # update is already applied
        exit 2
fi
