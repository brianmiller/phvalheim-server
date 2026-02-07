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

function getLaunchString($pdo,$world,$gameDNS,$phvalheimHost,$httpScheme) {
        $getWorldData = $pdo->query("SELECT status,name,port FROM worlds WHERE name='$world'");
        foreach($getWorldData as $row)
        {
                $status = $row['status'];
                $world = $row['name'];
                $port = $row['port'];
                $password = "hammertime";
                #$password = $row['password'];
                $launchString = base64_encode("launch?$world?$password?$gameDNS?$port?$phvalheimHost?$httpScheme");

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

	$timezone = exec('date +%Z');

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

        $timezone = exec('date +%Z');

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

        $timezone = exec('date +%Z');

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

        $timezone = exec('date +%Z');

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

        $timezone = exec('date +%Z');

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

        $timezone = exec('date +%Z');

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
