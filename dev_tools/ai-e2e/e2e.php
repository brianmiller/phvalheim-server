<?php
// End-to-end exercise of the 2.45 AI Helper through the admin HTTP API,
// run from inside the container against 127.0.0.1:8081.

function hit($action, $post = null, $raw = false) {
    $url = "http://127.0.0.1:8081/adminAPI.php?action=$action";
    $c = curl_init($url);
    curl_setopt($c, CURLOPT_RETURNTRANSFER, true);
    if ($post !== null) {
        curl_setopt($c, CURLOPT_POST, true);
        curl_setopt($c, CURLOPT_POSTFIELDS, json_encode($post));
        curl_setopt($c, CURLOPT_HTTPHEADER, ['Content-Type: application/json']);
    }
    $b = curl_exec($c);
    $code = curl_getinfo($c, CURLINFO_HTTP_CODE);
    return $raw ? [$code, $b] : [$code, json_decode($b, true)];
}

$pass = 0; $fail = 0;
function ok($m)  { global $pass; $pass++; echo "  PASS  $m\n"; }
function bad($m) { global $fail; $fail++; echo "  FAIL  $m\n"; }

echo "== 1. wizard test step: WRONG key must surface the provider error ==\n";
list($c, $r) = hit('testAiProvider', [
    'kind' => 'openai_compatible', 'label' => 'Mock',
    'base_url' => 'http://127.0.0.1:8899/v1', 'api_key' => 'wrongkey', 'model' => '',
]);
$disc = $r['steps'][0] ?? [];
if (!$r['success'] && strpos($disc['detail'] ?? '', 'Incorrect API key') !== false) {
    ok('bad key rejected, upstream message shown verbatim: ' . $disc['detail']);
} else {
    bad('bad key not surfaced: ' . json_encode($r));
}

echo "\n== 2. wizard test step: CORRECT key ==\n";
list($c, $r) = hit('testAiProvider', [
    'kind' => 'openai_compatible', 'label' => 'Mock',
    'base_url' => 'http://127.0.0.1:8899/v1', 'api_key' => 'testkey', 'model' => 'mock-small',
]);
foreach (($r['steps'] ?? []) as $s) {
    printf("     %-18s %s  %s\n", $s['name'], $s['ok'] ? 'ok' : 'FAIL', $s['detail']);
}
if ($r['success'] && count($r['models'] ?? []) === 2) ok('all test steps passed, 2 models discovered');
else bad('test failed: ' . json_encode($r));

echo "\n== 3. a model the provider never listed is NOT substituted (issue #83) ==\n";
list($c, $r) = hit('testAiProvider', [
    'kind' => 'openai_compatible', 'label' => 'Mock',
    'base_url' => 'http://127.0.0.1:8899/v1', 'api_key' => 'testkey',
    'model' => 'a-model-we-never-heard-of',
]);
$mstep = null;
foreach (($r['steps'] ?? []) as $s) if ($s['name'] === 'Model') $mstep = $s;
if ($mstep && !empty($mstep['warn']) && strpos($mstep['detail'], 'still be used as entered') !== false) {
    ok('unknown model warned about, not rewritten: ' . $mstep['detail']);
} else {
    bad('unknown model handling wrong: ' . json_encode($mstep));
}

echo "\n== 4. save the provider ==\n";
list($c, $r) = hit('saveAiProvider', [
    'kind' => 'openai_compatible', 'label' => 'Mock Provider',
    'base_url' => 'http://127.0.0.1:8899/v1', 'api_key' => 'testkey',
    'model' => 'mock-small', 'enabled' => 1, 'is_default' => 1,
]);
$pid = $r['id'] ?? 0;
$pid ? ok("saved as provider id $pid") : bad('save failed: ' . json_encode($r));

echo "\n== 5. getAiProviders returns live-discovered models ==\n";
list($c, $r) = hit('getAiProviders');
$mock = null;
foreach (($r['providers'] ?? []) as $p) if ($p['id'] == $pid) $mock = $p;
if ($mock && count($mock['models']) === 2 && $mock['has_key'] === true) {
    ok('models=' . implode(',', array_column($mock['models'], 'id')) . '  has_key=true');
} else { bad('provider listing wrong: ' . json_encode($mock)); }
if ($mock && !isset($mock['api_key'])) ok('the API key is NOT returned to the browser');
else bad('API KEY LEAKED to the client');
// Three, not four: the dedicated Ollama kind was removed and is a preset instead.
if (count($r['kinds'] ?? []) === 3) ok('3 provider kinds offered to the wizard');
else bad('kinds wrong: ' . implode(',', array_keys($r['kinds'] ?? [])));
$oc = $r['kinds']['openai_compatible'] ?? [];
if (count($oc['presets'] ?? []) >= 10) ok(count($oc['presets']) . ' endpoint presets offered (vLLM, Ollama, ...)');
else bad('presets missing from openai_compatible');

echo "\n== 6. diagnostics run with NO model involved ==\n";
list($c, $r) = hit('aiDiagnostics&world=Testworld');
$titles = array_column($r['findings'] ?? [], 'title');
echo "     findings: \n";
foreach (($r['findings'] ?? []) as $f) echo "       [" . $f['severity'] . "] " . $f['title'] . "\n";
$joined = implode(' | ', $titles);
if (strpos($joined, 'mod failed to load') !== false) ok('detected the BepInEx load failure');
else bad('missed the load failure');
if (strpos($joined, 'missing a dependency') !== false) ok('detected the missing dependency');
else bad('missed the missing dependency');
if (strpos($joined, 'port conflict') !== false) ok('detected the port conflict');
else bad('missed the port conflict');
if (strpos(strtolower($joined), 'depthoffield') === false && strpos(strtolower($joined), 'shader') === false) {
    ok('headless noise (DepthOfField shader) correctly ignored');
} else { bad('reported a graphics warning on a headless server'); }

