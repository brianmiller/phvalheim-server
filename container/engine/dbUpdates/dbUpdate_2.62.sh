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
