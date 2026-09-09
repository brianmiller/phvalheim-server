#!/bin/sh
# Write permittedlist.txt / adminlist.txt / bannedlist.txt from the database.
#
# This script is /bin/sh, NOT bash. Keep it POSIX -- no arrays, no [[ ]], no ${x,,}.
#
# WHY THIS EXISTS
#
# Until 2.40 the only thing that ever wrote these files was the admin UI, at the moment an
# operator pressed Save. Nothing rewrote them at world start. That left two ways for the
# files to drift from what the admin UI showed, permanently and silently:
#
#   * A failed write. php-fpm ignored the return value of file_put_contents(), so a file it
#     could not replace (owned by root by an older engine, or by a restore) kept its old
#     contents while the UI said "Saved successfully". The world then went on enforcing a
#     stale citizens list and refusing players the operator had just added.
#   * A restore or a rebuild. worldRestore unpacks whatever list files the archive happened
#     to contain, over the top of the current ones.
#
# Running this at every world start makes the database the single source of truth and lets
# either kind of drift heal itself on the next restart.
#
# VERIFIED against the real Valheim dedicated server: it reads all three files from the
# -savedir ROOT, creates any that are missing at startup, and does not overwrite entries
# written from outside. See dev_tools/test-accesslists.sh.

if [ -z "$1" ]; then
	echo "`date` [ERROR : phvalheim] syncAccessLists.sh: missing world name"
	exit 1
fi

worldName="$1"

# Must stay in lockstep with the -savedir argument in startWorld.sh and with
# accessListDir() in nginx/www/includes/accesslists.php.
saveDir="/opt/stateful/games/valheim/worlds/$worldName/game/.config/unity3d/IronGate/Valheim"

mkdir -p "$saveDir" || {
	echo "`date` [ERROR : phvalheim] Could not create $saveDir"
	exit 1
}

# $1=target file, $2=header comment, $3=space separated ids
writeList() {
	target="$1"
	header="$2"
	ids="$3"

	tmp="$target.tmp.$$"

	echo "$header" > "$tmp" || {
		echo "`date` [ERROR : phvalheim] Could not write $tmp"
		return 1
	}

	# tr rather than a for loop so an empty list writes nothing at all rather than a
	# blank line. Valheim ignores blank lines, but an empty file is what it writes itself.
	if [ -n "$ids" ]; then
		echo "$ids" | tr ' ' '\n' >> "$tmp"
	fi

	# Atomic: Valheim may read this file at any moment, and a rename swaps it in whole.
	# It also replaces a file this user could not have opened for writing.
	mv -f "$tmp" "$target" || {
		echo "`date` [ERROR : phvalheim] Could not replace $target"
		rm -f "$tmp"
		return 1
	}

	chown phvalheim: "$target" 2>/dev/null
	chmod 664 "$target" 2>/dev/null
	return 0
}

# One row, tab separated, because `sql` runs mysql with --skip-column-names.
listSettings=$(/opt/stateless/engine/tools/sql "SELECT IFNULL(public,0), IFNULL(citizens,''), IFNULL(admins,''), IFNULL(banned,'') FROM worlds WHERE name='$worldName'")
isPublic=$(echo "$listSettings"  | cut -f1)
citizens=$(echo "$listSettings"  | cut -f2)
admins=$(echo "$listSettings"    | cut -f3)
banned=$(echo "$listSettings"    | cut -f4)

# `public` is the CITIZENS access-control flag: when 1 the permitted list is written EMPTY,
# which is Valheim's "anyone may join". It is NOT Valheim's -public server browser argument
# -- that is the separate `listed` column, read by startWorld.sh. Do not merge these two.
if [ "$isPublic" = "1" ]; then
	echo "`date` [NOTICE : phvalheim] World is public -- writing an empty permitted list."
	citizens=""
fi

# Header lines are byte-for-byte what the real Valheim server writes when it creates these
# files itself. The DOUBLE space in the admin and banned headers is Valheim's, not a typo.
writeList "$saveDir/permittedlist.txt" "// List permitted players ID ONE per line" "$citizens"
writeList "$saveDir/adminlist.txt"     "// List admin players ID  ONE per line"    "$admins"
writeList "$saveDir/bannedlist.txt"    "// List banned players ID  ONE per line"   "$banned"

echo "`date` [NOTICE : phvalheim] Access lists synced from database for '$worldName'."
exit 0
