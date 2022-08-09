#!/bin/bash

if [ ! $1 ] || [ ! $2 ] || [ ! $3 ]; then
	echo "Usage: sh addColumn.sh tableName columnToAdd columnDefinition"
	echo " Example: sh addColumn.sh worlds citizens TEXT"
	exit 1
fi

table="$1"
column="$2"
columnDef="$3"

source /opt/stateless/engine/includes/phvalheim.conf

echo
echo
echo

echo "###: BEFORE table($table) ###"
sql "describe worlds;"

echo
echo
echo

echo "### AFTER: table($table) ###"
sql "ALTER TABLE $table ADD COLUMN $column $columnDef;"
sql "describe worlds;"
