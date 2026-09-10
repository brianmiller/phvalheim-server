#!/bin/bash

# all dbUpdater scripts must be executable!

# This script is deliberately column-by-column idempotent rather than using a single
# "is this update already applied?" guard.
#
# 2.40 ships as an RC while we wait on the Deep North boss trophy prefab name (see
# docs/RELEASE-2.40-DESIGN.md section 2). The boss column therefore lands in a LATER
# revision of this same script, on servers that have already run the earlier revision.
# A single top-level guard would skip it forever on exactly the machines running the RC.
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

echo "`date` [NOTICE : phvalheim] Applying database schema update for phvalheim-server >=v2.40"

## BEGIN UPDATE ##

# --- worlds table: vanilla (zero-mod) world support (issue #81) ---
#
# `vanilla`, `password` and `listed` are VANILLA-ONLY settings. Modded worlds keep their
# existing behaviour: gated by the CITIZENS list (permittedlist.txt), -public 0, no -password.
#
# `crossplay` is NOT vanilla-only -- it controls whether Xbox / Microsoft Store players can
# join, which is orthogonal to whether a world runs mods. It applies to EVERY world and is
# grouped here only because 2.40 is when it was added.
#
# NOTE: worlds.public already exists and does NOT mean "public server". It is an
# access-control flag written by saveCitizensJson() -- when 1 it BLANKS permittedlist.txt,
# i.e. "anyone may join, stop enforcing the citizens list". Routing it into Valheim's
# -public argument would silently list every open world in the global Steam server
# browser on upgrade. That is why `listed` below is a separate column.
addColumn worlds vanilla       "TINYINT DEFAULT 0"
addColumn worlds password      "VARCHAR(64) DEFAULT NULL"
addColumn worlds crossplay     "TINYINT DEFAULT 0"
addColumn worlds listed        "TINYINT DEFAULT 0"

# Whether the password is shown on the public world card. Defaults to 1 because a
# vanilla world is unjoinable without it -- Valheim has no way to receive a password
# from a launch argument, so the player must read it somewhere and type it.
# Turn it off for a world whose password is shared out of band.
addColumn worlds password_public "TINYINT DEFAULT 1"

# --- worlds table: custom launch parameters (all worlds) ---
addColumn worlds launch_params "VARCHAR(512) DEFAULT NULL"

# --- worlds table: per-world admin and banned lists (all worlds) ---
#
# These mirror adminlist.txt and bannedlist.txt. The DATABASE is the source of truth:
# syncAccessLists.sh rewrites all three files from these columns at every world start, so
# a world that was restored from an old backup, or rebuilt, converges back to what the
# admin UI shows instead of silently keeping a stale list.
addColumn worlds admins        "TEXT DEFAULT NULL"
addColumn worlds banned        "TEXT DEFAULT NULL"

# --- worlds table: Deep North boss trophy ---
#
# BLOCKED until the Valheim 1.0 trophy prefab name is confirmed. The value must be the
# lowercased prefab name, matching the existing trophyeikthyr ... trophyfader columns,
# because setHungHeads() derives the column name from the prefab name the companion mod
# reads off the item stand at runtime.
#
# To land it: uncomment, set the real name, and add the matching entry to
# container/nginx/www/includes/bosses.php. Re-running this script picks it up.
#
#addColumn worlds trophy<newboss> "BOOL DEFAULT 0"

# --- settings table: one-time Access-tab notice ---
#
# DEFAULT 1 means "already seen", i.e. stay quiet. migrateAccessIds.php flips it to 0 only
# when it actually converts something, so a fresh install never gets a notice about a
# migration it never had, and a failed migration cannot produce a spurious one.
addColumn settings accessIdNoticeShown "TINYINT DEFAULT 1"

# --- one-time notice: the world access switch was RENAMED AND INVERTED in 2.40 ---
#
# "Public World: on" became "Use Access List: off". Same stored flag, same actual access,
# opposite-looking control -- so an upgrader who "corrects" it back really does change who
# can join. That is worth one modal.
#
# Armed here rather than by addColumn because it needs a value that depends on whether
# this is an upgrade, and it must be armed EXACTLY ONCE. Every script in dbUpdates/ runs
# on EVERY boot -- dbUpdater.sh has no version gate -- so an unconditional UPDATE would
# re-raise the notice after every restart and it could never stay dismissed. The column
# not existing yet is the only reliable "this database has not seen 2.40 before" signal.
sql "DESCRIBE settings"|awk '{print $1}'|grep -qx "accessSwitchNoticeShown" > /dev/null 2>&1
if [ ! $? = 0 ]; then
	echo "`date` [NOTICE : phvalheim] Adding settings.accessSwitchNoticeShown"
	# DEFAULT 1 = "already seen", i.e. stay quiet, so the arming below is the only
	# thing that can ever raise it.
	sql "ALTER TABLE settings ADD COLUMN accessSwitchNoticeShown TINYINT DEFAULT 1;"

	# A server that already has worlds is upgrading, and its switches are about to
	# change appearance. A fresh install has no switch it ever saw the old way.
	worldCount=$(sql "SELECT COUNT(*) FROM worlds")
	case "$worldCount" in
		''|*[!0-9]*) worldCount=0 ;;
	esac
	if [ "$worldCount" -gt 0 ]; then
		echo "`date` [NOTICE : phvalheim] Upgrade detected ($worldCount worlds) - arming the access-switch notice"
		sql "UPDATE settings SET accessSwitchNoticeShown = 0;"
	fi
fi

## END UPDATE ##

# --- Valheim 1.0 access-id format ---
#
# Valheim 1.0 matches permitted/admin/banned entries on the PlatformUserID DISPLAY form
# (V_ for Steam), not the bare SteamID64 an operator types. syncAccessLists.sh already
# canonicalises on the way out to the files, and upgrading restarts every world, so an
# upgraded server is functionally correct before this runs.
#
# This normalises the DATABASE so the Access tab shows the same thing Valheim receives.
# Idempotent, and it keeps anything it cannot parse rather than dropping it.
if [ -x /usr/bin/php ]; then
	/usr/bin/php /opt/stateless/engine/tools/migrateAccessIds.php
else
	echo "`date` [WARNING : phvalheim] php CLI missing -- skipped access id normalisation. Stored ids still work; only the Access tab display is affected."
fi

# Deliberately NO backfill.
#
# Every pre-2.40 world lands on vanilla=0, listed=0, crossplay=0, password=NULL, which is
# byte-for-byte what those worlds do today. Backfilling password='hammertime' would look
# harmless but would start feeding a -password argument to modded worlds that have never
# had one.

exit 0
