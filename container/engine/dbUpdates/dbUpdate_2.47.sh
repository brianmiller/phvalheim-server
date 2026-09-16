#!/bin/bash

source /opt/stateless/engine/includes/phvalheim-static.conf

## BEGIN UPDATE ##
#
# 2.47: player counts, and automatic game + mod updates (issue #87).
#
# Object-by-object idempotent rather than one top-level guard, for the same reason as 2.40,
# 2.43 and 2.45: this ships as an RC first, and later revisions of THIS script must run on
# servers that already ran an earlier revision.

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

echo "`date` [NOTICE : phvalheim] Applying database schema update for phvalheim-server >=v2.47"


# --- worlds: player counts -----------------------------------------------------------
#
# Valheim exposes no reliable live player count, so this is a best-effort number parsed out
# of each world's log. See docs/RELEASE-2.47-DESIGN.md for what was verified against real
# players and what was ruled out.
#
# player_count_at is NOT decoration. A nonzero count can stick in the log after everyone has
# left -- verified on production 2026-09-15, where a world logged "now 1 player(s)" and then
# nothing for 39 minutes while empty. Every reader must treat a nonzero count older than the
# idle threshold as idle. Without the timestamp there is no way to tell the two apart, and
# auto-update would wait forever on a world nobody is playing.
#
# player_count_source records WHICH signal produced the number, because the three sources
# have different accuracy and different lag. When a count looks wrong this is the first
# thing to look at.
addColumn worlds player_count "INT DEFAULT 0"
addColumn worlds player_count_at "DATETIME DEFAULT NULL"
addColumn worlds player_count_source "VARCHAR(16) DEFAULT 'none'"

# Exposing player counts on the PUBLIC UI is per-world opt-in, and defaults to off. The
# admin UI always shows them.
addColumn worlds show_players_public "TINYINT DEFAULT 0"


# --- worlds: auto-update overrides ----------------------------------------------------
#
# Deliberately mirrors the backup_* override block. autoupdate_use_global=1 means this world
# takes every global value from `settings`; 0 means the columns below win. A global "on for
# all worlds" does NOT override a world that has explicitly opted out through its own
# override block -- same semantics as backup_use_global, which is what operators already
# understand.
addColumn worlds autoupdate_use_global "TINYINT DEFAULT 1"
addColumn worlds autoupdate_mode "TINYINT DEFAULT 0"
addColumn worlds autoupdate_scope "VARCHAR(8) DEFAULT 'both'"
addColumn worlds autoupdate_idle_minutes "INT DEFAULT 30"
addColumn worlds autoupdate_max_wait_hours "INT DEFAULT 24"
addColumn worlds autoupdate_on_timeout "VARCHAR(8) DEFAULT 'wait'"
addColumn worlds autoupdate_backup_first "TINYINT DEFAULT 1"

# -1 means "any time of day". A window is start hour + length in hours, local server time.
addColumn worlds autoupdate_window_start "INT DEFAULT -1"
addColumn worlds autoupdate_window_hours "INT DEFAULT 0"


# --- worlds: auto-update state --------------------------------------------------------
#
# update_available_* is DERIVED state, refreshed by updateChecker. It is stored rather than
# recomputed on every page load because the game check costs a steamcmd call.
#
# installed_buildid is read from the world's own appmanifest_896660.acf. Each world has its
# own steamcmd tree, so "is this world up to date" is a per-world question even though the
# available buildid is fetched once for the whole server.
addColumn worlds update_available_game "TINYINT DEFAULT 0"
addColumn worlds update_available_mods "INT DEFAULT 0"
addColumn worlds update_checked_at "DATETIME DEFAULT NULL"
addColumn worlds installed_buildid "VARCHAR(32) DEFAULT NULL"

# update_pending_since starts the max-wait clock. It is set when an update is first seen and
# cleared when the update is applied or the update goes away.
addColumn worlds update_pending_since "DATETIME DEFAULT NULL"
addColumn worlds update_state "VARCHAR(16) DEFAULT 'idle'"
addColumn worlds update_last_result "TEXT DEFAULT NULL"


