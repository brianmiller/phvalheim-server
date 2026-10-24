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

tableExists() { sql "SHOW TABLES LIKE '$1'" | grep -qx "$1"; }

## BEGIN UPDATE ##
#
# 2.59 -- Hugin's Vibes (docs/HUGINS-VIBES-DESIGN.md).
#
# Object-by-object idempotent, like every migration since 2.40: this ships as an RC first and a
# later revision of THIS script must run on a server that already ran an earlier one.

echo "`date` [NOTICE : phvalheim] Applying database schema update for phvalheim-server >=v2.59"

# --- settings: four columns, not thirty ---------------------------------------------------
#
# Vibes has many knobs and they are read by Vibes alone, so they live in ONE JSON document
# (vibesSettings) with a schema documented in the design, section 4.1, and merged over defaults
# by vibesSettingsGet() in includes/vibes.php -- a newer server never trips on an older document.
#
# vibesInstalled is the master switch: nothing in Vibes runs, and the 'vibes' catalogue source
# is not offered, unless it is 1. It is written by the runner at the end of a GREEN install (a
# smoke build passed) and cleared by uninstall. vibesBuildpakVersion is the extracted pak's
# manifest version. vibesNoticeShown gates the first-launch modal exactly as the other one-shot
# notices (huginNoticeShown et al.) do.
addColumn settings vibesInstalled       "TINYINT NOT NULL DEFAULT 0"
addColumn settings vibesBuildpakVersion "VARCHAR(32) NULL"
addColumn settings vibesSettings        "JSON NULL"
addColumn settings vibesNoticeShown     "TINYINT NOT NULL DEFAULT 0"

# --- hv_projects: one row per Vibe (a mod being worked on) --------------------------------
#
# namespace/name are the catalogue identity the project publishes under (mods.owner / mods.name),
# so they carry the catalogue's case-sensitive collation: 'MyMod' and 'mymod' are two mods there
# and must be two projects here, or publishing the second would overwrite the first's rows.
if ! tableExists hv_projects; then
	echo "`date` [NOTICE : phvalheim] Creating table 'hv_projects'"
	sql "CREATE TABLE hv_projects (
		id                INT UNSIGNED NOT NULL AUTO_INCREMENT,
		slug              VARCHAR(64)  NOT NULL,
		name              VARCHAR(96)  COLLATE utf8mb4_0900_as_cs NOT NULL,
		namespace         VARCHAR(80)  COLLATE utf8mb4_0900_as_cs NOT NULL,
		description       VARCHAR(250) NOT NULL DEFAULT '',
		version           VARCHAR(32)  NOT NULL DEFAULT '0.1.0',
		template          VARCHAR(32)  NOT NULL DEFAULT 'basic-plugin',
		status            VARCHAR(16)  NOT NULL DEFAULT 'draft',
		provider_id       INT UNSIGNED     NULL,
		model             VARCHAR(190) NOT NULL DEFAULT '',
		last_build_ok     TINYINT          NULL,
		last_build_at     DATETIME         NULL,
		published_version VARCHAR(32)      NULL,
		github_remote     VARCHAR(255) NOT NULL DEFAULT '',
		gitlab_remote     VARCHAR(255) NOT NULL DEFAULT '',
		created           DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
		updated           DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
		PRIMARY KEY (id),
		UNIQUE KEY uk_slug (slug),
		UNIQUE KEY uk_ident (namespace, name)
	) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;"
fi

# --- hv_sessions: a remembered conversation about one project ------------------------------
#
# The transcript itself is a JSONL file beside the project (.vibes/sessions/<id>.jsonl); this row
# is the index and the compaction note (summary), so switching between projects restores the
# conversation without the DB holding every token.
if ! tableExists hv_sessions; then
	echo "`date` [NOTICE : phvalheim] Creating table 'hv_sessions'"
	sql "CREATE TABLE hv_sessions (
		id          INT UNSIGNED NOT NULL AUTO_INCREMENT,
		project_id  INT UNSIGNED NOT NULL,
		title       VARCHAR(120) NOT NULL DEFAULT '',
		status      VARCHAR(16)  NOT NULL DEFAULT 'idle',
		turns       INT          NOT NULL DEFAULT 0,
		summary     MEDIUMTEXT       NULL,
		tokens_in   BIGINT       NOT NULL DEFAULT 0,
		tokens_out  BIGINT       NOT NULL DEFAULT 0,
		started     DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
		updated     DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
		PRIMARY KEY (id),
		KEY idx_project (project_id, updated)
	) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;"
fi

# --- hv_jobs: every long-running thing Vibes does -----------------------------------------
#
# A job is a row first and a detached process second: vibesAPI.php inserts the row and launches
# `vibes.php job <id>`; the runner claims it (status running, pid), reports step/progress here
# and streams its events to logs/jobs/<id>.jsonl. The UI learns everything from the row and the
# file, never from the process, so a page reload mid-install loses nothing.
if ! tableExists hv_jobs; then
	echo "`date` [NOTICE : phvalheim] Creating table 'hv_jobs'"
	sql "CREATE TABLE hv_jobs (
		id          INT UNSIGNED NOT NULL AUTO_INCREMENT,
		kind        VARCHAR(24)  NOT NULL,
		project_id  INT UNSIGNED     NULL,
		session_id  INT UNSIGNED     NULL,
		status      VARCHAR(16)  NOT NULL DEFAULT 'queued',
		step        VARCHAR(64)  NOT NULL DEFAULT '',
		progress    TINYINT      NOT NULL DEFAULT 0,
		pid         INT              NULL,
		args        JSON             NULL,
		result      JSON             NULL,
		error       VARCHAR(500)     NULL,
		started     DATETIME         NULL,
		finished    DATETIME         NULL,
		created     DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
		PRIMARY KEY (id),
		KEY idx_open (status, id),
		KEY idx_project (project_id, id)
	) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;"
fi

# A job left running by the previous engine process can never finish; say so, as phv_operations
# does for engine commands. The runner checks its own pid on claim, so a stale row is harmless.
sql "UPDATE hv_jobs SET status='failed', finished=NOW(), error='PhValheim restarted before this finished' WHERE status IN ('queued','running');" > /dev/null 2>&1

## END UPDATE ##
