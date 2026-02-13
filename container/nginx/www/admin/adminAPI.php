<?php
/**
 * Admin Dashboard API
 * Provides JSON endpoints for AJAX-powered admin interface
 *
 * NOTE: This is separate from /public/api.php which serves phvalheim-client
 */

include '/opt/stateless/nginx/www/includes/config_env_puller.php';
include '/opt/stateless/nginx/www/includes/phvalheim-frontend-config.php';
include '../includes/db_sets.php';
include '../includes/db_gets.php';

header('Content-Type: application/json');

$action = $_GET['action'] ?? '';

switch($action) {
    case 'getWorlds':
        getWorldsJson($pdo);
        break;

    case 'getSystemStats':
        getSystemStatsJson($pdo);
        break;

    case 'getWorldMods':
        $world = $_GET['world'] ?? '';
        if ($world) {
            getWorldModsJson($pdo, $world);
        } else {
            echo json_encode(['error' => 'World name required']);
        }
        break;

    case 'getSyncStatus':
        getSyncStatusJson($pdo);
        break;

    case 'getWorldSettings':
        $world = $_GET['world'] ?? '';
        if ($world) {
            getWorldSettingsJson($pdo, $world);
        } else {
            echo json_encode(['error' => 'World name required']);
        }
        break;

    case 'stopTsSync':
        stopTsSyncJson();
        break;

    case 'getWorldStats':
        getWorldStatsJson($pdo);
        break;

    case 'getWorldHealth':
        getWorldHealthJson($pdo);
        break;

    case 'getCitizens':
        $world = $_GET['world'] ?? '';
        if ($world) {
            getCitizensJson($pdo, $world);
        } else {
            echo json_encode(['error' => 'World name required']);
        }
        break;

    case 'saveCitizens':
        if ($_SERVER['REQUEST_METHOD'] === 'POST') {
            $input = json_decode(file_get_contents('php://input'), true);
            $world = $input['world'] ?? '';
            $citizens = $input['citizens'] ?? '';
            $isPublic = isset($input['public']) ? (int)$input['public'] : 0;
            if ($world) {
                saveCitizensJson($pdo, $world, $citizens, $isPublic);
            } else {
                echo json_encode(['error' => 'World name required']);
            }
        } else {
            echo json_encode(['error' => 'POST method required']);
        }
        break;

    case 'fetchSteamID':
        if ($_SERVER['REQUEST_METHOD'] === 'POST') {
            $input = json_decode(file_get_contents('php://input'), true);
            $vanityURL = $input['vanityURL'] ?? '';
            if ($vanityURL) {
                fetchSteamIDJson($vanityURL);
            } else {
                echo json_encode(['error' => 'Vanity URL required']);
            }
        } else {
            echo json_encode(['error' => 'POST method required']);
        }
        break;

    case 'getWorldModUUIDs':
        $world = $_GET['world'] ?? '';
        if ($world) {
            getWorldModUUIDsJson($pdo, $world);
        } else {
            echo json_encode(['error' => 'World name required']);
        }
        break;

    case 'getWorldModsWithNames':
        $world = $_GET['world'] ?? '';
        if ($world) {
            getWorldModsWithNamesJson($pdo, $world);
        } else {
            echo json_encode(['error' => 'World name required']);
        }
        break;

    case 'getAllWorlds':
        getAllWorldsJson($pdo);
        break;

    case 'getWorldFolderContents':
        $world = $_GET['world'] ?? '';
        if ($world) {
            getWorldFolderContentsJson($world);
        } else {
            echo json_encode(['error' => 'World name required']);
        }
        break;

    case 'cloneWorldFolders':
        if ($_SERVER['REQUEST_METHOD'] === 'POST') {
            $input = json_decode(file_get_contents('php://input'), true);
            $sourceWorld = $input['sourceWorld'] ?? '';
            $targetWorld = $input['targetWorld'] ?? '';
            $cloneConfigs = isset($input['cloneConfigs']) ? (bool)$input['cloneConfigs'] : false;
            $clonePlugins = isset($input['clonePlugins']) ? (bool)$input['clonePlugins'] : false;
            if ($sourceWorld && $targetWorld) {
                cloneWorldFoldersJson($sourceWorld, $targetWorld, $cloneConfigs, $clonePlugins);
            } else {
                echo json_encode(['error' => 'Source and target world names required']);
            }
        } else {
            echo json_encode(['error' => 'POST method required']);
        }
        break;

    case 'getAllModsWithDeps':
        getAllModsWithDepsJson($pdo);
        break;

    case 'getWorldModSelection':
        $world = $_GET['world'] ?? '';
        if ($world) {
            getWorldModSelectionJson($pdo, $world);
        } else {
            echo json_encode(['error' => 'World name required']);
        }
        break;

    case 'saveWorldMods':
        if ($_SERVER['REQUEST_METHOD'] === 'POST') {
            $input = json_decode(file_get_contents('php://input'), true);
            $world = $input['world'] ?? '';
            $mods = $input['mods'] ?? [];
            $cloneSource = $input['cloneSourceWorld'] ?? '';
            $cloneConfigs = isset($input['cloneConfigs']) ? (bool)$input['cloneConfigs'] : false;
            $clonePlugins = isset($input['clonePlugins']) ? (bool)$input['clonePlugins'] : false;
            if ($world) {
                saveWorldModsJson($pdo, $world, $mods, $cloneSource, $cloneConfigs, $clonePlugins);
            } else {
                echo json_encode(['error' => 'World name required']);
            }
        } else {
            echo json_encode(['error' => 'POST method required']);
        }
        break;

    case 'createWorld':
        if ($_SERVER['REQUEST_METHOD'] === 'POST') {
            $input = json_decode(file_get_contents('php://input'), true);
            $world = $input['world'] ?? '';
            $seed = $input['seed'] ?? '';
            $mods = $input['mods'] ?? [];
            $cloneSource = $input['cloneSourceWorld'] ?? '';
            $cloneConfigs = isset($input['cloneConfigs']) ? (bool)$input['cloneConfigs'] : false;
            $clonePlugins = isset($input['clonePlugins']) ? (bool)$input['clonePlugins'] : false;
            if ($world) {
                createWorldJson($pdo, $world, $seed, $mods, $cloneSource, $cloneConfigs, $clonePlugins);
            } else {
                echo json_encode(['error' => 'World name required']);
            }
        } else {
            echo json_encode(['error' => 'POST method required']);
        }
        break;

    case 'worldAction':
        $world = $_GET['world'] ?? '';
        $cmd = $_GET['cmd'] ?? '';
        if ($world && in_array($cmd, ['start', 'stop', 'update', 'delete'])) {
            switch ($cmd) {
                case 'start':  startWorld($pdo, $world); break;
                case 'stop':   stopWorld($pdo, $world); break;
                case 'update': updateWorld($pdo, $world); break;
                case 'delete': deleteWorld($pdo, $world); break;
            }
            echo json_encode(['success' => true, 'world' => $world, 'action' => $cmd]);
        } else {
            echo json_encode(['success' => false, 'error' => 'World name and valid cmd (start/stop/update/delete) required']);
        }
        break;

    default:
        // Preserve original behavior for backwards compatibility
        echo "true";
}

