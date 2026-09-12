<?php

include '/opt/stateless/nginx/www/includes/config_env_puller.php';
include '/opt/stateless/nginx/www/includes/phvalheim-frontend-config.php';
function getModViewerJsonForWorld($pdo,$world) {
        $sth = $pdo->prepare("SELECT modsViewer FROM worlds WHERE name='$world'");
        $sth->execute();
        $result = $sth->fetchColumn();
        return $result;
}
/**
 * Mod display name by `mods.id`.
 *
 * The parameter is a mods.id, NOT a source uuid. It was a `tsmods.moduuid` lookup until
 * 2.43; a uuid cannot identify a mod once there is more than one catalogue, because Hexium
 * mirrors Thunderstore packages carrying their original uuid4 and 600 of them collide.
 *
 * Prefixed with the owner, since two catalogues can both carry a mod called `Jotunn` by
 * different owners and a bare name would be ambiguous in a list.
 */
function getModNameByUuid($pdo,$modUUID) {
        $sth = $pdo->prepare("SELECT name FROM mods WHERE id = ?");
        $sth->execute([(int)$modUUID]);
        $result = $sth->fetchColumn();
        return $result === false ? null : $result;
}
/**
 * Every mod a world runs — the operator's picks AND their resolved dependencies.
 *
 * Returns `mods.id` values. Read `world_mods`, not the legacy space-separated
 * `thunderstore_mods` / `thunderstore_mods_deps` columns: since 2.43 nothing writes those,
 * so this returned an empty list for every world while looking like it worked.
 */
