#!/bin/bash
source /opt/stateless/engine/includes/phvalheim-static.conf
chunkSize=$(sql "SELECT thunderstore_chunk_size FROM settings;")

/opt/stateless/engine/tools/sql "UPDATE systemstats SET tsSyncLocalLastExecStatus='running';"
/opt/stateless/engine/tools/sql "UPDATE systemstats SET tsSyncLocalLastRun=NOW();"

# error setter
function errorSetter() {
        RESULT=$1

        if [ $RESULT -ne 0 ]; then
                /opt/stateless/engine/tools/sql "UPDATE systemstats SET tsSyncLocalLastExecStatus='error';"
                exit 1
        fi
}


# multithreaded pid checker
for pidFile in /tmp/ts_*.pid; do
        if [ ! "$pidFile" = "/tmp/ts_*.pid" ]; then
                thisPid=$(echo "$pidFile"|cut -d '.' -f1|cut -d '_' -f2)
                ps -p $thisPid > /dev/null 2>&1
                if [ $? = 0 ]; then
                        echo "`date` [NOTICE : thunderstore] WARNING: a previous thunderstore sync process ($thisPid) is still running. This could mean your thunderstore chunk size is too agressive for your system. Consider increasing the 'thunderstore_chunk_size' database value."
                fi

                echo "`date` [NOTICE : thunderstore] WARNING: killing previous sync thread PID: $thisPid'"
                kill -9 $thisPid > /dev/null 2>&1
                rm /tmp/ts_$thisPid.pid

        fi
done


echo "`date` [NOTICE : thunderstore] Downloading Thunderstore's Valheim database..."
curl -s -X GET "$tsApiUrl" -H  "accept: application/json" |jq '.[]' > $tsWIP/json
errorSetter $?


# split json into smaller workable chunks
echo "`date` [thunderstore] Chunking Thunderstore's Valheim database ($chunkSize mods per chunk to process)..."
rm -f $tsWIP/chunk_*.json
jq -c . < $tsWIP/json | split -l $chunkSize --additional-suffix=.json - $tsWIP/chunk_


function toDatabase(){
        ts_owner="${1}"
        ts_name="${2}"
        ts_package_url="${3}"
        ts_date_created="${4}"
        ts_date_updated="${5}"
        ts_uuid4="${6}"
        ts_versionUUID="${7}"
        ts_version="${8}"
        ts_deps="${9}"
        ts_version_date_created="${10}"

        ts_date_created=$(echo $ts_date_created|sed -e 's/^"//' -e 's/"$//')
        errorSetter $?

        ts_date_created=$(date -d"$ts_date_created" "+%Y-%m-%d %T")
        errorSetter $?

        ts_date_updated=$(echo $ts_date_updated|sed -e 's/^"//' -e 's/"$//')
        errorSetter $?

        ts_date_updated=$(date -d"$ts_date_updated" "+%Y-%m-%d %T")
        errorSetter $?

        ts_version_date_created=$(echo $ts_version_date_created|sed -e 's/^"//' -e 's/"$//')
        errorSetter $?

        ts_version_date_created=$(date -d "$ts_version_date_created" "+%Y-%m-%d %T")
        errorSetter $?

        existCheck=$(SQL "SELECT id FROM tsmods WHERE versionuuid='$ts_versionUUID';")

        if [ -z $existCheck ]; then
                echo "`date` [thunderstore] Thunderstore: $ts_name ($ts_versionUUID : $ts_version) does not exist in the database, adding..."
                SQL "INSERT INTO tsmods (owner,name,url,created,updated,moduuid,versionuuid,version,deps,version_date_created) VALUES ('$ts_owner','$ts_name','$ts_package_url','$ts_date_created','$ts_date_updated','$ts_uuid4','$ts_versionUUID','$ts_version','$ts_deps','$ts_version_date_created');"
        else
                echo "`date` [thunderstore] Thunderstore: $ts_name ($ts_versionUUID : $ts_version) already exists in database, updating..."
                SQL "UPDATE tsmods SET owner='$ts_owner',name='$ts_name',url='$ts_package_url',created='$ts_date_created',updated='$ts_date_updated',moduuid='$ts_uuid4',versionuuid='$ts_versionUUID',version='$ts_version',deps='$ts_deps',version_date_created='$ts_version_date_created' WHERE versionUUID='$ts_versionUUID';"

        fi
}


# worker
function worker() {
        echo "`date` [thunderstore] Thunderstore: storing thread PID: /tmp/ts_$BASHPID.pid"
        touch /tmp/ts_$BASHPID.pid

        jsonChunk=$(cat $1)
        allModsInChunk=$(jq -r ".uuid4" <<<$jsonChunk)

        for ts_uuid4 in $allModsInChunk; do
                ts_modJson=$(jq "select(.uuid4 == \"$ts_uuid4\") | {name,owner,package_url,date_created,date_updated,versions}"<<<$jsonChunk)
                errorSetter $?
                ts_name=$(jq -r ".name" <<<$ts_modJson)
                errorSetter $?
                ts_owner=$(jq -r ".owner" <<<$ts_modJson)
                errorSetter $?
                ts_package_url=$(jq -r ".package_url" <<<$ts_modJson)
                errorSetter $?
                ts_date_created=$(jq -r ".date_created" <<<$ts_modJson)
                errorSetter $?
                ts_date_updated=$(jq -r ".date_updated" <<<$ts_modJson)
                errorSetter $?
                ts_versions=$(jq ".versions|.[]" <<<$ts_modJson)
                errorSetter $?

                ts_versionUUIDs=$(jq -r ".uuid4" <<<$ts_versions|head -1)

                for ts_versionUUID in $ts_versionUUIDs; do
                        ts_version=$(jq ". | select(.uuid4 == \"$ts_versionUUID\") | {version_number}|.[]"<<<$ts_versions)
                        errorSetter $?

                        ts_deps=$(jq ". | select(.uuid4 == \"$ts_versionUUID\") | {dependencies}|.[]"<<<$ts_versions)
                        errorSetter $?

                        ts_version_date_created=$(jq ". | select(.uuid4 == \"$ts_versionUUID\") | {date_created}|.[]"<<<$ts_versions)
                        errorSetter $?

                        toDatabase "$ts_owner" "$ts_name" "$ts_package_url" "$ts_date_created" "$ts_date_updated" "$ts_uuid4" "$ts_versionUUID" "$ts_version" "$ts_deps" "$ts_version_date_created"
                        errorSetter $?
                done

        done

        echo "`date` [NOTICE : thunderstore] Thunderstore's sync is complete for chunk '$1'..."
        rm /tmp/ts_$BASHPID.pid
}


# forker
for chunk in $tsWIP/chunk_*.json; do
        worker $chunk & 
done


# update the database
/opt/stateless/engine/tools/sql "UPDATE systemstats SET tsSyncLocalLastExecStatus='idle';"
/opt/stateless/engine/tools/sql "UPDATE systemstats SET tsUpdated=NOW();"
/opt/stateless/engine/tools/sql "UPDATE systemstats SET tsSyncLocalLastRun=NOW();"