/**
 * Returns all worlds with their current status
 */
function getWorldsJson($pdo) {
    global $gameDNS, $phvalheimHost;

    // HTTP(S) detector
    if (isset($_SERVER['HTTP_X_FORWARDED_PROTO']) && $_SERVER['HTTP_X_FORWARDED_PROTO'] == "https") {
        $httpScheme = "https";
    } else {
        $httpScheme = "http";
    }

    $stmt = $pdo->query("SELECT status, mode, name, port, external_endpoint, seed, autostart, beta, date_updated FROM worlds ORDER BY name");
    $worlds = [];

    foreach ($stmt as $row) {
        $password = "hammertime";
        $launchString = base64_encode("launch?{$row['name']}?$password?$gameDNS?{$row['port']}?$phvalheimHost?$httpScheme");

        $worlds[] = [
            'name' => $row['name'],
            'status' => $row['status'],
            'mode' => $row['mode'],
            'port' => $row['port'],
            'endpoint' => $row['external_endpoint'],
            'seed' => $row['seed'],
            'autostart' => (int)$row['autostart'],
            'beta' => (int)$row['beta'],
            'modCount' => getTotalModCountOfWorld($pdo, $row['name']),
            'launchString' => $launchString,
            'dateUpdated' => $row['date_updated']
        ];
    }

    echo json_encode([
        'success' => true,
        'worlds' => $worlds,
        'timestamp' => date('Y-m-d H:i:s')
    ]);
}

