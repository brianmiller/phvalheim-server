<?php

include '/opt/stateless/nginx/www/includes/config_env_puller.php';
include '/opt/stateless/nginx/www/includes/phvalheim-frontend-config.php';
include '../includes/db_gets.php';
include '../includes/db_sets.php';


$mode = NULL;
$world = NULL;
$action = NULL;


############## BEGIN: API input detectors ##############

if (!empty($_GET['mode'])) {
	$mode = $_GET['mode'];
}

if (!empty($_GET['world'])) {
        $world = $_GET['world'];
}

$jsonIncoming = json_decode(file_get_contents("php://input"));

############## END: API input detectors ##############


############## BEGIN: Mode detectors ##############

# getMD5 of world
if ($mode == "getMD5") {
	print getMD5($pdo,$world);
}

if ($jsonIncoming->action) {
	$action = $jsonIncoming->action;
}

if ($jsonIncoming->world) {
        $world = $jsonIncoming->world;
}

############## END: Mode detectors ##############


if($action == "TrophyEikthyr"    || 
   $action == "TrophyTheElder"   || 
   $action == "TrophyBonemass"   ||
   $action == "TrophyDragonQueen"||
   $action == "TrophyGoblinKing" ||
   $action == "TrophySeekerQueen" ||
   $action == "TrophyFader") {
	if(setHungHeads($pdo,$world,$action)) {
		print "true";
	} else {
		print "false";
	}
}

