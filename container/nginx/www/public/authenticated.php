<?php

require_once '../vendor/autoload.php';
include '../includes/config_env_puller.php';
include '../includes/phvalheim-frontend-config.php';
include '../includes/db_gets.php';
include '../includes/db_sets.php';
include '../includes/userAgent.php';
include '../includes/clientDownloadButton.php';
include '../includes/modViewerGenerator.php';

# simple security: if this page is accessed from a source other than steam, redirect back to login page
# NOTE: this security check only works when HTTPS is used!
if($_SERVER['HTTP_X_FORWARDED_PROTO'] == "https") {
    if ($_SERVER['HTTP_REFERER'] != "https://steamcommunity.com/") {
        header('Location: ../index.php');
    }
}

if($_SERVER['HTTP_X_FORWARDED_PROTO'] == "https") {
    $httpScheme = "https";
} else {
    $httpScheme = "http";
}

// Get Steam user info
$steamID = '';
$playerName = '';
$steamAvatarURL = '';

if(isset($_GET['openid_claimed_id'])) {
    $steamIDArr = explode('/', $_GET['openid_claimed_id']);
    $steamID = end($steamIDArr);
    $steamJSON = file_get_contents("https://api.steampowered.com/ISteamUser/GetPlayerSummaries/v0002/?key=$steamAPIKey&steamids=$steamID");
    $steamJSONObj = json_decode($steamJSON);
    $steamJSONObj = $steamJSONObj->response->players;
    $steamJSONObj = $steamJSONObj[0];

    $steamNickName = $steamJSONObj->personaname;
    $steamFullName = $steamJSONObj->realname ?? '';
    $steamAvatarURL = $steamJSONObj->avatarmedium;

    if(!empty($steamFullName)) {
        $playerName = explode(' ', $steamFullName)[0];
    } else {
        $playerName = $steamNickName;
    }
} else {
    header('Location: ../index.php');
    exit;
}

// Get worlds for this user
$myWorlds = getMyWorlds($pdo, $steamID);
$onlineCount = 0;
$totalCount = count($myWorlds);

