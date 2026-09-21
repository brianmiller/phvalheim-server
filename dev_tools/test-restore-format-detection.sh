#!/bin/bash
# Regression test: backup format detection in worldRestore (issue #89)
#
# Restoring any 2.38+ backup booted the world into a brand-new seed instead of the saved
# progress. The cause was the legacy-format probe in worldRestore step 5:
#
#	tar tf "$backupFilePath" | grep -q "worlds_local/" && isOldFormat=1
#
# BOTH backup generations contain worlds_local/ -- they differ only in WHERE it sits.
#   pre-2.38 worldBackup:  cd $worldDir/game/.config/unity3d/IronGate/Valheim && tar cf .
#                          -> ./worlds_local/ at the archive ROOT
#   2.38+    worldBackup:  cd $worldDir && tar cf .
#                          -> ./game/.config/unity3d/IronGate/Valheim/worlds_local/
#
# Unanchored, that grep matched every modern archive, so every restore took the legacy
# branch and unpacked the whole world tree into $worldDir/game/.config/unity3d/IronGate/
# Valheim/ -- putting the real save at a doubly-nested path and leaving the server's
# -savedir worlds_local EMPTY. Valheim then generated a fresh world. The "new format"
# branch was unreachable code.
#
# This test exercises the REAL function out of the shipping script (sed + eval), not a
# copy, so editing worldRestore's probe changes what is tested here. It then asserts the
# decisive user-visible property: after restore, the save is readable at the exact
# worlds_local path the server is pointed at.
#
# Run: ./dev_tools/test-restore-format-detection.sh     (no container, no root needed)

REPO="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
RESTORE="$REPO/container/engine/tools/worldRestore"

PASS=0
FAIL=0
ok(){ echo "  PASS  $1"; PASS=$((PASS+1)); }
no(){ echo "  FAIL  $1"; FAIL=$((FAIL+1)); }

UNITY_REL="game/.config/unity3d/IronGate/Valheim"
W="TestWorld"

[ -r "$RESTORE" ] || { echo "cannot read $RESTORE"; exit 1; }

# --- Pull the real detection function out of the shipping script --------------------
fnSrc=$(sed -n '/^function archiveListingIsLegacy()/,/^}/p' "$RESTORE")
if [ -z "$fnSrc" ]; then
	no "worldRestore defines archiveListingIsLegacy() (the probe must be testable)"
	echo; echo "  $PASS passed, $FAIL failed"; exit 1
fi
eval "$fnSrc" || { echo "  FAIL  could not eval archiveListingIsLegacy()"; exit 1; }
ok "extracted archiveListingIsLegacy() from the shipping worldRestore"

# --- Guard the assumptions this test makes about worldRestore's two branches ---------
# If the extraction targets move, the outcome assertions below would silently go stale.
grep -q 'targetDir="\$worldDir/'"$UNITY_REL"'"' "$RESTORE" \
	&& ok "legacy branch still extracts to \$worldDir/$UNITY_REL" \
	|| no "legacy branch target changed -- update this test"
grep -q 'tar xf "\$backupFilePath" -C "\$worldDir"' "$RESTORE" \
	&& ok "modern branch still extracts to \$worldDir" \
	|| no "modern branch target changed -- update this test"

T=$(mktemp -d) || exit 1
trap 'rm -rf "$T"' EXIT

# --- Fixture: a world directory in the real 2.38+ on-disk shape ---------------------
WD="$T/worlds/$W"
SAVE="$WD/$UNITY_REL/worlds_local/$W"
mkdir -p "$SAVE" "$WD/custom_configs" "$WD/game/BepInEx/plugins"
printf 'REAL_SAVE_DB'  > "$SAVE/_main.1.db2"
printf 'REAL_SAVE_FWL' > "$SAVE/_main.1.fwl2"
printf 'adminlist'     > "$WD/$UNITY_REL/adminlist.txt"
printf 'plugin'        > "$WD/game/BepInEx/plugins/x.dll"

mkdir -p "$T/backups"

# modern archive -- byte-for-byte how worldBackup builds it
NEW="$T/backups/valheimworld_${W}-new.tar"
( cd "$WD" && tar cf "$NEW" --exclude="./${W}.zip" . ) 2>/dev/null

