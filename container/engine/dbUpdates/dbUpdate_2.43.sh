#!/bin/bash

source /opt/stateless/engine/includes/phvalheim-static.conf

## BEGIN UPDATE ##
#
# 2.43: the multi-source mod database.
#
# Like dbUpdate_2.40.sh this script is deliberately object-by-object idempotent rather
# than sitting behind one top-level "already applied?" guard. 2.43 ships as an RC first,
# so later revisions of THIS script will run on servers that already ran an earlier
# revision; a single guard would skip those additions forever on exactly the machines
# doing the testing.

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

echo "`date` [NOTICE : phvalheim] Applying database schema update for phvalheim-server >=v2.43"


# --- the unified mod catalogue -------------------------------------------------------
#
# IDENTITY IS (source, owner, name) -- NOT the source's uuid.
#
# Hexium mirrors Thunderstore packages carrying their ORIGINAL uuid4: 600 package uuids
# appear in both catalogues, all 600 with the same owner/name. Everything before 2.43
# keyed a world's mod selection on a bare `moduuid`, which would have silently conflated
# those 600 the moment a second source existed. source_uuid is kept as source metadata
# and is deliberately NOT unique.
#
# owner/name/version are COLLATE utf8mb4_0900_as_cs -- CASE SENSITIVE -- and that is
# load-bearing, not tidiness. MySQL's default utf8mb4_0900_ai_ci treats
# IronTeam/Iron_ModPack and IronTeam/Iron_Modpack as the SAME row; they are different
# published mods. 22 such groups exist on Thunderstore today
# (Janoobalance/JanooBalance, AlbusWorld_ModPack/AlbusWorld_Modpack, ...). Under ai_ci
# they collapse into one row AND overwrite each other on every sync, silently.
#
# The oracle: a full sync of both catalogues must land exactly 11,672 mods and 91,701
# mod_versions. Under ai_ci it lands 11,649 / 91,668 and reports success.
#
# The staging tables the loader creates must use the same collation, or the merge JOIN
# fails with "Illegal mix of collations".
if ! tableExists mods; then
	echo "`date` [NOTICE : phvalheim] Creating table 'mods'"
	sql "CREATE TABLE mods (
		id             INT UNSIGNED NOT NULL AUTO_INCREMENT,
		source         VARCHAR(16)  NOT NULL,
		owner          VARCHAR(80)  COLLATE utf8mb4_0900_as_cs NOT NULL,
		name           VARCHAR(96)  COLLATE utf8mb4_0900_as_cs NOT NULL,
		full_name      VARCHAR(180) NOT NULL,
		source_uuid    CHAR(36)         NULL,
		package_url    VARCHAR(255)     NULL,
		donation_link  VARCHAR(255)     NULL,
		date_created   DATETIME         NULL,
		date_updated   DATETIME         NULL,
		rating_score   INT          NOT NULL DEFAULT 0,
		downloads      BIGINT       NOT NULL DEFAULT 0,
		is_deprecated  TINYINT      NOT NULL DEFAULT 0,
		is_nsfw        TINYINT      NOT NULL DEFAULT 0,
		is_pinned      TINYINT      NOT NULL DEFAULT 0,
		categories     JSON             NULL,
		version_count  INT          NOT NULL DEFAULT 0,
		latest_version VARCHAR(32)      NULL,
		first_seen     DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
		last_seen      DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
		PRIMARY KEY (id),
		UNIQUE KEY uk_mod (source, owner, name),
		KEY idx_name (name),
		KEY idx_full (full_name),
		KEY idx_source (source),
		KEY idx_seen (last_seen)
	) ENGINE=InnoDB;"
fi

