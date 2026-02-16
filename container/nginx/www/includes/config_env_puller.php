<?php

# Pull settings from database (source of truth since v2.31)
$_settingsPdo = new PDO('mysql:host=localhost;dbname=phvalheim', 'phvalheim_user', 'phvalheim_secretpassword');
$_settingsRow = $_settingsPdo->query("SELECT * FROM settings LIMIT 1")->fetch(PDO::FETCH_ASSOC);

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
