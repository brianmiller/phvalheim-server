<?php
include '/opt/stateless/nginx/www/includes/config_env_puller.php';
include '/opt/stateless/nginx/www/includes/phvalheim-frontend-config.php';
include '../includes/db_sets.php';
include '../includes/db_gets.php';

// Handle world actions via traditional GET (preserving existing behavior)
if (!empty($_GET['delete_world'])) {
    $world = $_GET['delete_world'];
    deleteWorld($pdo, $world);
    header('Location: /');
    exit;
}

if (!empty($_GET['stop_world'])) {
    $world = $_GET['stop_world'];
    stopWorld($pdo, $world);
    header('Location: /');
    exit;
}

if (!empty($_GET['start_world'])) {
    $world = $_GET['start_world'];
    startWorld($pdo, $world);
    header('Location: /');
    exit;
}

if (!empty($_GET['update_world'])) {
    $world = $_GET['update_world'];
    updateWorld($pdo, $world);
    header('Location: /');
    exit;
}

if (!empty($_GET['manual_ts_sync_start'])) {
    $manual_ts_sync_start = $_GET['manual_ts_sync_start'];
    if ($manual_ts_sync_start == "go") {
        exec("/opt/stateless/engine/tools/tsSyncLocalParseMultithreaded.sh >> /opt/stateful/logs/tsSync.log &");
        header('Location: /');
        exit;
    }
}

// HTTP(S) detector
if (isset($_SERVER['HTTP_X_FORWARDED_PROTO']) && $_SERVER['HTTP_X_FORWARDED_PROTO'] == "https") {
    $httpScheme = "https";
} else {
    $httpScheme = "http";
}

// Time now with server timezone
$timeNow = date("Y-m-d H:i:s T");
$serverTimezone = date_default_timezone_get();
$tsSyncLocalStatus = getLastTsSyncLocalExecStatus($pdo);

// Get initial world data for page load
function getWorldsData($pdo, $gameDNS, $phvalheimHost, $httpScheme) {
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
            'launchString' => $launchString,
            'modCount' => getTotalModCountOfWorld($pdo, $row['name'])
        ];
    }

    return $worlds;
}

$worlds = getWorldsData($pdo, $gameDNS, $phvalheimHost, $httpScheme);
$runningCount = count(array_filter($worlds, fn($w) => $w['mode'] === 'running'));
$totalCount = count($worlds);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>PhValheim Admin</title>
    <link rel="stylesheet" type="text/css" href="/css/bootstrap.min.css">
    <link rel="stylesheet" type="text/css" href="/css/phvalheimStyles.css?v=<?php echo time()?>">
    <script type="text/javascript" charset="utf8" src="/js/jquery-3.6.0.js"></script>
    <script type="text/javascript" charset="utf8" src="/js/bootstrap.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.1/dist/chart.umd.min.js"></script>
