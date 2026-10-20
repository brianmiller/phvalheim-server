<?php
/**
 * Oracle test: the event log (2.56) against the real database.
 *
 * requires: container
 *
 * Run INSIDE a container built from this tree:
 *   docker cp dev_tools/test-event-log-live.php <c>:/tmp/t.php
 *   docker exec <c> php /tmp/t.php
 *
 * WHY: dev_tools/test-event-log.sh pins the bash writer against a stub and
 * test-event-timeline.php pins the pure PHP; this pins that the rows the bash helper writes
 * are the rows PHP reads, through the real tables, with the real engine conf:
 *   - phvlog from bash lands as a row PHP sees, with world, op, source and level
 *   - operations open, close and count their events; open ones show on the dashboard poll
 *   - the operator's world commands (db_sets) and Hugin's source tag are recorded
 *   - the summary counts unseen warn/error, and ack moves the mark (never backwards)
 *   - the viewer allowlist refuses traversal over HTTP; a world log is served
 *   - the world timeline endpoint merges and honours this-boot
 *   - the recent-errors health check reads the same rows
 *
 * Writes only rows it can delete (a scratch world name) and cleans up, pass or fail.
 */

require '/opt/stateless/nginx/www/includes/config_env_puller.php';
require '/opt/stateless/nginx/www/includes/phvalheim-frontend-config.php';
require_once '/opt/stateless/nginx/www/includes/db_gets.php';
require_once '/opt/stateless/nginx/www/includes/db_sets.php';
require_once '/opt/stateless/nginx/www/includes/events.php';
require_once '/opt/stateless/nginx/www/includes/health.php';

$pass = 0; $fail = 0;
function check($name, $want, $got) {
    global $pass, $fail;
    $w = json_encode($want); $g = json_encode($got);
    if ($w === $g) { $pass++; echo "  PASS  $name\n"; }
    else { $fail++; echo "  FAIL  $name\n          want $w\n          got  $g\n"; }
}
function http($path, $post = null) {
    $ctx = stream_context_create(['http' => ['timeout' => 30, 'ignore_errors' => true, 'method' => $post === null ? 'GET' : 'POST',
        'header' => "Content-Type: application/json\r\n", 'content' => $post === null ? '' : json_encode($post)]]);
    $body = file_get_contents('http://127.0.0.1:8081/' . $path, false, $ctx);
    preg_match('/ (\d{3}) /', $http_response_header[0] ?? ' 000 ', $m);
    return [(int)$m[1], $body];
}
function sh($cmd) { return trim((string)shell_exec($cmd . ' 2>&1')); }

$W = 'zevtlive';
$cleanup = function () use ($pdo, $W) {
    $pdo->prepare("DELETE FROM phv_events WHERE world = ?")->execute([$W]);
    $pdo->prepare("DELETE FROM phv_operations WHERE world = ?")->execute([$W]);
    $pdo->prepare("DELETE FROM worlds WHERE name = ?")->execute([$W]);
    @unlink("/opt/stateful/logs/valheimworld_$W.log");
};
$cleanup();
register_shutdown_function($cleanup);
$seen0 = (int)$pdo->query("SELECT logsSeenEventId FROM settings LIMIT 1")->fetchColumn();
$max0 = (int)$pdo->query("SELECT IFNULL(MAX(id),0) FROM phv_events")->fetchColumn();

echo "\nbash writer -> PHP reader\n";
$conf = '/opt/stateless/engine/includes/phvalheim-static.conf';
sh("bash -c 'source $conf; export PHV_WORLD=$W; export PHV_LOG_SOURCE=updateApplier; phvOpBegin update; phvlog NOTICE \"step one\"; phvlog WARN \"it is late\"; phvOpEnd ok \"all good\"; phvlog ERROR \"outside the op, quote: it'\"'\"'s\"'");
$ev = phvEvents($pdo, ['world' => $W]);
check('three rows, in order, for the world', ['step one', 'it is late', "outside the op, quote: it's"], array_column($ev, 'message'));
check('levels mapped', ['notice', 'warn', 'error'], array_column($ev, 'level'));
check('source is the tool', ['updateApplier'], array_values(array_unique(array_column($ev, 'source'))));
check('the first two carry the op, the third none', [true, true, null], [$ev[0]['op_id'] !== null, $ev[1]['op_id'] === $ev[0]['op_id'], $ev[2]['op_id']]);
$ops = phvOperations($pdo, $W);
check('one operation: update, ok, 2 events, 1 warn', ['update', 'ok', 'all good', 2, 1, false],
    [$ops[0]['kind'], $ops[0]['outcome'], $ops[0]['summary'], $ops[0]['event_count'], $ops[0]['error_count'], $ops[0]['open']]);
$op = phvOperation($pdo, $ops[0]['op_id']);
check('phvOperation returns its events', ['step one', 'it is late'], array_column($op['events'], 'message'));

