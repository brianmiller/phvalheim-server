<?php

include '/opt/stateless/nginx/www/includes/config_env_puller.php';
include '/opt/stateless/nginx/www/includes/phvalheim-frontend-config.php';


function getAllModUUIDs($pdo) {
	$sth = $pdo->query("SELECT moduuid FROM tsmods;");
	$result = $sth->fetchAll(PDO::FETCH_COLUMN);
	$result = array_unique($result);
	return $result;
}

function getAllModVersionUUIDs($pdo) {
        $sth = $pdo->query("SELECT versionuuid FROM tsmods;");
        $result = $sth->fetchAll(PDO::FETCH_COLUMN);
        $result = array_unique($result);
        return $result;
}

function getAllMods($pdo) {
        $sth = $pdo->query("SELECT DISTINCT(name),moduuid,owner,url FROM tsmods ORDER BY name");
        $result = $sth->fetchAll(PDO::FETCH_ASSOC);
        return $result;
}

function getModViewerJsonForWorld($pdo,$world) {
        $sth = $pdo->prepare("SELECT modsViewer FROM worlds WHERE name='$world'");
        $sth->execute();
        $result = $sth->fetchColumn();
        return $result;
}

function getAllModsLatestVersion($pdo) {
        $sth = $pdo->query("
		SELECT t1.name,t1.version,t1.moduuid,t1.owner,t1.url,t1.version_date_created,t1.deps
			FROM tsmods AS t1
			LEFT OUTER JOIN tsmods AS t2
			  ON t1.moduuid = t2.moduuid
			        AND (t1.version_date_created < t2.version_date_created
			         OR (t1.version_date_created = t2.version_date_created
			        AND t1.Id < t2.Id))
			WHERE t2.moduuid IS NULL ORDER BY name
		");

        $result = $sth->fetchAll(PDO::FETCH_ASSOC);
        return $result;
}

function resolveDepStringToUuid($depString, $ownerNameLookup) {
        // Parse "Owner-Name-Version" -> owner, name
        // Format: "Owner/Name-Version" e.g. "BepInEx/BepInExPack_Valheim-5.4.2200"
        // But some use "Owner-Name-Version" without slash
        if (strpos($depString, '/') !== false) {
                // Standard format: Owner/Name-Version
                $parts = explode('/', $depString, 2);
                $owner = $parts[0];
                $rest = $parts[1];
                // Name is everything before the last dash (version)
                $lastDash = strrpos($rest, '-');
                if ($lastDash !== false) {
                        $name = substr($rest, 0, $lastDash);
                } else {
                        $name = $rest;
                }
        } else {
                // Fallback: Owner-Name-Version (older format)
                $parts = explode('-', $depString);
                if (count($parts) >= 3) {
                        $owner = $parts[0];
                        $name = $parts[1];
                } else {
                        return null;
                }
        }

        $key = strtolower($owner . '/' . $name);
        return $ownerNameLookup[$key] ?? null;
}

function buildOwnerNameLookup($allMods) {
        $lookup = [];
        foreach ($allMods as $mod) {
                $key = strtolower($mod['owner'] . '/' . $mod['name']);
                $lookup[$key] = $mod['moduuid'];
        }
        return $lookup;
}

function resolveModDeps($depsRaw, $ownerNameLookup) {
        if (empty($depsRaw)) return [];

        // Clean up the deps JSON string
        $depsRaw = trim($depsRaw);
        $deps = json_decode($depsRaw, true);
        if (!is_array($deps)) {
                // Try manual parsing for non-standard format
                $depsRaw = str_replace(['"', '[', ']', "\n"], '', $depsRaw);
                $deps = array_filter(array_map('trim', explode(',', $depsRaw)));
        }

        $resolvedUuids = [];
        foreach ($deps as $depString) {
                $depString = trim($depString);
                if (empty($depString)) continue;
                $uuid = resolveDepStringToUuid($depString, $ownerNameLookup);
                if ($uuid) {
                        $resolvedUuids[] = $uuid;
                }
        }
        return $resolvedUuids;
}

function getWorldSelectedMods($pdo, $world) {
        $sth = $pdo->query("SELECT thunderstore_mods FROM worlds WHERE name='$world'");
        $sth->execute();
        $result = $sth->fetchColumn();
        if (empty($result)) return [];
        $mods = array_filter(explode(' ', $result), function($m) {
                return !empty($m) && $m !== 'placeholder';
        });
        return array_values($mods);
}

function getWorldDepMods($pdo, $world) {
        $sth = $pdo->query("SELECT thunderstore_mods_deps FROM worlds WHERE name='$world'");
        $sth->execute();
        $result = $sth->fetchColumn();
        if (empty($result)) return [];
        $mods = array_filter(explode(' ', $result), function($m) {
                return !empty($m) && $m !== 'placeholder';
        });
        return array_values($mods);
}

function getModNameByUuid($pdo,$modUUID) {
        $sth = $pdo->query("SELECT name FROM tsmods WHERE moduuid='$modUUID'");
        $sth->execute();
        $result = $sth->fetchColumn();
        return $result;
}

function getModUrlByUuid($pdo,$modUUID) {
        $sth = $pdo->query("SELECT url FROM tsmods WHERE moduuid='$modUUID'");
        $sth->execute();
        $result = $sth->fetchColumn();
        return $result;
}

function modSelectedCheck($pdo,$world,$modUUID) {
        $sth = $pdo->query("SELECT thunderstore_mods_deps FROM worlds WHERE name='$world' AND thunderstore_mods_deps LIKE '%$modUUID%'");
        $sth->execute();
        $result = $sth->fetchColumn();
        if ($result) {
                return false;
        }

	$sth = $pdo->query("SELECT thunderstore_mods FROM worlds WHERE name='$world' AND thunderstore_mods LIKE '%$modUUID%'");
        $sth->execute();
        $result = $sth->fetchColumn();
        if ($result) {
                return true;
	} else {
		return false;
	}
}

function modIsDep($pdo,$world,$modUUID) {
        $sth = $pdo->query("SELECT thunderstore_mods_deps FROM worlds WHERE name='$world' AND thunderstore_mods_deps LIKE '%$modUUID%'");
        $sth->execute();
        $result = $sth->fetchColumn();
	if ($result) {
		return true;
	} else {
		return false;
	}

}

function getAllWorldMods($pdo,$world) {
        $sth = $pdo->query("SELECT thunderstore_mods FROM worlds WHERE name='$world'");
        $sth->execute();
        $selected = $sth->fetchColumn();

        $sth = $pdo->query("SELECT thunderstore_mods_deps FROM worlds WHERE name='$world'");
        $sth->execute();
        $deps = $sth->fetchColumn();

        $all = $selected . ' ' . $deps;
	$all = explode(' ', $all);

        return $all;
}

function getSelectedModCountOfWorld($pdo,$world) {
        $sth = $pdo->query("SELECT thunderstore_mods FROM worlds WHERE name='$world'");
	$sth->execute();
	$result = $sth->fetchColumn();

	// Filter out placeholder entries
	$mods = explode(' ', $result);
	$mods = array_filter($mods, function($mod) {
		return !empty($mod) && $mod !== 'placeholder';
	});

	$modCount = count($mods);
	return $modCount;
}

function getTotalModCountOfWorld($pdo,$world) {
        $sth = $pdo->query("SELECT thunderstore_mods FROM worlds WHERE name='$world'");
        $sth->execute();
	$selected = $sth->fetchColumn();

        $sth = $pdo->query("SELECT thunderstore_mods_deps FROM worlds WHERE name='$world'");
        $sth->execute();
        $deps = $sth->fetchColumn();

	$all = $selected . ' ' . $deps;

	// Filter out placeholder entries and count valid mods
	$mods = explode(' ', $all);
	$mods = array_filter($mods, function($mod) {
		return !empty($mod) && $mod !== 'placeholder';
	});

	// Remove duplicates (deps may overlap with selected)
	$mods = array_unique($mods);

	$modCount = count($mods);
        return $modCount;
}

function getSeed($pdo,$world) {
	$sth = $pdo->prepare("SELECT seed FROM worlds WHERE name='$world'");
	$sth->execute();
	$result = $sth->fetchColumn();
	return $result;
}

function getMyWorlds($pdo,$citizen) {
        $sth = $pdo->query("SELECT name FROM worlds WHERE citizens LIKE '%$citizen%' OR public = '1' ORDER BY currentMemory, name ASC");
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

function modExistCheck($pdo,$world,$modUUID) {
	$sth = $pdo->prepare("SELECT thunderstore_mods FROM worlds WHERE thunderstore_mods_all LIKE '%$modUUID%' AND name='$world';");
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
        $log = "/opt/stateful/logs/valheimworld_" . $world . ".log";
        if (!is_readable($log)) { return NULL; }

        $size = @filesize($log);
        if ($size === false) { return NULL; }

        $fh = @fopen($log, 'rb');
        if (!$fh) { return NULL; }
        $window = 256 * 1024;
        if ($size > $window) { fseek($fh, -$window, SEEK_END); }
        $tail = stream_get_contents($fh);
        fclose($fh);
        if ($tail === false) { return NULL; }

        # World logs can carry NUL bytes from torn writes; they break nothing here, but strip
        # them so the match is not split across one.
        $tail = str_replace("\0", '', $tail);

        if (preg_match_all('/registered with join code (\d{4,10})/', $tail, $m)) {
                return end($m[1]);
        }
        return NULL;
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

function getLastTsUpdated($pdo) {
        $sth = $pdo->prepare("SELECT tsUpdated FROM systemstats LIMIT 1;");
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


function getLastTsLocalDiffExecTime($pdo) {
        $sth = $pdo->prepare("SELECT tsSyncLocalLastRun FROM systemstats LIMIT 1;");
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

function getLastTsRemoteDiffExecTime($pdo) {
        $sth = $pdo->prepare("SELECT tsSyncRemoteLastRun FROM systemstats LIMIT 1;");
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

function getLastTsSyncLocalExecStatus($pdo) {
        $sth = $pdo->prepare("SELECT tsSyncLocalLastExecStatus FROM systemstats LIMIT 1;");
        $sth->execute();
        $result = $sth->fetchColumn();

        // If status is 'running', verify the process is actually running
        if (strtolower($result) === 'running') {
                // Check if tsSyncLocalParseMultithreaded.sh is actually running
                $processCheck = trim(exec("pgrep -f tsSyncLocalParseMultithreaded.sh 2>/dev/null"));
                $pidFileCheck = trim(exec("ls /tmp/ts_*.pid 2>/dev/null | head -1"));

                // If no process running and no pid files, the status is stale
                if (empty($processCheck) && empty($pidFileCheck)) {
                        // Clean up orphan pid files and reset status
                        exec("rm -f /tmp/ts_*.pid 2>/dev/null");
                        $updateStmt = $pdo->prepare("UPDATE systemstats SET tsSyncLocalLastExecStatus='idle'");
                        $updateStmt->execute();
                        return 'idle';
                }
        }

        return $result;
}

function getLastTsSyncRemoteExecStatus($pdo) {
        $sth = $pdo->prepare("SELECT tsSyncRemoteLastExecStatus FROM systemstats LIMIT 1;");
        $sth->execute();
        $result = $sth->fetchColumn();
        return $result;
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
        // Check if /opt/stateful/backups is a separate mount point (bind mount or distinct volume)
        $ret = 0;
        exec("mountpoint -q /opt/stateful/backups 2>/dev/null", $out, $ret);
        return $ret === 0;
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
