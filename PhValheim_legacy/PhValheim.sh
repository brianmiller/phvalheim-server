#!/bin/bash
###############################################################################
# * Do the thing
# * 
# * Mirrors some stuff.
#
# Written and maintained by:
#  * Brian Miller (brian@phospher.com) 
###############################################################################
VERSION="1.3"

#Dirs
DATA_DIR="$HOME/.PhValheim"
STEAM_DIR="$HOME/.local/share/Steam"
VALHEIM_DIR="$STEAM_DIR/steamapps/common/Valheim"


#Exit if root
if [ "$USER" = "root" ]; then
	echo "Negative, captain. We're not running as root. Exiting..."
	exit 1
fi

#Stop everything if Steam isn't installed...
if [ ! -f "$VALHEIM_DIR/valheim.x86_64" ]; then
	echo "Valheim not found at '$VALHEIM_DIR', exiting..."
	exit 1
fi


#Header message
echo ""
echo "#############################################################################"
echo "# PhosHeim Launcher v$VERSION"
echo "#"
echo "#  This script will automatically download world and mod files from Phospher"
echo "#  servers and keep your system in-sync with everyone else."
echo "#"
echo "#  This script modifies your local game install with BepInEx bootstrap libs"
echo "#  and optionally installs HD assets within Valheim's Steam directory."
echo "#"
echo "#  All files are kept in '\$HOME/.PhValheim'"
echo "#"
echo "#  Valheim must be installed via Steam!"
echo "#############################################################################"


#Check local disk for DATA_DIR
if [ ! -d "$DATA_DIR" ]; then
        echo  "PhValheim directory does not exist, creating..."
	mkdir -p $DATA_DIR
fi


#IFS hackery
SAVEIFS=$IFS
IFS=$(echo -en "\n\b")


