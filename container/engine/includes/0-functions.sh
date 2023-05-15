#!/bin/bash
source /opt/stateless/engine/includes/phvalheim-static.conf
#source /opt/stateful/config/phvalheim-backend.conf


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

function updateSteam(){
#        if [ ! -d "/opt/stateful/games/steamcmd" ]; then
#                mkdir -p /opt/stateful/games/steamcmd
#        fi

#	if [ ! -f "/opt/stateful/games/steamcmd/steamcmd.sh" ]; then
#		echo "`date` [phvalheim] Steam appears to be missing, installing..."
#		curl http://media.steampowered.com/installer/steamcmd_linux.tar.gz --output /opt/stateful/games/steamcmd.tar.gz
#                tar xvzf /opt/stateful/games/steamcmd.tar.gz --directory /opt/stateful/games/steamcmd/.
#                rm /opt/stateful/games/steamcmd.tar.gz
#	else
#		echo "`date` [phvalheim] Steam appears to be installed, checking for updates..."
#        fi

	echo "`date` [NOTICE : phvalheim] Checking for Steam updates..."

	# this is important: creation of a world after the last world has been deleted caused numerous "No such file or directory" errors.
        # This was due to PWD missing after deletion of the last world.
        cd

	if [ ! -d /opt/stateful/games/steam_home ]; then
		mkdir -p /opt/stateful/games/steam_home
	fi

        if [ ! -d /opt/stateful/games/steam_home/.steam ]; then
                mkdir -p /opt/stateful/games/steam_home/.steam
        fi

	if [ ! -d /opt/stateful/games/steam_home/Steam ]; then
                mkdir -p /opt/stateful/games/steam_home/Steam
        fi

        if [ -d /root/.steam ] && [ ! -L /root/.steam ] ; then
                rm -rf /root/.steam > /dev/null 2>&1
        fi

        if [ -d /root/Steam ] && [ ! -L /root/Steam ]; then
                rm -rf /root/Steam > /dev/null 2>&1
        fi

	if [ ! -L "/root/.steam" ]; then
		ln -s /opt/stateful/games/steam_home/.steam /root/.steam
	fi

	if [ ! -L "/root/Steam" ]; then
                ln -s /opt/stateful/games/steam_home/Steam /root/Steam
        fi

	if [ -f "/usr/games/steamcmd" ]; then
		/usr/games/steamcmd +quit
	else
		 echo "`date` [FAIL : phvalheim] Steam didn't install correctly, can't continue..."
	fi




}

#$1=world name
function InstallAndUpdateBepInEx() {
	worldName="$1"
	response=$(curl -sfSL -H "accept: application/json" "https://valheim.thunderstore.io/api/experimental/package/denikson/BepInExPack_Valheim/")
        download_url=$(jq -r  ".latest.download_url" <<< "$response")
        latest_version=$(jq -r  ".latest.version_number" <<< "$response")
        installed_version=$(cat /opt/stateful/games/valheim/worlds/$worldName/game/bepinex_version.txt 2> /dev/null)

        if [ "$latest_version" == "$installed_version" ]; then
                echo "`date` [NOTICE : phvalheim] BepInEx is up-to-date."
        else
                echo "`date` [NOTICE : phvalheim] BepInEx is out-of-date and will be updated..."
                curl -sfSL $download_url --output /opt/stateful/games/valheim/worlds/$worldName/game/BepInEx_latest.zip
                unzip /opt/stateful/games/valheim/worlds/$worldName/game/BepInEx_latest.zip "BepInExPack_Valheim/*" -d "/opt/stateful/games/valheim/worlds/$worldName/game"
                rm /opt/stateful/games/valheim/worlds/$worldName/game/BepInEx_latest.zip
		chown -R phvalheim: $worldsDirectoryRoot/$worldName

		# this is important: creation of a world after the last world has been deleted caused numerous "No such file or directory" errors.
		# This was due to PWD missing after deletion of the last world. 
		cd

                rsync -purval /opt/stateful/games/valheim/worlds/$worldName/game/BepInExPack_Valheim/ /opt/stateful/games/valheim/worlds/$worldName/game/
                rm -r /opt/stateful/games/valheim/worlds/$worldName/game/BepInExPack_Valheim
                echo $latest_version > /opt/stateful/games/valheim/worlds/$worldName/game/bepinex_version.txt
        fi

	chown -R phvalheim: $worldsDirectoryRoot/$worldName
}

