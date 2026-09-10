<?php

include '/opt/stateless/nginx/www/includes/config_env_puller.php';
include '/opt/stateless/nginx/www/includes/phvalheim-frontend-config.php';
include '../includes/db_gets.php';
include '../includes/db_sets.php';
# require_once with the SAME absolute path db_sets.php uses -- a relative include here
# would not dedupe against it and PHP would fatal on redeclaring the boss functions.
require_once '/opt/stateless/nginx/www/includes/bosses.php';
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


# Hung head reported by phvalheim-companion.
#
# bossColumnForPrefab() is the ONLY thing standing between the posted prefab name and a
# column name interpolated into SQL by setHungHeads(). Never pass $action through raw.
if ($action !== NULL) {
	$bossColumn = bossColumnForPrefab($action);

	if ($bossColumn !== NULL) {
		if (setHungHeads($pdo, $world, $bossColumn)) {
			print "true";
		} else {
			print "false";
		}
	} elseif (looksLikeBossPrefab($action)) {
		# A trophy we don't have registered yet -- almost certainly a new boss.
		# Log it so the first real kill tells us the prefab name without anyone
		# having to watch a companion console. See includes/bosses.php.
		logUnknownBoss($action, $world);
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

            $isVanilla = (getVanilla($pdo, $myWorld) == 1);

            // Boss progression comes from the companion mod, which a vanilla world does
            // not run. Send an empty set rather than seven zeroes -- "no data" and "all
            // seven bosses still alive" are different claims and the UI must not confuse
            // them. The vanilla card does not render a trophy row at all.
            $trophies = [];
            if (!$isVanilla) {
                foreach (getBossProgression($pdo, $myWorld) as $key => $boss) {
                    $trophies[$key] = $boss['defeated'] ? 1 : 0;
                }
            }

            // Vanilla worlds are joined with Valheim's own +connect, so the player needs
            // the endpoint and password in front of them. Only ever shown to a citizen of
            // that world -- getMyWorlds() has already scoped this loop to their worlds.
            $connection = NULL;
            if ($isVanilla) {
                $worldPort = getPort($pdo, $myWorld);
                // Honour the per-world password visibility flag here too. The card is
                // server-rendered, but this same payload drives the AJAX refresh -- omitting
                // the check would put the password back in a JSON response the admin has
                // explicitly said not to publish.
                $showPassword = (getPasswordPublic($pdo, $myWorld) != 0);
                # Resolved once: these read the world log, so calling them per array entry
                # would re-read the file for every field that mentions them.
                #
                # The join path follows the RUNNING backend, not the crossplay column --
                # -crossplay only applies at launch, so the two disagree until a toggled world
                # restarts, and in that window the column is wrong about how to reach it.
                $isCrossplayWorld  = (getCrossplay($pdo, $myWorld) == 1);
                $isPlayFabWorld    = (getWorldNetBackend($myWorld) === 'playfab');
                $crossplayJoinCode = $isPlayFabWorld ? getWorldJoinCode($myWorld) : NULL;
                $connection = [
                    'endpoint'       => $gameDNS . ':' . $worldPort,
                    'host'           => $gameDNS,
                    'port'           => $worldPort,
                    'password'       => $showPassword ? getWorldPassword($pdo, $myWorld) : NULL,
                    'passwordPublic' => $showPassword,
                    'crossplay'      => $isCrossplayWorld,
                    'listed'         => (getListed($pdo, $myWorld) == 1),
                    # A crossplay world is served over PlayFab and cannot be reached by IP, so
                    # it launches with -joincode rather than +connect. Handing back a +connect
                    # URL here would let the 5s refresh put the dead Launch button back.
                    'joinCode'       => $crossplayJoinCode,
                    'playfab'        => $isPlayFabWorld,
                    'steamUrl'       => $isPlayFabWorld
                                            ? ($crossplayJoinCode !== NULL
                                                ? 'steam://run/892970//-joincode ' . $crossplayJoinCode
                                                : NULL)
                                            : 'steam://run/892970//+connect ' . $gameDNS . ':' . $worldPort
                ];
            }

            $worldsData[] = [
                'name' => $myWorld,
                'online' => $isOnline,
                'memory' => $worldMemory,
                'launchString' => $launchString,
                'md5' => getMD5($pdo, $myWorld),
                // Mirror authenticated.php's seed rendering exactly. This payload drives
                // the 5s refresh, which writes .world-seed -- if the two disagree, the
                // server-rendered value is silently replaced a few seconds after load.
                // A vanilla world has no seed until Valheim generates the .fwl.
                'seed' => (getHideSeed($pdo, $myWorld) == 1)
                    ? '<i>hidden</i>'
                    : (($seedValue = getSeed($pdo, $myWorld)) === '' || $seedValue === NULL
                        ? '<i>generated on first start</i>'
                        : $seedValue),
                'dateDeployed' => getDateDeployed($pdo, $myWorld),
                'dateUpdated' => getDateUpdated($pdo, $myWorld),
                'mods' => $mods,
                'vanilla' => $isVanilla,
                'connection' => $connection,
                'trophies' => $trophies
            ];
        }
    }

    echo json_encode(['success' => true, 'worlds' => $worldsData]);
    exit;
}

