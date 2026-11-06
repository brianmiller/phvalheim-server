<?php
// Read-only, narrowly selected fields. Never return passwords or server API keys.
function phvRemoteNetworkRow(array $row, $backend, $runningOptions): array {
    $running = ($row['mode'] ?? '') === 'running';
    $saved = (int)($row['crossplay'] ?? 0) === 1;
    $observed = $running && in_array($backend, ['steam', 'playfab'], true);
    $launched = $running && is_array($runningOptions);
    $crossplay = $observed ? $backend === 'playfab' : ($launched ? (int)$runningOptions['crossplay'] === 1 : $saved);
    $port = filter_var($row['port'] ?? null, FILTER_VALIDATE_INT, ['options'=>['min_range'=>1,'max_range'=>65534]]);
    return ['name'=>(string)$row['name'], 'mode'=>(string)$row['mode'],
        'crossplay'=>$crossplay, 'savedCrossplay'=>$saved,
        'basis'=>$observed ? 'Running session' : ($launched ? 'Launch settings' : 'Saved settings'),
        'restartPending'=>$running && ($observed || $launched) && $crossplay !== $saved,
        'port'=>$port === false ? null : $port, 'queryPort'=>$port === false ? null : $port+1];
}

function phvRemoteNetwork($pdo): array {
    require_once __DIR__ . '/gameports.php';
    $settings = $pdo->query('SELECT gameDNS, basePort FROM settings LIMIT 1')->fetch(PDO::FETCH_ASSOC) ?: [];
    $worlds = [];
    foreach ($pdo->query('SELECT name, mode, port, crossplay FROM worlds ORDER BY name') as $row) {
        // Use the same session/launch evidence as the existing world cards.
        $running = $row['mode'] === 'running';
        $worlds[] = phvRemoteNetworkRow($row, $running ? getWorldNetBackend($row['name']) : null,
            $running ? runningWorldOptions($row['name']) : null);
    }
    $probe = curl_init('http://127.0.0.1:8080/');
    curl_setopt_array($probe, [CURLOPT_NOBODY=>true, CURLOPT_RETURNTRANSFER=>true,
        CURLOPT_FOLLOWLOCATION=>false, CURLOPT_CONNECTTIMEOUT=>1, CURLOPT_TIMEOUT=>2,
        CURLOPT_PROXY=>'']);
    curl_exec($probe);
    $status = (int)curl_getinfo($probe, CURLINFO_RESPONSE_CODE);
    curl_close($probe);
    return ['success'=>true, 'checkedAt'=>time(), 'gameDNS'=>(string)($settings['gameDNS'] ?? ''),
        'range'=>phvGamePortStatus($pdo), 'worlds'=>$worlds,
        'website'=>['port'=>8080, 'httpStatus'=>$status, 'responding'=>$status>=200 && $status<400],
        // The container cannot inspect host Docker mappings or router firewall rules.
        'internetVerified'=>false];
}
