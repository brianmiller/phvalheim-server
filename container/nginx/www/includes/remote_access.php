<?php
declare(strict_types=1);

function phvRemoteOriginAllowed(array $server): bool {
    $origin = $server['HTTP_ORIGIN'] ?? '';
    $host = $server['HTTP_HOST'] ?? '';
    // Match the browser-facing authority exactly, including its port. HTTPS
    // may terminate at the operator's existing reverse proxy; no forwarded
    // header is used to grant access. Direct TLS never accepts an HTTP origin.
    $matches = $host !== '' && (hash_equals('https://' . $host, $origin)
        || (($server['HTTPS'] ?? '') !== 'on' && hash_equals('http://' . $host, $origin)));
    return $origin !== '' && $matches
        && !in_array($server['HTTP_SEC_FETCH_SITE'] ?? '', ['cross-site', 'same-site'], true);
}

function phvRemoteControl(array $request, int $timeout = 10): array {
    $socket = @stream_socket_client('unix:///run/phvalheim-remote/control.sock', $errno, $error, 2);
    if (!$socket) {
        return ['success' => true, 'available' => false, 'error' => 'The built-in Remote Access service is stopped or starting. Wait a moment, then retry. If it persists, check Server Health.'];
    }
    stream_set_timeout($socket, $timeout);
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
