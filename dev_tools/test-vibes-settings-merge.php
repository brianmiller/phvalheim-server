<?php
# Oracle: the vibesSettings document (includes/vibes.php): an OLD document merged over NEW
# defaults, a masked token meaning "unchanged", an empty token meaning "clear it", unknown
# top-level keys dropped, and the validation sentences.
#
# Why: the settings live in ONE JSON column read by vibesSettingsGet(); a server upgrade adds keys
# the stored document has never seen, and the UI round-trips tokens masked. If either path is
# wrong the operator either loses a token on every Save or silently keeps a wrong value.
#
# Usage: php dev_tools/test-vibes-settings-merge.php

require __DIR__ . '/vibes-test-lib.php';

$defaults = vibesSettingsDefaults();

# 1. An old document (schema 1, pre-override_url, no 'assets' section, extra unknown key) over today's defaults.
$old = ['schema' => 1, 'author' => ['namespace' => 'MyServer', 'display' => 'Brian'], 'agent' => ['max_rounds' => 12],
        'buildpak' => ['repo' => 'me/pak'], 'github' => ['enabled' => true, 'token' => 'ghp_abcdefgh1234', 'owner' => 'me'], 'legacy_key' => 'kept'];
$doc = vibesMergeSettings($defaults, $old);
check('merge: old values win', $doc['author']['namespace'] === 'MyServer' && $doc['agent']['max_rounds'] === 12 && $doc['buildpak']['repo'] === 'me/pak');
check('merge: missing keys filled from defaults', $doc['author']['url'] === '' && $doc['agent']['turn_timeout_s'] === 3600 && $doc['buildpak']['override_url'] === '' && $doc['assets']['max_upload_mb'] === 64);
check('merge: nested section partially given keeps other defaults', $doc['github']['visibility'] === 'private' && $doc['github']['api'] === 'https://api.github.com' && $doc['github']['token'] === 'ghp_abcdefgh1234');
check('merge: an unknown key is kept by the merge (the client filter drops it later)', $doc['legacy_key'] === 'kept');
check('merge: non-array over -> defaults', vibesMergeSettings($defaults, 'junk') === $defaults);
check('merge: every default section present', !array_diff(array_keys($defaults), array_keys($doc)));

check('2.62 default: 300 rounds, 60-minute turn, 15-minute response', $defaults['agent']['max_rounds'] === 300 && $defaults['agent']['turn_timeout_s'] === 3600 && $defaults['agent']['model_timeout_s'] === 900);
check('merge alone never rewrites a stored value', vibesMergeSettings($defaults, ['agent' => ['max_rounds' => 40]])['agent']['max_rounds'] === 40);

# 1b. Upgrade on read (2.62). Every save persists the whole document, so the old limits are
# written down on any install that ever saved a setting. The numbers PhValheim itself supplied
# follow the new default; a number the operator typed does not. $up() is what vibesSettingsGet()
# does with the stored column.
$up = fn($stored) => vibesSettingsUpgrade(vibesMergeSettings($defaults, $stored), $stored)['agent'];
foreach ([40, 100, 200] as $stock) check("upgrade: stock $stock rounds follows the new default", $up(['schema' => 1, 'agent' => ['max_rounds' => $stock]])['max_rounds'] === 300);
foreach ([1, 7, 39, 60, 150, 199] as $own) check("upgrade: the operator's own $own rounds is kept", $up(['schema' => 1, 'agent' => ['max_rounds' => $own]])['max_rounds'] === $own);
foreach ([900, 1800] as $stock) check("upgrade: stock {$stock}s turn follows the new default", $up(['schema' => 1, 'agent' => ['turn_timeout_s' => $stock]])['turn_timeout_s'] === 3600);
foreach ([61, 600, 3600, 7200] as $own) check("upgrade: the operator's own {$own}s turn is kept", $up(['schema' => 1, 'agent' => ['turn_timeout_s' => $own]])['turn_timeout_s'] === $own);
check('upgrade: the response limit is never touched', $up(['schema' => 1, 'agent' => ['max_rounds' => 100, 'model_timeout_s' => 900]])['model_timeout_s'] === 900);
check('upgrade: a document with no schema key is an old one', $up(['agent' => ['max_rounds' => 100]])['max_rounds'] === 300);
check('upgrade: once saved under 2.62, 100 rounds is a choice and stays', $up(['schema' => 2, 'agent' => ['max_rounds' => 100, 'turn_timeout_s' => 900]]) === ['max_rounds' => 100, 'turn_timeout_s' => 900] + $defaults['agent']);
check('upgrade: no stored document reads as the defaults', vibesSettingsUpgrade($defaults, []) === $defaults);
check('upgrade: the upgraded document is marked current', vibesSettingsUpgrade(vibesMergeSettings($defaults, ['schema' => 1]), ['schema' => 1])['schema'] === 2);
check('upgrade: idempotent', $up(['schema' => 1, 'agent' => $up(['schema' => 1, 'agent' => ['max_rounds' => 200, 'turn_timeout_s' => 1800]])])['max_rounds'] === 300);
foreach (['agent.max_rounds' => [[1, 300, 10000], [0, 10001]], 'agent.turn_timeout_s' => [[30, 86400], [29, 86401]], 'agent.model_timeout_s' => [[30, 7200], [29, 7201]]] as $path => [$good, $refused]) {
	[$section, $key] = explode('.', $path);
	foreach ($good as $v)    { [, $errs] = vibesSettingsApplyClient($defaults, [$section => [$key => $v]]); check("limits: $path accepts $v", $errs === []); }
	foreach ($refused as $v) { [, $errs] = vibesSettingsApplyClient($defaults, [$section => [$key => $v]]); check("limits: $path refuses $v", count($errs) === 1 && str_contains($errs[0], $path)); }
}