# legacy archive -- how pre-2.38 worldBackup built it
LEGSRC="$T/legacy/$W/$UNITY_REL"
mkdir -p "$LEGSRC/worlds_local"
printf 'LEGACY_DB' > "$LEGSRC/worlds_local/${W}.db"
printf 'LEGACY_FWL' > "$LEGSRC/worlds_local/${W}.fwl"
printf 'oldadmin'  > "$LEGSRC/adminlist.txt"
OLD="$T/backups/valheimworld_${W}-old.tar"
( cd "$LEGSRC" && tar cf "$OLD" . ) 2>/dev/null

# a legacy archive stored WITHOUT the ./ prefix (tar cf f.tar worlds_local adminlist.txt)
OLD2="$T/backups/valheimworld_${W}-old-noprefix.tar"
( cd "$LEGSRC" && tar cf "$OLD2" worlds_local adminlist.txt ) 2>/dev/null

# --- Detection ----------------------------------------------------------------------
detect(){ tar tf "$1" 2>/dev/null | archiveListingIsLegacy && echo 1 || echo 0; }

[ "$(detect "$NEW")" = "0" ] \
	&& ok "modern backup (worlds_local nested under game/) is NOT read as legacy" \
	|| no "modern backup misread as legacy -- this is issue #89"

[ "$(detect "$OLD")" = "1" ] \
	&& ok "legacy backup (./worlds_local at archive root) is read as legacy" \
	|| no "legacy backup no longer detected -- legacy restores would break"

[ "$(detect "$OLD2")" = "1" ] \
	&& ok "legacy backup without ./ prefix is read as legacy" \
	|| no "legacy backup without ./ prefix not detected"

# gz + zst travel the other two code paths in worldRestore's case statement
NEWGZ="$T/backups/valheimworld_${W}-new.tar.gz"
( cd "$WD" && tar czf "$NEWGZ" --exclude="./${W}.zip" . ) 2>/dev/null
[ "$(tar tzf "$NEWGZ" 2>/dev/null | archiveListingIsLegacy && echo 1 || echo 0)" = "0" ] \
	&& ok "modern .tar.gz backup is NOT read as legacy" \
	|| no "modern .tar.gz backup misread as legacy"

if command -v zstd >/dev/null 2>&1; then
	NEWZST="$T/backups/valheimworld_${W}-new.tar.zst"
	( cd "$WD" && tar cf - --exclude="./${W}.zip" . | zstd -q -o "$NEWZST" ) 2>/dev/null
	v=$(zstd -dq --stdout "$NEWZST" 2>/dev/null | archiveListingIsLegacy && echo 1 || echo 0)
	[ "$v" = "0" ] \
		&& ok "modern .tar.zst backup is NOT read as legacy" \
		|| no "modern .tar.zst backup misread as legacy"
else
	echo "  SKIP  zstd not installed, .tar.zst path not exercised"
fi

# --- The real oracle: can the server actually read the restored save? ---------------
# Mirrors worldRestore step 5, which the two grep guards above pin down.
restoreInto(){
	local archive="$1" dest="$2"
	mkdir -p "$dest"
	if [ "$(detect "$archive")" = "1" ]; then
		mkdir -p "$dest/$UNITY_REL"
		tar xf "$archive" -C "$dest/$UNITY_REL"
	else
		tar xf "$archive" -C "$dest"
	fi
}

D1="$T/restored-modern/$W"
restoreInto "$NEW" "$D1"
SD1="$D1/$UNITY_REL/worlds_local"
if [ -f "$SD1/$W/_main.1.fwl2" ] && [ "$(cat "$SD1/$W/_main.1.db2" 2>/dev/null)" = "REAL_SAVE_DB" ]; then
	ok "modern restore: save is readable at the server's -savedir worlds_local"
else
	no "modern restore: server's -savedir has no save -- Valheim generates a fresh world"
fi
# nothing may be buried a second world-tree deep
if find "$D1" -path "*/$UNITY_REL/game/*" -name '*.fwl2' 2>/dev/null | grep -q .; then
	no "modern restore: save buried at a doubly-nested .config path"
else
	ok "modern restore: no doubly-nested .config path"
