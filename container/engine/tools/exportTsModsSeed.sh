#!/bin/bash

echo
echo "Exporting Thunderstore Mods database from this instance to /opt/stateful/tsmods_seed.sql"
echo

mysqldump phvalheim tsmods > /opt/stateful/tsmods_seed.sql
