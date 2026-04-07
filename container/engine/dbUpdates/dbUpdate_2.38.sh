#!/bin/bash

# all dbUpdater scripts must be executable!

# is this update already applied?
sql "DESCRIBE settings"|grep backupIntervalMinutes > /dev/null 2>&1
if [ ! $? = 0 ]; then
	echo "`date` [NOTICE : phvalheim] Applying database schema update for phvalheim-server >=v2.38"

	## BEGIN UPDATE ##

	# --- settings table: global backup defaults ---
	sql "ALTER TABLE settings ADD COLUMN backupIntervalMinutes INT DEFAULT 30;"
	sql "ALTER TABLE settings ADD COLUMN backupRequireActivity TINYINT DEFAULT 1;"
	sql "ALTER TABLE settings ADD COLUMN backupCompression VARCHAR(10) DEFAULT 'none';"
	sql "ALTER TABLE settings ADD COLUMN backupCompressionHour INT DEFAULT 3;"
	sql "ALTER TABLE settings ADD COLUMN backupRetainAllHours INT DEFAULT 24;"
	sql "ALTER TABLE settings ADD COLUMN backupRetainDailyDays INT DEFAULT 7;"
	sql "ALTER TABLE settings ADD COLUMN backupRetainWeeklyDays INT DEFAULT 30;"
	sql "ALTER TABLE settings ADD COLUMN backupRetainMonthlyMonths INT DEFAULT 6;"

	# --- worlds table: per-world backup overrides ---
	sql "ALTER TABLE worlds ADD COLUMN backup_use_global TINYINT DEFAULT 1;"
	sql "ALTER TABLE worlds ADD COLUMN backup_interval_minutes INT DEFAULT 30;"
	sql "ALTER TABLE worlds ADD COLUMN backup_require_activity TINYINT DEFAULT 1;"
	sql "ALTER TABLE worlds ADD COLUMN backup_retain_all_hours INT DEFAULT 24;"
	sql "ALTER TABLE worlds ADD COLUMN backup_retain_daily_days INT DEFAULT 7;"
	sql "ALTER TABLE worlds ADD COLUMN backup_retain_weekly_days INT DEFAULT 30;"
	sql "ALTER TABLE worlds ADD COLUMN backup_retain_monthly_months INT DEFAULT 6;"
	sql "ALTER TABLE worlds ADD COLUMN last_player_activity DATETIME DEFAULT NULL;"
	sql "ALTER TABLE worlds ADD COLUMN last_backup_time DATETIME DEFAULT NULL;"

	# --- worlds table: per-world compression overrides ---
	sql "ALTER TABLE worlds ADD COLUMN backup_compression VARCHAR(10) DEFAULT 'none';"
	sql "ALTER TABLE worlds ADD COLUMN backup_compression_hour INT DEFAULT 3;"

	# --- settings table: backup performance tuning ---
	sql "ALTER TABLE settings ADD COLUMN backupCpuPriority INT DEFAULT 10;"
	sql "ALTER TABLE settings ADD COLUMN backupIoPriority VARCHAR(10) DEFAULT 'low';"
	sql "ALTER TABLE settings ADD COLUMN backupCompressionLevel INT DEFAULT 0;"

	# --- worlds table: per-world performance tuning ---
	sql "ALTER TABLE worlds ADD COLUMN backup_cpu_priority INT DEFAULT 10;"
	sql "ALTER TABLE worlds ADD COLUMN backup_io_priority VARCHAR(10) DEFAULT 'low';"
	sql "ALTER TABLE worlds ADD COLUMN backup_compression_level INT DEFAULT 0;"

	# --- backups table: individual backup tracking ---
	sql "CREATE TABLE backups (
		id INTEGER PRIMARY KEY NOT NULL AUTO_INCREMENT,
		world_name VARCHAR(64) NOT NULL,
		created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
		type VARCHAR(16) NOT NULL DEFAULT 'scheduled',
		file_path TEXT NOT NULL,
		file_size BIGINT DEFAULT 0,
		uncompressed_size BIGINT DEFAULT 0,
		compressed TINYINT DEFAULT 0,
		compression_type VARCHAR(10) DEFAULT 'none',
		orphaned TINYINT DEFAULT 0,
		metadata JSON,
		INDEX idx_world_created (world_name, created_at)
	);"

	if [ ! $? = 0 ]; then
		# update failed to apply
		exit 1
	fi

	# --- import existing backup files into the new backups table ---
	backupDir="/opt/stateful/backups"
	if [ -d "$backupDir" ]; then
		for backupFile in "$backupDir"/valheimworld_*.tar; do
			[ -f "$backupFile" ] || continue

			fileName=$(basename "$backupFile")
			# parse world name and timestamp from filename: valheimworld_<name>-<timestamp>.tar
			worldName=$(echo "$fileName" | sed 's/^valheimworld_//; s/-[0-9T:+-]*\.tar$//')
			timestamp=$(echo "$fileName" | sed 's/^valheimworld_[^-]*-//; s/\.tar$//' | sed 's/T/ /; s/\([0-9]\{2\}\)\([0-9]\{2\}\)$/\1:\2/')
			fileSize=$(stat --format=%s "$backupFile" 2>/dev/null || echo 0)

			# only import if the world still exists in DB
			worldExists=$(sql "SELECT COUNT(*) FROM worlds WHERE name='$worldName';")
			if [ "$worldExists" -gt 0 ] 2>/dev/null; then
				sql "INSERT INTO backups (world_name, created_at, type, file_path, file_size, compressed, compression_type)
					VALUES ('$worldName', '$timestamp', 'scheduled', '$backupFile', $fileSize, 0, 'none');"
				echo "`date` [NOTICE : phvalheim]   Imported existing backup: $fileName"
			fi
		done
		echo "`date` [NOTICE : phvalheim]   Existing backup import complete."
	fi

	## END UPDATE ##
