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

    case 'getAiProviders':
        getAiProvidersJson($aiKeys);
        break;

    case 'aiHelper':
        if ($_SERVER['REQUEST_METHOD'] === 'POST') {
            $input = json_decode(file_get_contents('php://input'), true);
            $message  = trim($input['message'] ?? '');
            $history  = $input['history'] ?? [];
            $context  = $input['context'] ?? 'none';
            $world    = $input['world'] ?? '';
            $provider = $input['provider'] ?? '';
            $model    = $input['model'] ?? '';
            if (!$message) { echo json_encode(['success'=>false,'error'=>'No message']); break; }
            aiHelperDispatch($aiKeys, $provider, $model, $message, $history, $context, $world);
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

    case 'getServerSettings':
        getServerSettingsJson($pdo);
        break;

    case 'saveServerSettings':
        if ($_SERVER['REQUEST_METHOD'] === 'POST') {
            $input = json_decode(file_get_contents('php://input'), true);
            if ($input) {
                saveServerSettingsJson($pdo, $input);
            } else {
                echo json_encode(['success' => false, 'error' => 'Invalid JSON input']);
            }
        } else {
            echo json_encode(['success' => false, 'error' => 'POST method required']);
        }
        break;

    case 'completeSetup':
        if ($_SERVER['REQUEST_METHOD'] === 'POST') {
            $input = json_decode(file_get_contents('php://input'), true);
            if ($input) {
                completeSetupJson($pdo, $input);
            } else {
                echo json_encode(['success' => false, 'error' => 'Invalid JSON input']);
            }
        } else {
            echo json_encode(['success' => false, 'error' => 'POST method required']);
        }
        break;

    case 'dismissMigrationNotice':
        if ($_SERVER['REQUEST_METHOD'] === 'POST') {
            dismissMigrationNoticeJson($pdo);
        } else {
            echo json_encode(['success' => false, 'error' => 'POST method required']);
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
    // Get autostart, endpoint, and port
    $stmt = $pdo->prepare("SELECT autostart, external_endpoint, port FROM worlds WHERE name = ?");
    $stmt->execute([$world]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    $autostart = (int)($row['autostart'] ?? 0);
    $endpoint = $row['external_endpoint'] ?? '';
    $port = $row['port'] ?? '';

    echo json_encode([
        'success' => true,
        'world' => $world,
        'seed' => getSeed($pdo, $world),
        'md5' => getMd5($pdo, $world),
        'endpoint' => $endpoint,
        'port' => $port,
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
    global $steamAPIKey;
    $apiKey = $steamAPIKey;
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

/**
 * Returns available AI providers and their models
 */
function getAiProvidersJson($aiKeys) {
    $providerDefs = [
        'openai' => [
            'label' => 'OpenAI',
            'models' => [
                ['id' => 'gpt-4o-mini', 'label' => 'GPT-4o Mini'],
                ['id' => 'gpt-4o', 'label' => 'GPT-4o'],
            ]
        ],
        'gemini' => [
            'label' => 'Google Gemini',
            'models' => [
                ['id' => 'gemini-2.0-flash', 'label' => 'Gemini 2.0 Flash'],
                ['id' => 'gemini-2.0-flash-lite', 'label' => 'Gemini 2.0 Flash Lite'],
                ['id' => 'gemini-1.5-pro', 'label' => 'Gemini 1.5 Pro'],
            ]
        ],
        'claude' => [
            'label' => 'Anthropic Claude',
            'models' => [
                ['id' => 'claude-haiku-4-5-20251001', 'label' => 'Haiku 4.5'],
                ['id' => 'claude-sonnet-4-5-20250929', 'label' => 'Sonnet 4.5'],
            ]
        ],
    ];

    $providers = [];
    foreach ($providerDefs as $key => $def) {
        if (!empty($aiKeys[$key])) {
            $providers[$key] = $def;
        }
    }

    // Ollama: dynamically fetch models from the server
    if (!empty($aiKeys['ollama'])) {
        $ollamaModels = getOllamaModels($aiKeys['ollama']);
        if (!empty($ollamaModels)) {
            $providers['ollama'] = [
                'label' => 'Ollama',
                'models' => $ollamaModels
            ];
        }
    }

    echo json_encode(['success' => true, 'providers' => $providers]);
}

/**
 * Dispatch AI Helper request to the correct provider
 */
function aiHelperDispatch($aiKeys, $provider, $model, $message, $history, $context, $world) {
    $allowedModels = [
        'openai' => ['gpt-4o-mini', 'gpt-4o'],
        'gemini' => ['gemini-2.0-flash', 'gemini-2.0-flash-lite', 'gemini-1.5-pro'],
        'claude' => ['claude-haiku-4-5-20251001', 'claude-sonnet-4-5-20250929'],
    ];
    $validProviders = ['openai', 'gemini', 'claude', 'ollama'];

    // Validate provider
    if (!in_array($provider, $validProviders) || empty($aiKeys[$provider])) {
        echo json_encode(['success' => false, 'error' => "Provider '$provider' not available"]);
        return;
    }

    // Validate model (skip for ollama — models are dynamic)
    if ($provider !== 'ollama' && isset($allowedModels[$provider])) {
        if (!in_array($model, $allowedModels[$provider])) {
            $model = $allowedModels[$provider][0];
        }
    }

    $apiKey = $aiKeys[$provider];
    $systemPrompt = buildAiSystemPrompt($context, $world, $GLOBALS['pdo'] ?? null);

    // Trim history
    $trimmedHistory = [];
    if (is_array($history)) {
        $history = array_slice($history, -20);
        foreach ($history as $h) {
            if (isset($h['role']) && isset($h['content']) && in_array($h['role'], ['user', 'assistant'])) {
                $trimmedHistory[] = $h;
            }
        }
    }

    switch ($provider) {
        case 'openai':  aiHelperOpenAI($apiKey, $model, $systemPrompt, $trimmedHistory, $message); break;
        case 'gemini':  aiHelperGemini($apiKey, $model, $systemPrompt, $trimmedHistory, $message); break;
        case 'claude':  aiHelperClaude($apiKey, $model, $systemPrompt, $trimmedHistory, $message); break;
        case 'ollama':  aiHelperOllama($apiKey, $model, $systemPrompt, $trimmedHistory, $message); break;
    }
}

/**
 * Build the system prompt with optional log context
 */
function buildAiSystemPrompt($context, $world, $pdo = null) {
    $systemPrompt = "You are PhValheim AI Helper, a concise technical assistant for the PhValheim Valheim server manager. "
        . "You help admins troubleshoot server issues, understand logs, and manage mods. "
        . "Keep answers short and actionable. Use bullet points for lists. "
        . "If you see errors in logs, explain the likely cause and suggest a fix. "
        . "CRITICAL: This is a HEADLESS dedicated server. Never mention or report on anything related to fonts, UI, shaders, graphics, rendering, DepthOfField, textures, cameras, screen resolution, visual effects, materials, meshes, sprites, or any graphical/visual warnings — they are completely irrelevant on a headless server. "
        . "Also ignore mod RPC errors — these are normal networked mod communication and not actionable.";

    $logFile = null;
    $contextLabel = $context;
    $safeWorld = null;
    if (strpos($context, 'world:') === 0) {
        // World-specific log: context = "world:WorldName"
        $worldName = substr($context, 6);
        $safeWorld = preg_replace('/[^a-zA-Z0-9_-]/', '', $worldName);
        $logFile = "/opt/stateful/logs/valheimworld_{$safeWorld}.log";
        $contextLabel = "world '$safeWorld'";
    } else {
        switch ($context) {
            case 'engine':  $logFile = '/opt/stateful/logs/phvalheim.log'; break;
            case 'ts':      $logFile = '/opt/stateful/logs/tsSync.log'; break;
            case 'backup':  $logFile = '/opt/stateful/logs/worldBackups.log'; break;
        }
    }

    // If world context and we have a database connection, inject expected mod list
    if ($safeWorld && $pdo) {
        try {
            $modUuids = getAllWorldMods($pdo, $safeWorld);
            $modNames = [];
            foreach ($modUuids as $uuid) {
                $uuid = trim($uuid);
                if (empty($uuid)) continue;
                $name = getModNameByUuid($pdo, $uuid);
                if ($name) {
                    $modNames[] = $name;
                }
            }
            if (!empty($modNames)) {
                $systemPrompt .= "\n\nExpected mods configured in the database for this world:\n";
                foreach ($modNames as $modName) {
                    $systemPrompt .= "- {$modName}\n";
                }
                $systemPrompt .= "\nCompare this list against [BepInEx] Loading lines in the log to identify any mods that are expected but not loaded. Do not report on unexpected mods loaded.";
            }
        } catch (Exception $e) {
            // Silently skip mod list if query fails
        }
    }

    if ($logFile && file_exists($logFile)) {
        $lines = [];
        $fp = @fopen($logFile, 'r');
        if ($fp) {
            while (($line = fgets($fp)) !== false) {
                $lines[] = $line;
                if (count($lines) > 200) {
                    array_shift($lines);
                }
            }
            fclose($fp);
        }
        if (!empty($lines)) {
            $logContent = implode('', $lines);
            $systemPrompt .= "\n\nHere are the last " . count($lines) . " lines from the {$contextLabel} log:\n```\n{$logContent}\n```";
        }
    }

    return $systemPrompt;
}

/**
 * OpenAI Chat Completions API
 */
function aiHelperOpenAI($apiKey, $model, $systemPrompt, $history, $message) {
    $messages = [['role' => 'system', 'content' => $systemPrompt]];
    foreach ($history as $h) {
        $messages[] = ['role' => $h['role'], 'content' => $h['content']];
    }
    $messages[] = ['role' => 'user', 'content' => $message];

    $payload = json_encode([
        'model' => $model,
        'messages' => $messages,
        'max_tokens' => 1024,
        'temperature' => 0.7
    ]);

    $ch = curl_init('https://api.openai.com/v1/chat/completions');
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, $payload);
    curl_setopt($ch, CURLOPT_HTTPHEADER, [
        'Content-Type: application/json',
        'Authorization: Bearer ' . $apiKey
    ]);
    curl_setopt($ch, CURLOPT_TIMEOUT, 30);
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, true);

    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $curlError = curl_error($ch);
    curl_close($ch);

    if ($curlError) {
        echo json_encode(['success' => false, 'error' => 'OpenAI error: ' . $curlError]);
        return;
    }

    $data = json_decode($response, true);

    if ($httpCode !== 200 || !isset($data['choices'][0]['message']['content'])) {
        $errMsg = $data['error']['message'] ?? "HTTP {$httpCode} from OpenAI";
        echo json_encode(['success' => false, 'error' => $errMsg]);
        return;
    }

    echo json_encode(['success' => true, 'reply' => $data['choices'][0]['message']['content']]);
}

/**
 * Google Gemini generateContent API
 */
function aiHelperGemini($apiKey, $model, $systemPrompt, $history, $message) {
    $contents = [];
    foreach ($history as $h) {
        $geminiRole = ($h['role'] === 'assistant') ? 'model' : 'user';
        $contents[] = ['role' => $geminiRole, 'parts' => [['text' => $h['content']]]];
    }
    $contents[] = ['role' => 'user', 'parts' => [['text' => $message]]];

    $payload = json_encode([
        'system_instruction' => ['parts' => [['text' => $systemPrompt]]],
        'contents' => $contents,
        'generationConfig' => [
            'maxOutputTokens' => 1024,
            'temperature' => 0.7
        ]
    ]);

    $url = 'https://generativelanguage.googleapis.com/v1beta/models/' . urlencode($model) . ':generateContent?key=' . urlencode($apiKey);

    $ch = curl_init($url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, $payload);
    curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/json']);
    curl_setopt($ch, CURLOPT_TIMEOUT, 30);
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, true);

    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $curlError = curl_error($ch);
    curl_close($ch);

    if ($curlError) {
        echo json_encode(['success' => false, 'error' => 'Gemini error: ' . $curlError]);
        return;
    }

    $data = json_decode($response, true);

    if ($httpCode !== 200 || !isset($data['candidates'][0]['content']['parts'][0]['text'])) {
        $errMsg = $data['error']['message'] ?? "HTTP {$httpCode} from Gemini";
        echo json_encode(['success' => false, 'error' => $errMsg]);
        return;
    }

    echo json_encode(['success' => true, 'reply' => $data['candidates'][0]['content']['parts'][0]['text']]);
}

/**
 * Anthropic Claude Messages API
 */
function aiHelperClaude($apiKey, $model, $systemPrompt, $history, $message) {
    $messages = [];
    foreach ($history as $h) {
        $messages[] = ['role' => $h['role'], 'content' => $h['content']];
    }
    $messages[] = ['role' => 'user', 'content' => $message];

    $payload = json_encode([
        'model' => $model,
        'system' => $systemPrompt,
        'messages' => $messages,
        'max_tokens' => 1024
    ]);

    $ch = curl_init('https://api.anthropic.com/v1/messages');
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, $payload);
    curl_setopt($ch, CURLOPT_HTTPHEADER, [
        'Content-Type: application/json',
        'x-api-key: ' . $apiKey,
        'anthropic-version: 2023-06-01'
    ]);
    curl_setopt($ch, CURLOPT_TIMEOUT, 30);
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, true);

    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $curlError = curl_error($ch);
    curl_close($ch);

    if ($curlError) {
        echo json_encode(['success' => false, 'error' => 'Claude error: ' . $curlError]);
        return;
    }

    $data = json_decode($response, true);

    if ($httpCode !== 200 || !isset($data['content'][0]['text'])) {
        $errMsg = $data['error']['message'] ?? "HTTP {$httpCode} from Claude";
        echo json_encode(['success' => false, 'error' => $errMsg]);
        return;
    }

    echo json_encode(['success' => true, 'reply' => $data['content'][0]['text']]);
}

