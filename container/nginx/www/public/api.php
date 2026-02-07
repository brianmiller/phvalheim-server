<?php

include '/opt/stateless/nginx/www/includes/config_env_puller.php';
include '/opt/stateless/nginx/www/includes/phvalheim-frontend-config.php';
include '../includes/db_gets.php';
include '../includes/db_sets.php';
include '/opt/stateless/nginx/www/includes/session_auth.php';


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

# Get worlds status for a steamID (for AJAX polling)
if ($mode == "getMyWorldsStatus") {
    header('Content-Type: application/json');
    header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');

    // Prefer session, fall back to GET param for backward compatibility
    $steamID = getSessionSteamID();
    if (empty($steamID) && isset($_GET['steamID'])) {
        $steamID = $_GET['steamID'];
    }

    if (empty($steamID)) {
        echo json_encode(['success' => false, 'error' => 'Missing steamID']);
        exit;
    }

    // HTTP(S) detector
    if (isset($_SERVER['HTTP_X_FORWARDED_PROTO']) && $_SERVER['HTTP_X_FORWARDED_PROTO'] == "https") {
        $httpScheme = "https";
    } else {
        $httpScheme = "http";
    }

    // Helper function to check if world process is running for real-time detection
    function isWorldRunning($worldName) {
        // Use pgrep to check if valheim_server process is running for this world
        // Match on "-name worldname " to avoid substring matches (foo matching foo3)
        // Use [n] character class to prevent pgrep from matching its own shell process
        $output = shell_exec("pgrep -f -- " . escapeshellarg("-[n]ame " . $worldName . " ") . " 2>&1");
        return (!empty(trim($output)));
    }

    $getMyWorlds = getMyWorlds($pdo, $steamID);
    $worldsData = [];

    if (!empty($getMyWorlds)) {
        foreach ($getMyWorlds as $myWorld) {
            $launchString = getLaunchString($pdo, $myWorld, $gameDNS, $phvalheimHost, $httpScheme);
            // Check supervisor directly for real-time status instead of cached DB value
            $isOnline = isWorldRunning($myWorld);
            $worldMemory = $isOnline ? getWorldMemory($pdo, $myWorld) : "offline";

            // Get mods list for tooltip
            $modsJson = getModViewerJsonForWorld($pdo, $myWorld);
            $modsArray = json_decode($modsJson, true) ?? [];
            $mods = [];
            foreach ($modsArray as $mod) {
                if (!empty($mod['name'])) {
                    $mods[] = ['name' => $mod['name'], 'url' => $mod['url']];
                }
            }

            $worldsData[] = [
                'name' => $myWorld,
                'online' => $isOnline,
                'memory' => $worldMemory,
                'launchString' => $launchString,
                'md5' => getMD5($pdo, $myWorld),
                'seed' => (getHideSeed($pdo, $myWorld) == 1) ? '<i>hidden</i>' : getSeed($pdo, $myWorld),
                'dateDeployed' => getDateDeployed($pdo, $myWorld),
                'dateUpdated' => getDateUpdated($pdo, $myWorld),
                'mods' => $mods,
                'trophies' => [
                    'eikthyr' => getBossTrophyStatus($pdo, $myWorld, "trophyeikthyr"),
                    'theElder' => getBossTrophyStatus($pdo, $myWorld, "trophytheelder"),
                    'bonemass' => getBossTrophyStatus($pdo, $myWorld, "trophybonemass"),
                    'dragonQueen' => getBossTrophyStatus($pdo, $myWorld, "trophydragonqueen"),
                    'goblinKing' => getBossTrophyStatus($pdo, $myWorld, "trophygoblinking"),
                    'seekerQueen' => getBossTrophyStatus($pdo, $myWorld, "trophyseekerqueen"),
                    'fader' => getBossTrophyStatus($pdo, $myWorld, "trophyfader")
                ]
            ];
        }
    }

    echo json_encode(['success' => true, 'worlds' => $worldsData]);
    exit;
}