else
	# Additive migrations for columns added after initial 2.38 release
	sql "DESCRIBE backups"|grep uncompressed_size > /dev/null 2>&1
	if [ ! $? = 0 ]; then
		echo "`date` [NOTICE : phvalheim] Applying 2.38 additive migration: uncompressed_size, per-world compression"
		sql "ALTER TABLE backups ADD COLUMN uncompressed_size BIGINT DEFAULT 0 AFTER file_size;"
		sql "ALTER TABLE worlds ADD COLUMN backup_compression VARCHAR(10) DEFAULT 'none';"
		sql "ALTER TABLE worlds ADD COLUMN backup_compression_hour INT DEFAULT 3;"
	fi

	# Performance tuning columns
	sql "DESCRIBE settings"|grep backupCpuPriority > /dev/null 2>&1
	if [ ! $? = 0 ]; then
		echo "`date` [NOTICE : phvalheim] Applying 2.38 additive migration: backup performance tuning"
		sql "ALTER TABLE settings ADD COLUMN backupCpuPriority INT DEFAULT 10;"
		sql "ALTER TABLE settings ADD COLUMN backupIoPriority VARCHAR(10) DEFAULT 'low';"
		sql "ALTER TABLE settings ADD COLUMN backupCompressionLevel INT DEFAULT 0;"
		sql "ALTER TABLE worlds ADD COLUMN backup_cpu_priority INT DEFAULT 10;"
		sql "ALTER TABLE worlds ADD COLUMN backup_io_priority VARCHAR(10) DEFAULT 'low';"
		sql "ALTER TABLE worlds ADD COLUMN backup_compression_level INT DEFAULT 0;"
	fi

	# Orphaned backup tracking
	sql "DESCRIBE backups"|grep orphaned > /dev/null 2>&1
	if [ ! $? = 0 ]; then
		echo "`date` [NOTICE : phvalheim] Applying 2.38 additive migration: backup orphan tracking"
		sql "ALTER TABLE backups ADD COLUMN orphaned TINYINT DEFAULT 0;"
	fi

	exit 2
fi
