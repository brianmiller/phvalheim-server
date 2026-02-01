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

    case 'getCitizens':
        $world = $_GET['world'] ?? '';
        if ($world) {
            getCitizensJson($pdo, $world);
        } else {
            echo json_encode(['error' => 'World name required']);
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

    $stmt = $pdo->query("SELECT status, mode, name, port, external_endpoint, seed, autostart, beta FROM worlds ORDER BY name");
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
            'launchString' => $launchString
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

    // Sort by name
    usort($mods, fn($a, $b) => strcasecmp($a['name'], $b['name']));

    echo json_encode([
        'success' => true,
        'world' => $world,
        'mods' => $mods,
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
    // Kill any running tsSyncLocalParseMultithreaded.sh processes
    exec("pkill -f tsSyncLocalParseMultithreaded.sh 2>/dev/null");
    exec("pkill -f tsSyncRemoteCheckMultithreaded.sh 2>/dev/null");

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
 * Returns citizens list for a specific world
 */
function getCitizensJson($pdo, $world) {
    $citizensStr = getCitizens($pdo, $world);
    $citizens = [];

    if (!empty($citizensStr)) {
        // Citizens are stored as space-separated Steam IDs or names
        $citizensList = array_filter(explode(' ', trim($citizensStr)));
        $citizens = array_values(array_unique($citizensList));
    }

    echo json_encode([
        'success' => true,
        'world' => $world,
        'citizens' => $citizens,
        'count' => count($citizens)
    ]);
}

?>
