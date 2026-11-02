#!/usr/bin/env php
<?php
require_once '/opt/stateless/nginx/www/includes/gameports.php';
try {
    $policy = phvRequireGamePortPolicy();
    if (($argv[1] ?? '') === 'validate') exit(0);
    if (!$policy['enabled']) throw new RuntimeException('No container game port range configured.');
    if (($argv[1] ?? '') === 'start') { echo $policy['start']; exit(0); }
    $pdo = new PDO('mysql:host=localhost;dbname=phvalheim', 'phvalheim_user', 'phvalheim_secretpassword', [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
    if (($argv[1] ?? '') === 'assign' && ctype_digit($argv[2] ?? '')) {
        echo phvAssignManagedWorldPort($pdo, (int)$argv[2]);
    } else {
        throw new RuntimeException('Expected validate, start or assign WORLD_ID.');
    }
} catch (Throwable $e) {
    fwrite(STDERR, $e->getMessage() . "\n");
    exit(1);
}
