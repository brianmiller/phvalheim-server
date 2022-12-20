#!/bin/bash

if [ ! $1 ]; then
	echo "Usage: sh addTable.sh tableName"
	echo " Example: sh addTable.sh settings"
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
sql "CREATE TABLE $table(
ID INT NOT NULL AUTO_INCREMENT,
PRIMARY KEY ( ID )
);"

sql "show tables;"