fi
# the rest of the world dir must land at the root too
[ -d "$D1/custom_configs" ] && [ -f "$D1/game/BepInEx/plugins/x.dll" ] \
	&& ok "modern restore: custom_configs/ and game/BepInEx/ land at the world root" \
	|| no "modern restore: world directory contents did not land at the world root"

D2="$T/restored-legacy/$W"
restoreInto "$OLD" "$D2"
if [ "$(cat "$D2/$UNITY_REL/worlds_local/${W}.db" 2>/dev/null)" = "LEGACY_DB" ]; then
	ok "legacy restore: save still lands at the server's -savedir worlds_local"
else
	no "legacy restore: save did not land at the Unity save path"
fi

# --- step 5b: recovering a world already damaged by the old bug ----------------------
# A world restored by 2.38-2.47 is nested one level deep, and so is every backup taken of
# it afterwards. Restoring such a backup correctly still buries the save, so worldRestore
# lifts the nested tree. Exercise the real loop out of the shipping script.
liftSrc=$(sed -n '/^unityRel="game\/.config\/unity3d\/IronGate\/Valheim"/,/^fi$/p' "$RESTORE")
if [ -z "$liftSrc" ]; then
	no "worldRestore contains the issue #89 nesting repair (step 5b)"
else
	ok "extracted the step 5b nesting repair from the shipping worldRestore"

	# Build a world dir shaped exactly like one damaged by N successive bad restores.
	# One bad restore buries the whole world root under $UNITY_REL, so the save ends up
	# under N+1 repetitions of it.
	makeDamaged(){
		local dest="$1" levels="$2" i
		# NOT in the local above: bash expands every word of `local` before assigning any of
		# them, so "prefix=$dest" there would see an empty, freshly-shadowed dest.
		local prefix="$dest"
		rm -rf "$dest"; mkdir -p "$dest"
		for ((i=0;i<levels;i++)); do prefix="$prefix/$UNITY_REL"; done
		mkdir -p "$prefix/$UNITY_REL/worlds_local/$W" "$prefix/custom_configs"
		printf 'BURIED_DB'  > "$prefix/$UNITY_REL/worlds_local/$W/_main.1.db2"
		printf 'BURIED_FWL' > "$prefix/$UNITY_REL/worlds_local/$W/_main.1.fwl2"
		printf 'cfg'        > "$prefix/custom_configs/keep.cfg"
	}

	# the repair mutates the tree, so it must run in-process, not in a subshell
	LOG=/dev/null
	progress(){ :; }

	for lv in 1 2; do
		D="$T/damaged-$lv/$W"
		makeDamaged "$D" "$lv"
		worldDir="$D"; eval "$liftSrc" >/dev/null 2>&1
		if [ "$(cat "$D/$UNITY_REL/worlds_local/$W/_main.1.db2" 2>/dev/null)" = "BURIED_DB" ] \
		   && [ -f "$D/custom_configs/keep.cfg" ] \
		   && [ ! -d "$D/$UNITY_REL/$UNITY_REL" ]; then
			ok "step 5b: a world nested $lv level(s) by the old bug is lifted back to the root"
		else
			no "step 5b: nesting of $lv level(s) not repaired"
		fi
		rm -rf "$T/damaged-$lv"
	done

	# a HEALTHY world must be left completely alone
	DH="$T/healthy/$W"
	mkdir -p "$DH/$UNITY_REL/worlds_local/$W" "$DH/custom_configs"
	printf 'GOOD_DB' > "$DH/$UNITY_REL/worlds_local/$W/_main.1.db2"
	printf 'cfg'     > "$DH/custom_configs/keep.cfg"
	beforeList=$(cd "$DH" && find . | sort)
	worldDir="$DH"; eval "$liftSrc" >/dev/null 2>&1
	afterList=$(cd "$DH" && find . | sort)
	if [ "$beforeList" = "$afterList" ] \
	   && [ "$(cat "$DH/$UNITY_REL/worlds_local/$W/_main.1.db2")" = "GOOD_DB" ]; then
		ok "step 5b: a healthy world directory is left untouched"
	else
		no "step 5b: a healthy world directory was modified"
	fi
fi

echo
echo "  $PASS passed, $FAIL failed"
[ "$FAIL" -eq 0 ] || exit 1
