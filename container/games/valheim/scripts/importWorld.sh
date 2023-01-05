#!/bin/bash

## a hack of a script intended to import existing worlds into PhValheim
source /opt/stateless/engine/includes/0-functions.sh


if [ ! $1 ]; then
	echo "USAGE: ./importWorld <path to world root to import>"
	echo " Example: ./importWorld root@myserver:/myworldroot"
	echo " Example: ./importWorld /mnt/myworldroot"
	echo " Example: ./importWorld root@cerebrum.phospher.com:/mnt/user/appdata/37648-valheim2"
	echo
	exit 1
fi


# this isn't really used but it's needed to pass the logic, for now.
worldPassword="hammertime"

# steam users that will become admins of this world (adminlist.txt)
worldAdmins="7656000000000000X 7656000000000000Y"

# steam users allowed to join the world (permittedlist.txt)
worldCitizens="7656000000000000A 7656000000000000B 7656000000000000C 7656000000000000D 7656000000000000E"


if [ -d "import_wip" ]; then
	rm -rf import_wip
fi

mkdir import_wip


# copy files from the world that will be imported into the WIP directory
echo "Copying world files..."
rsync -av --progress $1/ import_wip/.
if [ ! $? = 0 ]; then
	echo "ERROR: Copy failed, can't continue..."
	exit 1
fi


# determine world directory
if [ -d "import_wip/.config/unity3d/IronGate/Valheim/worlds" ]; then
	worldDir="import_wip/.config/unity3d/IronGate/Valheim/worlds"
fi
if [ -d "import_wip/.config/unity3d/IronGate/Valheim/worlds_local" ]; then
        worldDir="import_wip/.config/unity3d/IronGate/Valheim/worlds_local"
fi


# get world name from local files
worldName=$(ls -alt $worldDir/*.db|head -1|rev|cut -d " " -f1|cut -d "." -f2-|cut -d "/" -f1|rev)
if [ $worldName == "" ]; then
	echo "ERROR: Could not determine world name, can't continue..."
	exit 1
else
	echo
	echo "World name found: $worldName"
fi


# get world seed from world files
worldSeed=$((head -c$(od -j$(od -j8 -N1 -An -t u1) -N1 -An -t u1);echo)<$worldDir/$worldName.fwl)
if [ "$worldSeed" == "" ]; then
        echo "ERROR: Could not determine world seed, can't continue..."
        exit 1
else
	echo "World seed found: $worldSeed"
fi


# get world creation time
echo $1|grep ":" > /dev/null 2>&1
if [ $? = 0 ]; then
	sshHost=$(echo $1|cut -d ":" -f1)
	worldCreationFile=$(echo $1|cut -d ":" -f2)
	worldCreation=$(ssh $sshHost "stat --format=%X $worldCreationFile/.config/unity3d/IronGate/Valheim/prefs")
else
	worldCreation=$(stat --format=%X $1/.config/unity3d/IronGate/Valheim/prefs)
fi


# convert epoch time to database useable value
worldCreation=$(date -d @$worldCreation +'%F %T')


# port to use for this imported world
worldPort=$(getNextPort)


# now
now=$(date +'%F %T')


# provision database
echo "Importing into database..."
sql "INSERT INTO worlds (name,seed,external_endpoint,port,mode,status,date_deployed,date_updated) VALUES ('$worldName','$worldSeed','$gameDNS','$worldPort','importing','Down','$worldCreation','$now')"


# prepare directory structure for this imported world
worldDirPrep "$worldName"

# install valheim for this imported world
InstallAndUpdateValheim "$worldName"

# install bepinex for this imported world
InstallAndUpdateBepInEx "$worldName"

# create quick connect config
createQuickConnectConfig "$worldName" "$worldHost" "$worldPort" "$worldPassword"

# add required mods
mergeRequiredTsMods "$worldName"

# manually create important destination directories
mkdir -p /opt/stateful/games/valheim/worlds/$worldName/game/.config/unity3d/IronGate/Valheim/worlds_local/
mkdir -p /opt/stateful/games/valheim/worlds/$worldName/custom_configs/
mkdir -p /opt/stateful/games/valheim/worlds/$worldName/custom_plugins/
mkdir -p /opt/stateful/games/valheim/worlds/$worldName/custom_patchers/

# copy world .db and .fwl files
echo "Copying world .db and .fwl files..."
echo "$worldDir/$worldName.db"
cp $worldDir/$worldName.db /opt/stateful/games/valheim/worlds/$worldName/game/.config/unity3d/IronGate/Valheim/worlds_local/.
cp $worldDir/$worldName.fwl /opt/stateful/games/valheim/worlds/$worldName/game/.config/unity3d/IronGate/Valheim/worlds_local/.

# add imported mods: you shouldn't do this. you should use PhValheim's mod manager which will keep plugins up-to-date. using the custom_plugins directory is acceptable if the mod(s) are not in thunderstore.
#cp -prfv import_wip/BepInEx/plugins/* /opt/stateful/games/valheim/worlds/$worldName/custom_plugins/.

# add imported configs
cp -prfv import_wip/BepInEx/config/* /opt/stateful/games/valheim/worlds/$worldName/custom_configs/.
if [ -f "/opt/stateful/games/valheim/worlds/$worldName/custom_configs/BepInEx.cfg" ]; then
	rm /opt/stateful/games/valheim/worlds/$worldName/custom_configs/BepInEx.cfg
fi
if [ -f "/opt/stateful/games/valheim/worlds/$worldName/custom_configs/quick_connect_servers.cfg" ]; then
        rm /opt/stateful/games/valheim/worlds/$worldName/custom_configs/quick_connect_servers.cfg
fi

# add imported patchers: you shouldn't do this. you should use PhValheim's mod manager which will keep plugins up-to-date. using the custom_plugins directory is acceptable if the mod(s) are not in thunderstore.
#cp -prfv import_wip/BepInEx/plugins/* /opt/stateful/games/valheim/worlds/$worldName/custom_patchers/.

# set admins
echo "// List admin players ID  ONE per line" > /opt/stateful/games/valheim/worlds/$worldName/game/.config/unity3d/IronGate/Valheim/adminlist.txt
for worldAdmin in $worldAdmins; do
	echo "$worldAdmin" >> /opt/stateful/games/valheim/worlds/$worldName/game/.config/unity3d/IronGate/Valheim/adminlist.txt
done

# set citizens
echo "// List permitted players ID  ONE per line" > /opt/stateful/games/valheim/worlds/$worldName/game/.config/unity3d/IronGate/Valheim/permittedlist.txt
for worldCitizen in $worldCitizens; do
        echo "$worldCitizen" >> /opt/stateful/games/valheim/worlds/$worldName/game/.config/unity3d/IronGate/Valheim/permittedlist.txt
done
sql "UPDATE worlds SET citizens='$worldCitizens' WHERE name='$worldName'"

# download and install TS mods
downloadAndInstallTsModsForWorld "$worldName"

# sync custom mods/configs to world
installCustomModsConfigsPatchers "$worldName"

# package client
packageClient "$worldName"

# create supervisor process config
createSupervisorWorldConfig "$worldName" "$worldPassword" "$worldPort"

# get md5sum of new world payload
worldMD5=$(getMD5 "/opt/stateful/games/valheim/worlds/$worldName/$worldName.zip")

# set database md5
setMD5 "$worldName" "$worldMD5"

# set world status to stopped
sql "UPDATE worlds SET mode='stopped' WHERE name='$worldName'"

# clean wip
rm -rf import_wip
