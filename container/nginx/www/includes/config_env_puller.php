<?php

# Pull settings from database (source of truth since v2.31)
try {
    $_settingsPdo = new PDO('mysql:host=localhost;dbname=phvalheim', 'phvalheim_user', 'phvalheim_secretpassword');
    $_settingsRow = $_settingsPdo->query("SELECT * FROM settings LIMIT 1")->fetch(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    // Database not ready yet (e.g. MariaDB still starting) — show a friendly page
    http_response_code(503);
    echo '<!DOCTYPE html><html><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1">';
    echo '<meta http-equiv="refresh" content="5">';
    echo '<link rel="icon" type="image/svg+xml" href="/images/phvalheim_favicon.svg">';
    echo '<link rel="stylesheet" href="/css/phvalheimStyles.css">';
    echo '<style>@keyframes pulse{0%,100%{opacity:1}50%{opacity:.4}}.startup-logo{animation:pulse 2s ease-in-out infinite}</style>';
    echo '</head>';
    echo '<body style="display:flex;align-items:center;justify-content:center;min-height:100vh;background:var(--bg-primary);color:var(--text-primary);">';
    echo '<div style="text-align:center;max-width:400px;padding:2rem;">';
    echo '<img src="/images/phvalheim_favicon.svg" class="startup-logo" style="width:64px;height:64px;margin-bottom:1.5rem;" alt="PhValheim">';
    echo '<h2 style="margin-bottom:0.75rem;">PhValheim Server is Starting&hellip;</h2>';
    echo '<p style="color:var(--text-muted);">The database is initializing. This page will automatically refresh.</p>';
    echo '</div></body></html>';
    exit;
}

# Version comes from Dockerfile ENV (not user-configurable)
$phvalheimVersion = getenv('phvalheimVersion');

# Host is derived from the request (not stored in DB)
$phvalheimHost = $_SERVER['HTTP_HOST'] ?? 'localhost';

# All other settings from database
$basePort = $_settingsRow['basePort'] ?? 25000;
$defaultSeed = $_settingsRow['defaultSeed'] ?? '';
$gameDNS = $_settingsRow['gameDNS'] ?? '';
$steamAPIKey = $_settingsRow['steamApiKey'] ?? '';
$phvalheimClientURL = $_settingsRow['phvalheimClientURL'] ?? '';
$backupsToKeep = $_settingsRow['backupsToKeep'] ?? 24;
$sessionTimeout = $_settingsRow['sessionTimeout'] ?? 2592000;
$setupComplete = (int)($_settingsRow['setupComplete'] ?? 0);
$migrationNoticeShown = (int)($_settingsRow['migrationNoticeShown'] ?? 0);
$timezone = $_settingsRow['timezone'] ?? 'Etc/UTC';
date_default_timezone_set($timezone);

$aiKeys = [
    'openai'  => $_settingsRow['openaiApiKey'] ?? '',
    'gemini'  => $_settingsRow['geminiApiKey'] ?? '',
    'claude'  => $_settingsRow['claudeApiKey'] ?? '',
    'ollama'  => $_settingsRow['ollamaUrl'] ?? '',
];

unset($_settingsPdo, $_settingsRow);
?>
