#!/bin/sh
# BepInEx-specific settings
# NOTE: Do not edit unless you know what you are doing!


#if [ ! `whoami` = phvalheim ]; then
#	echo "ERROR: This script must be run as the user 'phvalheim', exiting..."
	#exit 1
#fi

if [ ! $1 ] || [ ! $2 ] || [ ! $3 ]; then
        echo "`date` [ERROR : phvalheim] Missing arguments..."
        echo " Example: startWorld.sh \"world_name\" \"world_port\""
        exit 1
else
        worldName="$1"
        worldPassword="$2"
        worldPort="$3"
fi


echo ""
echo "`date` [NOTICE : phvalheim] World start command received: "
echo "`date` [phvalheim]  Time: `date`"
echo "`date` [phvalheim]  World: $worldName"
echo "`date` [phvalheim]  Port: $worldPort/udp"
echo ""

cd /opt/stateful/games/valheim/worlds/$worldName/game

export DOORSTOP_ENABLE=TRUE
export DOORSTOP_INVOKE_DLL_PATH=./BepInEx/core/BepInEx.Preloader.dll
#export DOORSTOP_CORLIB_OVERRIDE_PATH=./unstripped_corlib
export LD_LIBRARY_PATH="./doorstop_libs:$LD_LIBRARY_PATH"
export LD_PRELOAD="libdoorstop_x64.so:$LD_PRELOAD"

export LD_LIBRARY_PATH="./linux64:$LD_LIBRARY_PATH"
export SteamAppId=892970


exec /opt/stateful/games/valheim/worlds/$worldName/game/valheim_server.x86_64 \
-nographics \
-batchmode \
-name $worldName \
-port $worldPort \
-world $worldName \
-public 0 \
-savedir /opt/stateful/games/valheim/worlds/$worldName/game/.config/unity3d/IronGate/Valheim 


#Undo the BepInEx stuff
unset LD_LIBRARY_PATH
unset LD_PRELOAD
unset DYLD_INSERT_LIBRARIES
unset DYLD_LIBRARY_PATH

