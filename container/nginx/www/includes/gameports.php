<?php
// Optional container-owned UDP range. Never overwrite the saved basePort: removing
// these variables returns control to the operator's existing Docker/Unraid settings.
function phvGamePortPolicy() {
    $start = getenv('PHVALHEIM_GAME_PORT_START');
    $end = getenv('PHVALHEIM_GAME_PORT_END');
    $enabled = ($start !== false && $start !== '') || ($end !== false && $end !== '');
    $policy = ['enabled' => $enabled, 'start' => null, 'end' => null, 'error' => null];
    if (!$enabled) return $policy;
    if (!is_string($start) || !is_string($end) ||
        !preg_match('/^[0-9]{1,5}$/D', $start) || !preg_match('/^[0-9]{1,5}$/D', $end) ||
        (int)$start < 1 || (int)$end > 65535 || (int)$start > (int)$end ||
        (int)$start % 2 !== 0 || ((int)$end - (int)$start + 1) % 2 !== 0) {
        $policy['error'] = 'Set PHVALHEIM_GAME_PORT_START and PHVALHEIM_GAME_PORT_END together to a valid UDP range (even start, complete two-port pairs, maximum 65535).';
        return $policy;
    }
    $policy['start'] = (int)$start;
    $policy['end'] = (int)$end;
    return $policy;
}

function phvRequireGamePortPolicy() {
    $policy = phvGamePortPolicy();
    if ($policy['error']) throw new RuntimeException($policy['error']);
    return $policy;
}

function phvGamePortFits($policy, $port) {
    return !$policy['error'] && $port !== null && is_numeric($port) &&
        (int)$port % 2 === 0 && (int)$port >= $policy['start'] && (int)$port + 1 <= $policy['end'];
}

function phvNextManagedGamePort($policy, $ports) {
    if ($policy['error']) throw new RuntimeException($policy['error']);
    $used = [];
    foreach ($ports as $port) {
        if ($port === null || $port === '') continue;
        $used[(int)$port] = true;
        $used[(int)$port + 1] = true;
    }
    for ($port = $policy['start']; $port < $policy['end']; $port += 2) {
        if (!isset($used[$port]) && !isset($used[$port + 1])) return $port;
    }
    throw new RuntimeException('Game UDP range ' . $policy['start'] . '-' . $policy['end'] . ' is full. Expand the container port range or remove a world before creating another.');
}

function phvGamePortStatus($pdo) {
    $policy = phvGamePortPolicy();
    $policy['conflicts'] = [];
    if (!$policy['enabled'] || $policy['error']) return $policy;
    foreach ($pdo->query('SELECT name, port FROM worlds WHERE port IS NOT NULL') as $row) {
        if (!phvGamePortFits($policy, $row['port'])) {
            $policy['conflicts'][] = ['name' => $row['name'], 'port' => (int)$row['port']];
        }
    }
    return $policy;
}

function phvManagedSettingsInput($input) {
    $policy = phvRequireGamePortPolicy();
    if (!$policy['enabled']) return $input;
    if (array_key_exists('basePort', $input) && (string)$input['basePort'] !== (string)$policy['start']) {
        throw new RuntimeException('Base Port is managed by the container. Change the published game range in your container settings.');
    }
    unset($input['basePort']);
    return $input;
}

// Serialize bounded allocations across PHP requests and the engine. The insert
// reserves the port before the engine sees the new world, including cloned worlds.
function phvWithGamePortLock($pdo, $callback) {
    if ((int)$pdo->query("SELECT GET_LOCK('phv_game_ports', 10)")->fetchColumn() !== 1) {
        throw new RuntimeException('Game port allocation is busy. Retry creating the world.');
    }
    try { return $callback(); }
    finally { $pdo->query("SELECT RELEASE_LOCK('phv_game_ports')"); }
}

function phvInsertManagedWorld($pdo, $name, $endpoint, $seed) {
    $policy = phvRequireGamePortPolicy();
    return phvWithGamePortLock($pdo, function () use ($pdo, $policy, $name, $endpoint, $seed) {
        $query = $pdo->prepare('SELECT id FROM worlds WHERE name = ?');
        $query->execute([$name]);
        if ($query->fetchColumn() !== false) return 2;
        $port = phvNextManagedGamePort($policy, $pdo->query('SELECT port FROM worlds')->fetchAll(PDO::FETCH_COLUMN));
        $query = $pdo->prepare("INSERT INTO worlds (mode,status,name,external_endpoint,seed,port) VALUES ('create','Down',?,?,?,?)");
        $query->execute([$name, $endpoint, $seed, $port]);
        return 0;
    });
}

function phvAssignManagedWorldPort($pdo, $id) {
    $policy = phvRequireGamePortPolicy();
    return phvWithGamePortLock($pdo, function () use ($pdo, $policy, $id) {
        $query = $pdo->prepare('SELECT port FROM worlds WHERE id = ?');
        $query->execute([$id]);
        $row = $query->fetch(PDO::FETCH_ASSOC);
        if (!$row) throw new RuntimeException('World no longer exists.');
        if ($row['port'] !== null && $row['port'] !== '') {
            if (!phvGamePortFits($policy, $row['port'])) {
                throw new RuntimeException('Existing world port ' . (int)$row['port'] . ' is outside the container UDP range. Expand the range; the world has not been renumbered.');
            }
            return (int)$row['port'];
        }
        $port = phvNextManagedGamePort($policy, $pdo->query('SELECT port FROM worlds')->fetchAll(PDO::FETCH_COLUMN));
        $query = $pdo->prepare('UPDATE worlds SET port = ? WHERE id = ?');
        $query->execute([$port, $id]);
        return $port;
    });
}
