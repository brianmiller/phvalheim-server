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

tableExists() {
	sql "SHOW TABLES LIKE '$1'" | grep -qx "$1" > /dev/null 2>&1
}

## BEGIN UPDATE ##
#
# 2.55: the mod config editor.
#
# Object-by-object idempotent rather than one top-level guard, for the same reason as 2.40,
# 2.43, 2.45, 2.47 and 2.53: this ships as an RC first, and later revisions of THIS script
# must run on servers that already ran an earlier revision.

echo "`date` [NOTICE : phvalheim] Applying database schema update for phvalheim-server >=v2.55"


# --- worlds.config_md5: the config-only payload checksum -------------------------------
#
# A second, finer sync signal beside world_md5. packageClient() now builds TWO archives from
# the client staging tree -- the full <world>.zip and an 80 KB <world>-config.zip holding just
# BepInEx/config -- and stamps a checksum for each. A client new enough to ask for both can
# then take an 80 KB download when only a config value moved, instead of 573 MB.
#
# NULL is the correct default and is load-bearing: it means "this world has not been packaged
# since the column existed", which is NOT the same as "its config archive is empty". A client
# that gets NULL must fall back to the full-payload comparison rather than concluding there is
# nothing to sync -- the same "unknown is not a value" rule that
# [[phvalheim_unknown_is_not_uptodate]] cost three shipped bugs to learn.
#
# WHY world_md5 IS NOT TOUCHED, which is the single biggest hazard in this change:
# Syncer.cs compares the server's world_md5 against getMD5() of the payload file ON DISK. So
# world_md5 must always equal the md5 of the real <world>.zip. Making it a composite of both
# archives -- the obvious way to make old clients notice a config change -- puts every old
# client in a PERMANENT re-download loop: it fetches the zip, hashes the bytes it just got,
# still disagrees with the composite, and tries again on every launch forever. config_md5 is
# strictly additive for that reason, and a repackage rebuilds BOTH archives so an old client
# degrades to exactly today's behaviour (a full re-download) instead of breaking.
addColumn worlds config_md5 "TEXT NULL"


# --- worlds.mods_md5: which MODS the payload holds, config excluded --------------------
#
# config_md5 alone was not enough, and the reason is the paragraph above. world_md5 must equal
# the md5 of the real zip, so it cannot also mean "the same mods" -- re-zipping an unchanged
# tree yields different bytes, and a repackage rebuilds the zip (deliberately: a NEW player
# downloads the full payload and must find the current settings inside it). So world_md5 moved
# on every config edit, the client's first question is "does the payload match", and the answer
# was always no. The 80 KB path could not be reached by any route. Measured live on
# VikingOutlaws: three repackages, three different world_md5 values, three full downloads.
#
# mods_md5 is the missing identity: md5 of the payload's per-entry CRC-32s and sizes, sorted,
# with BepInEx/config excluded (engine/tools/payloadKey.py). Stable across re-zips of the same
# mods, moves when a plugin does. The client asks mods_md5 "do I need the payload" and
# config_md5 "do I need the 80 KB"; world_md5 is left to verify a finished download.
#
# NULL for the same load-bearing reason as config_md5: a world not packaged since this column
# existed is UNKNOWN, and a client reading unknown must fall back to comparing the full
# payload. Costly, correct, and self-correcting on the next package.
addColumn worlds mods_md5 "TEXT NULL"


