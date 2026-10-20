#!/bin/bash

source /opt/stateless/engine/includes/phvalheim-static.conf

addColumn() {
	table="$1"
	column="$2"
	definition="$3"

	sql "DESCRIBE $table"|awk '{print $1}'|grep -qx "$column" > /dev/null 2>&1
	if [ ! $? = 0 ]; then
		echo "`date` [NOTICE : phvalheim] Adding $table.$column"
		sql "ALTER TABLE $table ADD COLUMN $column $definition;"
	fi
}

## BEGIN UPDATE ##
#
# 2.56
#
# Object-by-object idempotent rather than one top-level guard, for the same reason as every
# migration since 2.40: this ships as an RC first, and later revisions of THIS script must run
# on servers that already ran an earlier revision.

echo "`date` [NOTICE : phvalheim] Applying database schema update for phvalheim-server >=v2.56"


# --- worlds.join_code: the crossplay join code, remembered --------------------------------
#
# THE BUG (production, 2026-10-18): a modded crossplay world was up and joinable on code
# 417250 for 35 hours while the dashboard said "waiting for its join code". Its startup was
# verbose enough to pass settings.maxLogSize within five minutes, logRotater.sh (cp + truncate,
# ONE generation kept) moved every mention of the code out of the live log, and Valheim only
# re-prints it when a player joins or leaves. The code was stored nowhere, so once the log
# lost it nothing could find it again.
#
# Written by engine/tools/playerMonitor, which already reads every running world's log every
# two minutes. Read by getWorldJoinCode() in db_gets.php, and ONLY when neither log generation
# still says anything about the running session -- a visible session start with no code after
# it means "not issued yet", whatever this column holds. The safety argument for never serving
# a previous session's code lives with the writer, in playerMonitor.
#
# NULL is the default and means "nothing remembered", which the reader treats exactly like a
# log with no code in it.
addColumn worlds join_code "VARCHAR(16) NULL"


# --- mod_plugin_guids.learned / .manual: an operator can say which mod wrote a file -----------
#
# Asked for on Discord (2026-10-19) right after the ValheimPlus report: "we detected an orphaned
# config file, does it match one of your mods?" Learning covers most stragglers now, but not a
# file a mod names itself (original ValheimPlus writes valheim_plus.cfg, no GUID in sight), a
# DLL the scanner cannot read, or a package whose zip and installed copy are both gone.
#
# Stored as one more fact in THIS table, not in a table of its own. Every reader that asks "whose
# file is this" -- the live editor, the Config badges, the boot re-attribution, the check that
# decides whether a missing config may be recreated -- already reads mod_plugin_guids; a second
# store would be a second attributor, and two attributors are how two answers start to differ.
# The file's stem is recorded as the GUID because attribute() already treats a file whose stem
# is a known GUID as belonging to that GUID's mod.
#
# TWO flags, not one origin column. A row can be both: a DLL declares the GUID AND the operator
# asserted it (they matched a file the two-claimant rule had left unowned). With a single
# origin, undoing the match would either delete a learned fact the next boot then re-learns, or
# leave the assertion in place. Undo clears `manual`; a row with neither flag is deleted.
#
# Existing rows are all learned, which is exactly what the defaults say.
addColumn mod_plugin_guids learned "TINYINT NOT NULL DEFAULT 1"
addColumn mod_plugin_guids manual "TINYINT NOT NULL DEFAULT 0"
#
# BEFORE the learn step below, which writes `learned`: on the first boot of an upgrade the
# column has to exist by the time anything sets it.


# --- learn every world's plugin GUIDs, then re-attribute --------------------------------------
#
# THE REPORT (Discord, 2026-10-19): Grantapher-ValheimPlus_Grantapher_Temporary's 471 settings
# -- 166 of them changed -- sat under "Unmatched config files", and the mod itself was listed
# as having "no config file yet". The settings applied and reached players; only the label was
# wrong. But it is the label an operator navigates by.
#
# Attribution was never the problem. The package's DLL declares
# [BepInPlugin("org.bepinex.plugins.valheim_plus", ...)], which is EXACTLY the config's file
# name, and pluginGuids.py reads it correctly (verified on the real 10.3.0 zip). What was
# missing is the learning: mod_plugin_guids is filled only after a full mod install or an
# "Apply to players". A world installed before 2.55 and not rebuilt since has its zips cached
# and its GUIDs unknown -- and name matching cannot rescue a fork, because the package name
# (`valheimplusgrantaphertemporary`) resembles nothing in the file (`valheimplus`). Every fork
# with a suffix is the same straggler. Reproduced: same world, same file, Unmatched without the
# GUID row and attributed with it.
#
# dbUpdate_2.55.sh already re-attributes on every boot, but only from
# the GUIDs ALREADY KNOWN -- it never learns. So: learn, then re-attribute, every boot. Both are
# idempotent (an upsert; NULL -> value only) and cheap (the cached zips, a few MB per
# world). A package whose zip is gone is read from its installed directory instead.
#
# Here, and not only in dbUpdate_2.55.sh, because dbUpdater runs the scripts in version order:
# 2.55's re-attribute has already run by the time this learns, with nothing new to work from.
#
# Never fatal. A label that stays wrong for one more boot must not stop an upgrade.
if ! /opt/stateless/engine/tools/pluginGuids.py --all --learn; then
	echo "`date` [WARNING : phvalheim] Could not learn plugin GUIDs for every world. Config files for those mods may show as unmatched until their next rebuild; their settings still apply."
fi
if ! /opt/stateless/engine/tools/modConfigs.py --reattribute --all; then
	echo "`date` [WARNING : phvalheim] Could not re-attribute unowned mod config files after learning. Their settings still apply."
fi