# One row per published version. download_url is stored rather than templated: Hexium
# serves from cdn.hexium.gg with an opaque numeric path (cdn.hexium.gg/upload/1036/1.4.9.zip),
# so it CANNOT be reconstructed the way the old Thunderstore-only path built
# "$tsModDownloadUrl/$owner/$name/$version". Storing the source's own URL is what makes a
# second source possible at all.
#
# source_rank is the version's index in the source's own versions[] array (0 = newest).
# The feeds are ordered newest-first and that ordering is more trustworthy than parsing
# version strings -- prerelease versions like 2.0.6-beta.1 do not sort as semver.
if ! tableExists mod_versions; then
	echo "`date` [NOTICE : phvalheim] Creating table 'mod_versions'"
	sql "CREATE TABLE mod_versions (
		id           INT UNSIGNED NOT NULL AUTO_INCREMENT,
		mod_id       INT UNSIGNED NOT NULL,
		version      VARCHAR(32)  COLLATE utf8mb4_0900_as_cs NOT NULL,
		full_name    VARCHAR(220) NOT NULL,
		source_uuid  CHAR(36)         NULL,
		download_url VARCHAR(255) NOT NULL,
		icon_url     VARCHAR(255)     NULL,
		website_url  VARCHAR(400)     NULL,
		description  VARCHAR(400)     NULL,
		file_size    BIGINT       NOT NULL DEFAULT 0,
		downloads    BIGINT       NOT NULL DEFAULT 0,
		is_active    TINYINT      NOT NULL DEFAULT 1,
		source_rank  INT          NOT NULL DEFAULT 0,
		date_created DATETIME         NULL,
		deps         JSON             NULL,
		last_seen    DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
		PRIMARY KEY (id),
		UNIQUE KEY uk_ver (mod_id, version),
		KEY idx_rank (mod_id, source_rank),
		KEY idx_seen (last_seen)
	) ENGINE=InnoDB;"
fi

# Resolved dependency edges for the versions a world can actually select (latest of each
# mod, plus any pinned version). Resolution happens once at sync time because it cannot
# be done by string splitting at lookup time:
#
#   A dep string is "owner-name-version" and ALL THREE parts may contain hyphens --
#   owners LVH-IT and sinai-dev, versions like 2.0.6-beta.1. Splitting on the last
#   hyphen misresolves; longest-prefix match against known owner-name keys resolves
#   1371 of Hexium's 1372 distinct dep strings.
#
# dep_mod_id may point at a DIFFERENT source than the depending mod: of those 1372
# strings, 576 name a package that exists only on Thunderstore. A Hexium world still
# needs Thunderstore rows present to resolve its own graph, which is why there is one
# unified catalogue instead of two parallel ones.
if ! tableExists mod_deps; then
	echo "`date` [NOTICE : phvalheim] Creating table 'mod_deps'"
	sql "CREATE TABLE mod_deps (
		version_id     INT UNSIGNED NOT NULL,
		dep_string     VARCHAR(220) NOT NULL,
		dep_mod_id     INT UNSIGNED     NULL,
		dep_version    VARCHAR(32)      NULL,
		dep_version_id INT UNSIGNED     NULL,
		PRIMARY KEY (version_id, dep_string),
		KEY idx_depmod (dep_mod_id),
		KEY idx_depver (dep_version_id)
	) ENGINE=InnoDB;"
fi

# A world's selection. Replaces worlds.thunderstore_mods / thunderstore_mods_deps, which
# were space-separated uuid strings and can express NEITHER a source NOR a pinned
# version -- the two things 2.43 exists to add.
#
# pin_version_id NULL means "follow whatever is latest", which is the pre-2.43 behaviour
# and stays the default.
if ! tableExists world_mods; then
	echo "`date` [NOTICE : phvalheim] Creating table 'world_mods'"
	sql "CREATE TABLE world_mods (
		world_id       INT UNSIGNED NOT NULL,
		mod_id         INT UNSIGNED NOT NULL,
		pin_version_id INT UNSIGNED     NULL,
		is_dep         TINYINT      NOT NULL DEFAULT 0,
		date_added     DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
		PRIMARY KEY (world_id, mod_id),
		KEY idx_mod (mod_id),
		KEY idx_world_dep (world_id, is_dep)
	) ENGINE=InnoDB;"
fi

