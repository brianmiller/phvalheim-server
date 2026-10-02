#!/bin/bash

source /opt/stateless/engine/includes/phvalheim-static.conf

## BEGIN UPDATE ##
#
# 2.53: crossplay on modded worlds, and the Companion takes over joining.
#
# Object-by-object idempotent rather than one top-level guard, for the same reason as 2.40,
# 2.43, 2.45 and 2.47: this ships as an RC first, and later revisions of THIS script must run
# on servers that already ran an earlier revision.

echo "`date` [NOTICE : phvalheim] Applying database schema update for phvalheim-server >=v2.53"


# --- the one-shot "how players join has changed" notice --------------------------------
#
# 0 = not yet shown, 1 = shown or nothing to say.
#
# Created and seeded in the SAME branch, deliberately. Every script in dbUpdates/ runs on
# EVERY boot -- dbUpdater.sh has no version gate -- so seeding outside the
# column-does-not-exist check would re-decide the answer on every restart. dbUpdate_2.40.sh
# documents at length what that costs.
#
# The seed value is the whole point of this block:
#
#   An UPGRADER with modded worlds  -> 0. Their worlds each need updating once to move to
#                                     the Companion join path, and nothing else in the
#                                     product will tell them so.
#   Anyone else                     -> 1. A fresh install has no world built the old way,
#                                     and a server with only vanilla worlds is unaffected
#                                     (vanilla worlds join with +connect and no mod at all).
#                                     Neither has anything to be told, and 2.31/2.35 shipped
#                                     the setup wizard to the wrong audience twice by
#                                     guessing this the other way round.
#
# MODDED worlds specifically, not the total world count that dbUpdate_2.40/2.42 use for the
# What's New modal. Those ask "has this server ever run an older version"; this asks "does
# this server own anything the change actually affects", which is a different question and
# has a different answer on a vanilla-only server.
sql "DESCRIBE settings"|awk '{print $1}'|grep -qx "connectNoticeShown" > /dev/null 2>&1
if [ ! $? = 0 ]; then
	echo "`date` [NOTICE : phvalheim] Adding settings.connectNoticeShown"
	sql "ALTER TABLE settings ADD COLUMN connectNoticeShown TINYINT NOT NULL DEFAULT 0;"

	moddedWorldCount=$(sql "SELECT COUNT(*) FROM worlds WHERE IFNULL(vanilla,0) = 0")
	case "$moddedWorldCount" in
		''|*[!0-9]*) moddedWorldCount=0 ;;
	esac

	if [ "$moddedWorldCount" -eq 0 ]; then
		echo "`date` [NOTICE : phvalheim] No modded worlds - marking the join-path notice as seen"
		sql "UPDATE settings SET connectNoticeShown = 1;"
	else
		echo "`date` [NOTICE : phvalheim] $moddedWorldCount modded world(s) - the join-path notice will be shown once"
	fi
fi

# --- per-mod install destination: server tree and/or client payload -------------------
#
# Two switches per mod per world. See section 8 of docs/RELEASE-2.53-DESIGN.md.
#
# DEFAULT 1 on both is the whole migration story, and it is load-bearing rather than
# convenient: every world_mods row that already exists keeps installing exactly where it
# installs today, so an upgrade changes nothing on disk until an operator deliberately
# flips a switch. No backfill, no notice, and nothing to get wrong for the servers already
# running the 2.53 RC -- they pick these columns up on their next boot.
#
# NOT NULL as well as DEFAULT, because the flags are read as booleans in three languages
# (bash, python, PHP) and a NULL would arrive as "" in the plan TSV, where an empty field
# is indistinguishable from "off". The 2.50 StateFlags work is the standing reminder that a
# falsy default doubles as a real answer if you let it.
#
# The columns are added separately, each behind its own check, for the reason the notice
# block above gives: later revisions of THIS script run on servers that already applied an
# earlier revision of it, and one that adds only the second column must still work.
for destCol in deploy_server deploy_client; do
	sql "DESCRIBE world_mods"|awk '{print $1}'|grep -qx "$destCol" > /dev/null 2>&1
	if [ ! $? = 0 ]; then
		echo "`date` [NOTICE : phvalheim] Adding world_mods.$destCol"
		sql "ALTER TABLE world_mods ADD COLUMN $destCol TINYINT NOT NULL DEFAULT 1;"
	fi
done

