#!/bin/bash

if [ ! $1 ]; then
	echo "Usage: sh dropTable.sh tableName"
	echo " Example: sh dropTable.sh settings"
	exit 1
fi

table="$1"

source /opt/stateless/engine/includes/phvalheim-static.conf

echo
echo
echo

echo "###: BEFORE ###"
sql "show tables;"

echo
echo
echo

echo "### AFTER ###"
sql "DROP TABLE IF EXISTS $table;"
sql "show tables;"
