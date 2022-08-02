<?php

include 'config.php';

#Get next available port. Returns the next available port(int).
#function getNextPort($pdo, $basePort){
#	$sth = $pdo->prepare("SELECT port FROM worlds");
#	$sth->execute();
#	$portExcludes = $sth->fetchAll(PDO::FETCH_COLUMN);
#	$port = $basePort;

#	$in_array = in_array($port,$portExcludes,true);
#	while ($in_array){
#		$port++;
#		$in_array = array_search($port,$portExcludes,true);
#	}

#	return $port;
#}

#$getNextPort = getNextPort($pdo,$basePort);

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

function getMD5($pdo,$world) {
        $sth = $pdo->prepare("SELECT world_md5 FROM worlds WHERE name='$world'");
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

#function worldModeCheck($pdo,$world) {
#        $sth = $pdo->prepare("SELECT mode FROM worlds WHERE name='$world';");
#        $sth->execute();
#        $result = $sth->fetchColumn();
#        return $result;
#}
?>
