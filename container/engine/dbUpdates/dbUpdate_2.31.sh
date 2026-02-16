#!/bin/bash

# all dbUpdater scripts must be executable!

# is this update already applied?
sql "DESCRIBE settings"|grep setupComplete > /dev/null 2>&1
if [ ! $? = 0 ]; then
	echo "`date` [NOTICE : phvalheim] Applying database schema update for phvalheim-server >=v2.31"

	## BEGIN UPDATE ##

	# Server settings (previously env vars)
	sql "ALTER TABLE settings ADD COLUMN basePort INT DEFAULT 25000;"
	sql "ALTER TABLE settings ADD COLUMN defaultSeed VARCHAR(32) DEFAULT '';"
	sql "ALTER TABLE settings ADD COLUMN gameDNS VARCHAR(255) DEFAULT '';"
	# Note: steamApiKey already exists from v2.10 - reuse it (MariaDB columns are case-insensitive)
	sql "ALTER TABLE settings MODIFY COLUMN steamApiKey VARCHAR(255) DEFAULT '';"
	sql "ALTER TABLE settings ADD COLUMN phvalheimClientURL VARCHAR(512) DEFAULT 'https://github.com/brianmiller/phvalheim-client/raw/master/published_build/phvalheim-client-installer.exe';"
	sql "ALTER TABLE settings ADD COLUMN sessionTimeout INT DEFAULT 2592000;"

	# AI Helper keys (previously env vars)
	sql "ALTER TABLE settings ADD COLUMN openaiApiKey VARCHAR(255) DEFAULT '';"
	sql "ALTER TABLE settings ADD COLUMN geminiApiKey VARCHAR(255) DEFAULT '';"
	sql "ALTER TABLE settings ADD COLUMN claudeApiKey VARCHAR(255) DEFAULT '';"
	sql "ALTER TABLE settings ADD COLUMN ollamaUrl VARCHAR(512) DEFAULT '';"

	# Timezone (previously TZ env var)
	sql "ALTER TABLE settings ADD COLUMN timezone VARCHAR(64) DEFAULT 'Etc/UTC';"

	# Setup state tracking
	# setupComplete: 0=fresh install (wizard needed), 1=migrated from env vars, 2=fully configured
	sql "ALTER TABLE settings ADD COLUMN setupComplete TINYINT DEFAULT 0;"
	# migrationNoticeShown: 0=not yet shown, 1=admin dismissed the notice
	sql "ALTER TABLE settings ADD COLUMN migrationNoticeShown TINYINT DEFAULT 0;"

	if [ ! $? = 0 ]; then
		# update failed to apply
		exit 1
	fi

	## END UPDATE ##
else
	# update is already applied
	exit 2
fi
