<?php
include '/opt/stateless/nginx/www/includes/config_env_puller.php';
include '/opt/stateless/nginx/www/includes/phvalheim-frontend-config.php';
include '../includes/db_sets.php';
include '../includes/db_gets.php';
# Absolute + require_once: a relative include would redeclare its functions fatally.
require_once '/opt/stateless/nginx/www/includes/whatsnew.php';
require_once '/opt/stateless/nginx/www/includes/hugin.php';

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


// Per-source manual sync, from the Sync panel's individual buttons.
if (!empty($_GET['manual_mod_sync'])) {
    $syncSource = $_GET['manual_mod_sync'];
    if (in_array($syncSource, ['all', 'thunderstore', 'hexium'], true)) {
        exec("/opt/stateless/engine/tools/modSync.py --source "
            . escapeshellarg($syncSource)
            . " --trigger manual --force >> /opt/stateful/logs/modSync.log 2>&1 &");
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

// Get initial world data for page load
function getWorldsData($pdo, $gameDNS, $phvalheimHost, $httpScheme) {
    $stmt = $pdo->query("SELECT status, mode, name, port, external_endpoint, seed, autostart, beta, date_updated, IFNULL(vanilla,0) AS vanilla, password FROM worlds ORDER BY name");
    $worlds = [];

    foreach ($stmt as $row) {
        // Same positional launch-string contract as getLaunchString() in db_gets.php and
        // getWorldsJson() in adminAPI.php -- keep all three in step, and only ever append.
        $vanilla = (int)$row['vanilla'];
        $password = $vanilla ? ($row['password'] ?: "") : "hammertime";
        $launchString = base64_encode("launch?{$row['name']}?$password?$gameDNS?{$row['port']}?$phvalheimHost?$httpScheme?$vanilla");

        // A vanilla world has no client payload and no BepInEx, so phvalheim:// is
        // meaningless for it -- handing that link to the client makes it try to sync mods
        // that do not exist. Join it the way the public card does.
        //
        // This used to be an unconditional +connect, which is wrong for a crossplay world:
        // crossplay opens a PlayFab server that cannot be joined by IP at all, so the button
        // failed silently. getVanillaJoinInfo() follows the RUNNING backend and is shared with
        // the public card, so the two can no longer disagree.
        $isRunning = ($row['mode'] === 'running');
        $joinInfo = $vanilla
            ? getVanillaJoinInfo($pdo, $row['name'], $gameDNS, $row['port'], $isRunning)
            : ['href' => 'phvalheim://?' . $launchString, 'playfab' => false, 'joinCode' => NULL];

        $worlds[] = [
            // May be NULL: a crossplay world that is up but has not registered its lobby yet
            // has no code to pass. Both render paths show a non-link state for that.
            'launchHref' => $joinInfo['href'],
            'launchPlayfab' => $joinInfo['playfab'],
            'launchJoinCode' => $joinInfo['joinCode'],
            'name' => $row['name'],
            'status' => $row['status'],
            'mode' => $row['mode'],
            'port' => $row['port'],
            'endpoint' => $row['external_endpoint'],
            'seed' => $row['seed'],
            'autostart' => (int)$row['autostart'],
            'beta' => (int)$row['beta'],
            'vanilla' => $vanilla,
            'launchString' => $launchString,
            'modCount' => getTotalModCountOfWorld($pdo, $row['name']),
            // Saved settings that Valheim will not see until the world restarts. Empty for a
            // stopped world -- there is nothing running for them to be pending against.
            'restartPending' => worldRestartPending($pdo, $row['name'], $isRunning),
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
                    <!-- modSync.log, not tsSync.log: the log covers every catalogue now, not
                         just Thunderstore, so the label would have been wrong either way. -->
                    <a href="readLog.php?logfile=modSync.log#bottom" target="_blank" class="nav-item">
                        <svg class="nav-icon" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 4v5h.582m15.356 2A8.001 8.001 0 004.582 9m0 0H9m11 11v-5h-.581m0 0a8.003 8.003 0 01-15.357-2m15.357 2H15"/>
                        </svg>
                        Mod Catalogues
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
<?php
                    # The manual catalogue-sync nav item lived here until 2.43: a
                    # warning-coloured button with a confirm dialog, a spinner and a stop
                    # button, because the old sync was a 12-hourly job that ran for hours and
                    # could be left half-finished.
                    #
                    # None of that holds now. A sync of an unchanged catalogue is about two
                    # seconds, it runs hourly on its own, and Sync & Maintenance shows live
                    # per-catalogue state with its own per-source sync link. A prominent
                    # manual trigger for a job needing no intervention was an invitation to
                    # interrupt something harmless.
                    #
                    # PHP comment, not HTML: an HTML comment would ship this to every page
                    # load, and the removed identifiers would keep turning up in the served
                    # markup for anyone grepping to check they were gone.
?>
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
<?php
                    // 2.45: no longer hidden until a credential exists. The panel's
                    // health scan is deterministic PHP and needs no provider at all, so
                    // hiding the entry point meant the one operator who most needed the
                    // diagnostics -- the one who had configured nothing -- could not
                    // reach them.
                    ?>
                    <button class="ai-helper-btn" id="aiHelperBtn" onclick="toggleAiPanel()" title="Ask Hugin — the AI Helper">
                        <?php echo huginSvg('idle ai-btn-hugin', 20); ?>
                        Ask Hugin
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
                                            <?php // Saved settings Valheim has not seen yet. Named, not just flagged:
                                                  // "restart pending" alone leaves you guessing which change is waiting. ?>
                                            <?php if (!empty($world['restartPending'])): ?>
                                            <span class="restart-pending-badge"
                                                  title="Saved, but not applied until the world restarts: <?php echo htmlspecialchars(implode(', ', $world['restartPending'])); ?>">restart
                                                pending</span>
                                            <?php endif; ?>
                                        </td>
                                        <td>
                                            <div class="action-group">
                                                <?php if ($world['mode'] === 'running'): ?>
                                                <?php if ($world['launchHref'] === NULL): ?>
                                                <?php // Crossplay world, up but no join code registered yet. There is
                                                      // genuinely nothing to launch with, so say so rather than offer a
                                                      // link with an empty argument. ?>
                                                <span class="action-btn disabled" data-action="launch" title="Crossplay world: waiting for its join code">starting&hellip;</span>
                                                <?php else: ?>
                                                <a href="<?php echo htmlspecialchars($world['launchHref']); ?>" class="action-btn success" data-action="launch">Launch</a>
                                                <?php endif; ?>
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
                                                <?php if (!$world['vanilla']): /* tick health comes from the TickMonitor BepInEx plugin, which a vanilla world does not run */ ?>
                                                <div class="world-resource-item">
                                                    <span class="resource-label">HEALTH</span>
                                                    <div class="world-load-bar" title="Server tick rate (target: 50 TPS). 45-50 = healthy, 35-44 = busy, below 35 = lagging. Low TPS means the server can't keep up with game updates.">
                                                        <div class="world-load-fill" style="width:0%"></div>
                                                    </div>
                                                    <span class="resource-value world-load-value">—</span>
                                                </div>
                                                <?php endif; ?>
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
                                                <?php if ($world['vanilla']): ?>
                                                <span class="action-btn disabled" data-action="edit-mods" title="This is a vanilla world — it runs no mods. Turn off &quot;Vanilla world&quot; in Settings to add mods.">Edit Mods</span>
                                                <?php else: ?>
                                                <a href="edit_world.php?world=<?php echo urlencode($world['name']); ?>" class="action-btn primary" data-action="edit-mods">Edit Mods</a>
                                                <?php endif; ?>
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
                                                <?php if (!$world['vanilla']): /* tick health comes from the TickMonitor BepInEx plugin, which a vanilla world does not run */ ?>
                                                <div class="world-resource-item">
                                                    <span class="resource-label">HEALTH</span>
                                                    <div class="world-load-bar" title="Server tick rate (target: 50 TPS). 45-50 = healthy, 35-44 = busy, below 35 = lagging. Low TPS means the server can't keep up with game updates.">
                                                        <div class="world-load-fill" style="width:0%"></div>
                                                    </div>
                                                    <span class="resource-value world-load-value">—</span>
                                                </div>
                                                <?php endif; ?>
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
                        <!-- Mod catalogues (2.43). One block per source, filled in by
                             refreshModSyncPanel() from mod_sync_runs. Replaces the three
                             Thunderstore-only rows that could only ever say "idle" and a
                             timestamp -- they had no counts, no phase, and no way to
                             describe a second catalogue. -->
                        <div id="modSyncPanel" class="mod-sync-panel"></div>

                        <ul class="status-info-list" id="syncStatusList">
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
                <button class="backup-tab active" data-tab="settingsTab" onclick="switchSettingsTab('settingsTab', this)">General</button>
                <button class="backup-tab" data-tab="optionsTab" onclick="switchSettingsTab('optionsTab', this)">Options</button>
                <button class="backup-tab" data-tab="accessTab" onclick="switchSettingsTab('accessTab', this)">Access</button>
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

    <!-- Valheim 1.0 access-id notice. Rendered hidden and shown by the Access tab, not on
         page load -- it is explaining the list the admin is about to look at. -->
    <?php if ($accessIdNoticeShown == 0): ?>
    <div class="mods-modal-overlay" id="accessIdNoticeOverlay">
        <div class="mods-modal" onclick="event.stopPropagation()" style="max-width: 620px;">
            <div class="mods-modal-header">
                <h3 class="mods-modal-title">
                    <svg width="20" height="20" fill="none" stroke="var(--success)" viewBox="0 0 24 24" style="vertical-align: middle; margin-right: 0.5rem;">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z"/>
                    </svg>
                    Player IDs updated for Valheim 1.0
                </h3>
            </div>
            <div class="mods-modal-body">
                <p style="color: var(--text-secondary); margin-bottom: 1rem;">
                    Valheim 1.0 stopped matching players on the bare SteamID64 and now uses a
                    platform-prefixed form. A Steam id needs a <code style="color: var(--accent-primary);">V_</code>
                    in front of it; a player whose id is missing the prefix is refused with a
                    <strong>&ldquo;Banned&rdquo;</strong> message, even though nothing banned them.
                </p>
                <div class="pv-note" style="margin-bottom: 1rem;">
                    <div style="font-family: var(--font-mono); font-size: 0.85rem;">
                        <span style="color: var(--text-muted);">before</span>&nbsp;&nbsp;76561198012345678<br>
                        <span style="color: var(--text-muted);">after</span>&nbsp;&nbsp;&nbsp;<span style="color: var(--accent-primary);">V_</span>76561198012345678
                    </div>
                </div>
                <p style="color: var(--text-secondary); margin-bottom: 1rem;">
                    <strong>Your existing Citizens, Admins and Banned lists have already been
                    converted</strong> &mdash; there is nothing for you to do. Entries that were
                    already prefixed, and console ids, were left alone.
                </p>
                <p style="color: var(--text-muted); font-size: 0.85rem; margin-bottom: 0;">
                    You can still paste a plain SteamID64 into any of these lists. It gets the
                    prefix added for you when you save.
                </p>
            </div>
            <div style="display: flex; justify-content: center; padding: 1rem;">
                <button class="action-btn success" onclick="dismissAccessIdNotice()" style="padding: 0.5rem 2rem; font-size: 0.9rem;">Got it</button>
            </div>
        </div>
    </div>
    <?php endif; ?>

    <!-- Access switch rename/inversion notice.
         Kept SEPARATE from the id notice above rather than folded into it: that one
         states the lists "have already been converted", which is only true for a server
         that actually had ids to convert. This one is true for every upgrader. When both
         are armed they are shown one after the other, see maybeShowAccessNotices(). -->
    <!--
        Shown when Settings is opened on a world whose access list is ON but empty. That state
        is not merely untidy: Valheim enforces permittedlist.txt only when it has entries, so an
        empty one restricts nobody. New worlds can no longer be created this way, but worlds
        made before that check still exist, and this is where their owner will see it.

        Not dismissible-forever on purpose -- it reflects a live condition, so it stops
        appearing when the condition is fixed rather than when someone clicks "don't show me".
    -->
    <div class="mods-modal-overlay" id="emptyAccessListOverlay">
        <div class="mods-modal" onclick="event.stopPropagation()" style="max-width: 600px;">
            <div class="mods-modal-header">
                <h3 class="mods-modal-title">
                    <svg width="20" height="20" fill="none" stroke="var(--warning)" viewBox="0 0 24 24" style="vertical-align: middle; margin-right: 0.5rem;">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 9v2m0 4h.01M5 19h14a2 2 0 001.84-2.75L13.74 4a2 2 0 00-3.48 0L3.16 16.25A2 2 0 005 19z"/>
                    </svg>
                    This world&rsquo;s access list is empty
                </h3>
            </div>
            <div class="mods-modal-body">
                <p style="color: var(--text-secondary); margin-bottom: 1rem;">
                    <strong id="emptyAccessListWorld"></strong> has <strong>Use Access List</strong>
                    switched on, but there is nobody on the list.
                </p>
                <p style="color: var(--text-secondary); margin-bottom: 1rem;">
                    Valheim only applies a permitted list when it has entries, so an empty list is
                    not &ldquo;nobody may join&rdquo; &mdash; it is <strong>no restriction at
                    all</strong>. Add at least one player, or switch the access list off if the
                    world is meant to be open.
                </p>
            </div>
            <div class="mods-modal-footer">
                <button class="btn-modal btn-modal-secondary" onclick="dismissEmptyAccessList()">Later</button>
                <button class="btn-modal btn-modal-primary" onclick="dismissEmptyAccessList(true)">Take me to Access</button>
            </div>
        </div>
    </div>

    <?php if ($accessSwitchNoticeShown == 0): ?>
    <div class="mods-modal-overlay" id="accessSwitchNoticeOverlay">
        <div class="mods-modal" onclick="event.stopPropagation()" style="max-width: 620px;">
            <div class="mods-modal-header">
                <h3 class="mods-modal-title">
                    <svg width="20" height="20" fill="none" stroke="var(--warning)" viewBox="0 0 24 24" style="vertical-align: middle; margin-right: 0.5rem;">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 9v2m0 4h.01M5 19h14a2 2 0 001.84-2.75L13.74 4a2 2 0 00-3.48 0L3.16 16.25A2 2 0 005 19z"/>
                    </svg>
                    The world access switch changed name
                </h3>
            </div>
            <div class="mods-modal-body">
                <p style="color: var(--text-secondary); margin-bottom: 1rem;">
                    <strong>Public World</strong> is now <strong>Use Access List</strong>, and it reads
                    the opposite way round. It never had anything to do with the Valheim server
                    browser &mdash; it only decides whether your Citizens list is enforced &mdash; so
                    the old name kept being read as the separate
                    <strong>List in server browser</strong> option.
                </p>
                <div class="pv-note" style="margin-bottom: 1rem;">
                    <div style="font-size: 0.85rem;">
                        <span style="color: var(--text-muted);">before</span>&nbsp;&nbsp;Public World <strong>on</strong> &nbsp;&mdash;&nbsp; anyone may join<br>
                        <span style="color: var(--text-muted);">after</span>&nbsp;&nbsp;&nbsp;Use Access List <strong>off</strong> &nbsp;&mdash;&nbsp; anyone may join
                    </div>
                </div>
                <p style="color: var(--text-secondary); margin-bottom: 1rem;">
                    <strong>Nothing about your worlds changed</strong> &mdash; not who can join, not
                    your lists, nothing stored. Only the wording on the switch. A world that read
                    &ldquo;Public World: on&rdquo; now reads &ldquo;Use Access List: off&rdquo;.
                </p>
                <p style="color: var(--text-muted); font-size: 0.85rem; margin-bottom: 0;">
                    So if a switch looks backwards to you, it is showing the same setting you
                    already had. Flipping it <em>will</em> change who can join.
                </p>
            </div>
            <div style="display: flex; justify-content: center; padding: 1rem;">
                <button class="action-btn success" onclick="dismissAccessSwitchNotice()" style="padding: 0.5rem 2rem; font-size: 0.9rem;">Got it</button>
            </div>
        </div>
    </div>
    <?php endif; ?>

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

    <!-- Ollama provider conversion (one shot, only when the migration actually converted
         something). z-index 1070: this must sit ABOVE the What's New dialog below, because
         both can fire on the same boot and this one describes a change to the operator's
         own configuration rather than a list of features. -->
    <!-- Meet Hugin (one shot) ------------------------------------------------------
         z-index 1075 puts him ABOVE the Ollama notice (1070) and What's New, so on an
         upgrade the operator is introduced to the bird first and the technical notes
         follow underneath as each is dismissed. Gated on setupComplete == 2 so a fresh
         install meets him after the setup wizard rather than on top of it. -->
    <?php // ?? 1 is load-bearing: an UNDEFINED $huginNoticeShown is null, and null == 0 is
      // TRUE in PHP -- so a missing variable would show this dialog on every single page
      // load forever. Defaulting to 1 means "already seen" when we cannot tell.
      if ($setupComplete == 2 && ($huginNoticeShown ?? 1) == 0): ?>
    <div class="mods-modal-overlay show" id="huginNoticeOverlay" style="z-index:1075;">
        <div class="mods-modal" onclick="event.stopPropagation()" style="max-width: 560px;">
            <div class="mods-modal-body" style="padding-top: 1.75rem;">

                <div style="text-align: center; margin-bottom: 1.25rem;">
                    <?php echo huginSvg('idle hugin-hello', 92); ?>
                    <h3 style="margin: 0.75rem 0 0.25rem; font-size: 1.35rem; color: var(--text-primary);">
                        Hello, I&rsquo;m Hugin.
                    </h3>
                    <p style="margin: 0; color: var(--text-secondary); font-size: 0.9rem;">
                        Odin had two ravens. You get one, and he watches your server.
                    </p>
                </div>

                <div style="background: var(--bg-primary); border-radius: 8px; padding: 1rem 1.15rem; margin-bottom: 1.1rem;">
                    <p style="margin: 0 0 0.7rem; color: var(--text-secondary); font-size: 0.88rem; line-height: 1.55;">
                        <strong style="color: var(--text-primary);">Ask me anything about your worlds.</strong>
                        I can read your logs, work out why a world will not start, check who is
                        allowed to join, and tell you what needs attention &mdash; in plain words,
                        no log-diving required.
                    </p>
                    <p style="margin: 0; color: var(--text-secondary); font-size: 0.88rem; line-height: 1.55;">
                        <strong style="color: var(--text-primary);">I can do things too.</strong>
                        Start a world, take a backup, change settings, fix an access list. Anything
                        with consequences I hand you a card first &mdash; <em>you</em> press Apply.
                        I never change your server behind your back.
                    </p>
                </div>

                <p style="color: var(--text-muted); font-size: 0.82rem; line-height: 1.55; margin-bottom: 1.25rem; text-align: center;">
                    I need a brain to borrow &mdash; bring your own AI provider (OpenAI, Anthropic,
                    Gemini, or your own local model).<br>
                    Set one up in <strong style="color: var(--text-secondary);">Server Settings &rarr; AI Helper</strong>,
                    or press <strong style="color: var(--text-secondary);">Ask Hugin</strong> and
                    click <strong style="color: var(--text-secondary);">+ Provider</strong>.
                </p>

                <p style="color: var(--text-muted); font-size: 0.78rem; text-align: center; margin-bottom: 1.25rem;">
                    No provider yet? I still run a health check on your server for free.
                </p>

                <div style="text-align: center; display: flex; gap: 0.6rem; justify-content: center;">
                    <button class="action-btn success" onclick="dismissHuginNotice(true)"
                            style="padding: 0.5rem 1.5rem; font-size: 0.9rem;">Set up an AI provider</button>
                    <button class="action-btn" onclick="dismissHuginNotice(false)"
                            style="padding: 0.5rem 1.5rem; font-size: 0.9rem;">Maybe later</button>
                </div>
            </div>
        </div>
    </div>
    <script>
    async function dismissHuginNotice(openSettings) {
        const el = document.getElementById('huginNoticeOverlay');
        if (el) el.classList.remove('show');
        // Fire and forget, but close regardless: the operator has read it, and a dialog that
        // will not go away is worse than a flag that clears on the next page load.
        try { await fetch('adminAPI.php?action=dismissHuginNotice', { method: 'POST' }); }
        catch (e) { /* dismissed visually either way */ }

        // Take them straight there rather than making them hunt for it.
        //
        // showServerSettingsModal, NOT openServerSettings -- the latter does not exist. A
        // `typeof x === "function"` guard around a name that is never defined is silently
        // dead code that reads as working, and the only symptom would be a button in the
        // welcome dialog that quietly does nothing.
        if (openSettings) showServerSettingsModal();
    }
    </script>
    <?php endif; ?>

    <?php if ($aiOllamaNotice > 0): ?>
    <div class="mods-modal-overlay show" id="aiOllamaNoticeOverlay" style="z-index:1070;">
        <div class="mods-modal" onclick="event.stopPropagation()" style="max-width: 620px;">
            <div class="mods-modal-header">
                <h3 class="mods-modal-title">
                    <svg width="20" height="20" fill="none" stroke="var(--warning)" viewBox="0 0 24 24" style="vertical-align: middle; margin-right: 0.5rem;">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 9v2m0 4h.01M5 19h14a2 2 0 001.84-2.75L13.74 4a2 2 0 00-3.5 0L3.2 16.25A2 2 0 005 19z"/>
                    </svg>
                    <?php echo $aiOllamaNotice === 1 ? 'Your Ollama provider was updated' : 'Your Ollama providers were updated'; ?>
                </h3>
            </div>
            <div class="mods-modal-body">
                <p style="color: var(--text-secondary); font-size: 0.9rem; margin-bottom: 1rem;">
                    PhValheim no longer has a separate <strong>Ollama</strong> provider type. Ollama serves an
                    OpenAI-compatible API, so it is now one of the endpoint presets on the
                    <strong>OpenAI-compatible</strong> type &mdash; one adapter instead of two.
                </p>
                <p style="color: var(--text-secondary); font-size: 0.9rem; margin-bottom: 1rem;">
                    <?php echo $aiOllamaNotice === 1 ? 'One existing provider was' : $aiOllamaNotice . ' existing providers were'; ?>
                    converted automatically. Because the two APIs live at different paths, the base URL changed:
                </p>
                <div style="background: var(--bg-primary); border-radius: 6px; padding: 0.75rem 1rem; margin-bottom: 1rem; font-family: var(--font-mono); font-size: 0.8rem; color: var(--text-muted);">
                    http://your-host:11434 &nbsp;&rarr;&nbsp; http://your-host:11434<span style="color: var(--accent-secondary);">/v1</span>
                </div>
                <p style="color: var(--text-secondary); font-size: 0.85rem; margin-bottom: 1.25rem;">
                    Nothing else changed &mdash; same host, same models, no API key needed. Check it under
                    <strong>Server Settings &rarr; AI Helper</strong>, or open Hugin and use
                    <strong>Refresh models</strong>. If the model list comes back empty, confirm the
                    <code>/v1</code> path is reachable from this container.
                </p>
                <div style="text-align: center;">
                    <button class="action-btn success" onclick="dismissAiOllamaNotice()" style="padding: 0.5rem 2rem; font-size: 0.9rem;">Got it</button>
                </div>
            </div>
        </div>
    </div>
    <script>
    async function dismissAiOllamaNotice() {
        const el = document.getElementById('aiOllamaNoticeOverlay');
        if (el) el.classList.remove('show');
        // Fire and forget, but never leave the modal up if the POST fails: the operator has
        // read it, and a dialog that will not close is worse than a flag cleared next boot.
        try { await fetch('adminAPI.php?action=dismissAiOllamaNotice', { method: 'POST' }); }
        catch (e) { /* cleared visually either way */ }
    }
    </script>
    <?php endif; ?>

    <!-- What's New Dialog (one shot, after every upgrade) -->
    <?php
    // Gated on setupComplete == 2 so it queues BEHIND the setup wizard and the migration
    // notice rather than stacking on top of them -- dismissing those is what sets 2.
    $whatsNew = ($setupComplete == 2)
        ? whatsNewSince($whatsNewShownVersion, $phvalheimVersion)
        : [];
    ?>
    <?php if (!empty($whatsNew)): ?>
    <div class="mods-modal-overlay show" id="whatsNewOverlay">
        <div class="mods-modal" onclick="event.stopPropagation()" style="max-width: 560px;">
            <div class="mods-modal-header">
                <h3 class="mods-modal-title">
                    <svg width="20" height="20" fill="none" stroke="var(--success)" viewBox="0 0 24 24" style="vertical-align: middle; margin-right: 0.5rem;">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13 10V3L4 14h7v7l9-11h-7z"/>
                    </svg>
                    What's New in v<?php echo htmlspecialchars($phvalheimVersion); ?>
                </h3>
            </div>
            <div class="mods-modal-body">
                <?php foreach ($whatsNew as $version => $items): ?>
                <?php if (count($whatsNew) > 1): ?>
                <h6 style="color: var(--text-secondary); margin-bottom: 0.5rem; font-size: 0.8rem; text-transform: uppercase; letter-spacing: 0.05em;">v<?php echo htmlspecialchars($version); ?></h6>
                <?php endif; ?>
                <ul style="color: var(--text-secondary); font-size: 0.9rem; line-height: 1.6; margin-bottom: 1.25rem; padding-left: 1.1rem;">
                    <?php foreach ($items as $item): ?>
                    <li style="margin-bottom: 0.5rem;"><?php echo $item; ?></li>
                    <?php endforeach; ?>
                </ul>
                <?php endforeach; ?>

                <div style="text-align: center;">
                    <button class="action-btn success" onclick="dismissWhatsNew()" style="padding: 0.5rem 2rem; font-size: 0.9rem;">Got it</button>
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

    // Worlds the AI Helper's "About" picker can offer.
    //
    // 2.45: this used to build an <optgroup> of "world:<name>" context values, one per
    // world log, and it ran only ONCE (a `populated` latch) -- so a world created after
    // the page loaded never appeared. It is now just a name list, re-rendered on every
    // poll, because the panel's picker is a hint about what the operator is asking about
    // rather than a selector for which log gets pasted into the prompt.
    function updateAiContextWorlds(worlds) {
        aiKnownWorlds = (worlds || []).map(function (w) { return w.name; });
        if (typeof aiRenderContextOptions === 'function') aiRenderContextOptions();
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

        updateRestartPendingBadge(row, world);

        // Update action buttons
        updateActionButtons(row, world);
    }

    // "restart pending" has to be ADDED and REMOVED here, not just added: the badge clears
    // when the world restarts, and a poll that can only ever set it would leave the dashboard
    // claiming a restart was still owed on a world that had just had one.
    function updateRestartPendingBadge(row, world) {
        const nameCell = row.querySelector('.world-name');
        if (!nameCell) { return; }
        const existing = nameCell.parentNode.querySelector('.restart-pending-badge');
        const pending = Array.isArray(world.restartPending) ? world.restartPending : [];

        if (!pending.length) {
            if (existing) { existing.remove(); }
            return;
        }
        const title = 'Saved, but not applied until the world restarts: ' + pending.join(', ');
        if (existing) {
            existing.title = title;
            return;
        }
        const badge = document.createElement('span');
        badge.className = 'restart-pending-badge';
        badge.title = title;
        badge.textContent = 'restart pending';
        nameCell.insertAdjacentElement('afterend', badge);
    }

    // A vanilla world may run with no password at all -- verified against the game: with
    // `-public 0` and no `-password` it reports "Opened Steam server / Game server connected"
    // and serves normally. What it will NOT do is start LISTED without one; `-public 1` with an
    // empty password dies on "Error bad password: The password is too short".
    //
    // So the listing toggle is the thing that depends on the password, not the other way round.
    // Rather than let someone switch listing on and meet that error at boot -- or a save that
    // bounces off the endpoint -- the toggle is disabled and says why while the field is empty.
    // The server re-checks regardless: saveWorldOptions() and createWorld() both refuse
    // listed=1 with no password.
    function syncListedAvailability() {
        const pw = document.getElementById('settingsWorldPassword');
        const listed = document.getElementById('settingsListedToggle');
        const note = document.getElementById('settingsListedBlocked');
        if (!pw || !listed) { return; }

        const blocked = pw.value.trim() === '';
        listed.disabled = blocked;
        if (note) { note.style.display = blocked ? '' : 'none'; }
        const row = document.getElementById('settingsListedRow');
        if (row) { row.style.opacity = blocked ? '0.6' : ''; }

        // Untick on the way out, don't just grey it. A disabled-but-ticked box still posts
        // listed:1 from a form that is telling the operator it cannot be listed, and the save
        // would then fail with an error the UI had already claimed to prevent.
        if (blocked && listed.checked) { listed.checked = false; }
    }

    // Used by the row builder below, which writes the whole <tr> at once rather than patching it.
    function restartPendingBadgeHtml(world) {
        const pending = Array.isArray(world.restartPending) ? world.restartPending : [];
        if (!pending.length) { return ''; }
        const title = escapeAttr('Saved, but not applied until the world restarts: ' + pending.join(', '));
        return `<span class="restart-pending-badge" title="${title}">restart pending</span>`;
    }

    function escapeAttr(s) {
        return String(s).replace(/&/g, '&amp;').replace(/"/g, '&quot;')
                        .replace(/</g, '&lt;').replace(/>/g, '&gt;');
    }

    // The Launch button for a RUNNING world. launchHref is null when the world is a crossplay
    // one whose lobby has not registered a join code yet -- interpolating that straight into
    // the markup produced href="null", a link that silently goes nowhere. Mirrors the PHP
    // render in getWorldsData(); both take their href from getVanillaJoinInfo().
    function launchButtonHtml(world) {
        if (!world.launchHref) {
            return '<span class="action-btn disabled" data-action="launch" title="Crossplay world: waiting for its join code">starting&hellip;</span>';
        }
        return `<a href="${world.launchHref}" class="action-btn success" data-action="launch">Launch</a>`;
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
                ${launchButtonHtml(world)}
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
                ${world.vanilla
                    ? `<span class="action-btn disabled" data-action="edit-mods" title="This is a vanilla world — it runs no mods. Turn off &quot;Vanilla world&quot; in Settings to add mods.">Edit Mods</span>`
                    : `<a href="edit_world.php?world=${encodeURIComponent(world.name)}" class="action-btn primary" data-action="edit-mods">Edit Mods</a>`}
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
            <td><span class="world-name">${world.name}</span>${restartPendingBadgeHtml(world)}</td>
            <td><div class="action-group">${actionsHtml}</div></td>
            <td><div class="action-group">${configHtml}</div></td>
            <td>
                <div class="world-resources" data-world="${world.name}">
                    <div class="world-resource-item">
                        <span class="resource-label">MEM</span>
                        <canvas class="world-mem-chart" width="60" height="20"></canvas>
                        <span class="resource-value world-mem-value">—</span>
                    </div>
                    ${world.vanilla ? '' : `
                    <div class="world-resource-item">
                        <span class="resource-label">HEALTH</span>
                        <div class="world-load-bar" title="Server tick rate (target: 50 TPS). 45-50 = healthy, 35-44 = busy, below 35 = lagging. Low TPS means the server can't keep up with game updates.">
                            <div class="world-load-fill" style="width:0%"></div>
                        </div>
                        <span class="resource-value world-load-value">—</span>
                    </div>`}
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
                launchBtn.outerHTML = launchButtonHtml(world);
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
                // A vanilla world runs no mods, so Edit Mods stays disabled even when the
                // world is stopped. This runs on every poll, so without the check here the
                // PHP-rendered gating would be undone a few seconds after page load.
                editModsBtn.outerHTML = world.vanilla
                    ? `<span class="action-btn disabled" data-action="edit-mods" title="This is a vanilla world — it runs no mods. Turn off &quot;Vanilla world&quot; in Settings to add mods.">Edit Mods</span>`
                    : `<a href="edit_world.php?world=${encodeURIComponent(world.name)}" class="action-btn primary" data-action="edit-mods">Edit Mods</a>`;
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
    // Which list asked for the lookup. It used to always append to Citizens, so the
    // Admins and Banned lists had no lookup at all and a result found while editing
    // them landed in the wrong list.
    let steamIdLookupTarget = 'settingsCitizensTextarea';

    function openSteamIdLookup(targetId) {
        steamIdLookupTarget = targetId || 'settingsCitizensTextarea';
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
                // Show the ACCESS-LIST form (V_...), not the bare SteamID64. What the
                // lookup hands back gets pasted straight into a list Valheim reads, and
                // Valheim 1.0 refuses a bare id with a misleading "Banned".
                document.getElementById('steamIdResultText').textContent = data.accessId || data.steamid;
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
                // Append to the list that opened the lookup, not always Citizens.
                const textarea = document.getElementById(steamIdLookupTarget);
                if (textarea) {
                    const currentValue = textarea.value.trim();
                    textarea.value = currentValue ? currentValue + '\n' + steamId : steamId;
                    // The heading count is derived from the textarea, and setting .value
                    // in script fires no input event -- so it would have gone stale here.
                    textarea.dispatchEvent(new Event('input'));
                }
                closeSteamIdModal();
            });
        }
    }

    // The three sidebar catalogue-sync helpers were removed in 2.43 along with the nav item
    // they drove. The Sync & Maintenance panel owns catalogue state now, and its
    // per-catalogue link is the manual trigger.

    // Settings Modal (includes Citizens)
    let currentSettingsWorld = '';

    // The enforced-but-empty heads-up. Driven by the citizens payload showSettingsModal()
    // already fetches, so it costs no extra request and cannot disagree with what the Access
    // tab is about to render.
    function maybeWarnEmptyAccessList(worldName, citizens) {
        // citizens.public is the CITIZENS access flag: 1 = list OFF (world deliberately open),
        // 0 = list ENFORCED. Not Valheim's -public server browser argument.
        const enforced = String(citizens.public) !== '1';
        const empty = !citizens.citizens || citizens.citizens.trim() === '';
        if (!enforced || !empty) return;

        document.getElementById('emptyAccessListWorld').textContent = worldName;
        document.getElementById('emptyAccessListOverlay').classList.add('show');
    }

    function dismissEmptyAccessList(goToAccess) {
        document.getElementById('emptyAccessListOverlay').classList.remove('show');
        if (!goToAccess) return;
        const btn = document.querySelector('#settingsTabBar .backup-tab[data-tab="accessTab"]');
        if (btn) switchSettingsTab('accessTab', btn);
    }

    function switchSettingsTab(tabId, btn) {
        document.querySelectorAll('#settingsTabBar .backup-tab').forEach(t => t.classList.remove('active'));
        btn.classList.add('active');
        document.querySelectorAll('.settings-tab-pane').forEach(p => p.style.display = 'none');
        document.getElementById(tabId).style.display = 'block';
        // The dialog body is the scroll container now, so a tab switch must reset it --
        // otherwise you arrive at a new tab already scrolled to the middle of it.
        const body = document.getElementById('settingsModalBody');
        if (body) body.scrollTop = 0;
        if (tabId === 'backupsTab' && !document.getElementById('backupsTab').dataset.loaded) {
            loadWorldBackups(currentSettingsWorld);
        }
        if (tabId === 'accessTab') {
            maybeShowAccessNotices();
        }
    }

    // One-time explanation of the Valheim 1.0 id format change, fired the first time the
    // admin opens the Access tab after upgrading. It is deliberately NOT a page-load modal:
    // it only makes sense next to the list it is talking about.
    //
    // PHP seeds this from the settings row. Flipping it in JS before the request completes
    // keeps a fast second click on another world from opening a second copy.
    let accessIdNoticePending = <?php echo $accessIdNoticeShown === 0 ? 'true' : 'false'; ?>;
    let accessSwitchNoticePending = <?php echo $accessSwitchNoticeShown === 0 ? 'true' : 'false'; ?>;

    // Both can be armed on the same upgrade. Show ONE at a time -- stacking two overlays
    // puts them on top of each other and only the last is readable. The switch notice goes
    // first: it is the one where acting on the confusion changes who can join a world.
    function maybeShowAccessNotices() {
        if (accessSwitchNoticePending) {
            accessSwitchNoticePending = false;
            const overlay = document.getElementById('accessSwitchNoticeOverlay');
            if (overlay) { overlay.classList.add('show'); return; }
            // No markup (PHP did not render it) -- fall through rather than swallow the
            // id notice behind a modal that does not exist.
        }
        maybeShowAccessIdNotice();
    }

    function maybeShowAccessIdNotice() {
        if (!accessIdNoticePending) return;
        accessIdNoticePending = false;
        const overlay = document.getElementById('accessIdNoticeOverlay');
        if (overlay) overlay.classList.add('show');
    }

    async function dismissAccessIdNotice() {
        const overlay = document.getElementById('accessIdNoticeOverlay');
        if (overlay) overlay.classList.remove('show');
        try {
            await fetch('adminAPI.php?action=dismissAccessIdNotice', { method: 'POST' });
        } catch (e) {
            // Losing the dismissal is harmless -- the notice reappears next time rather
            // than the admin losing anything.
        }
    }

    async function dismissAccessSwitchNotice() {
        const overlay = document.getElementById('accessSwitchNoticeOverlay');
        if (overlay) overlay.classList.remove('show');
        try {
            await fetch('adminAPI.php?action=dismissAccessSwitchNotice', { method: 'POST' });
        } catch (e) {
            // As above -- worst case it is shown again.
        }
        // If the id notice is also armed, it follows this one now rather than waiting for
        // the admin to leave the tab and come back.
        maybeShowAccessIdNotice();
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
            const [settingsRes, citizensRes, optionsRes, adminsRes, bannedRes] = await Promise.all([
                fetch(`adminAPI.php?action=getWorldSettings&world=${encodeURIComponent(worldName)}`),
                fetch(`adminAPI.php?action=getCitizens&world=${encodeURIComponent(worldName)}`),
                fetch(`adminAPI.php?action=getWorldOptions&world=${encodeURIComponent(worldName)}`),
                fetch(`adminAPI.php?action=getAdmins&world=${encodeURIComponent(worldName)}`),
                fetch(`adminAPI.php?action=getBanned&world=${encodeURIComponent(worldName)}`)
            ]);
            const settings = await settingsRes.json();
            const citizens = await citizensRes.json();
            const options = await optionsRes.json();
            const admins = await adminsRes.json();
            const banned = await bannedRes.json();

            if (settings.success && citizens.success) {
                const hideSeedChecked = settings.hideSeed == 1 ? 'checked' : '';
                const autostartChecked = settings.autostart == 1 ? 'checked' : '';
                const citizensText = citizens.citizens ? citizens.citizens.replace(/ /g, '\n') : '';
                // The switch reads "Use Access List", which is the inverse of the stored
                // worlds.public flag. Invert on the way in, and once more on the way out.
                const useAccessList = citizens.public ? '' : 'checked';
                const adminsText = (admins.admins || '').replace(/ /g, '\n');
                const bannedText = (banned.banned || '').replace(/ /g, '\n');

                // ONE copy, above all three lists, collapsed by default. This used to be
                // stamped over every list; an admin only needs to learn "press F2" once, and
                // a screen reader had to read the whole procedure out three times per visit.
                // Valheim 1.0 matches on the Platform User ID, not the SteamID64, and console
                // players have no SteamID64 at all -- so F2 is the only universal method.
                const idHelpDisclosure = `
                    <details class="pv-disclosure">
                        <summary>How do I find a player's ID?</summary>
                        <div class="pv-disclosure-body">
                            <p>
                                Have them join any world and press <kbd>F2</kbd>. The panel shows their
                                <em>Platform User ID</em> &mdash; paste that into any of the lists below.
                            </p>
                            <p>
                                A plain SteamID64 (17 digits) also works &mdash; PhValheim converts it to the
                                <code>V_</code> form Valheim actually matches. Xbox, PlayStation, Nintendo and
                                GameCenter players have no SteamID64, so for them F2 is the only way.
                            </p>
                        </div>
                    </details>`;
                // Same example in all three hints. A format that is shown but never explained
                // is how people ended up pasting bare SteamID64s that matched nothing.
                const idExample = 'One ID per line. For example <code>V_76561198012345678</code>.';
                const idCount = (t) => String(t || '').split('\n').filter(l => l.trim() !== '').length;
                const isVanilla = options.vanilla == 1;
                const vanillaChecked = isVanilla ? 'checked' : '';
                const crossplayChecked = options.crossplay == 1 ? 'checked' : '';
                const listedChecked = options.listed == 1 ? 'checked' : '';
                const passwordPublicChecked = options.passwordPublic != 0 ? 'checked' : '';
                const worldPassword = options.password || '';
                const launchParams = options.launchParams || '';

                document.getElementById('settingsModalBody').innerHTML = `
                    <!-- General Tab -->
                    <div class="settings-tab-pane" id="settingsTab" style="display:block;">
                    <div class="pv-section">
                        <h6 class="pv-section-title">World Information</h6>
                        <dl class="pv-panel">
                            <div class="pv-kv"><dt>Endpoint</dt><dd><code style="color: var(--accent-primary);">${settings.endpoint}:${settings.port}</code></dd></div>
                            <div class="pv-kv"><dt>MD5 Hash</dt><dd><code style="color: var(--accent-secondary); font-size: 0.72rem;">${settings.md5 || 'N/A'}</code></dd></div>
                            <div class="pv-kv"><dt>Seed</dt><dd><code style="color: var(--accent-primary);">${settings.seed || 'N/A'}</code></dd></div>
                            <div class="pv-kv"><dt>Date Deployed</dt><dd>${settings.dateDeployed || 'N/A'}</dd></div>
                            <div class="pv-kv"><dt>Date Updated</dt><dd>${settings.dateUpdated || 'N/A'}</dd></div>
                        </dl>
                    </div>
                    <div class="pv-section">
                        <h6 class="pv-section-title">Behaviour</h6>
                        <div class="pv-panel">
                            <div class="pv-row">
                                <div class="pv-row-text">
                                    <span class="pv-row-label">Auto-Start</span>
                                    <span class="pv-row-desc">Automatically start this world when the PhValheim server starts.</span>
                                </div>
                                <label class="switch pv-row-control">
                                    <input type="checkbox" ${autostartChecked} onchange="toggleAutostart('${worldName}', this.checked)">
                                    <span class="slider round"></span>
                                </label>
                            </div>
                            <div class="pv-row">
                                <div class="pv-row-text">
                                    <span class="pv-row-label">Hide seed from public UI</span>
                                    <span class="pv-row-desc">When enabled, the world seed is not shown on the public player interface.</span>
                                </div>
                                <label class="switch pv-row-control">
                                    <input type="checkbox" ${hideSeedChecked} onchange="toggleHideSeed('${worldName}', this.checked)">
                                    <span class="slider round"></span>
                                </label>
                            </div>
                        </div>
                        <p class="pv-section-hint">These two apply immediately &mdash; there is no Save for this tab.</p>
                    </div>
                    </div>

                    <!-- Options Tab -->
                    <div class="settings-tab-pane" id="optionsTab" style="display:none;">
                    <div class="pv-section">
                        <h6 class="pv-section-title">Server Type</h6>
                        <div class="pv-panel">
                            <div class="pv-row">
                                <div class="pv-row-text">
                                    <span class="pv-row-label">Vanilla world (no mods)</span>
                                    <span class="pv-row-desc">Runs stock Valheim with zero mods and no BepInEx. Players join with the normal Valheim client. Requires a world update to take effect.</span>
                                </div>
                                <label class="switch pv-row-control">
                                    <input type="checkbox" id="settingsVanillaToggle" ${vanillaChecked} onchange="toggleVanillaFields(this.checked)">
                                    <span class="slider round"></span>
                                </label>
                            </div>
                            <!--
                                VANILLA ONLY for now. Crossplay makes Valheim open a PlayFab
                                server, which has no host:port -- and the PhValheim client
                                reaches a modded world through QuickConnect, whose config is
                                host:port. So a modded crossplay world cannot be joined by the
                                client at all. Hidden rather than disabled: an inert switch
                                invites the question this comment would have to answer.
                                Revisit when the client learns to launch with -joincode.
                            -->
                            <div class="pv-row" id="crossplayRow" style="display: ${isVanilla ? '' : 'none'};">
                                <div class="pv-row-text">
                                    <span class="pv-row-label">Enable crossplay</span>
                                    <span class="pv-row-desc">Let Xbox, PlayStation and Nintendo players join. Vanilla worlds only for now &mdash; the PhValheim client cannot yet connect to a modded crossplay world.</span>
                                </div>
                                <label class="switch pv-row-control">
                                    <input type="checkbox" id="settingsCrossplayToggle" ${crossplayChecked}>
                                    <span class="slider round"></span>
                                </label>
                            </div>
                        </div>
                    </div>
                    <div class="pv-section" id="vanillaOptionsBlock" style="display: ${isVanilla ? 'block' : 'none'};">
                        <h6 class="pv-section-title">Unmodded Server Access</h6>
                        <div class="pv-panel">
                            <div class="pv-row pv-row-stack">
                                <div class="pv-row-text">
                                    <span class="pv-row-label">Server Password</span>
                                    <span class="pv-row-desc">Optional. Leave it blank and anyone who can reach the server may join &mdash; but a world with no password cannot be listed in the server browser. At least 5 characters, and it cannot appear inside the world name.</span>
                                </div>
                                <div class="pv-row-control" style="width: 100%;">
                                    <input type="text" id="settingsWorldPassword" class="form-control pv-input" style="font-family: var(--font-mono);" value="${worldPassword.replace(/"/g, '&quot;')}" placeholder="(no password)" oninput="syncListedAvailability()">
                                </div>
                            </div>
                            <div class="pv-row">
                                <div class="pv-row-text">
                                    <span class="pv-row-label">Show password on public UI</span>
                                    <span class="pv-row-desc">When off, the password row is removed from the world card entirely. Valheim cannot be handed a password at launch, so players will need it from you another way.</span>
                                </div>
                                <label class="switch pv-row-control">
                                    <input type="checkbox" id="settingsPasswordPublicToggle" ${passwordPublicChecked}>
                                    <span class="slider round"></span>
                                </label>
                            </div>
                            <div class="pv-row" id="settingsListedRow">
                                <div class="pv-row-text">
                                    <span class="pv-row-label">List in server browser</span>
                                    <span class="pv-row-desc">Publish to the public Valheim community server list.</span>
                                    <span class="pv-row-desc" id="settingsListedBlocked" style="display:none; color: var(--warning, #fbbf24);">Unavailable without a password &mdash; Valheim refuses to start a listed server that has none (&ldquo;bad password: the password is too short&rdquo;). Set one above to enable this.</span>
                                </div>
                                <label class="switch pv-row-control">
                                    <input type="checkbox" id="settingsListedToggle" ${listedChecked}>
                                    <span class="slider round"></span>
                                </label>
                            </div>
                        </div>
                    </div>
                    <div class="pv-section">
                        <h6 class="pv-section-title">Custom Launch Parameters</h6>
                        <div class="pv-panel" style="padding: 0.9rem;">
                            <label class="pv-field-label" for="settingsLaunchParams">Appended to the Valheim server command line, after everything PhValheim generates.</label>
                            <input type="text" id="settingsLaunchParams" class="form-control pv-input" style="font-family: var(--font-mono);" value="${launchParams.replace(/"/g, '&quot;')}" placeholder="-saveinterval 900">
                            <span class="pv-field-hint">An invalid value stops the world booting, with the reason only in the world log.</span>
                        </div>
                    </div>
                    <div class="pv-actions">
                        <p class="pv-section-hint pv-actions-hint">Changes here need a world restart to take effect.</p>
                        <span class="pv-status" id="settingsOptionsSaveStatus"></span>
                        <button class="action-btn success" onclick="saveWorldOptions()">Save World Options</button>
                    </div>
                    </div>

                    <!-- Access Tab -->
                    <div class="settings-tab-pane" id="accessTab" style="display:none;">
                    <!-- This comes FIRST because it decides whether the Citizens list is
                         consulted at all. Reading the list, then finding out underneath
                         that it is switched off, was backwards. -->
                    <div class="pv-section">
                        <h6 class="pv-section-title">World Access</h6>
                        <div class="pv-panel">
                            <div class="pv-row">
                                <div class="pv-row-text">
                                    <span class="pv-row-label">Use Access List</span>
                                    <span class="pv-row-desc">Only the players on the Citizens list below may join this world. Switch this off to let anyone in &mdash; the list is kept, not cleared, and comes back when you switch it on again.</span>
                                </div>
                                <label class="switch pv-row-control">
                                    <input type="checkbox" id="settingsAccessListToggle" ${useAccessList} onchange="toggleAccessCitizens(this.checked)">
                                    <span class="slider round"></span>
                                </label>
                            </div>
                        </div>
                        <!-- This needs its OWN save. It used to share the Citizens one,
                             which is why that button had to stay on screen after the
                             editor hid -- a lone save button with nothing above it that
                             answered "Citizens saved." -->
                        <div class="pv-actions">
                            <span class="pv-status" id="settingsAccessSaveStatus"></span>
                            <button class="action-btn success" onclick="saveAccessPublic()">Save Access</button>
                        </div>
                    </div>
                    <!-- The one copy of the ID help, above all three lists. -->
                    ${idHelpDisclosure}

                    <!-- The textarea stays in the DOM while hidden so both save paths
                         round-trip the list instead of posting an empty one and wiping it. -->
                    <div class="pv-section" id="settingsCitizensBlock" style="display: ${citizens.public ? 'none' : 'block'};">
                        <h6 class="pv-section-title">Citizens <span class="pv-count" id="citizensCount">${idCount(citizensText)}</span></h6>
                        <p class="pv-field-hint" style="margin-bottom: 0.6rem;">Only these players may join this world. ${idExample}</p>
                        <textarea id="settingsCitizensTextarea" class="form-control pv-list-area" style="min-height: 150px;" oninput="updateAccessCount('citizens')">${citizensText}</textarea>
                        <!-- INSIDE the block: these belong to the Citizens editor and go
                             away with it. A lone save button answering "Citizens saved."
                             for a list that was no longer on screen is what this fixed. -->
                        <div class="pv-list-actions">
                            <button type="button" class="action-btn pv-list-lookup" onclick="openSteamIdLookup('settingsCitizensTextarea')">
                                <svg width="14" height="14" fill="none" stroke="currentColor" viewBox="0 0 24 24" style="margin-right: 0.375rem;">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M21 21l-6-6m2-5a7 7 0 11-14 0 7 7 0 0114 0z"/>
                                </svg>
                                Look Up SteamID
                            </button>
                            <button class="action-btn success" onclick="saveSettingsCitizens()">Save Citizens</button>
                        </div>
                        <div id="settingsCitizensSaveStatus" class="pv-list-status"></div>
                    </div>

                    <div class="pv-section">
                        <h6 class="pv-section-title">Admins <span class="pv-count" id="adminsCount">${idCount(adminsText)}</span></h6>
                        <p class="pv-field-hint" style="margin-bottom: 0.6rem;">These players can use in-game admin commands. ${idExample}<br>Anyone already connected keeps their previous admin status until they reconnect.</p>
                        <textarea id="settingsAdminsTextarea" class="form-control pv-list-area" oninput="updateAccessCount('admins')">${adminsText}</textarea>
                        <div class="pv-list-actions">
                            <button type="button" class="action-btn pv-list-lookup" onclick="openSteamIdLookup('settingsAdminsTextarea')">
                                <svg width="14" height="14" fill="none" stroke="currentColor" viewBox="0 0 24 24" style="margin-right: 0.375rem;">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M21 21l-6-6m2-5a7 7 0 11-14 0 7 7 0 0114 0z"/>
                                </svg>
                                Look Up SteamID
                            </button>
                            <button class="action-btn success" onclick="saveSettingsAdmins()">Save Admins</button>
                        </div>
                        <div id="settingsAdminsSaveStatus" class="pv-list-status"></div>
                    </div>

                    <div class="pv-section">
                        <h6 class="pv-section-title">Banned <span class="pv-count" id="bannedCount">${idCount(bannedText)}</span></h6>
                        <!-- The one list here that locks people out. It gets a warning line
                             rather than a red panel -- tinting the whole section would
                             compete with the danger save button for the same signal. -->
                        <p class="pv-warn">A ban applies <strong>even when the access list is switched off</strong>, and takes effect without a restart.</p>
                        <p class="pv-field-hint" style="margin-bottom: 0.6rem;">These players cannot join this world. ${idExample}</p>
                        <textarea id="settingsBannedTextarea" class="form-control pv-list-area" oninput="updateAccessCount('banned')">${bannedText}</textarea>
                        <div class="pv-list-actions">
                            <button type="button" class="action-btn pv-list-lookup" onclick="openSteamIdLookup('settingsBannedTextarea')">
                                <svg width="14" height="14" fill="none" stroke="currentColor" viewBox="0 0 24 24" style="margin-right: 0.375rem;">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M21 21l-6-6m2-5a7 7 0 11-14 0 7 7 0 0114 0z"/>
                                </svg>
                                Look Up SteamID
                            </button>
                            <button class="action-btn danger" onclick="saveSettingsBanned()">Save Banned</button>
                        </div>
                        <div id="settingsBannedSaveStatus" class="pv-list-status"></div>
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

                // After the body exists, so "Take me to Access" has a tab bar to switch to.
                maybeWarnEmptyAccessList(worldName, citizens);
                // And so the listing toggle reflects the password the world ALREADY has, not
                // just what gets typed afterwards. Opening the modal on a passwordless world
                // has to show the toggle blocked straight away.
                syncListedAvailability();
            } else {
                document.getElementById('settingsModalBody').innerHTML = '<div style="text-align: center; padding: 2rem; color: var(--danger);">Error loading settings</div>';
            }
        } catch (error) {
            document.getElementById('settingsModalBody').innerHTML = '<div style="text-align: center; padding: 2rem; color: var(--danger);">Error loading settings</div>';
        }
    }

    // Password / crossplay / listing only apply to vanilla worlds -- modded worlds are
    // gated by the CITIZENS list and startWorld.sh ignores these for them. Hide rather
    // than disable so nobody sets a password on a modded world and wonders why nothing
    // asks for it.
    function toggleVanillaFields(checked) {
        const block = document.getElementById('vanillaOptionsBlock');
        if (block) block.style.display = checked ? 'block' : 'none';

        // Crossplay lives in the Server Type section rather than vanillaOptionsBlock -- it sits
        // next to the Vanilla switch that controls it -- so it needs toggling by hand.
        const crossplay = document.getElementById('crossplayRow');
        if (crossplay) crossplay.style.display = checked ? '' : 'none';
    }

    // A public world does not consult permittedlist.txt at all, so showing an editor for
    // it invites someone to curate a list that has no effect. Hide the EDITOR only --
    // the textarea stays in the DOM, so saveSettingsCitizens() still posts the existing
    // list back and the ids survive a trip through public and out again.
    // ON means the access list is enforced, so the Citizens editor belongs on screen.
    // That is the opposite of the stored worlds.public flag -- see saveAccessPublic().
    function toggleAccessCitizens(useAccessList) {
        const block = document.getElementById('settingsCitizensBlock');
        if (block) block.style.display = useAccessList ? 'block' : 'none';
    }

    // Keeps the count in a list heading honest while the admin types. Blank lines do
    // not count -- the engine ignores them, so counting them would overstate the list.
    function updateAccessCount(which) {
        const areas = {
            citizens: ['settingsCitizensTextarea', 'citizensCount'],
            admins:   ['settingsAdminsTextarea',   'adminsCount'],
            banned:   ['settingsBannedTextarea',   'bannedCount']
        };
        const pair = areas[which];
        if (!pair) return;
        const ta = document.getElementById(pair[0]);
        const badge = document.getElementById(pair[1]);
        if (!ta || !badge) return;
        badge.textContent = ta.value.split('\n').filter(l => l.trim() !== '').length;
    }

    async function saveWorldOptions() {
        const statusEl = document.getElementById('settingsOptionsSaveStatus');
        statusEl.innerHTML = '<span style="color: var(--text-secondary);">Saving...</span>';

        try {
            const response = await fetch('adminAPI.php?action=saveWorldOptions', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({
                    world: currentSettingsWorld,
                    vanilla: document.getElementById('settingsVanillaToggle').checked ? 1 : 0,
                    password: document.getElementById('settingsWorldPassword').value,
                    crossplay: document.getElementById('settingsCrossplayToggle').checked ? 1 : 0,
                    listed: document.getElementById('settingsListedToggle').checked ? 1 : 0,
                    passwordPublic: document.getElementById('settingsPasswordPublicToggle').checked ? 1 : 0,
                    launchParams: document.getElementById('settingsLaunchParams').value
                })
            });
            const data = await response.json();

            if (data.success) {
                statusEl.innerHTML = `<span style="color: var(--success);">${data.message || 'Saved successfully!'}</span>`;
                // Flipping vanilla changes whether Edit Mods is available on the world
                // row, so the dashboard behind the modal is now stale. Refresh it.
                if (typeof fetchWorldStatus === 'function') { fetchWorldStatus(); }
                setTimeout(() => { statusEl.innerHTML = ''; }, 6000);
            } else {
                statusEl.innerHTML = `<span style="color: var(--danger);">Error: ${data.error || 'Failed to save'}</span>`;
            }
        } catch (error) {
            statusEl.innerHTML = '<span style="color: var(--danger);">Error saving world options</span>';
        }
    }

    async function saveSettingsBanned() {
        const statusEl = document.getElementById('settingsBannedSaveStatus');
        statusEl.innerHTML = '<span style="color: var(--text-secondary);">Saving...</span>';

        try {
            const response = await fetch('adminAPI.php?action=saveBanned', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({
                    world: currentSettingsWorld,
                    banned: document.getElementById('settingsBannedTextarea').value
                })
            });
            const data = await response.json();

            if (data.success) {
                statusEl.innerHTML = `<span style="color: var(--success);">${data.message || 'Saved successfully!'}</span>`;
                setTimeout(() => { statusEl.innerHTML = ''; }, 4000);
            } else {
                statusEl.innerHTML = `<span style="color: var(--danger);">Error: ${data.error || 'Failed to save'}</span>`;
            }
        } catch (error) {
            statusEl.innerHTML = '<span style="color: var(--danger);">Error saving banned list</span>';
        }
    }

    async function saveSettingsAdmins() {
        const statusEl = document.getElementById('settingsAdminsSaveStatus');
        statusEl.innerHTML = '<span style="color: var(--text-secondary);">Saving...</span>';

        try {
            const response = await fetch('adminAPI.php?action=saveAdmins', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({
                    world: currentSettingsWorld,
                    admins: document.getElementById('settingsAdminsTextarea').value
                })
            });
            const data = await response.json();

            if (data.success) {
                statusEl.innerHTML = `<span style="color: var(--success);">${data.message || 'Saved successfully!'}</span>`;
                setTimeout(() => { statusEl.innerHTML = ''; }, 4000);
            } else {
                statusEl.innerHTML = `<span style="color: var(--danger);">Error: ${data.error || 'Failed to save'}</span>`;
            }
        } catch (error) {
            statusEl.innerHTML = '<span style="color: var(--danger);">Error saving admins</span>';
        }
    }

    async function saveSettingsCitizens() {
        const citizens = document.getElementById('settingsCitizensTextarea').value;
        // Switch ON = list enforced = NOT public. The column stores the inverse.
        const isPublic = document.getElementById('settingsAccessListToggle').checked ? 0 : 1;
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
                statusEl.innerHTML = `<span style="color: var(--success);">${data.message || 'Saved successfully!'}</span>`;
                setTimeout(() => { statusEl.innerHTML = ''; }, 4000);
            } else {
                statusEl.innerHTML = `<span style="color: var(--danger);">Error: ${data.error || 'Failed to save'}</span>`;
            }
        } catch (error) {
            statusEl.innerHTML = '<span style="color: var(--danger);">Error saving citizens</span>';
        }
    }

    // Saves the access-list flag on its own. It posts the citizens textarea UNCHANGED
    // alongside it -- saveCitizens writes both columns, so sending an empty list here
    // would wipe the ids the moment someone switched the list off.
    async function saveAccessPublic() {
        const ta = document.getElementById('settingsCitizensTextarea');
        // "Use Access List" ON means the list is enforced, which is worlds.public = 0.
        const isPublic = document.getElementById('settingsAccessListToggle').checked ? 0 : 1;
        const statusEl = document.getElementById('settingsAccessSaveStatus');

        statusEl.innerHTML = '<span style="color: var(--text-secondary);">Saving...</span>';

        try {
            const response = await fetch('adminAPI.php?action=saveCitizens', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({
                    world: currentSettingsWorld,
                    citizens: ta ? ta.value : '',
                    public: isPublic
                })
            });
            const data = await response.json();

            if (data.success) {
                statusEl.innerHTML = `<span style="color: var(--success);">${isPublic ? 'Access list off &mdash; anyone may join.' : 'Access list on &mdash; Citizens only.'}</span>`;
                setTimeout(() => { statusEl.innerHTML = ''; }, 4000);
            } else {
                statusEl.innerHTML = `<span style="color: var(--danger);">Error: ${data.error || 'Failed to save'}</span>`;
            }
        } catch (error) {
            statusEl.innerHTML = '<span style="color: var(--danger);">Error saving world access</span>';
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
                // Tolerant of a missing element: the three Thunderstore rows this used to
                // write into were replaced by #modSyncPanel in 2.43, and an unguarded
                // .textContent on null throws -- which would take the backup and
                // log-rotation rows down with it, since they are set after.
                const setText = (id, val) => {
                    const el = document.getElementById(id);
                    if (el) el.textContent = val;
                };
                setText('syncBackupTime', data.worldBackup.time);
                setText('syncBackupStatus', data.worldBackup.status);
                setText('syncLogRotateTime', data.logRotate.time);
                setText('syncLogRotateStatus', data.logRotate.status);
            }
        } catch (error) {
            console.error('Failed to fetch sync status:', error);
        }
    }

    // ---------------------------------------------------------------- mod catalogue sync
    //
    // Two cadences on purpose. An idle catalogue changes at most hourly, so polling it
    // every two seconds would be the same defeated-throttle request storm that made
    // /api/state cost 474ms in 2.36. While a sync is actually RUNNING the phase and
    // counts move, so the panel tightens to 2s and drops back the moment it finishes.
    const MODSYNC_IDLE_POLL = 30000;
    const MODSYNC_ACTIVE_POLL = 2000;
    let modSyncTimer = null;
    let modSyncPollMs = MODSYNC_IDLE_POLL;

    function fmtBytesMS(n) {
        n = parseInt(n, 10) || 0;
        if (!n) return '0 B';
        if (n < 1024) return n + ' B';
        if (n < 1048576) return (n / 1024).toFixed(0) + ' KB';
        if (n < 1073741824) return (n / 1048576).toFixed(1) + ' MB';
        return (n / 1073741824).toFixed(2) + ' GB';
    }

    function fmtDuration(ms) {
        ms = parseInt(ms, 10) || 0;
        if (ms < 1000) return ms + ' ms';
        if (ms < 60000) return (ms / 1000).toFixed(1) + ' s';
        return Math.floor(ms / 60000) + 'm ' + Math.round((ms % 60000) / 1000) + 's';
    }

    // A delta is only meaningful next to the run it is being compared with, so the
    // previous run's number is shown rather than just an arrow.
    function deltaSpan(now, before) {
        const a = parseInt(now, 10) || 0, b = parseInt(before, 10) || 0;
        if (a === b) return '';
        const d = a - b;
        const cls = d > 0 ? 'ms-up' : 'ms-down';
        return ` <span class="${cls}">${d > 0 ? '+' : ''}${d}</span>`;
    }

    const MODSYNC_STATUS_CLASS = {
        ok: 'ms-ok', unchanged: 'ms-idle', running: 'ms-run',
        error: 'ms-err', stopped: 'ms-warn', stale: 'ms-warn', disabled: 'ms-idle'
    };

    function renderModSyncSource(src, def, stats, runs, cache) {
        const r = runs || {};
        const live = r.running;
        const last = r.last;
        const prev = r.previous;
        const st = stats || { mods: 0, versions: 0 };

        const pill = `<span class="src-pill src-${def.colour}">${def.label}</span>`;

        if (!def.enabled) {
            return `<div class="ms-src ms-src-off">
                <div class="ms-head">${pill}
                    <span class="ms-state ms-idle">disabled</span></div>
                <div class="ms-note">Not synced. Its mods are hidden from the picker;
                    worlds that already selected them keep them.</div>
            </div>`;
        }

        // Running: phase + progress. The bar is driven by the engine's own phase_pct
        // rather than a spinner, so a stuck phase is visible as a stuck number.
        if (live) {
            const pct = parseInt(live.phase_pct, 10) || 0;
            return `<div class="ms-src ms-src-live">
                <div class="ms-head">${pill}
                    <span class="ms-state ms-run">${live.phase || 'running'}</span>
                    <span class="ms-trigger">${live.trigger_kind || ''}</span></div>
                <div class="ms-bar"><div class="ms-bar-fill" style="width:${pct}%"></div></div>
                <div class="ms-grid">
                    <span>packages</span><b>${(+live.pkgs_seen || 0).toLocaleString()}</b>
                    <span>versions</span><b>${(+live.vers_seen || 0).toLocaleString()}</b>
                    <span>fetched</span><b>${fmtBytesMS(live.bytes_fetched)}</b>
                    <span>started</span><b>${live.started || ''}</b>
                </div>
            </div>`;
        }

        const cls = MODSYNC_STATUS_CLASS[last ? last.status : 'idle'] || 'ms-idle';
        const label = last ? last.status : 'never run';

        let body;
        if (!last) {
            body = `<div class="ms-note">No sync has run yet. Use
                    <b>Sync Catalogues</b> to fetch this catalogue now.</div>`;
        } else if (last.status === 'error') {
            // The error text is shown, not hidden behind a log file. A sync that failed
            // silently is a catalogue quietly going stale.
            body = `<div class="ms-err-box">${(last.error || 'unknown error')
                        .replace(/[<>&]/g, c => ({'<':'&lt;','>':'&gt;','&':'&amp;'}[c]))}</div>`;
        } else if (last.status === 'unchanged') {
            body = `<div class="ms-note">Catalogue unchanged
                    (HTTP ${last.http_status || '—'}, ${fmtDuration(last.duration_ms)}).
                    Nothing needed rewriting.</div>`;
        } else {
            body = `<div class="ms-grid">
                <span>mods</span><b>+${+last.mods_added || 0} ~${+last.mods_updated || 0} −${+last.mods_removed || 0}${prev ? deltaSpan(last.mods_added, prev.mods_added) : ''}</b>
                <span>versions</span><b>+${+last.vers_added || 0} ~${+last.vers_updated || 0} −${+last.vers_removed || 0}</b>
                <span>fetched</span><b>${fmtBytesMS(last.bytes_fetched)}</b>
                <span>took</span><b>${fmtDuration(last.duration_ms)}${prev ? ' <span class="ms-prev">was ' + fmtDuration(prev.duration_ms) + '</span>' : ''}</b>
                <span>deps</span><b>${(+last.deps_resolved || 0).toLocaleString()} resolved${(+last.deps_unresolved) ? ', <span class="ms-warn">' + last.deps_unresolved + ' unresolved</span>' : ''}</b>
            </div>`;
        }

        return `<div class="ms-src">
            <div class="ms-head">${pill}
                <span class="ms-state ${cls}">${label}</span>
                <span class="ms-trigger">${last ? (last.trigger_kind || '') : ''}</span>
                <a class="ms-sync-one" href="?manual_mod_sync=${src}"
                   title="Force a sync of this catalogue now">sync</a></div>
            <div class="ms-totals">${st.mods.toLocaleString()} mods ·
                 ${st.versions.toLocaleString()} versions on disk</div>
            <div class="ms-when">${last ? ('finished ' + (last.finished || '')) : ''}</div>
            ${body}
            ${timingsHtml(last)}
            ${logPaneHtml(src)}
        </div>`;
    }

    // Where a slow sync spent its time. From the run's own phase_timings, so it does not
    // have to be inferred by subtracting log timestamps.
    function timingsHtml(last) {
        if (!last || !last.phase_timings) return '';
        let t;
        try { t = JSON.parse(last.phase_timings); } catch (e) { return ''; }
        const parts = Object.entries(t).filter(([, v]) => v >= 0.01);
        if (!parts.length) return '';
        const total = parts.reduce((a, [, v]) => a + v, 0) || 1;
        return '<div class="ms-timings">'
            + parts.map(([k, v]) =>
                `<span class="ms-timing" title="${k}: ${v.toFixed(2)}s">`
                + `<i style="width:${Math.max(2, (v / total) * 100).toFixed(1)}%"></i>`
                + `${k} ${v.toFixed(2)}s</span>`).join('')
            + '</div>';
    }

    // The log pane is rendered EMPTY here and filled by pollModSyncLog(). renderModSyncSource()
    // runs on every status refresh, so building the lines here would discard the operator's
    // scroll position and their show-detail choice every couple of seconds.
    function logPaneHtml(src) {
        const st = modSyncLogState[src] || (modSyncLogState[src] = {
            open: false, afterId: 0, runId: null, detail: true, lines: []
        });
        return `<div class="ms-log-wrap">
            <div class="ms-log-bar">
                <button type="button" class="ms-log-toggle" data-source="${src}">
                    <span class="ms-log-caret">${st.open ? '▾' : '▸'}</span> sync log
                    <span class="ms-log-count" id="ms-log-count-${src}"></span>
                </button>
                <label class="ms-log-detail" style="${st.open ? '' : 'display:none'}">
                    <input type="checkbox" class="ms-log-detail-cb" data-source="${src}"
                           ${st.detail ? 'checked' : ''}> per-mod detail
                </label>
            </div>
            <pre class="ms-log" id="ms-log-${src}" style="${st.open ? '' : 'display:none'}"></pre>
        </div>`;
    }

    const modSyncLogState = {};

    function renderModSyncLogLines(src) {
        const st = modSyncLogState[src];
        const el = document.getElementById('ms-log-' + src);
        if (!st || !el) return;
        const shown = st.detail ? st.lines : st.lines.filter(l => !l.detail);
        // Pinned to the bottom unless the operator has scrolled up to read something.
        const atBottom = el.scrollHeight - el.scrollTop - el.clientHeight < 24;
        el.innerHTML = shown.length
            ? shown.map(l =>
                `<span class="msl msl-${l.level}${l.detail ? ' msl-detail' : ''}">`
                + `<span class="msl-t">${l.at}</span>`
                + escapeHtmlMs(l.message) + '</span>').join('\n')
            : '<span class="msl msl-info">no log lines for this run</span>';
        if (atBottom) el.scrollTop = el.scrollHeight;
        const c = document.getElementById('ms-log-count-' + src);
        if (c) c.textContent = shown.length ? `(${shown.length})` : '';
    }

    function escapeHtmlMs(s) {
        return String(s).replace(/[<>&]/g, c => ({ '<': '&lt;', '>': '&gt;', '&': '&amp;' }[c]));
    }

    async function pollModSyncLog(src) {
        const st = modSyncLogState[src];
        if (!st || !st.open) return false;
        try {
            // Deliberately WITHOUT runId: the server decides which run the panel should be
            // showing (the one in flight, else the newest finished one). Pinning the request
            // to the run we already know -- which the first version of this did -- means the
            // pane latches onto whatever run it saw first and never follows a later sync. The
            // symptom is a log that looks fine and quietly describes the wrong run.
            const r = await fetch('adminAPI.php?action=getModSyncLog&source='
                + encodeURIComponent(src) + '&afterId=' + (st.runId ? st.afterId : 0));
            const d = await r.json();
            if (!d.success) return false;

            if (st.runId !== d.runId) {
                // A different run: start its log from scratch. Appending would splice two
                // syncs into one impossibly long one.
                st.runId = d.runId;
                st.lines = [];
                st.afterId = 0;
                const again = await fetch('adminAPI.php?action=getModSyncLog&source='
                    + encodeURIComponent(src) + '&afterId=0&runId=' + d.runId);
                const d2 = await again.json();
                if (d2.success) { st.lines = d2.lines; st.afterId = d2.lastId; }
            } else if (d.lines.length) {
                st.lines = st.lines.concat(d.lines);
                st.afterId = d.lastId;
            }
            renderModSyncLogLines(src);
            return !!d.running;
        } catch (e) {
            console.error('mod sync log', e);
            return false;
        }
    }

    // Delegated: the panel is re-rendered on every status refresh, so handlers bound to the
    // buttons themselves would be lost on the first repaint.
    document.addEventListener('click', function (ev) {
        const btn = ev.target.closest && ev.target.closest('.ms-log-toggle');
        if (!btn) return;
        ev.preventDefault();
        const src = btn.dataset.source;
        const st = modSyncLogState[src] || (modSyncLogState[src] = {
            open: false, afterId: 0, runId: null, detail: true, lines: []
        });
        st.open = !st.open;
        const pane = document.getElementById('ms-log-' + src);
        const caret = btn.querySelector('.ms-log-caret');
        const detailLbl = btn.parentElement.querySelector('.ms-log-detail');
        if (pane) pane.style.display = st.open ? '' : 'none';
        if (caret) caret.textContent = st.open ? '▾' : '▸';
        if (detailLbl) detailLbl.style.display = st.open ? '' : 'none';
        if (st.open) pollModSyncLog(src).then(() => renderModSyncLogLines(src));
    });

    document.addEventListener('change', function (ev) {
        if (!ev.target.classList || !ev.target.classList.contains('ms-log-detail-cb')) return;
        const src = ev.target.dataset.source;
        if (modSyncLogState[src]) {
            modSyncLogState[src].detail = ev.target.checked;
            renderModSyncLogLines(src);
        }
    });

    async function refreshModSyncPanel() {
        const el = document.getElementById('modSyncPanel');
        if (!el) return;
        let anyRunning = false;
        try {
            const res = await fetch('adminAPI.php?action=getModSyncStatus');
            const d = await res.json();
            if (!d.success) return;

            let html = '';
            (d.sources || []).forEach(def => {
                const runs = (d.runs || {})[def.key] || {};
                if (runs.running) anyRunning = true;
                html += renderModSyncSource(def.key, def,
                    (d.stats || {})[def.key], runs, d.cache);
            });

            const c = d.cache || { files: 0, bytes: 0 };
            html += `<div class="ms-cache">Local mod cache:
                <b>${c.files}</b> archive${c.files === 1 ? '' : 's'},
                <b>${fmtBytesMS(c.bytes)}</b>
                <span class="ms-prev">${c.dir || ''}</span></div>`;
            el.innerHTML = html;

            // Pull each open log pane forward in the same cycle. Only open panes are
            // fetched, so a collapsed one costs nothing.
            (d.sources || []).forEach(def => {
                const st = modSyncLogState[def.key];
                if (st && st.open) pollModSyncLog(def.key);
            });
        } catch (e) {
            // Leave the last good render in place; blanking the panel on a transient
            // fetch failure reads as "the sync data is gone".
            console.error('mod sync status', e);
        }

        const want = anyRunning ? MODSYNC_ACTIVE_POLL : MODSYNC_IDLE_POLL;
        if (want !== modSyncPollMs || modSyncTimer === null) {
            modSyncPollMs = want;
            if (modSyncTimer) clearInterval(modSyncTimer);
            modSyncTimer = setInterval(refreshModSyncPanel, modSyncPollMs);
        }
    }

    // Add sync status polling to the main polling loop
    const SYNC_POLL_INTERVAL = 30000; // 30 seconds for sync status

    // Fetch sync status on page load and then every 30 seconds
    document.addEventListener('DOMContentLoaded', function() {
        fetchSyncStatus();
        refreshModSyncPanel();
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

        // Which worlds this poll actually reported on. The API only returns worlds that
        // are running, so anything MISSING here has stopped -- and nothing else will ever
        // tell us that. Without the sweep below, a world that goes down (or into an
        // update) keeps its last drawn memory bar on screen indefinitely.
        const reported = new Set();

        worldStats.forEach(stat => {
            const worldName = stat.name;
            reported.add(worldName);
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

        clearUnreportedWorlds(reported);
    }

    // Blank the resource readouts for every world the last poll did not report on.
    // Shared by the stats and health pollers so a world cannot be cleared by one and
    // left stale by the other.
    function clearUnreportedWorlds(reported) {
        document.querySelectorAll('.world-resources[data-world]').forEach(container => {
            const worldName = container.dataset.world;
            if (reported.has(worldName)) return;

            const charts = worldCharts[worldName];
            if (charts && charts.mem) {
                charts.memData.length = 0;
                charts.mem.data.datasets[0].data = [];
                charts.mem.update('none');
            }
            const memValue = container.querySelector('.world-mem-value');
            if (memValue) memValue.textContent = '—';
        });
    }

    // Update world tick health indicators
    function updateWorldHealth(healthData) {
        if (!healthData) return;

        // Same problem as the memory chart: getWorldHealth only reports worlds that are
        // running AND whose tick_stats.json is under 30s old. A world that stops -- or
        // whose plugin stops writing -- simply drops out of the payload, so its last
        // tick reading sat there looking live. Blank those first, then draw the rest.
        const reported = new Set(Object.keys(healthData));
        document.querySelectorAll('.world-resources[data-world]').forEach(container => {
            if (reported.has(container.dataset.world)) return;
            const fill = container.querySelector('.world-load-fill');
            const value = container.querySelector('.world-load-value');
            if (fill) { fill.style.width = '0%'; fill.style.backgroundColor = ''; }
            if (value) value.textContent = '—';
        });

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

    // Only closes the modal once the server has recorded the dismissal -- closing first
    // would look dismissed but reappear on the next page load.
    async function dismissWhatsNew() {
        try {
            const res = await fetch('adminAPI.php?action=dismissWhatsNew', { method: 'POST' });
            const data = await res.json();
            if (!data.success) { console.error('Failed to dismiss release notes:', data.error); return; }
            document.getElementById('whatsNewOverlay').classList.remove('show');
        } catch(e) { console.error('Failed to dismiss release notes:', e); }
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
                    <div style="margin-bottom:0.75rem;font-size:0.75rem;color:var(--text-muted);line-height:1.5;">
                        Add as many providers as you like &mdash; cloud or self-hosted, several of the same kind.
                        Models are discovered live from each endpoint, so PhValheim never holds a built-in model
                        list that can go stale.
                    </div>
                    <div id="ss-aiProviderList" class="ai-prov-list"></div>
                    <button type="button" class="action-btn" style="margin-top:0.6rem;" onclick="aiWizOpen()">+ Add AI provider</button>
                </div>

                <!-- The "Advanced" section held Thunderstore Local Sync and Thunderstore
                     Chunk Size. Both described the pre-2.43 sync: chunk size set how many
                     parallel bash worker threads to fork, and local sync toggled the
                     12-hourly job. The sync is one process with nothing to tune now, and
                     enabling/disabling a catalogue lives under Mod Catalogues above.
                     Leaving a setting on screen that no longer changes anything is worse
                     than not having it. -->

                <div style="margin-bottom: 1.5rem;">
                    ${sectionHead('Mod Catalogues', 'var(--accent-secondary)')}
                    <div style="margin-bottom:0.6rem;font-size:0.72rem;color:var(--text-muted);line-height:1.5;">
                        Mods are read from these catalogues. <b>Neither needs an API key</b> — both
                        are public and unauthenticated. Fill a key in only if a source starts
                        requiring one; it is then sent as a bearer token.
                    </div>
                    <div class="row mb-2">
                        <div class="col-md-6">
                            <label style="font-size:0.8rem;color:var(--info)">Thunderstore</label>
                            <select class="form-control form-control-sm" id="ss-thunderstoreEnabled">
                                <option value="1" ${s.thunderstoreEnabled == 1 ? 'selected' : ''}>Enabled</option>
                                <option value="0" ${s.thunderstoreEnabled == 0 ? 'selected' : ''}>Disabled</option>
                            </select>
                            <div style="margin-top:0.3rem;">
                                ${keyField('ss-thunderstoreApiKey', s.thunderstoreApiKey)}
                            </div>
                            <div style="margin-top:0.25rem;font-size:0.68rem;color:var(--text-muted)">
                                API key — optional
                            </div>
                        </div>
                        <div class="col-md-6">
                            <label style="font-size:0.8rem;color:var(--accent-secondary)">Hexium</label>
                            <select class="form-control form-control-sm" id="ss-hexiumEnabled">
                                <option value="1" ${s.hexiumEnabled == 1 ? 'selected' : ''}>Enabled</option>
                                <option value="0" ${s.hexiumEnabled == 0 ? 'selected' : ''}>Disabled</option>
                            </select>
                            <div style="margin-top:0.3rem;">
                                ${keyField('ss-hexiumApiKey', s.hexiumApiKey)}
                            </div>
                            <div style="margin-top:0.25rem;font-size:0.68rem;color:var(--text-muted)">
                                API key — optional
                            </div>
                        </div>
                    </div>
                    <div class="row mb-2">
                        <div class="col-md-6">
                            <label style="font-size:0.8rem;color:orchid">Catalogue Sync Interval (hours)</label>
                            <input type="number" min="1" max="168" class="form-control form-control-sm" id="ss-modSyncIntervalHours" value="${s.modSyncIntervalHours}" style="font-family:var(--font-mono)" ${tip('How often the catalogues are checked for changes. A check that finds nothing changed costs about two seconds, so a short interval is cheap. Default: 6.')}>
                        </div>
                    </div>
                    <div style="font-size:0.7rem;color:var(--text-muted);line-height:1.5;">
                        Disabling a catalogue stops syncing it and hides its mods from the picker.
                        Mods already selected by a world are <b>not</b> removed.
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
            // The AI section is rendered from the live provider registry rather than
            // from the settings payload -- providers are rows in ai_providers now, not
            // four fixed columns in `settings`.
            aiRenderProviderList();
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
            analyticsEnabled: parseInt(document.getElementById('ss-analyticsEnabled').value),
            thunderstoreApiKey: document.getElementById('ss-thunderstoreApiKey').value.trim(),
            hexiumApiKey: document.getElementById('ss-hexiumApiKey').value.trim(),
            thunderstoreEnabled: parseInt(document.getElementById('ss-thunderstoreEnabled').value),
            hexiumEnabled: parseInt(document.getElementById('ss-hexiumEnabled').value),
            modSyncIntervalHours: parseInt(document.getElementById('ss-modSyncIntervalHours').value) || 6,
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

    <!-- ================================================================================
         AI Helper (2.45)

         The 2.44 panel had a "Context" dropdown whose whole job was choosing WHICH single
         log got pasted into the system prompt. That choice is gone: the model has tools
         and fetches what it needs, so the only context that remains is "which world am I
         asking about", which is a hint rather than a hard scope.
         ================================================================================ -->
    <div class="ai-panel" id="aiPanel">
        <div class="ai-panel-header">
            <span class="ai-panel-title">
                <!-- Hugin. Rendered server-side so he is present before any JS runs, and
                     restyled by class as the stream progresses -- the header copy is the
                     busy tell that stays visible when the transcript is scrolled away.
                     It is also the ORIGINAL that aiHuginNode() clones, so there is exactly
                     one drawing of this bird in the product. -->
                <?php echo huginSvg('idle ai-panel-hugin', 24); ?>
                Hugin <span style="opacity:.55;font-weight:400;">· AI Helper</span>
            </span>
            <button class="ai-panel-close" onclick="toggleAiPanel()" title="Close">&times;</button>
            <div class="ai-panel-selectors">
                <div class="ai-selector-group">
                    <label class="ai-selector-label">Model</label>
                    <!-- The <select> stays and remains the single source of truth: every
                         reader (aiCurrentSelection, the cookie, aiRenderModelSelect) keeps
                         working untouched. The combobox in front of it is a VIEW of its
                         options. A native select is unusable at 130 models -- OpenAI alone
                         returns that many -- because it has no way to type past the first
                         letter. -->
                    <select id="aiModelSelect" class="ai-context-select" onchange="aiOnModelChange()" style="display:none;"></select>
                    <div class="ai-modelpick" id="aiModelPick">
                        <button type="button" class="ai-modelpick-btn" id="aiModelBtn" onclick="aiModelPickToggle(event)">
                            <span id="aiModelBtnLabel">No provider configured</span>
                            <span class="ai-modelpick-caret">&#9662;</span>
                        </button>
                        <div class="ai-modelpick-pop" id="aiModelPop">
                            <input type="text" id="aiModelFilter" class="form-control form-control-sm"
                                   placeholder="Search models&hellip;" autocomplete="off"
                                   oninput="aiModelPickRender()" onkeydown="aiModelPickKey(event)">
                            <div class="ai-modelpick-list" id="aiModelList"></div>
                        </div>
                    </div>
                </div>
                <div class="ai-selector-group">
                    <label class="ai-selector-label">About</label>
                    <select id="aiWorldSelect" class="ai-context-select" onchange="aiOnWorldChange()">
                        <option value="">Whole server</option>
                    </select>
                </div>
            </div>
            <div class="ai-panel-toolbar">
                <button class="ai-chip-btn" onclick="aiLoadDiagnostics(true)" title="Re-run the deterministic scan">&#8635; Rescan</button>
                <button class="ai-chip-btn" onclick="aiRefreshAllModels()" title="Re-query every provider for its current model list">&#10227; Refresh models</button>
                <button class="ai-chip-btn" onclick="aiNewChat()" title="Clear the conversation">&#9998; New chat</button>
                <button class="ai-chip-btn" onclick="aiWizOpen()" title="Add an AI provider">+ Provider</button>
            </div>
        </div>

        <div class="ai-panel-body">
            <div class="ai-diagnostics" id="aiDiagnostics"></div>
            <div class="ai-panel-messages" id="aiMessages"></div>
        </div>

        <div class="ai-panel-input">
            <div class="ai-quick-prompts" id="aiQuickPrompts"></div>
            <div class="ai-composer">
                <textarea id="aiInput" placeholder="Ask about a world, a log, mods, backups&hellip;" rows="2"></textarea>
                <button onclick="aiSend()" id="aiSendBtn" class="ai-send-btn" title="Send (Enter)">
                    <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                        <line x1="22" y1="2" x2="11" y2="13"/><polygon points="22 2 15 22 11 13 2 9 22 2"/>
                    </svg>
                </button>
            </div>
            <div class="ai-footer" id="aiFooter"></div>
        </div>
    </div>
    <div class="ai-panel-overlay" id="aiOverlay" onclick="toggleAiPanel()"></div>

    <!-- Add-AI-provider wizard.
         z-index 1060 deliberately: this opens from the Server Settings modal, and the
         shared .mods-modal-overlay sits at the same level as that one. A stacked overlay
         left at the base z-index renders behind its own dim layer and cannot be dismissed. -->
    <div class="mods-modal-overlay" id="aiWizardOverlay" style="z-index:1060;" onclick="aiWizClose(event)">
        <div class="mods-modal" style="max-width:640px;" onclick="event.stopPropagation()">
            <div class="mods-modal-header">
                <h3 id="aiWizTitle">Add an AI provider</h3>
                <button class="mods-modal-close" onclick="aiWizClose()">&times;</button>
            </div>
            <div class="ai-wiz-steps" id="aiWizSteps"></div>
            <div class="mods-modal-body" id="aiWizBody" style="min-height:260px;"></div>
            <div class="mods-modal-footer" style="display:flex;justify-content:space-between;gap:0.5rem;">
                <button class="action-btn" id="aiWizBack" onclick="aiWizGo(-1)">Back</button>
                <div id="aiWizStatus" style="flex:1;font-size:0.8rem;align-self:center;"></div>
                <button class="action-btn success" id="aiWizNext" onclick="aiWizGo(1)">Next</button>
            </div>
        </div>
    </div>

    <script>
    /* ================================================================================
     * AI Helper client
     *
     * Talks to three endpoints:
     *   adminAPI.php?action=getAiProviders   registry + live-discovered models
     *   adminAPI.php?action=aiDiagnostics    the deterministic scan (no model involved)
     *   aiStream.php                         SSE chat
     *
     * The model list is never hardcoded here either. If a provider stops offering a
     * model, it stops appearing - that is the entire fix for issue #83, and it only
     * holds if the client also refuses to keep its own copy.
     * ================================================================================ */

    let aiProviders   = [];     // [{id, label, kind, model, models:[...], ...}]
    let aiKinds       = {};
    let aiHistory     = [];     // [{role, content}] - plain turns only
    let aiKnownWorlds = [];
    let aiBusy        = false;
    const AI_MAX_HISTORY = 20;

    /* ---- tiny Markdown renderer -------------------------------------------------
     * Deliberately small and escape-first: every model output is untrusted text, and
     * 2.44 asked the model for raw HTML and injected it. Fenced code is extracted
     * before anything else so that markup inside a code block is shown, not applied. */
    function aiEsc(s) {
        return String(s).replace(/&/g, '&amp;').replace(/</g, '&lt;')
                        .replace(/>/g, '&gt;').replace(/"/g, '&quot;');
    }
    function aiMd(src) {
        const blocks = [];
        let s = String(src || '').replace(/\r\n/g, '\n');

        s = s.replace(/```([a-zA-Z0-9_+-]*)\n([\s\S]*?)```/g, function (_, lang, code) {
            blocks.push('<pre><code class="lang-' + aiEsc(lang) + '">' + aiEsc(code.replace(/\n$/, '')) + '</code></pre>');
            return '@@AIBLOCK' + (blocks.length - 1) + '@@';
        });

        s = aiEsc(s);
        s = s.replace(/`([^`\n]+)`/g, function (_, c) { return '<code>' + c + '</code>'; });
        s = s.replace(/\*\*([^*\n]+)\*\*/g, '<strong>$1</strong>');
        s = s.replace(/(^|[^*])\*([^*\n]+)\*/g, '$1<em>$2</em>');
        s = s.replace(/\[([^\]]+)\]\((https?:\/\/[^)\s]+)\)/g, '<a href="$2" target="_blank" rel="noopener">$1</a>');
        s = s.replace(/^#{1,6}\s+(.+)$/gm, '<strong class="ai-h">$1</strong>');

        const out = [];
        let list = null;
        s.split('\n').forEach(function (line) {
            const ul = line.match(/^\s*[-*]\s+(.*)$/);
            const ol = line.match(/^\s*\d+\.\s+(.*)$/);
            const want = ul ? 'ul' : (ol ? 'ol' : null);
            if (want) {
                if (list !== want) { if (list) out.push('</' + list + '>'); out.push('<' + want + '>'); list = want; }
                out.push('<li>' + (ul ? ul[1] : ol[1]) + '</li>');
            } else {
                if (list) { out.push('</' + list + '>'); list = null; }
                out.push(line.trim() === '' ? '' : '<div>' + line + '</div>');
            }
        });
        if (list) out.push('</' + list + '>');

        return out.join('\n').replace(/@@AIBLOCK(\d+)@@/g, function (_, i) { return blocks[+i]; });
    }

    /* ---- registry ---------------------------------------------------------------- */

    async function aiLoadProviders(refreshId, announce) {
        try {
            const url = 'adminAPI.php?action=getAiProviders' + (refreshId ? '&refresh=' + refreshId : '');
            const res = await fetch(url);
            const data = await res.json();
            if (!data.success) return;
            aiProviders = data.providers || [];
            aiKinds     = data.kinds || {};
            aiRenderModelSelect();
            aiRenderQuickPrompts();
            if (announce) aiFoot('Model lists refreshed.');
        } catch (e) {
            console.error('AI provider load failed', e);
        }
    }

    /** Refresh every provider's catalogue, not just one. */
    async function aiRefreshAllModels() {
        aiFoot('Refreshing model lists...');
        for (const p of aiProviders) await aiLoadProviders(p.id, false);
        aiRenderProviderList();
        aiFoot('Model lists refreshed from every provider.');
    }

    function aiRenderModelSelect() {
        const sel = document.getElementById('aiModelSelect');
        if (!sel) return;
        const prev = sel.value || getCookie('aiModel');
        sel.innerHTML = '';

        const usable = aiProviders.filter(function (p) { return p.enabled; });
        if (!usable.length) {
            sel.innerHTML = '<option value="">No provider configured</option>';
            aiModelPickSync();
            return;
        }

        usable.forEach(function (p) {
            const g = document.createElement('optgroup');
            g.label = p.label;

            // A provider whose discovery failed still has to be usable: its pinned model
            // goes in the list on its own. Hiding it because api.openai.com had a bad
            // minute would make a transient outage look like a broken install.
            let models = p.models || [];
            if (!models.length && p.model) models = [{ id: p.model, label: p.model + ' (pinned)' }];

            models.forEach(function (m) {
                const o = document.createElement('option');
                o.value = p.id + ' ' + m.id;
                o.textContent = (m.label || m.id) + (m.context ? '  · ' + Math.round(m.context / 1000) + 'k' : '');
                g.appendChild(o);
            });

            if (!models.length) {
                const o = document.createElement('option');
                o.value = p.id + ' ';
                o.textContent = p.models_error ? ('unavailable - ' + p.models_error) : 'no models';
                o.disabled = true;
                g.appendChild(o);
            }
            sel.appendChild(g);
        });

        // Restore the previous choice; otherwise the default provider's pinned model.
        if (prev && Array.prototype.some.call(sel.options, function (o) { return o.value === prev; })) {
            sel.value = prev;
        } else {
            const def = usable.find(function (p) { return p.is_default; }) || usable[0];
            const want = def.id + ' ' + (def.model || (def.models[0] || {}).id || '');
            if (Array.prototype.some.call(sel.options, function (o) { return o.value === want; })) sel.value = want;
        }
        aiModelPickSync();   // the button is the only visible part; it must follow the select
    }

    function aiCurrentSelection() {
        const v = (document.getElementById('aiModelSelect') || {}).value || '';
        const i = v.indexOf(' ');
        if (i < 0) return { providerId: 0, model: '' };
        return { providerId: parseInt(v.slice(0, i), 10) || 0, model: v.slice(i + 1) };
    }

    function aiOnModelChange() {
        setCookie('aiModel', document.getElementById('aiModelSelect').value);
        aiModelPickSync();
    }

    /* ---- searchable model picker -----------------------------------------------------
     *
     * A thin view over #aiModelSelect. It never holds state of its own: picking a row sets
     * the select's value and fires its change handler, so the cookie, aiCurrentSelection()
     * and every other reader stay authoritative and unaware this exists.
     */
    function aiModelPickSync() {
        const sel = document.getElementById('aiModelSelect');
        const lab = document.getElementById('aiModelBtnLabel');
        if (!sel || !lab) return;
        const opt = sel.options[sel.selectedIndex];
        lab.textContent = opt ? opt.textContent.trim() : 'No provider configured';
        lab.title = lab.textContent;
    }

    function aiModelPickToggle(e) {
        if (e) e.stopPropagation();
        const pop = document.getElementById('aiModelPop');
        const open = pop.classList.toggle('open');
        if (open) {
            const f = document.getElementById('aiModelFilter');
            f.value = '';
            aiModelPickRender();
            f.focus();
        }
    }

    /* Starred models.
     *
     * Kept in a cookie, like aiModel and aiWorld beside it, rather than a new settings
     * column: this is a per-operator view preference, it needs no migration, and it works
     * on an already-running install the moment the image is pulled. Losing it to a cleared
     * cookie costs one click per model to rebuild.
     *
     * Capped, because a cookie is sent on EVERY request to this origin — an unbounded
     * favourites list would quietly add weight to every page load and API call in the admin.
     */
    const AI_FAV_MAX = 24;

    function aiFavList() {
        try {
            const v = JSON.parse(getCookie('aiFavModels') || '[]');
            return Array.isArray(v) ? v : [];
        } catch (e) { return []; }
    }

    function aiFavToggle(value) {
        let f = aiFavList();
        f = f.indexOf(value) >= 0 ? f.filter(function (x) { return x !== value; })
                                  : [value].concat(f).slice(0, AI_FAV_MAX);
        setCookie('aiFavModels', JSON.stringify(f));
        aiModelPickRender();
    }

    function aiModelPickRender() {
        const sel  = document.getElementById('aiModelSelect');
        const list = document.getElementById('aiModelList');
        const q    = (document.getElementById('aiModelFilter').value || '').toLowerCase().trim();
        if (!sel || !list) return;

        const fav     = aiFavList();
        const matches = function (o) {
            return !o.disabled && (!q || (o.textContent + ' ' + o.value).toLowerCase().indexOf(q) >= 0);
        };
        const row = function (o, providerLabel) {
            const starred = fav.indexOf(o.value) >= 0;
            return '<div class="ai-modelpick-item' + (o.value === sel.value ? ' sel' : '') + '">'
                 +   '<button type="button" class="ai-modelpick-star' + (starred ? ' on' : '') + '"'
                 +     ' data-star="' + aiEsc(o.value) + '"'
                 +     ' title="' + (starred ? 'Unpin' : 'Pin to the top') + '">'
                 +     (starred ? '★' : '☆')
                 +   '</button>'
                 +   '<button type="button" class="ai-modelpick-row" data-v="' + aiEsc(o.value) + '">'
                 +     aiEsc(o.textContent.trim())
                 +     (providerLabel ? '<span class="ai-modelpick-prov">' + aiEsc(providerLabel) + '</span>' : '')
                 +   '</button>'
                 + '</div>';
        };

        let html = '';

        // Starred first, in the order they were starred. Rendered from the LIVE options, so
        // a model that has since been retired upstream simply stops appearing — the cookie
        // keeps it in case a transient discovery failure brings it back.
        const all = [...sel.querySelectorAll('option')];
        const favRows = fav
            .map(function (v) { return all.find(function (o) { return o.value === v; }); })
            .filter(function (o) { return o && matches(o); });
        if (favRows.length) {
            html += '<div class="ai-modelpick-group">★ Pinned</div>';
            favRows.forEach(function (o) {
                const grp = o.closest('optgroup');
                html += row(o, grp ? grp.label : '');
            });
        }

        // Then every provider, with its own heading. Headings survive filtering because with
        // two providers configured "gpt" and "claude" are different answers to one query.
        for (const grp of sel.querySelectorAll('optgroup')) {
            const rows = [...grp.querySelectorAll('option')].filter(matches);
            if (!rows.length) continue;
            html += '<div class="ai-modelpick-group">' + aiEsc(grp.label) + '</div>';
            rows.forEach(function (o) { html += row(o, ''); });
        }

        list.innerHTML = html || '<div class="ai-modelpick-empty">No model matches &ldquo;' + aiEsc(q) + '&rdquo;.</div>';

        list.querySelectorAll('.ai-modelpick-row').forEach(function (b) {
            b.addEventListener('click', function () {
                sel.value = b.getAttribute('data-v');
                aiOnModelChange();
                document.getElementById('aiModelPop').classList.remove('open');
            });
        });
        list.querySelectorAll('.ai-modelpick-star').forEach(function (b) {
            b.addEventListener('click', function (e) {
                // Without this the star's click bubbles to the row and selects the model,
                // closing the popup — pinning something would silently switch to it.
                e.stopPropagation();
                aiFavToggle(b.getAttribute('data-star'));
            });
        });
    }

    function aiModelPickKey(e) {
        if (e.key === 'Escape') { document.getElementById('aiModelPop').classList.remove('open'); return; }
        if (e.key !== 'Enter') return;
        // Enter takes the first match, which is the whole point of typing three letters.
        const first = document.querySelector('#aiModelList .ai-modelpick-row');
        if (first) first.click();
    }

    // Click-away. Registered once, not per-open, so repeated opening cannot stack handlers.
    document.addEventListener('click', function (e) {
        const pick = document.getElementById('aiModelPick');
        if (pick && !pick.contains(e.target)) {
            const pop = document.getElementById('aiModelPop');
            if (pop) pop.classList.remove('open');
        }
    });

    function aiOnWorldChange() {
        setCookie('aiWorld', document.getElementById('aiWorldSelect').value);
        aiLoadDiagnostics(false);
        aiRenderQuickPrompts();
    }

    function aiRenderContextOptions() {
        const sel = document.getElementById('aiWorldSelect');
        if (!sel) return;
        const prev = sel.value || getCookie('aiWorld') || '';
        sel.innerHTML = '<option value="">Whole server</option>';
        aiKnownWorlds.forEach(function (n) {
            const o = document.createElement('option');
            o.value = n; o.textContent = n;
            sel.appendChild(o);
        });
        if (prev && aiKnownWorlds.indexOf(prev) >= 0) sel.value = prev;
    }

    /* ---- diagnostics (no model involved) ------------------------------------------ */

    async function aiLoadDiagnostics(force) {
        const box = document.getElementById('aiDiagnostics');
        if (!box) return;
        const world = (document.getElementById('aiWorldSelect') || {}).value || '';
        box.innerHTML = '<div class="ai-diag-loading">Scanning logs, mods, backups and services&hellip;</div>';
        try {
            const res  = await fetch('adminAPI.php?action=aiDiagnostics&world=' + encodeURIComponent(world));
            const data = await res.json();
            aiRenderDiagnostics(data.findings || []);
        } catch (e) {
            box.innerHTML = '<div class="ai-diag-loading">Scan failed: ' + aiEsc(e.message) + '</div>';
        }
    }

    function aiRenderDiagnostics(findings) {
        const box = document.getElementById('aiDiagnostics');
        if (!box) return;
        if (!findings.length) { box.innerHTML = ''; return; }

        const icon = { critical: '✕', warning: '!', info: 'i' };
        box.innerHTML = findings.map(function (f, i) {
            const ev = (f.evidence || []).length
                ? '<pre class="ai-diag-evidence">' + aiEsc(f.evidence.slice(0, 12).join('\n')) + '</pre>'
                : '';
            return '<div class="ai-diag ai-diag-' + aiEsc(f.severity) + '">'
                 +   '<div class="ai-diag-head">'
                 +     '<span class="ai-diag-badge">' + (icon[f.severity] || '?') + '</span>'
                 +     '<span class="ai-diag-title">' + aiEsc(f.title) + '</span>'
                 +   '</div>'
                 +   '<div class="ai-diag-detail">' + aiEsc(f.detail) + '</div>'
                 +   ev
                 +   (f.ask ? '<button class="ai-diag-ask" data-i="' + i + '">Ask AI about this &rarr;</button>' : '')
                 + '</div>';
        }).join('');

        box.querySelectorAll('.ai-diag-ask').forEach(function (btn) {
            btn.addEventListener('click', function () {
                const f = findings[parseInt(btn.getAttribute('data-i'), 10)];
                if (f && f.world) {
                    const sel = document.getElementById('aiWorldSelect');
                    if (sel && aiKnownWorlds.indexOf(f.world) >= 0) sel.value = f.world;
                }
                aiSend(f.ask);
            });
        });
    }

    function aiRenderNoProviderNotice() {
        const box = document.getElementById('aiMessages');
        if (!box || box.querySelector('.ai-message')) return;
        if (aiProviders.filter(function (p) { return p.enabled; }).length) return;
        aiAppend('assistant',
            'No AI provider is configured yet, so I cannot answer questions.\n\n'
          + 'The health scan above still runs - it is plain pattern matching and needs no model at all.\n\n'
          + 'Add a provider with **+ Provider** above. OpenAI, Anthropic and Gemini take an API key; '
          + 'Ollama, vLLM, LM Studio and llama.cpp just need a URL.');
    }

    /* ---- quick prompts ------------------------------------------------------------ */

    function aiRenderQuickPrompts() {
        const box = document.getElementById('aiQuickPrompts');
        if (!box) return;
        const world = (document.getElementById('aiWorldSelect') || {}).value || '';

        const prompts = world ? [
            ["Why won't it start?",  "World '" + world + "' is not starting correctly. Read its log since the most recent server start and tell me the cause."],
            ['Check its mods',       "Compare the mods configured for world '" + world + "' against what BepInEx actually loaded, and list anything missing or failing."],
            ['Backups OK?',          "Is world '" + world + "' backing up on schedule? Check the backup log if it is not."],
            ['Who can join?',        "Explain exactly who can currently join world '" + world + "' and whether that matches how it is configured."]
        ] : [
            ['Full health check',    'Run a full health check of this server and tell me what needs my attention, most urgent first.'],
            ['Any world broken?',    'Check every world and tell me if any of them are failing to start or run correctly.'],
            ['Mod catalogue status', 'Is the mod catalogue syncing correctly? When did each source last update?'],
            ['Disk and memory',      'How is this host doing on disk, memory and CPU, and is anything at risk?']
        ];

        box.innerHTML =
            '<button class="ai-quick-prompt ai-quick-prompt-meta" data-local="capabilities">'
          + 'What can Hugin do for me?</button>'
          + prompts.map(function (p) {
                return '<button class="ai-quick-prompt" data-q="' + aiEsc(p[1]) + '">' + aiEsc(p[0]) + '</button>';
            }).join('');

        box.querySelectorAll('.ai-quick-prompt').forEach(function (b) {
            b.addEventListener('click', function () {
                // The capability answer is rendered server-side from the action catalogue
                // and never goes near the model. Asked of an LLM, "what can you do" is an
                // invitation to invent: it would cheerfully offer to restore a backup,
                // because that is what a server manager plausibly does. This is the one
                // answer that has to be exactly right, so it is generated from the same
                // catalogue that decides what actually runs -- and it still works when the
                // operator's model cannot call tools at all.
                if (b.getAttribute('data-local') === 'capabilities') { aiShowCapabilities(); return; }
                aiSend(b.getAttribute('data-q'));
            });
        });
    }

    /* A reply produced WITHOUT tools must say so, once per conversation.
     *
     * The endpoint refused the tools parameter, so Hugin answered from the live-state
     * summary in its prompt and general knowledge alone — it could not read a log, check a
     * world, or change anything. An answer like that is often still useful and is always
     * indistinguishable from a real investigation unless we label it. */
    let aiDegradedShown = false;
    function aiDegradedNotice(kind) {
        if (aiDegradedShown) return;
        aiDegradedShown = true;
        const box = document.getElementById('aiMessages');
        const el  = document.createElement('div');
        el.className = 'ai-degraded';
        el.innerHTML =
            '<strong>&#9888; This model can\'t use tools.</strong> '
          + 'Hugin answered from the server summary and general knowledge only — it did not '
          + 'read any log, inspect any world, or change anything, and it cannot propose '
          + 'changes. Pick a model that supports function calling for the full assistant.';
        box.appendChild(el);
        box.scrollTop = box.scrollHeight;
    }

    /* ---- confirm cards -------------------------------------------------------------
     *
     * Hugin proposes; the operator decides. The card is built from the SERVER's summary,
     * never from the model's description of what it is doing -- when those two disagree,
     * this is the moment that matters, and the operator should see the truth rather than
     * the claim.
     *
     * The browser holds nothing but an opaque token. It cannot alter what will run.
     */
    function aiRenderProposals(list) {
        if (!list || !list.length) return;
        const box = document.getElementById('aiMessages');

        list.forEach(function (p) {
            const card = document.createElement('div');
            card.className = 'ai-proposal' + (p.typed ? ' danger' : '');

            const mins = Math.max(1, Math.round((p.expires || 900) / 60));
            let html =
                '<div class="ai-proposal-head">'
              +   '<span class="ai-proposal-icon">' + (p.typed ? '&#9888;' : '&#10003;') + '</span>'
              +   '<span class="ai-proposal-title">' + (p.typed ? 'Confirm — this cannot be undone' : 'Waiting for your confirmation') + '</span>'
              + '</div>'
              + '<div class="ai-proposal-body">' + aiEsc(p.summary) + '</div>';

            if (p.typed) {
                html += '<div class="ai-proposal-typed">'
                     +    '<label>Type <strong>' + aiEsc(p.typed) + '</strong> to confirm</label>'
                     +    '<input type="text" class="form-control pv-input ai-proposal-name" '
                     +         'autocomplete="off" spellcheck="false" placeholder="' + aiEsc(p.typed) + '">'
                     +  '</div>';
            }

            html += '<div class="ai-proposal-actions">'
                 +    '<button class="ai-proposal-apply">Apply</button>'
                 +    '<button class="ai-proposal-dismiss">Dismiss</button>'
                 +    '<span class="ai-proposal-note">expires in ' + mins + ' min</span>'
                 +  '</div>';

            card.innerHTML = html;
            box.appendChild(card);

            const applyBtn = card.querySelector('.ai-proposal-apply');
            const dropBtn  = card.querySelector('.ai-proposal-dismiss');
            const nameIn   = card.querySelector('.ai-proposal-name');
            const note     = card.querySelector('.ai-proposal-note');

            function settle(cls, msg) {
                card.classList.add(cls);
                applyBtn.remove(); dropBtn.remove();
                if (nameIn) nameIn.closest('.ai-proposal-typed').remove();
                note.textContent = msg;
            }

            applyBtn.addEventListener('click', async function () {
                applyBtn.disabled = true; dropBtn.disabled = true;
                note.textContent = 'Applying…';
                try {
                    const r = await fetch('adminAPI.php?action=applyAiProposal', {
                        method: 'POST',
                        headers: { 'Content-Type': 'application/json' },
                        body: JSON.stringify({ token: p.token, typed: nameIn ? nameIn.value : '' })
                    });
                    const d = await r.json();
                    if (d.success) {
                        settle('applied', d.message || 'Done.');
                        // Lifecycle actions move worlds.mode; the engine picks that up on
                        // its next two-second tick. The worlds table already self-polls,
                        // but nudge it so the result is visible now rather than up to
                        // POLL_INTERVAL later.
                        //
                        // fetchWorldStatus, NOT refreshWorlds -- the latter does not exist,
                        // and a `typeof x === 'function'` guard around a name that is never
                        // defined is silently dead code that reads as working.
                        setTimeout(fetchWorldStatus, 2500);
                    } else {
                        // NOT settled: a refusal is usually recoverable (wrong name typed,
                        // state moved on). Leave the buttons so the operator can retry.
                        applyBtn.disabled = false; dropBtn.disabled = false;
                        note.textContent = d.error || 'That could not be applied.';
                        card.classList.add('refused');
                    }
                } catch (e) {
                    applyBtn.disabled = false; dropBtn.disabled = false;
                    note.textContent = 'Could not reach the server: ' + e.message;
                }
            });

            dropBtn.addEventListener('click', async function () {
                try {
                    await fetch('adminAPI.php?action=dismissAiProposal', {
                        method: 'POST',
                        headers: { 'Content-Type': 'application/json' },
                        body: JSON.stringify({ token: p.token })
                    });
                } catch (e) { /* the row expires on its own; nothing to recover */ }
                settle('dismissed', 'Dismissed.');
            });

            if (nameIn) nameIn.addEventListener('keydown', function (e) {
                if (e.key === 'Enter') { e.preventDefault(); applyBtn.click(); }
            });
        });

        box.scrollTop = box.scrollHeight;
    }

    async function aiShowCapabilities() {
        if (aiBusy) return;
        aiAppend('user', 'What can Hugin do for me?');
        const bubble = aiAppend('assistant', '_Reading my own catalogue&hellip;_');
        try {
            const r = await fetch('adminAPI.php?action=aiCapabilities');
            const d = await r.json();
            if (!d.success) throw new Error(d.error || 'Could not read the catalogue.');
            bubble.querySelector('.ai-message-content').innerHTML = aiMd(d.markdown);
        } catch (e) {
            bubble.classList.add('error');
            bubble.querySelector('.ai-message-content').textContent =
                'Could not list my capabilities: ' + e.message;
        }
        // Kept out of aiHistory on purpose: it is a UI affordance, not part of the
        // conversation, and feeding a long capability dump back as context on every
        // subsequent turn would cost tokens for no benefit.
        document.getElementById('aiMessages').scrollTop = 1e9;
    }

    /* ---- Hugin ---------------------------------------------------------------------
     *
     * Inline SVG rather than a file: the admin UI ships as PHP + CSS with no image
     * pipeline, and a <img> here would be one more 404 to get wrong on a subpath install.
     * Every state he can be in corresponds to an actual SSE event, so he is a progress
     * indicator that cannot lie about whether work is happening.
     */
    /* Clone the raven already in the panel header rather than describing him a second time.
     * The artwork is authored once, in includes/hugin.php; a JS string copy would be a
     * second drawing that silently stops matching the first the moment either is edited. */
    function aiHuginNode(state, size) {
        const src = document.querySelector('.ai-panel-hugin');
        if (!src) return null;
        const el = src.cloneNode(true);
        el.setAttribute('class', 'ai-hugin ' + (state || 'idle'));
        el.setAttribute('width',  size || 36);
        el.setAttribute('height', size || 36);
        return el;
    }

    /* What Hugin says he is doing. Keyed on the tool the model actually called, so the
     * line is never a generic "Thinking..." when the truth is available. */
    const AI_TOOL_PHRASE = {
        get_diagnostics:     'Scanning for faults',
        list_worlds:         'Taking stock of the worlds',
        get_world:           'Looking up the world settings',
        list_logs:           'Finding the right log',
        read_log:            'Reading the log',
        search_log:          'Searching the log',
        get_world_mods:      'Checking which mods should be installed',
        get_mod_sync_status: 'Checking the mod catalogues',
        get_backup_status:   'Checking the backups',
        get_system_health:   'Checking the host'
    };

    function aiToolPhrase(name, args) {
        const base = AI_TOOL_PHRASE[name] || ('Running ' + name);
        const a    = args || {};
        if (a.file)    return base + ' — ' + a.file;
        if (a.world)   return base + ' — ' + a.world;
        if (a.pattern) return base + ' for "' + a.pattern + '"';
        return base;
    }

    /* ---- chat --------------------------------------------------------------------- */

    function aiAppend(role, text) {
        const box = document.getElementById('aiMessages');
        const el  = document.createElement('div');
        el.className = 'ai-message ' + role;
        el.innerHTML = '<div class="ai-message-content">' + (role === 'user' ? aiEsc(text) : aiMd(text)) + '</div>';
        box.appendChild(el);
        box.scrollTop = box.scrollHeight;
        return el;
    }

    function aiFoot(msg) {
        const f = document.getElementById('aiFooter');
        if (f) f.textContent = msg || '';
    }

    function aiNewChat() {
        aiHistory = [];
        document.getElementById('aiMessages').innerHTML = '';
        aiFoot('');
        aiRenderNoProviderNotice();
    }

    async function aiSend(preset) {
        if (aiBusy) return;
        const input = document.getElementById('aiInput');
        const text  = (preset !== undefined ? preset : input.value).trim();
        if (!text) return;

        const panel = document.getElementById('aiPanel');
        if (!panel.classList.contains('open')) toggleAiPanel();

        const sel = aiCurrentSelection();
        if (!sel.providerId || !sel.model) {
            aiAppend('error', 'Pick a provider and model first, or add one with **+ Provider**.');
            return;
        }

        if (preset === undefined) input.value = '';
        aiAppend('user', text);
        aiHistory.push({ role: 'user', content: text });
        if (aiHistory.length > AI_MAX_HISTORY) aiHistory = aiHistory.slice(-AI_MAX_HISTORY);

        aiBusy = true;
        document.getElementById('aiSendBtn').disabled = true;

        const bubble  = aiAppend('assistant', '');
        const content = bubble.querySelector('.ai-message-content');
        const trace   = document.createElement('div');
        trace.className = 'ai-trace';
        bubble.insertBefore(trace, content);
        content.innerHTML = '<span class="ai-cursor"></span>';

        const started = Date.now();
        let acc = '';

        // ---- the working strip -------------------------------------------------------
        // Before this, a 17-second tool-calling answer showed a blinking caret and nothing
        // else. There was no way to tell thinking from hung, and no sign that the helper
        // was reading anything at all until the finished answer landed all at once.
        const work = document.createElement('div');
        work.className = 'ai-working';
        work.innerHTML = '<div class="ai-working-text"><span>Thinking</span>'
                       +   '<div class="ai-working-sub"></div></div>'
                       + '<div class="ai-working-timer">0.0s</div>';
        const cloned = aiHuginNode('thinking');
        if (cloned) work.insertBefore(cloned, work.firstChild);
        bubble.insertBefore(work, trace);

        const raven   = work.querySelector('.ai-hugin');
        const workTxt = work.querySelector('.ai-working-text');
        const workSub = work.querySelector('.ai-working-sub');
        const header  = document.querySelector('.ai-panel-hugin');
        let   toolCount = 0, lastRow = null;

        const setState = function (st) {
            raven.className = 'ai-hugin ' + st;
            if (header) header.className = 'ai-hugin ai-panel-hugin ' + st;
        };
        // Re-creating the <span> is what replays the crossfade animation.
        const say = function (msg, sub) {
            workTxt.firstChild.replaceWith(Object.assign(document.createElement('span'), { textContent: msg }));
            if (sub !== undefined) workSub.textContent = sub;
        };
        const timer = setInterval(function () {
            work.querySelector('.ai-working-timer').textContent =
                ((Date.now() - started) / 1000).toFixed(1) + 's';
        }, 100);
        // Marks the previous tool row finished: the stream tells us a tool STARTED, and the
        // next event is proof the one before it returned.
        const settleLastRow = function () {
            if (!lastRow) return;
            lastRow.classList.remove('running');
            lastRow.classList.add('done');
            lastRow.querySelector('.ai-trace-mark').textContent = '✓';
            lastRow = null;
        };
        let finished = false;
        const finish = function (st, msg) {
            if (finished) return;               // called from the done branch AND the tail
            finished = true;
            clearInterval(timer);
            settleLastRow();
            setState(st);
            if (msg) aiFoot(msg);
            // Let the cheer (or the slump) actually play before the strip goes. Removing it
            // in the same tick as setting the state means the animation is authored, applied
            // and destroyed within one frame, and nobody ever sees it.
            if (st === 'done' || st === 'error') {
                say(st === 'done' ? 'Done' : 'Gave up', '');
                setTimeout(function () { work.remove(); }, 780);
            }
            if (header) setTimeout(function () { header.className = 'ai-hugin ai-panel-hugin idle'; }, 820);
        };

        try {
            const res = await fetch('aiStream.php', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({
                    message:     text,
                    history:     aiHistory.slice(0, -1),
                    world:       (document.getElementById('aiWorldSelect') || {}).value || '',
                    provider_id: sel.providerId,
                    model:       sel.model
                })
            });

            if (!res.ok || !res.body) throw new Error('HTTP ' + res.status);

            const reader  = res.body.getReader();
            const decoder = new TextDecoder();
            let buf = '';

            for (;;) {
                const chunk = await reader.read();
                if (chunk.done) break;
                buf += decoder.decode(chunk.value, { stream: true });

                // SSE framing: events are separated by a blank line.
                let sep;
                while ((sep = buf.indexOf('\n\n')) >= 0) {
                    const block = buf.slice(0, sep);
                    buf = buf.slice(sep + 2);
                    const line = block.split('\n').find(function (l) { return l.indexOf('data:') === 0; });
                    if (!line) continue;

                    let ev;
                    try { ev = JSON.parse(line.slice(5).trim()); } catch (e) { continue; }

                    if (ev.type === 'delta') {
                        if (acc === '') {
                            // First character of the answer: the investigation is over.
                            settleLastRow();
                            setState('speaking');
                            say('Writing it up', '');
                        }
                        acc += ev.text;
                        content.innerHTML = aiMd(acc) + '<span class="ai-cursor"></span>';
                        document.getElementById('aiMessages').scrollTop = 1e9;
                    } else if (ev.type === 'tool') {
                        // Show what the model actually looked at. This is the difference
                        // between "trust me" and a citation the operator can check.
                        settleLastRow();
                        toolCount++;
                        setState('working');
                        say(aiToolPhrase(ev.name, ev.args),
                            toolCount + (toolCount === 1 ? ' source consulted' : ' sources consulted'));

                        const row = document.createElement('div');
                        row.className = 'ai-trace-row running';
                        const args = Object.keys(ev.args || {}).map(function (k) {
                            return k + '=' + JSON.stringify(ev.args[k]);
                        }).join(', ');
                        row.innerHTML = '<span class="ai-trace-mark">⚙</span> '
                                      + aiEsc(ev.name + '(' + args + ')');
                        trace.appendChild(row);
                        lastRow = row;
                    } else if (ev.type === 'error') {
                        finish('error');
                        bubble.className = 'ai-message error';
                        content.innerHTML = aiMd('**' + ev.error + '**');
                        acc = '';
                        // A proposal written before the turn died is still valid, and the
                        // card describes itself. Showing it beats an error plus a silent
                        // pending change the operator never saw.
                        aiRenderProposals(ev.proposals);
                    } else if (ev.type === 'done') {
                        finish('done');
                        aiRenderProposals(ev.proposals);
                        if (ev.degraded) aiDegradedNotice(ev.degraded);
                        if (ev.content && ev.content.length > acc.length) acc = ev.content;
                        content.innerHTML = aiMd(acc);
                        const secs = ((Date.now() - started) / 1000).toFixed(1);
                        const u = ev.usage || {};
                        const tok = u.total_tokens || u.totalTokenCount
                                 || ((u.input_tokens || 0) + (u.output_tokens || 0)) || 0;
                        aiFoot(ev.model + ' · ' + secs + 's' + (tok ? ' · ' + tok + ' tokens' : ''));
                    }
                }
            }

            if (acc) {
                content.innerHTML = aiMd(acc);
                aiHistory.push({ role: 'assistant', content: acc });
            } else if (bubble.className.indexOf('error') < 0) {
                // The stream opened, said `start`, and then stopped without a single delta,
                // tool or done event. Previously this left an EMPTY GREY BUBBLE and nothing
                // else -- no text, no error, no clue. It is what a server-side fatal looks
                // like from the browser (a undefined-function fatal in the prompt builder
                // produced exactly this), and an operator cannot be expected to go reading
                // php.log to discover that their question was never answered.
                bubble.className = 'ai-message error';
                content.innerHTML = aiMd(
                    '**The reply ended before it began.** The connection opened but no answer '
                  + 'followed, which usually means the request failed server-side. Check '
                  + '`/opt/stateful/logs/php.log` and `ai.log` for the reason.'
                );
            }
        } catch (e) {
            // A stream that never opened is usually a proxy that will not pass
            // text/event-stream. The non-streaming endpoint is the same conversation
            // without the typing effect, so fall back rather than fail.
            //
            // Hugin keeps working through it: the request is still in flight, and killing
            // the indicator here would show a frozen panel for the whole fallback call.
            setState('working');
            say('Stream refused — asking again the plain way', '');
            try {
                const res = await fetch('adminAPI.php?action=aiHelper', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify({
                        message: text, history: aiHistory.slice(0, -1),
                        world: (document.getElementById('aiWorldSelect') || {}).value || '',
                        provider_id: sel.providerId, model: sel.model
                    })
                });
                const d = await res.json();
                if (d.success) {
                    content.innerHTML = aiMd(d.reply);
                    aiHistory.push({ role: 'assistant', content: d.reply });
                    aiFoot((d.model || '') + ' · non-streaming fallback');
                } else {
                    bubble.className = 'ai-message error';
                    content.innerHTML = aiMd('**' + (d.error || 'Request failed') + '**');
                }
            } catch (e2) {
                bubble.className = 'ai-message error';
                content.innerHTML = aiMd('**' + e2.message + '**');
            }
        }

        // Unconditional: clearInterval here or a failed request leaves a timer ticking in
        // the background for the life of the page, and the strip stranded mid-animation.
        finish(bubble.className.indexOf('error') >= 0 ? 'error' : 'done');

        aiBusy = false;
        document.getElementById('aiSendBtn').disabled = false;
    }

    function toggleAiPanel() {
        const panel   = document.getElementById('aiPanel');
        const overlay = document.getElementById('aiOverlay');
        const open    = panel.classList.toggle('open');
        if (overlay) overlay.classList.toggle('open', open);
        if (open) {
            aiRenderContextOptions();
            aiRenderQuickPrompts();
            aiLoadDiagnostics(false);
            aiRenderNoProviderNotice();
            document.getElementById('aiInput').focus();
        }
    }

    /* Called from log-viewer windows via window.opener, and from the ?aiWorld=/?aiAsk=
     * deep link when the opener is gone. */
    function openAiHelperWithContext(world, question) {
        const panel = document.getElementById('aiPanel');
        if (!panel.classList.contains('open')) toggleAiPanel();

        if (world) {
            const sel = document.getElementById('aiWorldSelect');
            if (sel && aiKnownWorlds.indexOf(world) < 0) {
                // The world poll may not have run yet in a freshly opened tab. Add the
                // option rather than silently dropping the context the caller asked for.
                aiKnownWorlds.push(world);
                aiRenderContextOptions();
            }
            if (sel) { sel.value = world; setCookie('aiWorld', world); }
            aiRenderQuickPrompts();
            aiLoadDiagnostics(false);
        }

        if (question) aiSend(question);
        else document.getElementById('aiInput').focus();
    }

    /* ---- the Add-provider wizard --------------------------------------------------- */

    // Model BEFORE Test, deliberately. The other order forces the round-trip step to invent
    // a model to probe with, and the only thing it can invent is "whichever the provider
    // listed first" -- which on a live Gemini account is an internal preview that refuses
    // system instructions. A good key then fails the wizard with an error about a model the
    // operator never chose. Pick first, then test what was picked.
    const AI_WIZ_STEPS = ['Type', 'Endpoint', 'Credentials', 'Model', 'Test'];
    const AI_WIZ_MODEL = 3, AI_WIZ_TEST = 4;
    let aiWiz = null;

    function aiWizOpen(providerId) {
        const existing = providerId ? aiProviders.find(function (p) { return p.id === providerId; }) : null;
        aiWiz = {
            step: existing ? 1 : 0,
            id: existing ? existing.id : 0,
            kind: existing ? existing.kind : '',
            label: existing ? existing.label : '',
            base_url: existing ? existing.base_url : '',
            api_key: '',
            has_key: existing ? existing.has_key : false,
            model: existing ? existing.model : '',
            models: existing ? (existing.models || []) : [],
            enabled: existing ? !!existing.enabled : true,
            extra_headers: existing ? (existing.extra_headers || {}) : {}
        };
        document.getElementById('aiWizTitle').textContent = existing ? ('Edit ' + existing.label) : 'Add an AI provider';
        document.getElementById('aiWizardOverlay').classList.add('show');
        aiWizRender();
    }

    function aiWizClose(e) {
        if (e && e.target !== document.getElementById('aiWizardOverlay')) return;
        document.getElementById('aiWizardOverlay').classList.remove('show');
        aiWiz = null;
    }

    function aiWizStatus(msg, cls) {
        const el = document.getElementById('aiWizStatus');
        el.textContent = msg || '';
        el.style.color = cls === 'bad' ? 'var(--danger)' : (cls === 'good' ? 'var(--success)' : 'var(--text-muted)');
    }

    function aiWizRender() {
        const w = aiWiz;
        if (!w) return;

        document.getElementById('aiWizSteps').innerHTML = AI_WIZ_STEPS.map(function (s, i) {
            return '<span class="ai-wiz-step' + (i === w.step ? ' active' : (i < w.step ? ' done' : '')) + '">'
                 + (i + 1) + '. ' + s + '</span>';
        }).join('');

        const body = document.getElementById('aiWizBody');
        const kind = aiKinds[w.kind] || {};
        aiWizStatus('');

        if (w.step === 0) {
            body.innerHTML = '<div class="ai-wiz-kinds">' + Object.keys(aiKinds).map(function (k) {
                const d = aiKinds[k];
                return '<button class="ai-wiz-kind' + (w.kind === k ? ' sel' : '') + '" data-k="' + k + '">'
                     +   '<span class="ai-wiz-kind-name">' + aiEsc(d.label) + '</span>'
                     +   '<span class="ai-wiz-kind-blurb">' + aiEsc(d.blurb) + '</span>'
                     + '</button>';
            }).join('') + '</div>';
            body.querySelectorAll('.ai-wiz-kind').forEach(function (b) {
                b.addEventListener('click', function () {
                    const next = b.getAttribute('data-k');
                    if (next === w.kind) return;
                    const prev = aiKinds[w.kind] || {};

                    // Carry the defaults across ONLY if the operator has not edited them.
                    //
                    // The old test was `if (!w.label)` — blank-or-not. Once the first pick
                    // had filled them in they were never blank again, so going Back and
                    // choosing a different type left the PREVIOUS type's name and base URL
                    // sitting in the form, and the wizard went on to talk to the wrong
                    // endpoint entirely. Comparing against the outgoing kind's defaults
                    // distinguishes "untouched" from "deliberately typed", which is the
                    // distinction that was actually wanted.
                    if (!w.label    || w.label    === prev.label)    w.label    = aiKinds[next].label;
                    if (!w.base_url || w.base_url === prev.base_url) w.base_url = aiKinds[next].base_url;

                    w.kind   = next;
                    w.models = [];          // a different endpoint has a different catalogue
                    w.model  = '';
                    aiWizRender();
                });
            });

        } else if (w.step === 1) {
            // Presets for the catch-all kind. They are a typing aid only: everything here
            // speaks the same protocol, which is exactly why they are presets and not
            // eleven more provider types with eleven more adapters to keep working.
            const presets = (kind.presets || []).map(function (pr, i) {
                return '<button type="button" class="ai-wiz-preset" data-p="' + i + '">' + aiEsc(pr.label) + '</button>';
            }).join('');

            body.innerHTML =
                (presets
                    ? '<label class="ai-wiz-label">Start from</label>'
                      + '<div class="ai-wiz-presets">' + presets + '</div>'
                      + '<div class="ai-wiz-hint" id="aiWizPresetHint">All of these serve the same '
                      + '<code>/chat/completions</code> API — a preset only fills in the URL below.</div>'
                      + '<div style="height:1rem;"></div>'
                    : '')
              + '<label class="ai-wiz-label">Name</label>'
              + '<input class="form-control form-control-sm" id="aiWizLabel" value="' + aiEsc(w.label) + '">'
              + '<div class="ai-wiz-hint">Shown in the model picker. Give two endpoints of the same type different names.</div>'
              + '<label class="ai-wiz-label" style="margin-top:1rem;">Base URL</label>'
              + '<input class="form-control form-control-sm" id="aiWizBase" style="font-family:var(--font-mono)" value="' + aiEsc(w.base_url) + '">'
              + '<div class="ai-wiz-hint">' + aiEsc(kind.base_hint || '') + '</div>';

            body.querySelectorAll('.ai-wiz-preset').forEach(function (b) {
                b.addEventListener('click', function () {
                    const pr = kind.presets[parseInt(b.getAttribute('data-p'), 10)];
                    document.getElementById('aiWizBase').value = pr.base_url;
                    // Update the STATE too, not just the input.
                    //
                    // Setting only the DOM left w.base_url on the previous value -- after
                    // clicking vLLM the field read http://127.0.0.1:8000/v1 while the wizard
                    // still believed https://api.openai.com/v1. Anything that re-rendered
                    // this step from state (Back, or a cleared field) silently put the
                    // OpenAI URL back in front of the operator.
                    w.base_url = pr.base_url;
                    // Only rename if the name is still a default — never overwrite a name
                    // the operator chose, same rule as switching provider type.
                    const nameEl = document.getElementById('aiWizLabel');
                    const isDefault = !nameEl.value || nameEl.value === kind.label
                                   || (kind.presets || []).some(function (x) { return x.label === nameEl.value; });
                    if (isDefault) nameEl.value = pr.label;
                    w.models = [];
                    body.querySelectorAll('.ai-wiz-preset').forEach(function (o) { o.classList.remove('sel'); });
                    b.classList.add('sel');
                    const hint = document.getElementById('aiWizPresetHint');
                    if (hint && pr.hint) hint.textContent = pr.hint;
                });
            });

        } else if (w.step === 2) {
            body.innerHTML =
                '<label class="ai-wiz-label">' + aiEsc(kind.key_label || 'API key') + '</label>'
              + '<input class="form-control form-control-sm" type="password" id="aiWizKey" autocomplete="new-password"'
              +   ' placeholder="' + (w.has_key ? 'stored - leave blank to keep it' : '') + '" value="">'
              + '<div class="ai-wiz-hint">' + aiEsc(kind.key_hint || '') + '</div>'
              + '<label class="ai-wiz-label" style="margin-top:1rem;">Extra headers <span style="font-weight:normal;color:var(--text-muted)">(optional JSON)</span></label>'
              + '<input class="form-control form-control-sm" id="aiWizHeaders" style="font-family:var(--font-mono)"'
              +   ' value="' + aiEsc(Object.keys(w.extra_headers).length ? JSON.stringify(w.extra_headers) : '') + '"'
              +   ' placeholder="{&quot;HTTP-Referer&quot;: &quot;https://example.org&quot;}">'
              + '<div class="ai-wiz-hint">Only needed by gateways that require them, such as OpenRouter attribution headers.</div>';

        } else if (w.step === AI_WIZ_TEST) {
            body.innerHTML = '<div id="aiWizTestOut" class="ai-wiz-test">Testing&hellip;</div>';
            aiWizRunTest();

        } else if (!w.models.length) {
            // Discovery has not run for this draft yet. Ask, then re-render into the picker.
            body.innerHTML = '<div class="ai-wiz-hint">Asking the endpoint what it can run&hellip;</div>';
            aiWizDiscover();

        } else {
            const list = w.models.length ? w.models : (w.model ? [{ id: w.model, label: w.model }] : []);
            body.innerHTML =
                '<label class="ai-wiz-label">Model</label>'
              + '<input class="form-control form-control-sm" id="aiWizFilter" placeholder="Filter&hellip;" style="margin-bottom:0.5rem;">'
              + '<div class="ai-wiz-models" id="aiWizModels"></div>'
              + '<div class="ai-wiz-hint" style="margin-top:0.75rem;">'
              +   list.length + ' model(s) reported by this endpoint just now. PhValheim keeps no built-in list, '
              +   'so anything retired upstream is simply absent here.'
              + '</div>';

            const render = function () {
                const q = (document.getElementById('aiWizFilter').value || '').toLowerCase();
                document.getElementById('aiWizModels').innerHTML = list
                    .filter(function (m) { return !q || m.id.toLowerCase().indexOf(q) >= 0; })
                    .map(function (m) {
                        return '<button class="ai-wiz-model' + (w.model === m.id ? ' sel' : '') + '" data-m="' + aiEsc(m.id) + '">'
                             +   '<span>' + aiEsc(m.label || m.id) + '</span>'
                             +   (m.context ? '<span class="ai-wiz-ctx">' + Math.round(m.context / 1000) + 'k</span>' : '')
                             + '</button>';
                    }).join('') || '<div class="ai-wiz-hint">No match.</div>';
                document.getElementById('aiWizModels').querySelectorAll('.ai-wiz-model').forEach(function (b) {
                    b.addEventListener('click', function () { w.model = b.getAttribute('data-m'); render(); });
                });
            };
            render();
            document.getElementById('aiWizFilter').addEventListener('input', render);
        }

        document.getElementById('aiWizBack').style.visibility = w.step === 0 ? 'hidden' : 'visible';
        document.getElementById('aiWizNext').textContent = w.step === AI_WIZ_STEPS.length - 1 ? 'Save' : 'Next';
    }

    function aiWizCollect() {
        const w = aiWiz;
        if (w.step === 1) {
            w.label = (document.getElementById('aiWizLabel') || {}).value || w.label;
            // `?? w.base_url`, NOT `|| w.base_url`.
            //
            // With `||`, clearing the field to retype it read as "no value" and fell back to
            // whatever the state held — so an empty box silently kept the old URL, and the
            // "A base URL is required" check below could never fire. An empty string is a
            // deliberate act by the operator; only a MISSING element should fall back.
            const el   = document.getElementById('aiWizBase');
            const base = el ? el.value : w.base_url;
            // A different endpoint has a different catalogue. Dropping the cached list forces
            // rediscovery instead of offering models the new URL may not serve.
            if (base !== w.base_url) w.models = [];
            w.base_url = base;
        } else if (w.step === 2) {
            const k = (document.getElementById('aiWizKey') || {}).value || '';
            if (k && k !== w.api_key) { w.api_key = k; w.models = []; }
            const h = ((document.getElementById('aiWizHeaders') || {}).value || '').trim();
            if (h) {
                try { w.extra_headers = JSON.parse(h); }
                catch (e) { return 'Extra headers must be valid JSON.'; }
            } else {
                w.extra_headers = {};
            }
        }
        return null;
    }

    async function aiWizDiscover() {
        const w = aiWiz;
        try {
            const res = await fetch('adminAPI.php?action=discoverAiModels', {
                method: 'POST', headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({
                    id: w.id, kind: w.kind, label: w.label, base_url: w.base_url,
                    api_key: w.api_key, extra_headers: w.extra_headers
                })
            });
            const d = await res.json();
            if (!aiWiz || aiWiz !== w) return;               // wizard closed while in flight
            if (d.success && (d.models || []).length) {
                w.models = d.models;
                aiWizRender();
            } else {
                // A discovery failure here is almost always the key or the URL, and both are
                // one step back. Say which, rather than dropping the operator into an empty list.
                document.getElementById('aiWizBody').innerHTML =
                    '<div class="ai-wiz-test-row bad"><span>✕</span><b>Model discovery</b><span>'
                  + aiEsc(d.error || 'the endpoint listed no models') + '</span></div>';
                aiWizStatus('Go Back and check the base URL and credentials.', 'bad');
            }
        } catch (e) {
            if (!aiWiz || aiWiz !== w) return;
            document.getElementById('aiWizBody').innerHTML =
                '<div class="ai-wiz-test-row bad"><span>✕</span><b>Model discovery</b><span>' + aiEsc(e.message) + '</span></div>';
            aiWizStatus('Discovery failed.', 'bad');
        }
    }

    async function aiWizRunTest() {
        const w   = aiWiz;
        const out = document.getElementById('aiWizTestOut');
        try {
            const res = await fetch('adminAPI.php?action=testAiProvider', {
                method: 'POST', headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({
                    id: w.id, kind: w.kind, label: w.label, base_url: w.base_url,
                    api_key: w.api_key, model: w.model, extra_headers: w.extra_headers
                })
            });
            const d = await res.json();
            if (d.models) w.models = d.models;

            out.innerHTML = (d.steps || [{ name: 'Test', ok: false, detail: d.error || 'failed' }]).map(function (s) {
                const cls = s.ok ? (s.warn ? 'warn' : 'ok') : 'bad';
                const gly = s.ok ? (s.warn ? '!' : '✓') : '✕';
                return '<div class="ai-wiz-test-row ' + cls + '"><span>' + gly + '</span>'
                     + '<b>' + aiEsc(s.name) + '</b><span>' + aiEsc(s.detail) + '</span></div>';
            }).join('');

            aiWizStatus(d.success ? 'Connection verified.' : 'Fix the failures above, or go Back and correct the settings.',
                        d.success ? 'good' : 'bad');
        } catch (e) {
            out.innerHTML = '<div class="ai-wiz-test-row bad"><span>✕</span><b>Test</b><span>' + aiEsc(e.message) + '</span></div>';
            aiWizStatus('Test failed.', 'bad');
        }
    }

    async function aiWizGo(dir) {
        const w = aiWiz;
        if (!w) return;

        if (dir > 0) {
            const err = aiWizCollect();
            if (err) { aiWizStatus(err, 'bad'); return; }
            if (w.step === 0 && !w.kind)     { aiWizStatus('Pick a provider type.', 'bad'); return; }
            if (w.step === 1 && !w.base_url) { aiWizStatus('A base URL is required.', 'bad'); return; }
            // Enforced on the way OUT of the model step, so the test that follows always has
            // a real, operator-chosen model to exercise.
            if (w.step === AI_WIZ_MODEL && !w.model) { aiWizStatus('Choose a model.', 'bad'); return; }

            if (w.step === AI_WIZ_STEPS.length - 1) {
                if (!w.model) { aiWizStatus('Choose a model.', 'bad'); return; }
                aiWizStatus('Saving...');
                const res = await fetch('adminAPI.php?action=saveAiProvider', {
                    method: 'POST', headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify({
                        id: w.id, kind: w.kind, label: w.label, base_url: w.base_url,
                        api_key: w.api_key, model: w.model, enabled: w.enabled ? 1 : 0,
                        extra_headers: w.extra_headers
                    })
                });
                const d = await res.json();
                if (!d.success) { aiWizStatus(d.error || 'Save failed', 'bad'); return; }
                aiWizClose();
                await aiLoadProviders(0, false);
                aiRenderProviderList();
                aiFoot('Provider saved.');
                return;
            }
            w.step++;
        } else {
            aiWizCollect();
            w.step = Math.max(0, w.step - 1);
        }
        aiWizRender();
    }

    /* ---- provider list inside Server Settings --------------------------------------- */

    function aiRenderProviderList() {
        const box = document.getElementById('ss-aiProviderList');
        if (!box) return;
        if (!aiProviders.length) {
            box.innerHTML = '<div class="ai-wiz-hint">No providers yet.</div>';
            return;
        }
        box.innerHTML = aiProviders.map(function (p) {
            const models = (p.models || []).length;
            const state  = p.models_error
                ? '<span style="color:var(--danger)">' + aiEsc(p.models_error) + '</span>'
                : models + ' models available';
            return '<div class="ai-prov">'
                 +   '<div class="ai-prov-main">'
                 +     '<b>' + aiEsc(p.label) + '</b>'
                 +     (p.is_default ? '<span class="ai-prov-tag">default</span>' : '')
                 +     (p.enabled ? '' : '<span class="ai-prov-tag off">disabled</span>')
                 +     '<div class="ai-prov-sub">' + aiEsc(p.base_url) + '</div>'
                 +     '<div class="ai-prov-sub">' + aiEsc(p.model || 'no model pinned') + ' · ' + state + '</div>'
                 +   '</div>'
                 +   '<div class="ai-prov-actions">'
                 +     '<button class="ai-chip-btn" data-edit="' + p.id + '">Edit</button>'
                 +     '<button class="ai-chip-btn" data-del="' + p.id + '">Delete</button>'
                 +   '</div>'
                 + '</div>';
        }).join('');

        box.querySelectorAll('[data-edit]').forEach(function (b) {
            b.addEventListener('click', function () { aiWizOpen(parseInt(b.getAttribute('data-edit'), 10)); });
        });
        box.querySelectorAll('[data-del]').forEach(function (b) {
            b.addEventListener('click', async function () {
                if (!confirm('Delete this AI provider?')) return;
                await fetch('adminAPI.php?action=deleteAiProvider', {
                    method: 'POST', headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify({ id: parseInt(b.getAttribute('data-del'), 10) })
                });
                await aiLoadProviders(0, false);
                aiRenderProviderList();
            });
        });
    }

    /* ---- wiring -------------------------------------------------------------------- */

    document.getElementById('aiInput').addEventListener('keydown', function (e) {
        if (e.key === 'Enter' && !e.shiftKey) { e.preventDefault(); aiSend(); }
    });

    document.addEventListener('click', function (e) {
        const row = e.target.closest('tr[data-world]');
        if (row) window.lastSelectedWorld = row.getAttribute('data-world');
    });

    aiLoadProviders(0, false);

    // Deep link from a log-viewer window that lost its opener.
    (function () {
        const params = new URLSearchParams(window.location.search);
        const w = params.get('aiWorld');
        const q = params.get('aiAsk');
        if (w !== null || q) {
            setTimeout(function () { openAiHelperWithContext(w || '', q || ''); }, 1200);
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