# --- mod_config_overrides -------------------------------------------------------------
#
# An operator's mod config edits, stored as SPARSE PER-KEY rows rather than whole files.
#
# WHY PER-KEY AND NOT A FILE SNAPSHOT
# purgeWorldModsConfigsPatchers() deletes BepInEx/config/* on every world update, so the
# config tree is derived and nothing may treat it as state. The pre-2.55 answer was
# custom_configs/, which stores whole FILES -- and a whole file pins the config at the shape
# the mod had when it was copied. On a mod update that means:
#
#   - a setting the new version ADDS is silently lost, because the old file is copied over
#     the freshly generated one and the new key disappears;
#   - a setting the new version REMOVED or RENAMED lingers as a dead key forever;
#   - "what did the operator actually change?" is unanswerable, so no UI can honestly say
#     "3 settings modified".
#
# A per-key row re-applies onto whatever the new version generates. New keys arrive at their
# new defaults, dead keys can be SHOWN as dead instead of silently re-injected, and
# modified-from-default is a real computation against the file's own `# Default value:`.
#
# r2modman and Gale get away with whole-file editing because they edit a live tree that
# nothing ever purges. PhValheim purges, so for us this is a requirement, not a refinement.
#
# WHY KEYED ON THE FILE AND NOT ON mod_id
# mod_id is ATTRIBUTION, and it is NULLABLE on purpose. Three classes of config file have no
# world_mods row to hang an override on, and all three are live:
#
#   - engine-installed non-catalogue plugins: ZeroBandwidth-CustomSeed (0-functions.sh ~469)
#     and PhValheim-TickMonitor (installSystemPlugins) are copied in from /opt/stateless and
#     are deliberately absent from the catalogue;
#   - operator-dropped DLLs in custom_plugins/, which 2.53 kept precisely because "a file in
#     custom_plugins/ has no catalogue identity";
#   - imported worlds, whose config tree importWorld.sh copies in wholesale.
#
# Keying on mod_id would have left every one of those uneditable and forced custom_configs/
# to survive as a second mechanism. Keyed on the file, mod_id set means "show this behind the
# mod row's Config icon" and mod_id NULL means "show it under Unattributed" -- and both
# directories retire completely.
#
# COLLATION IS LOAD-BEARING, same hazard as 2.43's mods.owner/name.
# BepInEx section and key names are case-sensitive, and so are config filenames on Linux.
# Under MySQL's default utf8mb4_0900_ai_ci, `Enabled` and `enabled` are the SAME row and the
# unique key below would silently collapse two different settings into one -- the operator
# would set one and watch the other change. The three identity columns are therefore
# _as_cs. Their widths are trimmed from the obvious 255 to keep the four-column unique key
# inside InnoDB's 3072-byte limit (4 + 160*4 + 128*4 + 128*4 = 1668).
#
# locked = engine-owned, operator may not edit. The world seed is the case that forced it:
# the engine computes ZeroBandwidth.CustomSeed.cfg from worlds.seed, so an operator-editable
# row there would let them fight the engine and lose on every update.
#
# origin records WHERE a row came from: operator (the editor), legacy (imported from
# custom_configs*/ by --import-legacy) or engine. A legacy row is a real override but it was
# never explicitly confirmed by anyone, which the UI needs to be able to say out loud.
if ! tableExists mod_config_overrides; then
	echo "`date` [NOTICE : phvalheim] Creating table 'mod_config_overrides'"
	sql "CREATE TABLE mod_config_overrides (
		id          INT UNSIGNED NOT NULL AUTO_INCREMENT,
		world_id    INT UNSIGNED NOT NULL,
		cfg_file    VARCHAR(160) COLLATE utf8mb4_0900_as_cs NOT NULL,
		section     VARCHAR(128) COLLATE utf8mb4_0900_as_cs NOT NULL,
		ckey        VARCHAR(128) COLLATE utf8mb4_0900_as_cs NOT NULL,
		cvalue      TEXT         NOT NULL,
		mod_id      INT UNSIGNED     NULL,
		server_only TINYINT      NOT NULL DEFAULT 0,
		locked      TINYINT      NOT NULL DEFAULT 0,
		origin      VARCHAR(16)  NOT NULL DEFAULT 'operator',
		date_set    DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
		PRIMARY KEY (id),
		UNIQUE KEY uk_override (world_id, cfg_file, section, ckey),
		KEY idx_world (world_id),
		KEY idx_mod (mod_id)
	) ENGINE=InnoDB;"
fi


# --- the one-shot legacy import -------------------------------------------------------
#
# 0 = custom_configs*/ has not been imported yet, 1 = it has (or there was nothing to take).
#
# Created and seeded in the SAME branch, deliberately: every script in dbUpdates/ runs on
# EVERY boot -- dbUpdater.sh has no version gate -- so a seed outside the
# column-does-not-exist check would re-decide the answer on every restart.
#
# The import itself is NOT done here. It needs to parse BepInEx config files and compare each
# key against that file's own `# Default value:` comment, which is modConfigs.py's job; bash
# re-implementing a cfg parser is how this would end up with two parsers that disagree.
#
# Why the diff matters, and it is the single biggest hazard in this release:
# importWorld.sh copies an imported world's ENTIRE BepInEx/config tree into custom_configs/.
# So an imported world looks on disk exactly like an operator who hand-copied hundreds of
# files, when almost all of them are untouched defaults. Import those as overrides and that
# world is FROZEN at its import-time defaults permanently -- every future mod update would
# have its new defaults overwritten by rows the operator never set and cannot reason about.
# --import-legacy therefore takes only keys that actually DIFFER from a knowable default.
sql "DESCRIBE settings"|awk '{print $1}'|grep -qx "configEditorMigrated" > /dev/null 2>&1
if [ ! $? = 0 ]; then
	echo "`date` [NOTICE : phvalheim] Adding settings.configEditorMigrated"
	sql "ALTER TABLE settings ADD COLUMN configEditorMigrated TINYINT NOT NULL DEFAULT 0;"
	sql "UPDATE settings SET configEditorMigrated=0;"
fi

# Run the import once. Gated on the flag rather than on "did it find anything", because
# finding nothing is a legitimate outcome (a fresh install has no custom_configs content)
# and re-running every boot would keep re-importing rows an operator had deliberately
# deleted in the editor.
#
# The flag is only set on a ZERO exit. A failed import that marked itself done would leave
# the operator's pre-2.55 edits silently dropped, which is the one outcome worth a retry.
configMigrated=$(sql "SELECT IFNULL(configEditorMigrated,0) FROM settings LIMIT 1;")
if [ "${configMigrated:-0}" = "0" ]; then
	echo "`date` [NOTICE : phvalheim] Importing pre-2.55 custom_configs/ and custom_configs_secure/ overrides..."
	if /opt/stateless/engine/tools/modConfigs.py --import-legacy --all; then
		sql "UPDATE settings SET configEditorMigrated=1;"
		echo "`date` [NOTICE : phvalheim] Legacy mod config import complete."
	else
		echo "`date` [ERROR : phvalheim] Legacy mod config import FAILED -- leaving configEditorMigrated=0 so the next boot retries. The operator's custom_configs/ files are untouched and still on disk."
	fi
fi
