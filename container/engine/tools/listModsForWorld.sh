#!/bin/bash
#
# Show what a world will actually install: its own picks, the dependencies those pulled in,
# the version each will get, and where it comes from.
#
# Read world_mods / mods / mod_versions. It used to print the three legacy
# `thunderstore_mods*` columns, which have not been written since 2.43 -- so it showed an
# empty result for every world and looked like the world had no mods.

if [ ! "$1" ]; then
        echo "USAGE: listModsForWorld.sh <world name>"
        echo " Example: listModsForWorld.sh myworld"
        exit 1
fi

world="$1"

worldId=$(/opt/stateless/engine/tools/sql "SELECT id FROM worlds WHERE name='$world' LIMIT 1;")
if [ -z "$worldId" ]; then
        echo "World '$world' not found."
        exit 1
fi

echo
echo "######### '$world' (world id $worldId) #########"
echo
/usr/bin/mysql --table --database=phvalheim -e "
SELECT m.source,
       m.owner,
       m.name,
       IF(wm.is_dep = 1, 'dependency', 'selected')                  AS why,
       COALESCE(pin.version, latest.version, '(none)')              AS install_version,
       IF(wm.pin_version_id IS NULL, '', 'PINNED')                  AS pinned
  FROM world_mods wm
  JOIN mods m              ON m.id = wm.mod_id
  LEFT JOIN mod_versions pin    ON pin.id = wm.pin_version_id
  LEFT JOIN mod_versions latest ON latest.mod_id = m.id AND latest.source_rank = 0
 WHERE wm.world_id = $worldId
 ORDER BY wm.is_dep, m.name;"

echo
echo "######### install plan (what the engine loops over) #########"
echo
/opt/stateless/engine/tools/worldMods.py --world "$world" --plan 2>&1 \
        | column -t -s"$(printf '\t')"
echo