/**
 * Fetch available models from Ollama server
 */
function getOllamaModels($ollamaUrl) {
    $url = rtrim($ollamaUrl, '/') . '/api/tags';

    $ch = curl_init($url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_TIMEOUT, 5);
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);

    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($httpCode !== 200 || !$response) return [];

    $data = json_decode($response, true);
    if (!isset($data['models']) || !is_array($data['models'])) return [];

    $models = [];
    foreach ($data['models'] as $m) {
        $name = $m['name'] ?? '';
        if (empty($name)) continue;
        // Use the model name as both id and label, clean up the label
        $label = explode(':', $name)[0]; // strip :latest tag for display
        $models[] = ['id' => $name, 'label' => $label];
    }

    return $models;
}

/**
 * Returns all server settings from the settings table
 */
function getServerSettingsJson($pdo) {
    $stmt = $pdo->query("SELECT * FROM settings LIMIT 1");
    $settings = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$settings) {
        echo json_encode(['success' => false, 'error' => 'No settings found']);
        return;
    }

    echo json_encode([
        'success' => true,
        'settings' => [
            'basePort' => (int)$settings['basePort'],
            'defaultSeed' => $settings['defaultSeed'] ?? '',
            'gameDNS' => $settings['gameDNS'] ?? '',
            'steamAPIKey' => $settings['steamApiKey'] ?? '',
            'phvalheimClientURL' => $settings['phvalheimClientURL'] ?? '',
            'backupsToKeep' => (int)$settings['backupsToKeep'],
            'maxLogSize' => (int)$settings['maxLogSize'],
            'sessionTimeout' => (int)$settings['sessionTimeout'],
            'timezone' => $settings['timezone'] ?? 'Etc/UTC',
            'thunderstore_local_sync' => (int)$settings['thunderstore_local_sync'],
            'thunderstore_chunk_size' => (int)$settings['thunderstore_chunk_size'],
            'openaiApiKey' => $settings['openaiApiKey'] ?? '',
            'geminiApiKey' => $settings['geminiApiKey'] ?? '',
            'claudeApiKey' => $settings['claudeApiKey'] ?? '',
            'ollamaUrl' => $settings['ollamaUrl'] ?? '',
            'setupComplete' => (int)$settings['setupComplete'],
            'migrationNoticeShown' => (int)$settings['migrationNoticeShown'],
        ]
    ]);
}

