<?php
declare(strict_types=1);
require_once __DIR__ . '/../includes/remote_access.php';
header('Content-Type: application/json');
header('Cache-Control: no-store');
header('X-Content-Type-Options: nosniff');
header('Cross-Origin-Resource-Policy: same-origin');

function remoteFail(int $status, string $message): void {
    http_response_code($status);
    echo json_encode(['success' => false, 'error' => $message]);
    exit;
}

// Use the existing private admin boundary, including ordinary LAN access.
// Reject cross-origin reads before issuing the session-bound mutation token.
if (in_array($_SERVER['HTTP_SEC_FETCH_SITE'] ?? '', ['cross-site', 'same-site'], true)
    || (isset($_SERVER['HTTP_ORIGIN']) && !phvRemoteOriginAllowed($_SERVER))) {
    remoteFail(403, 'Open Remote Access from the admin page.');
}
session_name('phv_remote_admin');
session_start(['cookie_httponly' => true, 'cookie_samesite' => 'Strict',
               'cookie_secure' => ($_SERVER['HTTPS'] ?? '') === 'on', 'use_strict_mode' => true]);
if (!isset($_SESSION['remote_csrf'])) $_SESSION['remote_csrf'] = bin2hex(random_bytes(32));
$csrf = $_SESSION['remote_csrf'];
session_write_close();
$method = $_SERVER['REQUEST_METHOD'] ?? '';
if ($method === 'GET') {
    $result = phvRemoteControl(['action' => 'status']);
    $result['csrf'] = $csrf;
    echo json_encode($result);
    exit;
}
if ($method !== 'POST') remoteFail(405, 'POST required.');
if (!phvRemoteOriginAllowed($_SERVER) || !hash_equals($csrf, $_SERVER['HTTP_X_PHV_CSRF'] ?? '')) {
    remoteFail(403, 'Reload the admin page and retry this action.');
}
if ((int)($_SERVER['CONTENT_LENGTH'] ?? 0) > 16384) remoteFail(413, 'Request is too large.');
if (strtolower(explode(';', $_SERVER['CONTENT_TYPE'] ?? '')[0]) !== 'application/json') remoteFail(415, 'JSON required.');
$input = json_decode(file_get_contents('php://input', false, null, 0, 16385), true);
if (!is_array($input)) remoteFail(400, 'Invalid request.');
$actions = ['save', 'connect', 'enroll', 'disconnect', 'forget', 'test', 'cancelTest'];
if (!in_array($input['action'] ?? '', $actions, true)) remoteFail(400, 'Unknown action.');
$result = phvRemoteControl($input);
if (empty($result['available']) && ($result['success'] ?? false)) remoteFail(503, 'The built-in Remote Access service is stopped or starting. Wait a moment, then retry. If it persists, check Server Health.');
if (empty($result['success'])) http_response_code(400);
echo json_encode($result);
