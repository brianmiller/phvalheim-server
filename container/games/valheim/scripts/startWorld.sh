#!/bin/sh
# BepInEx-specific settings
# NOTE: Do not edit unless you know what you are doing!
#
# This script is /bin/sh, NOT bash. Keep it POSIX -- no arrays, no [[ ]], no ${x,,}.


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

# public check
#
# NOTE: `public` is the CITIZENS access-control flag -- when 1, permittedlist.txt is
# blanked so anyone may join. It is NOT Valheim's -public server-browser argument; that
# is the separate `listed` column, read below. Do not merge these two.
unset isPublic
unset public
isPublic=$(/opt/stateless/engine/tools/sql "SELECT public FROM worlds WHERE name='$worldName'")
if [ "$isPublic" = "1" ]; then
	echo "`date` [NOTICE : phvalheim] World is set to public!"
	## reset permittedlist.txt
	#echo "// List permitted players ID ONE per line" > /opt/stateful/games/valheim/worlds/$worldName/game/.config/unity3d/IronGate/Valheim/permittedlist.txt
fi

# vanilla world settings (see docs/RELEASE-2.40-DESIGN.md)
#
# One row, tab separated, because `sql` runs mysql with --skip-column-names.
unset isVanilla isListed isCrossplay worldPasswordDb launchParams
worldSettings=$(/opt/stateless/engine/tools/sql "SELECT IFNULL(vanilla,0), IFNULL(listed,0), IFNULL(crossplay,0), IFNULL(password,''), IFNULL(launch_params,'') FROM worlds WHERE name='$worldName'")
isVanilla=$(echo "$worldSettings"      | cut -f1)
isListed=$(echo "$worldSettings"       | cut -f2)
isCrossplay=$(echo "$worldSettings"    | cut -f3)
worldPasswordDb=$(echo "$worldSettings"| cut -f4)
launchParams=$(echo "$worldSettings"   | cut -f5)


cd /opt/stateful/games/valheim/worlds/$worldName/game


# Build the argument list in the positional parameters so that values containing spaces
# survive intact.
set -- \
-nographics \
-batchmode \
-name "$worldName" \
-port "$worldPort" \
-world "$worldName" \
-oldconsole

if [ "$isVanilla" = "1" ]; then
	echo "`date` [NOTICE : phvalheim] Vanilla world -- BepInEx will NOT be loaded."

	# -public is the Steam server browser listing, driven by `listed` only.
	set -- "$@" -public "$isListed"

	# Valheim refuses to boot on `-password ""`, so the flag must be ABSENT rather
	# than empty when no password is set.
	if [ -n "$worldPasswordDb" ]; then
		set -- "$@" -password "$worldPasswordDb"
	elif [ "$isListed" = "1" ]; then
		# Valheim requires a password on a listed server. Fail loudly here rather
		# than let supervisor restart-loop on an error buried in the world log.
		echo "`date` [ERROR : phvalheim] World '$worldName' is listed in the server browser but has no password. Set one in the world Settings modal."
		exit 1
	fi

	if [ "$isCrossplay" = "1" ]; then
		set -- "$@" -crossplay
	fi
else
	# Modded world: unchanged from pre-2.40. Gated by the CITIZENS permittedlist,
	# never listed, never password protected.
	set -- "$@" -public 0
fi

set -- "$@" -savedir /opt/stateful/games/valheim/worlds/$worldName/game/.config/unity3d/IronGate/Valheim

# Operator overrides, appended last so they win.
#
# Split on whitespace with globbing disabled. This is deliberately NOT eval: a value like
# "; rm -rf /" becomes three literal arguments to valheim_server rather than a command.
if [ -n "$launchParams" ]; then
	echo "`date` [NOTICE : phvalheim] Appending custom launch parameters: $launchParams"
	set -f
	set -- "$@" $launchParams
	set +f
fi


# BepInEx is only injected for modded worlds. A vanilla world has no BepInEx directory at
# all, so exporting these would be pointing doorstop at a DLL that does not exist.
if [ "$isVanilla" != "1" ]; then
	export DOORSTOP_ENABLED=1
	export DOORSTOP_TARGET_ASSEMBLY=./BepInEx/core/BepInEx.Preloader.dll
	export LD_LIBRARY_PATH="./doorstop_libs:$LD_LIBRARY_PATH"
	export LD_PRELOAD="libdoorstop_x64.so:$LD_PRELOAD"
fi
export LD_LIBRARY_PATH="./linux64:$LD_LIBRARY_PATH"
export SteamAppId=892970


exec /opt/stateful/games/valheim/worlds/$worldName/game/valheim_server.x86_64 "$@"


#Undo the BepInEx stuff
unset LD_LIBRARY_PATH
unset LD_PRELOAD
unset DYLD_INSERT_LIBRARIES
unset DYLD_LIBRARY_PATH
