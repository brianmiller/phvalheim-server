<?php
/** Executes the real harness and adapter against a deterministic streaming provider. */
require '/opt/stateless/nginx/www/includes/phvalheim-frontend-config.php';
require '/opt/stateless/nginx/www/includes/vibes.php';
foreach (['env', 'jobs', 'git', 'project', 'build', 'tools', 'agent'] as $lib) {
    require_once "/opt/stateless/engine/tools/vibes/lib/$lib.php";
}
$root = sys_get_temp_dir() . '/forge-oracle-' . getmypid();
mkdir($root, 0700, true);
$providerId = null;
$projectId = null;
$proc = null;
try {
    $listener = stream_socket_server('tcp://127.0.0.1:0', $errno, $error);
    if (!$listener) throw new RuntimeException($error);
    $address = stream_socket_get_name($listener, false);
    fclose($listener);
    file_put_contents("$root/router.php", <<<'FIXTURE'
<?php
$input = json_decode(file_get_contents('php://input'), true);
if (empty($input['stream'])) {
    file_put_contents(__DIR__ . '/summaries', '1', FILE_APPEND);
    header('Content-Type: application/json');
    echo json_encode(['choices' => [['message' => ['content' => 'Preserve the request. Nine read_file checks are in progress; continue and report completion.']]], 'usage' => ['prompt_tokens' => 100, 'completion_tokens' => 20]]);
    exit;
}
$n = (int)@file_get_contents(__DIR__ . '/rounds') + 1;
file_put_contents(__DIR__ . '/rounds', (string)$n);
header('Content-Type: text/event-stream');
$delta = $n <= 9
    ? ['tool_calls' => [['index' => 0, 'id' => 'read_' . $n, 'type' => 'function', 'function' => ['name' => 'read_file', 'arguments' => '{"path":"reference.txt"}']]]]
    : ['content' => 'All nine reads completed. 0 data lost.'];
echo 'data: ' . json_encode(['choices' => [['delta' => $delta, 'finish_reason' => $n <= 9 ? 'tool_calls' : 'stop']], 'usage' => ['prompt_tokens' => 1000, 'completion_tokens' => 50]]) . "\n\n";
echo "data: [DONE]\n\n";
FIXTURE
    );
    $proc = proc_open(['/usr/bin/php', '-S', $address, "$root/router.php"],
        [0 => ['file', '/dev/null', 'r'], 1 => ['file', "$root/http.log", 'a'], 2 => ['file', "$root/http.log", 'a']], $pipes);
    if (!is_resource($proc)) throw new RuntimeException('Fixture provider could not start.');
    usleep(300000);
    $pdo->prepare("INSERT INTO ai_providers(kind,label,base_url,model,enabled,is_default) VALUES ('openai_compatible','Forge oracle',?,'fixture',1,0)")->execute(['http://' . $address]);
    $providerId = (int)$pdo->lastInsertId();
    $created = vibesProjectCreate($pdo, ['name' => 'OracleForge' . getmypid(), 'namespace' => 'Oracle', 'template' => 'basic-plugin']);
    if (empty($created['success'])) throw new RuntimeException($created['error']);
    $projectId = $created['project']['id'];
    $pdo->prepare('UPDATE hv_projects SET provider_id=?, model=? WHERE id=?')->execute([$providerId, 'fixture', $projectId]);
    $project = vibesProjectRow($pdo, $projectId);
    file_put_contents(vibesProjectDir($project['slug']) . '/reference.txt', str_repeat('reference data ', 2000));
    $pdo->prepare('INSERT INTO hv_sessions(project_id,title) VALUES (?,?)')->execute([$projectId, 'Compaction oracle']);
    $sessionId = (int)$pdo->lastInsertId();
    $q = $pdo->prepare('SELECT * FROM hv_sessions WHERE id=?');
    $q->execute([$sessionId]);
    $session = $q->fetch(PDO::FETCH_ASSOC);
    $log = vibesSessionLog($project['slug'], $sessionId);
    $emit = new HvEmitter("$root/job.jsonl", $log);
    $result = forgeTurn($pdo, $session, $project, 'Read reference.txt nine times, then confirm completion.', $emit, fn() => false);
    $events = array_map(fn($line) => json_decode($line, true), file($log, FILE_IGNORE_NEW_LINES));
    $checks = [
        'real adapter and harness complete' => !empty($result['success']),
        'all nine tool calls execute' => count(array_filter($events, fn($e) => $e['type'] === 'tool_result')) === 9,
        'automatic compaction occurs during the turn' => count(array_filter($events, fn($e) => $e['type'] === 'compaction')) >= 1,
        'summary provider was actually called' => is_file("$root/summaries"),
        'latest turn completes after compaction' => count(array_filter($events, fn($e) => $e['type'] === 'assistant' && str_contains($e['text'], '0 data lost'))) === 1,
        'provider usage includes all rounds' => $result['usage']['in'] >= 10000 && $result['usage']['out'] >= 500,
    ];
    foreach ($events as $event) if ($event['type'] === 'context' && $event['tokens'] + $event['reserved'] > $event['budget']) {
        $checks['all requests preserve output headroom'] = false;
    }
    $checks['all requests preserve output headroom'] ??= true;
    foreach ($checks as $name => $ok) echo ($ok ? 'PASS ' : 'FAIL ') . $name . "\n";
    if (in_array(false, $checks, true)) throw new RuntimeException('Forge integration failed: ' . json_encode($result));
} finally {
    if ($projectId) vibesProjectDelete($pdo, $projectId);
    if ($providerId) $pdo->prepare('DELETE FROM ai_providers WHERE id=?')->execute([$providerId]);
    if (is_resource($proc)) { proc_terminate($proc); proc_close($proc); }
    vibesRmTree($root);
}
