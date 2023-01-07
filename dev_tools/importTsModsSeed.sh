#!/bin/bash


ls -ald ../container > /dev/null 2>&1
if [ ! $? = 0 ]; then
	echo "This script must be run from within the dev_tools directory, exiting..."
	exit 1
fi

if [ ! -f "../running_container/tsmods_seed.sql" ]; then
	echo "ERROR: ../running_container/tsmods_seed.sql is missing.  Did you run /opt/stateless/engine/tools/exportTsModsSeed.sh from within a running container?"
	exit 1
fi

echo
echo "Moving ../running_container/tsmods_seed.sql to ../container/mysql/tsmods_seed.sql"
sudo mv ../running_container/tsmods_seed.sql ../container/mysql/tsmods_seed.sql
echo
