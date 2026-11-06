<?php
require_once __DIR__ . '/modcatalog.php';
require_once __DIR__ . '/modconfigs.php';

/** Only rules already published to players may appear here. Never return the admin payload. */
function playerModViewer($pdo, $world) {
    $mods = publicWorldMods($pdo, $world);
    $byId = [];
    foreach ($mods as $i => $mod) { $byId[$mod['id']] = $i; $mods[$i]['settings'] = []; }
    $path = MODCONFIG_WORLDS_ROOT . '/' . $world . '/client/BepInEx/config/.phvalheim-policy.json';
    $policy = is_file($path) ? json_decode(file_get_contents($path), true) : null;
    $published = is_array($policy) && ($policy['version'] ?? null) === 1;
    if ($published) {
        $schema = modConfigEditorPayload($pdo, $world);
        $files = [];
        foreach ($schema['files'] ?? [] as $file) $files[$file['file']] = $file;
        foreach ($policy['files'] ?? [] as $rules) {
            $file = $files[$rules['file']] ?? null;
            if (!$file || !$file['push_client'] || !isset($byId[$file['mod_id']])) continue;
            $i = $byId[$file['mod_id']];
            if (!(int)$mods[$i]['deploy_client']) continue;
            $entries = [];
            foreach ($file['entries'] as $entry) $entries[$entry['section'] . "\x1f" . $entry['key']] = $entry;
            foreach ($rules['entries'] ?? [] as $rule) {
                $entry = $entries[$rule['section'] . "\x1f" . $rule['key']] ?? null;
                if (!$entry || $entry['server_only']) continue;
                $mods[$i]['settings'][] = [
                    'file' => $rules['file'], 'section' => $rule['section'], 'key' => $rule['key'],
                    'value' => $rule['value'],
                    'overridable' => !empty($rule['overridable']) && !empty($entry['overridable']),
                    'type' => $entry['type'], 'description' => $entry['description'],
                    'acceptable' => $entry['acceptable'], 'range' => $entry['range'],
                ];
            }
        }
    }
    return ['world' => $world, 'mods' => $mods, 'configPublished' => $published];
}
