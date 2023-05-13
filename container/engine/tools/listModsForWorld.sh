#!/bin/bash

if [ ! $1 ]; then
        echo "USAGE: listModsForWorld.sh <world name>"
        echo " Example: listModsForWorld.sh myworld"
        exit 1
fi

echo
echo "######### 'thunderstore_mods' column #########"
sql "SELECT thunderstore_mods FROM worlds WHERE name=\"$1\""
echo

echo
echo "######### 'thunderstore_mods_deps' column #########"
sql "SELECT thunderstore_mods_deps FROM worlds WHERE name=\"$1\""
echo

echo
echo "######### 'thunderstore_mods_all' column #########"
sql "SELECT thunderstore_mods_all FROM worlds WHERE name=\"$1\""
echo
