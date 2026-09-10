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

# Convert one entry to the ONLY form Valheim will match.
#
# Valheim 1.0's ZNet.ListContainsId() ends with
#     val2 = PlatformUserID.FilterPlatformUserID(val);
#     if (val2 != val) { flag = list.Contains(val2.ToString()); }   # ASSIGNS, does not OR
# which replaces the earlier "Steam_<id>" and bare "<id>" checks with a lookup for the
# single-letter DISPLAY prefix form (Steam -> "V"). So only "V_<steamid64>" ever matches.
# Confirmed by decompiling the shipped assembly and verified on a live server.
#
# Must stay in lockstep with canonicalAccessId() in nginx/www/includes/accesslists.php.
canonicalId() {
	entry="$1"
	case "$entry" in
		# bare SteamID64
		[0-9][0-9][0-9][0-9][0-9][0-9][0-9][0-9][0-9][0-9][0-9][0-9][0-9][0-9][0-9][0-9][0-9])
			echo "V_$entry" ;;
		Steam_*)       echo "V_${entry#Steam_}" ;;
		Xbox_*)        echo "X_${entry#Xbox_}" ;;
		PlayStation_*) echo "S_${entry#PlayStation_}" ;;
		Nintendo_*)    echo "N_${entry#Nintendo_}" ;;
		GameCenter_*)  echo "A_${entry#GameCenter_}" ;;
		# already a display prefix, or something we do not recognise -- pass through
		*)             echo "$entry" ;;
	esac
}

# The fail-closed sentinel for permittedlist.txt.
#
# 76561197960265728 is the SteamID64 base -- account ID 0, which Steam does not issue. It is a
# structurally perfect SteamID64 that can never belong to anyone, so it can never accidentally
# grant access the way a real person's ID (Gabe Newell's, say) could.
#
# The comment goes on its OWN line, not trailing the entry. Valheim may well tolerate a
# same-line comment, but that has not been verified against the shipped assembly and there is
# nothing to gain by betting the access control on it.
#
# Must stay in lockstep with accesslists.php.
ACCESS_SENTINEL="V_76561197960265728"

# $1=target file, $2=header comment, $3=space separated ids, $4=1 if this list must RESTRICT
writeList() {
	target="$1"
	header="$2"
	ids="$3"
	enforced="$4"

	tmp="$target.tmp.$$"

	echo "$header" > "$tmp" || {
		echo "`date` [ERROR : phvalheim] Could not write $tmp"
		return 1
	}

	# One line per entry, converted on the way out. The database keeps whatever the
	# operator typed, so an existing world's plain SteamID64s are repaired on next start.
	if [ -n "$ids" ]; then
		for entry in $ids; do
			canonicalId "$entry" >> "$tmp"
		done
	elif [ "$enforced" = "1" ]; then
		# FAIL CLOSED. Valheim ENFORCES this list only when it has entries, so an empty
		# file is not "nobody may join" -- it is no restriction at all, and the world runs
		# wide open while the Access tab calls it restricted.
		#
		# This runs at EVERY world start, so it is the backstop that covers paths the
		# save-time guard cannot see: create (which never sets `public` or `citizens` at
		# all), clone, and restore. It also heals worlds that were already in this state.
		{
			echo "// PhValheim placeholder -- Steam account ID 0, which is never issued to anyone."
			echo "// Valheim ENFORCES this list only when it has entries, so an empty file would mean"
			echo "// ANYONE may join. This entry keeps \"Use Access List\" closed until a real player is"
			echo "// added. Remove it only by turning the access list off in the admin UI."
			echo "$ACCESS_SENTINEL"
		} >> "$tmp"
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
else
	# ENFORCED BUT EMPTY: the admin UI says "Use Access List: on" and the list has nobody in
	# it. Valheim only applies permittedlist.txt when it has ENTRIES -- an empty one is not
	# "nobody may join", it is no restriction at all. So this world is wide open while its
	# Access tab claims it is restricted, and that is the dangerous direction to be wrong in.
	#
	# saveCitizensJson() now refuses to CREATE this state, but nothing re-saves a world that
	# is already in it, so worlds predating that guard stay open and stay silent. This is the
	# only code that runs on every start and can see the condition, so it says so out loud.
	#
	# It is deliberately NOT auto-corrected. Both repairs -- add the intended players, or turn
	# the access list off -- are one click apart and mean opposite things; guessing on the
	# operator's behalf is how a server ends up locked or open against their intent.
	if [ -z "`echo \"$citizens\" | tr -d '[:space:]'`" ]; then
		echo "`date` [WARNING : phvalheim] World '$worldName' has the access list ENABLED but EMPTY. A placeholder entry is being written to keep it CLOSED, so NOBODY can join -- not even the operator. Fix it in Settings > Access: add at least one player ID, or switch 'Use Access List' off if the world is meant to be open."
	fi
fi

# Header lines are byte-for-byte what the real Valheim server writes when it creates these
# files itself. The DOUBLE space in the admin and banned headers is Valheim's, not a typo.
# Only CITIZENS gets the sentinel, and only when the list is enforced. An empty admin list
# ("no admins") and an empty banned list ("nobody banned") are both correct and safe as-is;
# forcing an entry into either would invent access rules nobody asked for.
enforceCitizens=0
[ "$isPublic" != "1" ] && enforceCitizens=1

writeList "$saveDir/permittedlist.txt" "// List permitted players ID ONE per line" "$citizens" "$enforceCitizens"
writeList "$saveDir/adminlist.txt"     "// List admin players ID  ONE per line"    "$admins"   0
writeList "$saveDir/bannedlist.txt"    "// List banned players ID  ONE per line"   "$banned"   0

echo "`date` [NOTICE : phvalheim] Access lists synced from database for '$worldName'."
exit 0