# Per-run sync bookkeeping. Feeds the reactive progress panel and the
# previous-run-vs-current-run comparison.
#
# body_sha256 is the change detector for a source that sends no validator: Hexium
# returns neither ETag nor Last-Modified, so the only way to know its 2.3 MB catalogue is
# unchanged is to hash the body and compare against the last successful run.
# Thunderstore DOES send Last-Modified, so it gets a conditional If-Modified-Since and
# usually answers 304 without sending a body at all.
if ! tableExists mod_sync_runs; then
	echo "`date` [NOTICE : phvalheim] Creating table 'mod_sync_runs'"
	sql "CREATE TABLE mod_sync_runs (
		id              INT UNSIGNED NOT NULL AUTO_INCREMENT,
		source          VARCHAR(16)  NOT NULL,
		status          VARCHAR(16)  NOT NULL DEFAULT 'running',
		phase           VARCHAR(24)  NOT NULL DEFAULT 'starting',
		phase_pct       TINYINT      NOT NULL DEFAULT 0,
		trigger_kind    VARCHAR(16)  NOT NULL DEFAULT 'cron',
		started         DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
		finished        DATETIME         NULL,
		duration_ms     INT              NULL,
		http_status     INT              NULL,
		bytes_fetched   BIGINT       NOT NULL DEFAULT 0,
		body_sha256     CHAR(64)         NULL,
		http_last_mod   VARCHAR(64)      NULL,
		pkgs_seen       INT          NOT NULL DEFAULT 0,
		vers_seen       INT          NOT NULL DEFAULT 0,
		mods_added      INT          NOT NULL DEFAULT 0,
		mods_updated    INT          NOT NULL DEFAULT 0,
		mods_removed    INT          NOT NULL DEFAULT 0,
		vers_added      INT          NOT NULL DEFAULT 0,
		vers_updated    INT          NOT NULL DEFAULT 0,
		vers_removed    INT          NOT NULL DEFAULT 0,
		deps_resolved   INT          NOT NULL DEFAULT 0,
		deps_unresolved INT          NOT NULL DEFAULT 0,
		pid             INT              NULL,
		error           TEXT             NULL,
		PRIMARY KEY (id),
		KEY idx_src (source, started),
		KEY idx_status (status)
	) ENGINE=InnoDB;"
fi


# --- per-run sync log, so each catalogue can show its own live detail ----------------
#
# The engine already writes /opt/stateful/logs/modSync.log, but that is ONE file with both
# catalogues interleaved: to answer "what did Hexium actually do just now" you have to read
# past whatever Thunderstore was doing at the same time. These rows are keyed to a run, so
# the admin UI can stream one catalogue's detail on its own and show what changed rather
# than just a phase name and a spinner.
#
# DATETIME(3): a fast sync finishes in ~2s, so whole-second stamps would collapse most of
# the run into one timestamp and lose the ordering the log exists to show.
#
# message is VARCHAR(500), not TEXT: these are single log lines, and a bounded column keeps
# the table small enough to prune by run rather than by size.
if ! tableExists mod_sync_log; then
	echo "`date` [NOTICE : phvalheim] Creating table 'mod_sync_log'"
	sql "CREATE TABLE mod_sync_log (
		id      BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
		run_id  INT UNSIGNED NOT NULL,
		source  VARCHAR(16)  NOT NULL,
		level   VARCHAR(8)   NOT NULL DEFAULT 'info',
		phase   VARCHAR(24)      NULL,
		message VARCHAR(500) NOT NULL,
		is_detail TINYINT    NOT NULL DEFAULT 0,
		created DATETIME(3)  NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
		PRIMARY KEY (id),
		KEY idx_run (run_id, id),
		KEY idx_source (source, id)
	) ENGINE=InnoDB;"
fi

# Separate addColumn so a server already running an earlier 2.43 RC revision, which created
# mod_sync_log without it, picks it up. The table guard above would skip that server forever.
addColumn mod_sync_log is_detail "TINYINT NOT NULL DEFAULT 0"

