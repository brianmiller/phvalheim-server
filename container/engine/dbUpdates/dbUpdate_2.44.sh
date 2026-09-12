#!/bin/bash
#
# 2.44 -- remove BepInEx mod-loader rows from every world's mod selection.
#
# The loader is installed by InstallAndUpdateBepInEx() at engine start, unconditionally and
# always latest, before any mod is installed. It was never a meaningful selection: ticking it,
# unticking it or pinning a version had no effect on what got installed.
#
# But the catalogue carries three rows for it and every mod declares a dependency on one, so
# 2.43's dependency resolution added a loader row to every modded world -- and because Hexium
# mods resolve to Hexium's copy, the picker hung a yellow "dependency (deselected)" badge on a
# row the operator could not act on. 2.44 excludes the loader from the catalogue, the closure,
# the install plan and the viewer; this clears the rows already written.
#
# Object-by-object idempotent with NO top-level version guard, so a server that ran an earlier
# 2.44 RC still picks up later revisions. Re-running is harmless: the DELETE simply matches
# nothing the second time.

source /opt/stateless/engine/includes/phvalheim-static.conf

echo "`date` [NOTICE : dbUpdate_2.44] Removing mod-loader rows from world mod selections..."

# Counted BEFORE deleting so the log states what actually changed rather than implying it.
loaderRows=$(SQL "SELECT COUNT(*) FROM world_mods wm
                    JOIN mods m ON m.id = wm.mod_id
                   WHERE m.name LIKE 'BepInExPack%';")
case "$loaderRows" in
        ''|*[!0-9]*) loaderRows=0 ;;
esac

if [ "$loaderRows" -gt 0 ]; then
        # Worlds are named so an operator can see which selections changed, rather than
        # discovering a silently different mod count.
        SQL "SELECT CONCAT('  ', w.name, ' -> ', m.source, '/', m.owner, '/', m.name)
               FROM world_mods wm
               JOIN mods m ON m.id = wm.mod_id
               JOIN worlds w ON w.id = wm.world_id
              WHERE m.name LIKE 'BepInExPack%';" | while read -r line; do
                [ -n "$line" ] && echo "`date` [NOTICE : dbUpdate_2.44] $line"
        done

        SQL "DELETE wm FROM world_mods wm
               JOIN mods m ON m.id = wm.mod_id
              WHERE m.name LIKE 'BepInExPack%';"

        echo "`date` [NOTICE : dbUpdate_2.44] Removed $loaderRows loader row(s). The loader is still installed on every modded world by the engine."
else
        echo "`date` [NOTICE : dbUpdate_2.44] No loader rows to remove."
fi

# The loader's mods/mod_versions rows are deliberately LEFT in the catalogue. modSync writes
# them on every run from the live feeds, so deleting them here would only have them return an
# hour later; and mod_deps edges pointing at them are what the exclusion filters read.
echo "`date` [NOTICE : dbUpdate_2.44] dbUpdate_2.44 complete."