echo "\nopen operations reach the dashboard poll\n";
sh("bash -c 'source $conf; export PHV_WORLD=$W; phvOpBegin start; phvlog NOTICE \"starting\"'");
$open = phvOpenOperations($pdo);
check('the open start shows for the world', 'start', $open[$W]['kind'] ?? null);
[$code, $body] = http('adminAPI.php?action=getWorlds');
$j = json_decode($body, true);
check('getWorlds carries events.open_ops', 'start', $j['events']['open_ops'][$W]['kind'] ?? null);
$pdo->prepare("UPDATE phv_operations SET finished=NOW(3), outcome='ok' WHERE world = ? AND finished IS NULL")->execute([$W]);

echo "\nthe operator's commands and Hugin's tag\n";
// No worlds row on purpose: the engine is live in this container and would act on a real one.
// The command functions record their event before the UPDATE, so the row is not needed.
startWorld($pdo, $W);
$ev = phvEvents($pdo, ['world' => $W, 'source' => 'admin']);
check('startWorld records an admin event', ["Start requested for world '$W'"], array_column($ev, 'message'));
phvEventSource('hugin');
stopWorld($pdo, $W);
phvEventSource('admin');
$ev = phvEvents($pdo, ['world' => $W, 'source' => 'hugin']);
check('the same command under Hugin is tagged hugin', ["Stop requested for world '$W'"], array_column($ev, 'message'));

echo "\nsummary and ack\n";
$s = phvEventsSummary($pdo);
check('unseen counts include this test\'s warn and error', [true, true], [$s['unseen_warn'] >= 1, $s['unseen_error'] >= 1]);
[$code, $body] = http('adminAPI.php?action=ackEvents', ['id' => $s['max_id']]);
$j = json_decode($body, true);
// The engine is live, so a warning may land between the two calls: what is still unseen must be
// exactly what was written AFTER the acked id, nothing before it.
$after = $pdo->prepare("SELECT level, COUNT(*) FROM phv_events WHERE id > ? AND level IN ('warn','error') GROUP BY level");
$after->execute([$s['max_id']]); $cnt = ['warn' => 0, 'error' => 0]; foreach ($after->fetchAll(PDO::FETCH_NUM) as $r) { $cnt[$r[0]] = (int)$r[1]; }
check('ack over HTTP: only events after the acked id remain unseen', [200, $cnt['warn'], $cnt['error']], [$code, $j['unseen_warn'] ?? -1, $j['unseen_error'] ?? -1]);
phvEventsAck($pdo, 1);
check('ack never moves backwards', $s['max_id'], (int)$pdo->query("SELECT logsSeenEventId FROM settings LIMIT 1")->fetchColumn());
$pdo->prepare("UPDATE settings SET logsSeenEventId = ?")->execute([$seen0]);

echo "\nthe viewer and the world timeline over HTTP\n";
[$code] = http('readLog.php?logfile=' . rawurlencode('../../etc/passwd'));
check('traversal is refused with 400', 400, $code);
[$code] = http('readLog.php?logfile=phvalheim.log');
check('the engine log is served', 200, $code);
file_put_contents("/opt/stateful/logs/valheimworld_$W.log", "10/19/2026 20:00:00: old boot\n");
sh("bash -c 'source $conf; phvWorldSessionMark $W'");
file_put_contents("/opt/stateful/logs/valheimworld_$W.log", "10/20/2026 01:00:00: new boot line\n", FILE_APPEND);
[$code, $body] = http('adminAPI.php?action=getWorldTimeline&world=' . $W . '&mode=both');
$j = json_decode($body, true);
$texts = array_map(fn($x) => $x['kind'] . ':' . preg_replace('/^.*?: /', '', $x['text']), $j['timeline'] ?? []);
check('this boot: the old boot line is not shown, the new one is, events are merged',
    [false, true, true], [in_array('valheim:old boot', $texts), in_array('valheim:new boot line', $texts), in_array('event:step one', $texts)]);
[$code, $body] = http('adminAPI.php?action=getWorldTimeline&world=' . $W . '&mode=valheim&boot=0');
$j = json_decode($body, true);
check('whole file on request, Valheim only', [2, 0], [count($j['timeline']), count(array_filter($j['timeline'], fn($x) => $x['kind'] === 'event'))]);
[$code, $body] = http('adminAPI.php?action=getLogFiles');
$j = json_decode($body, true);
check('getLogFiles lists the world log with its world', $W, array_values(array_filter($j['files'], fn($f) => $f['name'] === "valheimworld_$W.log"))[0]['world'] ?? null);

echo "\nhealth\n";
$r = phvRecentErrors($pdo);
$mine = phvEvents($pdo, ['world' => $W, 'min_level' => 'error']);
check('recent errors counts the error logged above (and has a latest)', [true, true], [$r['count'] >= count($mine) && count($mine) === 1, !empty($r['last']['message'])]);
$h = phvServerHealth($pdo, ['steamProbe' => null, 'releaseCache' => null, 'dataWritable' => true, 'syncRuns' => [], 'backup' => [], 'housekeeping' => [], 'analyticsRecord' => null, 'settings' => ['analyticsEnabled' => 0]]);
$re = array_values(array_filter($h['checks'], fn($c) => $c['id'] === 'recent_errors'))[0] ?? null;
check('the health check is warn with the logs action', ['warn', 'logs'], [$re['state'] ?? null, $re['action'] ?? null]);

echo "\n$pass passed, $fail failed\n";
exit($fail === 0 ? 0 : 1);