# Per-phase timings, so the log can show where a slow sync actually spent its time instead
# of leaving the operator to subtract timestamps by hand.
addColumn mod_sync_runs phase_timings "JSON DEFAULT NULL"


# --- content_hash: what makes an incremental sync incremental ------------------------
#
# md5 of the row's significant fields. The sync reads these in one query and writes ONLY
# rows that are new or genuinely different.
#
# Without it the only way to "sync" is to re-upsert every row, which for Thunderstore's
# 89,405 versions costs ~55s of pure UPDATE work even when the catalogue has not changed
# at all. With it, a real incremental sync touches the handful of rows that moved.
#
# Download counts and rating score are deliberately NOT part of the hash -- they tick
# constantly and would make every mod look changed on every run, which would defeat the
# whole mechanism. They are still written whenever a row is written for another reason.
#
# Added as a separate column rather than in the CREATE TABLE above so a server already
# running an earlier 2.43 RC revision picks it up.
addColumn mods         content_hash "CHAR(32) DEFAULT NULL"
addColumn mod_versions content_hash "CHAR(32) DEFAULT NULL"


# --- settings: catalogue sources and their (optional) API keys -----------------------
#
# NEITHER source needs a credential to read its catalogue. Both
# thunderstore.io/c/valheim/api/v1/package/ and valheim.hexium.gg/api/v1/package/ are
# public and unauthenticated -- verified 2026-09-12. These fields exist so an operator
# CAN supply a key if a source starts requiring or rate-limiting one, and the sync sends
# it as a bearer token when present. Leaving them empty is the supported default, and the
# Settings UI says so rather than presenting them as required setup.
addColumn settings thunderstoreApiKey "VARCHAR(255) DEFAULT ''"
addColumn settings hexiumApiKey       "VARCHAR(255) DEFAULT ''"

# Which catalogues the server syncs at all. A source that is off is not fetched and its
# mods do not appear in the picker.
addColumn settings thunderstoreEnabled "TINYINT DEFAULT 1"
addColumn settings hexiumEnabled       "TINYINT DEFAULT 1"

# Hours between automatic catalogue syncs. The old schedule was hardcoded at every 12h
# in cron.d; with change detection a sync of an unchanged catalogue costs one HTTP round
# trip, so a shorter interval is now cheap.
addColumn settings modSyncIntervalHours "INT DEFAULT 6"

# Per-world source selection: a comma-separated list of enabled source names. Empty means
# "every source the server has enabled", which is what every pre-2.43 world wants.
addColumn worlds mod_sources "VARCHAR(64) DEFAULT ''"


