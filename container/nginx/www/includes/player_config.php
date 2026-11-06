<?php
require_once __DIR__ . '/companion.php';
function playerConfigToken($world, $player, $key = null, $now = null) {
    if (!preg_match('/^[0-9]{17}$/', (string)$player)) return '';
    $expiry = (int)(floor(($now ?? time()) / 300) * 300 + 3600);
    $key = $key ?? phvCompanionKey(); if (!$key) return '';
    return "$player.$expiry." . hash_hmac('sha256', "player-config\n$world\n$player\n$expiry", $key);
}
function playerConfigTokenPlayer($world, $token, $key = null, $now = null) {
    if (!is_string($token) || !preg_match('/^(\d{17})\.(\d{10})\.([a-f0-9]{64})$/', $token, $m)) return null;
    $now = $now ?? time(); if ((int)$m[2] < $now || (int)$m[2] > $now + 3600) return null;
    $key = $key ?? phvCompanionKey(); if (!$key) return null;
    return hash_equals(hash_hmac('sha256', "player-config\n$world\n{$m[1]}\n{$m[2]}", $key), $m[3]) ? $m[1] : null;
}
function playerConfigKey($entry) { return $entry['file'] . "\x1f" . $entry['section'] . "\x1f" . $entry['key']; }
function playerConfigRead($pdo, $world, $player) {
    $st = $pdo->prepare('SELECT revision,config_json FROM player_mod_configs WHERE world_id=? AND steam_id=?');
    $st->execute([worldIdByName($pdo, $world), $player]); $row = $st->fetch(PDO::FETCH_ASSOC);
    return ['revision' => (int)($row['revision'] ?? 0), 'entries' => $row ? (json_decode($row['config_json'], true) ?: []) : []];
}
function playerConfigAllowed($viewer) {
    $allowed = [];
    foreach ($viewer['mods'] as $mod) foreach ($mod['settings'] as $s) if ($s['overridable']) $allowed[playerConfigKey($s)] = $s;
    return $allowed;
}
function playerConfigValueValid($s, $value) {
    if (!is_string($value) || strlen($value)>4096 || preg_match('/[\r\n\x00]/', $value)) return false;
    if (!empty($s['acceptable']) && !in_array($value, $s['acceptable'], true)) return false;
    $type = strtolower($s['type'] ?? '');
    if ($type === 'boolean' && !in_array(strtolower($value), ['true','false'], true)) return false;
    if (in_array($type, ['int16','int32','int64','uint16','uint32','uint64','single','double','decimal'], true)) {
        if (!is_numeric($value) || !is_finite((float)$value)) return false;
        if (str_contains($type, 'int') && !preg_match('/^-?\d+$/', $value)) return false;
        if (str_starts_with($type, 'uint') && (float)$value < 0) return false;
        $bounds = ['int16'=>[-32768,32767], 'uint16'=>[0,65535], 'int32'=>[-2147483648,2147483647],
            'uint32'=>[0,4294967295], 'single'=>[-3.402823466e38,3.402823466e38]];
        if (isset($bounds[$type]) && ((float)$value<$bounds[$type][0] || (float)$value>$bounds[$type][1])) return false;
        $range = $s['range'] ?? null;
        if (is_array($range)) {
            $min = $range['min'] ?? $range[0] ?? null; $max = $range['max'] ?? $range[1] ?? null;
            if (($min !== null && (float)$value < (float)$min) || ($max !== null && (float)$value > (float)$max)) return false;
        }
    }
    return true;
}
function playerConfigSave($pdo, $world, $player, $input, $viewer) {
    if (!is_array($input) || !is_int($input['revision'] ?? null) || !is_array($input['entries'] ?? null) || count($input['entries'])>500) throw new InvalidArgumentException('Invalid personal settings.');
    $allowed = playerConfigAllowed($viewer);
    $pdo->beginTransaction();
    try {
        // Serialize first saves as well as updates, and reject stale browser edits.
        $st=$pdo->prepare('SELECT id FROM worlds WHERE name=? FOR UPDATE');$st->execute([$world]);$wid=$st->fetchColumn();
        $current=playerConfigRead($pdo,$world,$player);
        if ($input['revision'] !== $current['revision']) throw new RuntimeException('Settings changed in another tab. Reopen the viewer before saving.',409);
        $entries=[];foreach($current['entries'] as $e) if(isset($allowed[playerConfigKey($e)]))$entries[playerConfigKey($e)]=$e;
        foreach($input['entries'] as $e) {
            if(!is_array($e) || !is_string($e['file']??null) || !is_string($e['section']??null) || !is_string($e['key']??null) || !array_key_exists('value',$e)) throw new InvalidArgumentException('Invalid setting.');
            $key=playerConfigKey($e);$s=$allowed[$key]??null;
            if(!$s)throw new InvalidArgumentException('This setting is not customizable. Reopen the viewer.');
            if($e['value']===null){unset($entries[$key]);continue;}
            if(!playerConfigValueValid($s,$e['value']))throw new InvalidArgumentException('Invalid value for '.$e['key'].'.');
            $entries[$key]=['file'=>$e['file'],'section'=>$e['section'],'key'=>$e['key'],'value'=>$e['value']];
        }
        $json=json_encode(array_values($entries), JSON_THROW_ON_ERROR);if(strlen($json)>65536)throw new InvalidArgumentException('Personal settings exceed the size limit.');
        $st=$pdo->prepare('INSERT INTO player_mod_configs (world_id,steam_id,revision,config_json) VALUES (?,?,?,?) ON DUPLICATE KEY UPDATE revision=VALUES(revision),config_json=VALUES(config_json)');
        $st->execute([$wid,$player,$current['revision']+1,$json]);$pdo->commit();
        return ['revision'=>$current['revision']+1,'entries'=>array_values($entries)];
    } catch(Throwable $e) { $pdo->rollBack();throw $e; }
}
