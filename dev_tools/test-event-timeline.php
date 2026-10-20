<?php
/*
 * Oracle test: the PHP side of the event log (2.56) that needs no database.
 *
 * WHY: the world log view merges two sources -- PhValheim's events and Valheim's stdout -- by
 * time, and reads the Valheim file "from this boot" using an offset the engine recorded. Both
 * are easy to get subtly wrong and both fail silently (a line in the wrong place, or the
 * previous boot's errors shown as this boot's). And the log viewer used to serve ANY path.
 *
 * Pinned:
 *   1. the viewer's file allowlist: plain .log / .log.1 names inside the logs dir, nothing else
 *   2. Valheim line timestamps, and continuation lines inheriting the previous time
 *   3. the merge: ordered by time, events before Valheim lines at equal times, input order kept
 *   4. "this boot": read from the session offset; offset beyond the file (rotated) -> whole file;
 *      a huge file is cut from the END, never the start of this boot's lines
 *   5. the Valheim line level guess, used for colour only
 *
 * Mutation controls break the merge order and the allowlist; each must fail.
 *
 * Usage: php dev_tools/test-event-timeline.php
 */

$SRC = __DIR__ . '/../container/nginx/www/includes/events.php';
$TMP = sys_get_temp_dir() . '/phv-evt-' . getmypid();
@mkdir($TMP, 0777, true);
$child = getenv('PHV_EVT_SRC');
if ($child !== false && $child !== '') { $SRC = $child; }

define('PHV_LOG_DIR', $TMP);
require $SRC;

register_shutdown_function(function () use ($TMP) {
	foreach (glob("$TMP/*") as $f) { @unlink($f); }
	foreach (glob("$TMP/.*") as $f) { if (is_file($f)) @unlink($f); }
	@rmdir($TMP);
});

/* A PDO stand-in for phvValheimSessionOffset(): answers the one SELECT with a detail JSON. */
class FakeStmt { public $v; function __construct($v) { $this->v = $v; } function execute($a = []) { return true; } function fetchColumn() { return $this->v; } }
class FakePdo { public $detail; function __construct($d) { $this->detail = $d; } function prepare($sql) { return new FakeStmt($this->detail); } }

$pass = 0; $fail = 0;
function check($what, $want, $got) {
	global $pass, $fail;
	$w = json_encode($want); $g = json_encode($got);
	if ($w === $g) { $pass++; echo "  PASS  $what\n"; }
	else { $fail++; echo "  FAIL  $what\n          want $w\n          got  $g\n"; }
}

function scenarios() {
	global $TMP;
	$out = [];
	$ev = [['id' => 1, 't' => 100, 'level' => 'notice', 'source' => 'phvalheim', 'op_id' => 'a', 'message' => 'e1', 'detail' => null]];
	$vl = [[100, '10/20/2026 00:01:40: v-same'], [99, '10/20/2026 00:01:39: v-before']];
	$out['merge_kinds'] = array_map(fn($x) => $x['kind'] . ':' . preg_replace('/^.*?: /', '', $x['text']), phvMergeTimeline($ev, $vl));
	file_put_contents("$TMP/x.log", "a\n");
	file_put_contents("$TMP/old.log.2", "a\n");
	@symlink('/etc/hostname', "$TMP/evil.log");
	$out['allow_plain'] = phvLogFileAllowed('x.log');
	$out['allow_second_rotation'] = phvLogFileAllowed('old.log.2');   // the name regex alone refuses it
	$out['allow_symlink_out'] = phvLogFileAllowed('evil.log');        // only the realpath check refuses it
	return $out;
}
if ($child !== false && $child !== '') { echo json_encode(scenarios()), "\n"; exit(0); }

