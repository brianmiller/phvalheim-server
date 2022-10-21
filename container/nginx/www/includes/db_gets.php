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
        $sth = $pdo->query("SELECT DISTINCT moduuid,name,owner FROM tsmods ORDER BY name;");
        $result = $sth->fetchAll(PDO::FETCH_ASSOC);
        return $result;
}

function getSeed($pdo,$world) {
	$sth = $pdo->prepare("SELECT seed FROM worlds WHERE name='$world'");
	$sth->execute();
	$result = $sth->fetchColumn();
	return $result;
}

function getMyWorlds($pdo,$citizen) {
        $sth = $pdo->query("SELECT name FROM worlds WHERE citizens LIKE '%$citizen%'");
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
	$sth = $pdo->prepare("SELECT thunderstore_mods FROM worlds WHERE thunderstore_mods LIKE '%$modUUID%' AND name='$world';");
	$sth->execute();
	$result = $sth->fetchColumn();
	return $result;
}

function getLaunchString($pdo,$world,$gameDNS,$phvalheimHost) {
        $getWorldData = $pdo->query("SELECT status,name,port FROM worlds WHERE name='$world'");
        foreach($getWorldData as $row)
        {
                $status = $row['status'];
                $world = $row['name'];
                $port = $row['port'];
                $password = "hammertime";
                #$password = $row['password'];
                $launchString = base64_encode("launch?$world?$password?$gameDNS?$port?$phvalheimHost");

		return $launchString;
	}
}

function getCitizens($pdo,$world) {
        $sth = $pdo->query("SELECT citizens FROM worlds WHERE name='$world';");
	$sth->execute();
	$result = $sth->fetchColumn();
        return $result;
}
?>
