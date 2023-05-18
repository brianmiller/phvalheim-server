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
		SELECT t1.name,t1.version,t1.moduuid,t1.owner,t1.url,t1.version_date_created
			FROM tsmods AS t1
			LEFT OUTER JOIN tsmods AS t2
			  ON t1.name = t2.name 
			        AND (t1.version_date_created < t2.version_date_created 
			         OR (t1.version_date_created = t2.version_date_created 
			        AND t1.Id < t2.Id))
			WHERE t2.name IS NULL ORDER BY name
		");

        $result = $sth->fetchAll(PDO::FETCH_ASSOC);
        return $result;
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
        $sth = $pdo->query("SELECT thunderstore_mods FROM worlds WHERE name='$world' AND thunderstore_mods LIKE '%$modUUID%'");
        $sth->execute();
        $result = $sth->fetchColumn();
        if ($result) {
                return true;
        }
}

function modIsDep($pdo,$world,$modUUID) {
        $sth = $pdo->query("SELECT thunderstore_mods_deps FROM worlds WHERE name='$world' AND thunderstore_mods_deps LIKE '%$modUUID%'");
        $sth->execute();
        $result = $sth->fetchColumn();
	if ($result) {
		return true;
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
	$modCount = substr_count($result, '-');
	$modCount = $modCount / 4;
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
	
        $modCount = substr_count($all, '-');
        $modCount = $modCount / 4;
        return $modCount;
}

function getSeed($pdo,$world) {
	$sth = $pdo->prepare("SELECT seed FROM worlds WHERE name='$world'");
	$sth->execute();
	$result = $sth->fetchColumn();
	return $result;
}

function getMyWorlds($pdo,$citizen) {
        $sth = $pdo->query("SELECT name FROM worlds WHERE citizens LIKE '%$citizen%' ORDER BY currentMemory, name ASC");
        $result = $sth->fetchAll(PDO::FETCH_COLUMN);
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
                return "pending first execution...";
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
        if(!empty($result)) {
                $result = "$result";
                return $result . "%";
        } else {
                return "pending first execution...";
        }
}

?>
