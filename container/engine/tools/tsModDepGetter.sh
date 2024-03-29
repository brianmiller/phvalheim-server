#!/bin/bash
source /opt/stateless/engine/includes/phvalheim-static.conf
#source /opt/stateful/config/phvalheim-backend.conf

if [ ! $1 ]; then
	echo "`date` [ERROR : phvalheim] World name wasn't provided, exiting..."
	exit 1
else
	world="$1"
fi


#echo
echo "`date` [NOTICE : phvalheim] #### Mods detected for world '$world', running dependency checks, this could take a while... ####"


worldMods=$(SQL "SELECT thunderstore_mods FROM worlds WHERE name='$world';")


#$1=uuid of mod, $2=version(e.g., latest, or actual version number)
function getDepends() {
        UUID="$1"
        modVersion="$2"

	if [ "$modVersion" = "latest" ]; then
                getDepends=$(SQL "SELECT deps FROM tsmods WHERE moduuid='$UUID' ORDER BY version_date_created DESC LIMIT 1;"|sed 's/,//g'|sed 's/\\n//g'|grep "\""|sed 's/\[//g'|sed 's/\]//g'|sed 's/\"//g'|tr -s " ")
	else
		getDepends=$(SQL "SELECT deps FROM tsmods WHERE versionuuid='$UUID' AND version='$modVersion' ORDER BY version_date_created DESC LIMIT 1;"|sed 's/,//g'|sed 's/\\n//g'|grep "\""|sed 's/\[//g'|sed 's/\]//g'|sed 's/\"//g'|tr -s " ")
        fi

	echo "$getDepends"
}


#$1=uuid of mod, $2=version(e.g., latest, or actual version number)
function hasDepends() {
	UUID="$1"
	modVersion="$2"

	if [ "$modVersion" = "latest" ]; then 
		hasDepends=$(SQL "SELECT deps FROM tsmods WHERE moduuid='$UUID' ORDER BY version_date_created DESC LIMIT 1;"|sed 's/,//g'|sed 's/\\n//g'|grep "\""|sed 's/\[//g'|sed 's/\]//g'|sed 's/\"//g'|tr -s " ")
	else
		hasDepends=$(SQL "SELECT deps FROM tsmods WHERE versionuuid='$UUID' AND version='$modVersion' ORDER BY version_date_created DESC LIMIT 1;"|sed 's/,//g'|sed 's/\\n//g'|grep "\""|sed 's/\[//g'|sed 's/\]//g'|sed 's/\"//g'|tr -s " ")
	fi

	hasDepends=$(echo -n $hasDepends|wc -c)
	if [ $hasDepends -gt 0 ]; then
		echo "yes"
	else
		echo "no"
	fi
}


echo "`date` [phvalheim] World '$world' has the following mods: "
for worldMod in $worldMods; do

	modName=$(SQL "SELECT name from tsmods WHERE moduuid='$worldMod' ORDER BY version_date_created DESC LIMIT 1;")

	echo "`date` [phvalheim] Top-Level Mod: "
	echo "`date` [phvalheim]  Name: $modName"
	echo "`date` [phvalheim]  UUID: $worldMod"

	hasDepends=$(hasDepends "$worldMod" "latest")

	if [ "$hasDepends" = "yes" ]; then
		echo "`date` [phvalheim]    Depdendencies:"
	else
		echo "`date` [NOTICE : phvalheim]  *** Dependencies Met ***"
	fi

	#Top-level dep check
	function depLooper(){
		getDepends="$1"
		for getDepend in $getDepends; do
		        modDepOwner=$(echo "$getDepend"|rev|cut -d "-" -f3-|rev)
		        modDepName=$(echo "$getDepend"|rev|cut -d "-" -f2|rev)
			modUUID=$(SQL "SELECT moduuid FROM tsmods WHERE owner='$modDepOwner' AND name='$modDepName' ORDER BY version_date_created DESC LIMIT 1;")
		
			if [ -z "$modUUID" ]; then
				echo "`date` [WARN : phvalheim]    '$modDepName' was not found in PhValheim's database. '$modName' mod will likely not work..."
				continue
			fi
			
			totalMods=$(echo "$modUUID $totalMods")
			hasDepends=$(hasDepends "$modUUID" "latest")	

			echo "`date` [phvalheim]     Mod: $modDepName"
			echo "`date` [phvalheim]     UUID: $modUUID"

			if [ "$hasDepends" = "no" ]; then
				echo "`date` [NOTICE : phvalheim]     *** Dependencies Met ***"
				#echo
			else
				getDepends=$(getDepends "$modUUID" "latest")
				depLooper "$getDepends"
			fi
		done
	}

	getDepends=$(getDepends "$worldMod" "latest")
	getDepends_len=$(echo -n $getDepends|wc -c)
	if [ $getDepends_len -le 1 ]; then
		#echo "Depedencies done."
		continue
	else
		depLooper "$getDepends"
	fi
done


totalMods_len=$(echo -n $totalMods|wc -c)
worldMods_len=$(echo -n $worldMods|wc -c)


echo "`date` [phvalheim] total mods len: $totalMods_len"
echo "`date` [phvalheim] world mods len: $worldMods_len"


if [ $totalMods_len -ge 1 ]; then
	for modUUID in $totalMods; do
		uniqTotalMods=$(echo $modUUID $uniqTotalMods)
	done
else
	uniqTotalMods=$(echo $uniqTotalMods)
fi


if [ $totalMods_len -lt 1 ] && [ $worldMods_len -lt 1 ]; then
	 echo "`date` [phvalheim] World '$world' has no mods."
	 uniqTotalMods=''
fi


#sort and uniq
uniqTotalMods=$(echo $uniqTotalMods|xargs -n 1|sort|uniq|xargs -d $'\n')


#Send determined mods to database
echo "`date` [phvalheim] Updating database..."
updateWorldTSMods=$(SQL "UPDATE worlds SET thunderstore_mods_deps='$uniqTotalMods' WHERE name='$world';")
