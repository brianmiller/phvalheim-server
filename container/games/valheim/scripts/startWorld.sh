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

# Access lists: rewrite permittedlist/adminlist/bannedlist from the database.
#
# The database is the source of truth. Doing this on every start means a list file that
# drifted -- because a write from the admin UI failed, or because a restore unpacked an old
# copy over it -- converges back to what the admin UI shows. Valheim reads all three from
# -savedir at startup, so this has to happen before the server is exec'd.
#
# NOTE: `public` is the CITIZENS access-control flag -- when 1, permittedlist.txt is written
# empty so anyone may join. It is NOT Valheim's -public server-browser argument; that is the
# separate `listed` column, read below. Do not merge these two.
/opt/stateless/games/valheim/scripts/syncAccessLists.sh "$worldName"

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

else
	# Modded world: gated by the CITIZENS permittedlist, never listed in the server
	# browser, never password protected.
	set -- "$@" -public 0
fi

# Crossplay is VANILLA-ONLY for now.
#
# -crossplay makes Valheim open a PlayFab server instead of a Steam one. A PlayFab server is
# reached by join code and has no host:port at all -- but the PhValheim client reaches a modded
# world through QuickConnect, whose config file is `world:host:port:password`. So a modded
# crossplay world cannot be joined by the client, whatever the operator intended.
#
# Enforced HERE and not only in the admin UI, because this is what actually reaches Valheim.
# That also means a modded world whose crossplay flag was set before this gate existed stops
# opening a PlayFab server on its next start, without anyone having to find and fix the row.
#
# NOTE: the `crossplay` column is left alone rather than zeroed -- it is the operator's stored
# preference, and it becomes live again the moment the world is switched to vanilla.
#
# Revisit when the client can launch with -joincode; see docs and the 2.0.13 client work.
if [ "$isCrossplay" = "1" ] && [ "$isVanilla" = "1" ]; then
	set -- "$@" -crossplay
elif [ "$isCrossplay" = "1" ]; then
	echo "`date` [NOTICE : phvalheim] World '$worldName' has crossplay set but is MODDED -- starting without -crossplay. The PhValheim client cannot join a modded crossplay world."
fi

set -- "$@" -savedir /opt/stateful/games/valheim/worlds/$worldName/game/.config/unity3d/IronGate/Valheim

# Record what this start is ACTUALLY using, after the gates above have had their say.
#
# The database holds the operator's INTENT and can be edited while a world is running. This file
# is what the running process was handed. Without it the UIs had two sources of truth for one
# card: the public page drew its CROSSPLAY pill from the database (so it appeared the instant
# the option was saved) while the Launch link followed the running server (so it stayed a
# direct-connect link). The pill promised a crossplay world the server was not serving.
#
# Anything describing a LIVE world reads this; anything describing a stopped one reads the
# database, because then there is nothing running to contradict it. A stale file from a previous
# run is harmless for the same reason -- it is only consulted while the world is up.
#
# The password is stored as a hash. This file sits in the world directory and is easier to read
# than the database row; a hash is enough to notice the password changed, which is all the
# restart-pending check needs.
runtimeOptions=/opt/stateful/games/valheim/worlds/$worldName/.running-options
effectiveCrossplay=0
effectiveListed=0
effectivePasswordHash=""
if [ "$isVanilla" = "1" ]; then
	effectiveListed=$isListed
	[ "$isCrossplay" = "1" ] && effectiveCrossplay=1
	if [ -n "$worldPasswordDb" ]; then
		effectivePasswordHash=$(printf '%s' "$worldPasswordDb" | sha256sum | cut -d' ' -f1)
	fi
fi
# Written to a temp file and renamed so a reader never sees a half-written one.
if {
	echo "vanilla=$isVanilla"
	echo "crossplay=$effectiveCrossplay"
	echo "listed=$effectiveListed"
	echo "passwordhash=$effectivePasswordHash"
} > "$runtimeOptions.tmp"; then
	mv -f "$runtimeOptions.tmp" "$runtimeOptions"
	chown phvalheim:phvalheim "$runtimeOptions" 2>/dev/null
else
	# Not fatal -- the world still starts. Say so, because the UIs will fall back to the
	# database and can then describe a live world by its pending settings.
	echo "`date` [WARNING : phvalheim] Could not write $runtimeOptions -- the UI will describe '$worldName' by its SAVED options, which may not be what is running."
	rm -f "$runtimeOptions.tmp"
fi

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