# 2. Masking for the client
$client = vibesSettingsForClient($doc);
check('client: token masked to •••• + last 4', $client['github']['token'] === VIBES_SECRET_MASK . '1234');
check('client: empty token stays empty', $client['gitlab']['token'] === '');
check('client: nothing else changed', $client['author'] === $doc['author']);

# 3. Applying a client document
$stored = $doc;
[$new, $errors] = vibesSettingsApplyClient($stored, ['github' => ['token' => VIBES_SECRET_MASK . '1234', 'owner' => 'org'], 'author' => ['display' => 'B'], 'junk' => 1]);
check('apply: masked token = unchanged', $new['github']['token'] === 'ghp_abcdefgh1234' && $errors === [], implode(' ', $errors));
check('apply: other fields updated', $new['github']['owner'] === 'org' && $new['author']['display'] === 'B');
check('apply: unknown top-level key dropped', !isset($new['junk']));
check('apply: untouched sections kept', $new['agent']['max_rounds'] === 12);
[$new, $errors] = vibesSettingsApplyClient($stored, ['github' => ['token' => '']]);
check('apply: empty token clears it', $new['github']['token'] === '' && $errors === []);
[$new, $errors] = vibesSettingsApplyClient($stored, ['github' => ['token' => 'ghp_newtoken9999']]);
check('apply: a new token replaces', $new['github']['token'] === 'ghp_newtoken9999');
[$new, $errors] = vibesSettingsApplyClient($stored, ['gitlab' => ['token' => VIBES_SECRET_MASK . 'zzzz']]);
check('apply: masked value for a token that was empty -> stays empty (no phantom secret)', $new['gitlab']['token'] === '');
[$new, $errors] = vibesSettingsApplyClient($stored, 'not an object');
check('apply: non-object refused with a sentence', $errors === ['Settings must be an object.'] && $new === $stored);

# 4. Validation sentences
$bad = ['author' => ['namespace' => 'My Server'], 'agent' => ['max_rounds' => 0, 'turn_timeout_s' => 10], 'defaults' => ['configuration' => 'Fast'],
        'advanced' => ['dotnet_verbosity' => 'loud', 'log_level' => 'trace'], 'github' => ['visibility' => 'secret', 'api' => 'http://insecure'],
        'gitlab' => ['host' => 'gitlab.example'], 'buildpak' => ['repo' => 'nope', 'override_url' => '/opt/stateful/x.tar.gz']];
[$new, $errors] = vibesSettingsApplyClient($stored, $bad);
$want = ['namespace', 'agent.max_rounds', 'agent.turn_timeout_s', 'Configuration', 'dotnet verbosity', 'Log level', 'github.visibility', 'github.api', 'gitlab.host', 'owner/repo', 'override'];
foreach ($want as $w) check("validate: complains about $w", (bool)preg_grep('/' . preg_quote($w, '/') . '/i', $errors), implode(' | ', $errors));
check('validate: ' . count($want) . ' distinct complaints', count($errors) === count($want), count($errors) . ' errors');
[$new, $errors] = vibesSettingsApplyClient($stored, ['buildpak' => ['override_url' => 'file:///opt/stateful/vibes/downloads/pak.tar.gz']]);
check('validate: file:///opt/stateful override accepted', $errors === []);
[$new, $errors] = vibesSettingsApplyClient($stored, ['buildpak' => ['override_url' => 'file:///tmp/pak.tar.gz']]);
check('validate: file:///tmp override refused', count($errors) === 1);

# 5. Dotted reads
check('vibesSetting: dotted path and default', vibesSetting($doc, 'agent.max_rounds') === 12 && vibesSetting($doc, 'agent.nope', 7) === 7 && vibesSetting($doc, 'x.y.z', 'd') === 'd');

vibesTestDone();
