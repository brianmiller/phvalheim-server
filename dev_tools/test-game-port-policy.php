<?php
// Pure behavior oracle: php dev_tools/test-game-port-policy.php
require_once __DIR__ . '/../container/nginx/www/includes/gameports.php';
$count = 0;
function ck($label, $condition) {
    global $count;
    if (!$condition) throw new RuntimeException('FAIL: ' . $label);
    echo 'PASS: ' . $label . "\n";
    $count++;
}
function rejects($fn) {
    try { $fn(); return false; } catch (RuntimeException $e) { return true; }
}
$oldStart = getenv('PHVALHEIM_GAME_PORT_START');
$oldEnd = getenv('PHVALHEIM_GAME_PORT_END');
try {
    putenv('PHVALHEIM_GAME_PORT_START'); putenv('PHVALHEIM_GAME_PORT_END');
    ck('ordinary Docker/Unraid installs are unmanaged', phvGamePortPolicy()['enabled'] === false);
    ck('ordinary saved base port passes through', phvManagedSettingsInput(['basePort' => 26000]) === ['basePort' => 26000]);
    foreach ([['', ''], ['31000', ''], ['', '31003'], ['odd', '31003'], ['31001','31004'], ['0','3'], ['65534','65536'], ['31004','31003'], ['31000','31002'], ['1;touch /tmp/bad','3']] as [$start,$end]) {
        putenv('PHVALHEIM_GAME_PORT_START=' . $start); putenv('PHVALHEIM_GAME_PORT_END=' . $end);
        ck('range validation ' . json_encode([$start,$end]), $start === '' && $end === '' ? !phvGamePortPolicy()['enabled'] : phvGamePortPolicy()['error'] !== null);
    }
    putenv('PHVALHEIM_GAME_PORT_START=31000'); putenv('PHVALHEIM_GAME_PORT_END=31003');
    $policy = phvGamePortPolicy();
    ck('valid range recognized', $policy['start'] === 31000 && $policy['end'] === 31003 && !$policy['error']);
    ck('first pair allocated', phvNextManagedGamePort($policy, []) === 31000);
    ck('second pair allocated', phvNextManagedGamePort($policy, [31000]) === 31002);
    ck('deleted pair can be reused', phvNextManagedGamePort($policy, [31002]) === 31000);
    ck('odd legacy port reserves both occupied ports', rejects(fn() => phvNextManagedGamePort($policy, [31001])));
    ck('full range never allocates beyond end', rejects(fn() => phvNextManagedGamePort($policy, [31000,31002])));
    ck('null/unallocated world ports are not zero', phvNextManagedGamePort($policy, [null]) === 31000);
    ck('last valid pair fits', phvGamePortFits($policy, 31002));
    ck('out of range world is detected', !phvGamePortFits($policy, 30998));
    ck('odd existing port is detected', !phvGamePortFits($policy, 31001));
    ck('end cannot be a world base', !phvGamePortFits($policy, 31003));
    ck('matching managed base is omitted from DB writes', phvManagedSettingsInput(['basePort' => 31000, 'gameDNS' => 'fixture.invalid']) === ['gameDNS' => 'fixture.invalid']);
    ck('API cannot override managed base', rejects(fn() => phvManagedSettingsInput(['basePort' => 25000])));
    putenv('PHVALHEIM_GAME_PORT_START=65534'); putenv('PHVALHEIM_GAME_PORT_END=65535');
    ck('last UDP pair supported', phvNextManagedGamePort(phvGamePortPolicy(), []) === 65534);
    ck('last UDP pair exhausts correctly', rejects(fn() => phvNextManagedGamePort(phvGamePortPolicy(), [65534])));
    putenv('PHVALHEIM_GAME_PORT_START'); putenv('PHVALHEIM_GAME_PORT_END');
    ck('removing range restores saved setting', phvManagedSettingsInput(['basePort'=>26000]) === ['basePort'=>26000]);
} finally {
    putenv($oldStart === false ? 'PHVALHEIM_GAME_PORT_START' : 'PHVALHEIM_GAME_PORT_START=' . $oldStart);
    putenv($oldEnd === false ? 'PHVALHEIM_GAME_PORT_END' : 'PHVALHEIM_GAME_PORT_END=' . $oldEnd);
}
echo "$count checks passed\n";