/**
 * Returns system resource statistics
 */
function getSystemStatsJson($pdo) {
    // Get memory percentage (used/total * 100)
    $memUsedRaw = trim(exec("free | grep Mem: | tr -s ' ' | cut -d ' ' -f3"));
    $memTotalRaw = trim(exec("free | grep Mem: | tr -s ' ' | cut -d ' ' -f2"));
    $memPercent = ($memTotalRaw > 0) ? round(($memUsedRaw / $memTotalRaw) * 100, 1) : 0;

    // Get CPU utilization directly using mpstat for real-time data
    $cpuIdle = trim(exec("mpstat 1 1 2>/dev/null | tail -1 | awk '{print \$NF}'"));
    if (is_numeric($cpuIdle)) {
        $cpuPercent = round(100 - (float)$cpuIdle, 1);
    } else {
        // Fallback to /proc/stat calculation
        $stat1 = file_get_contents('/proc/stat');
        usleep(100000); // 100ms
        $stat2 = file_get_contents('/proc/stat');

        preg_match('/^cpu\s+(\d+)\s+(\d+)\s+(\d+)\s+(\d+)/m', $stat1, $m1);
        preg_match('/^cpu\s+(\d+)\s+(\d+)\s+(\d+)\s+(\d+)/m', $stat2, $m2);

        if ($m1 && $m2) {
            $idle1 = $m1[4];
            $idle2 = $m2[4];
            $total1 = $m1[1] + $m1[2] + $m1[3] + $m1[4];
            $total2 = $m2[1] + $m2[2] + $m2[3] + $m2[4];
            $idleDiff = $idle2 - $idle1;
            $totalDiff = $total2 - $total1;
            $cpuPercent = ($totalDiff > 0) ? round((1 - $idleDiff / $totalDiff) * 100, 1) : 0;
        } else {
            $cpuPercent = 0;
        }
    }

    $cpuUtilization = $cpuPercent . '%';

    echo json_encode([
        'success' => true,
        'memory' => [
            'total' => getTotalMemory(),
            'used' => getUsedMemory(),
            'free' => getFreeMemory(),
            'percent' => $memPercent
        ],
        'disk' => [
            'total' => getTotalDisk('/opt/stateful'),
            'used' => getUsedDisk('/opt/stateful'),
            'free' => getFreeDisk('/opt/stateful'),
            'percent' => getUsedDiskPerc('/opt/stateful')
        ],
        'cpu' => [
            'model' => getCpuModel($pdo),
            'utilization' => $cpuUtilization,
            'percent' => $cpuPercent
        ],
        'timestamp' => date('Y-m-d H:i:s')
    ]);
}

/**
 * Returns mods list for a specific world
 */
function getWorldModsJson($pdo, $world) {
    $modsJson = getModViewerJsonForWorld($pdo, $world);
    $mods = json_decode($modsJson, true) ?? [];

    // Filter out placeholder entries
    $mods = array_filter($mods, function($mod) {
        return isset($mod['uuid']) && $mod['uuid'] !== 'placeholder' && !empty($mod['uuid']);
    });

    // Sort by name
    usort($mods, fn($a, $b) => strcasecmp($a['name'], $b['name']));

    echo json_encode([
        'success' => true,
        'world' => $world,
        'mods' => array_values($mods),
        'count' => count($mods)
    ]);
}

/**
 * Returns settings for a specific world
 */