echo "\n== 6b. EVERY tool must actually run ==\n";
// The mock provider only ever calls one tool, so the other nine were never executed by
// any test. get_world_mods had a wrong column name (m.version instead of m.latest_version)
// and returned an error string for its entire life; a live model asking about a world's
// mods is what finally surfaced it. Call them all directly and reject error-shaped output.
$probe = <<<'PHPCODE'
define("AI_LOG_DIR","/opt/stateful/logs");
include "/opt/stateless/nginx/www/includes/config_env_puller.php";
include "/opt/stateless/nginx/www/includes/phvalheim-frontend-config.php";
require "/opt/stateless/nginx/www/includes/aicontext.php";
$calls = [
    ["list_worlds", []],
    ["get_world", ["world" => "Testworld"]],
    ["list_logs", []],
    ["read_log", ["file" => "valheimworld_Testworld.log", "lines" => 5]],
    ["search_log", ["file" => "valheimworld_Testworld.log", "pattern" => "BepInEx"]],
    ["get_world_mods", ["world" => "Testworld"]],
    ["get_mod_sync_status", []],
    ["get_backup_status", []],
    ["get_system_health", []],
    ["get_diagnostics", []],
];
foreach ($calls as $c) {
    $out = aiRunTool($pdo, $c[0], $c[1]);
    // "not available on this install" is how a broken query surfaces; so is an exception.
    $broken = stripos($out, "SQLSTATE") !== false
           || stripos($out, "not available on this install") !== false
           || stripos($out, "failed:") !== false
           || stripos($out, "Unknown tool") !== false;
    printf("%s|%s|%s\n", $c[0], $broken ? "BROKEN" : "ok", str_replace("\n", " ", substr($out, 0, 90)));
}
PHPCODE;
file_put_contents('/tmp/toolprobe.php', "<?php\n" . $probe);
$raw = shell_exec('php /tmp/toolprobe.php 2>&1');
$brokenTools = [];
foreach (explode("\n", trim((string)$raw)) as $line) {
    if (!$line || substr_count($line, '|') < 2) continue;
    list($name, $state, $preview) = explode('|', $line, 3);
    printf("     %-22s %-7s %s\n", $name, $state, substr($preview, 0, 60));
    if ($state === 'BROKEN') $brokenTools[] = $name;
}
if (!$brokenTools && $raw) ok('all 10 tools executed without a query or runtime error');
else bad('tools returning an error: ' . (implode(', ', $brokenTools) ?: 'probe produced no output'));

echo "\n== 7. non-streaming chat: full tool loop ==\n";
list($c, $r) = hit('aiHelper', [
    'message' => 'what is wrong?', 'history' => [], 'world' => 'Testworld',
    'provider_id' => $pid, 'model' => 'mock-small',
]);
if (!empty($r['success']) && !empty($r['trace'])) {
    ok('reply: ' . trim($r['reply']));
    ok('tools actually called: ' . implode(',', array_column($r['trace'], 'tool')));
} else { bad('chat failed: ' . json_encode($r)); }

echo "\n== 8. SSE streaming endpoint ==\n";
$c = curl_init('http://127.0.0.1:8081/aiStream.php');
curl_setopt($c, CURLOPT_RETURNTRANSFER, true);
curl_setopt($c, CURLOPT_POST, true);
curl_setopt($c, CURLOPT_HTTPHEADER, ['Content-Type: application/json']);
curl_setopt($c, CURLOPT_POSTFIELDS, json_encode([
    'message' => 'what is wrong?', 'history' => [], 'world' => 'Testworld',
    'provider_id' => $pid, 'model' => 'mock-small',
]));
$body = curl_exec($c);
$types = []; $text = '';
foreach (explode("\n", $body) as $line) {
    if (strpos($line, 'data:') !== 0) continue;
    $e = json_decode(trim(substr($line, 5)), true);
    if (!is_array($e)) continue;
    $types[] = $e['type'];
    if ($e['type'] === 'delta') $text .= $e['text'];
}
echo "     event types: " . implode(',', $types) . "\n";
if (in_array('tool', $types) && in_array('delta', $types) && in_array('done', $types)) {
    ok('stream carried tool + delta + done');
} else { bad('stream event types wrong'); }
if (trim($text) === 'I read the diagnostics. Here is what I found.') ok('streamed text reassembled exactly');
else bad("streamed text wrong: [$text]");

echo "\n== 9. a disabled/absent provider fails cleanly ==\n";
list($c, $r) = hit('aiHelper', ['message' => 'hi', 'provider_id' => 99999, 'model' => 'x']);
if (empty($r['success']) && !empty($r['error'])) ok('unknown provider: ' . $r['error']);
else bad('unknown provider did not fail cleanly');

echo "\n== 10. delete ==\n";
list($c, $r) = hit('deleteAiProvider', ['id' => $pid]);
list($c, $r2) = hit('getAiProviders');
$still = false;
foreach (($r2['providers'] ?? []) as $p) if ($p['id'] == $pid) $still = true;
(!$still) ? ok('provider deleted') : bad('provider survived deletion');

echo "\n$pass passed, $fail failed\n";
exit($fail > 0 ? 1 : 0);
