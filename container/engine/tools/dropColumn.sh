#!/bin/bash

if [ ! $1 ] || [ ! $2 ]; then
        echo "Usage: sh dropColumn.sh tableName columnToDrop"
        echo " Example: sh dropColumn.sh worlds citizens"
        exit 1
fi

table="$1"
column="$2"

source /opt/stateless/engine/includes/phvalheim-static.conf

echo
echo
echo

echo "###: BEFORE table($table) ###"
sql "describe $table;"

echo
echo
echo

echo "### AFTER: table($table) ###"
sql "ALTER TABLE $table DROP COLUMN $column;"
sql "describe $table;"
