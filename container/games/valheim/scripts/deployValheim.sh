#!/bin/bash

if [ ! $1 ] || [ ! $2 ]; then
	echo "`date` [ERROR : phvalheim] Missing arguments, exiting..."
	echo " Example: ./deployValheim.sh \"world_name\" \"world_seed\""
	exit 1
else
	worldName="$1"
	worldSeed="$2"
fi


function InstallAndUpdateBepInEx() {
	response=$(curl -sfSL -H "accept: application/json" "https://valheim.thunderstore.io/api/experimental/package/denikson/BepInExPack_Valheim/")
	download_url=$(jq -r  ".latest.download_url" <<< "$response")
	latest_version=$(jq -r  ".latest.version_number" <<< "$response")
	installed_version=$(cat /opt/stateful/games/valheim/worlds/$worldName/game/bepinex_version.txt 2> /dev/null)
	
	if [ "$latest_version" == "$installed_version" ]; then
	        echo "`date` [phvalheim] BepInEx is up-to-date."
	else
	        echo "`date` [NOTICE : phvalheim] BepInEx is out-of-date and will be updated..."
	        curl -sfSL $download_url --output /opt/stateful/games/valheim/worlds/$worldName/game/BepInEx_latest.zip
	        unzip /opt/stateful/games/valheim/worlds/$worldName/game/BepInEx_latest.zip "BepInExPack_Valheim/*" -d "/opt/stateful/games/valheim/worlds/$worldName/game"
	        rm /opt/stateful/games/valheim/worlds/$worldName/game/BepInEx_latest.zip
	        rsync -purval /opt/stateful/games/valheim/worlds/$worldName/game/BepInExPack_Valheim/ /opt/stateful/games/valheim/worlds/$worldName/game/
	        rm -r /opt/stateful/games/valheim/worlds/$worldName/game/BepInExPack_Valheim
	        echo $latest_version > /opt/stateful/games/valheim/worlds/$worldName/game/bepinex_version.txt
	fi
}


function InstallCustomSeed() {
	if [ ! -f "/opt/stateful/games/valheim/worlds/$worldName/game/BepInEx/plugins/ZeroBandwidth-CustomSeed/CustomSeed.dll" ]; then
	        echo "`date` [phvalheim] CustomSeed.dll is missing, installing..."
	        if [ ! -f "/opt/stateless/games/valheim/custom_plugins/ZeroBandwidth-CustomSeed/CustomSeed.dll" ]; then
	                echo "`date` [ERROR : phvalheim] Install source for CustomSeed.dll is missing from '/opt/stateless/games/valheim/custom_plugins/ZeroBandwidth-CustomSeed/CustomSeed.dll', exiting..."
	                exit 1
	        else
	                mkdir -p /opt/stateful/games/valheim/worlds/$worldName/game/BepInEx/plugins/ZeroBandwidth-CustomSeed
	                cp /opt/stateless/games/valheim/custom_plugins/ZeroBandwidth-CustomSeed/CustomSeed.dll /opt/stateful/games/valheim/worlds/$worldName/game/BepInEx/plugins/ZeroBandwidth-CustomSeed/
	        fi
	fi


	echo "[CustomSeed]" > /opt/stateful/games/valheim/worlds/$worldName/game/BepInEx/config/ZeroBandwidth.CustomSeed.cfg
	echo "custom_seed = $worldSeed" >> /opt/stateful/games/valheim/worlds/$worldName/game/BepInEx/config/ZeroBandwidth.CustomSeed.cfg
}


function InstallAndUpdateValheim() {
	#Ensure required directories exist
	if [ ! -d "/opt/stateful/games/steamcmd" ]; then
	        mkdir -p /opt/stateful/games/steamcmd
	fi
	if [ ! -d "/opt/stateful/games/valheim" ]; then
	        mkdir -p /opt/stateful/games/valheim
	fi

	#If Steam isn't installed, install it
	if [ ! -f "/opt/stateful/games/steamcmd/steamcmd.sh" ]; then
	        curl http://media.steampowered.com/installer/steamcmd_linux.tar.gz --output /opt/stateful/games/steamcmd.tar.gz
	        tar xvzf /opt/stateful/games/steamcmd.tar.gz --directory /opt/stateful/games/steamcmd/.
	        rm /opt/stateful/games/steamcmd.tar.gz
	fi

	#Install Valheim once Steam is installed
	echo "`date` [NOTICE : phvalheim] Installing and/or checking for Valheim updates..."
	/opt/stateful/games/steamcmd/steamcmd.sh +@sSteamCmdForcePlatformType linux +force_install_dir /opt/stateful/games/valheim/worlds/$worldName/game +login anonymous +app_update 896660 validate +quit
}


InstallAndUpdateBepInEx "$worldName" 
InstallCustomSeed "$worldName"
InstallAndUpdateValheim "$worldName"


