<?php
declare(strict_types=1);

function phvRemoteSecure(array $server): bool {
    // Forwarded headers alone do not make the admin connection secure.
    $peer = $server['REMOTE_ADDR'] ?? '';
    $host = parse_url('http://' . ($server['HTTP_HOST'] ?? ''), PHP_URL_HOST);
    // Docker can translate a loopback-published port's source to its bridge
    // gateway. Browser localhost remains a loopback secure context; require the
    // literal loopback Host too (the endpoint independently rejects other Hosts).
    $localBrowser = in_array($host, ['localhost', '127.0.0.1', '[::1]'], true)
        && filter_var($peer, FILTER_VALIDATE_IP)
        && !filter_var($peer, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE);
    return ($server['HTTPS'] ?? '') === 'on' || $localBrowser
        || in_array($peer, ['127.0.0.1', '::1'], true);
}

function phvRemoteOriginAllowed(array $server): bool {
    $origin = $server['HTTP_ORIGIN'] ?? '';
    $host = $server['HTTP_HOST'] ?? '';
    $scheme = ($server['HTTPS'] ?? '') === 'on' ? 'https' : 'http';
    return $origin !== '' && hash_equals($scheme . '://' . $host, $origin)
        && !in_array($server['HTTP_SEC_FETCH_SITE'] ?? '', ['cross-site', 'same-site'], true);
}

function phvRemoteControl(array $request): array {
    $socket = @stream_socket_client('unix:///run/phvalheim-remote/control.sock', $errno, $error, 2);
    if (!$socket) {
        return ['success' => true, 'available' => false, 'error' => 'The built-in Remote Access service is stopped or starting. Wait a moment, then retry. If it persists, check Server Health.'];
    }
    stream_set_timeout($socket, 10);
    $line = json_encode($request, JSON_THROW_ON_ERROR) . "\n";
    $written = fwrite($socket, $line);
    $response = $written === strlen($line) ? fgets($socket, 131073) : false;
    fclose($socket);
    if (!$response || strlen($response) > 131072) {
        return ['success' => false, 'error' => 'Connector status is unavailable. Retry in a moment.'];
    }
    $result = json_decode($response, true);
    return is_array($result) ? $result : ['success' => false, 'error' => 'Invalid connector response.'];
}