/**
 * Save server settings
 */
function saveServerSettingsJson($pdo, $input) {
    $allowedFields = [
        'basePort' => 'int',
        'defaultSeed' => 'string',
        'gameDNS' => 'string',
        'steamAPIKey' => 'string',
        'phvalheimClientURL' => 'string',
        'backupsToKeep' => 'int',
        'maxLogSize' => 'int',
        'sessionTimeout' => 'int',
        'timezone' => 'string',
        'thunderstore_local_sync' => 'int',
        'thunderstore_chunk_size' => 'int',
        'openaiApiKey' => 'string',
        'geminiApiKey' => 'string',
        'claudeApiKey' => 'string',
        'ollamaUrl' => 'string',
    ];

    // Map input field names to actual DB column names where they differ
    $columnMap = ['steamAPIKey' => 'steamApiKey'];

    $updates = [];
    $params = [];
    foreach ($input as $key => $value) {
        if (!isset($allowedFields[$key])) continue;
        $col = $columnMap[$key] ?? $key;
        if ($allowedFields[$key] === 'int') {
            $updates[] = "$col = ?";
            $params[] = (int)$value;
        } else {
            $updates[] = "$col = ?";
            $params[] = (string)$value;
        }
    }

    if (empty($updates)) {
        echo json_encode(['success' => false, 'error' => 'No valid fields to update']);
        return;
    }

    $sql = "UPDATE settings SET " . implode(', ', $updates);
    $stmt = $pdo->prepare($sql);
    $result = $stmt->execute($params);

    // Re-export settings to /etc/environment so engine/cron picks them up
    if ($result) {
        $settings = $pdo->query("SELECT * FROM settings LIMIT 1")->fetch(PDO::FETCH_ASSOC);
        $tz = $settings['timezone'] ?? 'Etc/UTC';
        $envVars = [
            'basePort' => $settings['basePort'],
            'defaultSeed' => $settings['defaultSeed'],
            'gameDNS' => $settings['gameDNS'],
            'steamAPIKey' => $settings['steamApiKey'],
            'phvalheimClientURL' => $settings['phvalheimClientURL'],
            'backupsToKeep' => $settings['backupsToKeep'],
            'maxLogSize' => $settings['maxLogSize'],
            'sessionTimeout' => $settings['sessionTimeout'],
            'TZ' => $tz,
        ];

        // Read existing /etc/environment and update/add our vars
        $envFile = '/etc/environment';
        $envLines = file_exists($envFile) ? file($envFile, FILE_IGNORE_NEW_LINES) : [];
        $envMap = [];
        foreach ($envLines as $line) {
            if (preg_match('/^([^=]+)=(.*)$/', $line, $m)) {
                $envMap[$m[1]] = $m[2];
            }
        }
        foreach ($envVars as $k => $v) {
            $envMap[$k] = $v;
        }
        $output = '';
        foreach ($envMap as $k => $v) {
            $output .= "$k=$v\n";
        }
        @file_put_contents($envFile, $output);

        // Apply timezone to the running system via helper script (needs sudo for /etc files)
        $tzSafe = escapeshellarg($tz);
        exec("sudo /opt/stateless/engine/tools/applyTimezone.sh $tzSafe 2>&1", $tzOutput, $tzResult);
        if ($tzResult === 0) {
            date_default_timezone_set($tz);
        }
    }

    echo json_encode([
        'success' => $result ? true : false,
        'message' => $result ? 'Settings saved successfully' : 'Failed to save settings'
    ]);
}

