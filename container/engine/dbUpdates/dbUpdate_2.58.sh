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

dropColumn() {
	table="$1"
	column="$2"

	sql "DESCRIBE $table"|awk '{print $1}'|grep -qx "$column" > /dev/null 2>&1
	if [ $? = 0 ]; then
		echo "`date` [NOTICE : phvalheim] Dropping $table.$column"
		sql "ALTER TABLE $table DROP COLUMN $column;"
	fi
}

dropColumn() {
	table="$1"
	column="$2"

	sql "DESCRIBE $table"|awk '{print $1}'|grep -qx "$column" > /dev/null 2>&1
	if [ $? = 0 ]; then
		echo "`date` [NOTICE : phvalheim] Dropping $table.$column"
		sql "ALTER TABLE $table DROP COLUMN $column;"
	fi
}

## BEGIN UPDATE ##
#
# 2.58
#
# Object-by-object idempotent, like every migration since 2.40: this ships as an RC first and a
# later revision of THIS script must run on a server that already ran an earlier one.

echo "`date` [NOTICE : phvalheim] Applying database schema update for phvalheim-server >=v2.58"

# --- Which mods can be updated, by name --------------------------------------------------
#
# WHY: updateChecker.py already works out WHICH unpinned mods are behind the catalogue, and
# then stored only the count. The dashboard's update badge said "1" and nothing else, and the
# Updates tab said "1 can be updated" -- the operator had to open the mod list and compare
# versions by eye to find it. One line per mod, "name installed -> available", newline
# separated; written by updateChecker.py alongside update_available_mods and read by the
# badge's hover text and the Updates tab.
addColumn worlds update_mods_list "TEXT NULL"

# A catalogue row whose version string carries the quotes the feed sent (`"3.5.8"`) can never
# equal the installed mod_versions row (3.5.8), so updateChecker flagged a phantom update on
# every check and the badge never cleared. modSync.py strips at parse time from 2.58; this
# repairs what is already stored. mods.latest_version has no uniqueness to collide with.
# mod_versions.version does (per mod), so there it is UPDATE IGNORE: a quoted row that
# collides with its clean twin is left alone rather than failing the whole migration.
sql "UPDATE mods SET latest_version=TRIM(BOTH '\"' FROM TRIM(latest_version)) WHERE latest_version LIKE '\"%' OR latest_version LIKE '%\"';"
sql "UPDATE IGNORE mod_versions SET version=TRIM(BOTH '\"' FROM TRIM(version)) WHERE version LIKE '\"%' OR version LIKE '%\"';"

# settings.playerCountsEnabled existed for two revisions of 2.58 while the count was still
# scraped from the log and wrong. The count is REAL now (the Companion's roster file, read by
# playerMonitor) and the maintainer retired the switch: nothing reads the column, so a box that
# ran either revision loses it here. Idempotent.
dropColumn settings playerCountsEnabled

# The Logs badge is per EVENT from 2.58: phv_events.acked. The 2.56 watermark in
# settings.logsSeenEventId could not express "I looked at this world's log", and its GREATEST()
# update compared the bound id as a STRING, so it froze at 669 once ids reached four digits
# (see phvEventsAck() in includes/events.php). Everything the operator had seen under the old
# rule is marked seen; everything after the stuck watermark stays unseen, which is exactly
# what the badge was showing.
addColumn phv_events acked "TINYINT NOT NULL DEFAULT 0"
sql "UPDATE phv_events SET acked=1 WHERE acked=0 AND id <= (SELECT IFNULL(MAX(logsSeenEventId),0) FROM settings);"
sql "SHOW INDEX FROM phv_events" | awk '{print $3}' | grep -qx idx_phv_events_acked || sql "CREATE INDEX idx_phv_events_acked ON phv_events (acked, level);"
