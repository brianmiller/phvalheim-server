<?php
// Public appearance and client launch preferences. The database remains authoritative.
function phvPublicThemes() {
    return ['classic' => 'Classic', 'reforged' => 'Classic Reforged', 'gate' => 'The Gate', 'ember' => 'Ember'];
}
function phvPublicTheme($value) {
    return is_string($value) && isset(phvPublicThemes()[$value]) ? $value : 'classic';
}
function phvOverlayPolicy($serverDisabled, $worldOverride) {
    return (int)($worldOverride === null ? $serverDisabled : $worldOverride) === 1;
}
function phvWorldOverlay($pdo, $world) {
    $s = $pdo->query('SELECT * FROM settings LIMIT 1')->fetch(PDO::FETCH_ASSOC) ?: [];
    $q = $pdo->prepare('SELECT * FROM worlds WHERE name = ?');
    $q->execute([$world]);
    $w = $q->fetch(PDO::FETCH_ASSOC) ?: [];
    $override = $w['disableSteamOverlay'] ?? null;
    return ['override' => $override === null ? null : (int)$override,
            'serverDisabled' => (int)($s['disableSteamOverlay'] ?? 0),
            'disabled' => phvOverlayPolicy($s['disableSteamOverlay'] ?? 0, $override)];
}