</head>
<body>
    <div class="admin-layout">
        <!-- Sidebar -->
        <aside class="admin-sidebar" id="sidebar">
            <div class="sidebar-header">
                <div class="sidebar-logo">PV</div>
                <span class="sidebar-title">PhValheim</span>
                <span class="sidebar-version">v<?php echo $phvalheimVersion; ?></span>
            </div>

            <nav class="sidebar-nav">
                <div class="nav-section">
                    <div class="nav-section-title">Main</div>
                    <a href="/" class="nav-item active">
                        <svg class="nav-icon" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 6a2 2 0 012-2h2a2 2 0 012 2v2a2 2 0 01-2 2H6a2 2 0 01-2-2V6zM14 6a2 2 0 012-2h2a2 2 0 012 2v2a2 2 0 01-2 2h-2a2 2 0 01-2-2V6zM4 16a2 2 0 012-2h2a2 2 0 012 2v2a2 2 0 01-2 2H6a2 2 0 01-2-2v-2zM14 16a2 2 0 012-2h2a2 2 0 012 2v2a2 2 0 01-2 2h-2a2 2 0 01-2-2v-2z"/>
                        </svg>
                        Dashboard
                    </a>
                    <a href="new_world.php" class="nav-item">
                        <svg class="nav-icon" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 6v6m0 0v6m0-6h6m-6 0H6"/>
                        </svg>
                        Add World
                    </a>
                </div>

                <div class="nav-section">
                    <div class="nav-section-title">System Logs</div>
                    <a href="readLog.php?logfile=phvalheim.log#bottom" target="_blank" class="nav-item">
                        <svg class="nav-icon" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12h6m-6 4h6m2 5H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z"/>
                        </svg>
                        Engine
                    </a>
                    <a href="readLog.php?logfile=mysqld.log#bottom" target="_blank" class="nav-item">
                        <svg class="nav-icon" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 7v10c0 2.21 3.582 4 8 4s8-1.79 8-4V7M4 7c0 2.21 3.582 4 8 4s8-1.79 8-4M4 7c0-2.21 3.582-4 8-4s8 1.79 8 4"/>
                        </svg>
                        Database
                    </a>
                    <a href="readLog.php?logfile=tsSync.log#bottom" target="_blank" class="nav-item">
                        <svg class="nav-icon" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 4v5h.582m15.356 2A8.001 8.001 0 004.582 9m0 0H9m11 11v-5h-.581m0 0a8.003 8.003 0 01-15.357-2m15.357 2H15"/>
                        </svg>
                        Thunderstore
                    </a>
                    <a href="readLog.php?logfile=worldBackups.log#bottom" target="_blank" class="nav-item">
                        <svg class="nav-icon" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M8 7H5a2 2 0 00-2 2v9a2 2 0 002 2h14a2 2 0 002-2V9a2 2 0 00-2-2h-3m-1 4l-3 3m0 0l-3-3m3 3V4"/>
                        </svg>
                        Backups
                    </a>
                </div>

                <div class="nav-section">
                    <div class="nav-section-title">Tools</div>
                    <a href="/supervisor/" target="_blank" class="nav-item">
                        <svg class="nav-icon" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M10.325 4.317c.426-1.756 2.924-1.756 3.35 0a1.724 1.724 0 002.573 1.066c1.543-.94 3.31.826 2.37 2.37a1.724 1.724 0 001.065 2.572c1.756.426 1.756 2.924 0 3.35a1.724 1.724 0 00-1.066 2.573c.94 1.543-.826 3.31-2.37 2.37a1.724 1.724 0 00-2.572 1.065c-.426 1.756-2.924 1.756-3.35 0a1.724 1.724 0 00-2.573-1.066c-1.543.94-3.31-.826-2.37-2.37a1.724 1.724 0 00-1.065-2.572c-1.756-.426-1.756-2.924 0-3.35a1.724 1.724 0 001.066-2.573c-.94-1.543.826-3.31 2.37-2.37.996.608 2.296.07 2.572-1.065z"/>
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 12a3 3 0 11-6 0 3 3 0 016 0z"/>
                        </svg>
                        Supervisor
                    </a>
                    <a href="gridphp/" target="_blank" class="nav-item" onclick="return confirm('I hope you know what you\'re doing. \nAre you sure?')">
                        <svg class="nav-icon" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 7v10c0 2.21 3.582 4 8 4s8-1.79 8-4V7M4 7c0 2.21 3.582 4 8 4s8-1.79 8-4M4 7c0-2.21 3.582-4 8-4s8 1.79 8 4m0 5c0 2.21-3.582 4-8 4s-8-1.79-8-4"/>
                        </svg>
                        Database Browser
                    </a>
                    <a href="fileBrowser.php" target="_blank" class="nav-item">
                        <svg class="nav-icon" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 7v10a2 2 0 002 2h14a2 2 0 002-2V9a2 2 0 00-2-2h-6l-2-2H5a2 2 0 00-2 2z"/>
                        </svg>
                        File Browser
                    </a>
                    <a href="#" onclick="return confirmThunderstoreSync()" class="nav-item ts-sync-tool" id="tsSyncTool" style="background: rgba(251, 191, 36, 0.1); border-left: 3px solid var(--warning);">
                        <svg class="nav-icon ts-sync-icon" id="tsSyncIcon" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 4v5h.582m15.356 2A8.001 8.001 0 004.582 9m0 0H9m11 11v-5h-.581m0 0a8.003 8.003 0 01-15.357-2m15.357 2H15"/>
                        </svg>
                        <span class="ts-sync-label">Thunderstore Sync</span>
                        <button class="ts-sync-stop" id="tsSyncStop" onclick="event.preventDefault(); event.stopPropagation(); stopThunderstoreSync();" title="Stop Sync" style="display: none;">
                            <svg width="14" height="14" fill="currentColor" viewBox="0 0 24 24">
                                <rect x="6" y="6" width="12" height="12" rx="1"/>
                            </svg>
                        </button>
                    </a>
                </div>
            </nav>
        </aside>

        <!-- Mobile Sidebar Toggle -->
        <button class="sidebar-toggle" onclick="document.getElementById('sidebar').classList.toggle('show')">
            <svg width="24" height="24" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 6h16M4 12h16M4 18h16"/>
            </svg>
        </button>

        <!-- Main Content -->
        <main class="admin-main">
            <header class="admin-header">
                <h1>PhValheim Administrator Interface</h1>
                <div class="header-actions">
                    <span class="live-indicator">
                        <span class="live-indicator-dot"></span>
                        Live
                    </span>
                    <span class="header-time" id="currentTime"><?php echo $timeNow; ?></span>
                </div>
            </header>

            <!-- Stats Cards -->
            <div class="stats-grid" id="statsGrid">
                <div class="stat-card">
                    <div class="stat-icon worlds">
                        <svg width="24" height="24" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M21 12a9 9 0 01-9 9m9-9a9 9 0 00-9-9m9 9H3m9 9a9 9 0 01-9-9m9 9c1.657 0 3-4.03 3-9s-1.343-9-3-9m0 18c-1.657 0-3-4.03-3-9s1.343-9 3-9m-9 9a9 9 0 019-9"/>
                        </svg>
                    </div>
                    <div class="stat-content">
                        <div class="stat-label">Worlds</div>
                        <div class="stat-value" id="statWorlds"><?php echo $runningCount; ?> / <?php echo $totalCount; ?></div>
                        <div class="stat-subtext">Running / Total</div>
                    </div>
                </div>

                <div class="stat-card stat-card-chart">
                    <div class="stat-card-top">
                        <div class="stat-icon memory">
                            <svg width="24" height="24" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 3v2m6-2v2M9 19v2m6-2v2M5 9H3m2 6H3m18-6h-2m2 6h-2M7 19h10a2 2 0 002-2V7a2 2 0 00-2-2H7a2 2 0 00-2 2v10a2 2 0 002 2zM9 9h6v6H9V9z"/>
                            </svg>
                        </div>
                        <div class="stat-content">
                            <div class="stat-label">Memory</div>
                            <div class="stat-value" id="statMemory"><?php echo getUsedMemory(); ?></div>
                            <div class="stat-subtext" id="statMemoryDetail">of <?php echo getTotalMemory(); ?> total</div>
                        </div>
                    </div>
                    <div class="stat-chart-container">
                        <canvas id="memoryChart"></canvas>
                    </div>
                </div>

                <div class="stat-card stat-card-chart">
                    <div class="stat-card-top">
                        <div class="stat-icon cpu">
                            <svg width="24" height="24" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 3v2m6-2v2M9 19v2m6-2v2M5 9H3m2 6H3m18-6h-2m2 6h-2M7 19h10a2 2 0 002-2V7a2 2 0 00-2-2H7a2 2 0 00-2 2v10a2 2 0 002 2zM9 9h6v6H9V9z"/>
                            </svg>
                        </div>
                        <div class="stat-content">
                            <div class="stat-label">CPU</div>
                            <div class="stat-value" id="statCpu"><?php echo getCpuUtilization($pdo); ?></div>
                            <div class="stat-subtext" id="statCpuModel"><?php echo getCpuModel($pdo); ?></div>
                        </div>
                    </div>
                    <div class="stat-chart-container">
                        <canvas id="cpuChart"></canvas>
                    </div>
                </div>

                <div class="stat-card">
                    <div class="stat-icon disk">
                        <svg width="24" height="24" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 7v10c0 2.21 3.582 4 8 4s8-1.79 8-4V7M4 7c0 2.21 3.582 4 8 4s8-1.79 8-4M4 7c0-2.21 3.582-4 8-4s8 1.79 8 4"/>
                        </svg>
                    </div>
                    <div class="stat-content">
                        <div class="stat-label">Disk</div>
                        <div class="stat-value" id="statDisk"><?php echo getUsedDisk('/opt/stateful'); ?></div>
                        <div class="stat-subtext" id="statDiskDetail">of <?php echo getTotalDisk('/opt/stateful'); ?> (<?php echo getFreeDisk('/opt/stateful'); ?> free)</div>
                    </div>
                </div>
            </div>

            <!-- Worlds Table -->
            <div class="dashboard-card">
                <div class="card-header">
                    <h2 class="card-title">
                        <svg class="card-title-icon" width="20" height="20" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M21 12a9 9 0 01-9 9m9-9a9 9 0 00-9-9m9 9H3m9 9a9 9 0 01-9-9m9 9c1.657 0 3-4.03 3-9s-1.343-9-3-9m0 18c-1.657 0-3-4.03-3-9s1.343-9 3-9m-9 9a9 9 0 019-9"/>
                        </svg>
                        Worlds
                    </h2>
                    <a href="new_world.php" class="action-btn primary">+ Add World</a>
                </div>
                <div class="card-body no-padding">
                    <div class="table-responsive">
                        <table class="worlds-table" id="worldsTable">
                            <thead>
                                <tr>
                                    <th>Status</th>
                                    <th>World</th>
                                    <th>Endpoint</th>
                                    <th>Seed</th>
                                    <th>Actions</th>
                                    <th>Configure</th>
                                    <th>Auto-Start</th>
                                    <th>Resources</th>
                                </tr>
                            </thead>
                            <tbody id="worldsTableBody">
                                <?php foreach ($worlds as $world): ?>
                                <?php
                                // Map mode to display text
                                $modeDisplayMap = [
                                    'running' => 'Running',
                                    'stopped' => 'Stopped',
                                    'create' => 'Creating',
                                    'update' => 'Updating',
                                    'delete' => 'Deleting',
                                    'start' => 'Starting',
                                    'stop' => 'Stopping',
                                    'starting' => 'Starting',
                                    'stopping' => 'Stopping'
                                ];
                                $modeDisplay = $modeDisplayMap[$world['mode']] ?? $world['mode'];
                                ?>
                                <tr data-world="<?php echo htmlspecialchars($world['name']); ?>">
                                    <td>
                                        <span class="status-badge <?php echo $world['mode']; ?>">
                                            <span class="status-dot"></span>
                                            <?php echo $modeDisplay; ?>
                                        </span>
                                        <?php if ($world['beta']): ?>
                                        <span class="status-badge" style="background: rgba(248,113,113,0.15); color: var(--danger); margin-left: 0.25rem;">BETA</span>
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <span class="world-name"><?php echo htmlspecialchars($world['name']); ?></span>
                                    </td>
                                    <td>
                                        <code class="world-endpoint"><?php echo htmlspecialchars($world['endpoint']); ?>:<?php echo $world['port']; ?></code>
                                    </td>
                                    <td>
                                        <code class="world-seed"><?php echo htmlspecialchars($world['seed']); ?></code>
                                    </td>
                                    <td>
                                        <div class="action-group">
                                            <?php if ($world['mode'] === 'running'): ?>
                                            <a href="phvalheim://?<?php echo $world['launchString']; ?>" class="action-btn success">Launch</a>
                                            <span class="action-btn disabled">Start</span>
                                            <a href="?stop_world=<?php echo urlencode($world['name']); ?>" class="action-btn">Stop</a>
                                            <?php elseif ($world['mode'] === 'stopped'): ?>
                                            <span class="action-btn disabled">Launch</span>
                                            <a href="?start_world=<?php echo urlencode($world['name']); ?>" class="action-btn success">Start</a>
                                            <span class="action-btn disabled">Stop</span>
                                            <?php else: ?>
                                            <span class="action-btn disabled">Launch</span>
                                            <span class="action-btn disabled">Start</span>
                                            <span class="action-btn disabled">Stop</span>
                                            <?php endif; ?>
                                            <a href="#" onclick="window.open('readLog.php?logfile=valheimworld_<?php echo urlencode($world['name']); ?>.log','logReader','resizable,height=750,width=1600'); return false;" class="action-btn">Logs</a>
                                        </div>
                                    </td>
                                    <td>
                                        <div class="action-group">
                                            <?php if ($world['mode'] === 'stopped'): ?>
                                            <a href="edit_world.php?world=<?php echo urlencode($world['name']); ?>" class="action-btn primary">Edit Mods</a>
                                            <?php else: ?>
                                            <span class="action-btn disabled">Edit Mods</span>
                                            <?php endif; ?>
                                            <a href="#" class="action-btn" onclick="showModsModal('<?php echo htmlspecialchars($world['name']); ?>'); return false;">
                                                View <span class="mods-count-badge"><?php echo $world['modCount']; ?></span>
                                            </a>
                                            <a href="citizensEditor.php?world=<?php echo urlencode($world['name']); ?>" class="action-btn">Citizens</a>
                                            <a href="#" onclick="showSettingsModal('<?php echo htmlspecialchars($world['name']); ?>'); return false;" class="action-btn">Settings</a>
                                            <?php if ($world['mode'] === 'stopped'): ?>
                                            <a href="?update_world=<?php echo urlencode($world['name']); ?>" class="action-btn">Update</a>
                                            <a href="?delete_world=<?php echo urlencode($world['name']); ?>" onclick="return confirm('Are you sure you want to delete this world?')" class="action-btn danger">Delete</a>
                                            <?php else: ?>
                                            <span class="action-btn disabled">Update</span>
                                            <span class="action-btn disabled">Delete</span>
                                            <?php endif; ?>
                                        </div>
                                    </td>
                                    <td>
                                        <label class="switch">
                                            <input type="checkbox" <?php echo $world['autostart'] ? 'checked' : ''; ?> onchange="toggleAutostart('<?php echo htmlspecialchars($world['name']); ?>', this.checked)">
                                            <span class="slider round"></span>
                                        </label>
                                    </td>
                                    <td>
                                        <div class="world-resources" data-world="<?php echo htmlspecialchars($world['name']); ?>">
                                            <div class="world-resource-item">
                                                <span class="resource-label">CPU</span>
                                                <canvas class="world-cpu-chart" width="60" height="20"></canvas>
                                                <span class="resource-value world-cpu-value">—</span>
                                            </div>
                                            <div class="world-resource-item">
                                                <span class="resource-label">MEM</span>
                                                <canvas class="world-mem-chart" width="60" height="20"></canvas>
                                                <span class="resource-value world-mem-value">—</span>
                                            </div>
                                        </div>
                                    </td>
                                </tr>
                                <?php endforeach; ?>
                                <?php if (empty($worlds)): ?>
                                <tr>
                                    <td colspan="8" style="text-align: center; padding: 3rem; color: var(--text-muted);">
                                        No worlds yet. <a href="new_world.php" style="color: var(--accent-primary);">Create your first world</a>
                                    </td>
                                </tr>
                                <?php endif; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>

            <!-- Bottom Cards Grid -->
            <div class="dashboard-grid two-col" style="margin-top: 1.5rem;">
                <!-- Sync Status -->
                <div class="dashboard-card">
                    <div class="card-header">
                        <h2 class="card-title">
                            <svg class="card-title-icon" width="20" height="20" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 4v5h.582m15.356 2A8.001 8.001 0 004.582 9m0 0H9m11 11v-5h-.581m0 0a8.003 8.003 0 01-15.357-2m15.357 2H15"/>
                            </svg>
                            Sync & Maintenance
                        </h2>
                    </div>
                    <div class="card-body">
                        <ul class="status-info-list" id="syncStatusList">
                            <li class="status-info-item">
                                <span class="status-info-label">Last Thunderstore Sync</span>
                                <span class="status-info-value" id="syncLastTs"><?php echo getLastTsUpdated($pdo); ?></span>
                            </li>
                            <li class="status-info-item">
                                <span class="status-info-label">Last Local Diff Check</span>
                                <span class="status-info-value">
                                    <span id="syncLocalTime"><?php echo getLastTsLocalDiffExecTime($pdo); ?></span>
                                    <span class="status-ok" id="syncLocalStatus"><?php echo getLastTsSyncLocalExecStatus($pdo); ?></span>
                                </span>
                            </li>
                            <li class="status-info-item">
                                <span class="status-info-label">Last Remote Diff Check</span>
                                <span class="status-info-value">
                                    <span id="syncRemoteTime"><?php echo getLastTsRemoteDiffExecTime($pdo); ?></span>
                                    <span class="status-ok" id="syncRemoteStatus"><?php echo getLastTsSyncRemoteExecStatus($pdo); ?></span>
                                </span>
                            </li>
                            <li class="status-info-item">
                                <span class="status-info-label">Last World Backup</span>
                                <span class="status-info-value">
                                    <span id="syncBackupTime"><?php echo getLastWorldBackupExecTime($pdo); ?></span>
                                    <span class="status-ok" id="syncBackupStatus"><?php echo getLastWorldBackupExecStatus($pdo); ?></span>
                                </span>
                            </li>
                            <li class="status-info-item">
                                <span class="status-info-label">Last Log Rotation</span>
                                <span class="status-info-value">
                                    <span id="syncLogRotateTime"><?php echo getLastLogRotateExecTime($pdo); ?></span>
                                    <span class="status-ok" id="syncLogRotateStatus"><?php echo getLastLogRotateExecStatus($pdo); ?></span>
                                </span>
                            </li>
                        </ul>
                    </div>
                </div>

                <!-- More Logs -->
                <div class="dashboard-card">
                    <div class="card-header">
                        <h2 class="card-title">
                            <svg class="card-title-icon" width="20" height="20" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12h6m-6 4h6m2 5H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z"/>
                            </svg>
                            Additional Logs
                        </h2>
                    </div>
                    <div class="card-body">
                        <div class="quick-actions">
                            <a href="readLog.php?logfile=php.log#bottom" target="_blank" class="quick-action-btn">
                                <svg width="24" height="24" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M10 20l4-16m4 4l4 4-4 4M6 16l-4-4 4-4"/>
                                </svg>
                                PHP
                            </a>
                            <a href="readLog.php?logfile=nginx.log#bottom" target="_blank" class="quick-action-btn">
                                <svg width="24" height="24" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M21 12a9 9 0 01-9 9m9-9a9 9 0 00-9-9m9 9H3m9 9a9 9 0 01-9-9m9 9c1.657 0 3-4.03 3-9s-1.343-9-3-9m0 18c-1.657 0-3-4.03-3-9s1.343-9 3-9m-9 9a9 9 0 019-9"/>
                                </svg>
                                NGINX
                            </a>
                            <a href="readLog.php?logfile=cron.log#bottom" target="_blank" class="quick-action-btn">
                                <svg width="24" height="24" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8v4l3 3m6-3a9 9 0 11-18 0 9 9 0 0118 0z"/>
                                </svg>
                                CRON
                            </a>
                            <a href="readLog.php?logfile=logRotater.log#bottom" target="_blank" class="quick-action-btn">
                                <svg width="24" height="24" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 4v5h.582m15.356 2A8.001 8.001 0 004.582 9m0 0H9m11 11v-5h-.581m0 0a8.003 8.003 0 01-15.357-2m15.357 2H15"/>
                                </svg>
                                Log Rotater
                            </a>
                        </div>
                    </div>
                </div>
            </div>
        </main>
    </div>

    <!-- Mods Modal -->
    <div class="mods-modal-overlay" id="modsModalOverlay" onclick="closeModsModal(event)">
        <div class="mods-modal" onclick="event.stopPropagation()">
            <div class="mods-modal-header">
                <h3 class="mods-modal-title" id="modsModalTitle">Running Mods</h3>
                <button class="mods-modal-close" onclick="closeModsModal()">&times;</button>
            </div>
            <div class="mods-modal-body">
                <ul class="mods-list" id="modsModalList">
                    <li>Loading...</li>
                </ul>
            </div>
        </div>
    </div>

    <!-- Settings Modal -->
    <div class="mods-modal-overlay" id="settingsModalOverlay" onclick="closeSettingsModal(event)">
        <div class="mods-modal" onclick="event.stopPropagation()" style="max-width: 500px;">
            <div class="mods-modal-header">
                <h3 class="mods-modal-title" id="settingsModalTitle">World Settings</h3>
                <button class="mods-modal-close" onclick="closeSettingsModal()">&times;</button>
            </div>
            <div class="mods-modal-body" id="settingsModalBody">
                <div style="text-align: center; padding: 2rem; color: var(--text-muted);">Loading...</div>
            </div>
        </div>
    </div>

    <script>
    // Live update interval (5 seconds)
    const POLL_INTERVAL = 5000;
    const STATS_POLL_INTERVAL = 2000; // 2 seconds for smoother charts
    const MAX_DATA_POINTS = 30; // Keep 30 data points (1 minute of history at 2s intervals)
    const SERVER_TIMEZONE = '<?php echo $serverTimezone; ?>';
    let pollTimer = null;
    let statsPollTimer = null;

    // Chart data storage
    let memoryData = [];
    let cpuData = [];
    let memoryChart = null;
    let cpuChart = null;

    // World resource charts storage
    let worldCharts = {};

    // Start polling on page load
    document.addEventListener('DOMContentLoaded', function() {
        initCharts();
        initWorldCharts();
        startPolling();
        startStatsPolling();
        updateTime();
        setInterval(updateTime, 1000);
        updateTsSyncStatus('<?php echo $tsSyncLocalStatus; ?>');
    });

    // Initialize charts
    function initCharts() {
        const chartOptions = {
            responsive: true,
            maintainAspectRatio: false,
            animation: {
                duration: 300,
                easing: 'easeOutQuart'
            },
            plugins: {
                legend: { display: false },
                tooltip: { enabled: false }
            },
            scales: {
                x: {
                    display: false,
                    grid: { display: false }
                },
                y: {
                    display: false,
                    min: 0,
                    max: 100,
                    grid: { display: false }
                }
            },
            elements: {
                point: { radius: 0 },
                line: {
                    tension: 0.4,
                    borderWidth: 2
                }
            }
        };

        // Memory chart
        const memoryCtx = document.getElementById('memoryChart').getContext('2d');
        memoryChart = new Chart(memoryCtx, {
            type: 'line',
            data: {
                labels: Array(MAX_DATA_POINTS).fill(''),
                datasets: [{
                    data: Array(MAX_DATA_POINTS).fill(null),
                    borderColor: '#4ade80',
                    backgroundColor: 'rgba(74, 222, 128, 0.1)',
                    fill: true
                }]
            },
            options: chartOptions
        });

        // CPU chart
        const cpuCtx = document.getElementById('cpuChart').getContext('2d');
        cpuChart = new Chart(cpuCtx, {
            type: 'line',
            data: {
                labels: Array(MAX_DATA_POINTS).fill(''),
                datasets: [{
                    data: Array(MAX_DATA_POINTS).fill(null),
                    borderColor: '#a78bfa',
                    backgroundColor: 'rgba(167, 139, 250, 0.1)',
                    fill: true
                }]
            },
            options: chartOptions
        });
    }

    // Start stats polling for charts
    function startStatsPolling() {
        fetchSystemStats();
        statsPollTimer = setInterval(fetchSystemStats, STATS_POLL_INTERVAL);
    }

    // Fetch system stats and update charts
    async function fetchSystemStats() {
        try {
            const response = await fetch('adminAPI.php?action=getSystemStats');
            const data = await response.json();

            if (data.success) {
                // Update memory display
                document.getElementById('statMemory').textContent = data.memory.used;
                document.getElementById('statMemoryDetail').textContent = `of ${data.memory.total} total`;

                // Update CPU display
                document.getElementById('statCpu').textContent = data.cpu.utilization;

                // Update charts
                updateChart(memoryChart, data.memory.percent);
                updateChart(cpuChart, data.cpu.percent);
            }
        } catch (error) {
            console.error('Failed to fetch system stats:', error);
        }
    }

    // Update chart with new data point
    function updateChart(chart, value) {
        const data = chart.data.datasets[0].data;
        data.push(value);
        if (data.length > MAX_DATA_POINTS) {
            data.shift();
        }
        chart.update('none'); // 'none' for smooth animation
    }

    function startPolling() {
        fetchWorldStatus();
        pollTimer = setInterval(fetchWorldStatus, POLL_INTERVAL);
    }

    function updateTime() {
        const now = new Date();
        const timeStr = now.toLocaleString('en-US', {
            timeZone: SERVER_TIMEZONE,
            year: 'numeric',
            month: '2-digit',
            day: '2-digit',
            hour: '2-digit',
            minute: '2-digit',
            second: '2-digit',
            hour12: false
        }).replace(',', '');
        // Get timezone abbreviation
        const tzAbbr = now.toLocaleString('en-US', { timeZone: SERVER_TIMEZONE, timeZoneName: 'short' }).split(' ').pop();
        document.getElementById('currentTime').textContent = `${timeStr} ${tzAbbr}`;
    }

    async function fetchWorldStatus() {
        try {
            const response = await fetch('adminAPI.php?action=getWorlds');
            const data = await response.json();

            if (data.success) {
                updateWorldsDisplay(data.worlds);
                updateStats(data.worlds);
            }
        } catch (error) {
            console.error('Failed to fetch world status:', error);
        }
    }

    // Convert mode to display text (e.g., "create" -> "Creating")
    function getModeDisplayText(mode) {
        const modeMap = {
            'running': 'Running',
            'stopped': 'Stopped',
            'create': 'Creating',
            'update': 'Updating',
            'delete': 'Deleting',
            'start': 'Starting',
            'stop': 'Stopping',
            'starting': 'Starting',
            'stopping': 'Stopping'
        };
        return modeMap[mode] || mode;
    }

    function updateWorldsDisplay(worlds) {
        const tbody = document.getElementById('worldsTableBody');
        const existingWorldNames = new Set(worlds.map(w => w.name));

        // Remove rows for deleted worlds
        const allRows = tbody.querySelectorAll('tr[data-world]');
        allRows.forEach(row => {
            const worldName = row.getAttribute('data-world');
            if (!existingWorldNames.has(worldName)) {
                row.remove();
            }
        });

        worlds.forEach(world => {
            const row = tbody.querySelector(`tr[data-world="${world.name}"]`);
            if (row) {
                // Update status badge with proper display text
                const statusCell = row.querySelector('td:first-child');
                const badge = statusCell.querySelector('.status-badge');
                if (badge) {
                    badge.className = `status-badge ${world.mode}`;
                    badge.innerHTML = `<span class="status-dot"></span>${getModeDisplayText(world.mode)}`;
                }

                // Update endpoint (port may have changed)
                const endpointCode = row.querySelector('.world-endpoint');
                if (endpointCode) {
                    endpointCode.textContent = `${world.endpoint}:${world.port}`;
                }

                // Update action buttons based on mode
                updateActionButtons(row, world);
            }
        });
    }

    function updateActionButtons(row, world) {
        const actionGroup = row.querySelectorAll('.action-group')[0];
        const configGroup = row.querySelectorAll('.action-group')[1];
        if (!actionGroup) return;

        // Get action buttons (Launch, Start, Stop, Logs)
        const launchBtn = actionGroup.children[0];
        const startBtn = actionGroup.children[1];
        const stopBtn = actionGroup.children[2];

        if (world.mode === 'running') {
            // Launch: enabled
            launchBtn.outerHTML = `<a href="phvalheim://?${world.launchString}" class="action-btn success">Launch</a>`;
            // Start: disabled
            startBtn.outerHTML = `<span class="action-btn disabled">Start</span>`;
            // Stop: enabled
            stopBtn.outerHTML = `<a href="?stop_world=${encodeURIComponent(world.name)}" class="action-btn">Stop</a>`;
        } else if (world.mode === 'stopped') {
            // Launch: disabled
            launchBtn.outerHTML = `<span class="action-btn disabled">Launch</span>`;
            // Start: enabled
            startBtn.outerHTML = `<a href="?start_world=${encodeURIComponent(world.name)}" class="action-btn success">Start</a>`;
            // Stop: disabled
            stopBtn.outerHTML = `<span class="action-btn disabled">Stop</span>`;
        } else {
            // All disabled during transitions
            launchBtn.outerHTML = `<span class="action-btn disabled">Launch</span>`;
            startBtn.outerHTML = `<span class="action-btn disabled">Start</span>`;
            stopBtn.outerHTML = `<span class="action-btn disabled">Stop</span>`;
        }

        // Update configure buttons (Edit Mods, Update, Delete)
        if (configGroup) {
            const editModsBtn = configGroup.children[0];
            const updateBtn = configGroup.children[4];
            const deleteBtn = configGroup.children[5];

            if (world.mode === 'stopped') {
                editModsBtn.outerHTML = `<a href="edit_world.php?world=${encodeURIComponent(world.name)}" class="action-btn primary">Edit Mods</a>`;
                updateBtn.outerHTML = `<a href="?update_world=${encodeURIComponent(world.name)}" class="action-btn">Update</a>`;
                deleteBtn.outerHTML = `<a href="?delete_world=${encodeURIComponent(world.name)}" onclick="return confirm('Are you sure you want to delete this world?')" class="action-btn danger">Delete</a>`;
            } else {
                editModsBtn.outerHTML = `<span class="action-btn disabled">Edit Mods</span>`;
                updateBtn.outerHTML = `<span class="action-btn disabled">Update</span>`;
                deleteBtn.outerHTML = `<span class="action-btn disabled">Delete</span>`;
            }
        }
    }

    function updateStats(worlds) {
        const running = worlds.filter(w => w.mode === 'running').length;
        const total = worlds.length;
        document.getElementById('statWorlds').textContent = `${running} / ${total}`;
    }

    // Autostart toggle
    function toggleAutostart(worldName, checked) {
        const value = checked ? 1 : 0;
        fetch(`setters.php?type=autostart&value=${value}&worldName=${encodeURIComponent(worldName)}`);
    }

    // Mods Modal
    async function showModsModal(worldName) {
        document.getElementById('modsModalTitle').textContent = `Mods - ${worldName}`;
        document.getElementById('modsModalList').innerHTML = '<li>Loading...</li>';
        document.getElementById('modsModalOverlay').classList.add('show');

        try {
            const response = await fetch(`adminAPI.php?action=getWorldMods&world=${encodeURIComponent(worldName)}`);
            const data = await response.json();

            if (data.success && data.mods.length > 0) {
                const listHtml = data.mods.map(mod =>
                    `<li><a href="${mod.url}" target="_blank" rel="noopener">${mod.name}</a></li>`
                ).join('');
                document.getElementById('modsModalList').innerHTML = listHtml;
            } else {
                document.getElementById('modsModalList').innerHTML = '<li style="color: var(--text-muted);">No mods installed</li>';
            }
        } catch (error) {
            document.getElementById('modsModalList').innerHTML = '<li style="color: var(--danger);">Error loading mods</li>';
        }
    }

    function closeModsModal(event) {
        if (!event || event.target === document.getElementById('modsModalOverlay')) {
            document.getElementById('modsModalOverlay').classList.remove('show');
        }
    }

    // Close modal on Escape key
    document.addEventListener('keydown', function(e) {
        if (e.key === 'Escape') {
            closeModsModal();
            closeSettingsModal();
        }
    });

    // Confirmation for Thunderstore Sync
    function confirmThunderstoreSync() {
        if (confirm('Are you sure you want to manually sync with Thunderstore?\n\nThis will fetch the latest mod metadata and may take a few minutes.')) {
            window.location.href = '?manual_ts_sync_start=go';
            return true;
        }
        return false;
    }

    // Stop Thunderstore Sync
    function stopThunderstoreSync() {
        if (confirm('Are you sure you want to stop the Thunderstore sync process?')) {
            fetch('adminAPI.php?action=stopTsSync')
                .then(response => response.json())
                .then(data => {
                    if (data.success) {
                        updateTsSyncStatus('stopped');
                    }
                })
                .catch(error => console.error('Failed to stop TS sync:', error));
        }
    }

    // Update TS Sync status indicator
    function updateTsSyncStatus(status) {
        const icon = document.getElementById('tsSyncIcon');
        const stopBtn = document.getElementById('tsSyncStop');
        const tool = document.getElementById('tsSyncTool');

        if (status && status.toLowerCase() === 'running') {
            icon.classList.add('spinning');
            stopBtn.style.display = 'flex';
            tool.classList.add('syncing');
        } else {
            icon.classList.remove('spinning');
            stopBtn.style.display = 'none';
            tool.classList.remove('syncing');
        }
    }

    // Settings Modal
    let currentSettingsWorld = '';

    async function showSettingsModal(worldName) {
        currentSettingsWorld = worldName;
        document.getElementById('settingsModalTitle').textContent = `Settings - ${worldName}`;
        document.getElementById('settingsModalBody').innerHTML = '<div style="text-align: center; padding: 2rem; color: var(--text-muted);">Loading...</div>';
        document.getElementById('settingsModalOverlay').classList.add('show');

        try {
            const response = await fetch(`adminAPI.php?action=getWorldSettings&world=${encodeURIComponent(worldName)}`);
            const data = await response.json();

            if (data.success) {
                const hideSeedChecked = data.hideSeed == 1 ? 'checked' : '';
                document.getElementById('settingsModalBody').innerHTML = `
                    <div style="margin-bottom: 1.5rem;">
                        <h6 style="color: var(--text-secondary); margin-bottom: 1rem; font-size: 0.875rem; text-transform: uppercase; letter-spacing: 0.05em;">World Information</h6>
                        <div style="background: var(--bg-primary); border-radius: 0.5rem; padding: 1rem;">
                            <div style="display: flex; justify-content: space-between; padding: 0.5rem 0; border-bottom: 1px solid var(--border-light);">
                                <span style="color: var(--text-secondary);">MD5 Hash</span>
                                <code style="font-size: 0.75rem; color: var(--accent-secondary);">${data.md5 || 'N/A'}</code>
                            </div>
                            <div style="display: flex; justify-content: space-between; padding: 0.5rem 0; border-bottom: 1px solid var(--border-light);">
                                <span style="color: var(--text-secondary);">Seed</span>
                                <code style="color: var(--accent-primary);">${data.seed || 'N/A'}</code>
                            </div>
                            <div style="display: flex; justify-content: space-between; padding: 0.5rem 0; border-bottom: 1px solid var(--border-light);">
                                <span style="color: var(--text-secondary);">Date Deployed</span>
                                <span>${data.dateDeployed || 'N/A'}</span>
                            </div>
                            <div style="display: flex; justify-content: space-between; padding: 0.5rem 0;">
                                <span style="color: var(--text-secondary);">Date Updated</span>
                                <span>${data.dateUpdated || 'N/A'}</span>
                            </div>
                        </div>
                    </div>
                    <div>
                        <h6 style="color: var(--text-secondary); margin-bottom: 1rem; font-size: 0.875rem; text-transform: uppercase; letter-spacing: 0.05em;">Privacy Settings</h6>
                        <div style="display: flex; justify-content: space-between; align-items: center; background: var(--bg-primary); border-radius: 0.5rem; padding: 1rem;">
                            <div>
                                <span style="display: block; margin-bottom: 0.25rem;">Hide seed from public UI</span>
                                <small style="color: var(--text-muted);">When enabled, the world seed will not be visible on the public player interface.</small>
                            </div>
                            <label class="switch" style="margin-left: 1rem;">
                                <input type="checkbox" ${hideSeedChecked} onchange="toggleHideSeed('${worldName}', this.checked)">
                                <span class="slider round"></span>
                            </label>
                        </div>
                    </div>
                `;
            } else {
                document.getElementById('settingsModalBody').innerHTML = '<div style="text-align: center; padding: 2rem; color: var(--danger);">Error loading settings</div>';
            }
        } catch (error) {
            document.getElementById('settingsModalBody').innerHTML = '<div style="text-align: center; padding: 2rem; color: var(--danger);">Error loading settings</div>';
        }
    }

    function closeSettingsModal(event) {
        if (!event || event.target === document.getElementById('settingsModalOverlay')) {
            document.getElementById('settingsModalOverlay').classList.remove('show');
        }
    }

    function toggleHideSeed(worldName, checked) {
        const value = checked ? 1 : 0;
        fetch(`setters.php?type=hideseed&value=${value}&worldName=${encodeURIComponent(worldName)}`);
    }

    // Sync & Maintenance status polling
    async function fetchSyncStatus() {
        try {
            const response = await fetch('adminAPI.php?action=getSyncStatus');
            const data = await response.json();

            if (data.success) {
                document.getElementById('syncLastTs').textContent = data.thunderstore.lastSync;
                document.getElementById('syncLocalTime').textContent = data.thunderstore.localDiff.time;
                document.getElementById('syncLocalStatus').textContent = data.thunderstore.localDiff.status;
                document.getElementById('syncRemoteTime').textContent = data.thunderstore.remoteDiff.time;
                document.getElementById('syncRemoteStatus').textContent = data.thunderstore.remoteDiff.status;
                document.getElementById('syncBackupTime').textContent = data.worldBackup.time;
                document.getElementById('syncBackupStatus').textContent = data.worldBackup.status;
                document.getElementById('syncLogRotateTime').textContent = data.logRotate.time;
                document.getElementById('syncLogRotateStatus').textContent = data.logRotate.status;

                // Update TS sync tool status
                updateTsSyncStatus(data.thunderstore.localDiff.status);
            }
        } catch (error) {
            console.error('Failed to fetch sync status:', error);
        }
    }

    // Add sync status polling to the main polling loop
    const SYNC_POLL_INTERVAL = 30000; // 30 seconds for sync status

    // Fetch sync status on page load and then every 30 seconds
    document.addEventListener('DOMContentLoaded', function() {
        fetchSyncStatus();
    });
    setInterval(fetchSyncStatus, SYNC_POLL_INTERVAL);

    // Initialize world resource mini charts
    function initWorldCharts() {
        document.querySelectorAll('.world-resources').forEach(container => {
            const worldName = container.dataset.world;
            const cpuCanvas = container.querySelector('.world-cpu-chart');
            const memCanvas = container.querySelector('.world-mem-chart');

            if (cpuCanvas && memCanvas) {
                const miniChartOptions = {
                    responsive: false,
                    maintainAspectRatio: false,
                    animation: { duration: 200 },
                    plugins: { legend: { display: false }, tooltip: { enabled: false } },
                    scales: {
                        x: { display: false },
                        y: { display: false, min: 0, max: 100 }
                    },
                    elements: {
                        point: { radius: 0 },
                        line: { tension: 0.3, borderWidth: 1.5 }
                    }
                };

                worldCharts[worldName] = {
                    cpu: new Chart(cpuCanvas, {
                        type: 'line',
                        data: {
                            labels: Array(15).fill(''),
                            datasets: [{ data: [], borderColor: '#a78bfa', backgroundColor: 'rgba(167, 139, 250, 0.1)', fill: true }]
                        },
                        options: miniChartOptions
                    }),
                    mem: new Chart(memCanvas, {
                        type: 'line',
                        data: {
                            labels: Array(15).fill(''),
                            datasets: [{ data: [], borderColor: '#4ade80', backgroundColor: 'rgba(74, 222, 128, 0.1)', fill: true }]
                        },
                        options: miniChartOptions
                    }),
                    cpuData: [],
                    memData: []
                };
            }
        });
    }

    // Update world resource charts
    function updateWorldCharts(worldStats) {
        if (!worldStats) return;

        worldStats.forEach(stat => {
            const worldName = stat.name;
            const charts = worldCharts[worldName];
            if (!charts) return;

            const container = document.querySelector(`.world-resources[data-world="${worldName}"]`);
            if (!container) return;

            // Update CPU
            if (stat.cpu !== undefined) {
                charts.cpuData.push(stat.cpu);
                if (charts.cpuData.length > 15) charts.cpuData.shift();
                charts.cpu.data.datasets[0].data = [...charts.cpuData];
                charts.cpu.update('none');
                container.querySelector('.world-cpu-value').textContent = stat.cpu + '%';
            }

            // Update Memory
            if (stat.mem !== undefined) {
                charts.memData.push(stat.mem);
                if (charts.memData.length > 15) charts.memData.shift();
                charts.mem.data.datasets[0].data = [...charts.memData];
                charts.mem.update('none');
                container.querySelector('.world-mem-value').textContent = stat.memFormatted || (stat.mem + '%');
            }
        });
    }

    // Fetch world stats and update charts
    async function fetchWorldStats() {
        try {
            const response = await fetch('adminAPI.php?action=getWorldStats');
            const data = await response.json();
            if (data.success && data.stats) {
                updateWorldCharts(data.stats);
            }
        } catch (error) {
            console.error('Failed to fetch world stats:', error);
        }
    }

    // Poll world stats every 3 seconds
    setInterval(fetchWorldStats, 3000);
    </script>
</body>
</html>