function getWorldSettingsJson($pdo, $world) {
    // Get autostart value
    $stmt = $pdo->prepare("SELECT autostart FROM worlds WHERE name = ?");
    $stmt->execute([$world]);
    $autostart = (int)$stmt->fetchColumn();

    echo json_encode([
        'success' => true,
        'world' => $world,
        'seed' => getSeed($pdo, $world),
        'md5' => getMd5($pdo, $world),
        'dateDeployed' => getDateDeployed($pdo, $world),
        'dateUpdated' => getDateUpdated($pdo, $world),
        'hideSeed' => GetHideSeed($pdo, $world),
        'autostart' => $autostart
    ]);
}

/**
 * Returns sync and maintenance status
 */
function getSyncStatusJson($pdo) {
    echo json_encode([
        'success' => true,
        'thunderstore' => [
            'lastSync' => getLastTsUpdated($pdo),
            'localDiff' => [
                'time' => getLastTsLocalDiffExecTime($pdo),
                'status' => getLastTsSyncLocalExecStatus($pdo)
            ],
            'remoteDiff' => [
                'time' => getLastTsRemoteDiffExecTime($pdo),
                'status' => getLastTsSyncRemoteExecStatus($pdo)
            ]
        ],
        'worldBackup' => [
            'time' => getLastWorldBackupExecTime($pdo),
            'status' => getLastWorldBackupExecStatus($pdo)
        ],
        'logRotate' => [
            'time' => getLastLogRotateExecTime($pdo),
            'status' => getLastLogRotateExecStatus($pdo)
        ],
        'utilization' => [
            'time' => getLastUtilizationMonitorExecTime($pdo),
            'status' => getLastUtilizationMonitorExecStatus($pdo)
        ],
        'timestamp' => date('Y-m-d H:i:s')
    ]);
}

/**
 * Stop Thunderstore sync process
 */
function stopTsSyncJson() {
    global $pdo;

    // Kill any running tsSyncLocalParseMultithreaded.sh processes
    exec("pkill -f tsSyncLocalParseMultithreaded.sh 2>/dev/null");
    exec("pkill -f tsSyncRemoteCheckMultithreaded.sh 2>/dev/null");

    // Clean up orphan pid files
    exec("rm -f /tmp/ts_*.pid 2>/dev/null");

    // Reset database status to idle
    $stmt = $pdo->prepare("UPDATE systemstats SET tsSyncLocalLastExecStatus='idle'");
    $stmt->execute();

    echo json_encode([
        'success' => true,
        'message' => 'Thunderstore sync stopped'
    ]);
}

/**
 * Returns resource stats for each running world
 */
