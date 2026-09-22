#!/bin/bash
# 2.50: escalating self-repair between steamcmd attempts must never touch player data.
#
# THE POINT OF THIS TEST. The world save lives INSIDE the game directory --
# startWorld.sh passes -savedir .../game/.config/unity3d/IronGate/Valheim -- and the mod
# loader lives at .../game/BepInEx. The obvious implementation of "self-healing" is to
# wipe the game dir and reinstall, and that would DELETE EVERY WORLD SAVE ON THE SERVER.
#
# So the survival assertions below are the real subject. The removal assertions only
# prove the repair does anything at all; the survival ones prove it is safe to ship.
#
# Mutation check: change healSteamcmdState to `rm -rf "$game"` and the six SURVIVES
# cases go red while every REMOVED case still passes. Confirmed before shipping.
#
# Run: ./dev_tools/test-steamcmd-self-heal.sh

REPO="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
FUNCS="${FUNCS_UNDER_TEST:-$REPO/container/engine/includes/0-functions.sh}"

pass=0
fail=0

eval "$(awk '/^function healSteamcmdState\(\)/,/^}/' "$FUNCS")"
if ! declare -f healSteamcmdState > /dev/null; then
	echo "FAIL  could not extract healSteamcmdState from $FUNCS"
	exit 1
fi

TMP=$(mktemp -d)
trap 'rm -rf "$TMP"' EXIT
worldsDirectoryRoot="$TMP"

SAVEDIR=".config/unity3d/IronGate/Valheim"

buildWorld() {
	local g="$TMP/$1/game"
	rm -rf "$TMP/$1"
	# --- player data and mods: must survive every level ---
	mkdir -p "$g/$SAVEDIR/Brotality"
	echo "world save"        > "$g/$SAVEDIR/Brotality/_main.fwl2"
	echo "world db"          > "$g/$SAVEDIR/Brotality/_main.db"
	echo "76561198000000000" > "$g/$SAVEDIR/permittedlist.txt"
	mkdir -p "$g/BepInEx/plugins" "$g/BepInEx/config" "$g/BepInExPack_Valheim" "$g/unstripped_corlib"
	echo "mod"    > "$g/BepInEx/plugins/SomeMod.dll"
	echo "loader" > "$g/BepInEx/config/BepInEx.cfg"
	echo "pack"   > "$g/BepInExPack_Valheim/marker"
	echo "corlib" > "$g/unstripped_corlib/marker"
	touch "$g/valheim_server.x86_64"
	# --- installed game content: re-downloadable, but NOT what we are clearing ---
	mkdir -p "$g/steamapps/common/Valheim dedicated server"
	echo "game content" > "$g/steamapps/common/Valheim dedicated server/marker"
	# --- steamcmd scratch: the only things in scope ---
	mkdir -p "$g/Steam/logs" "$g/.steam" "$g/steamapps/downloading/896660" "$g/steamapps/temp"
	echo "bootstrap" > "$g/Steam/logs/stderr.txt"
	echo "partial"   > "$g/steamapps/downloading/896660/chunk"
	echo "tmp"       > "$g/steamapps/temp/scratch"
	printf '"AppState"\n{\n\t"StateFlags"\t\t"6"\n}\n' > "$g/steamapps/appmanifest_896660.acf"
}

# $1=label  $2=path (relative to game/)  $3=survives|removed  $4=world
check() {
	local p="$TMP/$4/game/$2"
	if [ "$3" = "survives" ]; then
		if [ -e "$p" ]; then echo "PASS  $1"; pass=$((pass + 1))
		else echo "FAIL  $1 -- DESTROYED: $2"; fail=$((fail + 1)); fi
	else
		if [ ! -e "$p" ]; then echo "PASS  $1"; pass=$((pass + 1))
		else echo "FAIL  $1 -- still present: $2"; fail=$((fail + 1)); fi
	fi
}

for lvl in 2 3 4; do
	echo "--------------------------------- heal level $lvl"
	buildWorld "w$lvl"
	healSteamcmdState "w$lvl" "$lvl" > /dev/null

	# SURVIVAL -- asserted at EVERY level, because a regression at any one loses data.
	check "L$lvl: world save .fwl2 survives"        "$SAVEDIR/Brotality/_main.fwl2" survives "w$lvl"
	check "L$lvl: world db .db survives"            "$SAVEDIR/Brotality/_main.db"   survives "w$lvl"
	check "L$lvl: permittedlist survives"           "$SAVEDIR/permittedlist.txt"    survives "w$lvl"
	check "L$lvl: BepInEx plugins survive"          "BepInEx/plugins/SomeMod.dll"   survives "w$lvl"
	check "L$lvl: loader BepInEx.cfg survives"      "BepInEx/config/BepInEx.cfg"    survives "w$lvl"
	check "L$lvl: installed game content survives"  "steamapps/common/Valheim dedicated server/marker" survives "w$lvl"

	# REMOVAL -- proves the repair escalates instead of repeating itself.
	check "L$lvl: steamcmd bootstrap cleared"       "Steam/logs/stderr.txt"         removed  "w$lvl"

	if [ "$lvl" -ge 3 ]; then
		check "L$lvl: partial download discarded"   "steamapps/downloading"         removed  "w$lvl"
		check "L$lvl: steam temp discarded"         "steamapps/temp"                removed  "w$lvl"
	else
		check "L$lvl: partial download KEPT at L2"  "steamapps/downloading/896660/chunk" survives "w$lvl"
	fi

	if [ "$lvl" -ge 4 ]; then
		check "L$lvl: manifest cleared"             "steamapps/appmanifest_896660.acf" removed  "w$lvl"
	else
		check "L$lvl: manifest KEPT below L4"       "steamapps/appmanifest_896660.acf" survives "w$lvl"
	fi
done

echo "--------------------------------- guards"
# An empty world name would aim every path at the shared worlds root.
buildWorld guard
if healSteamcmdState "" 4 2>/dev/null | grep -q "refusing"; then
	echo "PASS  empty world name is refused"; pass=$((pass + 1))
else
	echo "FAIL  empty world name was NOT refused"; fail=$((fail + 1))
fi
check "other worlds untouched by the empty-name call" "$SAVEDIR/Brotality/_main.fwl2" survives guard

# .steam is recreated, not just deleted -- the engine relies on it existing.
[ -d "$TMP/w2/game/.steam" ] \
	&& { echo "PASS  .steam recreated after clearing"; pass=$((pass + 1)); } \
	|| { echo "FAIL  .steam not recreated"; fail=$((fail + 1)); }

echo
echo "passed=$pass failed=$fail"
[ "$fail" = "0" ] || exit 1
exit 0
