<?php
// An expiring, non-secret route challenge; no session, database, or config output.
declare(strict_types=1);
header('Cache-Control: no-store');
header('Content-Type: application/json');
$test = json_decode(@file_get_contents('/run/phvalheim-remote/challenge.json') ?: '', true);
$nonce = $_GET['nonce'] ?? '';
if (!is_array($test) || !is_string($nonce) || ($test['expires'] ?? 0) < time()
    || !hash_equals((string)($test['nonce'] ?? ''), $nonce) || strlen($nonce) !== 48) {
    http_response_code(404);
    echo '{"error":"No active test"}';
    exit;
}
if (($_GET['browser'] ?? '') === '1') {
    // The one-file record has no IP, identity, or user-agent. It proves this browser
    // reached the route, not that it was on another network or authorized as a player.
    $tmp = tempnam('/run/phvalheim-remote', 'device-');
    if ($tmp !== false) {
        file_put_contents($tmp, json_encode(['nonce' => $nonce, 'time' => time()]));
        chmod($tmp, 0644);
        rename($tmp, '/run/phvalheim-remote/device-test.json');
    }
    header('Content-Type: text/html; charset=utf-8');
    header("Content-Security-Policy: default-src 'none'; style-src 'unsafe-inline'; base-uri 'none'; frame-ancestors 'none'");
    echo '<!doctype html><html lang="en"><meta name="viewport" content="width=device-width, initial-scale=1"><title>PhValheim connection test</title><body style="font:18px system-ui;max-width:42rem;margin:4rem auto;padding:1rem"><h1>This browser reached PhValheim</h1><p>Return to Remote Access to see this result. This checks the website route, not Steam sign-in, downloads, game ports, or your network location.</p><p><a href="/">Open the player website</a></p></body></html>';
} else {
    echo json_encode(['nonce' => $nonce, 'instance' => $test['instance'], 'payload' => str_repeat('phvalheim', 128)]);
}