/**
 * Complete the setup wizard (fresh install)
 */
function completeSetupJson($pdo, $input) {
    // Save all provided settings first
    saveServerSettingsJson_internal($pdo, $input);

    // Mark setup as complete
    $stmt = $pdo->prepare("UPDATE settings SET setupComplete = 2");
    $stmt->execute();

    echo json_encode([
        'success' => true,
        'message' => 'Setup complete'
    ]);
}

/**
 * Internal helper to save settings without JSON output
 */
function saveServerSettingsJson_internal($pdo, $input) {
    $allowedFields = [
        'basePort' => 'int',
        'defaultSeed' => 'string',
        'gameDNS' => 'string',
        'steamAPIKey' => 'string',
        'phvalheimClientURL' => 'string',
        'backupsToKeep' => 'int',
        'maxLogSize' => 'int',
        'sessionTimeout' => 'int',
        'timezone' => 'string',
        'openaiApiKey' => 'string',
        'geminiApiKey' => 'string',
        'claudeApiKey' => 'string',
        'ollamaUrl' => 'string',
    ];

    $columnMap = ['steamAPIKey' => 'steamApiKey'];

    $updates = [];
    $params = [];
    foreach ($input as $key => $value) {
        if (!isset($allowedFields[$key])) continue;
        $col = $columnMap[$key] ?? $key;
        if ($allowedFields[$key] === 'int') {
            $updates[] = "$col = ?";
            $params[] = (int)$value;
        } else {
            $updates[] = "$col = ?";
            $params[] = (string)$value;
        }
    }

    if (!empty($updates)) {
        $sql = "UPDATE settings SET " . implode(', ', $updates);
        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
    }
}

