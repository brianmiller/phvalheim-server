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
            'launchString' => $launchString,
            'modCount' => getTotalModCountOfWorld($pdo, $row['name']),
            'dateUpdated' => $row['date_updated']
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
    <link rel="icon" type="image/svg+xml" href="/images/phvalheim_favicon.svg">
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
                <div class="sidebar-logo"><img src="/images/phvalheim_favicon.svg" alt="PhValheim" style="width:100%;height:100%;"></div>
                <span class="sidebar-title">PhValheim</span>
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

            <!-- Footer with social links -->
            <div class="admin-footer">
                <a href="https://github.com/brianmiller/phvalheim-server" target="_blank" rel="noopener" class="social-link" title="View on GitHub">
                    <svg width="20" height="20" viewBox="0 0 24 24" fill="currentColor">
                        <path d="M12 0c-6.626 0-12 5.373-12 12 0 5.302 3.438 9.8 8.207 11.387.599.111.793-.261.793-.577v-2.234c-3.338.726-4.033-1.416-4.033-1.416-.546-1.387-1.333-1.756-1.333-1.756-1.089-.745.083-.729.083-.729 1.205.084 1.839 1.237 1.839 1.237 1.07 1.834 2.807 1.304 3.492.997.107-.775.418-1.305.762-1.604-2.665-.305-5.467-1.334-5.467-5.931 0-1.311.469-2.381 1.236-3.221-.124-.303-.535-1.524.117-3.176 0 0 1.008-.322 3.301 1.23.957-.266 1.983-.399 3.003-.404 1.02.005 2.047.138 3.006.404 2.291-1.552 3.297-1.23 3.297-1.23.653 1.653.242 2.874.118 3.176.77.84 1.235 1.911 1.235 3.221 0 4.609-2.807 5.624-5.479 5.921.43.372.823 1.102.823 2.222v3.293c0 .319.192.694.801.576 4.765-1.589 8.199-6.086 8.199-11.386 0-6.627-5.373-12-12-12z"/>
                    </svg>
                </a>
                <a href="https://discord.gg/8RMMrJVQgy" target="_blank" rel="noopener" class="social-link" title="Join our Discord">
                    <svg width="20" height="20" viewBox="0 0 24 24" fill="currentColor">
                        <path d="M20.317 4.37a19.791 19.791 0 0 0-4.885-1.515.074.074 0 0 0-.079.037c-.21.375-.444.864-.608 1.25a18.27 18.27 0 0 0-5.487 0 12.64 12.64 0 0 0-.617-1.25.077.077 0 0 0-.079-.037A19.736 19.736 0 0 0 3.677 4.37a.07.07 0 0 0-.032.027C.533 9.046-.32 13.58.099 18.057a.082.082 0 0 0 .031.057 19.9 19.9 0 0 0 5.993 3.03.078.078 0 0 0 .084-.028 14.09 14.09 0 0 0 1.226-1.994.076.076 0 0 0-.041-.106 13.107 13.107 0 0 1-1.872-.892.077.077 0 0 1-.008-.128 10.2 10.2 0 0 0 .372-.292.074.074 0 0 1 .077-.01c3.928 1.793 8.18 1.793 12.062 0a.074.074 0 0 1 .078.01c.12.098.246.198.373.292a.077.077 0 0 1-.006.127 12.299 12.299 0 0 1-1.873.892.077.077 0 0 0-.041.107c.36.698.772 1.362 1.225 1.993a.076.076 0 0 0 .084.028 19.839 19.839 0 0 0 6.002-3.03.077.077 0 0 0 .032-.054c.5-5.177-.838-9.674-3.549-13.66a.061.061 0 0 0-.031-.03zM8.02 15.33c-1.183 0-2.157-1.085-2.157-2.419 0-1.333.956-2.419 2.157-2.419 1.21 0 2.176 1.096 2.157 2.42 0 1.333-.956 2.418-2.157 2.418zm7.975 0c-1.183 0-2.157-1.085-2.157-2.419 0-1.333.955-2.419 2.157-2.419 1.21 0 2.176 1.096 2.157 2.42 0 1.333-.946 2.418-2.157 2.418z"/>
                    </svg>
                </a>
                <span class="footer-version">v<?php echo $phvalheimVersion; ?></span>
            </div>
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
                <button class="sidebar-collapse-btn" id="sidebarCollapseBtn" onclick="toggleSidebarCollapse()" title="Toggle sidebar">
                    <svg width="20" height="20" fill="none" stroke="currentColor" viewBox="0 0 24 24" class="collapse-icon">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 19l-7-7 7-7"/>
                    </svg>
                </button>
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
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 6h16M4 6v12a1 1 0 001 1h14a1 1 0 001-1V6M4 6l1-2h14l1 2M8 10v4m4-4v4m4-4v4"/>
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
                    <?php
                    // Separate worlds into online (any status except stopped) and offline (stopped)
                    $onlineWorlds = array_filter($worlds, fn($w) => $w['mode'] !== 'stopped');
                    $offlineWorlds = array_filter($worlds, fn($w) => $w['mode'] === 'stopped');

                    // Sort offline worlds by date_updated (most recent first)
                    usort($offlineWorlds, function($a, $b) {
                        return strtotime($b['dateUpdated'] ?? '1970-01-01') - strtotime($a['dateUpdated'] ?? '1970-01-01');
                    });

                    $onlineCount = count($onlineWorlds);
                    $offlineCount = count($offlineWorlds);

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
                    ?>
                    <div class="table-responsive">
                        <table class="worlds-table" id="worldsTable">
                            <!-- Active Worlds Section -->
                            <tbody id="onlineWorldsHeader">
                                <tr class="worlds-section-header" onclick="toggleWorldsSection('online')" id="onlineSectionHeader">
                                    <td colspan="7">
                                        <div class="worlds-section-toggle">
                                            <svg fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 9l-7 7-7-7"/>
                                            </svg>
                                            Active Worlds
                                            <span class="worlds-section-count online" id="onlineWorldsCount"><?php echo $onlineCount; ?></span>
                                        </div>
                                    </td>
                                </tr>
                            </tbody>
                        </table>
                        <!-- Scrollable active worlds container -->
                        <div id="onlineWorldsWrapper" class="worlds-scroll-wrapper">
                            <table class="worlds-table">
                                <thead>
                                    <tr>
                                        <th>Status</th>
                                        <th>World</th>
                                        <th>Actions</th>
                                        <th>Configure</th>
                                        <th>Resources</th>
                                    </tr>
                                </thead>
                                <tbody id="onlineWorldsBody" class="worlds-section-body">
                                    <?php foreach ($onlineWorlds as $world): ?>
                                    <?php $modeDisplay = $modeDisplayMap[$world['mode']] ?? $world['mode']; ?>
                                    <tr data-world="<?php echo htmlspecialchars($world['name']); ?>" data-section="online">
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
                                            <div class="action-group">
                                                <?php if ($world['mode'] === 'running'): ?>
                                                <a href="phvalheim://?<?php echo $world['launchString']; ?>" class="action-btn success" data-action="launch">Launch</a>
                                                <span class="action-btn disabled" data-action="start">Start</span>
                                                <a href="?stop_world=<?php echo urlencode($world['name']); ?>" class="action-btn" data-action="stop">Stop</a>
                                                <?php else: ?>
                                                <span class="action-btn disabled" data-action="launch">Launch</span>
                                                <span class="action-btn disabled" data-action="start">Start</span>
                                                <span class="action-btn disabled" data-action="stop">Stop</span>
                                                <?php endif; ?>
                                                <a href="#" onclick="window.open('readLog.php?logfile=valheimworld_<?php echo urlencode($world['name']); ?>.log','logReader','resizable,height=750,width=1600'); return false;" class="action-btn" data-action="logs">Logs</a>
                                            </div>
                                        </td>
                                        <td>
                                            <div class="action-group">
                                                <span class="action-btn disabled" data-action="edit-mods">Edit Mods</span>
                                                <a href="#" class="action-btn" data-action="view-mods" onclick="showModsModal('<?php echo htmlspecialchars($world['name']); ?>'); return false;">
                                                    View <span class="mods-count-badge"><?php echo $world['modCount']; ?></span>
                                                </a>
                                                <span class="action-btn disabled" data-action="update">Update</span>
                                                <a href="#" onclick="showSettingsModal('<?php echo htmlspecialchars($world['name']); ?>'); return false;" class="action-btn" data-action="settings">Settings</a>
                                                <span class="action-btn disabled" data-action="delete">Delete</span>
                                            </div>
                                        </td>
                                        <td>
                                            <div class="world-resources" data-world="<?php echo htmlspecialchars($world['name']); ?>">
                                                <div class="world-resource-item">
                                                    <span class="resource-label">MEM</span>
                                                    <canvas class="world-mem-chart" width="60" height="20"></canvas>
                                                    <span class="resource-value world-mem-value">—</span>
                                                </div>
                                                <div class="world-resource-item">
                                                    <span class="resource-label">HEALTH</span>
                                                    <div class="world-load-bar" title="Server tick rate (target: 50 TPS). 45-50 = healthy, 35-44 = busy, below 35 = lagging. Low TPS means the server can't keep up with game updates.">
                                                        <div class="world-load-fill" style="width:0%"></div>
                                                    </div>
                                                    <span class="resource-value world-load-value">—</span>
                                                </div>
                                            </div>
                                        </td>
                                    </tr>
                                    <?php endforeach; ?>
                                    <?php if (empty($onlineWorlds)): ?>
                                    <tr class="no-worlds-row" data-section="online">
                                        <td colspan="7" style="text-align: center; padding: 1.5rem; color: var(--text-muted);">
                                            No online worlds
                                        </td>
                                    </tr>
                                    <?php endif; ?>
                                </tbody>
                            </table>
                        </div>
                        <table class="worlds-table">
                            <!-- Offline Worlds Section -->
                            <tbody id="offlineWorldsHeader">
                                <tr class="worlds-section-header" onclick="toggleWorldsSection('offline')" id="offlineSectionHeader">
                                    <td colspan="7">
                                        <div class="worlds-section-toggle">
                                            <svg fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 9l-7 7-7-7"/>
                                            </svg>
                                            Offline Worlds
                                            <span class="worlds-section-count offline" id="offlineWorldsCount"><?php echo $offlineCount; ?></span>
                                        </div>
                                    </td>
                                </tr>
                            </tbody>
                        </table>
                        <!-- Scrollable offline worlds container -->
                        <div id="offlineWorldsWrapper" class="offline-worlds-scroll-wrapper">
                            <table class="worlds-table offline-worlds-table">
                                <thead>
                                    <tr>
                                        <th>Status</th>
                                        <th>World</th>
                                        <th>Actions</th>
                                        <th>Configure</th>
                                        <th>Resources</th>
                                    </tr>
                                </thead>
                                <tbody id="offlineWorldsBody" class="worlds-section-body">
                                    <?php foreach ($offlineWorlds as $world): ?>
                                    <?php $modeDisplay = $modeDisplayMap[$world['mode']] ?? $world['mode']; ?>
                                    <tr data-world="<?php echo htmlspecialchars($world['name']); ?>" data-section="offline">
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
                                            <div class="action-group">
                                                <span class="action-btn disabled" data-action="launch">Launch</span>
                                                <a href="?start_world=<?php echo urlencode($world['name']); ?>" class="action-btn success" data-action="start">Start</a>
                                                <span class="action-btn disabled" data-action="stop">Stop</span>
                                                <a href="#" onclick="window.open('readLog.php?logfile=valheimworld_<?php echo urlencode($world['name']); ?>.log','logReader','resizable,height=750,width=1600'); return false;" class="action-btn" data-action="logs">Logs</a>
                                            </div>
                                        </td>
                                        <td>
                                            <div class="action-group">
                                                <a href="edit_world.php?world=<?php echo urlencode($world['name']); ?>" class="action-btn primary" data-action="edit-mods">Edit Mods</a>
                                                <a href="#" class="action-btn" data-action="view-mods" onclick="showModsModal('<?php echo htmlspecialchars($world['name']); ?>'); return false;">
                                                    View <span class="mods-count-badge"><?php echo $world['modCount']; ?></span>
                                                </a>
                                                <a href="?update_world=<?php echo urlencode($world['name']); ?>" class="action-btn" data-action="update">Update</a>
                                                <a href="#" onclick="showSettingsModal('<?php echo htmlspecialchars($world['name']); ?>'); return false;" class="action-btn" data-action="settings">Settings</a>
                                                <a href="?delete_world=<?php echo urlencode($world['name']); ?>" class="action-btn danger" data-action="delete">Delete</a>
                                            </div>
                                        </td>
                                        <td>
                                            <div class="world-resources" data-world="<?php echo htmlspecialchars($world['name']); ?>">
                                                <div class="world-resource-item">
                                                    <span class="resource-label">MEM</span>
                                                    <canvas class="world-mem-chart" width="60" height="20"></canvas>
                                                    <span class="resource-value world-mem-value">—</span>
                                                </div>
                                                <div class="world-resource-item">
                                                    <span class="resource-label">HEALTH</span>
                                                    <div class="world-load-bar" title="Server tick rate (target: 50 TPS). 45-50 = healthy, 35-44 = busy, below 35 = lagging. Low TPS means the server can't keep up with game updates.">
                                                        <div class="world-load-fill" style="width:0%"></div>
                                                    </div>
                                                    <span class="resource-value world-load-value">—</span>
                                                </div>
                                            </div>
                                        </td>
                                    </tr>
                                    <?php endforeach; ?>
                                    <?php if (empty($offlineWorlds)): ?>
                                    <tr class="no-worlds-row" data-section="offline">
                                        <td colspan="7" style="text-align: center; padding: 1.5rem; color: var(--text-muted);">
                                            No offline worlds
                                        </td>
                                    </tr>
                                    <?php endif; ?>
                                </tbody>
                            </table>
                        </div>
                        <table class="worlds-table" style="display: none;">
                            <?php if (empty($worlds)): ?>
                            <tbody>
                                <tr>
                                    <td colspan="7" style="text-align: center; padding: 3rem; color: var(--text-muted);">
                                        No worlds yet. <a href="new_world.php" style="color: var(--accent-primary);">Create your first world</a>
                                    </td>
                                </tr>
                            </tbody>
                            <?php endif; ?>
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

    <!-- Settings Modal (includes Citizens) -->
    <div class="mods-modal-overlay" id="settingsModalOverlay" onclick="closeSettingsModal(event)">
        <div class="mods-modal" onclick="event.stopPropagation()" style="max-width: 700px;">
            <div class="mods-modal-header">
                <h3 class="mods-modal-title" id="settingsModalTitle">World Settings</h3>
                <button class="mods-modal-close" onclick="closeSettingsModal()">&times;</button>
            </div>
            <div class="mods-modal-body" id="settingsModalBody">
                <div style="text-align: center; padding: 2rem; color: var(--text-muted);">Loading...</div>
            </div>
        </div>
    </div>

    <!-- SteamID Lookup Modal -->
    <div class="mods-modal-overlay" id="steamIdModalOverlay" onclick="closeSteamIdModal(event)">
        <div class="mods-modal" onclick="event.stopPropagation()" style="max-width: 400px;">
            <div class="mods-modal-header">
                <h3 class="mods-modal-title">SteamID Lookup</h3>
                <button class="mods-modal-close" onclick="closeSteamIdModal()">&times;</button>
            </div>
            <div class="mods-modal-body">
                <p style="color: var(--text-secondary); font-size: 0.875rem; margin-bottom: 1rem;">Enter a Steam username to look up their SteamID:</p>
                <input type="text" id="steamIdLookupInput" class="form-control" placeholder="Steam username" style="margin-bottom: 1rem;" onkeypress="if(event.key==='Enter'){lookupSteamId();}">
                <button class="action-btn primary" onclick="lookupSteamId()" style="width: 100%; margin-bottom: 1rem;">Look Up</button>
                <div id="steamIdResult" style="background: var(--bg-primary); padding: 0.75rem; border-radius: 0.375rem; font-family: var(--font-mono); font-size: 0.875rem; color: var(--accent-secondary); min-height: 2.5rem; display: flex; align-items: center; justify-content: space-between;">
                    <span id="steamIdResultText">—</span>
                    <button id="steamIdCopyBtn" class="action-btn" onclick="copySteamId()" style="display: none; padding: 0.25rem 0.5rem; font-size: 0.75rem;">Copy</button>
                </div>
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

    // Cookie helper functions
    function setCookie(name, value, days = 365) {
        const expires = new Date(Date.now() + days * 864e5).toUTCString();
        document.cookie = name + '=' + encodeURIComponent(value) + '; expires=' + expires + '; path=/; SameSite=Lax';
    }

    function getCookie(name) {
        return document.cookie.split('; ').reduce((r, v) => {
            const parts = v.split('=');
            return parts[0] === name ? decodeURIComponent(parts[1]) : r;
        }, '');
    }

    // Toggle worlds section collapse/expand
    function toggleWorldsSection(section) {
        const header = document.getElementById(section + 'SectionHeader');
        const body = document.getElementById(section + 'WorldsBody');

        if (header && body) {
            const isCollapsed = header.classList.toggle('collapsed');
            body.classList.toggle('collapsed', isCollapsed);

            // Also toggle the wrapper for both sections
            const wrapper = document.getElementById(section + 'WorldsWrapper');
            if (wrapper) {
                wrapper.classList.toggle('collapsed', isCollapsed);
            }

            // Save preference to cookie
            const prefs = JSON.parse(getCookie('worldsSectionPrefs') || '{}');
            prefs[section] = !isCollapsed; // true = expanded, false = collapsed
            setCookie('worldsSectionPrefs', JSON.stringify(prefs));
        }
    }

    // Initialize section collapse state from cookies
    function initSectionCollapse() {
        const prefs = JSON.parse(getCookie('worldsSectionPrefs') || '{"online": true, "offline": true}');

        ['online', 'offline'].forEach(section => {
            const header = document.getElementById(section + 'SectionHeader');
            const body = document.getElementById(section + 'WorldsBody');

            if (header && body && prefs[section] === false) {
                header.classList.add('collapsed');
                body.classList.add('collapsed');

                // Also collapse the wrapper for both sections
                const wrapper = document.getElementById(section + 'WorldsWrapper');
                if (wrapper) {
                    wrapper.classList.add('collapsed');
                }
            }
        });
    }

    // Start polling on page load
    document.addEventListener('DOMContentLoaded', function() {
        initSectionCollapse();
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
        const onlineBody = document.getElementById('onlineWorldsBody');
        const offlineBody = document.getElementById('offlineWorldsBody');
        const existingWorldNames = new Set(worlds.map(w => w.name));

        // Remove rows for deleted worlds from both sections
        [onlineBody, offlineBody].forEach(tbody => {
            const rows = tbody.querySelectorAll('tr[data-world]');
            rows.forEach(row => {
                const worldName = row.getAttribute('data-world');
                if (!existingWorldNames.has(worldName)) {
                    row.remove();
                }
            });
        });

        // Separate and sort worlds
        const onlineWorlds = worlds.filter(w => w.mode !== 'stopped');
        const offlineWorlds = worlds.filter(w => w.mode === 'stopped')
            .sort((a, b) => new Date(b.dateUpdated || '1970-01-01') - new Date(a.dateUpdated || '1970-01-01'));

        // Process online worlds
        onlineWorlds.forEach(world => {
            let row = document.querySelector(`tr[data-world="${world.name}"]`);
            if (row) {
                const currentSection = row.getAttribute('data-section');
                if (currentSection !== 'online') {
                    row.setAttribute('data-section', 'online');
                    onlineBody.appendChild(row);
                }
                updateWorldRow(row, world);
            } else {
                // Create new row
                const newRow = createWorldRow(world, 'online');
                onlineBody.appendChild(newRow);
                initWorldChartsForRow(newRow, world.name);
            }
        });

        // Process offline worlds (sorted by dateUpdated)
        offlineWorlds.forEach((world, index) => {
            let row = document.querySelector(`tr[data-world="${world.name}"]`);
            if (row) {
                const currentSection = row.getAttribute('data-section');
                if (currentSection !== 'offline') {
                    row.setAttribute('data-section', 'offline');
                }
                updateWorldRow(row, world);
                // Re-order: append in sorted order
                offlineBody.appendChild(row);
            } else {
                // Create new row
                const newRow = createWorldRow(world, 'offline');
                offlineBody.appendChild(newRow);
                initWorldChartsForRow(newRow, world.name);
            }
        });

        // Update section counts
        document.getElementById('onlineWorldsCount').textContent = onlineWorlds.length;
        document.getElementById('offlineWorldsCount').textContent = offlineWorlds.length;

        // Handle "no worlds" rows
        updateNoWorldsRow(onlineBody, onlineWorlds.length, 'online');
        updateNoWorldsRow(offlineBody, offlineWorlds.length, 'offline');

        // Reflow overflow menus after DOM updates
        reflowActionGroups();
    }

    function updateWorldRow(row, world) {
        // Update status badge
        const statusCell = row.querySelector('td:first-child');
        const badge = statusCell.querySelector('.status-badge');
        if (badge) {
            badge.className = `status-badge ${world.mode}`;
            badge.innerHTML = `<span class="status-dot"></span>${getModeDisplayText(world.mode)}`;
        }

        // Update mod count badge
        const modCountBadge = row.querySelector('.mods-count-badge');
        if (modCountBadge) {
            modCountBadge.textContent = world.modCount;
        }

        // Update action buttons
        updateActionButtons(row, world);
    }

    function createWorldRow(world, section) {
        const row = document.createElement('tr');
        row.setAttribute('data-world', world.name);
        row.setAttribute('data-section', section);

        const isOnline = section === 'online';
        const betaBadge = world.beta ? '<span class="status-badge" style="background: rgba(248,113,113,0.15); color: var(--danger); margin-left: 0.25rem;">BETA</span>' : '';

        let actionsHtml, configHtml;
        if (world.mode === 'running') {
            actionsHtml = `
                <a href="phvalheim://?${world.launchString}" class="action-btn success" data-action="launch">Launch</a>
                <span class="action-btn disabled" data-action="start">Start</span>
                <a href="?stop_world=${encodeURIComponent(world.name)}" class="action-btn" data-action="stop">Stop</a>
                <a href="#" onclick="window.open('readLog.php?logfile=valheimworld_${encodeURIComponent(world.name)}.log','logReader','resizable,height=750,width=1600'); return false;" class="action-btn" data-action="logs">Logs</a>`;
            configHtml = `
                <span class="action-btn disabled" data-action="edit-mods">Edit Mods</span>
                <a href="#" class="action-btn" data-action="view-mods" onclick="showModsModal('${world.name}'); return false;">View <span class="mods-count-badge">${world.modCount}</span></a>
                <span class="action-btn disabled" data-action="update">Update</span>
                <a href="#" onclick="showSettingsModal('${world.name}'); return false;" class="action-btn" data-action="settings">Settings</a>
                <span class="action-btn disabled" data-action="delete">Delete</span>`;
        } else if (world.mode === 'stopped') {
            actionsHtml = `
                <span class="action-btn disabled" data-action="launch">Launch</span>
                <a href="?start_world=${encodeURIComponent(world.name)}" class="action-btn success" data-action="start">Start</a>
                <span class="action-btn disabled" data-action="stop">Stop</span>
                <a href="#" onclick="window.open('readLog.php?logfile=valheimworld_${encodeURIComponent(world.name)}.log','logReader','resizable,height=750,width=1600'); return false;" class="action-btn" data-action="logs">Logs</a>`;
            configHtml = `
                <a href="edit_world.php?world=${encodeURIComponent(world.name)}" class="action-btn primary" data-action="edit-mods">Edit Mods</a>
                <a href="#" class="action-btn" data-action="view-mods" onclick="showModsModal('${world.name}'); return false;">View <span class="mods-count-badge">${world.modCount}</span></a>
                <a href="?update_world=${encodeURIComponent(world.name)}" class="action-btn" data-action="update">Update</a>
                <a href="#" onclick="showSettingsModal('${world.name}'); return false;" class="action-btn" data-action="settings">Settings</a>
                <a href="?delete_world=${encodeURIComponent(world.name)}" class="action-btn danger" data-action="delete">Delete</a>`;
        } else {
            actionsHtml = `
                <span class="action-btn disabled" data-action="launch">Launch</span>
                <span class="action-btn disabled" data-action="start">Start</span>
                <span class="action-btn disabled" data-action="stop">Stop</span>
                <a href="#" onclick="window.open('readLog.php?logfile=valheimworld_${encodeURIComponent(world.name)}.log','logReader','resizable,height=750,width=1600'); return false;" class="action-btn" data-action="logs">Logs</a>`;
            configHtml = `
                <span class="action-btn disabled" data-action="edit-mods">Edit Mods</span>
                <a href="#" class="action-btn" data-action="view-mods" onclick="showModsModal('${world.name}'); return false;">View <span class="mods-count-badge">${world.modCount}</span></a>
                <span class="action-btn disabled" data-action="update">Update</span>
                <a href="#" onclick="showSettingsModal('${world.name}'); return false;" class="action-btn" data-action="settings">Settings</a>
                <span class="action-btn disabled" data-action="delete">Delete</span>`;
        }

        row.innerHTML = `
            <td>
                <span class="status-badge ${world.mode}">
                    <span class="status-dot"></span>
                    ${getModeDisplayText(world.mode)}
                </span>
                ${betaBadge}
            </td>
            <td><span class="world-name">${world.name}</span></td>
            <td><div class="action-group">${actionsHtml}</div></td>
            <td><div class="action-group">${configHtml}</div></td>
            <td>
                <div class="world-resources" data-world="${world.name}">
                    <div class="world-resource-item">
                        <span class="resource-label">MEM</span>
                        <canvas class="world-mem-chart" width="60" height="20"></canvas>
                        <span class="resource-value world-mem-value">—</span>
                    </div>
                    <div class="world-resource-item">
                        <span class="resource-label">HEALTH</span>
                        <div class="world-load-bar" title="Server tick rate (target: 50 TPS). 45-50 = healthy, 35-44 = busy, below 35 = lagging. Low TPS means the server can't keep up with game updates.">
                            <div class="world-load-fill" style="width:0%"></div>
                        </div>
                        <span class="resource-value world-load-value">—</span>
                    </div>
                </div>
            </td>`;

        return row;
    }

    function initWorldChartsForRow(row, worldName) {
        const container = row.querySelector('.world-resources');
        if (!container) return;

        const memCanvas = container.querySelector('.world-mem-chart');

        if (memCanvas) {
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
                mem: new Chart(memCanvas, {
                    type: 'line',
                    data: {
                        labels: Array(15).fill(''),
                        datasets: [{ data: [], borderColor: '#22d3ee', backgroundColor: 'rgba(34, 211, 238, 0.1)', fill: true }]
                    },
                    options: miniChartOptions
                }),
                memData: []
            };
        }
    }

    function updateNoWorldsRow(tbody, count, section) {
        let noWorldsRow = tbody.querySelector('.no-worlds-row');

        if (count === 0) {
            if (!noWorldsRow) {
                noWorldsRow = document.createElement('tr');
                noWorldsRow.className = 'no-worlds-row';
                noWorldsRow.setAttribute('data-section', section);
                noWorldsRow.innerHTML = `<td colspan="7" style="text-align: center; padding: 1.5rem; color: var(--text-muted);">No ${section} worlds</td>`;
                tbody.appendChild(noWorldsRow);
            }
        } else if (noWorldsRow) {
            noWorldsRow.remove();
        }
    }

    function updateActionButtons(row, world) {
        // Find buttons by data-action attribute (works regardless of position — inline or in overflow menu)
        const findBtn = (attr) => row.querySelector(`[data-action="${attr}"]`);

        const launchBtn = findBtn('launch');
        const startBtn = findBtn('start');
        const stopBtn = findBtn('stop');

        if (launchBtn && startBtn && stopBtn) {
            if (world.mode === 'running') {
                launchBtn.outerHTML = `<a href="phvalheim://?${world.launchString}" class="action-btn success" data-action="launch">Launch</a>`;
                startBtn.outerHTML = `<span class="action-btn disabled" data-action="start">Start</span>`;
                stopBtn.outerHTML = `<a href="?stop_world=${encodeURIComponent(world.name)}" class="action-btn" data-action="stop">Stop</a>`;
            } else if (world.mode === 'stopped') {
                launchBtn.outerHTML = `<span class="action-btn disabled" data-action="launch">Launch</span>`;
                startBtn.outerHTML = `<a href="?start_world=${encodeURIComponent(world.name)}" class="action-btn success" data-action="start">Start</a>`;
                stopBtn.outerHTML = `<span class="action-btn disabled" data-action="stop">Stop</span>`;
            } else {
                launchBtn.outerHTML = `<span class="action-btn disabled" data-action="launch">Launch</span>`;
                startBtn.outerHTML = `<span class="action-btn disabled" data-action="start">Start</span>`;
                stopBtn.outerHTML = `<span class="action-btn disabled" data-action="stop">Stop</span>`;
            }
        }

        const editModsBtn = findBtn('edit-mods');
        const updateBtn = findBtn('update');
        const deleteBtn = findBtn('delete');

        if (editModsBtn && updateBtn && deleteBtn) {
            if (world.mode === 'stopped') {
                editModsBtn.outerHTML = `<a href="edit_world.php?world=${encodeURIComponent(world.name)}" class="action-btn primary" data-action="edit-mods">Edit Mods</a>`;
                updateBtn.outerHTML = `<a href="?update_world=${encodeURIComponent(world.name)}" class="action-btn" data-action="update">Update</a>`;
                deleteBtn.outerHTML = `<a href="?delete_world=${encodeURIComponent(world.name)}" class="action-btn danger" data-action="delete">Delete</a>`;
            } else {
                editModsBtn.outerHTML = `<span class="action-btn disabled" data-action="edit-mods">Edit Mods</span>`;
                updateBtn.outerHTML = `<span class="action-btn disabled" data-action="update">Update</span>`;
                deleteBtn.outerHTML = `<span class="action-btn disabled" data-action="delete">Delete</span>`;
            }
        }

        // Re-run reflow after button state changes
        reflowActionGroups();
    }

    function updateStats(worlds) {
        const running = worlds.filter(w => w.mode === 'running').length;
        const total = worlds.length;
        document.getElementById('statWorlds').textContent = `${running} / ${total}`;
    }

    // ==========================================
    // Responsive overflow menu for action buttons
    // ==========================================
    function reflowActionGroups() {
        document.querySelectorAll('.worlds-table .action-group').forEach(group => {
            // Ensure overflow container exists
            let overflow = group.querySelector('.action-overflow');
            if (!overflow) {
                overflow = document.createElement('div');
                overflow.className = 'action-overflow';
                overflow.innerHTML = '<span class="action-overflow-trigger" onclick="toggleOverflow(event, this)">&#x22EF;</span><div class="action-overflow-menu"></div>';
                group.appendChild(overflow);
            }
            const menu = overflow.querySelector('.action-overflow-menu');

            // Move all buttons back from menu to inline (before the overflow div)
            while (menu.firstChild) {
                group.insertBefore(menu.firstChild, overflow);
            }

            // Hide overflow trigger
            overflow.style.display = 'none';

            // Get visible inline buttons (not disabled/hidden, not inside overflow)
            const allBtns = Array.from(group.querySelectorAll(':scope > .action-btn'));
            const visibleBtns = allBtns.filter(btn => !btn.classList.contains('disabled'));

            // Check if content overflows: compare scrollWidth to clientWidth
            if (group.scrollWidth <= group.clientWidth) return;

            // Show overflow trigger and measure its impact
            overflow.style.display = '';

            // Move visible buttons right-to-left into menu until it fits (keep at least 1)
            for (let i = visibleBtns.length - 1; i >= 1; i--) {
                if (group.scrollWidth <= group.clientWidth) break;
                menu.insertBefore(visibleBtns[i], menu.firstChild);
            }
        });
    }

    function toggleOverflow(event, trigger) {
        event.stopPropagation();
        const menu = trigger.nextElementSibling;
        const wasOpen = menu.classList.contains('show');
        document.querySelectorAll('.action-overflow-menu.show').forEach(m => m.classList.remove('show'));
        if (!wasOpen) {
            menu.classList.add('show');
        }
    }

    // Close overflow menus on outside click
    document.addEventListener('click', function(e) {
        if (!e.target.closest('.action-overflow')) {
            document.querySelectorAll('.action-overflow-menu.show').forEach(m => m.classList.remove('show'));
        }
    });

    // Run reflow on load and resize
    reflowActionGroups();
    let reflowTimer;
    window.addEventListener('resize', function() {
        clearTimeout(reflowTimer);
        reflowTimer = setTimeout(reflowActionGroups, 100);
    });

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
            closeSteamIdModal();
        }
    });

    // (Citizens functionality merged into Settings modal below)

    // SteamID Lookup Modal
    function openSteamIdLookup() {
        document.getElementById('steamIdLookupInput').value = '';
        document.getElementById('steamIdResultText').textContent = '—';
        document.getElementById('steamIdCopyBtn').style.display = 'none';
        document.getElementById('steamIdModalOverlay').classList.add('show');
        setTimeout(() => document.getElementById('steamIdLookupInput').focus(), 100);
    }

    function closeSteamIdModal(event) {
        if (!event || event.target === document.getElementById('steamIdModalOverlay')) {
            document.getElementById('steamIdModalOverlay').classList.remove('show');
        }
    }

    async function lookupSteamId() {
        const vanityURL = document.getElementById('steamIdLookupInput').value.trim();
        if (!vanityURL) return;

        document.getElementById('steamIdResultText').textContent = 'Looking up...';
        document.getElementById('steamIdCopyBtn').style.display = 'none';

        try {
            const response = await fetch('adminAPI.php?action=fetchSteamID', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({ vanityURL: vanityURL })
            });
            const data = await response.json();

            if (data.success) {
                document.getElementById('steamIdResultText').textContent = data.steamid;
                document.getElementById('steamIdCopyBtn').style.display = 'block';
            } else {
                document.getElementById('steamIdResultText').textContent = data.error || 'Not found';
            }
        } catch (error) {
            document.getElementById('steamIdResultText').textContent = 'Error looking up SteamID';
        }
    }

    function copySteamId() {
        const steamId = document.getElementById('steamIdResultText').textContent;
        if (steamId && steamId !== '—') {
            navigator.clipboard.writeText(steamId).then(() => {
                // Add to textarea if settings modal citizens section is open
                const textarea = document.getElementById('settingsCitizensTextarea');
                if (textarea) {
                    const currentValue = textarea.value.trim();
                    textarea.value = currentValue ? currentValue + '\n' + steamId : steamId;
                }
                closeSteamIdModal();
            });
        }
    }

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

    // Settings Modal (includes Citizens)
    let currentSettingsWorld = '';

    async function showSettingsModal(worldName) {
        currentSettingsWorld = worldName;
        document.getElementById('settingsModalTitle').textContent = `Settings - ${worldName}`;
        document.getElementById('settingsModalBody').innerHTML = '<div style="text-align: center; padding: 2rem; color: var(--text-muted);">Loading...</div>';
        document.getElementById('settingsModalOverlay').classList.add('show');

        try {
            // Fetch settings and citizens in parallel
            const [settingsRes, citizensRes] = await Promise.all([
                fetch(`adminAPI.php?action=getWorldSettings&world=${encodeURIComponent(worldName)}`),
                fetch(`adminAPI.php?action=getCitizens&world=${encodeURIComponent(worldName)}`)
            ]);
            const settings = await settingsRes.json();
            const citizens = await citizensRes.json();

            if (settings.success && citizens.success) {
                const hideSeedChecked = settings.hideSeed == 1 ? 'checked' : '';
                const autostartChecked = settings.autostart == 1 ? 'checked' : '';
                const citizensText = citizens.citizens ? citizens.citizens.replace(/ /g, '\n') : '';
                const isPublic = citizens.public ? 'checked' : '';

                document.getElementById('settingsModalBody').innerHTML = `
                    <div style="margin-bottom: 1.5rem;">
                        <h6 style="color: var(--text-secondary); margin-bottom: 1rem; font-size: 0.875rem; text-transform: uppercase; letter-spacing: 0.05em;">World Information</h6>
                        <div style="background: var(--bg-primary); border-radius: 0.5rem; padding: 1rem;">
                            <div style="display: flex; justify-content: space-between; padding: 0.5rem 0; border-bottom: 1px solid var(--border-light);">
                                <span style="color: var(--text-secondary);">Endpoint</span>
                                <code style="font-size: 0.85rem; color: var(--accent-primary);">${settings.endpoint}:${settings.port}</code>
                            </div>
                            <div style="display: flex; justify-content: space-between; padding: 0.5rem 0; border-bottom: 1px solid var(--border-light);">
                                <span style="color: var(--text-secondary);">MD5 Hash</span>
                                <code style="font-size: 0.75rem; color: var(--accent-secondary);">${settings.md5 || 'N/A'}</code>
                            </div>
                            <div style="display: flex; justify-content: space-between; padding: 0.5rem 0; border-bottom: 1px solid var(--border-light);">
                                <span style="color: var(--text-secondary);">Seed</span>
                                <code style="color: var(--accent-primary);">${settings.seed || 'N/A'}</code>
                            </div>
                            <div style="display: flex; justify-content: space-between; padding: 0.5rem 0; border-bottom: 1px solid var(--border-light);">
                                <span style="color: var(--text-secondary);">Date Deployed</span>
                                <span>${settings.dateDeployed || 'N/A'}</span>
                            </div>
                            <div style="display: flex; justify-content: space-between; padding: 0.5rem 0;">
                                <span style="color: var(--text-secondary);">Date Updated</span>
                                <span>${settings.dateUpdated || 'N/A'}</span>
                            </div>
                        </div>
                    </div>
                    <div style="margin-bottom: 1.5rem;">
                        <h6 style="color: var(--text-secondary); margin-bottom: 1rem; font-size: 0.875rem; text-transform: uppercase; letter-spacing: 0.05em;">Startup Settings</h6>
                        <div style="display: flex; justify-content: space-between; align-items: center; background: var(--bg-primary); border-radius: 0.5rem; padding: 1rem;">
                            <div>
                                <span style="display: block; margin-bottom: 0.25rem;">Auto-Start</span>
                                <small style="color: var(--text-muted);">Automatically start this world when PhValheim server starts.</small>
                            </div>
                            <label class="switch" style="margin-left: 1rem;">
                                <input type="checkbox" ${autostartChecked} onchange="toggleAutostart('${worldName}', this.checked)">
                                <span class="slider round"></span>
                            </label>
                        </div>
                    </div>
                    <div style="margin-bottom: 1.5rem;">
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
                    <div>
                        <h6 style="color: var(--text-secondary); margin-bottom: 1rem; font-size: 0.875rem; text-transform: uppercase; letter-spacing: 0.05em;">Citizens</h6>
                        <div style="margin-bottom: 1rem;">
                            <p style="color: var(--text-secondary); font-size: 0.875rem; margin-bottom: 0.5rem;">
                                Add SteamIDs to grant access (one per line):
                            </p>
                            <p style="color: var(--text-muted); font-size: 0.75rem; margin-bottom: 1rem;">
                                <em>Note: SteamIDs are ignored when world is set to public.</em>
                            </p>
                            <textarea id="settingsCitizensTextarea" class="form-control" style="min-height: 150px; font-family: var(--font-mono); font-size: 0.875rem; resize: vertical;" placeholder="Enter SteamIDs, one per line">${citizensText}</textarea>
                        </div>
                        <div style="display: flex; justify-content: center; margin-bottom: 1rem;">
                            <button type="button" class="action-btn" onclick="openSteamIdLookup()">
                                <svg width="14" height="14" fill="none" stroke="currentColor" viewBox="0 0 24 24" style="margin-right: 0.375rem;">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M21 21l-6-6m2-5a7 7 0 11-14 0 7 7 0 0114 0z"/>
                                </svg>
                                Look Up SteamID
                            </button>
                        </div>
                        <div style="display: flex; justify-content: space-between; align-items: center; background: var(--bg-primary); border-radius: 0.5rem; padding: 1rem; margin-bottom: 1rem;">
                            <div>
                                <span style="display: block; margin-bottom: 0.25rem;">Public World</span>
                                <small style="color: var(--text-muted);">Allow all players to access this world.</small>
                            </div>
                            <label class="switch">
                                <input type="checkbox" id="settingsPublicToggle" ${isPublic}>
                                <span class="slider round"></span>
                            </label>
                        </div>
                        <div style="display: flex; gap: 0.75rem; justify-content: flex-end; padding-top: 1rem; border-top: 1px solid var(--border-light);">
                            <button class="action-btn success" onclick="saveSettingsCitizens()">Save Settings</button>
                        </div>
                        <div id="settingsCitizensSaveStatus" style="text-align: center; margin-top: 0.75rem; font-size: 0.875rem;"></div>
                    </div>
                `;
            } else {
                document.getElementById('settingsModalBody').innerHTML = '<div style="text-align: center; padding: 2rem; color: var(--danger);">Error loading settings</div>';
            }
        } catch (error) {
            document.getElementById('settingsModalBody').innerHTML = '<div style="text-align: center; padding: 2rem; color: var(--danger);">Error loading settings</div>';
        }
    }

    async function saveSettingsCitizens() {
        const citizens = document.getElementById('settingsCitizensTextarea').value;
        const isPublic = document.getElementById('settingsPublicToggle').checked ? 1 : 0;
        const statusEl = document.getElementById('settingsCitizensSaveStatus');

        statusEl.innerHTML = '<span style="color: var(--text-secondary);">Saving...</span>';

        try {
            const response = await fetch('adminAPI.php?action=saveCitizens', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({
                    world: currentSettingsWorld,
                    citizens: citizens,
                    public: isPublic
                })
            });
            const data = await response.json();

            if (data.success) {
                statusEl.innerHTML = '<span style="color: var(--success);">Saved successfully!</span>';
                setTimeout(() => { statusEl.innerHTML = ''; }, 2000);
            } else {
                statusEl.innerHTML = `<span style="color: var(--danger);">Error: ${data.error || 'Failed to save'}</span>`;
            }
        } catch (error) {
            statusEl.innerHTML = '<span style="color: var(--danger);">Error saving citizens</span>';
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

    // Sidebar collapse toggle
    function toggleSidebarCollapse() {
        const layout = document.querySelector('.admin-layout');
        layout.classList.toggle('sidebar-collapsed');
        localStorage.setItem('sidebarCollapsed', layout.classList.contains('sidebar-collapsed') ? '1' : '0');
    }

    // Restore sidebar state on page load
    (function() {
        if (localStorage.getItem('sidebarCollapsed') === '1') {
            document.querySelector('.admin-layout').classList.add('sidebar-collapsed');
        }
    })();

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

    // Update world tick health indicators
    function updateWorldHealth(healthData) {
        if (!healthData) return;

        Object.entries(healthData).forEach(([worldName, health]) => {
            const container = document.querySelector(`.world-resources[data-world="${worldName}"]`);
            if (!container) return;

            const loadFill = container.querySelector('.world-load-fill');
            const loadValue = container.querySelector('.world-load-value');

            if (loadFill && health.tick_health_pct !== undefined) {
                // Set bar width to health percentage
                const healthPct = Math.min(health.tick_health_pct, 100);
                loadFill.style.width = healthPct + '%';

                // Color based on health threshold
                let color;
                if (healthPct >= 90) {
                    color = '#4ade80';  // green — healthy
                } else if (healthPct >= 70) {
                    color = '#fb923c';  // amber — busy
                } else {
                    color = '#f87171';  // red — lagging
                }
                loadFill.style.backgroundColor = color;
            }

            if (loadValue && health.measured_tps !== undefined) {
                // Show measured TPS
                loadValue.textContent = Math.round(health.measured_tps) + ' TPS';
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

    // Fetch world health data from plugin
    async function fetchWorldHealth() {
        try {
            const response = await fetch('adminAPI.php?action=getWorldHealth');
            const data = await response.json();
            if (data.success && data.health) {
                updateWorldHealth(data.health);
            }
        } catch (error) {
            console.error('Failed to fetch world health:', error);
        }
    }

    // Poll world stats every 3 seconds
    setInterval(fetchWorldStats, 3000);

    // Poll world health every 5 seconds (matches plugin flush interval)
    setInterval(fetchWorldHealth, 5000);

    // Intercept world action links (start/stop/update/delete) and use AJAX instead of page reload
    document.addEventListener('click', function(e) {
        const link = e.target.closest('a[href]');
        if (!link) return;

        const href = link.getAttribute('href');
        const actionMap = {
            'start_world': 'start',
            'stop_world': 'stop',
            'update_world': 'update',
            'delete_world': 'delete'
        };

        let matched = null;
        for (const [param, cmd] of Object.entries(actionMap)) {
            if (href && href.includes(param + '=')) {
                const url = new URL(href, window.location.origin);
                matched = { cmd: cmd, world: url.searchParams.get(param) };
                break;
            }
        }

        if (!matched) return;

        e.preventDefault();

        if (matched.cmd === 'delete' && !confirm('Are you sure you want to delete this world?')) return;

        fetch(`adminAPI.php?action=worldAction&cmd=${matched.cmd}&world=${encodeURIComponent(matched.world)}`)
            .then(r => r.json())
            .then(() => fetchWorldStatus())
            .catch(err => console.error('World action failed:', err));
    });
    </script>

<span id="piEgg" style="position:fixed;bottom:4px;right:6px;font-size:9px;color:rgba(255,255,255,0.08);cursor:default;z-index:9999;user-select:none;line-height:1;">&pi;</span>
<div id="piModal" style="display:none;position:fixed;top:0;left:0;right:0;bottom:0;background:rgba(0,0,0,0.7);z-index:10000;justify-content:center;align-items:center;cursor:pointer;" onclick="this.style.display='none';">
    <img src="/images/lordnikon.png" style="max-width:90%;max-height:90%;border-radius:6px;box-shadow:0 0 30px rgba(0,0,0,0.8);">
</div>
<script>document.getElementById('piEgg').addEventListener('click',function(){document.getElementById('piModal').style.display='flex';});</script>
</body>
</html>