# --- migrate tsmods -> mods / mod_versions -------------------------------------------
#
# tsmods is left in place and untouched. It is the rollback path, and nothing in 2.43
# writes to it.
#
# Before 2.43 every selection was Thunderstore by definition, so source='thunderstore'
# for the whole migration with no ambiguity.
#
# tsmods holds only the NEWEST version of each package (the old sync did `head -1` on the
# versions array), so this recovers exactly one version per mod. The first run of the new
# sync fills in the full history -- 89,405 Thunderstore versions against tsmods' ~11k --
# and corrects anything below.
#
# tsmods.url is the package PAGE url, not a download url, so download_url is rebuilt from
# the canonical Thunderstore template here. That keeps worlds startable in the window
# between this migration and the first sync; the sync then overwrites it with the
# source's own url.
#
# EVERY comparison between a tsmods column and a mods column needs an explicit COLLATE.
# tsmods is utf8mb4_0900_ai_ci (it predates this change) and mods.owner/name are
# utf8mb4_0900_as_cs, so a bare `m.owner = t.owner` fails outright with
# "Illegal mix of collations (utf8mb4_0900_as_cs,IMPLICIT) and (utf8mb4_0900_ai_ci,IMPLICIT)".
# INSERT ... SELECT across the two is fine -- it is only comparison that errors -- which
# is exactly why the first cut of this migration inserted 11k mods and zero versions.
#
# The two steps are guarded SEPARATELY. A single shared guard meant a failure in the
# version step left the mods step "done", and every re-run skipped both.
if tableExists tsmods; then
	tsCount=$(sql "SELECT COUNT(*) FROM tsmods")
	case "$tsCount" in
		''|*[!0-9]*) tsCount=0 ;;
	esac

	migratedMods=$(sql "SELECT COUNT(*) FROM mods WHERE source='thunderstore'")
	case "$migratedMods" in
		''|*[!0-9]*) migratedMods=0 ;;
	esac

	if [ "$tsCount" -gt 0 ] && [ "$migratedMods" -eq 0 ]; then
			echo "`date` [NOTICE : phvalheim] Migrating $tsCount tsmods rows into the unified catalogue..."

			# GROUP BY owner,name because tsmods has no unique key at all and can hold
			# duplicate rows for the same package; MAX(id) takes the most recently
			# written one. Without this the INSERT would fail on uk_mod.
			sql "INSERT INTO mods
				(source, owner, name, full_name, source_uuid, package_url,
				 date_created, date_updated, version_count, latest_version,
				 first_seen, last_seen)
			     SELECT 'thunderstore',
			            t.owner,
			            t.name,
			            CONCAT(t.owner, '-', t.name),
			            NULLIF(t.moduuid, ''),
			            NULLIF(t.url, ''),
			            t.created,
			            t.updated,
			            1,
			            NULLIF(t.version, ''),
			            NOW(),
			            NOW()
			     FROM tsmods t
			     JOIN (SELECT MAX(id) AS id FROM tsmods
			           WHERE owner IS NOT NULL AND owner <> ''
			             AND name  IS NOT NULL AND name  <> ''
			           GROUP BY owner, name) pick ON pick.id = t.id
			     ON DUPLICATE KEY UPDATE last_seen = NOW();"

			movedMods=$(sql "SELECT COUNT(*) FROM mods WHERE source='thunderstore'")
			echo "`date` [NOTICE : phvalheim] Migrated $movedMods mods from tsmods."
	fi

	migratedVers=$(sql "SELECT COUNT(*) FROM mod_versions")
	case "$migratedVers" in
		''|*[!0-9]*) migratedVers=0 ;;
	esac

	if [ "$tsCount" -gt 0 ] && [ "$migratedVers" -eq 0 ]; then
			# The old catalogue stored the version string with literal quotes around it
			# in some rows (jq -r was not used consistently), so strip them rather than
			# build a download url containing a quote character.
			sql "INSERT INTO mod_versions
				(mod_id, version, full_name, source_uuid, download_url,
				 description, source_rank, date_created, deps, last_seen)
			     SELECT m.id,
			            REPLACE(t.version, '\"', ''),
			            CONCAT(t.owner, '-', t.name, '-', REPLACE(t.version, '\"', '')),
			            NULLIF(t.versionuuid, ''),
			            CONCAT('${tsModDownloadUrl}/', t.owner, '/', t.name, '/',
			                   REPLACE(t.version, '\"', '')),
			            NULL,
			            0,
			            t.version_date_created,
			            NULLIF(t.deps, ''),
			            NOW()
			     FROM tsmods t
			     JOIN (SELECT MAX(id) AS id FROM tsmods
			           WHERE owner IS NOT NULL AND owner <> ''
			             AND name  IS NOT NULL AND name  <> ''
			             AND version IS NOT NULL AND version <> ''
			           GROUP BY owner, name) pick ON pick.id = t.id
			     JOIN mods m ON m.source = 'thunderstore'
			                AND m.owner  = t.owner COLLATE utf8mb4_0900_as_cs
			                AND m.name   = t.name  COLLATE utf8mb4_0900_as_cs
			     ON DUPLICATE KEY UPDATE last_seen = NOW();"

			movedVers=$(sql "SELECT COUNT(*) FROM mod_versions")
			echo "`date` [NOTICE : phvalheim] Migrated $movedVers versions from tsmods."
	fi
fi