// Count online worlds
foreach ($myWorlds as $world) {
    $memory = getWorldMemory($pdo, $world);
    if ($memory !== "offline") {
        $onlineCount++;
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>PhValheim - My Worlds</title>
    <link rel="stylesheet" type="text/css" href="../css/bootstrap.min.css">
    <link rel="stylesheet" type="text/css" href="../css/phvalheimStyles.css?v=<?php echo time(); ?>">
    <script src="../js/jquery-3.6.0.js"></script>
    <script src="../js/bootstrap.min.js"></script>
</head>
<body class="public-page">
    <div class="public-container">
        <!-- Header -->
        <header class="public-header">
            <div class="public-header-left">
                <div class="public-logo">
                    <div class="public-logo-icon">PV</div>
                    <div class="public-logo-text">
                        <span class="public-logo-title">PhValheim</span>
                        <span class="public-logo-version">v<?php echo $phvalheimVersion; ?></span>
                    </div>
                </div>
            </div>
            <div class="public-header-right">
                <div class="public-user-info">
                    <span class="public-user-name">Welcome, <?php echo htmlspecialchars($playerName); ?>!</span>
                    <img src="<?php echo $steamAvatarURL; ?>" alt="Avatar" class="public-user-avatar">
                </div>
                <div class="public-download-area">
                    <?php populateDownloadMenu($operatingSystem, $phValheimClientGitRepo, $clientVersionsToRender); ?>
                </div>
            </div>
        </header>

        <!-- Stats Summary -->
        <div class="public-stats">
            <div class="public-stat-item">
                <span class="public-stat-value"><?php echo $onlineCount; ?></span>
                <span class="public-stat-label">Online</span>
            </div>
            <div class="public-stat-divider"></div>
            <div class="public-stat-item">
                <span class="public-stat-value"><?php echo $totalCount; ?></span>
                <span class="public-stat-label">Total Worlds</span>
            </div>
        </div>

        <!-- Worlds Grid -->
        <div class="public-worlds-grid">
            <?php if(!empty($myWorlds)): ?>
                <?php foreach ($myWorlds as $myWorld): ?>
                <?php
                    $launchString = getLaunchString($pdo, $myWorld, $gameDNS, $phvalheimHost, $httpScheme);
                    $md5 = getMD5($pdo, $myWorld);
                    $seed = getSeed($pdo, $myWorld);
                    $hideSeed = getHideSeed($pdo, $myWorld);
                    $dateDeployed = getDateDeployed($pdo, $myWorld);
                    $dateUpdated = getDateUpdated($pdo, $myWorld);
                    $worldMemory = getWorldMemory($pdo, $myWorld);
                    $modCount = getTotalModCountOfWorld($pdo, $myWorld);

                    $trophyEikthyr = getBossTrophyStatus($pdo, $myWorld, "trophyeikthyr");
                    $trophyTheElder = getBossTrophyStatus($pdo, $myWorld, "trophytheelder");
                    $trophyBonemass = getBossTrophyStatus($pdo, $myWorld, "trophybonemass");
                    $trophyDragonQueen = getBossTrophyStatus($pdo, $myWorld, "trophydragonqueen");
                    $trophyGoblinKing = getBossTrophyStatus($pdo, $myWorld, "trophygoblinking");
                    $trophySeekerQueen = getBossTrophyStatus($pdo, $myWorld, "trophyseekerqueen");
                    $trophyFader = getBossTrophyStatus($pdo, $myWorld, "trophyfader");

                    $isOffline = ($worldMemory == "offline");
                    $cardClass = $isOffline ? 'public-world-card offline' : 'public-world-card';

                    if ($hideSeed == 1) {
                        $seed = 'Hidden';
                    }

                    // Build mods tooltip
                    $runningMods_head = "<table border='0' style='line-height:auto;'>";
                    $runningMods_foot = "</table>";
                    $runningMods = $runningMods_head . generateToolTip($pdo, $myWorld) . $runningMods_foot;
                ?>
                <div class="<?php echo $cardClass; ?>">
                    <div class="public-world-header">
                        <h3 class="public-world-name"><?php echo htmlspecialchars($myWorld); ?></h3>
                        <span class="public-world-status <?php echo $isOffline ? 'offline' : 'online'; ?>">
                            <span class="status-dot"></span>
                            <?php echo $isOffline ? 'Offline' : 'Online'; ?>
                        </span>
                    </div>

                    <div class="public-world-launch">
                        <?php if($isOffline): ?>
                            <span class="public-launch-btn disabled">Offline</span>
                        <?php else: ?>
                            <a href="phvalheim://?<?php echo $launchString; ?>" class="public-launch-btn">Launch Game</a>
                        <?php endif; ?>
                    </div>

                    <div class="public-world-details">
                        <div class="public-detail-row">
                            <span class="public-detail-label">Seed</span>
                            <span class="public-detail-value"><?php echo $seed; ?></span>
                        </div>
                        <div class="public-detail-row">
                            <span class="public-detail-label">Mods</span>
                            <span class="public-detail-value">
                                <?php if(!$isOffline && $modCount > 0): ?>
                                <a href="#" class="public-mods-link" data-bs-toggle="popover" data-bs-trigger="focus" data-bs-placement="bottom" data-bs-title="Running Mods" data-bs-html="true" data-bs-content="<?php echo htmlspecialchars($runningMods); ?>"><?php echo $modCount; ?> mods</a>
                                <?php elseif(!$isOffline): ?>
                                <span style="color: var(--text-muted);">None</span>
                                <?php else: ?>
                                <span style="color: var(--text-muted);">—</span>
                                <?php endif; ?>
                            </span>
                        </div>
                        <div class="public-detail-row">
                            <span class="public-detail-label">Memory</span>
                            <span class="public-detail-value"><?php echo $isOffline ? '—' : $worldMemory; ?></span>
                        </div>
                        <div class="public-detail-row">
                            <span class="public-detail-label">Updated</span>
                            <span class="public-detail-value"><?php echo $dateUpdated ?: '—'; ?></span>
                        </div>
                    </div>

                    <div class="public-world-trophies">
                        <img src="../images/TrophyEikthyr.png" title="<?php echo $trophyEikthyr ? 'Eikthyr defeated' : 'Eikthyr undefeated'; ?>" class="<?php echo $trophyEikthyr && !$isOffline ? '' : 'dimmed'; ?>">
                        <img src="../images/TrophyTheElder.png" title="<?php echo $trophyTheElder ? 'The Elder defeated' : 'The Elder undefeated'; ?>" class="<?php echo $trophyTheElder && !$isOffline ? '' : 'dimmed'; ?>">
                        <img src="../images/TrophyBonemass.png" title="<?php echo $trophyBonemass ? 'Bonemass defeated' : 'Bonemass undefeated'; ?>" class="<?php echo $trophyBonemass && !$isOffline ? '' : 'dimmed'; ?>">
                        <img src="../images/TrophyDragonQueen.png" title="<?php echo $trophyDragonQueen ? 'Moder defeated' : 'Moder undefeated'; ?>" class="<?php echo $trophyDragonQueen && !$isOffline ? '' : 'dimmed'; ?>">
                        <img src="../images/TrophyGoblinKing.png" title="<?php echo $trophyGoblinKing ? 'Yagluth defeated' : 'Yagluth undefeated'; ?>" class="<?php echo $trophyGoblinKing && !$isOffline ? '' : 'dimmed'; ?>">
                        <img src="../images/TrophySeekerQueen.png" title="<?php echo $trophySeekerQueen ? 'The Queen defeated' : 'The Queen undefeated'; ?>" class="<?php echo $trophySeekerQueen && !$isOffline ? '' : 'dimmed'; ?>">
                        <img src="../images/TrophyFader.png" title="<?php echo $trophyFader ? 'Fader defeated' : 'Fader undefeated'; ?>" class="<?php echo $trophyFader && !$isOffline ? '' : 'dimmed'; ?>">
                    </div>
                </div>
                <?php endforeach; ?>
            <?php else: ?>
                <div class="public-no-worlds">
                    <svg width="48" height="48" fill="none" stroke="currentColor" viewBox="0 0 24 24" style="opacity: 0.5; margin-bottom: 1rem;">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M21 12a9 9 0 01-9 9m9-9a9 9 0 00-9-9m9 9H3m9 9a9 9 0 01-9-9m9 9c1.657 0 3-4.03 3-9s-1.343-9-3-9m0 18c-1.657 0-3-4.03-3-9s1.343-9 3-9m-9 9a9 9 0 019-9"/>
                    </svg>
                    <p>You don't have access to any worlds yet.</p>
                    <p style="font-size: 0.875rem; color: var(--text-muted);">Contact your server administrator to get access.</p>
                </div>
            <?php endif; ?>
        </div>

        <!-- Warnings -->
        <?php if(empty($backupsToKeep) || empty($playerName) || empty($phvalheimClientURL) || empty($phvalheimHost) || empty($basePort) || empty($gameDNS) || empty($defaultSeed)): ?>
        <div class="public-warnings">
            <?php if(empty($backupsToKeep)): ?>
                <div class="public-warning">WARNING: Backup retention is not configured. Ensure you're passing the "backupsToKeep" variable.</div>
            <?php endif; ?>
            <?php if(empty($playerName)): ?>
                <div class="public-warning">WARNING: The SteamAPI could not be contacted. Check your Steam API Key.</div>
            <?php endif; ?>
            <?php if(empty($phvalheimClientURL)): ?>
                <div class="public-warning">WARNING: The PhValheim Client Download URL is missing!</div>
            <?php endif; ?>
            <?php if(empty($phvalheimHost)): ?>
                <div class="public-warning">WARNING: The PhValheim Host FQDN variable is missing!</div>
            <?php endif; ?>
            <?php if(empty($basePort)): ?>
                <div class="public-warning">WARNING: The PhValheim Base Port is not set!</div>
            <?php endif; ?>
            <?php if(empty($gameDNS)): ?>
                <div class="public-warning">WARNING: The PhValheim game DNS endpoint is not set!</div>
            <?php endif; ?>
            <?php if(empty($defaultSeed)): ?>
                <div class="public-warning">WARNING: The PhValheim Default Seed is not set!</div>
            <?php endif; ?>
        </div>
        <?php endif; ?>
    </div>

    <script>
        // Initialize Bootstrap 5 popovers
        document.addEventListener('DOMContentLoaded', function() {
            var popoverTriggerList = [].slice.call(document.querySelectorAll('[data-bs-toggle="popover"]'));
            var popoverList = popoverTriggerList.map(function (popoverTriggerEl) {
                return new bootstrap.Popover(popoverTriggerEl, {
                    sanitize: false
                });
            });
        });
    </script>
</body>
</html>