/**
 * Dismiss the one-time migration notice
 */
function dismissMigrationNoticeJson($pdo) {
    $stmt = $pdo->prepare("UPDATE settings SET migrationNoticeShown = 1, setupComplete = 2");
    $result = $stmt->execute();

    echo json_encode([
        'success' => $result ? true : false,
        'message' => $result ? 'Migration notice dismissed' : 'Failed to dismiss notice'
    ]);
}

/**
 * Ollama Chat API
 */
function aiHelperOllama($ollamaUrl, $model, $systemPrompt, $history, $message) {
    $messages = [['role' => 'system', 'content' => $systemPrompt]];
    foreach ($history as $h) {
        $messages[] = ['role' => $h['role'], 'content' => $h['content']];
    }
    $messages[] = ['role' => 'user', 'content' => $message];

    $payload = json_encode([
        'model' => $model,
        'messages' => $messages,
        'stream' => false
    ]);

    $url = rtrim($ollamaUrl, '/') . '/api/chat';

    $ch = curl_init($url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, $payload);
    curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/json']);
    curl_setopt($ch, CURLOPT_TIMEOUT, 120);
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);

    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $curlError = curl_error($ch);
    curl_close($ch);

    if ($curlError) {
        echo json_encode(['success' => false, 'error' => 'Ollama error: ' . $curlError]);
        return;
    }

    $data = json_decode($response, true);

    if ($httpCode !== 200 || !isset($data['message']['content'])) {
        $errMsg = $data['error'] ?? "HTTP {$httpCode} from Ollama";
        echo json_encode(['success' => false, 'error' => $errMsg]);
        return;
    }

    echo json_encode(['success' => true, 'reply' => $data['message']['content']]);
}

?>
