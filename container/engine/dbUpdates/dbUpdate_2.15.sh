#!/bin/bash

# all dbUpdater scripts must be executable!

# is this update already applied?
sql "DESCRIBE settings"|grep thunderstore_chunk_size > /dev/null 2>&1
if [ ! $? = 0 ]; then
        echo "`date` [NOTICE : phvalheim] Applying database schema update for phvalheim-server >=v2.15"

        ## BEGIN UPDATE ##
        # add column
        sql "ALTER TABLE settings ADD COLUMN thunderstore_chunk_size INT;"

        # insert default setting
        sql "UPDATE settings SET thunderstore_chunk_size='1000';"

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