function getWorldStatsJson($pdo) {
    $stmt = $pdo->query("SELECT name, mode FROM worlds WHERE mode = 'running' ORDER BY name");
    $stats = [];

    foreach ($stmt as $row) {
        $worldName = $row['name'];

        // Get the PID of the valheim_server process for this world
        $pid = trim(exec("pgrep -f 'valheim_server.*-world $worldName' 2>/dev/null | head -1"));

        if (!empty($pid) && is_numeric($pid)) {
            // Calculate real-time CPU usage using /proc/[pid]/stat
            // Read process stat twice with a small interval
            $stat1 = @file_get_contents("/proc/$pid/stat");
            $cpuInfo1 = @file_get_contents('/proc/stat');
            usleep(100000); // 100ms
            $stat2 = @file_get_contents("/proc/$pid/stat");
            $cpuInfo2 = @file_get_contents('/proc/stat');

            $cpu = 0;
            if ($stat1 && $stat2 && $cpuInfo1 && $cpuInfo2) {
                // Parse process CPU times (utime + stime)
                $parts1 = explode(' ', $stat1);
                $parts2 = explode(' ', $stat2);
                if (count($parts1) > 14 && count($parts2) > 14) {
                    $procTime1 = (int)$parts1[13] + (int)$parts1[14]; // utime + stime
                    $procTime2 = (int)$parts2[13] + (int)$parts2[14];

                    // Parse total CPU time
                    preg_match('/^cpu\s+(\d+)\s+(\d+)\s+(\d+)\s+(\d+)\s+(\d+)\s+(\d+)\s+(\d+)/m', $cpuInfo1, $m1);
                    preg_match('/^cpu\s+(\d+)\s+(\d+)\s+(\d+)\s+(\d+)\s+(\d+)\s+(\d+)\s+(\d+)/m', $cpuInfo2, $m2);

                    if ($m1 && $m2) {
                        $total1 = array_sum(array_slice($m1, 1));
                        $total2 = array_sum(array_slice($m2, 1));
                        $totalDiff = $total2 - $total1;
                        $procDiff = $procTime2 - $procTime1;

                        if ($totalDiff > 0) {
                            // Get number of CPU cores for accurate percentage
                            $numCores = (int)trim(exec("nproc 2>/dev/null")) ?: 1;
                            $cpu = round(($procDiff / $totalDiff) * 100 * $numCores, 1);
                            // Cap at 100%
                            $cpu = min($cpu, 100);
                        }
                    }
                }
            }

            // Get memory percentage and RSS
            $memOutput = trim(exec("ps -p $pid -o %mem,rss --no-headers 2>/dev/null"));
            $memParts = preg_split('/\s+/', trim($memOutput));
            $mem = isset($memParts[0]) ? round((float)$memParts[0], 1) : 0;
            $rss = isset($memParts[1]) && is_numeric($memParts[1]) ? round((int)$memParts[1] / 1024) : 0;

            $stats[] = [
                'name' => $worldName,
                'cpu' => $cpu,
                'mem' => $mem,
                'memFormatted' => $rss . 'M'
            ];
        } else {
            // World is marked as running but no process found
            $stats[] = [
                'name' => $worldName,
                'cpu' => 0,
                'mem' => 0,
                'memFormatted' => '—'
            ];
        }
    }

    echo json_encode([
        'success' => true,
        'stats' => $stats,
        'timestamp' => date('Y-m-d H:i:s')
    ]);
}

/**
 * Returns tick health data from PhValheim.TickMonitor plugin
 */
function getWorldHealthJson($pdo) {
    $stmt = $pdo->query("SELECT name FROM worlds WHERE mode = 'running' ORDER BY name");
    $results = [];

    foreach ($stmt as $row) {
        $worldName = $row['name'];
        $path = "/opt/stateful/games/valheim/worlds/$worldName/game/BepInEx/data/PhValheim.TickMonitor/tick_stats.json";

        // Only return data if file exists and is fresh (< 30 seconds old)
        if (file_exists($path) && (time() - filemtime($path)) < 30) {
            $data = json_decode(file_get_contents($path), true);
            if ($data) {
                $results[$worldName] = $data;
            }
        }
    }

    echo json_encode([
        'success' => true,
        'health' => $results,
        'timestamp' => date('Y-m-d H:i:s')
    ]);
}

/**
 * Returns citizens list for a specific world
 */
function getCitizensJson($pdo, $world) {
    $citizensStr = getCitizens($pdo, $world);
    $isPublic = getPublic($pdo, $world);

    echo json_encode([
        'success' => true,
        'world' => $world,
        'citizens' => $citizensStr ?: '',
        'public' => (int)$isPublic
    ]);
}

/**
 * Save citizens for a specific world
 */
function saveCitizensJson($pdo, $world, $citizens, $isPublic) {
    // Clean up citizens string - convert newlines to spaces, remove extra whitespace
    $citizens = str_replace(["\r\n", "\r", "\n"], " ", $citizens);
    $citizens = preg_replace('!\s+!', ' ', trim($citizens));

    // Update database
    setCitizens($pdo, $world, $citizens);
    setPublic($pdo, $world, $isPublic);

    // Update the permittedlist.txt file
    $permittedListPath = "/opt/stateful/games/valheim/worlds/$world/game/.config/unity3d/IronGate/Valheim/permittedlist.txt";
    $dirPath = dirname($permittedListPath);

    if (is_dir($dirPath)) {
        if ($isPublic) {
            file_put_contents($permittedListPath, "// List permitted players ID ONE per line");
        } else {
            $citizensNewlines = str_replace(' ', "\n", $citizens);
            file_put_contents($permittedListPath, "// List permitted players ID ONE per line\n" . $citizensNewlines);
        }
    }

    echo json_encode([
        'success' => true,
        'message' => 'Citizens saved successfully'
    ]);
}

