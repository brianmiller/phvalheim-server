#!/bin/bash
source /opt/stateless/engine/includes/phvalheim-static.conf
#source /opt/stateful/config/phvalheim-backend.conf


#$1=worldName, $2=backupsToKeep
function purgeOldBackups() {
        worldName="$1"
        backupsToKeep="$2"

        backupFiles=$(ls -alt $backupDir/valheimworld_$worldName-***.tar|rev|cut -d " " -f1|rev)
        totalBackups=$(echo "$backupFiles"|wc -l)
        keepBackups=$(echo "$backupFiles"|head -$backupsToKeep)

        if [ $totalBackups -gt $backupsToKeep ]; then
                numberOfBackupsToDelete=$(echo $totalBackups-$backupsToKeep|bc)
                deleteBackups=$(echo "$backupFiles"|tail -$numberOfBackupsToDelete)

                for deleteBackup in $deleteBackups; do
                        echo "`date` [phvalheim] Deleting old backup $deleteBackup..."
                        rm "$deleteBackup"
                done
        fi
}


#$1=worldName
function backupWorld(){
        worldName="$1"

	backupTimeStamp=''
	backupDestinationFile=''
	backupTimeTaken=''
	backupSize=''


        cd $worldsDirectoryRoot/$worldName/game/.config/unity3d/IronGate/Valheim/
	if [ ! $? = 0 ]; then
		echo "`date` [WARN : phvalheim] .config directory missing for '$worldName', skipping backup..."
	else
	        backupTimeStamp=$(date +%Y-%m-%dT%H:%M:%S%z)
	        backupDestinationFile="$backupDir/valheimworld_$worldName-$backupTimeStamp.tar"
	        backupTimeTaken=$(/usr/bin/time --format='%e' /usr/bin/tar cf $backupDestinationFile . 2>&1)
	        backupSize=$(stat --format=%s "$backupDestinationFile")

		#echo
		echo "`date` [phvalheim]  Backup time (seconds): $backupTimeTaken"
		#echo
		echo "`date` [phvalheim]  Backup size (bytes): $backupSize"
	fi
}


#echo
echo "`date` [NOTICE : phvalheim] Starting world backups..."
#echo
echo "`date` [phvalheim]  Backup directory: $backupDir"
#echo


#backup all the worlds
worldNames=$(SQL "SELECT name FROM worlds;")
for worldName in $worldNames; do

        echo "`date` [phvalheim]  World backup started for '$worldName'..."

        #run backup
        backupWorld "$worldName"

        if [ -f "$backupDestinationFile" ]; then
                echo "`date` [NOTICE : phvalheim]  Backup written to: $backupDestinationFile"
                purgeOldBackups $worldName $backupsToKeep
                continue
        else
                echo "`date` [FAIL : phvalheim]  Backup failed for '$worldName'!"
                continue
        fi

done