# --- settings: auto-update globals ----------------------------------------------------
#
# camelCase here and snake_case on `worlds`, matching the existing split between the two
# tables. Defaults are deliberately conservative: mode 0 (off) means upgrading to 2.47
# changes NOTHING about how anyone's worlds behave until they turn it on.
addColumn settings autoUpdateMode "TINYINT DEFAULT 0"
addColumn settings autoUpdateScope "VARCHAR(8) DEFAULT 'both'"
addColumn settings autoUpdateCheckIntervalHours "INT DEFAULT 6"

# 30 minutes, not 5. A PlayFab reconnect wobble produced three join/lost pairs inside two
# minutes on production; a short threshold would call that world idle mid-session.
addColumn settings autoUpdateIdleMinutes "INT DEFAULT 30"

addColumn settings autoUpdateMaxWaitHours "INT DEFAULT 24"

# 'wait' means a world that never empties is never updated. Booting players off a server
# they are playing on is the worse failure, so it is opt-in.
addColumn settings autoUpdateOnTimeout "VARCHAR(8) DEFAULT 'wait'"

addColumn settings autoUpdateBackupFirst "TINYINT DEFAULT 1"
addColumn settings autoUpdateWindowStart "INT DEFAULT -1"
addColumn settings autoUpdateWindowHours "INT DEFAULT 0"


# --- update check errors ---------------------------------------------------------------
#
# A check that could not run is NOT "up to date", and conflating the two is how a world sat
# on build 20460518 while the UI reported it current. updateChecker could not reach steamcmd
# (it runs as the phvalheim user, whose HOME was not set, so steamcmd tried to write to
# /opt/.local and died), returned no published build, and the "unknown means claim nothing"
# branch left update_available_game at 0 -- which the UI rendered as a green "up to date".
#
# Storing the reason separately means the UI can say "could not check" and show why, instead
# of silently reporting the most reassuring possible answer.
addColumn worlds update_check_error "TEXT DEFAULT NULL"


# --- update phase ----------------------------------------------------------------------
#
# update_state says WHETHER an update is running; update_phase says WHICH PART is running.
# Without it the UI showed "updating" for several minutes while the thing actually happening
# was a backup, so the operator had no way to tell a slow download from a stuck job.
#
# Phases: backup | stopping | game | mods | starting | done
addColumn worlds update_phase "VARCHAR(16) DEFAULT NULL"
addColumn worlds update_phase_at "DATETIME DEFAULT NULL"


# --- published build cache --------------------------------------------------------------
#
# The published Valheim buildid is a property of the SERVER, not of a world: every world
# compares against the same number. Fetching it costs ~31 seconds, essentially all of it
# steamcmd starting up and logging in anonymously -- measured, and `+app_info_update 1`
# accounts for none of the difference.
#
# Without a cache, checking five worlds cost five logins, and clicking Check Now twice cost
# two. Cached here with its age so a check can reuse a recent answer and finish instantly,
# while a stale one still goes and asks Steam.
addColumn settings publishedBuildid "VARCHAR(32) DEFAULT NULL"
addColumn settings publishedBuildidAt "DATETIME DEFAULT NULL"


# --- check in progress -------------------------------------------------------------------
#
# Check Now is asynchronous because a cold check takes half a minute. This is what the UI
# watches to know the difference between "still working" and "finished".
addColumn worlds update_check_state "VARCHAR(16) DEFAULT NULL"


# --- mod check errors ---------------------------------------------------------------------
#
# Separate from update_check_error because the game row and the mods row can fail for
# completely different reasons, and a single column would make one overwrite the other.
#
# The reason this exists: worlds.modsViewer only started carrying per-mod versions in 2.43.
# A world not rebuilt since then has a snapshot of {name,url,uuid} with no version at all,
# so there is nothing to compare and the honest answer is "unknown". Measured on a real
# server: 28 of 35 worlds were in that state, every one of them reporting "up to date".
addColumn worlds update_mods_error "TEXT DEFAULT NULL"


echo "`date` [NOTICE : phvalheim] Database schema update for phvalheim-server >=v2.47 complete"

## END UPDATE ##
