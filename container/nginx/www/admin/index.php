<?php
include '/opt/stateless/nginx/www/includes/config_env_puller.php';
include '/opt/stateless/nginx/www/includes/phvalheim-frontend-config.php';
include '../includes/db_sets.php';
include '../includes/db_gets.php';

// Redirect to setup wizard if fresh install (but not for upgrades)
if ($setupComplete === 0) {
    $worldCheck = $pdo->query("SELECT COUNT(*) FROM worlds")->fetchColumn();
    if ((int)$worldCheck > 0) {
        // Upgrade in progress — engine migration hasn't completed yet
        // Show a brief auto-refreshing page instead of the setup wizard
        http_response_code(200);
        echo '<!DOCTYPE html><html><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1">';
        echo '<meta http-equiv="refresh" content="3">';
        echo '<link rel="icon" type="image/svg+xml" href="/images/phvalheim_favicon.svg">';
        echo '<link rel="stylesheet" href="/css/phvalheimStyles.css">';
        echo '<style>@keyframes pulse{0%,100%{opacity:1}50%{opacity:.4}}.startup-logo{animation:pulse 2s ease-in-out infinite}</style>';
        echo '</head>';
        echo '<body style="display:flex;align-items:center;justify-content:center;min-height:100vh;background:var(--bg-primary);color:var(--text-primary);">';
        echo '<div style="text-align:center;max-width:400px;padding:2rem;">';
        echo '<img src="/images/phvalheim_favicon.svg" class="startup-logo" style="width:64px;height:64px;margin-bottom:1.5rem;" alt="PhValheim">';
        echo '<h2 style="margin-bottom:0.75rem;">Migrating Settings&hellip;</h2>';
        echo '<p style="color:var(--text-muted);">PhValheim is upgrading your configuration. This page will refresh automatically.</p>';
        echo '</div></body></html>';
        exit;
    }
    header('Location: setup.php');
    exit;
}

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
                    <a href="#" onclick="showServerSettingsModal(); return false;" class="nav-item">
                        <svg class="nav-icon" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 6V4m0 2a2 2 0 100 4m0-4a2 2 0 110 4m-6 8a2 2 0 100-4m0 4a2 2 0 110-4m0 4v2m0-6V4m6 6v10m6-2a2 2 0 100-4m0 4a2 2 0 110-4m0 4v2m0-6V4"/>
                        </svg>
                        Server Settings
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
                    <button class="ai-helper-btn" id="aiHelperBtn" onclick="toggleAiPanel()" title="AI Helper" style="display:none">
                        <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                            <polygon points="12 2 15.09 8.26 22 9.27 17 14.14 18.18 21.02 12 17.77 5.82 21.02 7 14.14 2 9.27 8.91 8.26 12 2"/>
                        </svg>
                        AI Helper
                    </button>
                    <span class="live-indicator">
                        <span class="live-indicator-dot"></span>
                        Live
                    </span>
                    <span class="header-time" id="currentTime"><?php echo $timeNow; ?></span>
                </div>
            </header>

            <?php
            // Check for missing critical configuration
            $missingSettings = [];
            if (empty($steamAPIKey)) $missingSettings[] = 'Steam API Key';
            if (empty($gameDNS)) $missingSettings[] = 'Game DNS';
            if (empty($basePort)) $missingSettings[] = 'Base Port';
            if (empty($phvalheimClientURL)) $missingSettings[] = 'Client Download URL';
            ?>
            <?php if (!empty($missingSettings)): ?>
            <div id="criticalConfigBanner" style="margin: 0.75rem 0; padding: 1rem 1.25rem; background: rgba(248, 113, 113, 0.08); border: 1px solid var(--danger); border-radius: 8px; display: flex; align-items: flex-start; gap: 0.75rem;">
                <svg width="22" height="22" fill="none" stroke="var(--danger)" viewBox="0 0 24 24" style="flex-shrink: 0; margin-top: 1px;">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-2.5L13.732 4.5c-.77-.833-2.694-.833-3.464 0L3.34 16.5c-.77.833.192 2.5 1.732 2.5z"/>
                </svg>
                <div style="flex: 1;">
                    <div style="font-weight: 600; color: var(--danger); font-size: 0.95rem; margin-bottom: 0.4rem;">Critical Configuration Missing</div>
                    <div style="color: var(--text-secondary); font-size: 0.85rem; margin-bottom: 0.6rem;">
                        The following required settings are not configured:
                        <strong style="color: var(--text-primary);"><?php echo implode(', ', $missingSettings); ?></strong>
                    </div>
                    <button class="action-btn" onclick="showServerSettingsModal()" style="padding: 0.35rem 1rem; font-size: 0.8rem; background: var(--danger); border-color: var(--danger); color: #fff;">Open Server Settings</button>
                </div>
            </div>
            <?php endif; ?>

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

                <div class="stat-card" id="storageCard" style="min-width:280px;">
                    <div class="stat-icon disk" id="storageIcon">
                        <svg width="24" height="24" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 7v10c0 2.21 3.582 4 8 4s8-1.79 8-4V7M4 7c0 2.21 3.582 4 8 4s8-1.79 8-4M4 7c0-2.21 3.582-4 8-4s8 1.79 8 4m0 5c0 2.21-3.582 4-8 4s-8-1.79-8-4"/>
                        </svg>
                    </div>
                    <div class="stat-content" id="storageContent" style="flex:1;min-width:0;">
                        <div class="stat-label">Storage</div>
                        <div id="storageVolumes" style="margin-top:0.25rem;">
                            <div class="stat-subtext">Loading...</div>
                        </div>
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
                        'stopping' => 'Stopping',
                        'backup' => 'Backup'
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

    <!-- Settings Modal (includes Citizens + Backups) -->
    <div class="mods-modal-overlay" id="settingsModalOverlay" onclick="closeSettingsModal(event)">
        <div class="mods-modal" onclick="event.stopPropagation()" style="max-width: 950px;">
            <div class="mods-modal-header">
                <h3 class="mods-modal-title" id="settingsModalTitle">World Settings</h3>
                <button class="mods-modal-close" onclick="closeSettingsModal()">&times;</button>
            </div>
            <div class="backup-tab-bar" id="settingsTabBar" style="display:none;">
                <button class="backup-tab active" data-tab="settingsTab" onclick="switchSettingsTab('settingsTab', this)">Settings</button>
                <button class="backup-tab" data-tab="backupsTab" onclick="switchSettingsTab('backupsTab', this)">Backups</button>
            </div>
            <div class="mods-modal-body" id="settingsModalBody">
                <div style="text-align: center; padding: 2rem; color: var(--text-muted);">Loading...</div>
            </div>
        </div>
    </div>

    <!-- Backup Restore Confirmation Modal -->
    <div class="mods-modal-overlay" id="restoreConfirmOverlay" onclick="closeRestoreConfirm(event)" style="z-index:1060;">
        <div class="mods-modal" onclick="event.stopPropagation()" style="max-width:520px;">
            <div class="mods-modal-header" style="background:var(--bg-secondary);border-bottom:2px solid var(--warning);">
                <h3 class="mods-modal-title" style="color:var(--warning);">
                    <svg width="20" height="20" fill="none" stroke="var(--warning)" viewBox="0 0 24 24" style="vertical-align:middle;margin-right:0.5rem;">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-2.5L13.732 4c-.77-.833-1.964-.833-2.732 0L4.082 16.5c-.77.833.192 2.5 1.732 2.5z"/>
                    </svg>
                    Restore Backup
                </h3>
                <button class="mods-modal-close" onclick="closeRestoreConfirm()">&times;</button>
            </div>
            <div class="mods-modal-body" id="restoreConfirmBody">
            </div>
        </div>
    </div>

    <!-- Backup Restore Progress Modal -->
    <div class="mods-modal-overlay" id="restoreProgressOverlay" style="z-index:1070;">
        <div class="mods-modal" onclick="event.stopPropagation()" style="max-width:520px;">
            <div class="mods-modal-header" style="background:var(--bg-secondary);border-bottom:2px solid var(--accent-primary);">
                <h3 class="mods-modal-title" style="color:var(--accent-primary);">
                    <svg width="20" height="20" fill="none" stroke="var(--accent-primary)" viewBox="0 0 24 24" style="vertical-align:middle;margin-right:0.5rem;">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 4v5h.582m15.356 2A8.001 8.001 0 004.582 9m0 0H9m11 11v-5h-.581m0 0a8.003 8.003 0 01-15.357-2m15.357 2H15"/>
                    </svg>
                    Restoring World
                </h3>
            </div>
            <div class="mods-modal-body" id="restoreProgressBody">
                <div style="text-align:center;padding:2rem;color:var(--text-muted)">
                    <div class="backup-spinner"></div>
                    <div style="margin-top:1rem;">Initializing restore...</div>
                </div>
            </div>
        </div>
    </div>

    <!-- Backup Delete Confirmation Modal -->
    <div class="mods-modal-overlay" id="deleteConfirmOverlay" onclick="closeDeleteConfirm(event)" style="z-index:1060;">
        <div class="mods-modal" onclick="event.stopPropagation()" style="max-width:420px;">
            <div class="mods-modal-header" style="background:var(--bg-secondary);border-bottom:2px solid var(--danger);">
                <h3 class="mods-modal-title" style="color:var(--danger);">
                    <svg width="20" height="20" fill="none" stroke="var(--danger)" viewBox="0 0 24 24" style="vertical-align:middle;margin-right:0.5rem;">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 7l-.867 12.142A2 2 0 0116.138 21H7.862a2 2 0 01-1.995-1.858L5 7m5 4v6m4-6v6m1-10V4a1 1 0 00-1-1h-4a1 1 0 00-1 1v3M4 7h16"/>
                    </svg>
                    Delete Backup
                </h3>
                <button class="mods-modal-close" onclick="closeDeleteConfirm()">&times;</button>
            </div>
            <div class="mods-modal-body" id="deleteConfirmBody">
            </div>
        </div>
    </div>

    <!-- Backup Create Confirmation Modal -->
    <div class="mods-modal-overlay" id="backupConfirmOverlay" onclick="document.getElementById('backupConfirmOverlay').classList.remove('show')" style="z-index:1060;">
        <div class="mods-modal" onclick="event.stopPropagation()" style="max-width:520px;">
            <div class="mods-modal-header" style="background:var(--bg-secondary);border-bottom:2px solid var(--success);">
                <h3 class="mods-modal-title" style="color:var(--success);">
                    <svg width="20" height="20" fill="none" stroke="var(--success)" viewBox="0 0 24 24" style="vertical-align:middle;margin-right:0.5rem;">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M8 7H5a2 2 0 00-2 2v9a2 2 0 002 2h14a2 2 0 002-2V9a2 2 0 00-2-2h-3m-1 4l-3 3m0 0l-3-3m3 3V4"/>
                    </svg>
                    Create Backup
                </h3>
                <button class="mods-modal-close" onclick="document.getElementById('backupConfirmOverlay').classList.remove('show')">&times;</button>
            </div>
            <div class="mods-modal-body" id="backupConfirmBody">
            </div>
        </div>
    </div>

    <!-- Backup View Details Modal -->
    <div class="mods-modal-overlay" id="backupViewOverlay" onclick="document.getElementById('backupViewOverlay').classList.remove('show')" style="z-index:1060;">
        <div class="mods-modal" onclick="event.stopPropagation()" style="max-width:560px;">
            <div class="mods-modal-header" style="background:var(--bg-secondary);border-bottom:2px solid var(--accent-primary);">
                <h3 class="mods-modal-title" style="color:var(--accent-primary);">
                    <svg width="20" height="20" fill="none" stroke="var(--accent-primary)" viewBox="0 0 24 24" style="vertical-align:middle;margin-right:0.5rem;">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12h6m-6 4h6m2 5H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z"/>
                    </svg>
                    Backup Details
                </h3>
                <button class="mods-modal-close" onclick="document.getElementById('backupViewOverlay').classList.remove('show')">&times;</button>
            </div>
            <div class="mods-modal-body" id="backupViewBody">
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

    <!-- Migration Notice Dialog -->
    <?php if ($setupComplete == 1 && $migrationNoticeShown == 0): ?>
    <div class="mods-modal-overlay show" id="migrationNoticeOverlay">
        <div class="mods-modal" onclick="event.stopPropagation()" style="max-width: 600px;">
            <div class="mods-modal-header">
                <h3 class="mods-modal-title">
                    <svg width="20" height="20" fill="none" stroke="var(--success)" viewBox="0 0 24 24" style="vertical-align: middle; margin-right: 0.5rem;">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z"/>
                    </svg>
                    Settings Migration Complete
                </h3>
            </div>
            <div class="mods-modal-body">
                <p style="color: var(--text-secondary); font-size: 0.9rem; margin-bottom: 1rem;">
                    Your server settings have been migrated from environment variables to the PhValheim database. You can now manage all settings directly from the Admin UI using <strong>Server Settings</strong> in the sidebar.
                </p>
                <p style="color: var(--text-secondary); font-size: 0.9rem; margin-bottom: 1rem;">
                    You may remove the following environment variables from your Docker run command, Compose file, or Unraid template &mdash; they are no longer needed:
                </p>
                <div style="background: var(--bg-primary); border-radius: 6px; padding: 0.75rem 1rem; margin-bottom: 1rem; font-family: var(--font-mono); font-size: 0.8rem; color: var(--text-muted);">
                    basePort, backupsToKeep, gameDNS, steamAPIKey, phvalheimClientURL, sessionTimeout, openaiApiKey, geminiApiKey, claudeApiKey, ollamaUrl
                </div>
                <p style="color: var(--text-muted); font-size: 0.8rem; margin-bottom: 1.25rem;">
                    Port mappings (<code>-p</code>) and volume mounts (<code>-v</code>) must remain in your Docker configuration.
                </p>

                <h6 style="color: var(--text-secondary); margin-bottom: 0.75rem; font-size: 0.8rem; text-transform: uppercase; letter-spacing: 0.05em;">Migrated Values</h6>
                <table style="width: 100%; font-size: 0.8rem; margin-bottom: 1.25rem;">
                    <tbody id="migrationValuesTable"></tbody>
                </table>

                <div style="text-align: center;">
                    <button class="action-btn success" onclick="dismissMigrationNotice()" style="padding: 0.5rem 2rem; font-size: 0.9rem;">Got it</button>
                </div>
            </div>
        </div>
    </div>
    <?php endif; ?>

    <!-- Server Settings Modal -->
    <div class="mods-modal-overlay" id="serverSettingsOverlay" onclick="closeServerSettingsModal(event)">
        <div class="mods-modal" onclick="event.stopPropagation()" style="max-width: 700px;">
            <div class="mods-modal-header">
                <h3 class="mods-modal-title">Server Settings</h3>
                <div style="display:flex;align-items:center;gap:0.75rem;">
                    <a href="https://github.com/brianmiller/phvalheim-server" target="_blank" rel="noopener" title="PhValheim Documentation" style="color:var(--text-muted);font-size:0.75rem;text-decoration:none;display:flex;align-items:center;gap:0.3rem;opacity:0.7;transition:opacity 0.2s;" onmouseover="this.style.opacity='1'" onmouseout="this.style.opacity='0.7'">
                        <svg width="14" height="14" viewBox="0 0 24 24" fill="currentColor"><path d="M12 0c-6.626 0-12 5.373-12 12 0 5.302 3.438 9.8 8.207 11.387.599.111.793-.261.793-.577v-2.234c-3.338.726-4.033-1.416-4.033-1.416-.546-1.387-1.333-1.756-1.333-1.756-1.089-.745.083-.729.083-.729 1.205.084 1.839 1.237 1.839 1.237 1.07 1.834 2.807 1.304 3.492.997.107-.775.418-1.305.762-1.604-2.665-.305-5.467-1.334-5.467-5.931 0-1.311.469-2.381 1.236-3.221-.124-.303-.535-1.524.117-3.176 0 0 1.008-.322 3.301 1.23.957-.266 1.983-.399 3.003-.404 1.02.005 2.047.138 3.006.404 2.291-1.552 3.297-1.23 3.297-1.23.653 1.653.242 2.874.118 3.176.77.84 1.235 1.911 1.235 3.221 0 4.609-2.807 5.624-5.479 5.921.43.372.823 1.102.823 2.222v3.293c0 .319.192.694.801.576 4.765-1.589 8.199-6.086 8.199-11.386 0-6.627-5.373-12-12-12z"/></svg>
                        Docs
                    </a>
                    <button class="mods-modal-close" onclick="closeServerSettingsModal()">&times;</button>
                </div>
            </div>
            <div class="mods-modal-body" id="serverSettingsBody">
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

    // Backup disk stats
    // Global tooltip system — appends to <body> to escape overflow:hidden containers
    (function() {
        const tip = document.createElement('div');
        tip.id = 'phvTooltip';
        tip.style.cssText = 'position:fixed;z-index:99999;background:var(--bg-primary,#1a1a2e);color:var(--text-primary,#e0e0e0);border:1px solid var(--border-color,#333);border-radius:0.375rem;padding:0.4rem 0.6rem;font-size:0.7rem;font-weight:400;line-height:1.4;max-width:280px;pointer-events:none;box-shadow:0 4px 12px rgba(0,0,0,0.4);display:none;';
        document.body.appendChild(tip);

        document.addEventListener('mouseover', function(e) {
            const el = e.target.closest('[data-tip]');
            if (!el) { tip.style.display = 'none'; return; }
            tip.textContent = el.getAttribute('data-tip');
            tip.style.display = 'block';
            const r = el.getBoundingClientRect();
            let top = r.top - tip.offsetHeight - 6;
            let left = r.left + r.width / 2 - tip.offsetWidth / 2;
            // keep within viewport
            if (top < 4) top = r.bottom + 6;
            if (left < 4) left = 4;
            if (left + tip.offsetWidth > window.innerWidth - 4) left = window.innerWidth - tip.offsetWidth - 4;
            tip.style.top = top + 'px';
            tip.style.left = left + 'px';
        });
        document.addEventListener('mouseout', function(e) {
            const el = e.target.closest('[data-tip]');
            if (el) tip.style.display = 'none';
        });
    })();

    function fmtBytes(bytes) {
        if (bytes >= 1099511627776) return (bytes / 1099511627776).toFixed(1) + ' TB';
        if (bytes >= 1073741824) return (bytes / 1073741824).toFixed(1) + ' GB';
        if (bytes >= 1048576) return (bytes / 1048576).toFixed(1) + ' MB';
        return (bytes / 1024).toFixed(0) + ' KB';
    }

    async function fetchVolumeStats() {
        try {
            const res = await fetch('adminAPI.php?action=getVolumeStats');
            const data = await res.json();
            if (!data.success) return;

            const volContainer = document.getElementById('storageVolumes');
            const card = document.getElementById('storageCard');
            const icon = document.getElementById('storageIcon');
            let html = '';
            let worstPerc = 0;
            let hasWarning = false;

            data.volumes.forEach((vol, idx) => {
                const perc = vol.perc;
                if (perc > worstPerc) worstPerc = perc;

                let barColor, warnHtml = '';
                if (perc > 90) {
                    barColor = 'var(--danger)';
                    warnHtml = ' <span style="color:var(--danger);font-weight:600;">&#9888;</span>';
                    hasWarning = true;
                } else if (perc > 75) {
                    barColor = 'var(--warning)';
                    warnHtml = ' <span style="color:var(--warning);font-weight:600;">&#9888;</span>';
                    hasWarning = true;
                } else {
                    barColor = 'var(--success)';
                }

                // Extra detail for backup info
                let extraDetail = '';
                if (vol.name === 'Backups') {
                    extraDetail = data.backupCount + ' backup' + (data.backupCount !== 1 ? 's' : '') + ' (' + fmtBytes(data.backupTotalSize) + ')';
                }
                if (vol.name === 'Data' && !data.backupMounted) {
                    extraDetail = data.backupCount + ' backup' + (data.backupCount !== 1 ? 's' : '') + ' (' + fmtBytes(data.backupTotalSize) + ')';
                }

                if (idx > 0) html += '<div style="border-top:1px solid var(--border-light);margin:0.3rem 0;"></div>';

                html += `<div>
                        <div style="display:flex;justify-content:space-between;align-items:baseline;">
                            <span style="font-size:0.7rem;font-weight:600;color:var(--text-primary);">${vol.name}</span>
                            <span style="font-size:0.65rem;color:var(--text-muted);font-family:var(--font-mono);white-space:nowrap;">${vol.usedH}/${vol.totalH} (${vol.freeH} free)${warnHtml}</span>
                        </div>
                        <div style="font-size:0.55rem;color:var(--text-muted);font-family:var(--font-mono);margin-top:-0.05rem;">${vol.path}</div>
                        <div style="height:4px;background:var(--bg-tertiary);border-radius:2px;margin:0.15rem 0;overflow:hidden;">
                            <div style="height:100%;width:${perc}%;background:${barColor};border-radius:2px;transition:width 0.5s ease;"></div>
                        </div>
                        ${extraDetail ? '<div style="font-size:0.6rem;color:var(--text-muted);">' + extraDetail + '</div>' : ''}
                    </div>`;
            });

            // Warnings — compact single line each
            if (!data.backupMounted) {
                html += '<div style="font-size:0.6rem;font-weight:600;color:var(--danger);margin-top:0.25rem;white-space:nowrap;">&#9888; No dedicated backup volume</div>';
                hasWarning = true;
            }

            if (data.orphanedCount > 0) {
                html += `<div style="font-size:0.6rem;color:var(--warning);margin-top:0.2rem;white-space:nowrap;">&#9888; ${data.orphanedCount} orphaned record${data.orphanedCount !== 1 ? 's' : ''} <button onclick="purgeOrphanedBackups()" style="font-size:0.55rem;padding:0.05rem 0.3rem;margin-left:0.2rem;border:1px solid var(--warning);color:var(--warning);background:transparent;border-radius:2px;cursor:pointer;">Clean up</button></div>`;
                hasWarning = true;
            }

            volContainer.innerHTML = html;

            // Card border + icon color based on worst status
            if (worstPerc > 90 || (!data.backupMounted)) {
                card.style.borderLeft = '3px solid var(--danger)';
                icon.style.background = 'rgba(var(--danger-rgb,220,53,69),0.15)';
                icon.style.color = 'var(--danger)';
            } else if (worstPerc > 75) {
                card.style.borderLeft = '3px solid var(--warning)';
                icon.style.background = 'rgba(var(--warning-rgb,255,193,7),0.15)';
                icon.style.color = 'var(--warning)';
            } else {
                card.style.borderLeft = 'none';
                icon.className = 'stat-icon disk';
                icon.style.background = '';
                icon.style.color = '';
            }
        } catch(e) {}
    }

    // Start stats polling for charts
    function startStatsPolling() {
        fetchSystemStats();
        fetchVolumeStats();
        statsPollTimer = setInterval(fetchSystemStats, STATS_POLL_INTERVAL);
        setInterval(fetchVolumeStats, 60000); // refresh volume stats every 60s
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
                updateAiContextWorlds(data.worlds);
            }
        } catch (error) {
            console.error('Failed to fetch world status:', error);
        }
    }

    // Populate world logs in the AI context dropdown
    let aiContextWorldsPopulated = false;
    function updateAiContextWorlds(worlds) {
        if (aiContextWorldsPopulated) return; // only populate once
        const group = document.getElementById('aiContextWorldGroup');
        if (!group) return;
        group.innerHTML = '';
        worlds.forEach(w => {
            const opt = document.createElement('option');
            opt.value = 'world:' + w.name;
            opt.textContent = w.name;
            group.appendChild(opt);
        });
        aiContextWorldsPopulated = true;
        // Restore saved context (might be a world log)
        const savedContext = getCookie('aiContext');
        if (savedContext) {
            const sel = document.getElementById('aiContextSelect');
            for (let i = 0; i < sel.options.length; i++) {
                if (sel.options[i].value === savedContext) {
                    sel.value = savedContext;
                    break;
                }
            }
        }
    }

    // Called from log viewer windows via window.opener
    function openAiHelperWithContext(contextValue, prompt, displayLabel) {
        // Ensure worlds are in the dropdown
        const sel = document.getElementById('aiContextSelect');
        // Set the context value (may need to add it if world logs aren't populated yet)
        let found = false;
        for (let i = 0; i < sel.options.length; i++) {
            if (sel.options[i].value === contextValue) {
                sel.value = contextValue;
                found = true;
                break;
            }
        }
        if (!found && contextValue.startsWith('world:')) {
            // Add it dynamically
            const group = document.getElementById('aiContextWorldGroup');
            const opt = document.createElement('option');
            opt.value = contextValue;
            opt.textContent = contextValue.replace('world:', '');
            group.appendChild(opt);
            sel.value = contextValue;
        }
        setCookie('aiContext', contextValue, 365);
        // Open the panel
        const panel = document.getElementById('aiPanel');
        if (!panel.classList.contains('open')) {
            toggleAiPanel();
        }
        // Pre-fill and auto-send prompt if provided
        if (prompt) {
            document.getElementById('aiInput').value = prompt;
            sendAiMessage(displayLabel || null);
        } else {
            document.getElementById('aiInput').focus();
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
            'stopping': 'Stopping',
            'backup': 'Backup'
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

    function switchSettingsTab(tabId, btn) {
        document.querySelectorAll('#settingsTabBar .backup-tab').forEach(t => t.classList.remove('active'));
        btn.classList.add('active');
        document.querySelectorAll('.settings-tab-pane').forEach(p => p.style.display = 'none');
        document.getElementById(tabId).style.display = 'block';
        if (tabId === 'backupsTab' && !document.getElementById('backupsTab').dataset.loaded) {
            loadWorldBackups(currentSettingsWorld);
        }
    }

    function formatBytes(bytes) {
        if (!bytes || bytes == 0) return '0 B';
        const k = 1024;
        const sizes = ['B', 'KB', 'MB', 'GB'];
        const i = Math.floor(Math.log(bytes) / Math.log(k));
        return parseFloat((bytes / Math.pow(k, i)).toFixed(1)) + ' ' + sizes[i];
    }

    async function loadWorldBackups(worldName) {
        const container = document.getElementById('backupsList');
        container.innerHTML = '<div style="text-align:center;padding:1rem;color:var(--text-muted)">Loading backups...</div>';

        try {
            const [backupsRes, bkSettingsRes] = await Promise.all([
                fetch(`adminAPI.php?action=getWorldBackups&world=${encodeURIComponent(worldName)}`),
                fetch(`adminAPI.php?action=getWorldBackupSettings&world=${encodeURIComponent(worldName)}`)
            ]);
            const backupsData = await backupsRes.json();
            const bkSettings = await bkSettingsRes.json();

            if (bkSettings.success) {
                const bs = bkSettings.settings;
                const useGlobal = bs.backup_use_global == 1;
                document.getElementById('bk-useGlobal').checked = useGlobal;
                document.getElementById('bk-overrideFields').style.display = useGlobal ? 'none' : 'block';
                document.getElementById('bk-interval').value = bs.backup_interval_minutes;
                document.getElementById('bk-requireActivity').value = bs.backup_require_activity;
                document.getElementById('bk-retainAllHours').value = bs.backup_retain_all_hours;
                document.getElementById('bk-retainDailyDays').value = bs.backup_retain_daily_days;
                document.getElementById('bk-retainWeeklyDays').value = bs.backup_retain_weekly_days;
                document.getElementById('bk-retainMonthlyMonths').value = bs.backup_retain_monthly_months;
                document.getElementById('bk-compression').value = bs.backup_compression || 'none';
                document.getElementById('bk-compressionHour').value = bs.backup_compression_hour !== undefined ? bs.backup_compression_hour : 3;
                document.getElementById('bk-cpuPriority').value = bs.backup_cpu_priority !== undefined ? bs.backup_cpu_priority : 10;
                document.getElementById('bk-ioPriority').value = bs.backup_io_priority || 'low';
                document.getElementById('bk-compressionLevel').value = bs.backup_compression_level || 0;
            }

            if (backupsData.success && backupsData.backups) {
                if (backupsData.backups.length === 0) {
                    container.innerHTML = '<div style="text-align:center;padding:2rem;color:var(--text-muted)">No backups yet</div>';
                } else {
                    let html = '<table class="backup-table"><thead><tr>'
                        + '<th><input type="checkbox" id="bkSelectAll" onchange="toggleAllBackupCheckboxes(this)"></th>'
                        + '<th>Date</th><th>Type</th><th>Size</th><th>Compressed</th><th>Actions</th>'
                        + '</tr></thead><tbody>';
                    backupsData.backups.forEach(b => {
                        const isOrphaned = b.orphaned == 1;
                        const rowStyle = isOrphaned ? ' style="opacity:0.6;background:rgba(var(--warning-rgb,255,193,7),0.05);"' : '';
                        const typeBadge = b.type === 'manual'
                            ? '<span class="backup-badge backup-badge-manual">manual</span>'
                            : '<span class="backup-badge backup-badge-scheduled">scheduled</span>';
                        const orphanBadge = isOrphaned ? ' <span class="backup-badge" style="background:var(--warning);color:#000;font-size:0.6rem">missing</span>' : '';
                        const compBadge = b.compressed == 1
                            ? '<span class="backup-badge backup-badge-compressed">' + (b.compression_type || 'yes') + '</span>'
                            : '<span style="color:var(--text-muted);font-size:0.75rem">no</span>';
                        const meta = b.metadata ? JSON.parse(b.metadata) : {};
                        const preRestore = meta.pre_restore ? ' <span class="backup-badge" style="background:var(--warning);color:#000;font-size:0.6rem">pre-restore</span>' : '';
                        // size display: show both if compressed
                        let sizeDisplay = formatBytes(b.file_size);
                        if (b.compressed == 1 && b.uncompressed_size > 0) {
                            sizeDisplay = formatBytes(b.file_size) + '<br><span style="color:var(--text-muted);font-size:0.65rem">' + formatBytes(b.uncompressed_size) + ' uncompressed</span>';
                        } else if (b.uncompressed_size > 0 && b.uncompressed_size != b.file_size) {
                            sizeDisplay = formatBytes(b.file_size) + '<br><span style="color:var(--text-muted);font-size:0.65rem">' + formatBytes(b.uncompressed_size) + ' uncompressed</span>';
                        }
                        // Actions: orphaned backups can only be removed (no restore/download)
                        let actionsHtml;
                        if (isOrphaned) {
                            actionsHtml = '<button class="action-btn danger" style="padding:0.2rem 0.5rem;font-size:0.7rem" onclick="deleteSingleBackup(' + b.id + ')">Remove record</button>';
                        } else {
                            actionsHtml = '<button class="action-btn" style="padding:0.2rem 0.5rem;font-size:0.7rem" onclick="viewBackupDetails(' + b.id + ',\'' + b.created_at + '\',\'' + b.type + '\',' + b.file_size + ',' + (b.uncompressed_size||0) + ',' + b.compressed + ',\'' + (b.compression_type||'none') + '\',\'' + encodeURIComponent(b.metadata||'{}') + '\')">View</button> '
                                + '<button class="action-btn" style="padding:0.2rem 0.5rem;font-size:0.7rem" onclick="restoreBackup(' + b.id + ',\'' + b.created_at + '\',\'' + currentSettingsWorld + '\')">Restore</button> '
                                + '<a href="adminAPI.php?action=downloadBackup&backupId=' + b.id + '" class="action-btn" style="padding:0.2rem 0.5rem;font-size:0.7rem;text-decoration:none">Download</a> '
                                + '<button class="action-btn danger" style="padding:0.2rem 0.5rem;font-size:0.7rem" onclick="deleteSingleBackup(' + b.id + ')">Delete</button>';
                        }
                        html += '<tr' + rowStyle + '>'
                            + '<td><input type="checkbox" class="bk-check" value="' + b.id + '"></td>'
                            + '<td style="font-family:var(--font-mono);font-size:0.8rem;white-space:nowrap">' + b.created_at + preRestore + orphanBadge + '</td>'
                            + '<td>' + typeBadge + '</td>'
                            + '<td style="font-family:var(--font-mono);font-size:0.8rem">' + sizeDisplay + '</td>'
                            + '<td>' + compBadge + '</td>'
                            + '<td class="backup-actions">' + actionsHtml + '</td></tr>';
                    });
                    html += '</tbody></table>';
                    container.innerHTML = html;
                }
            } else {
                container.innerHTML = '<div style="text-align:center;padding:1rem;color:var(--danger)">Error loading backups</div>';
            }

            document.getElementById('backupsTab').dataset.loaded = '1';
        } catch(e) {
            container.innerHTML = '<div style="text-align:center;padding:1rem;color:var(--danger)">Error loading backups</div>';
        }
    }

    function toggleAllBackupCheckboxes(master) {
        document.querySelectorAll('.bk-check').forEach(cb => cb.checked = master.checked);
    }

    function viewBackupDetails(id, createdAt, type, fileSize, uncompressedSize, compressed, compressionType, metadataEncoded) {
        const meta = JSON.parse(decodeURIComponent(metadataEncoded));
        const body = document.getElementById('backupViewBody');

        // build info rows
        let html = '<div style="padding:0.25rem 0;">';

        // General info section
        html += '<div style="font-size:0.75rem;font-weight:600;color:var(--text-muted);text-transform:uppercase;letter-spacing:0.05em;margin-bottom:0.5rem;">General</div>';
        html += '<div style="background:var(--bg-primary);border-radius:0.5rem;padding:0.75rem;margin-bottom:1rem;">';
        html += detailRow('Backup ID', '#' + id);
        html += detailRow('Created', createdAt);
        html += detailRow('Type', type === 'manual' ? '<span class="backup-badge backup-badge-manual">manual</span>' : '<span class="backup-badge backup-badge-scheduled">scheduled</span>');
        if (meta.pre_restore) {
            html += detailRow('Pre-Restore', 'Safety backup before restoring from #' + meta.restored_from_id);
        }
        html += '</div>';

        // Size info
        html += '<div style="font-size:0.75rem;font-weight:600;color:var(--text-muted);text-transform:uppercase;letter-spacing:0.05em;margin-bottom:0.5rem;">Size &amp; Compression</div>';
        html += '<div style="background:var(--bg-primary);border-radius:0.5rem;padding:0.75rem;margin-bottom:1rem;">';
        html += detailRow('File Size', formatBytes(fileSize));
        if (uncompressedSize > 0 && uncompressedSize !== fileSize) {
            html += detailRow('Uncompressed Size', formatBytes(uncompressedSize));
            const ratio = ((fileSize / uncompressedSize) * 100).toFixed(1);
            html += detailRow('Compression Ratio', ratio + '%');
        }
        html += detailRow('Compressed', compressed == 1 ? '<span class="backup-badge backup-badge-compressed">' + compressionType + '</span>' : 'No');
        html += '</div>';

        // World info (from metadata)
        if (meta.seed || meta.port) {
            html += '<div style="font-size:0.75rem;font-weight:600;color:var(--text-muted);text-transform:uppercase;letter-spacing:0.05em;margin-bottom:0.5rem;">World Settings</div>';
            html += '<div style="background:var(--bg-primary);border-radius:0.5rem;padding:0.75rem;margin-bottom:1rem;">';
            if (meta.seed) html += detailRow('Seed', '<code>' + meta.seed + '</code>');
            if (meta.port !== undefined) html += detailRow('Port', meta.port);
            if (meta.beta !== undefined) html += detailRow('Beta', meta.beta == 1 ? 'Yes' : 'No');
            html += '</div>';
        }

        // Contents info
        if (meta.file_count || meta.top_dirs) {
            html += '<div style="font-size:0.75rem;font-weight:600;color:var(--text-muted);text-transform:uppercase;letter-spacing:0.05em;margin-bottom:0.5rem;">Contents</div>';
            html += '<div style="background:var(--bg-primary);border-radius:0.5rem;padding:0.75rem;margin-bottom:1rem;">';
            if (meta.file_count) html += detailRow('Files', meta.file_count + ' files in ' + (meta.dir_count||0) + ' directories');
            if (meta.has_game !== undefined) html += detailRow('Game Data', meta.has_game ? '<span style="color:var(--success)">&#10003;</span> Present' : '<span style="color:var(--text-muted)">&#10007;</span> Missing');
            if (meta.has_custom_configs !== undefined) html += detailRow('Custom Configs', meta.has_custom_configs ? '<span style="color:var(--success)">&#10003;</span> Present' : '<span style="color:var(--text-muted)">&#10007;</span> Not used');
            if (meta.has_mods !== undefined) html += detailRow('BepInEx Mods', meta.has_mods ? '<span style="color:var(--success)">&#10003;</span> Present' : '<span style="color:var(--text-muted)">&#10007;</span> None');
            if (meta.top_dirs) {
                const dirs = meta.top_dirs.split(',');
                const dirSummary = dirs.length + ' director' + (dirs.length === 1 ? 'y' : 'ies');
                const dirId = 'bkDirs_' + id;
                html += '<div style="display:flex;justify-content:space-between;padding:0.3rem 0;border-bottom:1px solid var(--border-light);"><span style="color:var(--text-muted);font-size:0.8rem;">Directories</span><span style="font-size:0.8rem;color:var(--text-primary);"><a href="#" onclick="event.preventDefault();document.getElementById(\'' + dirId + '\').style.display=document.getElementById(\'' + dirId + '\').style.display===\'none\'?\'block\':\'none\'" style="color:var(--accent-primary);text-decoration:none;font-size:0.8rem;">' + dirSummary + ' &#9662;</a></span></div>';
                html += '<div id="' + dirId + '" style="display:none;padding:0.5rem 0;"><div style="display:flex;flex-wrap:wrap;gap:0.3rem;">';
                dirs.forEach(d => {
                    html += '<code style="font-size:0.7rem;background:var(--bg-secondary);padding:0.1rem 0.4rem;border-radius:0.25rem;border:1px solid var(--border-light);">' + d.trim() + '/</code>';
                });
                html += '</div></div>';
            }
            html += '</div>';
        }

        // Mods
        if (meta.mod_names && meta.mod_names.length > 0) {
            const modId = 'bkMods_' + id;
            html += '<div style="font-size:0.75rem;font-weight:600;color:var(--text-muted);text-transform:uppercase;letter-spacing:0.05em;margin-bottom:0.5rem;">'
                + '<a href="#" onclick="event.preventDefault();document.getElementById(\'' + modId + '\').style.display=document.getElementById(\'' + modId + '\').style.display===\'none\'?\'block\':\'none\'" style="color:var(--text-muted);text-decoration:none;">Mods (' + meta.mod_names.length + ') &#9662;</a>'
                + '</div>';
            html += '<div id="' + modId + '" style="display:none;background:var(--bg-primary);border-radius:0.5rem;padding:0.75rem;margin-bottom:0.5rem;">';
            html += '<div style="display:flex;flex-direction:column;gap:0.25rem;">';
            meta.mod_names.forEach((m, i) => {
                html += '<div style="display:flex;align-items:center;gap:0.5rem;padding:0.25rem 0.4rem;background:var(--bg-secondary);border-radius:0.25rem;border:1px solid var(--border-light);font-size:0.75rem;">'
                    + '<span style="color:var(--text-muted);font-family:var(--font-mono);font-size:0.65rem;width:1.5rem;text-align:right;">' + (i+1) + '</span>'
                    + '<span style="color:var(--text-primary);">' + m + '</span></div>';
            });
            html += '</div></div>';
        }

        html += '<div style="text-align:center;margin-top:1rem;"><button class="action-btn" onclick="document.getElementById(\'backupViewOverlay\').classList.remove(\'show\')" style="padding:0.4rem 1.5rem;">Close</button></div>';
        html += '</div>';
        body.innerHTML = html;
        document.getElementById('backupViewOverlay').classList.add('show');
    }

    function detailRow(label, value) {
        return '<div style="display:flex;justify-content:space-between;padding:0.3rem 0;border-bottom:1px solid var(--border-light);"><span style="color:var(--text-muted);font-size:0.8rem;">' + label + '</span><span style="font-size:0.8rem;color:var(--text-primary);">' + value + '</span></div>';
    }

    async function confirmCreateBackup(worldName) {
        const body = document.getElementById('backupConfirmBody');
        body.innerHTML = '<div style="text-align:center;padding:2rem;color:var(--text-muted);"><div class="backup-spinner" style="width:24px;height:24px;border-width:3px;margin:0 auto 1rem;"></div>Checking disk space...</div>';
        document.getElementById('backupConfirmOverlay').classList.add('show');

        // Fetch preflight data
        let preflight = { mounted: false, worldSize: 0, freeBytes: 0 };
        try {
            const res = await fetch(`adminAPI.php?action=getBackupPreflight&world=${encodeURIComponent(worldName)}`);
            const data = await res.json();
            if (data.success) preflight = data;
        } catch(e) {}

        const worldSize = preflight.worldSize;
        const freeBytes = preflight.freeBytes;
        const isMounted = preflight.mounted;
        const isTransitional = preflight.transitional || false;
        const worldMode = preflight.worldMode || '';

        // Block if world is in a transitional state
        if (isTransitional) {
            body.innerHTML = `
                <div style="margin-bottom:1.25rem;">
                    <div style="background:rgba(var(--danger-rgb,220,53,69),0.1);border:1px solid var(--danger);border-radius:0.375rem;padding:1rem;font-size:0.85rem;text-align:center;">
                        <div style="font-size:1.5rem;margin-bottom:0.5rem;">&#9888;</div>
                        <strong style="color:var(--danger);">Cannot backup while world is ${worldMode}</strong>
                        <div style="color:var(--text-secondary);margin-top:0.5rem;">The world is currently in a transitional state (<strong>${worldMode}</strong>). Backups cannot run while files are being modified by another operation. Please wait for it to complete and try again.</div>
                    </div>
                </div>
                <div style="display:flex;gap:0.75rem;justify-content:flex-end;">
                    <button class="action-btn" onclick="document.getElementById('backupConfirmOverlay').classList.remove('show')" style="padding:0.4rem 1rem;">Close</button>
                </div>`;
            return;
        }

        function fmtSize(bytes) {
            if (bytes >= 1073741824) return (bytes / 1073741824).toFixed(1) + ' GB';
            return (bytes / 1048576).toFixed(1) + ' MB';
        }

        // Calculate space needed based on compression choice
        // none: world_size * 1.1 (tar + headroom)
        // compressed: world_size * 2.1 (tar + compressed output coexist during compression + headroom)
        function getSpaceNeeded(comp) {
            return comp === 'none' ? worldSize * 1.1 : worldSize * 2.1;
        }

        function updateDiskStatus(comp) {
            const needed = getSpaceNeeded(comp);
            const hasSpace = freeBytes > needed;
            const el = document.getElementById('backupDiskStatus');
            const btn = document.getElementById('startBackupBtn');

            if (!isMounted) {
                el.innerHTML = `
                    <div style="background:rgba(var(--danger-rgb,220,53,69),0.1);border:1px solid var(--danger);border-radius:0.375rem;padding:0.75rem;font-size:0.8rem;">
                        <strong style="color:var(--danger);">&#9888; No dedicated backup volume</strong>
                        <div style="color:var(--text-secondary);margin-top:0.25rem;">No separate backup mount detected. Backups will write to the main data volume which is not recommended.</div>
                    </div>`;
                btn.disabled = false; btn.style.opacity = '1'; btn.style.cursor = 'pointer';
            } else if (!hasSpace) {
                const extra = comp !== 'none' ? ' Compression requires ~2x world size since both the archive and compressed file exist simultaneously during the process.' : '';
                el.innerHTML = `
                    <div style="background:rgba(var(--danger-rgb,220,53,69),0.1);border:1px solid var(--danger);border-radius:0.375rem;padding:0.75rem;font-size:0.8rem;">
                        <strong style="color:var(--danger);">&#9888; Insufficient disk space</strong>
                        <div style="color:var(--text-secondary);margin-top:0.25rem;">Need <strong>~${fmtSize(needed)}</strong> but only <strong>${fmtSize(freeBytes)}</strong> free on the backup volume.${extra}</div>
                    </div>`;
                btn.disabled = true; btn.style.opacity = '0.5'; btn.style.cursor = 'not-allowed';
            } else {
                el.innerHTML = `
                    <div style="background:rgba(var(--success-rgb,25,135,84),0.1);border:1px solid var(--success);border-radius:0.375rem;padding:0.75rem;font-size:0.8rem;">
                        <strong style="color:var(--success);">&#10003; Disk space OK</strong>
                        <div style="color:var(--text-secondary);margin-top:0.25rem;">World: <strong>${fmtSize(worldSize)}</strong> &nbsp;|&nbsp; Required: <strong>~${fmtSize(needed)}</strong> &nbsp;|&nbsp; Free: <strong>${fmtSize(freeBytes)}</strong></div>
                    </div>`;
                btn.disabled = false; btn.style.opacity = '1'; btn.style.cursor = 'pointer';
            }
        }

        body.innerHTML = `
            <div style="margin-bottom:1.25rem;">
                <p style="color:var(--text-primary);font-size:0.9rem;margin-bottom:1rem;">
                    This will create a <strong>full snapshot</strong> of world <strong>"${worldName}"</strong>.
                </p>
                <div id="backupDiskStatus" style="margin-bottom:1rem;"></div>
                <div style="background:var(--bg-primary);border-radius:0.5rem;padding:1rem;margin-bottom:1rem;">
                    <div style="font-size:0.8rem;font-weight:600;color:var(--text-secondary);margin-bottom:0.5rem;">What will be backed up:</div>
                    <ul style="margin:0 0 0 1.25rem;padding:0;color:var(--text-secondary);font-size:0.8rem;line-height:1.8;">
                        <li><strong>Game save data</strong> — world files, player data, map exploration</li>
                        <li><strong>BepInEx mods &amp; plugins</strong> — all installed server-side mods</li>
                        <li><strong>Custom configs</strong> — any custom configuration overrides</li>
                        <li><strong>World settings</strong> — seed, port, mod list (stored as metadata)</li>
                    </ul>
                </div>
                <div style="display:flex;align-items:center;gap:0.75rem;background:var(--bg-primary);border-radius:0.5rem;padding:0.75rem 1rem;margin-bottom:1rem;">
                    <label style="font-size:0.8rem;color:var(--text-secondary);font-weight:600;white-space:nowrap;">Compression:</label>
                    <select id="manualBackupCompression" class="form-control form-control-sm" style="max-width:160px;font-size:0.8rem;">
                        <option value="none">None (fastest)</option>
                        <option value="gzip">Gzip</option>
                        <option value="zstd">Zstd (recommended)</option>
                    </select>
                </div>
                <div style="background:rgba(var(--accent-primary-rgb,13,110,253),0.08);border:1px solid var(--accent-primary);border-radius:0.375rem;padding:0.75rem;font-size:0.8rem;color:var(--text-secondary);">
                    <strong style="color:var(--accent-primary);">Note:</strong> The client payload ZIP is excluded to save space.
                </div>
            </div>
            <div style="display:flex;gap:0.75rem;justify-content:flex-end;">
                <button class="action-btn" onclick="document.getElementById('backupConfirmOverlay').classList.remove('show')" style="padding:0.4rem 1rem;">Cancel</button>
                <button class="action-btn success" id="startBackupBtn" style="padding:0.4rem 1rem;font-weight:600;" onclick="document.getElementById('backupConfirmOverlay').classList.remove('show');createManualBackup('${worldName}', document.getElementById('manualBackupCompression').value)">Start Backup</button>
            </div>
        `;

        // Initial disk status check and reactive update on compression change
        updateDiskStatus('none');
        document.getElementById('manualBackupCompression').addEventListener('change', function() {
            updateDiskStatus(this.value);
        });
    }

    // Shared progress bar helper
    function createProgressUI(titleEl, bodyEl, title, icon, stepLabels) {
        titleEl.innerHTML = icon + ' ' + title;
        const totalSteps = stepLabels.length;
        bodyEl.innerHTML = `
            <div style="padding:1rem;">
                <div id="pgCurrentStep" style="display:flex;align-items:center;gap:0.5rem;margin-bottom:0.5rem;">
                    <div class="backup-spinner" style="width:16px;height:16px;border-width:2px;"></div>
                    <span style="color:var(--text-primary);font-weight:600;font-size:0.85rem;" id="pgStepLabel">Initializing...</span>
                    <span id="pgPct" style="margin-left:auto;font-family:var(--font-mono);font-size:0.8rem;color:var(--accent-primary);font-weight:600;">0%</span>
                </div>
                <div class="backup-progress-track"><div class="backup-progress-fill pulsing" id="pgBar" style="width:0%"></div></div>
                <div id="pgSteps" style="background:var(--bg-primary);border-radius:0.5rem;padding:0.75rem;margin-top:0.75rem;max-height:220px;overflow-y:auto;"></div>
                <div id="pgResult" style="margin-top:1rem;"></div>
            </div>
        `;

        let stepCount = 0;
        let hadWarnings = false;
        const stepsEl = document.getElementById('pgSteps');

        function addStep(text, status) {
            if (status === 'warn') hadWarnings = true;
            stepCount++;
            const pct = Math.min(Math.round((stepCount / totalSteps) * 100), 99);
            const bar = document.getElementById('pgBar');
            const pctEl = document.getElementById('pgPct');
            const labelEl = document.getElementById('pgStepLabel');

            bar.style.width = pct + '%';
            pctEl.textContent = pct + '%';
            labelEl.textContent = text.length > 50 ? text.substring(0, 50) + '...' : text;

            const icon = status === 'ok' ? '<span style="color:var(--success);">&#10003;</span>'
                       : status === 'warn' ? '<span style="color:var(--warning);">&#9888;</span>'
                       : status === 'fail' ? '<span style="color:var(--danger);">&#10007;</span>'
                       : '<span style="color:var(--accent-primary);">&#9679;</span>';
            stepsEl.innerHTML += `<div class="backup-step${status==='active'?' active':''}"><span class="backup-step-icon">${icon}</span><span>${text}</span><span class="backup-step-pct">${pct}%</span></div>`;
            stepsEl.scrollTop = stepsEl.scrollHeight;
        }

        function complete(state) {
            // state: true=success, false=failed, 'warn'=completed with warnings
            const bar = document.getElementById('pgBar');
            const pctEl = document.getElementById('pgPct');
            const stepRow = document.getElementById('pgCurrentStep');
            bar.style.width = '100%';
            bar.classList.remove('pulsing');
            if (state === 'warn') {
                bar.classList.add('complete');
                bar.style.background = 'var(--warning)';
                pctEl.textContent = '100%';
                pctEl.style.color = 'var(--warning)';
                stepRow.innerHTML = `<svg width="18" height="18" fill="none" stroke="var(--warning)" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-2.5L13.732 4.5c-.77-.833-2.694-.833-3.464 0L3.34 16.5c-.77.833.192 2.5 1.732 2.5z"/></svg><span style="color:var(--warning);font-weight:600;font-size:0.85rem;">Completed with warnings</span><span style="margin-left:auto;font-family:var(--font-mono);font-size:0.8rem;color:var(--warning);font-weight:600;">100%</span>`;
            } else {
                bar.classList.add(state ? 'complete' : 'failed');
                pctEl.textContent = '100%';
                pctEl.style.color = state ? 'var(--success)' : 'var(--danger)';
                stepRow.innerHTML = state
                    ? `<svg width="18" height="18" fill="none" stroke="var(--success)" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z"/></svg><span style="color:var(--success);font-weight:600;font-size:0.85rem;">Complete</span><span style="margin-left:auto;font-family:var(--font-mono);font-size:0.8rem;color:var(--success);font-weight:600;">100%</span>`
                    : `<svg width="18" height="18" fill="none" stroke="var(--danger)" viewBox="0 0 24 24"><circle cx="12" cy="12" r="10" stroke-width="2"/><path stroke-linecap="round" stroke-width="2" d="M15 9l-6 6m0-6l6 6"/></svg><span style="color:var(--danger);font-weight:600;font-size:0.85rem;">Failed</span><span style="margin-left:auto;font-family:var(--font-mono);font-size:0.8rem;color:var(--danger);font-weight:600;">100%</span>`;
            }
        }

        return { addStep, complete, stepsEl, get hadWarnings() { return hadWarnings; } };
    }

    // Stream lines from a fetch response, calling onLine for each
    async function pollJobProgress(jobId, onLine) {
        let offset = 0;
        while (true) {
            await new Promise(r => setTimeout(r, 800));
            try {
                const res = await fetch(`adminAPI.php?action=getJobProgress&jobId=${jobId}&offset=${offset}`);
                const data = await res.json();
                if (data.error) { onLine(JSON.stringify({progress: data.error})); return; }
                for (const line of data.lines) {
                    onLine(line);
                }
                offset = data.offset;
                if (data.done) return;
            } catch(e) {
                onLine(JSON.stringify({progress: 'Connection error: ' + e.message}));
                return;
            }
        }
    }

    async function createManualBackup(worldName, compression) {
        compression = compression || 'none';
        const progressBody = document.getElementById('restoreProgressBody');
        const titleEl = document.querySelector('#restoreProgressOverlay .mods-modal-title');
        const svgBackup = '<svg width="20" height="20" fill="none" stroke="var(--success)" viewBox="0 0 24 24" style="vertical-align:middle;margin-right:0.5rem;"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M8 7H5a2 2 0 00-2 2v9a2 2 0 002 2h14a2 2 0 002-2V9a2 2 0 00-2-2h-3m-1 4l-3 3m0 0l-3-3m3 3V4"/></svg>';

        const expectedSteps = compression !== 'none' ? 14 : 9; // more steps with compression polling
        const pg = createProgressUI(titleEl, progressBody, 'Creating Backup', svgBackup, Array(expectedSteps));
        document.getElementById('restoreProgressOverlay').classList.add('show');

        pg.addStep('Sending backup request...', 'ok');

        try {
            const res = await fetch('adminAPI.php?action=createManualBackup', {
                method: 'POST',
                headers: {'Content-Type': 'application/json'},
                body: JSON.stringify({world: worldName, compression: compression})
            });
            const startData = await res.json();
            if (!startData.success || !startData.jobId) {
                pg.addStep(startData.error || 'Failed to start backup', 'warn');
                pg.complete(false);
                return;
            }

            let finalResult = null;
            await pollJobProgress(startData.jobId, line => {
                try {
                    const obj = JSON.parse(line);
                    if (obj.progress) {
                        const lc = obj.progress.toLowerCase();
                        const status = (lc.includes('failed') || lc.includes('error')) ? 'warn' : 'ok';
                        pg.addStep(obj.progress, status);
                    } else if (obj.success !== undefined) {
                        finalResult = obj;
                    }
                } catch(e) {
                    if (line.trim()) pg.addStep(line.trim(), 'ok');
                }
            });

            if (finalResult && finalResult.success) {
                const svgWarn = '<svg width="20" height="20" fill="none" stroke="var(--warning)" viewBox="0 0 24 24" style="vertical-align:middle;margin-right:0.5rem;"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-2.5L13.732 4.5c-.77-.833-2.694-.833-3.464 0L3.34 16.5c-.77.833.192 2.5 1.732 2.5z"/></svg>';
                if (pg.hadWarnings) {
                    pg.complete('warn');
                    titleEl.innerHTML = svgWarn + ' Backup Saved (with warnings)';
                    document.getElementById('pgResult').innerHTML = `
                        <div style="background:rgba(var(--warning-rgb,255,193,7),0.1);border:1px solid var(--warning);border-radius:0.375rem;padding:0.75rem;text-align:center;margin-bottom:0.75rem;">
                            <div style="color:var(--text-secondary);font-size:0.8rem;">The backup was saved <strong>uncompressed</strong> because compression could not complete. Check the log above for details.</div>
                        </div>
                        <div style="text-align:center;">
                            <button class="action-btn" style="padding:0.4rem 1.5rem;border-color:var(--warning);color:var(--warning);" onclick="document.getElementById('restoreProgressOverlay').classList.remove('show');document.getElementById('backupsTab').dataset.loaded='';loadWorldBackups('${worldName}');fetchVolumeStats();">Done</button>
                        </div>`;
                } else {
                    pg.complete(true);
                    titleEl.innerHTML = svgBackup + ' Backup Complete';
                    document.getElementById('pgResult').innerHTML = `
                        <div style="text-align:center;margin-top:0.75rem;">
                            <button class="action-btn success" onclick="document.getElementById('restoreProgressOverlay').classList.remove('show');document.getElementById('backupsTab').dataset.loaded='';loadWorldBackups('${worldName}');fetchVolumeStats();" style="padding:0.4rem 1.5rem;">Done</button>
                        </div>`;
                }
            } else {
                pg.complete(false);
                titleEl.innerHTML = '<svg width="20" height="20" fill="none" stroke="var(--danger)" viewBox="0 0 24 24" style="vertical-align:middle;margin-right:0.5rem;"><circle cx="12" cy="12" r="10" stroke-width="2"/><path stroke-linecap="round" stroke-width="2" d="M15 9l-6 6m0-6l6 6"/></svg> Backup Failed';
                document.getElementById('pgResult').innerHTML = `
                    <div style="background:rgba(var(--danger-rgb,220,53,69),0.1);border:1px solid var(--danger);border-radius:0.375rem;padding:0.75rem;text-align:center;">
                        <div style="color:var(--text-secondary);font-size:0.8rem;">${finalResult ? (finalResult.error || 'Unknown error') : 'No result from server'}</div>
                    </div>
                    <div style="text-align:center;margin-top:0.75rem;">
                        <button class="action-btn" onclick="document.getElementById('restoreProgressOverlay').classList.remove('show');" style="padding:0.4rem 1.5rem;">Close</button>
                    </div>`;
            }
        } catch(e) {
            pg.addStep('Connection error: ' + e.message, 'fail');
            pg.complete(false);
            document.getElementById('pgResult').innerHTML = `
                <div style="text-align:center;margin-top:0.75rem;">
                    <button class="action-btn" onclick="document.getElementById('restoreProgressOverlay').classList.remove('show');" style="padding:0.4rem 1.5rem;">Close</button>
                </div>`;
        }
    }

    // --- Restore Confirmation Modal ---
    let pendingRestoreId = null;
    let pendingRestoreWorld = null;

    async function restoreBackup(backupId, backupDate, worldName) {
        pendingRestoreId = backupId;
        pendingRestoreWorld = worldName;

        const confirmBody = document.getElementById('restoreConfirmBody');
        confirmBody.innerHTML = '<div style="text-align:center;padding:2rem;color:var(--text-muted);"><div class="backup-spinner" style="width:24px;height:24px;border-width:3px;margin:0 auto 1rem;"></div>Checking disk space...</div>';
        document.getElementById('restoreConfirmOverlay').classList.add('show');

        // Fetch preflight — safety backup needs world_size of free space
        let preflight = { mounted: false, worldSize: 0, freeBytes: 0 };
        try {
            const res = await fetch(`adminAPI.php?action=getBackupPreflight&world=${encodeURIComponent(worldName)}`);
            const data = await res.json();
            if (data.success) preflight = data;
        } catch(e) {}

        function fmtSize(bytes) {
            if (bytes >= 1073741824) return (bytes / 1073741824).toFixed(1) + ' GB';
            return (bytes / 1048576).toFixed(1) + ' MB';
        }

        // Block if world is in a transitional state
        const isTransitional = preflight.transitional || false;
        const worldModeRestore = preflight.worldMode || '';
        if (isTransitional) {
            confirmBody.innerHTML = `
                <div style="margin-bottom:1.25rem;">
                    <div style="background:rgba(var(--danger-rgb,220,53,69),0.1);border:1px solid var(--danger);border-radius:0.375rem;padding:1rem;font-size:0.85rem;text-align:center;">
                        <div style="font-size:1.5rem;margin-bottom:0.5rem;">&#9888;</div>
                        <strong style="color:var(--danger);">Cannot restore while world is ${worldModeRestore}</strong>
                        <div style="color:var(--text-secondary);margin-top:0.5rem;">The world is currently in a transitional state (<strong>${worldModeRestore}</strong>). Restores cannot run while files are being modified by another operation. Please wait for it to complete and try again.</div>
                    </div>
                </div>
                <div style="display:flex;gap:0.75rem;justify-content:flex-end;">
                    <button class="action-btn" onclick="closeRestoreConfirm()" style="padding:0.4rem 1rem;">Close</button>
                </div>`;
            return;
        }

        const worldSize = preflight.worldSize;
        const freeBytes = preflight.freeBytes;
        const needed = worldSize * 1.1; // safety backup = world size + 10% headroom
        const canSafetyBackup = freeBytes > needed;

        // Disk space warning for safety backup
        let diskStatusHtml = '';
        if (worldSize > 0 && !canSafetyBackup) {
            diskStatusHtml = `
                <div style="background:rgba(var(--danger-rgb,220,53,69),0.1);border:1px solid var(--danger);border-radius:0.375rem;padding:0.75rem;font-size:0.8rem;margin-bottom:1rem;">
                    <strong style="color:var(--danger);">&#9888; Insufficient space for safety backup</strong>
                    <div style="color:var(--text-secondary);margin-top:0.25rem;">A pre-restore safety backup requires <strong>~${fmtSize(needed)}</strong> but only <strong>${fmtSize(freeBytes)}</strong> is free on the backup volume. The safety backup will be <strong>skipped</strong> — you will not be able to undo this restore.</div>
                </div>`;
        } else if (worldSize > 0) {
            diskStatusHtml = `
                <div style="background:rgba(var(--success-rgb,25,135,84),0.1);border:1px solid var(--success);border-radius:0.375rem;padding:0.75rem;font-size:0.8rem;margin-bottom:1rem;">
                    <strong style="color:var(--success);">&#10003; Disk space OK</strong>
                    <div style="color:var(--text-secondary);margin-top:0.25rem;">Safety backup: <strong>~${fmtSize(needed)}</strong> &nbsp;|&nbsp; Free: <strong>${fmtSize(freeBytes)}</strong></div>
                </div>`;
        }

        confirmBody.innerHTML = `
            <div style="margin-bottom:1.25rem;">
                <p style="color:var(--text-primary);font-size:0.9rem;margin-bottom:1rem;">
                    This will <strong style="color:var(--warning)">replace the entire current state</strong> of world
                    <strong>"${worldName}"</strong> with the selected backup.
                </p>
                ${diskStatusHtml}
                <div style="background:var(--bg-primary);border-radius:0.5rem;padding:1rem;margin-bottom:1rem;">
                    <div style="display:flex;justify-content:space-between;padding:0.35rem 0;border-bottom:1px solid var(--border-light);">
                        <span style="color:var(--text-muted);font-size:0.8rem;">Backup Date</span>
                        <code style="font-size:0.8rem;color:var(--accent-primary);">${backupDate}</code>
                    </div>
                    <div style="display:flex;justify-content:space-between;padding:0.35rem 0;border-bottom:1px solid var(--border-light);">
                        <span style="color:var(--text-muted);font-size:0.8rem;">World</span>
                        <span style="font-size:0.8rem;color:var(--text-primary);">${worldName}</span>
                    </div>
                    <div style="display:flex;justify-content:space-between;padding:0.35rem 0;">
                        <span style="color:var(--text-muted);font-size:0.8rem;">Backup ID</span>
                        <code style="font-size:0.8rem;color:var(--text-secondary);">#${backupId}</code>
                    </div>
                </div>
                <div style="background:rgba(var(--warning-rgb,255,193,7),0.1);border:1px solid var(--warning);border-radius:0.375rem;padding:0.75rem;font-size:0.8rem;">
                    <strong style="color:var(--warning);">What will happen:</strong>
                    <ul style="margin:0.5rem 0 0 1.25rem;padding:0;color:var(--text-secondary);line-height:1.6;">
                        <li>The world process will be <strong>stopped</strong></li>
                        <li>${canSafetyBackup || worldSize === 0 ? 'A <strong>safety backup</strong> of the current state will be created' : '<strong style="color:var(--danger);">Safety backup will be SKIPPED</strong> (not enough disk space)'}</li>
                        <li>Current world files will be <strong>replaced</strong> with backup contents</li>
                        <li>World will be set to <strong>rebuild</strong> and restarted</li>
                    </ul>
                </div>
            </div>
            <div style="display:flex;gap:0.75rem;justify-content:flex-end;">
                <button class="action-btn" onclick="closeRestoreConfirm()" style="padding:0.4rem 1rem;">Cancel</button>
                <button class="action-btn" onclick="executeRestore()" style="padding:0.4rem 1rem;background:var(--warning);border-color:var(--warning);color:#000;font-weight:600;">Restore Backup</button>
            </div>
        `;
    }

    function closeRestoreConfirm(event) {
        if (!event || event.target === document.getElementById('restoreConfirmOverlay')) {
            document.getElementById('restoreConfirmOverlay').classList.remove('show');
            pendingRestoreId = null;
            pendingRestoreWorld = null;
        }
    }

    async function executeRestore() {
        const backupId = pendingRestoreId;
        const worldName = pendingRestoreWorld;
        closeRestoreConfirm();

        const progressBody = document.getElementById('restoreProgressBody');
        const titleEl = document.querySelector('#restoreProgressOverlay .mods-modal-title');
        const svgRestore = '<svg width="20" height="20" fill="none" stroke="var(--accent-primary)" viewBox="0 0 24 24" style="vertical-align:middle;margin-right:0.5rem;"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 4v5h.582m15.356 2A8.001 8.001 0 004.582 9m0 0H9m11 11v-5h-.581m0 0a8.003 8.003 0 01-15.357-2m15.357 2H15"/></svg>';

        // Expected steps: request, validate, stop, safety backup, clear, extract, extracted stats, ownership, rebuild, start, verify
        const pg = createProgressUI(titleEl, progressBody, 'Restoring World', svgRestore, Array(12));
        document.getElementById('restoreProgressOverlay').classList.add('show');

        pg.addStep('Sending restore request...', 'ok');

        try {
            const res = await fetch('adminAPI.php?action=restoreBackup', {
                method: 'POST',
                headers: {'Content-Type': 'application/json'},
                body: JSON.stringify({backupId: backupId})
            });
            const startData = await res.json();
            if (!startData.success || !startData.jobId) {
                pg.addStep(startData.error || 'Failed to start restore', 'warn');
                pg.complete(false);
                return;
            }

            let finalResult = null;
            await pollJobProgress(startData.jobId, line => {
                try {
                    const obj = JSON.parse(line);
                    if (obj.progress) {
                        const lc = obj.progress.toLowerCase();
                        const status = (lc.includes('warning') || lc.includes('failed') || lc.includes('error') || lc.includes('skipping')) ? 'warn' : 'ok';
                        pg.addStep(obj.progress, status);
                    } else if (obj.success !== undefined) {
                        finalResult = obj;
                    }
                } catch(e) {
                    if (line.trim()) pg.addStep(line.trim(), 'ok');
                }
            });

            if (finalResult && finalResult.success) {
                const svgWarnRestore = '<svg width="20" height="20" fill="none" stroke="var(--warning)" viewBox="0 0 24 24" style="vertical-align:middle;margin-right:0.5rem;"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-2.5L13.732 4.5c-.77-.833-2.694-.833-3.464 0L3.34 16.5c-.77.833.192 2.5 1.732 2.5z"/></svg>';
                if (pg.hadWarnings) {
                    pg.complete('warn');
                    titleEl.innerHTML = svgWarnRestore + ' Restored (with warnings)';
                    document.getElementById('pgResult').innerHTML = `
                        <div style="background:rgba(var(--warning-rgb,255,193,7),0.1);border:1px solid var(--warning);border-radius:0.375rem;padding:0.75rem;text-align:center;margin-bottom:0.75rem;">
                            <div style="color:var(--text-secondary);font-size:0.8rem;">The world was restored but the <strong>pre-restore safety backup was skipped</strong> due to insufficient disk space. This restore cannot be undone.</div>
                        </div>
                        <div style="text-align:center;">
                            <button class="action-btn" style="padding:0.4rem 1.5rem;border-color:var(--warning);color:var(--warning);" onclick="document.getElementById('restoreProgressOverlay').classList.remove('show');document.getElementById('backupsTab').dataset.loaded='';loadWorldBackups('${worldName}');fetchVolumeStats();">Done</button>
                        </div>`;
                } else {
                    pg.complete(true);
                    titleEl.innerHTML = '<svg width="20" height="20" fill="none" stroke="var(--success)" viewBox="0 0 24 24" style="vertical-align:middle;margin-right:0.5rem;"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z"/></svg> Restore Complete';
                    document.getElementById('pgResult').innerHTML = `
                        <div style="background:rgba(var(--success-rgb,40,167,69),0.1);border:1px solid var(--success);border-radius:0.375rem;padding:0.75rem;text-align:center;">
                            <div style="color:var(--text-secondary);font-size:0.8rem;">${finalResult.message}</div>
                            ${finalResult.safetyBackupId ? '<div style="color:var(--text-muted);font-size:0.75rem;margin-top:0.35rem;">Safety backup created (ID #' + finalResult.safetyBackupId + ')</div>' : ''}
                        </div>
                        <div style="text-align:center;margin-top:0.75rem;">
                            <button class="action-btn success" onclick="document.getElementById('restoreProgressOverlay').classList.remove('show');document.getElementById('backupsTab').dataset.loaded='';loadWorldBackups('${worldName}');fetchVolumeStats();" style="padding:0.4rem 1.5rem;">Done</button>
                        </div>`;
                }
            } else {
                pg.complete(false);
                titleEl.innerHTML = '<svg width="20" height="20" fill="none" stroke="var(--danger)" viewBox="0 0 24 24" style="vertical-align:middle;margin-right:0.5rem;"><circle cx="12" cy="12" r="10" stroke-width="2"/><path stroke-linecap="round" stroke-width="2" d="M15 9l-6 6m0-6l6 6"/></svg> Restore Failed';
                document.getElementById('pgResult').innerHTML = `
                    <div style="background:rgba(var(--danger-rgb,220,53,69),0.1);border:1px solid var(--danger);border-radius:0.375rem;padding:0.75rem;text-align:center;">
                        <div style="color:var(--text-secondary);font-size:0.8rem;">${finalResult ? (finalResult.error || 'Unknown error') : 'No response from server'}</div>
                    </div>
                    <div style="text-align:center;margin-top:0.75rem;">
                        <button class="action-btn" onclick="document.getElementById('restoreProgressOverlay').classList.remove('show');" style="padding:0.4rem 1.5rem;">Close</button>
                    </div>`;
            }
        } catch(e) {
            pg.addStep('Connection error: ' + e.message, 'fail');
            pg.complete(false);
            titleEl.innerHTML = '<svg width="20" height="20" fill="none" stroke="var(--danger)" viewBox="0 0 24 24" style="vertical-align:middle;margin-right:0.5rem;"><circle cx="12" cy="12" r="10" stroke-width="2"/><path stroke-linecap="round" stroke-width="2" d="M15 9l-6 6m0-6l6 6"/></svg> Restore Failed';
            document.getElementById('pgResult').innerHTML = `
                <div style="text-align:center;margin-top:0.75rem;">
                    <button class="action-btn" onclick="document.getElementById('restoreProgressOverlay').classList.remove('show');" style="padding:0.4rem 1.5rem;">Close</button>
                </div>`;
        }
    }

    // --- Delete Confirmation Modal ---
    let pendingDeleteIds = [];

    function deleteSingleBackup(backupId) {
        pendingDeleteIds = [backupId];
        document.getElementById('deleteConfirmBody').innerHTML = `
            <div style="margin-bottom:1.25rem;">
                <p style="color:var(--text-primary);font-size:0.9rem;">
                    Permanently delete backup <strong>#${backupId}</strong>?
                </p>
                <p style="color:var(--text-muted);font-size:0.8rem;">This action cannot be undone. The backup file will be removed from disk.</p>
            </div>
            <div style="display:flex;gap:0.75rem;justify-content:flex-end;">
                <button class="action-btn" onclick="closeDeleteConfirm()" style="padding:0.4rem 1rem;">Cancel</button>
                <button class="action-btn danger" onclick="executeDelete()" style="padding:0.4rem 1rem;font-weight:600;">Delete</button>
            </div>
        `;
        document.getElementById('deleteConfirmOverlay').classList.add('show');
    }

    function deleteSelectedBackups() {
        const checked = Array.from(document.querySelectorAll('.bk-check:checked')).map(cb => parseInt(cb.value));
        if (checked.length === 0) {
            document.getElementById('backupActionStatus').innerHTML = '<span style="color:var(--warning);font-size:0.8rem;">No backups selected</span>';
            setTimeout(() => { document.getElementById('backupActionStatus').innerHTML = ''; }, 2000);
            return;
        }
        pendingDeleteIds = checked;
        document.getElementById('deleteConfirmBody').innerHTML = `
            <div style="margin-bottom:1.25rem;">
                <p style="color:var(--text-primary);font-size:0.9rem;">
                    Permanently delete <strong>${checked.length}</strong> selected backup${checked.length > 1 ? 's' : ''}?
                </p>
                <p style="color:var(--text-muted);font-size:0.8rem;">This action cannot be undone. All selected backup files will be removed from disk.</p>
            </div>
            <div style="display:flex;gap:0.75rem;justify-content:flex-end;">
                <button class="action-btn" onclick="closeDeleteConfirm()" style="padding:0.4rem 1rem;">Cancel</button>
                <button class="action-btn danger" onclick="executeDelete()" style="padding:0.4rem 1rem;font-weight:600;">Delete ${checked.length} Backup${checked.length > 1 ? 's' : ''}</button>
            </div>
        `;
        document.getElementById('deleteConfirmOverlay').classList.add('show');
    }

    function closeDeleteConfirm(event) {
        if (!event || event.target === document.getElementById('deleteConfirmOverlay')) {
            document.getElementById('deleteConfirmOverlay').classList.remove('show');
            pendingDeleteIds = [];
        }
    }

    async function purgeOrphanedBackups() {
        if (!confirm('Remove all orphaned backup records? This only removes database entries for backups whose files no longer exist on disk.')) return;
        try {
            const res = await fetch('adminAPI.php?action=purgeOrphanedBackups', {
                method: 'POST',
                headers: {'Content-Type': 'application/json'}
            });
            const data = await res.json();
            if (data.success) {
                fetchVolumeStats();
                // Refresh backup table if open
                if (currentSettingsWorld && document.getElementById('backupsTab')?.dataset.loaded === '1') {
                    loadWorldBackups(currentSettingsWorld);
                }
            }
        } catch(e) {}
    }

    async function executeDelete() {
        const ids = [...pendingDeleteIds];
        closeDeleteConfirm();

        try {
            const action = ids.length === 1 ? 'deleteBackup' : 'deleteBackups';
            const body = ids.length === 1 ? {backupId: ids[0]} : {backupIds: ids};
            const res = await fetch('adminAPI.php?action=' + action, {
                method: 'POST',
                headers: {'Content-Type': 'application/json'},
                body: JSON.stringify(body)
            });
            const data = await res.json();
            if (data.success) {
                document.getElementById('backupsTab').dataset.loaded = '';
                loadWorldBackups(currentSettingsWorld);
                fetchVolumeStats();
            } else {
                document.getElementById('backupActionStatus').innerHTML = '<span style="color:var(--danger);font-size:0.8rem;">Delete failed: ' + (data.error || 'Unknown error') + '</span>';
                setTimeout(() => { document.getElementById('backupActionStatus').innerHTML = ''; }, 4000);
            }
        } catch(e) {
            document.getElementById('backupActionStatus').innerHTML = '<span style="color:var(--danger);font-size:0.8rem;">Delete failed: ' + e.message + '</span>';
            setTimeout(() => { document.getElementById('backupActionStatus').innerHTML = ''; }, 4000);
        }
    }

    async function saveWorldBackupSettings(worldName) {
        const statusEl = document.getElementById('bkSettingsStatus');
        statusEl.innerHTML = '<span style="color:var(--text-secondary)">Saving...</span>';

        const payload = {
            world: worldName,
            settings: {
                backup_use_global: document.getElementById('bk-useGlobal').checked ? 1 : 0,
                backup_interval_minutes: parseInt(document.getElementById('bk-interval').value) || 30,
                backup_require_activity: parseInt(document.getElementById('bk-requireActivity').value),
                backup_retain_all_hours: parseInt(document.getElementById('bk-retainAllHours').value) || 24,
                backup_retain_daily_days: parseInt(document.getElementById('bk-retainDailyDays').value) || 7,
                backup_retain_weekly_days: parseInt(document.getElementById('bk-retainWeeklyDays').value) || 30,
                backup_retain_monthly_months: parseInt(document.getElementById('bk-retainMonthlyMonths').value) || 6,
                backup_compression: document.getElementById('bk-compression').value,
                backup_compression_hour: parseInt(document.getElementById('bk-compressionHour').value),
                backup_cpu_priority: parseInt(document.getElementById('bk-cpuPriority').value),
                backup_io_priority: document.getElementById('bk-ioPriority').value,
                backup_compression_level: parseInt(document.getElementById('bk-compressionLevel').value) || 0,
            }
        };

        try {
            const res = await fetch('adminAPI.php?action=saveWorldBackupSettings', {
                method: 'POST',
                headers: {'Content-Type': 'application/json'},
                body: JSON.stringify(payload)
            });
            const data = await res.json();
            if (data.success) {
                statusEl.innerHTML = '<span style="color:var(--success)">Saved!</span>';
                setTimeout(() => { statusEl.innerHTML = ''; }, 2000);
            } else {
                statusEl.innerHTML = '<span style="color:var(--danger)">Error saving settings</span>';
            }
        } catch(e) {
            statusEl.innerHTML = '<span style="color:var(--danger)">Error saving settings</span>';
        }
    }

    async function showSettingsModal(worldName) {
        currentSettingsWorld = worldName;
        document.getElementById('settingsModalTitle').textContent = `Settings - ${worldName}`;
        document.getElementById('settingsModalBody').innerHTML = '<div style="text-align: center; padding: 2rem; color: var(--text-muted);">Loading...</div>';
        document.getElementById('settingsTabBar').style.display = 'flex';
        // Reset to Settings tab
        document.querySelectorAll('#settingsTabBar .backup-tab').forEach(t => t.classList.remove('active'));
        document.querySelector('#settingsTabBar .backup-tab[data-tab="settingsTab"]').classList.add('active');
        document.getElementById('settingsModalOverlay').classList.add('show');

        try {
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
                    <!-- Settings Tab -->
                    <div class="settings-tab-pane" id="settingsTab" style="display:block;">
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
                    </div>

                    <!-- Backups Tab -->
                    <div class="settings-tab-pane" id="backupsTab" style="display:none;">
                        <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:1rem;">
                            <div style="display:flex;gap:0.5rem;">
                                <button class="action-btn success" style="padding:0.3rem 0.75rem;font-size:0.8rem" onclick="confirmCreateBackup('${worldName}')">Start Manual Backup</button>
                                <button class="action-btn danger" style="padding:0.3rem 0.75rem;font-size:0.8rem" onclick="deleteSelectedBackups()">Delete Selected</button>
                            </div>
                            <div id="backupActionStatus" style="font-size:0.8rem;"></div>
                        </div>

                        <details style="margin-bottom:1rem;background:var(--bg-primary);border-radius:0.5rem;padding:0.75rem 1rem;">
                            <summary style="cursor:pointer;font-size:0.85rem;font-weight:600;color:var(--text-secondary);user-select:none;">Per-World Backup Settings</summary>
                            <div style="margin-top:0.75rem;">
                                <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:0.75rem;">
                                    <div>
                                        <span style="font-size:0.85rem;">Use Global Defaults</span>
                                        <small style="display:block;color:var(--text-muted);font-size:0.7rem;">When enabled, this world uses the server-wide backup settings.</small>
                                    </div>
                                    <label class="switch"><input type="checkbox" id="bk-useGlobal" checked onchange="document.getElementById('bk-overrideFields').style.display=this.checked?'none':'block'"><span class="slider round"></span></label>
                                </div>
                                <div id="bk-overrideFields" style="display:none;">
                                    <div class="row mb-2">
                                        <div class="col-6">
                                            <label style="font-size:0.75rem;color:var(--text-secondary)">Interval (min) <span class="backup-info-icon" data-tip="How often scheduled backups run for this world. Backups only trigger when conditions are met.">&#9432;</span></label>
                                            <input type="number" class="form-control form-control-sm" id="bk-interval" value="30" style="font-family:var(--font-mono)">
                                        </div>
                                        <div class="col-6">
                                            <label style="font-size:0.75rem;color:var(--text-secondary)">Require Activity <span class="backup-info-icon" data-tip="When enabled, backups only occur if players have connected since the last backup.">&#9432;</span></label>
                                            <select class="form-control form-control-sm" id="bk-requireActivity">
                                                <option value="1" selected>Yes</option>
                                                <option value="0">No</option>
                                            </select>
                                        </div>
                                    </div>
                                    <div class="row mb-2">
                                        <div class="col-6">
                                            <label style="font-size:0.75rem;color:var(--text-secondary)">Compression <span class="backup-info-icon" data-tip="Compression algorithm for this world's backups. 'none' stores as uncompressed tar. Gzip is widely compatible. Zstd is faster with better ratios.">&#9432;</span></label>
                                            <select class="form-control form-control-sm" id="bk-compression">
                                                <option value="none">None</option>
                                                <option value="gzip">Gzip</option>
                                                <option value="zstd">Zstd</option>
                                            </select>
                                        </div>
                                        <div class="col-6">
                                            <label style="font-size:0.75rem;color:var(--text-secondary)">Compression Schedule <span class="backup-info-icon" data-tip="Hour (0-23) to run deferred compression, or Immediate to compress right after backup. Only applies if compression is not 'none'.">&#9432;</span></label>
                                            <select class="form-control form-control-sm" id="bk-compressionHour">
                                                <option value="-1">Immediate</option>
                                                ${Array.from({length:24}, (_,i) => '<option value="'+i+'">'+String(i).padStart(2,'0')+':00</option>').join('')}
                                            </select>
                                        </div>
                                    </div>
                                    <div style="font-size:0.7rem;font-weight:600;color:var(--text-muted);text-transform:uppercase;letter-spacing:0.05em;margin-bottom:0.35rem;">Performance Tuning</div>
                                    <div class="row mb-2">
                                        <div class="col-4">
                                            <label style="font-size:0.7rem;color:var(--text-secondary)">CPU Priority <span class="backup-info-icon" data-tip="CPU scheduling priority. Higher nice value = lower priority = less impact on players.">&#9432;</span></label>
                                            <select class="form-control form-control-sm" id="bk-cpuPriority">
                                                <option value="0">Normal (0)</option>
                                                <option value="10" selected>Low (10)</option>
                                                <option value="19">Lowest (19)</option>
                                            </select>
                                        </div>
                                        <div class="col-4">
                                            <label style="font-size:0.7rem;color:var(--text-secondary)">I/O Priority <span class="backup-info-icon" data-tip="Disk I/O class. Idle = backups only use disk when game server isn't reading/writing.">&#9432;</span></label>
                                            <select class="form-control form-control-sm" id="bk-ioPriority">
                                                <option value="normal">Normal</option>
                                                <option value="low" selected>Low</option>
                                                <option value="idle">Idle</option>
                                            </select>
                                        </div>
                                        <div class="col-4">
                                            <label style="font-size:0.7rem;color:var(--text-secondary)">Comp. Level <span class="backup-info-icon" data-tip="Compression level (0=default). Lower = faster, higher = smaller files but more CPU.">&#9432;</span></label>
                                            <input type="number" class="form-control form-control-sm" id="bk-compressionLevel" value="0" min="0" max="19" style="font-family:var(--font-mono)">
                                        </div>
                                    </div>
                                    <div style="font-size:0.7rem;font-weight:600;color:var(--text-muted);text-transform:uppercase;letter-spacing:0.05em;margin-bottom:0.35rem;">Retention Override</div>
                                    <div class="row mb-2">
                                        <div class="col-3">
                                            <label style="font-size:0.7rem;color:var(--text-secondary)">All (hrs) <span class="backup-info-icon" data-tip="Keep every backup created within this many hours. Default: 24 hours.">&#9432;</span></label>
                                            <input type="number" class="form-control form-control-sm" id="bk-retainAllHours" value="24" style="font-family:var(--font-mono)">
                                        </div>
                                        <div class="col-3">
                                            <label style="font-size:0.7rem;color:var(--text-secondary)">Daily (days) <span class="backup-info-icon" data-tip="After the keep-all window, retain one backup per day for this many days. Default: 7 days.">&#9432;</span></label>
                                            <input type="number" class="form-control form-control-sm" id="bk-retainDailyDays" value="7" style="font-family:var(--font-mono)">
                                        </div>
                                        <div class="col-3">
                                            <label style="font-size:0.7rem;color:var(--text-secondary)">Weekly (days) <span class="backup-info-icon" data-tip="After the daily tier, retain one backup per week for this many days. Default: 30 days.">&#9432;</span></label>
                                            <input type="number" class="form-control form-control-sm" id="bk-retainWeeklyDays" value="30" style="font-family:var(--font-mono)">
                                        </div>
                                        <div class="col-3">
                                            <label style="font-size:0.7rem;color:var(--text-secondary)">Monthly (mo) <span class="backup-info-icon" data-tip="After the weekly tier, retain one backup per month for this many months. Default: 6 months.">&#9432;</span></label>
                                            <input type="number" class="form-control form-control-sm" id="bk-retainMonthlyMonths" value="6" style="font-family:var(--font-mono)">
                                        </div>
                                    </div>
                                </div>
                                <div style="display:flex;gap:0.75rem;justify-content:flex-end;padding-top:0.75rem;border-top:1px solid var(--border-light);">
                                    <button class="action-btn success" style="padding:0.3rem 0.75rem;font-size:0.8rem" onclick="saveWorldBackupSettings('${worldName}')">Save Backup Settings</button>
                                </div>
                                <div id="bkSettingsStatus" style="text-align:center;margin-top:0.5rem;font-size:0.8rem;"></div>
                            </div>
                        </details>

                        <div id="backupsList">
                            <div style="text-align:center;padding:2rem;color:var(--text-muted)">Switch to this tab to load backups</div>
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

    // ===== Migration Notice =====
    <?php if ($setupComplete == 1 && $migrationNoticeShown == 0): ?>
    (async function loadMigrationValues() {
        try {
            const res = await fetch('adminAPI.php?action=getServerSettings');
            const data = await res.json();
            if (data.success) {
                const s = data.settings;
                const rows = [
                    ['Base Port', s.basePort],
                    ['Game DNS', s.gameDNS || '(empty)'],
                    ['Steam API Key', s.steamAPIKey ? s.steamAPIKey.substring(0, 8) + '...' : '(empty)'],
                    ['Client Download URL', s.phvalheimClientURL ? (s.phvalheimClientURL.length > 40 ? s.phvalheimClientURL.substring(0, 40) + '...' : s.phvalheimClientURL) : '(empty)'],
                    ['Session Timeout', s.sessionTimeout + 's'],
                ];
                const tbody = document.getElementById('migrationValuesTable');
                tbody.innerHTML = rows.map(([k, v]) =>
                    `<tr><td style="padding:0.3rem 0;color:var(--text-muted);width:40%">${k}</td><td style="padding:0.3rem 0;color:var(--text-primary);font-family:var(--font-mono)">${v}</td></tr>`
                ).join('');
            }
        } catch(e) { console.error('Failed to load migration values:', e); }
    })();
    <?php endif; ?>

    // ===== Auto-open Server Settings when critical config is missing =====
    <?php if (!empty($missingSettings)): ?>
    document.addEventListener('DOMContentLoaded', function() {
        // Small delay so migration notice (if present) renders first
        setTimeout(function() { showServerSettingsModal(); }, 500);
    });
    <?php endif; ?>

    async function dismissMigrationNotice() {
        try {
            await fetch('adminAPI.php?action=dismissMigrationNotice', { method: 'POST' });
            document.getElementById('migrationNoticeOverlay').classList.remove('show');
        } catch(e) { console.error('Failed to dismiss notice:', e); }
    }

    // ===== Server Settings Modal =====
    async function showServerSettingsModal() {
        const overlay = document.getElementById('serverSettingsOverlay');
        const body = document.getElementById('serverSettingsBody');
        body.innerHTML = '<div style="text-align:center;padding:2rem;color:var(--text-muted)">Loading...</div>';
        overlay.classList.add('show');

        try {
            const res = await fetch('adminAPI.php?action=getServerSettings');
            const data = await res.json();
            if (!data.success) {
                body.innerHTML = '<div style="text-align:center;padding:2rem;color:var(--danger)">Error loading settings</div>';
                return;
            }
            const s = data.settings;
            const keyField = (id, val) => `
                <div style="position:relative">
                    <input type="password" class="form-control form-control-sm" id="${id}" value="${val || ''}" style="font-family:var(--font-mono);padding-right:3.5rem">
                    <button onclick="togglePasswordField('${id}')" style="position:absolute;right:0.5rem;top:50%;transform:translateY(-50%);background:none;border:none;color:var(--text-muted);cursor:pointer;font-size:0.7rem;padding:0.15rem 0.4rem;border-radius:3px;border:1px solid var(--border-color);transition:all 0.15s;" onmouseover="this.style.borderColor='var(--accent-primary)';this.style.color='var(--accent-primary)'" onmouseout="this.style.borderColor='var(--border-color)';this.style.color='var(--text-muted)'" title="Toggle visibility">show</button>
                </div>`;
            const sectionHead = (label, color) => `
                <h6 style="color:${color};margin-bottom:1rem;font-size:0.8rem;text-transform:uppercase;letter-spacing:0.05em;display:flex;align-items:center;gap:0.5rem;">
                    <span style="display:inline-block;width:3px;height:14px;background:${color};border-radius:2px;"></span>
                    ${label}
                </h6>`;
            const tip = (text) => `data-tip="${text}"`;
            const buildTimezoneOptions = (selected) => {
                const tzList = [
                    ['Etc/UTC',              '(GMT)  UTC'],
                    ['Pacific/Kwajalein',    '(GMT -12:00) Eniwetok, Kwajalein'],
                    ['Pacific/Midway',       '(GMT -11:00) Midway Island, Samoa'],
                    ['Pacific/Honolulu',     '(GMT -10:00) Hawaii'],
                    ['Pacific/Marquesas',    '(GMT -9:30) Marquesas Islands'],
                    ['America/Anchorage',    '(GMT -9:00) Alaska'],
                    ['America/Los_Angeles',  '(GMT -8:00) Pacific Time (US & Canada)'],
                    ['America/Denver',       '(GMT -7:00) Mountain Time (US & Canada)'],
                    ['America/Chicago',      '(GMT -6:00) Central Time (US & Canada), Mexico City'],
                    ['America/New_York',     '(GMT -5:00) Eastern Time (US & Canada), Bogota, Lima'],
                    ['America/Caracas',      '(GMT -4:30) Caracas'],
                    ['America/Halifax',      '(GMT -4:00) Atlantic Time (Canada), La Paz'],
                    ['America/St_Johns',     '(GMT -3:30) Newfoundland'],
                    ['America/Sao_Paulo',    '(GMT -3:00) Brazil, Buenos Aires, Georgetown'],
                    ['Atlantic/South_Georgia','(GMT -2:00) Mid-Atlantic'],
                    ['Atlantic/Azores',      '(GMT -1:00) Azores, Cape Verde Islands'],
                    ['Europe/London',        '(GMT)  Western Europe Time, London, Lisbon, Casablanca'],
                    ['Europe/Paris',         '(GMT +1:00) Brussels, Copenhagen, Madrid, Paris'],
                    ['Europe/Kaliningrad',   '(GMT +2:00) Kaliningrad, South Africa'],
                    ['Europe/Moscow',        '(GMT +3:00) Baghdad, Riyadh, Moscow, St. Petersburg'],
                    ['Asia/Tehran',          '(GMT +3:30) Tehran'],
                    ['Asia/Dubai',           '(GMT +4:00) Abu Dhabi, Muscat, Baku, Tbilisi'],
                    ['Asia/Kabul',           '(GMT +4:30) Kabul'],
                    ['Asia/Karachi',         '(GMT +5:00) Ekaterinburg, Islamabad, Karachi, Tashkent'],
                    ['Asia/Kolkata',         '(GMT +5:30) Mumbai, Kolkata, New Delhi'],
                    ['Asia/Kathmandu',       '(GMT +5:45) Kathmandu, Pokhara'],
                    ['Asia/Dhaka',           '(GMT +6:00) Almaty, Dhaka, Colombo'],
                    ['Asia/Yangon',          '(GMT +6:30) Yangon, Mandalay'],
                    ['Asia/Bangkok',         '(GMT +7:00) Bangkok, Hanoi, Jakarta'],
                    ['Asia/Singapore',       '(GMT +8:00) Beijing, Perth, Singapore, Hong Kong'],
                    ['Australia/Eucla',      '(GMT +8:45) Eucla'],
                    ['Asia/Tokyo',           '(GMT +9:00) Tokyo, Seoul, Osaka, Sapporo, Yakutsk'],
                    ['Australia/Adelaide',   '(GMT +9:30) Adelaide, Darwin'],
                    ['Australia/Sydney',     '(GMT +10:00) Eastern Australia, Guam, Vladivostok'],
                    ['Australia/Lord_Howe',  '(GMT +10:30) Lord Howe Island'],
                    ['Pacific/Guadalcanal',  '(GMT +11:00) Magadan, Solomon Islands, New Caledonia'],
                    ['Pacific/Norfolk',      '(GMT +11:30) Norfolk Island'],
                    ['Pacific/Auckland',     '(GMT +12:00) Auckland, Wellington, Fiji, Kamchatka'],
                    ['Pacific/Chatham',      '(GMT +12:45) Chatham Islands'],
                    ['Pacific/Apia',         '(GMT +13:00) Apia, Nukualofa'],
                    ['Pacific/Kiritimati',   '(GMT +14:00) Line Islands, Tokelau'],
                ];
                return tzList.map(([val, label]) =>
                    `<option value="${val}" ${val === selected ? 'selected' : ''}>${label}</option>`
                ).join('');
            };
            body.innerHTML = `
                <div style="margin-bottom: 1.5rem;">
                    ${sectionHead('Server', 'var(--accent-primary)')}
                    <div class="row mb-2">
                        <div class="col-6">
                            <label style="font-size:0.8rem;color:orchid" ${tip('The DNS name or IP that game clients use to connect to your Valheim worlds')}>Game DNS <span style="color:var(--danger);font-size:0.7rem">(required)</span></label>
                            <input type="text" class="form-control form-control-sm" id="ss-gameDNS" value="${s.gameDNS || ''}" style="font-family:var(--font-mono)" ${tip('Public hostname or IP for Valheim client connections (e.g. valheim.example.com)')}>
                        </div>
                        <div class="col-3">
                            <label style="font-size:0.8rem;color:orchid" ${tip('Starting UDP port for world servers. Each world uses 2 consecutive ports.')}>Base Port <span style="color:var(--danger);font-size:0.7rem">(required)</span></label>
                            <input type="number" class="form-control form-control-sm" id="ss-basePort" value="${s.basePort}" style="font-family:var(--font-mono)" ${tip('First UDP port in the range (default: 25000). Ensure ports are forwarded.')}>
                        </div>
                    </div>
                    <div class="row mb-2">
                        <div class="col-6">
                            <label style="font-size:0.8rem;color:orchid" ${tip('Controls how long public UI login cookies stay valid before expiring (in seconds)')}>Session Timeout (s)</label>
                            <input type="number" class="form-control form-control-sm" id="ss-sessionTimeout" value="${s.sessionTimeout}" style="font-family:var(--font-mono)" ${tip('Controls public UI cookie expiry. Default: 2592000 (30 days). After this, players must re-login via Steam.')}>
                        </div>
                        <div class="col-6">
                            <label style="font-size:0.8rem;color:orchid" ${tip('Maximum world log file size in bytes before rotation')}>Max Log Size</label>
                            <input type="number" class="form-control form-control-sm" id="ss-maxLogSize" value="${s.maxLogSize}" style="font-family:var(--font-mono)" ${tip('Log files exceeding this size are rotated. Default: 1000000 (1 MB).')}>
                        </div>
                    </div>
                    <div class="mb-2">
                        <label style="font-size:0.8rem;color:orchid" ${tip('URL where players download the PhValheim client installer')}>Client Download URL <span style="color:var(--danger);font-size:0.7rem">(required)</span></label>
                        <input type="text" class="form-control form-control-sm" id="ss-phvalheimClientURL" value="${s.phvalheimClientURL || ''}" style="font-family:var(--font-mono)" ${tip('Direct download link to the PhValheim client .exe installer')}>
                    </div>
                    <div class="mb-2">
                        <label style="font-size:0.8rem;color:orchid" ${tip('Server timezone for logs, backups, and world timestamps')}>Timezone</label>
                        <select class="form-control form-control-sm" id="ss-timezone" ${tip('Affects all timestamps in logs, backups, and the admin dashboard clock')}>
                            ${buildTimezoneOptions(s.timezone || 'Etc/UTC')}
                        </select>
                    </div>
                </div>

                <div style="margin-bottom: 1.5rem;">
                    ${sectionHead('Steam', '#1b9fff')}
                    <div class="mb-2">
                        <label style="font-size:0.8rem;color:orchid" ${tip('Required for Steam authentication and player identity resolution')}>Steam API Key <span style="color:var(--danger);font-size:0.7rem">(required)</span></label>
                        ${keyField('ss-steamAPIKey', s.steamAPIKey)}
                        <div style="margin-top:0.3rem;font-size:0.7rem;color:var(--text-muted)">Get a key at <a href="https://steamcommunity.com/dev/apikey" target="_blank" rel="noopener" style="color:#1b9fff;">steamcommunity.com/dev/apikey</a></div>
                    </div>
                </div>

                <div style="margin-bottom: 1.5rem;">
                    ${sectionHead('Backups', 'var(--success)')}

                    ${!s.backupPathMounted ? `
                    <div style="background:rgba(var(--danger-rgb,220,53,69),0.12);border:1px solid var(--danger);border-radius:0.5rem;padding:0.75rem 1rem;margin-bottom:1rem;">
                        <div style="display:flex;align-items:center;gap:0.5rem;margin-bottom:0.35rem;">
                            <svg width="18" height="18" fill="none" stroke="var(--danger)" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-2.5L13.732 4c-.77-.833-1.964-.833-2.732 0L4.082 16.5c-.77.833.192 2.5 1.732 2.5z"/></svg>
                            <strong style="color:var(--danger);font-size:0.85rem;">No dedicated backup volume detected</strong>
                        </div>
                        <div style="font-size:0.78rem;color:var(--text-secondary);line-height:1.5;">
                            Backups are writing to the main <code>/opt/stateful</code> volume. This means backups compete with game data for disk space.
                            <strong>Mount a separate host path to <code>/opt/stateful/backups</code></strong> in your container config for safe, isolated backup storage.
                            Automatic backups are <strong style="color:var(--danger)">disabled</strong> until a dedicated backup volume is mounted.
                        </div>
                    </div>
                    ` : ''}

                    <div style="background:var(--bg-primary);border-radius:0.5rem;padding:0.75rem 1rem;margin-bottom:1rem;">
                        <div style="display:flex;justify-content:space-between;align-items:center;">
                            <div>
                                <div style="font-size:0.75rem;color:var(--text-muted);text-transform:uppercase;letter-spacing:0.05em;margin-bottom:0.25rem;">Backup Storage</div>
                                <code style="font-size:0.8rem;color:var(--accent-primary);">${s.backupPath}</code>
                                <a href="https://github.com/brianmiller/phvalheim-server#backups" target="_blank" rel="noopener" style="margin-left:0.35rem;color:var(--accent-primary);text-decoration:none;font-size:0.8rem;" title="This is the internal container path. Your Docker host path must be mapped to this location. Click to view backup documentation.">&#9432;</a>
                                ${s.backupPathMounted
                                    ? '<span style="margin-left:0.5rem;font-size:0.7rem;color:var(--success);">&#10003; dedicated volume</span>'
                                    : '<span style="margin-left:0.5rem;font-size:0.7rem;color:var(--warning);">&#9888; shared with /opt/stateful</span>'}
                            </div>
                            <div style="text-align:right;font-size:0.8rem;">
                                <div style="color:var(--text-primary);font-weight:600;">${s.backupCount} backup${s.backupCount !== 1 ? 's' : ''}</div>
                                <div style="color:var(--text-muted);font-size:0.75rem;">${(s.backupTotalSize / 1073741824).toFixed(1)} GB used — ${s.backupDiskFree} free of ${s.backupDiskTotal}</div>
                            </div>
                        </div>
                        <div style="margin-top:0.5rem;height:4px;background:var(--bg-secondary);border-radius:2px;overflow:hidden;">
                            <div style="height:100%;border-radius:2px;width:${s.backupDiskPerc};background:${parseInt(s.backupDiskPerc) > 85 ? 'var(--danger)' : parseInt(s.backupDiskPerc) > 70 ? 'var(--warning)' : 'var(--success)'};"></div>
                        </div>
                    </div>

                    <div id="ss-backupFields" ${!s.backupPathMounted ? 'style="opacity:0.4;pointer-events:none;"' : ''}>
                    <div class="row mb-2">
                        <div class="col-4">
                            <label style="font-size:0.8rem;color:orchid" ${tip('How often scheduled backups run (in minutes)')}>Backup Interval (min)</label>
                            <input type="number" class="form-control form-control-sm" id="ss-backupIntervalMinutes" value="${s.backupIntervalMinutes}" style="font-family:var(--font-mono)" ${tip('Default: 30 minutes. Backups only occur when conditions are met.')}>
                        </div>
                        <div class="col-4">
                            <label style="font-size:0.8rem;color:orchid" ${tip('Only create backups when players have been online since the last backup')}>Require Player Activity</label>
                            <select class="form-control form-control-sm" id="ss-backupRequireActivity" ${tip('When enabled, worlds with no player activity since last backup are skipped.')}>
                                <option value="1" ${s.backupRequireActivity == 1 ? 'selected' : ''}>Yes</option>
                                <option value="0" ${s.backupRequireActivity == 0 ? 'selected' : ''}>No</option>
                            </select>
                        </div>
                        <div class="col-4">
                            <label style="font-size:0.8rem;color:orchid" ${tip('Compression algorithm for backup files')}>Compression</label>
                            <select class="form-control form-control-sm" id="ss-backupCompression" ${tip('None = uncompressed tar. Gzip/Zstd reduce size significantly.')}>
                                <option value="none" ${s.backupCompression === 'none' ? 'selected' : ''}>None</option>
                                <option value="gzip" ${s.backupCompression === 'gzip' ? 'selected' : ''}>Gzip</option>
                                <option value="zstd" ${s.backupCompression === 'zstd' ? 'selected' : ''}>Zstd</option>
                            </select>
                        </div>
                    </div>
                    <div class="row mb-2">
                        <div class="col-4">
                            <label style="font-size:0.8rem;color:orchid" ${tip('Hour of day (0-23) when deferred compression runs, or -1 for immediate')}>Compression Schedule</label>
                            <select class="form-control form-control-sm" id="ss-backupCompressionHour" ${tip('Set to Immediate to compress at backup time, or pick an off-peak hour.')}>
                                <option value="-1" ${s.backupCompressionHour == -1 ? 'selected' : ''}>Immediate</option>
                                ${Array.from({length:24}, (_,i) => '<option value="' + i + '" ' + (s.backupCompressionHour == i ? 'selected' : '') + '>' + (i === 0 ? '12:00 AM' : i < 12 ? i + ':00 AM' : i === 12 ? '12:00 PM' : (i-12) + ':00 PM') + '</option>').join('')}
                            </select>
                        </div>
                    </div>
                    <div style="margin-top:0.75rem;margin-bottom:0.35rem;font-size:0.75rem;font-weight:600;color:var(--text-secondary);text-transform:uppercase;letter-spacing:0.05em;">Performance Tuning</div>
                    <div style="font-size:0.7rem;color:var(--text-muted);margin-bottom:0.5rem;">Control how aggressively backups use system resources. Lower priority = less impact on active players.</div>
                    <div class="row mb-2">
                        <div class="col-4">
                            <label style="font-size:0.8rem;color:orchid" ${tip('CPU scheduling priority for tar and compression. Higher nice values = lower priority.')}>CPU Priority</label>
                            <select class="form-control form-control-sm" id="ss-backupCpuPriority" ${tip('Normal (0) = full speed. Low (10) = reduced priority. Lowest (19) = minimal CPU impact.')}>
                                <option value="0" ${s.backupCpuPriority == 0 ? 'selected' : ''}>Normal (0)</option>
                                <option value="10" ${s.backupCpuPriority == 10 ? 'selected' : ''}>Low (10)</option>
                                <option value="19" ${s.backupCpuPriority == 19 ? 'selected' : ''}>Lowest (19)</option>
                            </select>
                        </div>
                        <div class="col-4">
                            <label style="font-size:0.8rem;color:orchid" ${tip('Disk I/O scheduling class. Idle = only use disk when nothing else needs it.')}>I/O Priority</label>
                            <select class="form-control form-control-sm" id="ss-backupIoPriority" ${tip('Normal = default scheduling. Low = reduced I/O priority. Idle = only runs when disk is free.')}>
                                <option value="normal" ${s.backupIoPriority === 'normal' ? 'selected' : ''}>Normal</option>
                                <option value="low" ${s.backupIoPriority === 'low' ? 'selected' : ''}>Low</option>
                                <option value="idle" ${s.backupIoPriority === 'idle' ? 'selected' : ''}>Idle</option>
                            </select>
                        </div>
                        <div class="col-4">
                            <label style="font-size:0.8rem;color:orchid" ${tip('Compression level (0=default). Gzip: 1-9, Zstd: 1-19. Higher = smaller files but more CPU.')}>Compression Level</label>
                            <input type="number" class="form-control form-control-sm" id="ss-backupCompressionLevel" value="${s.backupCompressionLevel || 0}" min="0" max="19" style="font-family:var(--font-mono)" ${tip('0 = tool default (gzip: 6, zstd: 3). Lower = faster. Higher = better ratio but slower.')}>
                        </div>
                    </div>
                    <div style="margin-top:0.75rem;margin-bottom:0.35rem;font-size:0.75rem;font-weight:600;color:var(--text-secondary);text-transform:uppercase;letter-spacing:0.05em;">Retention Policy</div>
                    <div style="font-size:0.7rem;color:var(--text-muted);margin-bottom:0.5rem;">Scheduled backups are thinned over time. Manual backups are never auto-deleted.</div>
                    <div class="row mb-2">
                        <div class="col-3">
                            <label style="font-size:0.8rem;color:orchid" ${tip('Keep every backup created within this many hours')}>Keep All (hours)</label>
                            <input type="number" class="form-control form-control-sm" id="ss-backupRetainAllHours" value="${s.backupRetainAllHours}" style="font-family:var(--font-mono)" ${tip('Default: 24. All backups within this window are kept.')}>
                        </div>
                        <div class="col-3">
                            <label style="font-size:0.8rem;color:orchid" ${tip('After the keep-all window, retain one backup per day for this many days')}>1/Day (days)</label>
                            <input type="number" class="form-control form-control-sm" id="ss-backupRetainDailyDays" value="${s.backupRetainDailyDays}" style="font-family:var(--font-mono)" ${tip('Default: 7. One backup per day is kept in this tier.')}>
                        </div>
                        <div class="col-3">
                            <label style="font-size:0.8rem;color:orchid" ${tip('After the daily tier, retain one backup per week for this many days')}>1/Week (days)</label>
                            <input type="number" class="form-control form-control-sm" id="ss-backupRetainWeeklyDays" value="${s.backupRetainWeeklyDays}" style="font-family:var(--font-mono)" ${tip('Default: 30. One backup per week is kept in this tier.')}>
                        </div>
                        <div class="col-3">
                            <label style="font-size:0.8rem;color:orchid" ${tip('After the weekly tier, retain one backup per month for this many months')}>1/Month (months)</label>
                            <input type="number" class="form-control form-control-sm" id="ss-backupRetainMonthlyMonths" value="${s.backupRetainMonthlyMonths}" style="font-family:var(--font-mono)" ${tip('Default: 6. One backup per month is kept in this tier.')}>
                        </div>
                    </div>
                    </div><!-- /ss-backupFields -->
                </div>

                <div style="margin-bottom: 1.5rem;">
                    ${sectionHead('AI Helper', 'var(--warning)')}
                    <div style="margin-bottom:0.75rem;font-size:0.75rem;color:var(--text-muted)">Optional. Configure one or more providers for the AI log analysis feature.</div>
                    <div class="row mb-2">
                        <div class="col-6">
                            <label style="font-size:0.8rem;color:orchid" ${tip('API key from platform.openai.com for GPT-based log analysis')}>OpenAI API Key</label>
                            ${keyField('ss-openaiApiKey', s.openaiApiKey)}
                        </div>
                        <div class="col-6">
                            <label style="font-size:0.8rem;color:orchid" ${tip('API key from console.anthropic.com for Claude-based log analysis')}>Claude API Key</label>
                            ${keyField('ss-claudeApiKey', s.claudeApiKey)}
                        </div>
                    </div>
                    <div class="row mb-2">
                        <div class="col-6">
                            <label style="font-size:0.8rem;color:orchid" ${tip('API key from aistudio.google.com for Gemini-based log analysis')}>Gemini API Key</label>
                            ${keyField('ss-geminiApiKey', s.geminiApiKey)}
                        </div>
                        <div class="col-6">
                            <label style="font-size:0.8rem;color:orchid" ${tip('URL of your local Ollama instance (e.g. http://host:11434)')}>Ollama URL</label>
                            <input type="text" class="form-control form-control-sm" id="ss-ollamaUrl" value="${s.ollamaUrl || ''}" style="font-family:var(--font-mono)" ${tip('Full URL including port for a local Ollama server. No API key needed.')}>
                        </div>
                    </div>
                </div>

                <div style="margin-bottom: 1.5rem;">
                    ${sectionHead('Advanced', 'var(--text-secondary)')}
                    <div class="row mb-2">
                        <div class="col-6">
                            <label style="font-size:0.8rem;color:orchid" ${tip('When enabled, Thunderstore mod metadata is cached locally for faster browsing')}>Thunderstore Local Sync</label>
                            <select class="form-control form-control-sm" id="ss-thunderstore_local_sync" ${tip('Syncs mod metadata every 12 hours. Disable if you have limited disk space.')}>
                                <option value="1" ${s.thunderstore_local_sync == 1 ? 'selected' : ''}>Enabled</option>
                                <option value="0" ${s.thunderstore_local_sync == 0 ? 'selected' : ''}>Disabled</option>
                            </select>
                        </div>
                        <div class="col-6">
                            <label style="font-size:0.8rem;color:orchid" ${tip('Number of mods processed per batch during Thunderstore sync')}>Thunderstore Chunk Size</label>
                            <input type="number" class="form-control form-control-sm" id="ss-thunderstore_chunk_size" value="${s.thunderstore_chunk_size}" style="font-family:var(--font-mono)" ${tip('Lower values spawn more threads and increase CPU demand. Higher values use fewer threads but more memory per thread. Default: 1000.')}>
                        </div>
                    </div>
                </div>

                <div style="margin-bottom: 1.5rem;">
                    ${sectionHead('Analytics', 'var(--text-muted)')}
                    <div class="row mb-2">
                        <div class="col-12">
                            <label style="font-size:0.8rem;color:orchid">Usage Analytics</label>
                            <select class="form-control form-control-sm" id="ss-analyticsEnabled">
                                <option value="1" ${s.analyticsEnabled == 1 ? 'selected' : ''}>Enabled</option>
                                <option value="0" ${s.analyticsEnabled == 0 ? 'selected' : ''}>Disabled</option>
                            </select>
                            <div style="margin-top:0.4rem;font-size:0.72rem;color:var(--text-muted);line-height:1.5;">
                                When enabled, this installation periodically sends anonymous usage data to the PhValheim developer — including hostname, version, kernel, CPU/memory/disk info, AI feature usage (keys present, not values), and world+mod list. No player data is collected. This helps understand how PhValheim is being used.
                            </div>
                        </div>
                    </div>
                </div>

                <div style="text-align:center;">
                    <button class="action-btn success" onclick="saveServerSettings()" id="ssSubmitBtn" style="padding:0.5rem 2rem;">Save Settings</button>
                    <div id="ssStatus" style="margin-top:0.75rem;font-size:0.85rem;"></div>
                </div>
            `;
            // Highlight empty required fields with red border
            ['ss-gameDNS', 'ss-basePort', 'ss-steamAPIKey', 'ss-phvalheimClientURL'].forEach(id => {
                const el = document.getElementById(id);
                if (el && !el.value.trim()) {
                    el.style.borderColor = 'var(--danger)';
                    el.style.boxShadow = '0 0 0 1px var(--danger)';
                }
            });
        } catch(e) {
            body.innerHTML = '<div style="text-align:center;padding:2rem;color:var(--danger)">Error loading settings</div>';
        }
    }

    function togglePasswordField(id) {
        const input = document.getElementById(id);
        const btn = input.parentElement.querySelector('button');
        if (input.type === 'password') {
            input.type = 'text';
            btn.textContent = 'hide';
        } else {
            input.type = 'password';
            btn.textContent = 'show';
        }
    }

    async function saveServerSettings() {
        const btn = document.getElementById('ssSubmitBtn');
        const status = document.getElementById('ssStatus');
        btn.disabled = true;
        btn.textContent = 'Saving...';
        status.textContent = '';

        const payload = {
            gameDNS: document.getElementById('ss-gameDNS').value.trim(),
            basePort: parseInt(document.getElementById('ss-basePort').value) || 25000,
            defaultSeed: '',
            sessionTimeout: parseInt(document.getElementById('ss-sessionTimeout').value) || 2592000,
            maxLogSize: parseInt(document.getElementById('ss-maxLogSize').value) || 1000000,
            phvalheimClientURL: document.getElementById('ss-phvalheimClientURL').value.trim(),
            timezone: document.getElementById('ss-timezone').value.trim() || 'Etc/UTC',
            steamAPIKey: document.getElementById('ss-steamAPIKey').value.trim(),
            openaiApiKey: document.getElementById('ss-openaiApiKey').value.trim(),
            claudeApiKey: document.getElementById('ss-claudeApiKey').value.trim(),
            geminiApiKey: document.getElementById('ss-geminiApiKey').value.trim(),
            ollamaUrl: document.getElementById('ss-ollamaUrl').value.trim(),
            thunderstore_local_sync: parseInt(document.getElementById('ss-thunderstore_local_sync').value),
            thunderstore_chunk_size: parseInt(document.getElementById('ss-thunderstore_chunk_size').value) || 1000,
            analyticsEnabled: parseInt(document.getElementById('ss-analyticsEnabled').value),
            backupIntervalMinutes: parseInt(document.getElementById('ss-backupIntervalMinutes').value) || 30,
            backupRequireActivity: parseInt(document.getElementById('ss-backupRequireActivity').value),
            backupCompression: document.getElementById('ss-backupCompression').value,
            backupCompressionHour: parseInt(document.getElementById('ss-backupCompressionHour').value),
            backupRetainAllHours: parseInt(document.getElementById('ss-backupRetainAllHours').value) || 24,
            backupRetainDailyDays: parseInt(document.getElementById('ss-backupRetainDailyDays').value) || 7,
            backupRetainWeeklyDays: parseInt(document.getElementById('ss-backupRetainWeeklyDays').value) || 30,
            backupRetainMonthlyMonths: parseInt(document.getElementById('ss-backupRetainMonthlyMonths').value) || 6,
            backupCpuPriority: parseInt(document.getElementById('ss-backupCpuPriority').value),
            backupIoPriority: document.getElementById('ss-backupIoPriority').value,
            backupCompressionLevel: parseInt(document.getElementById('ss-backupCompressionLevel').value) || 0,
        };

        try {
            const res = await fetch('adminAPI.php?action=saveServerSettings', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify(payload)
            });
            const data = await res.json();
            if (data.success) {
                status.style.color = 'var(--success)';
                status.textContent = 'Settings saved. Reloading...';
                setTimeout(() => window.location.reload(), 1000);
                return;
            } else {
                status.style.color = 'var(--danger)';
                status.textContent = data.error || 'Failed to save settings.';
            }
        } catch(e) {
            status.style.color = 'var(--danger)';
            status.textContent = 'Network error.';
        }

        btn.disabled = false;
        btn.textContent = 'Save Settings';
    }

    function closeServerSettingsModal(event) {
        if (!event || event.target === document.getElementById('serverSettingsOverlay')) {
            document.getElementById('serverSettingsOverlay').classList.remove('show');
        }
    }

    // Close server settings modal on Escape
    document.addEventListener('keydown', function(e) {
        if (e.key === 'Escape') {
            closeServerSettingsModal();
            const migrationOverlay = document.getElementById('migrationNoticeOverlay');
            if (migrationOverlay) migrationOverlay.classList.remove('show');
        }
    });
    </script>

    <!-- AI Helper Panel -->
    <div class="ai-panel" id="aiPanel">
        <div class="ai-panel-header">
            <span class="ai-panel-title">
                <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                    <polygon points="12 2 15.09 8.26 22 9.27 17 14.14 18.18 21.02 12 17.77 5.82 21.02 7 14.14 2 9.27 8.91 8.26 12 2"/>
                </svg>
                AI Helper
            </span>
            <button class="ai-panel-close" onclick="toggleAiPanel()" title="Close">&times;</button>
            <div class="ai-panel-selectors">
                <div class="ai-selector-group">
                    <label class="ai-selector-label">Model</label>
                    <select id="aiModelSelect" class="ai-context-select" onchange="onAiModelChange()"></select>
                </div>
                <div class="ai-selector-group">
                    <label class="ai-selector-label">Context</label>
                    <select id="aiContextSelect" class="ai-context-select" onchange="onAiContextChange()">
                        <option value="none">No log context</option>
                        <option value="engine">Engine log</option>
                        <option value="ts">Thunderstore log</option>
                        <option value="backup">Backup log</option>
                        <optgroup label="World Logs" id="aiContextWorldGroup"></optgroup>
                    </select>
                </div>
            </div>
        </div>
        <div class="ai-panel-messages" id="aiMessages">
            <div class="ai-message assistant">
                <div class="ai-message-content">Hello! I can help you troubleshoot server issues, understand logs, and manage mods. Select a log context above for log-aware answers.</div>
            </div>
            <div class="ai-quick-prompts" id="aiQuickPrompts"></div>
        </div>
        <div class="ai-panel-input">
            <textarea id="aiInput" placeholder="Ask about logs, mods, errors..." rows="2"></textarea>
            <button onclick="sendAiMessage()" id="aiSendBtn" class="ai-send-btn" title="Send (Ctrl+Enter)">
                <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                    <line x1="22" y1="2" x2="11" y2="13"/><polygon points="22 2 15 22 11 13 2 9 22 2"/>
                </svg>
            </button>
        </div>
    </div>
    <div class="ai-panel-overlay" id="aiOverlay" onclick="toggleAiPanel()"></div>

    <script>
    // AI Helper Chat — Multi-provider support
    let aiChatHistory = [];
    let aiProviders = {};
    const AI_MAX_HISTORY = 20;

    // Cookie helpers
    function setCookie(name, value, days) {
        const d = new Date();
        d.setTime(d.getTime() + (days * 24 * 60 * 60 * 1000));
        document.cookie = name + '=' + encodeURIComponent(value) + ';expires=' + d.toUTCString() + ';path=/;SameSite=Lax';
    }
    function getCookie(name) {
        const match = document.cookie.match(new RegExp('(^| )' + name + '=([^;]+)'));
        return match ? decodeURIComponent(match[2]) : '';
    }

    // Load available providers from backend
    async function loadAiProviders() {
        try {
            const res = await fetch('adminAPI.php?action=getAiProviders');
            const data = await res.json();
            if (!data.success) return;
            aiProviders = data.providers;

            const keys = Object.keys(aiProviders);

            populateModelSelect();

            // Restore context cookie
            const savedContext = getCookie('aiContext');
            if (savedContext) {
                document.getElementById('aiContextSelect').value = savedContext;
            }

            // Update quick prompts to reflect initial context
            updateAiQuickPrompts();

            // Show AI Helper button only if providers exist
            document.getElementById('aiHelperBtn').style.display = keys.length === 0 ? 'none' : 'inline-flex';
        } catch(e) {
            console.error('Failed to load AI providers:', e);
        }
    }

    function populateModelSelect() {
        const modelSel = document.getElementById('aiModelSelect');
        modelSel.innerHTML = '';

        // Build flat list of all provider:model combinations
        const allModels = [];
        Object.keys(aiProviders).forEach(provider => {
            aiProviders[provider].models.forEach(m => {
                allModels.push({
                    provider: provider,
                    providerId: provider,
                    providerLabel: aiProviders[provider].label,
                    modelId: m.id,
                    modelLabel: m.label,
                    value: provider + ':' + m.id,
                    text: aiProviders[provider].label + ' - ' + m.label
                });
            });
        });

        // Group models by provider using optgroups
        const groupedByProvider = {};
        allModels.forEach(m => {
            if (!groupedByProvider[m.provider]) {
                groupedByProvider[m.provider] = [];
            }
            groupedByProvider[m.provider].push(m);
        });

        // Add optgroups with provider labels
        Object.keys(groupedByProvider).forEach(provider => {
            const group = document.createElement('optgroup');
            group.label = aiProviders[provider]?.label || provider;
            group.className = 'ai-model-optgroup';
            // Style optgroup label with data attribute for provider color
            group.setAttribute('data-provider', provider);
            groupedByProvider[provider].forEach(m => {
                const opt = document.createElement('option');
                opt.value = m.value;
                opt.textContent = m.modelLabel;
                opt.className = 'ai-model-option';
                group.appendChild(opt);
            });
            modelSel.appendChild(group);
        });

        // Restore from cookie if it exists
        const savedModel = getCookie('aiModel');
        if (savedModel && allModels.some(m => m.value === savedModel)) {
            modelSel.value = savedModel;
        } else if (allModels.length > 0) {
            modelSel.value = allModels[0].value;
        }
    }



    function onAiModelChange() {
        setCookie('aiModel', document.getElementById('aiModelSelect').value, 365);
    }

    function onAiContextChange() {
        setCookie('aiContext', document.getElementById('aiContextSelect').value, 365);
        updateAiQuickPrompts();
    }

    function updateAiQuickPrompts() {
        const contextSelect = document.getElementById('aiContextSelect');
        const contextValue = contextSelect.value;
        const quickPromptsContainer = document.getElementById('aiQuickPrompts');
        quickPromptsContainer.innerHTML = '';

        // Show quick prompts only if a world context is selected
        if (contextValue && contextValue.startsWith('world:')) {
            const worldName = contextValue.substring(6);

            // "Analyze world logs" button
            const analyzeBtn = document.createElement('div');
            analyzeBtn.className = 'ai-quick-prompt';
            analyzeBtn.textContent = "Analyze world logs for '" + worldName + "'?";
            analyzeBtn.onclick = function() { clickQuickPrompt('analyze-world'); };
            quickPromptsContainer.appendChild(analyzeBtn);

            // "Is world healthy" button
            const healthBtn = document.createElement('div');
            healthBtn.className = 'ai-quick-prompt';
            healthBtn.textContent = "Is '" + worldName + "' healthy?";
            healthBtn.onclick = function() { clickQuickPrompt('world-health'); };
            quickPromptsContainer.appendChild(healthBtn);
        }
    }

    function clickQuickPrompt(promptType) {
        if (promptType === 'analyze-world') {
            const contextSelect = document.getElementById('aiContextSelect');
            const contextValue = contextSelect.value;

            if (!contextValue.startsWith('world:')) {
                alert('Please select a world context first.');
                return;
            }

            const worldName = contextValue.substring(6);

            // Pre-fill the prompt
            const displayLabel = "Analyzing world '" + worldName + "'...";
            const prompt = 'You are a Valheim server log analyzer. Your task is to identify mod-related errors that occur after the most recent server start.\n\nFOCUS ONLY ON:\n- Mod loading failures\n- Missing dependencies\n- NullReferenceException in mod code\n- Assembly loading errors\n- Mod configuration errors\n- Errors that prevent the world from starting\n\nCOMPLETELY IGNORE:\n- Graphics, shaders, rendering, cameras, depth, textures, fonts, UI\n- The createDirectory /root/.config error\n- ZoneSystem, DungeonDB, RPC registration messages\n- Audio warnings\n- Any warning that does not affect mod loading or server startup\n\nINSTRUCTIONS:\n1. Read the entire log\n2. Find the most recent server start marker\n3. Only analyze entries after that point\n4. List mod errors in the order they appear\n5. If a mod fails early in startup, mark it: PRIMARY INVESTIGATION AREA - MAY PREVENT WORLD START\n6. Output as HTML bullet points\n7. End with one sentence stating whether critical mod errors exist\n\nOUTPUT FORMAT:\n<ul>\n<li><strong>ModName</strong> - Brief error description</li>\n</ul>\n<p><strong>Overall Health:</strong> One sentence summary</p>\n\nKeep responses concise and focused only on actionable mod issues.';

            openAiHelperWithContext(contextValue, prompt, displayLabel);
        } else if (promptType === 'world-health') {
            const contextSelect = document.getElementById('aiContextSelect');
            const contextValue = contextSelect.value;

            if (!contextValue.startsWith('world:')) {
                alert('Please select a world context first.');
                return;
            }

            const worldName = contextValue.substring(6);

            // Pre-fill the prompt
            const displayLabel = "Checking health of '" + worldName + "'...";
            const prompt = 'Based on the log provided, assess the overall health and stability of this Valheim world. Consider:\n\n- Mod loading status and any critical failures\n- Server performance indicators (TPS, memory, etc.)\n- Player activity and connectivity issues\n- Any errors that could impact gameplay\n\nProvide a brief, actionable health assessment in HTML format.';

            openAiHelperWithContext(contextValue, prompt, displayLabel);
        }
    }

    function toggleAiPanel() {
        const panel = document.getElementById('aiPanel');
        const overlay = document.getElementById('aiOverlay');
        const open = panel.classList.toggle('open');
        overlay.classList.toggle('open', open);
        if (open) {
            // Auto-select last world context if available
            if (window.lastSelectedWorld) {
                const contextSelect = document.getElementById('aiContextSelect');
                const worldContextValue = 'world:' + window.lastSelectedWorld;
                let found = false;
                for (let i = 0; i < contextSelect.options.length; i++) {
                    if (contextSelect.options[i].value === worldContextValue) {
                        contextSelect.value = worldContextValue;
                        found = true;
                        break;
                    }
                }
                if (!found) {
                    const group = document.getElementById('aiContextWorldGroup');
                    const opt = document.createElement('option');
                    opt.value = worldContextValue;
                    opt.textContent = window.lastSelectedWorld;
                    group.appendChild(opt);
                    contextSelect.value = worldContextValue;
                }
            }
            // Always update quick prompts when opening the panel
            updateAiQuickPrompts();
            document.getElementById('aiInput').focus();
        }
    }

    async function sendAiMessage(displayLabel) {
        const input = document.getElementById('aiInput');
        const msg = input.value.trim();
        if (!msg) return;
        input.value = '';
        appendAiMessage('user', displayLabel || msg);

        // Parse provider:model format from consolidated dropdown
        // Split only on first ':' to handle ollama models with colons (e.g. ollama:llama2:7b)
        const modelSelect = document.getElementById('aiModelSelect');
        const modelValue = modelSelect.value;
        const colonIdx = modelValue.indexOf(':');
        const provider = modelValue.substring(0, colonIdx);
        const model = modelValue.substring(colonIdx + 1);
        const context = document.getElementById('aiContextSelect').value;

        aiChatHistory.push({ role: 'user', content: msg });
        if (aiChatHistory.length > AI_MAX_HISTORY) aiChatHistory = aiChatHistory.slice(-AI_MAX_HISTORY);

        const typingId = appendAiTyping();
        document.getElementById('aiSendBtn').disabled = true;

        try {
            const res = await fetch('adminAPI.php?action=aiHelper', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({
                    message: msg,
                    history: aiChatHistory.slice(0, -1),
                    context: context,
                    provider: provider,
                    model: model
                })
            });
            const data = await res.json();
            removeAiTyping(typingId);
            if (data.success) {
                // Strip ```html and ``` code blocks from response
                let reply = data.reply;
                reply = reply.replace(/^```html\n/gm, '');
                reply = reply.replace(/\n```$/gm, '');
                reply = reply.replace(/```html/g, '');
                reply = reply.replace(/```/g, '');

                // Build provider info string from selected option text
                const modelLabel = modelSelect.selectedOptions[0]?.text || (provider + ' - ' + model);
                appendAiMessage('assistant', reply, modelLabel);
                aiChatHistory.push({ role: 'assistant', content: data.reply });
                if (aiChatHistory.length > AI_MAX_HISTORY) aiChatHistory = aiChatHistory.slice(-AI_MAX_HISTORY);
            } else {
                appendAiMessage('error', data.error || 'Error reaching AI');
            }
        } catch(e) {
            removeAiTyping(typingId);
            appendAiMessage('error', 'Network error: ' + e.message);
        }
        document.getElementById('aiSendBtn').disabled = false;
    }

    function appendAiMessage(role, text, providerInfo) {
        const container = document.getElementById('aiMessages');
        const wrapper = document.createElement('div');
        wrapper.className = 'ai-message-wrapper';
        const div = document.createElement('div');
        div.className = 'ai-message ' + role;
        const content = document.createElement('div');
        content.className = 'ai-message-content';
        // If response contains HTML tags, render directly; otherwise do markdown-like formatting
        if (role === 'assistant' && /<[a-z][\s\S]*>/i.test(text)) {
            content.innerHTML = text;
        } else {
            let html = text
                .replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;')
                .replace(/```([\s\S]*?)```/g, '<pre><code>$1</code></pre>')
                .replace(/`([^`]+)`/g, '<code>$1</code>')
                .replace(/\*\*([^*]+)\*\*/g, '<strong>$1</strong>')
                .replace(/\n/g, '<br>');
            content.innerHTML = html;
        }
        div.appendChild(content);
        wrapper.appendChild(div);

        // Add provider info footer for assistant messages
        if (role === 'assistant' && providerInfo) {
            const footer = document.createElement('div');
            footer.className = 'ai-message-footer';
            footer.textContent = 'generated by ' + providerInfo;
            wrapper.appendChild(footer);
        }

        container.appendChild(wrapper);
        container.scrollTop = container.scrollHeight;
    }

    let aiTypingCounter = 0;
    function appendAiTyping() {
        const container = document.getElementById('aiMessages');
        const id = 'ai-typing-' + (++aiTypingCounter);
        const div = document.createElement('div');
        div.className = 'ai-message assistant ai-typing';
        div.id = id;
        div.innerHTML = '<div class="ai-message-content"><span class="ai-typing-dots"><span>.</span><span>.</span><span>.</span></span></div>';
        container.appendChild(div);
        container.scrollTop = container.scrollHeight;
        return id;
    }

    function removeAiTyping(id) {
        const el = document.getElementById(id);
        if (el) el.remove();
    }

    document.getElementById('aiInput').addEventListener('keydown', function(e) {
        if (e.key === 'Enter' && !e.shiftKey) {
            e.preventDefault();
            sendAiMessage();
        }
    });

    // Initialize providers on load
    loadAiProviders();

    // Track last selected world when clicking on world rows
    document.addEventListener('click', function(e) {
        const row = e.target.closest('tr[data-world]');
        if (row) {
            window.lastSelectedWorld = row.getAttribute('data-world');
        }
    });

    // Handle ?aiContext= and ?aiPrompt= URL params (from log viewer fallback)
    (function() {
        const params = new URLSearchParams(window.location.search);
        const aiCtx = params.get('aiContext');
        const aiPrompt = params.get('aiPrompt');
        const aiLabel = params.get('aiLabel');
        if (aiCtx) {
            setTimeout(function() { openAiHelperWithContext(aiCtx, aiPrompt || '', aiLabel || null); }, 1500);
            window.history.replaceState({}, '', window.location.pathname);
        }
    })();
    </script>

<span id="piEgg" style="position:fixed;bottom:4px;right:6px;font-size:9px;color:rgba(255,255,255,0.08);cursor:default;z-index:9999;user-select:none;line-height:1;">&pi;</span>
<div id="piModal" style="display:none;position:fixed;top:0;left:0;right:0;bottom:0;background:rgba(0,0,0,0.7);z-index:10000;justify-content:center;align-items:center;cursor:pointer;" onclick="this.style.display='none';">
    <img src="/images/lordnikon.png" style="max-width:90%;max-height:90%;border-radius:6px;box-shadow:0 0 30px rgba(0,0,0,0.8);">
</div>
<script>document.getElementById('piEgg').addEventListener('click',function(){document.getElementById('piModal').style.display='flex';});</script>
</body>
</html>
