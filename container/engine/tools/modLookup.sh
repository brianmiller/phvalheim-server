#!/bin/bash

if [ ! $1 ]; then
	echo "USAGE: modLookup.sh <mod name> or <uuid>"
	echo " Example: modLookup.sh NoMovementPenalty"
	exit 1
fi


if [ $1 ]; then
	len=$(echo "$1"|grep -o "-"|wc -l)
	if [ $len -eq 4 ]; then
		sql "SELECT name,owner,moduuid FROM tsmods WHERE moduuid='$1' LIMIT 1"
	else
		sql "SELECT name,owner,moduuid FROM tsmods WHERE name='$1' LIMIT 1"
	fi
fi