#$1=world name
function worldDirPrep(){
	worldName="$1"

	echo "`date` [NOTICE : phvalheim] Preparing directory structure for world..."
	mkdir -p /opt/stateful/games/valheim/worlds/$worldName
	mkdir -p /opt/stateful/games/valheim/worlds/$worldName/game
	mkdir -p /opt/stateful/games/valheim/worlds/$worldName/custom_configs
	mkdir -p /opt/stateful/games/valheim/worlds/$worldName/custom_configs_secure
        mkdir -p /opt/stateful/games/valheim/worlds/$worldName/custom_plugins
        mkdir -p /opt/stateful/games/valheim/worlds/$worldName/custom_patchers

	# we need the world .config directory before the world starts (citizens and such...)
	mkdir -p /opt/stateful/games/valheim/worlds/$worldName/game/.config/unity3d/IronGate/Valheim/
	echo "// List permitted players ID ONE per line" > /opt/stateful/games/valheim/worlds/$worldName/game/.config/unity3d/IronGate/Valheim/permittedlist.txt


	chown -R phvalheim: $worldsDirectoryRoot/$worldName
}

#$1=world name
function InstallAndUpdateValheim() {
	worldName="$1"

	#Ensure required directories exist
        if [ ! -d "/opt/stateful/games/valheim" ]; then
                mkdir -p /opt/stateful/games/valheim
        fi
	#if [ ! -d "/opt/stateful/games/valheim/worlds/$worldName/game/steamapps" ]; then
        #        mkdir -p /opt/stateful/games/valheim/worlds/$worldName/game/steamapps
	#	ls -ald /opt/stateful/games/valheim/worlds/$worldName/game/steamapps
        #fi

        #Install Valheim once Steam is installed
        echo "`date` [NOTICE : phvalheim] Installing and/or checking for Valheim updates..."
	LD_LIBRARY_PATH="/opt/stateful/games/steam_home/.steam/steamcmd/linux32/:$LD_LIBRARY_PATH" /usr/games/steamcmd +@sSteamCmdForcePlatformType linux +force_install_dir /opt/stateful/games/valheim/worlds/$worldName/game +login anonymous +app_update 896660 validate +quit

	chown -R phvalheim: $worldsDirectoryRoot/$worldName
}

#$1=world name, $2=world seed
function createCustomSeedConfig() {
	worldName="$1"
	worldSeed="$2"

        if [ ! -f "/opt/stateful/games/valheim/worlds/$worldName/custom_plugins/ZeroBandwidth-CustomSeed/CustomSeed.dll" ]; then
                echo "`date` [phvalheim] CustomSeed.dll is missing, installing..."
                if [ ! -f "/opt/stateless/games/valheim/custom_plugins/ZeroBandwidth-CustomSeed/CustomSeed.dll" ]; then
                        echo "`date` [ERROR : phvalheim] Install source for CustomSeed.dll is missing from '/opt/stateless/games/valheim/custom_plugins/ZeroBandwidth-CustomSeed/CustomSeed.dll', exiting..."
                        exit 1
                else
                        mkdir -p /opt/stateful/games/valheim/worlds/$worldName/custom_plugins/ZeroBandwidth-CustomSeed
                        cp /opt/stateless/games/valheim/custom_plugins/ZeroBandwidth-CustomSeed/CustomSeed.dll /opt/stateful/games/valheim/worlds/$worldName/custom_plugins/ZeroBandwidth-CustomSeed/
                fi
        fi

	echo "[CustomSeed]" > /opt/stateful/games/valheim/worlds/$worldName/custom_configs/ZeroBandwidth.CustomSeed.cfg
        echo "custom_seed = $worldSeed" >> /opt/stateful/games/valheim/worlds/$worldName/custom_configs/ZeroBandwidth.CustomSeed.cfg

	chown -R phvalheim: $worldsDirectoryRoot/$worldName
}

