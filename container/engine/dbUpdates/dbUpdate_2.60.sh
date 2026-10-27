#!/bin/bash
set -e
source /opt/stateless/engine/includes/phvalheim-static.conf

addColumn() {
    local table="$1" column="$2" definition="$3"
    if ! sql "SHOW COLUMNS FROM $table LIKE '$column'" | grep -q "$column"; then
        sql "ALTER TABLE $table ADD COLUMN $column $definition;"
    fi
}

# Separate from world_mods: dependency resolution rebuilds those rows, but config
# delivery is operator intent and must survive resolution and temporary removal.
sql "CREATE TABLE IF NOT EXISTS world_mod_config_policy (
    world_id INT UNSIGNED NOT NULL,
    mod_id INT UNSIGNED NOT NULL,
    push_server TINYINT NOT NULL DEFAULT 1,
    push_client TINYINT NOT NULL DEFAULT 0,
    PRIMARY KEY (world_id, mod_id)
) ENGINE=InnoDB;"
addColumn mod_config_overrides overridable 'TINYINT NOT NULL DEFAULT 1'
addColumn worlds config_policy_version 'TINYINT NOT NULL DEFAULT 0'
addColumn settings configPolicyNoticeState 'TINYINT NOT NULL DEFAULT 0'