echo "\n-- 1. the viewer's allowlist --\n";
file_put_contents("$TMP/phvalheim.log", "x\n");
file_put_contents("$TMP/phvalheim.log.1", "x\n");
file_put_contents("$TMP/valheimworld_Midgard.log", "x\n");
file_put_contents("$TMP/notes.txt", "x\n");
check('a known log is allowed', true, phvLogFileAllowed('phvalheim.log'));
check('its rotated copy is allowed', true, phvLogFileAllowed('phvalheim.log.1'));
check('a world log is allowed', true, phvLogFileAllowed('valheimworld_Midgard.log'));
check('a .txt in the dir is not', false, phvLogFileAllowed('notes.txt'));
check('a file that does not exist is not', false, phvLogFileAllowed('nothere.log'));
check('dot-dot is refused', false, phvLogFileAllowed('../phvalheim.log'));
check('an absolute path is refused', false, phvLogFileAllowed('/etc/passwd'));
check('a path with a separator is refused even if it resolves inside', false, phvLogFileAllowed('./phvalheim.log'));
check('empty and non-string are refused', [false, false, false], [phvLogFileAllowed(''), phvLogFileAllowed(null), phvLogFileAllowed(['phvalheim.log'])]);
check('a name ending .log.2 is refused (only one rotation is kept)', false, phvLogFileAllowed('phvalheim.log.2'));

echo "\n-- 2. Valheim line times --\n";
$t = phvValheimLineTime('10/20/2026 01:03:05: World saved', null);
check('a stamped line parses (local time)', date('Y-m-d H:i:s', $t), '2026-10-20 01:03:05');
check('a continuation line inherits the previous time', $t, phvValheimLineTime('   at Some.Stack.Frame()', $t));
check('a line with no stamp and no previous time has none', null, phvValheimLineTime('bare', null));

echo "\n-- 3. the merge --\n";
$ev = [
	['id' => 1, 't' => 100, 'level' => 'notice', 'source' => 'phvalheim', 'op_id' => 'op1', 'message' => 'Start command received', 'detail' => null],
	['id' => 2, 't' => 105, 'level' => 'warn',   'source' => 'phvalheim', 'op_id' => 'op1', 'message' => 'slow', 'detail' => null],
];
$vl = [[100, '10/20/2026 00:01:40: Valheim version'], [100, '  continuation'], [103, '10/20/2026 00:01:43: Loading'], [null, 'no time at all']];
$m = phvMergeTimeline($ev, $vl);
check('ordered by time, events first at equal times, input order kept, timeless first',
	['valheim:no time at all', 'event:Start command received', 'valheim:Valheim version', 'valheim:  continuation', 'valheim:Loading', 'event:slow'],
	array_map(fn($x) => $x['kind'] . ':' . preg_replace('/^\d{2}\/\d{2}\/\d{4} \d{2}:\d{2}:\d{2}: /', '', $x['text']), $m));
check('a Valheim row keeps its whole line, stamp included', '10/20/2026 00:01:40: Valheim version', $m[2]['text']);
check('an event row keeps its id, op and level; a Valheim row has none', [[1, 'op1', 'notice'], [null, null, 'info']],
	[[$m[1]['id'], $m[1]['op_id'], $m[1]['level']], [$m[2]['id'], $m[2]['op_id'], $m[2]['level']]]);
check('no helper field leaks out', false, array_key_exists('order', $m[0]));
check('empty in, empty out', [], phvMergeTimeline([], []));

echo "\n-- 4. the Valheim log, this boot --\n";
$path = "$TMP/valheimworld_Midgard.log";
$boot1 = "10/19/2026 20:00:00: Valheim version: 0.219\n10/19/2026 20:00:01: ERROR old boot\n";
$boot2 = "10/20/2026 01:00:00: Valheim version: 0.219\n10/20/2026 01:00:01: World saved\n";
file_put_contents($path, $boot1 . $boot2);
$off = strlen($boot1);
$pdo = new FakePdo(json_encode(['session' => true, 'log_offset' => $off]));
$r = phvValheimLog($pdo, 'Midgard', true);
check('reads from the recorded offset: only this boot', ['Valheim version: 0.219', 'World saved'], array_map(fn($l) => preg_replace('/^.*?: /', '', $l[1]), $r['lines']));
check('  and reports the offset and size', [$off, strlen($boot1 . $boot2), false], [$r['from_offset'], $r['size'], $r['truncated']]);
$r = phvValheimLog($pdo, 'Midgard', false);
check('whole file when asked', 4, count($r['lines']));
$rot = new FakePdo(json_encode(['session' => true, 'log_offset' => 99999]));
$r = phvValheimLog($rot, 'Midgard', true);
check('an offset beyond the file (rotated since) falls back to the whole file', [0, 4], [$r['from_offset'], count($r['lines'])]);
$none = new FakePdo(false);
$r = phvValheimLog($none, 'Midgard', true);
check('no session marker: the whole file', [0, 4], [$r['from_offset'], count($r['lines'])]);
$r = phvValheimLog($pdo, 'Nowhere', true);
check('a world with no log file: exists=false, no lines', [false, []], [$r['exists'], $r['lines']]);
// A big file is cut from the end: the first (partial) line is dropped, the last lines survive.
$big = ''; for ($i = 0; $i < 2000; $i++) { $big .= sprintf("10/20/2026 01:%02d:%02d: line %d\n", intdiv($i, 60) % 60, $i % 60, $i); }
file_put_contents($path, $big);
$r = phvValheimLog($none, 'Midgard', false, 5000);
check('a huge file is cut to the tail, whole lines only', [true, 'line 1999', true],
	[$r['truncated'], preg_replace('/^.*?: /', '', end($r['lines'])[1]), str_starts_with($r['lines'][0][1], '10/20/2026')]);
