#!/bin/bash

echo "`date` [NOTICE : thunderstore] pruning thunderstore's Valheim database, keeping latest mods only..."


# latest mods query
latestMods="\
        SELECT t1.moduuid,t1.versionuuid
                        FROM tsmods AS t1
                        LEFT OUTER JOIN tsmods AS t2
                          ON t1.moduuid = t2.moduuid
                                AND (t1.version_date_created < t2.version_date_created 
                                 OR (t1.version_date_created = t2.version_date_created 
                                AND t1.Id < t2.Id))
                        WHERE t2.moduuid IS NULL"


# covert to csv
latestMods=$(/opt/stateless/engine/tools/sql "$latestMods"|sed s/'\s'/,/g)


# get before count
currentCount=$(/opt/stateless/engine/tools/sql "SELECT moduuid FROM tsmods"|wc -l)
echo "`date` [NOTICE : thunderstore] mods in database before pruning: $currentCount"


# loop through all latest mods and delete mods that do not match (keep only latest mods)
touch /tmp/sqlPrune.sql
rm /tmp/sqlPrune.sql
for latestMod in $latestMods; do
        modUuid=$(echo "$latestMod"|cut -d "," -f1)
        modVersionUuid=$(echo "$latestMod"|cut -d "," -f2)

        if [ ! -z $modUuid ] && [ ! -z $modVersionUuid ]; then
                #/opt/stateless/engine/tools/sql "DELETE FROM tsmods WHERE moduuid = '$modUuid' AND versionuuid != '$modVersionUuid'"
                echo "DELETE FROM tsmods WHERE moduuid = '$modUuid' AND versionuuid != '$modVersionUuid';" >> /tmp/sqlPrune.sql
        fi
done

# big bang purge
/opt/stateless/engine/tools/sql "source /tmp/sqlPrune.sql"

# get after count
currentCount=$(/opt/stateless/engine/tools/sql "SELECT moduuid FROM tsmods"|wc -l)
echo "`date` [NOTICE : thunderstore] mods in database after pruning: $currentCount"
