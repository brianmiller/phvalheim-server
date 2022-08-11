#!/bin/bash
source /opt/stateless/engine/includes/phvalheim-static.conf
source /opt/stateful/config/phvalheim-backend.conf


####### BEGIN: Functions #######
function getNextPort(){
        port=$basePort
        usedPorts=$(SQL "SELECT port FROM worlds;"|sort|uniq)
        echo "$usedPorts"|grep -w $port > /dev/null 2>&1
        RESULT=$?
        while [ $RESULT = 0 ]; do
		let port=$port+2 #we add 2 to ensure we're always using an even port number because Valheim also reserves the next consecutive (odd) port
                echo "$usedPorts"|grep -w $port > /dev/null 2>&1
                RESULT=$?
        done

        echo $port
}


#$1=world name
function downloadAndInstallTsModsForWorld(){
	worldName="$1"


	#Ensure we know about all dependencies for everymod selected
	/opt/stateless/engine/tools/tsModDepGetter.sh "$worldName"

	worldMods=$(SQL "SELECT thunderstore_mods_all FROM worlds WHERE name='$worldName'")

	for worldMod in $worldMods; do
	
		if [ "$worldMod" = "placeholder" ]; then
			continue
		fi
	
                if [ "$worldMod" = "NULL" ]; then
                        continue
                fi	
	
		modAuthor=$(SQL "SELECT owner FROM tsmods WHERE moduuid='$worldMod' LIMIT 1;")
		modName=$(SQL "SELECT name FROM tsmods WHERE moduuid='$worldMod' LIMIT 1;")
		modVersionLatest=$(SQL "SELECT version FROM tsmods WHERE moduuid='$worldMod' ORDER BY version_date_created DESC LIMIT 1;"|sed 's/"//g')

		echo
		echo "World '$worldName' wants the following mods: "
		echo " Name: $modName"
		echo " Author: $modAuthor"
		echo " UUID: $worldMod"
		echo " Latest Version: $modVersionLatest"

		modDownloadUrl="https://valheim.thunderstore.io/package/download/$modAuthor/$modName/$modVersionLatest"
		echo " Download URL: $modDownloadUrl"

		modFileConstructed="$modAuthor-$modName-$modVersionLatest.zip"
		if [ ! -f $tsModsDir/$modFileConstructed ]; then
			echo "  #### Downloading $modFileConstructed from Thunderstore... ####"
			wget -q --show-progress -O $tsModsDir/$modFileConstructed $modDownloadUrl
			
		else
			echo "  #### $modFileConstructed already exists in local repository, using it... ####"
		fi

		echo "   #### Installing... ####"

                #BepInEx is special
                rm -rf /tmp/BepInEx_tmp
                mkdir /tmp/BepInEx_tmp
                unzip -o $tsModsDir/$modFileConstructed BepInExPack_Valheim/* -d /tmp/BepInEx_tmp/ > /dev/null 2>&1
                RESULT=$?
                if [ $RESULT = 0 ]; then
                        cp -prfv /tmp/BepInEx_tmp/BepInExPack_Valheim/* $worldsDirectoryRoot/$worldName/game/. > /dev/null 2>&1
                        rm -rf /tmp/BepInEx_tmp
                fi

		#Plugins
		unzip -o $tsModsDir/$modFileConstructed -x config/* core/* patchers/* BepInExPack_Valheim/* README.md icon.png manifest.json -d $worldsDirectoryRoot/$worldName/game/BepInEx/plugins/$modName/ > /dev/null 2>&1

		#Core
		unzip -o $tsModsDir/$modFileConstructed core/* -d $worldsDirectoryRoot/$worldName/game/BepInEx/core/ > /dev/null 2>&1

		#Config
                rm -rf /tmp/BepInEx_tmp
                mkdir /tmp/BepInEx_tmp
		unzip -o $tsModsDir/$modFileConstructed config/* -d /tmp/BepInEx_tmp/ > /dev/null 2>&1
		cp -prfv /tmp/BepInEx_tmp/config/* $worldsDirectoryRoot/$worldName/game/BepInEx/config/. > /dev/null 2>&1

		#Patchers
		unzip -j -o $tsModsDir/$modFileConstructed patchers/* -d $worldsDirectoryRoot/$worldName/game/BepInEx/patchers/$modName/ > /dev/null 2>&1
	done 

	echo
	echo "Mods download and installation sequence complete. Note: This does NOT indicate success."
	

	#Remove empty directories
	allPluginDirs=$(ls -d $worldsDirectoryRoot/$worldName/game/BepInEx/plugins/* 2>/dev/null)
	for pluginDir in $allPluginDirs; do
		if [ ! "$(ls -A $pluginDir)" ]; then
			#remove empty dir
			rm -r $pluginDir	
		fi
	done

        allPatcherDirs=$(ls -d $worldsDirectoryRoot/$worldName/game/BepInEx/patchers/* 2>/dev/null)
        for patcherDir in $allPatcherDirs; do
                if [ ! "$(ls -A $patcherDir)" ]; then
                        #remove empty dir
                        rm -r $patcherDir
                fi
        done

	#final step, ensure the world and all its files are owned by phvalheim
	chown -R phvalheim:phvalheim $worldsDirectoryRoot/$worldName 


}


#$1=worldName, $2=worldHost, $3=worldPort, $4=worldPassword
function createQuickConnectConfig(){
	worldName="$1"
	worldHost="$2"
	worldPort="$3"
	worldPassword="$4"

	echo "$worldName:$worldHost:$worldPort:$worldPassword" > /opt/stateful/games/valheim/worlds/$worldName/game/BepInEx/config/quick_connect_servers.cfg
}

#$1=worldName
function packageClient(){

	echo ""
	echo "Building PhValheim client payload..."

	worldName="$1"

        cd /opt/stateful/games/valheim/worlds/$worldName/game

        zip ../$worldName.zip -r \
        ./BepInEx \
        ./unstripped_corlib \
        ./doorstop_libs \
        ./doorstop_config.ini \
        ./start_game_bepinex.sh \
        ./winhttp.dll
	
	return $?
}

#create supervisor config file for this world
#$1=worldName, $2=worldPassword, $3=worldPort
function createSupervisorWorldConfig() {
	worldName="$1"
	worldPassword="$2"
	worldPort="$3"

	echo "
	[program:valheimworld_$worldName]
	command=/opt/stateless/games/valheim/scripts/startWorld.sh $worldName $worldPassword $worldPort
	user=root
	autostart=false
	autorestart=true
	stdout_logfile=/opt/stateful/logs/valheimworld_$worldName.log
	stdout_logfile_maxbytes=5242880
	redirect_stderr=true
	" > $worldSupervisorConfigs/valheimworld_$worldName.conf

	/usr/bin/supervisorctl reread
	/usr/bin/supervisorctl update

}


#$1=input file, returns md5sum
function getMD5 (){
	md5sum "$1"|cut -d " " -f1
}


#$1=world, $2=md5sum, sets world md5sum in database.  Used for client version checking and download consistency validation 
function setMD5 (){
	worldName="$1"
	worldMD5="$2"

	echo "Setting world md5sum for '$worldName' to '$worldMD5'"
	SQL "UPDATE worlds SET world_md5='$worldMD5' WHERE name='$worldName';"
}


####### END: Functions #######
