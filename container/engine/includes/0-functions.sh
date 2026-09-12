#!/bin/bash
source /opt/stateless/engine/includes/phvalheim-static.conf
#source /opt/stateful/config/phvalheim-backend.conf


####### BEGIN: Functions #######
function getNextPort() {
        port=$basePort
        usedPorts=$(SQL "SELECT port FROM worlds;"|sort|uniq)
        echo "$usedPorts"|grep -w $port > /dev/null 2>&1
        RESULT=$?
        while [ $RESULT = 0 ]; do
                let port=$port+2 #we add 2 to ensure we're always using an even port number because Valheim also reserves the next consecutive (odd) port
                echo "$usedPorts"|grep -w $port > /dev/null 2>&1
                RESULT=$?
        done

        echo $port
}


#$1=world name
function InstallAndUpdateBepInEx() {
        worldName="$1"
        response=$(curl -sfSL -H "accept: application/json" "$tsJsonBepInExUrl")
        download_url=$(jq -r  ".latest.download_url" <<< "$response")
        latest_version=$(jq -r  ".latest.version_number" <<< "$response")
        installed_version=$(cat /opt/stateful/games/valheim/worlds/$worldName/game/bepinex_version.txt 2> /dev/null)

        if [ "$latest_version" == "$installed_version" ]; then
                echo "`date` [NOTICE : phvalheim] BepInEx is up-to-date."
        else
                echo "`date` [NOTICE : phvalheim] BepInEx is out-of-date and will be updated..."
                curl -sfSL $download_url --output /opt/stateful/games/valheim/worlds/$worldName/game/BepInEx_latest.zip
                unzip /opt/stateful/games/valheim/worlds/$worldName/game/BepInEx_latest.zip "BepInExPack_Valheim/*" -d "/opt/stateful/games/valheim/worlds/$worldName/game"
                rm /opt/stateful/games/valheim/worlds/$worldName/game/BepInEx_latest.zip
                chown -R phvalheim: $worldsDirectoryRoot/$worldName

                # this is important: creation of a world after the last world has been deleted caused numerous "No such file or directory" errors.
                # This was due to PWD missing after deletion of the last world. 
                cd

                rsync -purval /opt/stateful/games/valheim/worlds/$worldName/game/BepInExPack_Valheim/ /opt/stateful/games/valheim/worlds/$worldName/game/
                rm -r /opt/stateful/games/valheim/worlds/$worldName/game/BepInExPack_Valheim
                echo $latest_version > /opt/stateful/games/valheim/worlds/$worldName/game/bepinex_version.txt
        fi


        # added after Valheim was updated to 0.217.28
        if [ ! -z "$worldName" ]; then
                if [ -d "/opt/stateful/games/valheim/worlds/$worldName/game/unstripped_corlib" ]; then
                        rm -rf /opt/stateful/games/valheim/worlds/$worldName/game/unstripped_corlib
                fi
        fi


        # Ship macOS arm64 patched DLLs alongside stock ones (not replacing them).
        # The phvalheim-client swaps these in at launch ONLY on Apple Silicon Macs.
        # - BepInEx.Preloader: wraps RuntimeFix.Apply() in try-catch (stock crashes on arm64)
        # - MonoMod.RuntimeDetour: fixes DetourHelper/DetourNativeMonoPlatform for MAP_JIT W^X
        PATCHES_SRC="/opt/stateless/games/valheim/bepinex_patches"
        PATCHES_DST="/opt/stateful/games/valheim/worlds/$worldName/game/BepInEx/patches/macos_arm64"
        if [ -d "$PATCHES_SRC" ]; then
                mkdir -p "$PATCHES_DST"
                for patch_dll in "$PATCHES_SRC"/*.macos_arm64.dll; do
                        [ -f "$patch_dll" ] || continue
                        # derive stock name: BepInEx.Preloader.macos_arm64.dll -> BepInEx.Preloader.dll
                        stock_name=$(basename "$patch_dll" | sed 's/\.macos_arm64\.dll$/.dll/')
                        cp "$patch_dll" "$PATCHES_DST/$stock_name"
                        echo "`date` [NOTICE : phvalheim] Staged macOS arm64 patch: $stock_name"
                done
        fi


        chown -R phvalheim: $worldsDirectoryRoot/$worldName
}

#$1=world name
function worldDirPrep(){
        worldName="$1"

        echo "`date` [NOTICE : phvalheim] Preparing directory structure for world..."
        mkdir -p /opt/stateful/games/valheim/worlds/$worldName
        mkdir -p /opt/stateful/games/valheim/worlds/$worldName/game
        mkdir -p /opt/stateful/games/valheim/worlds/$worldName/custom_configs
        mkdir -p /opt/stateful/games/valheim/worlds/$worldName/custom_configs_secure
        mkdir -p /opt/stateful/games/valheim/worlds/$worldName/custom_plugins
        mkdir -p /opt/stateful/games/valheim/worlds/$worldName/custom_patchers

        # we need the world .config directory before the world starts (citizens and such...)
        #
        # Only permittedlist.txt was created here; adminlist.txt and bannedlist.txt were left
        # for Valheim to create on first boot, so the admin UI was editing files that did not
        # exist yet. syncAccessLists.sh writes all three from the database.
        mkdir -p /opt/stateful/games/valheim/worlds/$worldName/game/.config/unity3d/IronGate/Valheim/
        /opt/stateless/games/valheim/scripts/syncAccessLists.sh "$worldName"


        chown -R phvalheim: $worldsDirectoryRoot/$worldName
}

#$1=world name
function InstallAndUpdateValheim() {
        worldName="$1"

        # public_test check
        unset isBeta
        unset beta
        isBeta=$(/opt/stateless/engine/tools/sql "SELECT beta FROM worlds WHERE name='$worldName'")
        if [[ "$isBeta" -eq 1 ]]; then
                echo "`date` [NOTICE : phvalheim] Beta world detected!"
                beta="-beta public-test -betapassword yesimadebackups"
        fi

        # Ensure required directories exist
        if [ ! -d "/opt/stateful/games/valheim" ]; then
                mkdir -p /opt/stateful/games/valheim
        fi

        # Install Steam and Valheim
        echo "`date` [NOTICE : phvalheim] Installing and/or checking for Valheim updates..."

        # remove existing steam dir. this is needed when switching between stable and public_test branches
        if [ -d "/opt/stateful/games/valheim/worlds/$worldName/game/.steam" ]; then
                rm -rf /opt/stateful/games/valheim/worlds/$worldName/game/.steam
        fi

	# pre-create .steam dir fix the stupid 'ln' error we see in the world log files
	mkdir -p /opt/stateful/games/valheim/worlds/$worldName/game/.steam

        # do it with retry logic
        local maxRetries=5
        local retryCount=0
        local steamcmdSuccess=false

        while [ $retryCount -lt $maxRetries ] && [ "$steamcmdSuccess" = "false" ]; do
                retryCount=$((retryCount + 1))

                if [ $retryCount -gt 1 ]; then
                        echo "`date` [WARN : phvalheim] Steamcmd failed, retrying (attempt $retryCount of $maxRetries)..."
                        # Clean up Steam directory before retry to avoid stale state
                        rm -rf /opt/stateful/games/valheim/worlds/$worldName/game/Steam
                        rm -rf /opt/stateful/games/valheim/worlds/$worldName/game/.steam
                        mkdir -p /opt/stateful/games/valheim/worlds/$worldName/game/.steam
                        sleep 2
                fi

                HOME=/opt/stateful/games/valheim/worlds/$worldName/game \
                /usr/games/steamcmd +@sSteamCmdForcePlatformType linux \
                +force_install_dir /opt/stateful/games/valheim/worlds/$worldName/game \
                +login anonymous \
                +app_update 896660 \
                $beta validate \
                +quit

                # Check if valheim_server.x86_64 was installed
                if [ -f "/opt/stateful/games/valheim/worlds/$worldName/game/valheim_server.x86_64" ]; then
                        steamcmdSuccess=true
                        echo "`date` [NOTICE : phvalheim] Valheim server installed successfully for '$worldName'"
                fi
        done

        if [ "$steamcmdSuccess" = "false" ]; then
                echo "`date` [ERROR : phvalheim] Failed to install Valheim after $maxRetries attempts for '$worldName'"
                return 1
        fi

        chown -R phvalheim: $worldsDirectoryRoot/$worldName
}

#$1=world name, $2=world seed
function createCustomSeedConfig() {
        worldName="$1"
        worldSeed="$2"

        if [ ! -f "/opt/stateful/games/valheim/worlds/$worldName/custom_plugins/ZeroBandwidth-CustomSeed/CustomSeed.dll" ]; then
                echo "`date` [phvalheim] CustomSeed.dll is missing, installing..."
                if [ ! -f "/opt/stateless/games/valheim/custom_plugins/ZeroBandwidth-CustomSeed/CustomSeed.dll" ]; then
                        echo "`date` [ERROR : phvalheim] Install source for CustomSeed.dll is missing from '/opt/stateless/games/valheim/custom_plugins/ZeroBandwidth-CustomSeed/CustomSeed.dll', exiting..."
                        exit 1
                else
                        mkdir -p /opt/stateful/games/valheim/worlds/$worldName/custom_plugins/ZeroBandwidth-CustomSeed
                        cp /opt/stateless/games/valheim/custom_plugins/ZeroBandwidth-CustomSeed/CustomSeed.dll /opt/stateful/games/valheim/worlds/$worldName/custom_plugins/ZeroBandwidth-CustomSeed/
                fi
        fi

        echo "[CustomSeed]" > /opt/stateful/games/valheim/worlds/$worldName/custom_configs/ZeroBandwidth.CustomSeed.cfg
        echo "custom_seed = $worldSeed" >> /opt/stateful/games/valheim/worlds/$worldName/custom_configs/ZeroBandwidth.CustomSeed.cfg

        chown -R phvalheim: $worldsDirectoryRoot/$worldName
}

#$1=world name
function purgeWorldModsConfigsPatchers() {
        worldName="$1"

        if [ -z $worldsDirectoryRoot ]; then
                echo "`date` [ERROR : phvalheim] Main worlds root directory missing, this is fatal. Exiting..."
                break
        fi

        if [ -z $worldName ]; then
                echo "`date` [ERROR : phvalheim] World name not specificed during purge, can't continue..."
                break
        fi

        rm -rf $worldsDirectoryRoot/$worldName/game/BepInEx/plugins/*
        rm -rf $worldsDirectoryRoot/$worldName/game/BepInEx/config/*
        rm -rf $worldsDirectoryRoot/$worldName/game/BepInEx/patchers/*
}

#$1=world name
#
#Clears a world's mod selection. Called just BEFORE the worlds row is deleted, because
#world_mods is keyed on worlds.id and once that row is gone there is no way left to
#resolve which rows belonged to it.
function deleteWorldModRows() {
        worldName="$1"
        [ -z "$worldName" ] && return 0

        #Joined rather than done as two statements: a separate "SELECT id" then
        #"DELETE WHERE world_id=$id" deletes EVERY row if the select came back empty and
        #the variable expanded to nothing.
        SQL "DELETE wm FROM world_mods wm JOIN worlds w ON w.id = wm.world_id WHERE w.name='$worldName';"

        echo "`date` [NOTICE : phvalheim] Cleared mod selection rows for world '$worldName'."
}

#Syncs BOTH mod catalogues on every engine start.
#
#Replaces tsSeeder(), which downloaded a 14MB SQL dump from GitHub because the old sync took
#hours. A cold build of both catalogues from the live APIs is ~30s, so there is no reason to
#ship a stale dump -- and no reason to only do it once.
#
#Unconditional, NOT guarded on the catalogue being empty. An operator restarting the
#container expects the mod list to be current when it comes back; waiting up to the cron
#interval for that is the kind of staleness nobody thinks to suspect. The old empty-only
#guard existed because the previous sync was expensive; this one is not.
#
#Deliberately WITHOUT --force, so change detection still does its job: Thunderstore answers
#the conditional request with a bodiless 304 and Hexium's body is hashed and compared, so a
#restart with nothing new costs ~2s and zero writes. --force here would turn every container
#restart into a full refetch and a dependency-graph rebuild (~45s of mostly pointless work).
#
#`--trigger boot` matters: the configured modSyncIntervalHours is enforced for trigger=cron
#only (modSync.py), so a boot sync runs even if cron synced minutes ago. That is the point.
#
#Backgrounded so a slow or unreachable catalogue cannot stop worlds from starting -- the
#engine's job is to run Valheim, and cron retries hourly regardless.
function syncModCatalogue() {
        modCount=$(SQL "SELECT COUNT(*) FROM mods;" 2>/dev/null)
        case "$modCount" in
                ''|*[!0-9]*) modCount=0 ;;
        esac

        if [ "$modCount" -eq 0 ]; then
                echo "`date` [NOTICE : phvalheim] Mod catalogue is empty; building it in the background (about 30 seconds)."
        else
                echo "`date` [NOTICE : phvalheim] Refreshing both mod catalogues in the background ($modCount mods known)."
        fi
        echo "`date` [NOTICE : phvalheim] Progress: /opt/stateful/logs/modSync.log"
        setsid /opt/stateless/engine/tools/modSync.py --source all --trigger boot \
                >> /opt/stateful/logs/modSync.log 2>&1 &
}

#Removes world_mods rows whose world no longer exists. Runs at engine start to sweep up
#anything left by a pre-2.43 delete path or an interrupted one.
function pruneOrphanedWorldMods() {
        orphans=$(SQL "SELECT COUNT(*) FROM world_mods wm LEFT JOIN worlds w ON w.id = wm.world_id WHERE w.id IS NULL;")
        case "$orphans" in
                ''|*[!0-9]*) orphans=0 ;;
        esac

        if [ "$orphans" -gt 0 ]; then
                echo "`date` [NOTICE : phvalheim] Pruning $orphans orphaned world_mods row(s) whose world no longer exists."
                SQL "DELETE wm FROM world_mods wm LEFT JOIN worlds w ON w.id = wm.world_id WHERE w.id IS NULL;"
        fi
}

#$1=world name
#
#Ensures PhValheim's own required mods are part of the world's selection. They are
#recorded as normal is_dep=0 picks so the operator can see them in the mod list rather
#than wondering where three unrequested plugins came from.
function mergeRequiredTsMods() {
        worldName="$1"

        worldId=$(SQL "SELECT id FROM worlds WHERE name='$worldName' LIMIT 1;")
        if [ -z "$worldId" ]; then
                echo "`date` [ERROR : phvalheim] mergeRequiredTsMods: world '$worldName' not found."
                return 1
        fi

        for requiredMod in $requiredMods; do
                reqSource=$(echo "$requiredMod"|cut -d '|' -f1)
                reqOwner=$(echo "$requiredMod"|cut -d '|' -f2)
                reqName=$(echo "$requiredMod"|cut -d '|' -f3)

                reqModId=$(SQL "SELECT id FROM mods WHERE source='$reqSource' AND owner='$reqOwner' AND name='$reqName' LIMIT 1;")

                #A required mod that is not in the catalogue is not a cosmetic problem:
                #without QuickConnect a modded world cannot be joined, and without the
                #Companion the web UI has no server-side half. The old code could not
                #detect this at all -- it pasted uuids into a text column and only found
                #out at download time, by which point the log said "complete".
                if [ -z "$reqModId" ]; then
                        echo "`date` [WARN : phvalheim] Required mod $reqSource/$reqOwner/$reqName is NOT in the catalogue. World '$worldName' will be built WITHOUT it. Run a catalogue sync (admin UI -> Sync) and update the world."
                        continue
                fi

                #IGNORE, not REPLACE: if the operator has pinned a version of one of these
                #we must not silently reset them to latest.
                SQL "INSERT IGNORE INTO world_mods (world_id, mod_id, is_dep) VALUES ($worldId, $reqModId, 0);"
        done

        echo "`date` [NOTICE : phvalheim] Required mods merged for '$worldName'."
}

#$1=world name
function downloadAndInstallTsModsForWorld() {
        worldName="$1"

        #Counted per call, not per world -- this is a global, so a failure left over from the
        #previous world would otherwise condemn the next one.
        modInstallFailures=0

        #Expand dependencies. The graph is precomputed into mod_deps by modSync.py using
        #longest-prefix matching, so this is an indexed walk rather than the old
        #per-dependency `mysql` process against an unindexed table. It also walks the
        #version the world will ACTUALLY install -- the pinned one if pinned -- not
        #whatever happens to be newest.
        /opt/stateless/engine/tools/worldMods.py --world "$worldName" --resolve

        #The install plan is one tab-separated line per mod:
        #  source  owner  name  version  download_url  filename  pinned|latest  is_dep
        #
        #download_url comes from the CATALOGUE, not a template. The old code built
        #"$tsModDownloadUrl/$owner/$name/$version", which only ever works for
        #Thunderstore -- Hexium serves from cdn.hexium.gg behind an opaque numeric path
        #(cdn.hexium.gg/upload/1036/1.4.9.zip) that cannot be derived from owner/name/version.
        modPlan=$(/opt/stateless/engine/tools/worldMods.py --world "$worldName" --plan)

        #A read failure here must not look like "this world has no mods". Without this,
        #a database hiccup would silently produce an empty plan, install nothing, and the
        #only signal would be the plugins-empty check far below.
        if [ $? -ne 0 ]; then
                echo "`date` [ERROR : phvalheim] Could not build the mod install plan for '$worldName'. Refusing to continue."
                return 1
        fi

        #A literal tab, so read -r splits on tabs ONLY. Mod names and versions are safe
        #but owners are not guaranteed to be, and the default IFS would split any field
        #containing a space into the wrong column.
        origIFS="$IFS"
        while IFS=$'\t' read -r modSource modAuthor modName modVersion modDownloadUrl modFileConstructed modPinKind modIsDep; do

                [ -z "$modSource" ] && continue

                echo "`date` [phvalheim] World '$worldName' wants this mod: "
                echo "`date` [phvalheim]  Name: $modName"
                echo "`date` [phvalheim]  Author: $modAuthor"
                echo "`date` [phvalheim]  Source: $modSource"
                if [ "$modPinKind" = "pinned" ]; then
                        echo "`date` [phvalheim]  Version: $modVersion (PINNED by the operator)"
                else
                        echo "`date` [phvalheim]  Version: $modVersion (latest)"
                fi
                echo "`date` [phvalheim]  Download URL: $modDownloadUrl"

                if [ ! -f $tsModsDir/$modFileConstructed ]; then
                        echo "`date` [phvalheim]   #### Downloading $modFileConstructed from $modSource... ####"
                        wget -q --show-progress -O $tsModsDir/$modFileConstructed "$modDownloadUrl"
                        #wget's exit code was discarded here. A 404 (catalogue naming a version
                        #the source no longer serves), a DNS blip or a full disk all left an
                        #empty or absent file, and the loop carried on to "Installing..." as if
                        #nothing had happened -- which is how a world reaches its first start
                        #with no plugins at all while every log line reads like success.
                        if [ $? -ne 0 ] || [ ! -s "$tsModsDir/$modFileConstructed" ]; then
                                echo "`date` [ERROR : phvalheim]   #### DOWNLOAD FAILED for $modFileConstructed ($modDownloadUrl) -- this mod will be MISSING from '$worldName' ####"
                                rm -f "$tsModsDir/$modFileConstructed"
                                modInstallFailures=$((modInstallFailures+1))
                                continue
                        fi
                else
                        echo "`date` [phvalheim]   #### $modFileConstructed already exists in local repository, using it... ####"
                fi

                echo "`date` [phvalheim]    #### Installing... ####"

                #BepInEx is special
                rm -rf /tmp/BepInEx_tmp
                mkdir /tmp/BepInEx_tmp
                unzip -o $tsModsDir/$modFileConstructed BepInExPack_Valheim/* -d /tmp/BepInEx_tmp/ > /dev/null 2>&1
                RESULT=$?
                if [ $RESULT = 0 ]; then
                        cp -prfv /tmp/BepInEx_tmp/BepInExPack_Valheim/* $worldsDirectoryRoot/$worldName/game/. > /dev/null 2>&1
                        rm -rf /tmp/BepInEx_tmp
                fi

                #unzip -d creates only the LAST path component; it will NOT create missing
                #parents, and fails with exit 2 ("cannot create extraction directory") when one
                #is absent. The BepInEx pack ships BepInEx/config/ and BepInEx/core/ but NOT
                #BepInEx/plugins/ or BepInEx/patchers/, so on a fresh world those two parents
                #never existed -- and EVERY plugin unzip below failed, silently, because its
                #output is discarded. That is a world coming up with zero mods while every log
                #line reads "Installing...".
                #
                #Deterministic, not a race: it happens to every newly created modded world.
                mkdir -p $worldsDirectoryRoot/$worldName/game/BepInEx/plugins
                mkdir -p $worldsDirectoryRoot/$worldName/game/BepInEx/patchers
                mkdir -p $worldsDirectoryRoot/$worldName/game/BepInEx/config
                mkdir -p $worldsDirectoryRoot/$worldName/game/BepInEx/core

                #Plugins
                unzip -o $tsModsDir/$modFileConstructed -x config/* core/* patchers/* BepInExPack_Valheim/* README.md icon.png manifest.json -d $worldsDirectoryRoot/$worldName/game/BepInEx/plugins/$modName/ > /dev/null 2>&1
                unzipResult=$?
                #Captured BEFORE the test: inside the if, $? is the TEST's status, not unzip's.
                #
                #11 is "no matching files" and is CORRECT here -- the BepInEx pack contains only
                #BepInExPack_Valheim/*, which this command excludes, so it legitimately extracts
                #nothing. Treating 11 as failure would mark every modded world broken.
                if [ $unzipResult -ne 0 ] && [ $unzipResult -ne 11 ]; then
                        echo "`date` [ERROR : phvalheim]   #### PLUGIN INSTALL FAILED for $modName (unzip exit $unzipResult) -- it will be MISSING from '$worldName' ####"
                        modInstallFailures=$((modInstallFailures+1))
                fi

                #Core
                unzip -o $tsModsDir/$modFileConstructed core/* -d $worldsDirectoryRoot/$worldName/game/BepInEx/core/ > /dev/null 2>&1

                #Config
                rm -rf /tmp/BepInEx_tmp
                mkdir /tmp/BepInEx_tmp
                unzip -o $tsModsDir/$modFileConstructed config/* -d /tmp/BepInEx_tmp/ > /dev/null 2>&1
                cp -prfv /tmp/BepInEx_tmp/config/* $worldsDirectoryRoot/$worldName/game/BepInEx/config/. > /dev/null 2>&1

                #Patchers
                unzip -j -o $tsModsDir/$modFileConstructed patchers/* -d $worldsDirectoryRoot/$worldName/game/BepInEx/patchers/$modName/ > /dev/null 2>&1
        done <<< "$modPlan"
        IFS="$origIFS"

        #echo
        echo "`date` [NOTICE : phvalheim] Mods download and installation sequence complete. Note: This does NOT indicate success."


        #Remove empty directories
        allPluginDirs=$(ls -d $worldsDirectoryRoot/$worldName/game/BepInEx/plugins/* 2>/dev/null)
        for pluginDir in $allPluginDirs; do
                if [ ! "$(ls -A $pluginDir)" ]; then
                        #remove empty dir
                        rm -r $pluginDir
                fi
        done

        allPatcherDirs=$(ls -d $worldsDirectoryRoot/$worldName/game/BepInEx/patchers/* 2>/dev/null)
        for patcherDir in $allPatcherDirs; do
                if [ ! "$(ls -A $patcherDir)" ]; then
                        #remove empty dir
                        rm -r $patcherDir
                fi
        done

        #final step, ensure the world and all its files are owned by phvalheim
        chown -R phvalheim:phvalheim $worldsDirectoryRoot/$worldName

        #some mod zips (built on Windows) store directories without the execute bit; unzip
        #preserves that, and BepInEx then fails to boot with a fatal UnauthorizedAccessException
        #(issue #80). u+rwX restores directory traverse without touching group/other bits.
        #The dirs may legitimately not exist yet if nothing installed, hence the -d guards --
        #without them chmod prints "cannot access" and that was the ONLY visible trace of a
        #world whose mods had all silently failed to install.
        [ -d "$worldsDirectoryRoot/$worldName/game/BepInEx" ] && chmod -R u+rwX $worldsDirectoryRoot/$worldName/game/BepInEx

        #### Did any of that actually work? ####
        #
        #Everything above swallows its own errors: the unzips redirect stderr to /dev/null and
        #nothing checked a return code, so "Mods download and installation sequence complete"
        #was printed whether 15 mods installed or none did. A modded world would then be
        #packaged, given an md5, marked ready and started -- and BepInEx would report
        #"0 plugins to load" with nothing anywhere saying why.
        #
        #A world that asked for mods and ended up with an empty plugins directory is never
        #correct, whatever the underlying cause (failed download, bad archive, full disk).
        pluginRoot="$worldsDirectoryRoot/$worldName/game/BepInEx/plugins"
        if [ ! -d "$pluginRoot" ] || [ -z "$(ls -A "$pluginRoot" 2>/dev/null)" ]; then
                echo "`date` [ERROR : phvalheim] World '$worldName' selected mods but NO plugins were installed. Refusing to publish it as ready."
                echo "`date` [ERROR : phvalheim] Check the download errors above; the world's plugins directory is '$pluginRoot'."
                return 1
        fi

        if [ "${modInstallFailures:-0}" -gt 0 ]; then
                echo "`date` [ERROR : phvalheim] World '$worldName': $modInstallFailures mod(s) failed to download and are MISSING."
                return 1
        fi

        echo "`date` [NOTICE : phvalheim] Mod install verified for '$worldName': $(ls -A "$pluginRoot" | wc -l) plugin(s) present."
        return 0
}


#$1=worldName, $2=worldHost, $3=worldPort, $4=worldPassword
function createQuickConnectConfig() {
        worldName="$1"
        worldHost="$2"
        worldPort="$3"
        worldPassword="$4"

        echo "$worldName:$worldHost:$worldPort:$worldPassword" > /opt/stateful/games/valheim/worlds/$worldName/game/BepInEx/config/quick_connect_servers.cfg
}


#$1=worldName
function installCustomModsConfigsPatchers() {
        echo "`date` [NOTICE : phvalheim] Installing custom mods, configs, and patchers..."

        worldName="$1"

        customModsSourceDir="/opt/stateful/games/valheim/worlds/$worldName/custom_plugins"
        customConfigsSourceDir="/opt/stateful/games/valheim/worlds/$worldName/custom_configs"
        customPatchersSourceDir="/opt/stateful/games/valheim/worlds/$worldName/custom_patchers"

        worldModsDestDir="$worldsDirectoryRoot/$worldName/game/BepInEx/plugins"
        worldConfigsDestDir="$worldsDirectoryRoot/$worldName/game/BepInEx/config"
        worldPatchersDestDir="$worldsDirectoryRoot/$worldName/game/BepInEx/patchers"

        if [ ! -d $customModsSourceDir ]; then
                echo "`date` [NOTICE : phvalheim] Custom mods source directory for this world is missing, creating..."
                mkdir -p $customModsSourceDir
        fi

        if [ ! -d $customConfigsSourceDir ]; then
                echo "`date` [NOTICE : phvalheim] Custom configs source directory for this world is missing, creating..."
                mkdir -p $customConfigsSourceDir
        fi

        if [ ! -d $customPatchersSourceDir ]; then
                echo "`date` [NOTICE : phvalheim] Custom patchers source directory for this world is missing, creating..."
                mkdir -p $customPatchersSourceDir
        fi

        cp -prf $customModsSourceDir/* $worldModsDestDir/. > /dev/null 2>&1
        cp -prf $customConfigsSourceDir/* $worldConfigsDestDir/. > /dev/null 2>&1
        cp -prf $customPatchersSourceDir/* $worldPatchersDestDir/. > /dev/null 2>&1

        chown -R phvalheim:phvalheim $customModsSourceDir
        chown -R phvalheim:phvalheim $customConfigsSourceDir
        chown -R phvalheim:phvalheim $customPatchersSourceDir

        #cp -p preserves source permissions, which may lack the directory execute bit
        #(same failure mode as issue #80) — restore traverse for the phvalheim user
        chmod -R u+rwX $worldModsDestDir $worldConfigsDestDir $worldPatchersDestDir

}


#$1=worldName
function installSystemPlugins() {
	echo "`date` [NOTICE : phvalheim] Installing system plugins..."

	worldName="$1"

	systemPluginsSourceDir="/opt/stateless/games/valheim/custom_plugins"
	worldPluginsDestDir="$worldsDirectoryRoot/$worldName/custom_plugins"

	if [ ! -d $systemPluginsSourceDir ]; then
		echo "`date` [NOTICE : phvalheim] System plugins source directory is missing, skipping..."
		return 0
	fi

	# Install tick monitor plugin
	tickMonitorSrc="$systemPluginsSourceDir/PhValheim-TickMonitor"
	tickMonitorDest="$worldPluginsDestDir/PhValheim-TickMonitor"
	if [ -d "$tickMonitorSrc" ]; then
		mkdir -p "$tickMonitorDest"
		cp -rf "$tickMonitorSrc/"* "$tickMonitorDest/." 2>/dev/null || true
	fi

	chown -R phvalheim:phvalheim $worldPluginsDestDir
}


# this is seperate from installCustomModsConfigsPatchers() because this must only run after the client payload has been packaged.
function InstallCustomConfigSecureFiles() {
        echo "`date` [NOTICE : phvalheim] Installing custom_configs_secure files..."
        worldName="$1"

        customConfigsSecureSourceDir="/opt/stateful/games/valheim/worlds/$worldName/custom_configs_secure"
        worldConfigsDestDir="$worldsDirectoryRoot/$worldName/game/BepInEx/config"

        if [ ! -d $customConfigsSecureSourceDir ]; then
                echo "`date` [NOTICE : phvalheim] Custom configs secure source directory for this world is missing, creating..."
                mkdir -p $customConfigsSecureSourceDir
        fi

        cp -prf $customConfigsSecureSourceDir/* $worldConfigsDestDir/. > /dev/null 2>&1

        chown -R phvalheim:phvalheim $customConfigsSecureSourceDir
}


#$1=worldName
function packageClient() {

        #echo ""
        echo "`date` [NOTICE : phvalheim] Building PhValheim client payload..."

        worldName="$1"

        #delete current world payload zip
        rm /opt/stateful/games/valheim/worlds/$worldName/$worldName.zip > /dev/null 2>&1

        cd /opt/stateful/games/valheim/worlds/$worldName/game

        # inject universal macOS doorstop dylib for macOS client support
        cp /opt/stateless/games/valheim/macos/libdoorstop.dylib ./doorstop_libs/libdoorstop.dylib

        zip ../$worldName.zip -r \
        ./BepInEx \
        ./doorstop_libs \
        ./doorstop_config.ini \
        ./start_game_bepinex.sh \
        ./winhttp.dll

        rm -f ./doorstop_libs/libdoorstop.dylib

        return $?
}

#create supervisor config file for this world
#$1=worldName, $2=worldPassword, $3=worldPort
function createSupervisorWorldConfig() {
        worldName="$1"
        worldPassword="$2"
        worldPort="$3"

        echo "
        [program:valheimworld_$worldName]
        command=/opt/stateless/games/valheim/scripts/startWorld.sh $worldName $worldPassword $worldPort
        user=phvalheim
        autostart=false
        autorestart=true
        stdout_logfile=/opt/stateful/logs/valheimworld_$worldName.log
        ;stdout_logfile_maxbytes=1MB
        ;stdout_logfile_backups=1
        redirect_stderr=true
        " > $worldSupervisorConfigs/valheimworld_$worldName.conf

        # dumb supervisor
        touch /opt/stateful/logs/valheimworld_$worldName.log
        touch /opt/stateful/logs/valheimworld_$worldName.log.1
        chown phvalheim:phvalheim /opt/stateful/logs/valheimworld_*

        /usr/bin/supervisorctl reread
        /usr/bin/supervisorctl update

}

#delete supervisor config file for this world
#$1=worldName
function deleteSupervisorWorldConfig(){
        worldName="$1"
        rm $worldSupervisorConfigs/valheimworld_$worldName.conf
        /usr/bin/supervisorctl reread
        /usr/bin/supervisorctl update
}


#$1=input file, returns md5sum
function getMD5 () {
        md5sum "$1"|cut -d " " -f1
}


#$1=world, $2=md5sum, sets world md5sum in database.  Used for client version checking and download consistency validation
function setMD5 () {
        worldName="$1"
        worldMD5="$2"

        #An empty md5 is not a failure -- a vanilla world has no client payload to
        #checksum, so the column is deliberately cleared. Say that, instead of logging
        #"Setting world md5sum for 'x' to ''", which reads like a checksum that failed.
        if [ -z "$worldMD5" ]; then
                echo "`date` [NOTICE : phvalheim] Clearing world md5sum for '$worldName' (no client payload)"
        else
                echo "`date` [NOTICE : phvalheim] Setting world md5sum for '$worldName' to '$worldMD5'"
        fi
        SQL "UPDATE worlds SET world_md5='$worldMD5' WHERE name='$worldName';"
}


#$1=world. Reads the REAL seed out of the world's .fwl and stores it.
#
#A vanilla world gets no CustomSeed mod, and Valheim's dedicated server has no seed
#argument -- it invents a seed when it first generates the .fwl. So the only way to
#know a vanilla world's seed is to read it back out of the save file afterwards.
#
#The extraction is the same one importWorld.sh has always used on uploaded saves.
#No-op if the .fwl does not exist yet (world has never been started) or the seed is
#already recorded.
#$2=1 when the world is VANILLA, which changes who owns the seed:
#
#  modded  -- the stored seed is an INPUT. It is fed to the CustomSeed mod, so it is
#             the truth and the .fwl only ever confirms it. Fill it in if missing,
#             never overwrite.
#  vanilla -- the stored seed can only ever be an OUTPUT. Valheim invents the seed
#             when it generates the .fwl and there is no way to influence it, so the
#             .fwl is AUTHORITATIVE and anything that disagrees with it is wrong.
#
#That distinction is the whole fix: the engine used to stamp a random uint32 on every
#seedless world including vanilla ones, and this function's "already has a seed, stop"
#early-out then made that fabricated number permanent -- the real seed could never be
#recorded, and the public card advertised a number belonging to no world at all.
#$1=worlds_local dir, $2=world name. Echoes the path of the world-metadata file, or
#nothing if the world has never been saved.
#
#VALHEIM 1.0 CHANGED THIS LAYOUT. It used to be one file per world:
#
#    worlds_local/<name>.fwl
#
#and is now a DIRECTORY per world holding a numbered save set:
#
#    worlds_local/<name>/_main.1.fwl2   (+ _main.1.db2, chunks, _main.1.ok)
#
#Looking only for the old name silently found nothing on 1.0 — no error, just a world
#whose seed could never be read. Prefer the newest .fwl2, fall back to the legacy .fwl
#so pre-1.0 saves and imported worlds still resolve.
function findWorldSaveMeta () {
        localDir="$1"
        worldName="$2"

        newest=$(ls -t "$localDir/$worldName"/_main.*.fwl2 2>/dev/null | head -1)
        if [ -n "$newest" ]; then
                echo "$newest"
                return 0
        fi

        if [ -f "$localDir/$worldName.fwl" ]; then
                echo "$localDir/$worldName.fwl"
                return 0
        fi

        return 0
}


#$1=world metadata file (.fwl or .fwl2). Echoes the seed NAME, e.g. M5alDHpjHy.
#
#Both formats start with a length-prefixed world name followed by a length-prefixed
#seed name, so one walk reads either — verified against a real .fwl2 written by the
#1.0 server and against .fwl files from live pre-1.0 worlds.
function readSeedFromSaveMeta () {
        (head -c$(od -j$(od -j8 -N1 -An -t u1) -N1 -An -t u1);echo) < "$1"
}


function syncWorldSeedFromSave () {
        worldName="$1"
        isVanillaWorld="$2"
        worldSaveDir="/opt/stateful/games/valheim/worlds/$worldName/game/.config/unity3d/IronGate/Valheim/worlds_local"
        fwl=$(findWorldSaveMeta "$worldSaveDir" "$worldName")

        currentSeed=$(SQL "SELECT IFNULL(seed,'') FROM worlds WHERE name='$worldName'")

        if [ -z "$fwl" ] || [ ! -f "$fwl" ]; then
                #No save yet. For a vanilla world any seed on record is fiction by
                #definition -- there is nowhere else it could have come from -- so clear
                #it rather than keep displaying it. The card then reads "generated on
                #first start", which is the truth.
                if [ "$isVanillaWorld" = "1" ] && [ -n "$currentSeed" ]; then
                        echo "`date` [NOTICE : phvalheim] Clearing seed for vanilla world '$worldName' -- it has no save yet, so '$currentSeed' cannot be its seed"
                        SQL "UPDATE worlds SET seed=NULL WHERE name='$worldName';"
                fi
                return 0
        fi

        #Modded world with a seed already: that seed is the input, leave it alone.
        if [ -n "$currentSeed" ] && [ "$isVanillaWorld" != "1" ]; then
                return 0
        fi

        worldSeed=$(readSeedFromSaveMeta "$fwl")
        if [ -z "$worldSeed" ]; then
                echo "`date` [WARN : phvalheim] Could not read seed from '$fwl'"
                return 1
        fi

        if [ "$worldSeed" = "$currentSeed" ]; then
                return 0
        fi

        if [ -n "$currentSeed" ]; then
                echo "`date` [NOTICE : phvalheim] Correcting seed for vanilla world '$worldName': '$currentSeed' -> '$worldSeed' (read from its save)"
        else
                echo "`date` [NOTICE : phvalheim] Recording generated seed for '$worldName': $worldSeed"
        fi
        SQL "UPDATE worlds SET seed='$worldSeed' WHERE name='$worldName';"
}


#$1=world. Used to generate the mod viewer dropdown in the admin ui
function generateModViewerJson () {
        echo "`date` [NOTICE : phvalheim] Generating mod viewer json payload..."

        #Was hand-assembled JSON built with two `mysql` processes PER MOD and a regex to
        #strip the trailing comma, which also meant a mod name containing a quote produced
        #invalid JSON. worldMods.py builds it with a real JSON encoder from the unified
        #catalogue, and carries the source and pinned version the viewer now shows.
        /opt/stateless/engine/tools/worldMods.py --world "$worldName" --viewer-json
}

# no input required, sets world engine modes to 'start' on engine start if autostart flag is set
function autoStart () {
        WORLDS=$(SQL "SELECT id FROM worlds;")

        for WORLD in $WORLDS; do
                worldID=$WORLD
		worldName=$(SQL "SELECT name FROM worlds WHERE id='$worldID';")
		autoStart=$(SQL "SELECT autostart FROM worlds WHERE id='$worldID';")
		if [ "$autoStart" -eq 1 ]; then
			echo "`date` [NOTICE : phvalheim] World '$worldName' is set to autostart, starting world..."
			SQL "UPDATE worlds SET mode='start' WHERE id='$worldID'"
		fi
	done
}

####### END: Functions #######
