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
# How many Ollama providers dbUpdate_2.45.sh converted to openai_compatible, 0 = nothing to
# say. Non-zero raises a one-shot notice: the conversion rewrote a base URL the operator
# typed, and a change nobody was told about is discovered weeks later as "why is this
# pointing there". Defaults to 0 so a database predating the column stays quiet.
$aiOllamaNotice = (int)($_settingsRow['aiOllamaNotice'] ?? 0);
// Defaults to 1 (already shown) when the column is missing, so a server whose migration has
// not run yet does not flash an introduction for a feature it does not have.
$huginNoticeShown = (int)($_settingsRow['huginNoticeShown'] ?? 1);
# 1 = stay quiet. Defaults to 1 for a database that predates the column, so an install
# that never ran the 2.40 migration cannot be told its ids were converted.
$accessIdNoticeShown = (int)($_settingsRow['accessIdNoticeShown'] ?? 1);
$accessSwitchNoticeShown = (int)($_settingsRow['accessSwitchNoticeShown'] ?? 1);
# Version whose release notes were last dismissed. '' = never shown. Compared against
# $phvalheimVersion to decide whether the one-shot "What's New" modal appears.
$whatsNewShownVersion = (string)($_settingsRow['whatsNewShownVersion'] ?? '');
$timezone = $_settingsRow['timezone'] ?? 'Etc/UTC';
date_default_timezone_set($timezone);

# $aiKeys is GONE as of 2.45.
#
# AI providers are rows in `ai_providers`, not four fixed columns in `settings`, because
# the old shape could hold exactly one OpenAI key, one Claude key, one Gemini key and one
# keyless Ollama URL -- no second endpoint of the same kind, and no way at all to reach a
# self-hosted vLLM or LM Studio behind --api-key.
#
# Read providers with aiProviders($pdo) / aiDefaultProvider($pdo) from
# includes/aiproviders.php. The settings columns openaiApiKey, geminiApiKey,
# claudeApiKey and ollamaUrl still exist as a rollback record for dbUpdate_2.45.sh and
# are NOT read by anything: code found reading them is looking at a value the AI Helper
# stopped using, which is the same class of bug as the pre-2.43 mod columns.

unset($_settingsPdo, $_settingsRow);
?>
