#!/bin/bash
set -e
source /opt/stateless/engine/includes/phvalheim-static.conf
addColumn() {
    if ! sql "SHOW COLUMNS FROM $1 LIKE '$2'" | grep -q "$2"; then
        sql "ALTER TABLE $1 ADD COLUMN $2 $3;"
    fi
}
addColumn settings publicTheme "VARCHAR(16) NOT NULL DEFAULT 'classic'"
addColumn settings disableSteamOverlay 'TINYINT NOT NULL DEFAULT 0'
# NULL means inherit the operator default. A world can explicitly enable or disable.
addColumn worlds disableSteamOverlay 'TINYINT NULL DEFAULT NULL'
# Personal presets are separate from operator policy and never enter shared archives.
sql "CREATE TABLE IF NOT EXISTS player_mod_configs (
    world_id INT UNSIGNED NOT NULL,
    steam_id VARCHAR(17) NOT NULL,
    revision INT UNSIGNED NOT NULL DEFAULT 0,
    config_json MEDIUMTEXT NOT NULL,
    PRIMARY KEY (world_id, steam_id)
) ENGINE=InnoDB;"