function getAllWorldMods($pdo,$world) {
        $sth = $pdo->prepare(
            "SELECT wm.mod_id
               FROM world_mods wm
               JOIN worlds w ON w.id = wm.world_id
              WHERE w.name = ?
              ORDER BY wm.is_dep, wm.mod_id");
        $sth->execute([$world]);
        return array_map('strval', $sth->fetchAll(PDO::FETCH_COLUMN));
}

/**
 * Mods the operator explicitly chose (is_dep=0).
 *
 * No placeholder filtering needed any more: `world_mods` holds one row per real mod, where
 * the legacy column was a space-separated string that could contain the literal
 * 'placeholder' and empty segments.
 */
function getSelectedModCountOfWorld($pdo,$world) {
        $sth = $pdo->prepare(
            "SELECT COUNT(*)
               FROM world_mods wm
               JOIN worlds w ON w.id = wm.world_id
              WHERE w.name = ? AND wm.is_dep = 0");
        $sth->execute([$world]);
        return (int)$sth->fetchColumn();
}

/**
 * Every mod a world will actually install — picks plus dependencies.
 *
 * This is the number on the world cards ("Mods Running"). It read the legacy
 * space-separated columns, which nothing has written since 2.43, so every world's card
 * showed 0. Deduplication is now free: `world_mods` is keyed on (world_id, mod_id), so a
 * mod that is both chosen and a dependency of something else is one row, not two strings
 * needing array_unique().
 */
function getTotalModCountOfWorld($pdo,$world) {
        $sth = $pdo->prepare(
            "SELECT COUNT(*)
               FROM world_mods wm
               JOIN worlds w ON w.id = wm.world_id
              WHERE w.name = ?");
        $sth->execute([$world]);
        return (int)$sth->fetchColumn();
}

function getSeed($pdo,$world) {
	$sth = $pdo->prepare("SELECT seed FROM worlds WHERE name='$world'");
	$sth->execute();
	$result = $sth->fetchColumn();
	return $result;
}

# Order a world-name list: ONLINE first, then alphabetically within each group.
#
# Separate from getMyWorlds() because it cannot be done in SQL -- "online" is a live process
# check, not a column. Takes the online map as a plain array so it is testable without a
# database, a container, or a running Valheim.
#
# $isOnlineMap: [worldName => bool]. A world missing from the map counts as offline, so a
# lookup that failed sinks the world down the list rather than throwing.
function sortWorldsOnlineFirst(array $worlds, array $isOnlineMap) {
        usort($worlds, function($a, $b) use ($isOnlineMap) {
                $aOn = !empty($isOnlineMap[$a]);
                $bOn = !empty($isOnlineMap[$b]);
                if ($aOn !== $bOn) {
                        return $aOn ? -1 : 1;
                }
                # Case-insensitive: a byte comparison puts every capitalised name above every
                # lowercase one, so "banana" would sort above "Apple". This list is read by
                # people.
                return strcasecmp($a, $b);
        });
        return $worlds;
}

# Ordered by NAME only.
#
# It used to lead with `currentMemory`, presumably as a stand-in for "online first". That column
# is written by a cron, so it is stale for a world that has just started or stopped and
# meaningless for one that has never run -- which made the card order look arbitrary. Worse, the
# public page decides online-ness with a live isWorldRunning() check, so the column and the card
# could disagree outright.
#
# Online-first grouping is applied by the caller, which is the only place that can know it.
function getMyWorlds($pdo,$citizen) {
        $sth = $pdo->query("SELECT name FROM worlds WHERE citizens LIKE '%$citizen%' OR public = '1' ORDER BY name ASC");
        $result = $sth->fetchAll(PDO::FETCH_COLUMN);
        return $result;
}

function getHideSeed($pdo,$world) {
        $sth = $pdo->prepare("SELECT hideseed FROM worlds WHERE name='$world'");
        $sth->execute();
        $result = $sth->fetchColumn();
        return $result;
}

function getMD5($pdo,$world) {
        $sth = $pdo->prepare("SELECT world_md5 FROM worlds WHERE name='$world'");
        $sth->execute();
        $result = $sth->fetchColumn();
        return $result;
}

function getWorldMemory($pdo,$world) {
        $sth = $pdo->prepare("SELECT currentMemory FROM worlds WHERE name='$world'");
        $sth->execute();
        $result = $sth->fetchColumn();
        return $result;
}

function getDateDeployed($pdo,$world) {
        $sth = $pdo->prepare("SELECT date_deployed FROM worlds WHERE name='$world'");
        $sth->execute();
        $result = $sth->fetchColumn();
        return $result;
}

function getDateUpdated($pdo,$world) {
        $sth = $pdo->prepare("SELECT date_updated FROM worlds WHERE name='$world'");
        $sth->execute();
        $result = $sth->fetchColumn();
        return $result;
}
# Launch string field order is POSITIONAL and parsed by index in the client's
# Arguments.cs. Only ever APPEND fields -- an older client ignores trailing fields it
# does not know about, but reordering silently breaks every installed client.
#
#   0        1      2         3         4      5               6            7
#   launch ? world ? password ? gameDNS ? port ? phvalheimHost ? httpScheme ? vanilla
function getLaunchString($pdo,$world,$gameDNS,$phvalheimHost,$httpScheme) {
        $getWorldData = $pdo->query("SELECT status,name,port FROM worlds WHERE name='$world'");
        foreach($getWorldData as $row)
        {
                $status = $row['status'];
                $world = $row['name'];
                $port = $row['port'];

                $vanilla = (int)getVanilla($pdo, $world);

                # A modded world's password is inert -- startWorld.sh has never passed
                # -password for one, and access is gated by the CITIZENS list instead.
                # Keep sending the historical literal so older clients behave identically.
                $password = $vanilla ? (getWorldPassword($pdo, $world) ?: "") : "hammertime";

                $launchString = base64_encode("launch?$world?$password?$gameDNS?$port?$phvalheimHost?$httpScheme?$vanilla");

		return $launchString;
	}
}

function getCitizens($pdo,$world) {
        $sth = $pdo->query("SELECT citizens FROM worlds WHERE name='$world';");
	$sth->execute();
	$result = $sth->fetchColumn();
        return $result;
}

function getPublic($pdo,$world) {
        $sth = $pdo->query("SELECT public FROM worlds WHERE name='$world';");
        $sth->execute();
        $result = $sth->fetchColumn();
        return $result;
}

function getVanilla($pdo,$world) {
        $sth = $pdo->prepare("SELECT vanilla FROM worlds WHERE name=?");
        $sth->execute([$world]);
        return $sth->fetchColumn();
}

function getWorldPassword($pdo,$world) {
        $sth = $pdo->prepare("SELECT password FROM worlds WHERE name=?");
        $sth->execute([$world]);
        return $sth->fetchColumn();
}

function getCrossplay($pdo,$world) {
        $sth = $pdo->prepare("SELECT crossplay FROM worlds WHERE name=?");
        $sth->execute([$world]);
        return $sth->fetchColumn();
}

# Which network backend the world is ACTUALLY running, as opposed to what the crossplay
# column says it should be. Returns 'playfab', 'steam', or NULL if the log says neither.
#
# These disagree whenever the flag has been toggled without a restart, because -crossplay is
# only applied at launch (startWorld.sh). Keying the join link off the COLUMN meant a world
# with crossplay newly switched on -- but still running as a Steam server -- advertised a
# join code that would never appear, and hid the +connect link that would have worked.
#
# The log states it plainly once per session:
#   Opened PlayFab server   /   Opened Steam server
# Whichever appears LAST is the current session's backend.
function getWorldNetBackend($world) {
        $tail = phvCurrentSessionTail($world);
        if ($tail === NULL) { return NULL; }

        $playfab = strrpos($tail, 'Opened PlayFab server');
        $steam   = strrpos($tail, 'Opened Steam server');

        if ($playfab === false && $steam === false) { return NULL; }
        if ($playfab === false) { return 'steam'; }
        if ($steam === false)   { return 'playfab'; }
        return $playfab > $steam ? 'playfab' : 'steam';
}

# Everything the CURRENT session has logged.
#
# Valheim takes ~30 seconds from process start to "Opened <backend> server" -- it loads the
# world first. Searching the whole tail in that window finds the PREVIOUS session's line, so a
# world restarted into crossplay kept reporting `steam` and the card kept offering a +connect
# link that could not work. Same hazard for the join code: a restarted world would advertise
# the code from its last session, which is already dead.
#
# The session boundary is Valheim's own scene load, logged once per start:
#   09/10/2026 16:52:01: Loading: Starting to load scene: start.unity (...)
#
# If the marker is not in the window the world has been up a long time, and any Opened line
# still visible must belong to the current session anyway -- so the whole tail is the right
# answer there, not an error.
function phvCurrentSessionTail($world) {
        $tail = phvReadLogTail($world);
        if ($tail === NULL) { return NULL; }

        $pos = strrpos($tail, 'Starting to load scene: start.unity');
        return $pos === false ? $tail : substr($tail, $pos);
}

# Is this world REACHED by join code right now?
#
# Prefers what the running session actually logged; falls back to the crossplay column when the
# session has not said yet. That fallback is what makes a restart look instant instead of
# spending ~30 seconds advertising the wrong join method:
#
#   toggled but NOT restarted  -> the running session logged its backend; that wins, and it is
#                                 correctly the OLD one, because that is what is serving.
#   restarted, still loading   -> nothing logged yet; the column is the best available answer
#                                 and it is the one the world is about to come up as.
#   up for a long time         -> the Opened line has scrolled out of the tail; the column is
#                                 right, and before this the card silently fell back to
#                                 +connect on a crossplay world.
function worldIsPlayFab($pdo, $world, $isOnline = true) {
        $backend = getWorldNetBackend($world);
        if ($backend !== NULL) { return $backend === 'playfab'; }
        # No backend line yet -- Valheim logs it about 30 seconds after the process starts.
        # Fall back to what the world was STARTED with, not to the saved column: the column can
        # have been changed since, and answering from it is how the Launch link came to offer a
        # join code for a server that was still serving Steam.
        $opts = effectiveWorldOptions($pdo, $world, $isOnline);
        return ((int)$opts['crossplay'] === 1);
}

/* ---------------------------------------------------------------------------------------
   Running options vs saved options.

   The database holds the operator's INTENT. It can be edited while a world is up, and those
   edits do not reach Valheim until the world restarts. startWorld.sh writes what it actually
   launched with to <worldDir>/.running-options.

   Describing a LIVE world reads the file; describing a stopped one reads the database, because
   then there is nothing running to contradict it. That single rule is what keeps a card
   self-consistent: before this, the public page drew its CROSSPLAY pill from the database (so
   it appeared the moment the option was saved) while the Launch link followed the running
   server (so it stayed a direct-connect link) -- a pill promising a crossplay world the server
   was not serving.
   --------------------------------------------------------------------------------------- */

# What startWorld.sh recorded at launch, or NULL if it never got the chance.
function runningWorldOptions($world) {
        $path = "/opt/stateful/games/valheim/worlds/" . $world . "/.running-options";
        if (!is_readable($path)) { return NULL; }
        $raw = @file_get_contents($path);
        if ($raw === false) { return NULL; }

        $out = [];
        foreach (explode("\n", $raw) as $line) {
                $line = trim($line);
                if ($line === '' || strpos($line, '=') === false) { continue; }
                list($k, $v) = explode('=', $line, 2);
                $out[trim($k)] = trim($v);
        }
        # A file missing the keys we rely on is worse than no file: it would silently report
        # crossplay=0 for a world that is serving PlayFab. Treat it as absent.
        foreach (['vanilla', 'crossplay', 'listed'] as $required) {
                if (!array_key_exists($required, $out)) { return NULL; }
        }
        return [
                'vanilla'      => (int)$out['vanilla'],
                'crossplay'    => (int)$out['crossplay'],
                'listed'       => (int)$out['listed'],
                'passwordhash' => isset($out['passwordhash']) ? $out['passwordhash'] : '',
        ];
}

# The same shape, built from the database: what the world would use if it started now.
function savedWorldOptions($pdo, $world) {
        $sth = $pdo->prepare("SELECT IFNULL(vanilla,0) AS vanilla, IFNULL(crossplay,0) AS crossplay,
                                     IFNULL(listed,0) AS listed, IFNULL(password,'') AS password
                              FROM worlds WHERE name=?");
        $sth->execute([$world]);
        $row = $sth->fetch(PDO::FETCH_ASSOC);
        if (!$row) { return ['vanilla' => 0, 'crossplay' => 0, 'listed' => 0, 'passwordhash' => '']; }

        # Mirror startWorld.sh's gates, or a modded world with a stale crossplay flag would read
        # as "restart pending" forever: the flag is kept as a preference but never applied.
        $vanilla = (int)$row['vanilla'];
        return [
                'vanilla'      => $vanilla,
                'crossplay'    => ($vanilla === 1 && (int)$row['crossplay'] === 1) ? 1 : 0,
                'listed'       => ($vanilla === 1) ? (int)$row['listed'] : 0,
                'passwordhash' => ($vanilla === 1 && $row['password'] !== '')
                                  ? hash('sha256', $row['password']) : '',
        ];
}

# What to DESCRIBE the world as right now.
function effectiveWorldOptions($pdo, $world, $isOnline) {
        if ($isOnline) {
                $running = runningWorldOptions($world);
                if ($running !== NULL) { return $running; }
        }
        return savedWorldOptions($pdo, $world);
}

# Saved options that are waiting on a restart. Returns the list of changed keys, so the UI can
# say WHICH setting is pending rather than just that something is.
function worldRestartPending($pdo, $world, $isOnline) {
        if (!$isOnline) { return []; }
        $running = runningWorldOptions($world);
        if ($running === NULL) { return []; }

        $saved = savedWorldOptions($pdo, $world);
        $labels = [
                'vanilla'      => 'world type',
                'crossplay'    => 'crossplay',
                'listed'       => 'server browser listing',
                'passwordhash' => 'password',
        ];
        $changed = [];
        foreach ($labels as $key => $label) {
                if ((string)$running[$key] !== (string)$saved[$key]) { $changed[] = $label; }
        }
        return $changed;
}

# Shared tail reader for the two log-derived getters below. World logs reach hundreds of MB
# and everything we want is near the end.
function phvReadLogTail($world, $window = 262144) {
        $log = "/opt/stateful/logs/valheimworld_" . $world . ".log";
        if (!is_readable($log)) { return NULL; }

        $size = @filesize($log);
        if ($size === false) { return NULL; }

        $fh = @fopen($log, 'rb');
        if (!$fh) { return NULL; }
        if ($size > $window) { fseek($fh, -$window, SEEK_END); }
        $tail = stream_get_contents($fh);
        fclose($fh);
        if ($tail === false) { return NULL; }

        # World logs carry NUL bytes from torn writes. They break nothing here, but strip them
        # so a match cannot be split across one.
        return str_replace("\0", '', $tail);
}

# The PlayFab join code for a CROSSPLAY world.
#
# A crossplay server does not accept a direct IP connection at all: Valheim opens a PlayFab
# server instead of a Steam one, registers a lobby, and players reach it by join code. The
# server prints that code once per session:
#
#   Session "BayArea" registered with join code 441944
#
# DERIVED, never stored. The code is issued per session, so a world that restarts gets a new
# one -- a cached copy in the database would keep advertising a code that no longer works,
# with nothing to say it had gone stale.
#
# Reads only the tail of the log. These files reach hundreds of MB, and the current session's
# line is always near the end. The LAST match wins, because a restart appends a newer code
# above nothing.
function getWorldJoinCode($world) {
        # CURRENT session only. The code is reissued on every restart, so the last match in the
        # whole tail can be the previous session's -- a code that no longer works, handed out
        # with no indication it is stale.
        $tail = phvCurrentSessionTail($world);
        if ($tail === NULL) { return NULL; }

        if (preg_match_all('/registered with join code (\d{4,10})/', $tail, $m)) {
                return end($m[1]);
        }
        return NULL;
}

# How a VANILLA world is actually joined, as one answer for every caller.
#
# Enabling crossplay makes Valheim open a PlayFab server instead of a Steam one. A PlayFab
# server is reached by join code and cannot be joined by IP at all, so the usual
# `+connect host:port` asks for a direct connection it never offers -- the link fails
# silently while the in-game browser works fine.
#
# This exists because the SAME decision is made in four places: the public card, the public
# api.php poll, the admin dashboard's PHP render, and the admin poll payload. The admin pair
# were left on an unconditional +connect and so ignored crossplay entirely.
#
# The two ADMIN callers now share this function and cannot drift apart. The two PUBLIC callers
# still carry their own equivalent copy: they work, they are pinned by the 30 assertions in
# test-vanilla-joincode.php, and rewriting a verified path while fixing a different bug is how
# a working feature gets broken. Folding them in here is worth doing on its own.
#
# Follows the RUNNING backend rather than the `crossplay` column: toggling the column takes
# effect only at the next restart, and in that window the column is simply wrong about how
# players can reach the world.
#
# $isOnline gates it because the backend is read from the world's log -- a stopped world has no
# current session to report, and there is no point reading the log for one.
#
# Returns ['href' => string|NULL, 'playfab' => bool, 'joinCode' => string|NULL].
# href is NULL when the world is offline, or when it is a crossplay world whose lobby has not
# registered a code yet -- callers should show a non-link state rather than a link with an
# empty argument.
function getVanillaJoinInfo($pdo, $world, $gameDNS, $port, $isOnline) {
        if (!$isOnline) {
                return ['href' => NULL, 'playfab' => false, 'joinCode' => NULL];
        }

        if (!worldIsPlayFab($pdo, $world, $isOnline)) {
                return [
                        'href'     => 'steam://run/892970//+connect ' . $gameDNS . ':' . $port,
                        'playfab'  => false,
                        'joinCode' => NULL,
                ];
        }

        # A crossplay world has NO launch URL. -joincode is a real Valheim argument, but
        # decompiling FejdStartup shows it registers AutoJoinServer(), which resolves the code
        # and calls JoinServer() directly -- it never calls SelectCharacter(). The client then
        # joins with no character selected and falls back to its built-in developer profile, so
        # players landed in the world as "Odev (Developer)" and gained a character they never
        # made. There is no argument that stops at character selection, and
        # -joinserverwithcharacter takes a DEDICATED server address, which a PlayFab-hosted
        # world does not have. Hand back the code only; the UI explains how to use it.
        $code = getWorldJoinCode($world);
        return [
                'href'     => NULL,
                'playfab'  => true,
                'joinCode' => $code,
        ];
}

# NOTE: `listed` is the Steam server-browser flag (Valheim's -public argument).
# It is NOT the same as `public` -- see getPublic() below, which is the CITIZENS
# access-control flag. Conflating them would list every open world publicly.
function getListed($pdo,$world) {
        $sth = $pdo->prepare("SELECT listed FROM worlds WHERE name=?");
        $sth->execute([$world]);
        return $sth->fetchColumn();
}

# Is the password allowed to appear on the public world card?
function getPasswordPublic($pdo,$world) {
        $sth = $pdo->prepare("SELECT password_public FROM worlds WHERE name=?");
        $sth->execute([$world]);
        return $sth->fetchColumn();
}

function getPort($pdo,$world) {
        $sth = $pdo->prepare("SELECT port FROM worlds WHERE name=?");
        $sth->execute([$world]);
        return $sth->fetchColumn();
}

function getLaunchParams($pdo,$world) {
        $sth = $pdo->prepare("SELECT launch_params FROM worlds WHERE name=?");
        $sth->execute([$world]);
        return $sth->fetchColumn();
}

function getAdmins($pdo,$world) {
        $sth = $pdo->prepare("SELECT admins FROM worlds WHERE name=?");
        $sth->execute([$world]);
        return $sth->fetchColumn();
}

function getBanned($pdo,$world) {
        $sth = $pdo->prepare("SELECT banned FROM worlds WHERE name=?");
        $sth->execute([$world]);
        return $sth->fetchColumn();
}

function getBossTrophyStatus($pdo,$world,$trophy) {
	$sth = $pdo->query("SELECT $trophy FROM worlds WHERE name='$world';");
        $sth->execute();
        $result = $sth->fetchColumn();
        return $result;
}

function getCpuModel($pdo) {
        $sth = $pdo->prepare("SELECT cpuModel FROM systemstats LIMIT 1;");
        $sth->execute();
        $result = $sth->fetchColumn();
        if(!empty($result)) {
                return $result;
        } else {
                return "—";
        }
}
function getLastWorldBackupExecTime($pdo) {
        $sth = $pdo->prepare("SELECT worldBackupLastRun FROM systemstats LIMIT 1;");
        $sth->execute();
	$result = $sth->fetchColumn();

        $timezone = date('T');

        if(!empty($result)) {
		$result = "$result $timezone";
                return $result;		
        } else {
                return "pending first execution...";
        }
}

function getLastLogRotateExecTime($pdo) {
        $sth = $pdo->prepare("SELECT logRotaterLastRun FROM systemstats LIMIT 1;");
        $sth->execute();
	$result = $sth->fetchColumn();

        $timezone = date('T');

        if(!empty($result)) {
		$result = "$result $timezone";
                return $result;		
        } else {
                return "pending first execution...";
        }
}

function getLastUtilizationMonitorExecTime($pdo) {
        $sth = $pdo->prepare("SELECT utilizationMonitorLastRun FROM systemstats LIMIT 1;");
        $sth->execute();
	$result = $sth->fetchColumn();

        $timezone = date('T');

        if(!empty($result)) {
		$result = "$result $timezone";
		return $result;
        } else {
                return "pending first execution...";
        }
}
function getLastWorldBackupExecStatus($pdo) {
        $sth = $pdo->prepare("SELECT worldBackupLastExecStatus FROM systemstats LIMIT 1;");
        $sth->execute();
        $result = $sth->fetchColumn();
        return $result;
}

function getLastLogRotateExecStatus($pdo) {
        $sth = $pdo->prepare("SELECT logRotateLastExecStatus FROM systemstats LIMIT 1;");
        $sth->execute();
        $result = $sth->fetchColumn();
        return $result;
}

function getLastUtilizationMonitorExecStatus($pdo) {
        $sth = $pdo->prepare("SELECT utilizationMonitorLastExecStatus FROM systemstats LIMIT 1;");
        $sth->execute();
        $result = $sth->fetchColumn();
        return $result;
}

function getTotalDisk($path) {
	$result = exec("df -h $path|tail -1|tr -s ' '|cut -d ' ' -f2");
        return $result;
}

function getUsedDisk($path) {
        $result = exec("df -h $path|tail -1|tr -s ' '|cut -d ' ' -f3");
        return $result;
}

function getFreeDisk($path) {
        $result = exec("df -h $path|tail -1|tr -s ' '|cut -d ' ' -f4");
        return $result;
}

function getUsedDiskPerc($path) {
        $result = exec("df -h $path|tail -1|tr -s ' '|cut -d ' ' -f5");
        return $result;
}

function getTotalMemory() {
        $result = exec("free -h --giga|grep Mem:|tr -s ' '|cut -d ' ' -f2");
        return $result;
}

function getUsedMemory() {
        $result = exec("free -h --giga|grep Mem:|tr -s ' '|cut -d ' ' -f3");
        return $result;
}

function getFreeMemory() {
        $result = exec("free -h --giga|grep Mem:|tr -s ' '|cut -d ' ' -f7");
        return $result;
}

function getWorldBackups($pdo, $worldName) {
        $stmt = $pdo->prepare("SELECT id, world_name, created_at, type, file_path, file_size, uncompressed_size, compressed, compression_type, metadata, orphaned FROM backups WHERE world_name = ? ORDER BY created_at DESC");
        $stmt->execute([$worldName]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

function getBackupById($pdo, $backupId) {
        $stmt = $pdo->prepare("SELECT id, world_name, created_at, type, file_path, file_size, uncompressed_size, compressed, compression_type, metadata FROM backups WHERE id = ?");
        $stmt->execute([$backupId]);
        return $stmt->fetch(PDO::FETCH_ASSOC);
}

function getWorldBackupSettings($pdo, $worldName) {
        $stmt = $pdo->prepare("SELECT backup_use_global, backup_interval_minutes, backup_require_activity, backup_retain_all_hours, backup_retain_daily_days, backup_retain_weekly_days, backup_retain_monthly_months, backup_compression, backup_compression_hour, backup_cpu_priority, backup_io_priority, backup_compression_level, last_player_activity, last_backup_time FROM worlds WHERE name = ?");
        $stmt->execute([$worldName]);
        return $stmt->fetch(PDO::FETCH_ASSOC);
}

function getWorldBackupCount($pdo, $worldName) {
        $stmt = $pdo->prepare("SELECT COUNT(*) FROM backups WHERE world_name = ?");
        $stmt->execute([$worldName]);
        return (int)$stmt->fetchColumn();
}

function getWorldBackupTotalSize($pdo, $worldName) {
        $stmt = $pdo->prepare("SELECT COALESCE(SUM(file_size), 0) FROM backups WHERE world_name = ?");
        $stmt->execute([$worldName]);
        return (int)$stmt->fetchColumn();
}

function getTotalBackupCount($pdo) {
        $stmt = $pdo->query("SELECT COUNT(*) FROM backups");
        return (int)$stmt->fetchColumn();
}

function getTotalBackupSize($pdo) {
        $stmt = $pdo->query("SELECT COALESCE(SUM(file_size), 0) FROM backups");
        return (int)$stmt->fetchColumn();
}

function isBackupPathMounted() {
        // Is /opt/stateful/backups a separate mount point (bind mount or distinct volume)?
        //
        // Ask the mount table, never device identity: on Unraid every /mnt/user share
        // is the same FUSE 'shfs' device, so comparing devices marks a properly
        // mounted backup volume as shared.
        //
        // Must agree with isBackupPathMounted() in engine/includes/phvalheim-static.conf —
        // the admin UI and the backup scheduler have to reach the same verdict.
        $out = [];
        $ret = 0;
        exec("mountpoint -q /opt/stateful/backups 2>/dev/null", $out, $ret);
        if ($ret === 0) return true;

        // mountpoint missing (exit 127) — read the mount table directly
        if ($ret === 127) {
            foreach (@file('/proc/self/mountinfo', FILE_IGNORE_NEW_LINES) ?: [] as $line) {
                $f = explode(' ', $line);
                if (isset($f[4]) && $f[4] === '/opt/stateful/backups') return true;
            }
        }
        return false;
}

function getBackupDiskInfo() {
        $path = '/opt/stateful/backups';
        return [
            'total' => getTotalDisk($path),
            'used' => getUsedDisk($path),
            'free' => getFreeDisk($path),
            'perc' => getUsedDiskPerc($path),
        ];
}

function getVolumeStats() {
        $paths = [
            ['name' => 'Data', 'path' => '/opt/stateful', 'desc' => 'Worlds, database, logs, configs'],
            ['name' => 'Backups', 'path' => '/opt/stateful/backups', 'desc' => 'World backup archives'],
        ];

        // Detect which device each path is on to avoid duplicate entries
        $seen = [];
        $volumes = [];
        $backupMounted = isBackupPathMounted();

        foreach ($paths as $p) {
            if (!is_dir($p['path'])) continue;
            $device = trim(exec("df " . escapeshellarg($p['path']) . " 2>/dev/null | tail -1 | tr -s ' ' | cut -d' ' -f1"));
            // If backups are on the same device as data, merge them
            if ($p['name'] === 'Backups' && !$backupMounted) continue;
            if (isset($seen[$device]) && $p['name'] !== 'Backups') continue;
            $seen[$device] = true;

            $total = disk_total_space($p['path']);
            $free = disk_free_space($p['path']);
            $used = $total - $free;
            $perc = $total > 0 ? round(($used / $total) * 100) : 0;
            $volumes[] = [
                'name' => $p['name'],
                'path' => $p['path'],
                'desc' => $p['desc'],
                'device' => $device,
                'total' => $total,
                'used' => $used,
                'free' => $free,
                'perc' => $perc,
                'totalH' => getTotalDisk($p['path']),
                'usedH' => getUsedDisk($p['path']),
                'freeH' => getFreeDisk($p['path']),
            ];
        }

        // If backups are on the same device, annotate the data volume
        if (!$backupMounted) {
            if (!empty($volumes)) $volumes[0]['desc'] .= ' (+ backups, shared)';
        }

        return $volumes;
}

function getWorldMode($pdo, $worldName) {
        $stmt = $pdo->prepare("SELECT mode FROM worlds WHERE name = ?");
        $stmt->execute([$worldName]);
        return $stmt->fetchColumn() ?: 'unknown';
}

function isWorldTransitional($mode) {
        return in_array($mode, ['start', 'starting', 'stop', 'stopping', 'create', 'creating', 'update', 'updating', 'delete', 'deleting', 'backup']);
}

function getWorldDirSize($worldName) {
        $path = '/opt/stateful/games/valheim/worlds/' . basename($worldName);
        if (!is_dir($path)) return 0;
        $size = trim(exec("du -sb " . escapeshellarg($path) . " --exclude=" . escapeshellarg($worldName . ".zip") . " 2>/dev/null | cut -f1"));
        return (int)$size;
}

function getBackupDiskFreeBytes() {
        $free = disk_free_space('/opt/stateful/backups');
        return $free !== false ? (int)$free : 0;
}

function getOrphanedBackupCount($pdo) {
        $stmt = $pdo->query("SELECT COUNT(*) FROM backups WHERE orphaned = 1");
        return (int)$stmt->fetchColumn();
}

function getOrphanedBackups($pdo) {
        $stmt = $pdo->query("SELECT id, world_name, created_at, type, file_path, file_size, compressed, compression_type, metadata FROM backups WHERE orphaned = 1 ORDER BY created_at DESC");
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

function purgeOrphanedBackups($pdo) {
        $stmt = $pdo->query("SELECT id, file_path FROM backups WHERE orphaned = 1");
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
        $count = 0;
        foreach ($rows as $row) {
            // Double-check file is truly missing before deleting record
            if (!empty($row['file_path']) && file_exists($row['file_path'])) {
                // File reappeared — clear orphan flag instead of deleting
                $update = $pdo->prepare("UPDATE backups SET orphaned = 0 WHERE id = ?");
                $update->execute([$row['id']]);
            } else {
                $del = $pdo->prepare("DELETE FROM backups WHERE id = ?");
                $del->execute([$row['id']]);
                $count++;
            }
        }
        return $count;
}

function getCpuUtilization($pdo) {
        $sth = $pdo->prepare("SELECT currentCpuUtilization FROM systemstats LIMIT 1;");
        $sth->execute();
        $result = $sth->fetchColumn();
        if(!empty($result) || $result === '0' || $result === 0) {
                return $result . "%";
        } else {
                return "—";
        }
}

?>