check('  the cut never trims the START of this boot when the boot fits',
	['Valheim version: 0.219', 2], (function () use ($pdo, $path, $boot1, $boot2) {
		file_put_contents($path, $boot1 . $boot2);
		$r = phvValheimLog($pdo, 'Midgard', true, 5000);
		return [preg_replace('/^.*?: /', '', $r['lines'][0][1]), count($r['lines'])];
	})());

echo "\n-- 5. the Valheim level guess --\n";
check('an exception is error', 'error', phvValheimLineLevel('NullReferenceException: Object reference'));
check('a warning is warn', 'warn', phvValheimLineLevel('Warning: something'));
check('an ordinary line is info', 'info', phvValheimLineLevel('World saved ( 142.1583ms )'));

echo "\n-- mutation controls -- each must break the case it covers --\n";
$base = scenarios();
check('baseline', ['merge_kinds' => ['valheim:v-before', 'event:e1', 'valheim:v-same'], 'allow_plain' => true, 'allow_second_rotation' => false, 'allow_symlink_out' => false], $base);
$mutations = [
	['input order dropped at equal times (events would no longer precede the game\'s line)', "\t\treturn \$a['order'] <=> \$b['order'];", "\t\treturn \$b['order'] <=> \$a['order'];",
	 'merge_kinds', ['valheim:v-before', 'event:e1', 'valheim:v-same']],
	['time ordering dropped', "\t\tif (\$a['t'] !== \$b['t']) { return \$a['t'] <=> \$b['t']; }", "\t\tif (false) { return 0; }", 'merge_kinds', ['valheim:v-before', 'event:e1', 'valheim:v-same']],
	['the name regex dropped (any existing file in the dir would be served)', "if (!preg_match('/^[A-Za-z0-9][A-Za-z0-9_.-]*\\.log(\\.1)?\$/', \$name) || str_contains(\$name, '..')) { return false; }",
	 "if (false) { return false; }", 'allow_second_rotation', false],
	['the realpath check dropped (a symlink out of the dir would be served)', "return \$real !== false && \$dir !== false && str_starts_with(\$real, \$dir . '/') && is_file(\$real);",
	 "return \$real !== false && is_file(\$real);", 'allow_symlink_out', false],
];
$orig = file_get_contents($SRC);
foreach ($mutations as [$label, $find, $repl, $case, $baseline]) {
	if (substr_count($orig, $find) !== 1) { $fail++; echo "  FAIL  mutation applies exactly once: $label (found " . substr_count($orig, $find) . ")\n"; continue; }
	$mut = "$TMP/events_mut.php";
	file_put_contents($mut, str_replace($find, $repl, $orig));
	$got = json_decode((string)shell_exec('PHV_EVT_SRC=' . escapeshellarg($mut) . ' php ' . escapeshellarg(__FILE__) . ' 2>/dev/null'), true);
	if (!is_array($got)) { $fail++; echo "  FAIL  mutation ran: $label\n"; continue; }
	// Broken means: the result DIFFERS from the unbroken baseline for that case.
	check("$label => '$case' changes", true, json_encode($got[$case] ?? null) !== json_encode($baseline));
}

echo "\n$pass passed, $fail failed\n";
exit($fail === 0 ? 0 : 1);
