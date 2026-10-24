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
check('merge: missing keys filled from defaults', $doc['author']['url'] === '' && $doc['agent']['turn_timeout_s'] === 900 && $doc['buildpak']['override_url'] === '' && $doc['assets']['max_upload_mb'] === 64);
check('merge: nested section partially given keeps other defaults', $doc['github']['visibility'] === 'private' && $doc['github']['api'] === 'https://api.github.com' && $doc['github']['token'] === 'ghp_abcdefgh1234');
check('merge: an unknown key is kept by the merge (the client filter drops it later)', $doc['legacy_key'] === 'kept');
check('merge: non-array over -> defaults', vibesMergeSettings($defaults, 'junk') === $defaults);
check('merge: every default section present', !array_diff(array_keys($defaults), array_keys($doc)));

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
