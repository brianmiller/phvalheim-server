#!/bin/bash

# source tsmods_seed.sql
tsmods_source="/home/brian/docker_persistent/37648-phvalheim2/tsmods_seed.sql"

ls -ald ../container > /dev/null 2>&1
if [ ! $? = 0 ]; then
	echo "This script must be run from within the dev_tools directory, exiting..."
	exit 1
fi

if [ ! -f $tsmods_source ]; then
	echo "ERROR: ~/docker_persistent/37648-phvalheim2/tsmods_seed.sql is missing.  Did you run /opt/stateless/engine/tools/exportTsModsSeed.sh from within a running container?"
	exit 1
fi

echo
echo "Moving $tsmods_source to ../container/mysql/tsmods_seed.sql"
cp $tsmods_source ../container/mysql/tsmods_seed.sql
echo