/**
 * Fetch SteamID from vanity URL
 */
function fetchSteamIDJson($vanityURL) {
    $apiKey = getenv('steamAPIKey');
    if (empty($apiKey)) {
        echo json_encode(['success' => false, 'error' => 'Steam API key not configured']);
        return;
    }

    $url = "https://api.steampowered.com/ISteamUser/ResolveVanityURL/v1/?key=$apiKey&vanityurl=" . urlencode($vanityURL);
    $curl = curl_init($url);
    curl_setopt($curl, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($curl, CURLOPT_SSL_VERIFYPEER, true);
    curl_setopt($curl, CURLOPT_TIMEOUT, 10);
    $response = curl_exec($curl);
    $curlError = curl_error($curl);
    curl_close($curl);

    if ($curlError) {
        echo json_encode(['success' => false, 'error' => 'Failed to contact Steam API']);
        return;
    }

    $data = json_decode($response, true);

    if (!isset($data['response']) || $data['response']['success'] != 1) {
        echo json_encode(['success' => false, 'error' => 'Invalid username or private profile']);
        return;
    }

    echo json_encode([
        'success' => true,
        'steamid' => $data['response']['steamid']
    ]);
}

/**
 * Returns mods with names for a specific world
 */
function getWorldModsWithNamesJson($pdo, $world) {
    $mods = getAllWorldMods($pdo, $world);
    // Filter out empty strings and "placeholder" entries
    $mods = array_filter($mods, function($mod) {
        return !empty($mod) && $mod !== 'placeholder';
    });
    $mods = array_unique($mods);

    $modList = [];
    foreach ($mods as $uuid) {
        $name = getModNameByUuid($pdo, $uuid);
        if ($name) {
            $modList[] = ['uuid' => $uuid, 'name' => $name];
        }
    }

    // Sort by name
    usort($modList, function($a, $b) {
        return strcasecmp($a['name'], $b['name']);
    });

    echo json_encode([
        'success' => true,
        'world' => $world,
        'mods' => $modList,
        'count' => count($modList)
    ]);
}

/**
 * Returns mod UUIDs for a specific world
 */
function getWorldModUUIDsJson($pdo, $world) {
    $mods = getAllWorldMods($pdo, $world);
    // Filter out empty strings and "placeholder" entries
    $mods = array_filter($mods, function($mod) {
        return !empty($mod) && $mod !== 'placeholder';
    });

    // Remove duplicates (selected mods + their dependencies may overlap)
    $mods = array_unique($mods);

    echo json_encode([
        'success' => true,
        'world' => $world,
        'modUUIDs' => array_values($mods)
    ]);
}

/**
 * Returns all worlds (for dropdown)
 */
function getAllWorldsJson($pdo) {
    $stmt = $pdo->query("SELECT name FROM worlds ORDER BY name");
    $worlds = $stmt->fetchAll(PDO::FETCH_COLUMN);

    echo json_encode([
        'success' => true,
        'worlds' => $worlds
    ]);
}

/**
 * Get contents of custom_configs and custom_plugins folders for a world
 */
function getWorldFolderContentsJson($world) {
    $basePath = '/opt/stateful/games/valheim/worlds/' . $world;
    $results = [
        'success' => true,
        'world' => $world,
        'configs' => [],
        'plugins' => []
    ];

    $configsPath = $basePath . '/custom_configs';
    $pluginsPath = $basePath . '/custom_plugins';

    if (is_dir($configsPath)) {
        $files = scandir($configsPath);
        $results['configs'] = array_values(array_filter($files, function($f) {
            return $f !== '.' && $f !== '..';
        }));
    }

    if (is_dir($pluginsPath)) {
        $files = scandir($pluginsPath);
        $results['plugins'] = array_values(array_filter($files, function($f) {
            return $f !== '.' && $f !== '..';
        }));
    }

    echo json_encode($results);
}

/**
 * Returns all latest-version mods with resolved dependency UUIDs
 */
function getAllModsWithDepsJson($pdo) {
    $allMods = getAllModsLatestVersion($pdo);
    $ownerNameLookup = buildOwnerNameLookup($allMods);

    $modList = [];
    foreach ($allMods as $mod) {
        $depUuids = resolveModDeps($mod['deps'] ?? '', $ownerNameLookup);
        $modList[] = [
            'moduuid' => $mod['moduuid'],
            'name' => $mod['name'],
            'owner' => $mod['owner'],
            'url' => $mod['url'],
            'version' => str_replace('"', '', $mod['version']),
            'version_date_created' => $mod['version_date_created'],
            'deps' => $depUuids
        ];
    }

    echo json_encode([
        'success' => true,
        'mods' => $modList,
        'count' => count($modList)
    ]);
}

/**
 * Returns the selected and dependency mod UUIDs for a world
 */
function getWorldModSelectionJson($pdo, $world) {
    $selected = getWorldSelectedMods($pdo, $world);
    $deps = getWorldDepMods($pdo, $world);

    echo json_encode([
        'success' => true,
        'world' => $world,
        'selected' => $selected,
        'deps' => $deps
    ]);
}

/**
 * Save mod selection for an existing world (edit)
 */
function saveWorldModsJson($pdo, $world, $mods, $cloneSource, $cloneConfigs, $clonePlugins) {
    // Handle clone folder operations if requested
    if (!empty($cloneSource)) {
        handleCloneFolders($cloneSource, $world, $cloneConfigs, $clonePlugins);
    }

    // Clear existing mods
    deleteAllWorldMods($pdo, $world);

    // Add each selected mod
    if (is_array($mods)) {
        foreach ($mods as $mod) {
            $mod = trim($mod);
            if (!empty($mod)) {
                addModToWorld($pdo, $world, $mod);
            }
        }
    }

    // Set world to update mode to trigger engine processing
    updateWorld($pdo, $world);

    echo json_encode([
        'success' => true,
        'message' => 'Mods saved successfully'
    ]);
}

/**
 * Create a new world with optional mod selection
 */
function createWorldJson($pdo, $world, $seed, $mods, $cloneSource, $cloneConfigs, $clonePlugins) {
    global $gameDNS, $defaultSeed;

    if (empty($seed)) {
        $seed = $defaultSeed;
    }

    $result = addWorld($pdo, $world, $gameDNS, $seed);

    if ($result === 0) {
        // World created successfully
        // Handle clone folder operations if requested
        if (!empty($cloneSource)) {
            handleCloneFolders($cloneSource, $world, $cloneConfigs, $clonePlugins);
        }

        // Add mods if any selected
        if (is_array($mods) && !empty($mods)) {
            foreach ($mods as $mod) {
                $mod = trim($mod);
                if (!empty($mod)) {
                    addModToWorld($pdo, $world, $mod);
                }
            }
        }

        echo json_encode([
            'success' => true,
            'message' => "World '$world' created"
        ]);
    } elseif ($result === 2) {
        echo json_encode([
            'success' => false,
            'error' => "World '$world' already exists"
        ]);
    } else {
        echo json_encode([
            'success' => false,
            'error' => "Failed to create world '$world'"
        ]);
    }
}

/**
 * Handle cloning custom_configs and custom_plugins folders between worlds
 */
function handleCloneFolders($sourceWorld, $targetWorld, $cloneConfigs, $clonePlugins) {
    $basePath = '/opt/stateful/games/valheim/worlds';
    $sourcePath = $basePath . '/' . $sourceWorld;
    $targetPath = $basePath . '/' . $targetWorld;

    if (!is_dir($targetPath)) {
        mkdir($targetPath, 0775, true);
    }

    if ($cloneConfigs) {
        $sourceConfigs = $sourcePath . '/custom_configs';
        $targetConfigs = $targetPath . '/custom_configs';

        if (!is_dir($targetConfigs)) {
            mkdir($targetConfigs, 0775, true);
        }

        if (is_dir($sourceConfigs)) {
            exec("rsync -av --delete --exclude='ZeroBandwidth.CustomSeed.cfg' --filter='P ZeroBandwidth.CustomSeed.cfg' " . escapeshellarg($sourceConfigs . '/') . " " . escapeshellarg($targetConfigs . '/'));
        } else {
            exec("find " . escapeshellarg($targetConfigs) . " -mindepth 1 ! -name 'ZeroBandwidth.CustomSeed.cfg' -delete");
        }
    }

    if ($clonePlugins) {
        $sourcePlugins = $sourcePath . '/custom_plugins';
        $targetPlugins = $targetPath . '/custom_plugins';

        if (!is_dir($targetPlugins)) {
            mkdir($targetPlugins, 0775, true);
        }

        if (is_dir($sourcePlugins)) {
            exec("rsync -av --delete --exclude='ZeroBandwidth-CustomSeed' --filter='P ZeroBandwidth-CustomSeed' " . escapeshellarg($sourcePlugins . '/') . " " . escapeshellarg($targetPlugins . '/'));
        } else {
            exec("find " . escapeshellarg($targetPlugins) . " -mindepth 1 ! -name 'ZeroBandwidth-CustomSeed' -delete");
        }
    }
}

/**
 * Clone custom_configs and/or custom_plugins folders from one world to another
 */
function cloneWorldFoldersJson($sourceWorld, $targetWorld, $cloneConfigs, $clonePlugins) {
    $basePath = '/opt/stateful/games/valheim/worlds';
    $sourcePath = $basePath . '/' . $sourceWorld;
    $targetPath = $basePath . '/' . $targetWorld;
    $results = [];

    // Create target world folder if it doesn't exist
    if (!is_dir($targetPath)) {
        mkdir($targetPath, 0775, true);
    }

    if ($cloneConfigs) {
        $sourceConfigs = $sourcePath . '/custom_configs';
        $targetConfigs = $targetPath . '/custom_configs';

        // Create target directory if it doesn't exist
        if (!is_dir($targetConfigs)) {
            mkdir($targetConfigs, 0775, true);
        }

        if (is_dir($sourceConfigs)) {
            // Use rsync to copy all contents, excluding world-specific seed config
            // --exclude prevents copying from source, --filter protects existing files in dest from deletion
            exec("rsync -av --delete --exclude='ZeroBandwidth.CustomSeed.cfg' --filter='P ZeroBandwidth.CustomSeed.cfg' " . escapeshellarg($sourceConfigs . '/') . " " . escapeshellarg($targetConfigs . '/'));
            $results['configs'] = 'cloned';
        } else {
            // Source doesn't exist, empty the target but preserve seed config
            exec("find " . escapeshellarg($targetConfigs) . " -mindepth 1 ! -name 'ZeroBandwidth.CustomSeed.cfg' -delete");
            $results['configs'] = 'source not found';
        }
    }

    if ($clonePlugins) {
        $sourcePlugins = $sourcePath . '/custom_plugins';
        $targetPlugins = $targetPath . '/custom_plugins';

        // Create target directory if it doesn't exist
        if (!is_dir($targetPlugins)) {
            mkdir($targetPlugins, 0775, true);
        }

        if (is_dir($sourcePlugins)) {
            // Use rsync to copy all contents, excluding world-specific seed plugin
            // --exclude prevents copying from source, --filter protects existing files in dest from deletion
            exec("rsync -av --delete --exclude='ZeroBandwidth-CustomSeed' --filter='P ZeroBandwidth-CustomSeed' " . escapeshellarg($sourcePlugins . '/') . " " . escapeshellarg($targetPlugins . '/'));
            $results['plugins'] = 'cloned';
        } else {
            // Source doesn't exist, empty the target but preserve seed plugin
            exec("find " . escapeshellarg($targetPlugins) . " -mindepth 1 ! -name 'ZeroBandwidth-CustomSeed' -delete");
            $results['plugins'] = 'source not found';
        }
    }

    echo json_encode([
        'success' => true,
        'results' => $results
    ]);
}

?>
