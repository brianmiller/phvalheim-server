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
# These four are VANILLA-ONLY settings. Modded worlds keep their existing behaviour:
# gated by the CITIZENS list (permittedlist.txt), -public 0, and no -password.
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

# --- worlds table: custom launch parameters (all worlds) ---
addColumn worlds launch_params "VARCHAR(512) DEFAULT NULL"

# --- worlds table: per-world admin list (all worlds) ---
addColumn worlds admins        "TEXT DEFAULT NULL"

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

## END UPDATE ##

# Deliberately NO backfill.
#
# Every pre-2.40 world lands on vanilla=0, listed=0, crossplay=0, password=NULL, which is
# byte-for-byte what those worlds do today. Backfilling password='hammertime' would look
# harmless but would start feeding a -password argument to modded worlds that have never
# had one.

exit 0