# --- the Companion stops being a catalogue mod ------------------------------------------
#
# It ships in the image from 2.53 and is installed by installSystemPlugins(). It came OUT of
# requiredMods in the same change -- but mergeRequiredTsMods() only ever INSERTs, so every
# world built before this upgrade still carries its is_dep=0 row and would keep installing the
# Thunderstore copy on top of the bundled one.
#
# Both unzip into BepInEx/plugins/PhValheimCompanion, so the operator would not get an error --
# they would get whichever copy was written last, a mod in their world's mod list that they can
# deselect to no effect, and an entry in the Updates tab tracking a version that no longer
# decides anything. Two DLLs with the same BepInEx GUID is also the one packaging outcome
# section 9.2 of the design doc says to avoid.
#
# Unconditional rather than guarded, and safe to run on every boot: after 2.53 there is no
# legitimate reason for this row to exist, so deleting it is idempotent by construction. The
# NOTICE is emitted only when something was actually removed, so a steady-state boot stays
# quiet -- "cleaned up 0 rows" every two seconds is how a log stops being read.
companionRows=$(sql "SELECT COUNT(*) FROM world_mods wm JOIN mods m ON m.id = wm.mod_id WHERE m.name = 'PhValheimCompanion';")
case "$companionRows" in
	''|*[!0-9]*) companionRows=0 ;;
esac

if [ "$companionRows" -gt 0 ]; then
	echo "`date` [NOTICE : phvalheim] The PhValheim Companion now ships with the server; removing $companionRows catalogue mod row(s) for it. Worlds will pick up the bundled copy the next time they are updated."
	sql "DELETE wm FROM world_mods wm JOIN mods m ON m.id = wm.mod_id WHERE m.name = 'PhValheimCompanion';"
fi

# --- QuickConnect is retired from worlds that PhValheim put it in ----------------------
#
# Why a migration and not the install path.
#
# QuickConnect came out of requiredMods when companionProvidesConnect went to 1, so nothing
# ADDS it any more. But mergeRequiredTsMods() only ever INSERTed, so every world built while
# the flag was 0 still carries its world_mods row -- and world_mods is the source of truth for
# what gets installed. purgeWorldModsConfigsPatchers() deletes the plugin FILES on both trees
# before each rebuild, so it looked like an update would drop it; the reinstall that follows
# reads the row straight back out of the database and puts it there again. Brian updated a
# world and QuickConnect was still in plugins/ afterwards, on both trees.
#
# ONE-TIME, via a settings flag, and that is the important difference from the Companion block
# above. The Companion can never legitimately be a catalogue mod again, so deleting its rows
# unconditionally is idempotent by construction. QuickConnect is an ordinary Thunderstore mod
# that an operator is allowed to want: nothing in the schema distinguishes "PhValheim inserted
# this for connectivity" from "the operator picked it". Deleting it on every boot would
# therefore silently strip a deliberate choice every two seconds, with a NOTICE each time --
# a policy masquerading as a migration. Retiring it exactly once leaves the operator in
# control afterwards.
#
# A world still needs ONE update after this to lose the files, because the row going away only
# changes what the next rebuild installs. That matches what the What's New entry promises.
sql "DESCRIBE settings"|awk '{print $1}'|grep -qx "quickConnectRetired" > /dev/null 2>&1
if [ ! $? = 0 ]; then
	echo "`date` [NOTICE : phvalheim] Adding settings.quickConnectRetired"
	sql "ALTER TABLE settings ADD COLUMN quickConnectRetired TINYINT NOT NULL DEFAULT 0;"
fi

quickConnectRetired=$(sql "SELECT IFNULL(MAX(quickConnectRetired),0) FROM settings;")
case "$quickConnectRetired" in
	''|*[!0-9]*) quickConnectRetired=0 ;;
esac

if [ "$quickConnectRetired" -eq 0 ]; then
	# Matched on owner AND name. 'QuickConnect' alone could collapse a differently-owned
	# package of the same name from either catalogue, and mods identity in this project is
	# (source, owner, name) -- never the name on its own.
	quickConnectRows=$(sql "SELECT COUNT(*) FROM world_mods wm JOIN mods m ON m.id = wm.mod_id WHERE m.owner = 'bdew' AND m.name = 'QuickConnect';")
	case "$quickConnectRows" in
		''|*[!0-9]*) quickConnectRows=0 ;;
	esac

	if [ "$quickConnectRows" -gt 0 ]; then
		echo "`date` [NOTICE : phvalheim] The PhValheim Companion now handles joining; retiring $quickConnectRows QuickConnect mod row(s). Worlds will drop it the next time they are updated."
		sql "DELETE wm FROM world_mods wm JOIN mods m ON m.id = wm.mod_id WHERE m.owner = 'bdew' AND m.name = 'QuickConnect';"
	fi

	# Set regardless of whether any rows existed, so a server with none does not re-check
	# forever, and so an operator who adds QuickConnect back is never second-guessed.
	sql "UPDATE settings SET quickConnectRetired = 1;"
fi

## END UPDATE ##

exit 0