# --- migrate worlds.thunderstore_mods -> world_mods ----------------------------------
#
# The legacy columns are space-separated tsmods.moduuid values. They are resolved through
# tsmods to (owner,name) and then to a mods.id, which is unambiguous because every
# pre-2.43 selection was Thunderstore.
#
# The legacy columns are NOT cleared. They stay as a rollback record, and 2.43 stops
# reading them.
#
# Guarded on world_mods being empty for that world rather than a global flag, so a world
# created by an earlier 2.43 RC revision is not re-migrated over the operator's later
# edits.
if tableExists world_mods && tableExists tsmods; then
	migratedWorlds=0
	worldIds=$(sql "SELECT id FROM worlds WHERE (thunderstore_mods IS NOT NULL AND thunderstore_mods <> '')
	                                         OR (thunderstore_mods_deps IS NOT NULL AND thunderstore_mods_deps <> '')")

	for worldId in $worldIds; do
		existing=$(sql "SELECT COUNT(*) FROM world_mods WHERE world_id=$worldId")
		case "$existing" in
			''|*[!0-9]*) existing=0 ;;
		esac
		[ "$existing" -gt 0 ] && continue

		worldName=$(sql "SELECT name FROM worlds WHERE id=$worldId")
		selected=$(sql "SELECT thunderstore_mods FROM worlds WHERE id=$worldId")
		deps=$(sql "SELECT thunderstore_mods_deps FROM worlds WHERE id=$worldId")

		inserted=0
		# is_dep=0 first, then dependencies -- and the dependency insert must NOT
		# downgrade a mod the operator picked explicitly, hence IGNORE on the second
		# pass. A mod that is both chosen and a dependency of something else is a
		# CHOSEN mod; showing it as a dependency would let a cascade remove it.
		for legacyUuid in $selected; do
			case "$legacyUuid" in
				''|placeholder|NULL) continue ;;
			esac
			sql "INSERT IGNORE INTO world_mods (world_id, mod_id, is_dep)
			     SELECT $worldId, m.id, 0
			     FROM tsmods t
			     JOIN mods m ON m.source='thunderstore'
			                AND m.owner = t.owner COLLATE utf8mb4_0900_as_cs
			                AND m.name  = t.name  COLLATE utf8mb4_0900_as_cs
			     WHERE t.moduuid = '$legacyUuid'
			     LIMIT 1;"
			inserted=$((inserted+1))
		done

		for legacyUuid in $deps; do
			case "$legacyUuid" in
				''|placeholder|NULL) continue ;;
			esac
			sql "INSERT IGNORE INTO world_mods (world_id, mod_id, is_dep)
			     SELECT $worldId, m.id, 1
			     FROM tsmods t
			     JOIN mods m ON m.source='thunderstore'
			                AND m.owner = t.owner COLLATE utf8mb4_0900_as_cs
			                AND m.name  = t.name  COLLATE utf8mb4_0900_as_cs
			     WHERE t.moduuid = '$legacyUuid'
			     LIMIT 1;"
		done

		landed=$(sql "SELECT COUNT(*) FROM world_mods WHERE world_id=$worldId")
		case "$landed" in
			''|*[!0-9]*) landed=0 ;;
		esac

		# A world that HAD selections and ends up with none means every uuid failed to
		# resolve -- a mod delisted from Thunderstore, or a tsmods row pruned out from
		# under the selection. Silence here is how a world reaches its first start with
		# no plugins, so say it loudly.
		if [ "$landed" -eq 0 ]; then
			echo "`date` [WARN : phvalheim] World '$worldName' (id $worldId) had mod selections but NONE resolved into the new catalogue. Its mods must be re-picked in the admin UI."
		else
			echo "`date` [NOTICE : phvalheim] World '$worldName': migrated $landed mod selection(s)."
			migratedWorlds=$((migratedWorlds+1))
		fi
	done

	[ "$migratedWorlds" -gt 0 ] && echo "`date` [NOTICE : phvalheim] Migrated mod selections for $migratedWorlds world(s)."
fi

## END UPDATE ##

exit 0