#$1=world name
function purgeWorldModsConfigsPatchers(){
	worldName="$1"

	if [ -z $worldsDirectoryRoot ]; then
		echo "`date` [ERROR : phvalheim] Main worlds root directory missing, this is fatal. Exiting..."
		break
	fi

	if [ -z $worldName ]; then
		echo "`date` [ERROR : phvalheim] World name not specificed during purge, can't continue..."
		break
	fi

	rm -rf $worldsDirectoryRoot/$worldName/game/BepInEx/plugins/*
	rm -rf $worldsDirectoryRoot/$worldName/game/BepInEx/config/*
	rm -rf $worldsDirectoryRoot/$worldName/game/BepInEx/patchers/*
}

#$1=world name
function mergeRequiredTsMods(){
	worldName="$1"

	#update thunderstore_mods (not _all)
	currentMods=$(SQL "SELECT thunderstore_mods FROM worlds WHERE name='$worldName'")

	updatedModList=$(echo "$requiredTsMods" "$currentMods"|xargs -n 1|sort|uniq)
	updatedModList=$(echo $updatedModList|xargs -d $'\n')

	#update database
	echo "`date` [NOTICE : phvalheim] Updating database..."
	updateWorldTSMods=$(SQL "UPDATE worlds SET thunderstore_mods='$updatedModList' WHERE name='$worldName';")
}

#$1=world name
function downloadAndInstallTsModsForWorld(){
	worldName="$1"

	#Ensure we know about all dependencies for every mod selected
	/opt/stateless/engine/tools/tsModDepGetter.sh "$worldName"

	selectedMods=$(SQL "SELECT thunderstore_mods FROM worlds WHERE name='$worldName'")
	depMods=$(SQL "SELECT thunderstore_mods_deps FROM worlds WHERE name='$worldName'")

	worldMods="$selectedMods $depMods"

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

		#echo
		echo "`date` [phvalheim] World '$worldName' wants this mod: "
		echo "`date` [phvalheim]  Name: $modName"
		echo "`date` [phvalheim]  Author: $modAuthor"
		echo "`date` [phvalheim]  UUID: $worldMod"
		echo "`date` [phvalheim]  Latest Version: $modVersionLatest"

		modDownloadUrl="https://valheim.thunderstore.io/package/download/$modAuthor/$modName/$modVersionLatest"
		echo "`date` [phvalheim]  Download URL: $modDownloadUrl"

		modFileConstructed="$modAuthor-$modName-$modVersionLatest.zip"
		if [ ! -f $tsModsDir/$modFileConstructed ]; then
			echo "`date` [phvalheim]   #### Downloading $modFileConstructed from Thunderstore... ####"
			wget -q --show-progress -O $tsModsDir/$modFileConstructed $modDownloadUrl
			
		else
			echo "`date` [phvalheim]   #### $modFileConstructed already exists in local repository, using it... ####"
		fi

		echo "`date` [phvalheim]    #### Installing... ####"

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

	#echo
	echo "`date` [NOTICE : phvalheim] Mods download and installation sequence complete. Note: This does NOT indicate success."
	

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
function installCustomModsConfigsPatchers() {
	echo "`date` [NOTICE : phvalheim] Installing custom mods, configs, and patchers..."

	worldName="$1"

	customModsSourceDir="/opt/stateful/games/valheim/worlds/$worldName/custom_plugins"
	customConfigsSourceDir="/opt/stateful/games/valheim/worlds/$worldName/custom_configs"
	customPatchersSourceDir="/opt/stateful/games/valheim/worlds/$worldName/custom_patchers"

	worldModsDestDir="$worldsDirectoryRoot/$worldName/game/BepInEx/plugins"
	worldConfigsDestDir="$worldsDirectoryRoot/$worldName/game/BepInEx/config"
	worldPatchersDestDir="$worldsDirectoryRoot/$worldName/game/BepInEx/patchers"

	if [ ! -d $customModsSourceDir ]; then
		echo "`date` [NOTICE : phvalheim] Custom mods source directory for this world is missing, creating..."
		mkdir -p $customModsSourceDir
	fi

        if [ ! -d $customConfigsSourceDir ]; then
                echo "`date` [NOTICE : phvalheim] Custom configs source directory for this world is missing, creating..."
                mkdir -p $customConfigsSourceDir
        fi

        if [ ! -d $customPatchersSourceDir ]; then
                echo "`date` [NOTICE : phvalheim] Custom patchers source directory for this world is missing, creating..."
                mkdir -p $customPatchersSourceDir
        fi

	cp -prf $customModsSourceDir/* $worldModsDestDir/. > /dev/null 2>&1
	cp -prf $customConfigsSourceDir/* $worldConfigsDestDir/. > /dev/null 2>&1
	cp -prf $customPatchersSourceDir/* $worldPatchersDestDir/. > /dev/null 2>&1

	chown -R phvalheim:phvalheim $customModsSourceDir
	chown -R phvalheim:phvalheim $customConfigsSourceDir
	chown -R phvalheim:phvalheim $customPatchersSourceDir

}


# this is seperate from installCustomModsConfigsPatchers() because this must only run after the client payload has been packaged.
function InstallCustomConfigSecureFiles() {
	echo "`date` [NOTICE : phvalheim] Installing custom_configs_secure files..."
	worldName="$1"

	customConfigsSecureSourceDir="/opt/stateful/games/valheim/worlds/$worldName/custom_configs_secure"
        worldConfigsDestDir="$worldsDirectoryRoot/$worldName/game/BepInEx/config"

        if [ ! -d $customConfigsSecureSourceDir ]; then
                echo "`date` [NOTICE : phvalheim] Custom configs secure source directory for this world is missing, creating..."
                mkdir -p $customConfigsSecureSourceDir
        fi

	cp -prf $customConfigsSecureSourceDir/* $worldConfigsDestDir/. > /dev/null 2>&1

	chown -R phvalheim:phvalheim $customConfigsSecureSourceDir
}


#$1=worldName
function packageClient(){

	#echo ""
	echo "`date` [NOTICE : phvalheim] Building PhValheim client payload..."

	worldName="$1"

	#delete current world payload zip
	rm /opt/stateful/games/valheim/worlds/$worldName/$worldName.zip	> /dev/null 2>&1

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
	user=phvalheim
	autostart=false
	autorestart=true
	stdout_logfile=/opt/stateful/logs/valheimworld_$worldName.log
	;stdout_logfile_maxbytes=1MB
	;stdout_logfile_backups=1
	redirect_stderr=true
	" > $worldSupervisorConfigs/valheimworld_$worldName.conf

	# dumb supervisor
	touch /opt/stateful/logs/valheimworld_$worldName.log
	touch /opt/stateful/logs/valheimworld_$worldName.log.1
	chown phvalheim:phvalheim /opt/stateful/logs/valheimworld_*

	/usr/bin/supervisorctl reread
	/usr/bin/supervisorctl update

}

#delete supervisor config file for this world
#$1=worldName
function deleteSupervisorWorldConfig(){
	worldName="$1"
	rm $worldSupervisorConfigs/valheimworld_$worldName.conf
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

	echo "`date` [NOTICE : phvalheim] Setting world md5sum for '$worldName' to '$worldMD5'"
	SQL "UPDATE worlds SET world_md5='$worldMD5' WHERE name='$worldName';"
}


####### END: Functions #######