#Pull all worlds and store into $WORLDS
WORLDS=$(curl -s -k https://files.phospher.com/valheim/worlds.txt|dos2unix)

function LaunchValheim () {

	#vkBasalt (Linux reshade) (debug levels=trace,debug,info,warn,error,none)
	export ENABLE_VKBASALT=1
	export VKBASALT_LOG_LEVEL=error
	export VKBASALT_LOG_FILE="vkBasalt.log"
	export VKBASALT_CONFIG_FILE="$DATA_DIR/$WORLD/$WORLD/vkBasalt.conf"

	cd $VALHEIM_DIR
	exec="$VALHEIM_DIR/valheim.x86_64"
	export DOORSTOP_ENABLE=TRUE
	export DOORSTOP_INVOKE_DLL_PATH="$DATA_DIR/$WORLD/$WORLD/BepInEx/core/BepInEx.Preloader.dll"
	export DOORSTOP_CORLIB_OVERRIDE_PATH="$DATA_DIR/$WORLD/$WORLD/unstripped_corlib"
	export LD_LIBRARY_PATH="$DATA_DIR/$WORLD/$WORLD/doorstop_libs:$LD_LIBRARY_PATH"
	export LD_PRELOAD="libdoorstop_x64.so:$LD_PRELOAD"
	
	exec "$exec"
}


PS3='Please enter your choice (any other key to exit): '
OPTIONS=("$WORLDS")
COLUMNS=1
select OPTION in $OPTIONS; do
	if [ "$OPTION" = "Quit" ] || [ "$OPTION" = "" ]; then
		echo "exiting..."
		exit 0
	elif [ "$OPTION" = "Install HD Texture Pack (HDO)" ]; then
		echo "Installing HDO Texture Pack..."
		HDO_FILE_ID=$(curl -s -k https://files.phospher.com/valheim/hdo.txt)
		echo "Downloading archive file ID: '$HDO_FILE_ID'"
		wget -q --no-check-certificate --show-progress "https://drive.google.com/uc?export=download&confirm=t&id=$HDO_FILE_ID" -O "$VALHEIM_DIR/hdo.zip"
		echo "extracting HDO assets..."
		unzip -q -o "$VALHEIM_DIR/hdo.zip" -d "$VALHEIM_DIR/."
		echo
	else

		WORLD="$OPTION"
		echo "World \"$WORLD\" selected..."

		#Download version of selected world
		REMOTE_WORLD_VERSION=$(curl -s -k https://files.phospher.com/valheim/$WORLD/version.txt)
	
		#Maintenance mode check
		if [ "$REMOTE_WORLD_VERSION" = 0 ]; then
			echo "$WORLD is in maintenance mode, try another..."
		fi

		#Check local disk for WORLD directory
		if [ -d "$DATA_DIR/$WORLD" ]; then
			echo "$WORLD directory exists..."
		else
			echo "$WORLD directory does not exist, creating..."
			mkdir -p "$DATA_DIR/$WORLD"
		fi

		#Check local root files for doorstop libs
		if [ -f "$VALHEIM_DIR/doorstop_config.ini" ]; then
			echo "doorstop libs detected, continuing..."
		else
			echo "doorstop libs missing, downloading..."
			wget -q --no-check-certificate --show-progress "https://files.phospher.com/valheim/valheim_root_deps.zip" -O "$VALHEIM_DIR/valheim_root_deps.zip"
			echo "extrating doorstop libs..."
			unzip -q -o "$VALHEIM_DIR/valheim_root_deps.zip" -d "$VALHEIM_DIR/."
		fi

		#Check local disk for World files and download if missing
		if [ -f "$DATA_DIR/$WORLD/version.txt" ]; then
			echo "local version.txt file exists for $WORLD"
		else
			echo "local files for $WORLD do not exist, downloading..."
			wget -q --no-check-certificate "https://files.phospher.com/valheim/$WORLD/version.txt" -O "$DATA_DIR/$WORLD/version.txt"
			wget -q --no-check-certificate --show-progress "https://files.phospher.com/valheim/$WORLD/$WORLD.zip" -O "$DATA_DIR/$WORLD/$WORLD.zip"
			echo "extracting files for $WORLD..."
			unzip -q -o "$DATA_DIR/$WORLD/$WORLD.zip" -d "$DATA_DIR/$WORLD/."
		fi

		#Check local disk to ensure World version file downloaded, else exit.
		if [ ! -f "$DATA_DIR/$WORLD/version.txt" ]; then
			echo "could not download world files, exiting..."
			exit 1
		fi

		LOCAL_WORLD_VERSION=$(cat $DATA_DIR/$WORLD/version.txt)

		#Check and ensure local files match remote files (download new and/or update as needed). If all is well, launch.
		if [ $LOCAL_WORLD_VERSION -eq $REMOTE_WORLD_VERSION ]; then
			echo "local($LOCAL_WORLD_VERSION) and Remote($REMOTE_WORLD_VERSION) versions match for $WORLD..."
			echo "launching Valheim with $WORLD context..."
			LaunchValheim 
			#$STEAM_DIR/steam.sh -applaunch 892970 --doorstop-enable true --doorstop-target $DATA_DIR/$WORLD/$WORLD/BepInEx/core/BepInEx.Preloader.dll -console 2>&1 | while read line; do
			 
#                        	if [[ $line == *"Game process removed: AppID 892970"* ]]; then
#					echo
#					echo "Shutting down Steam, please wait..."
#					steam -shutdown
#                                        break
#                                fi
#                        done
		else
			#If a mismatch is detected, update and prepare to launch.
			echo "local($LOCAL_WORLD_VERSION) and Remote($REMOTE_WORLD_VERSION) versions DO NOT match for $WORLD, updating..."
			wget -q --no-check-certificate "https://files.phospher.com/valheim/$WORLD/version.txt" -O "$DATA_DIR/$WORLD/version.txt"
			echo "downloading update files for $WORLD..."
			wget -q --no-check-certificate --show-progress "https://files.phospher.com/valheim/$WORLD/$WORLD.zip" -O "$DATA_DIR/$WORLD/$WORLD.zip"

			LOCAL_WORLD_VERSION=$(cat $DATA_DIR/$WORLD/version.txt)

			if [ ! $LOCAL_WORLD_VERSION -eq $REMOTE_WORLD_VERSION ]; then
				#If an update was needed but failed, exit.
				echo "could not update local world version file for $WORLD, exiting..."
				exit 1
			else
				#If an update was needed and successed, extract and prepare to launch.
				echo "successfully updated local world files for $WORLD..."
				echo "removing outdates files for $WORLD..."
				if [ -f "$DATA_DIR/$WORLD/version.txt" ]; then
					rm -rf $DATA_DIR/$WORLD/$WORLD
				fi
				echo "extracting files for $WORLD..."
				unzip -q -o "$DATA_DIR/$WORLD/$WORLD.zip" -d "$DATA_DIR/$WORLD/."
				echo "launching Valheim with $WORLD context..."
				LaunchValheim
#				$STEAM_DIR/steam.sh -applaunch 892970 --doorstop-enable true --doorstop-target $DATA_DIR/$WORLD/$WORLD/BepInEx/core/BepInEx.Preloader.dll -console 2>&1 | while read line; do
#					if [[ $line == *"Game process removed: AppID 892970"* ]]; then
#						echo
#						echo "Shutting down Steam, please wait..."
#						steam -shutdown
#						break
#					fi
#				done
			fi
		fi
		exit

	fi
done


IFS=$SAVEIFS
exit











