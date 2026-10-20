<?php
/*
 * Oracle test: the dashboard's Server Health checks (2.56).
 *
 * WHY: a player reported "No published release is available right now" on Discord and the
 * OPERATOR heard it from the player. The cause was in the PHP error log inside the container;
 * nothing on the dashboard said so. The same silence covered a failed catalogue sync, a failed
 * backup run, an unwritable data volume and a rejected Steam API key -- and the one warning that
 * did exist (backup retention) was shown to players, who cannot act on it.
 *
 * What is pinned here, with every rule a pure function of plain values (no database, no network):
 *   1. each rule's state for each condition it distinguishes, and that 'unknown' is its own
 *      state: a missing input never reads as OK
 *   2. the stale-sync threshold follows settings.modSyncIntervalHours (cron is hourly but the
 *      sync skips until the interval elapses, recording nothing)
 *   3. the Steam probe: cached per key, re-asked after the TTL, NOT re-asked inside the backoff
 *      after a no-answer, re-asked at once when the key changes
 *   4. the data-volume check does a real write (is_writable() lies on read-only mounts)
 *   5. the collector: injected inputs give the expected list, counts match, and every check
 *      has the same record shape
 *   6. the players' page no longer carries the backup-retention warning; the dashboard's API
 *      serves the list; the dashboard asks for it
 *
 * Mutation controls re-run the decisive cases against a copy of health.php with one decision
 * broken; each must fail.
 *
 * Usage: php dev_tools/test-server-health.php
 */

$SRC = __DIR__ . '/../container/nginx/www/includes/health.php';
$WWW = __DIR__ . '/../container/nginx/www';
$TMP = sys_get_temp_dir() . '/phv-health-' . getmypid();
@mkdir($TMP, 0777, true);

$child = getenv('PHV_HEALTH_SRC');
if ($child !== false && $child !== '') { $SRC = $child; }

define('PHV_HEALTH_STEAM_CACHE', "$TMP/steam.json");
define('PHV_HEALTH_STEAM_TTL', 6 * 3600);
define('PHV_HEALTH_STEAM_BACKOFF', 300);
define('PHV_HEALTH_ANALYTICS_RECORD', "$TMP/analytics.json");
define('PHV_RELEASE_CACHE_TTL', 3600);
require $SRC;

register_shutdown_function(function () use ($TMP) {
	@chmod("$TMP/ro", 0755); @rmdir("$TMP/ro");
	foreach (glob("$TMP/*") as $f) { @unlink($f); }
	@rmdir($TMP);
});

$NOW = 1_800_000_000;
$steamCalls = 0;
function steamFetch($code) {
	return function ($url) use ($code) { $GLOBALS['steamCalls']++; return $code; };
}

/* The decisive scenarios, shared by the parent and the mutation children. */
function scenarios() {
	global $NOW, $TMP;
	$out = [];
	$out['sync_stale_at_13h'] = phvHealthRuleModSync('thunderstore',
		['running' => null, 'last' => ['status' => 'ok', 'finished' => date('Y-m-d H:i:s', $NOW - 13 * 3600)]],
		$NOW, phvHealthSyncStaleAfter(6))['state'];
	$out['downloads_via_latest'] = phvHealthRuleClientDownloads(
		['versions' => ['2.0.15'], 'source' => 'releases/latest', '_mtime' => $NOW - 60], $NOW, 3600)['state'];
	@unlink(PHV_HEALTH_STEAM_CACHE); clearstatcache();
	$GLOBALS['steamCalls'] = 0;
	phvHealthSteamProbe('KEY', $NOW, steamFetch(0));
	phvHealthSteamProbe('KEY', $NOW + 60, steamFetch(0));
	$out['steam_calls_inside_backoff'] = $GLOBALS['steamCalls'];
	// A directory that EXISTS but refuses writes (mode 0555). Root ignores mode bits, so this
	// scenario is only meaningful as an ordinary user; the parent skips its control as root.
	$ro = "$TMP/ro"; @mkdir($ro, 0555); @chmod($ro, 0555);
	$out['unwritable_is_fail'] = phvHealthRuleDataVolume(phvHealthDirWritable($ro), $ro)['state'];
	@chmod($ro, 0755);
	$out['empty_retention_is_warn'] = phvHealthRuleBackups('', 'idle', '', false, 10)['state'];
	return $out;
}
if ($child !== false && $child !== '') { echo json_encode(scenarios()), "\n"; exit(0); }

$pass = 0; $fail = 0;
function check($what, $want, $got) {
	global $pass, $fail;
	$w = json_encode($want); $g = json_encode($got);
	if ($w === $g) { $pass++; echo "  PASS  $what\n"; }
	else { $fail++; echo "  FAIL  $what\n          want $w\n          got  $g\n"; }
}
function st($item) { return $item['state']; }

echo "\n-- 1. required settings --\n";
check('both set: ok', 'ok', st(phvHealthRuleSettings('play.example.org', 25000)));
check('DNS missing: fail, names it', ['fail', true], [st($i = phvHealthRuleSettings('', 25000)), str_contains($i['detail'], 'Game DNS')]);
check('  and the action opens settings', 'settings', $i['action']);
check('port missing: fail', 'fail', st(phvHealthRuleSettings('x', '')));

echo "\n-- 2. Steam API key rule --\n";
check('no key: fail', 'fail', st(phvHealthRuleSteamKey('', null, $NOW)));
check('key, not probed yet: unknown (never ok)', 'unknown', st(phvHealthRuleSteamKey('K', null, $NOW)));
check('Steam said 200: ok', 'ok', st(phvHealthRuleSteamKey('K', ['code' => 200, 'at' => $NOW], $NOW)));
check('Steam said 403: fail, since the probe time', ['fail', $NOW - 100],
	[st($i = phvHealthRuleSteamKey('K', ['code' => 403, 'at' => $NOW - 100], $NOW)), $i['since']]);
check('no answer (0): unknown, not fail', 'unknown', st(phvHealthRuleSteamKey('K', ['code' => 0, 'at' => $NOW], $NOW)));
check('a 500 from Steam: unknown', 'unknown', st(phvHealthRuleSteamKey('K', ['code' => 500, 'at' => $NOW], $NOW)));

echo "\n-- 3. Steam probe cache and backoff --\n";
@unlink(PHV_HEALTH_STEAM_CACHE); clearstatcache(); $steamCalls = 0;
$r = phvHealthSteamProbe('KEY-A', $NOW, steamFetch(200));
check('first probe asks Steam, records 200', [1, 200], [$steamCalls, $r['code']]);
$r = phvHealthSteamProbe('KEY-A', $NOW + 3600, steamFetch(200));
check('an hour later: served from cache, no call', [1, 200], [$steamCalls, $r['code']]);
$r = phvHealthSteamProbe('KEY-A', $NOW + PHV_HEALTH_STEAM_TTL + 1, steamFetch(403));
check('after the TTL: asked again (key now rejected)', [2, 403], [$steamCalls, $r['code']]);
$r = phvHealthSteamProbe('KEY-B', $NOW + PHV_HEALTH_STEAM_TTL + 2, steamFetch(200));
check('a CHANGED key is asked at once, not served the old verdict', [3, 200], [$steamCalls, $r['code']]);
@unlink(PHV_HEALTH_STEAM_CACHE); clearstatcache(); $steamCalls = 0;
phvHealthSteamProbe('KEY-C', $NOW, steamFetch(0));
phvHealthSteamProbe('KEY-C', $NOW + 60, steamFetch(0));
check('no answer: not asked again inside the backoff', 1, $steamCalls);
$r = phvHealthSteamProbe('KEY-C', $NOW + PHV_HEALTH_STEAM_BACKOFF + 1, steamFetch(200));
check('  asked again after the backoff, and can succeed', [2, 200], [$steamCalls, $r['code']]);
check('empty key: no probe, no call', [null, 2], [phvHealthSteamProbe('', $NOW, steamFetch(200)), $steamCalls]);
check('the probe URL carries the key and a steamid', true,
	(function () { $u = null; phvHealthSteamProbe('KEY-D', 1, function ($url) use (&$u) { $u = $url; return 200; });
		return str_contains($u, 'key=KEY-D') && str_contains($u, 'steamids=' . PHV_HEALTH_STEAM_PROBE_ID); })());

echo "\n-- 4. client downloads (reads the release cache record from git.php) --\n";
check('no record: unknown', 'unknown', st(phvHealthRuleClientDownloads(null, $NOW, 3600)));
check('fresh, from the API: ok, names the version', ['ok', true],
	[st($i = phvHealthRuleClientDownloads(['versions' => ['2.0.15', '2.0.14'], 'source' => 'api', '_mtime' => $NOW - 120], $NOW, 3600)), str_contains($i['detail'], '2.0.15')]);
check('from releases/latest (API refused): warn, players still served', ['warn', true],
	[st($i = phvHealthRuleClientDownloads(['versions' => ['2.0.15'], 'source' => 'releases/latest', '_mtime' => $NOW - 60], $NOW, 3600)), str_contains($i['detail'], 'still download')]);
check('stale list (older than the TTL): warn', 'warn',
	st(phvHealthRuleClientDownloads(['versions' => ['2.0.13'], 'source' => 'api', '_mtime' => $NOW - 7200], $NOW, 3600)));
check('failed, nothing cached: fail, since failed_at', ['fail', $NOW - 500],
	[st($i = phvHealthRuleClientDownloads(['failed_at' => $NOW - 500, 'slug' => 'x', '_mtime' => $NOW - 500], $NOW, 3600)), $i['since']]);

echo "\n-- 5. data volume: a real write --\n";
check('a writable dir: ok', 'ok', st(phvHealthRuleDataVolume(phvHealthDirWritable($TMP), $TMP)));
check('  and the probe file is not left behind', [], glob("$TMP/.phv-health-*"));
check('an unwritable path: fail', 'fail', st(phvHealthRuleDataVolume(phvHealthDirWritable('/proc/phv-health-nowhere'), '/proc/phv-health-nowhere')));
check('a missing dir: fail', 'fail', st(phvHealthRuleDataVolume(phvHealthDirWritable("$TMP/absent"), "$TMP/absent")));

echo "\n-- 6. mod catalogue sync --\n";
$stale6 = phvHealthSyncStaleAfter(6);
check('stale threshold: 2 x interval + 1h (6h -> 13h)', 13 * 3600, $stale6);
check('  an unset/zero interval uses the default 6h', [13 * 3600, 13 * 3600], [phvHealthSyncStaleAfter(null), phvHealthSyncStaleAfter(0)]);
check('  a 1h interval -> 3h', 3 * 3600, phvHealthSyncStaleAfter(1));
$ago = fn($h) => date('Y-m-d H:i:s', $NOW - $h * 3600);
check('never synced: unknown', 'unknown', st(phvHealthRuleModSync('thunderstore', ['running' => null, 'last' => null], $NOW, $stale6)));
check('running now: ok', 'ok', st(phvHealthRuleModSync('thunderstore', ['running' => ['phase' => 'fetching'], 'last' => null], $NOW, $stale6)));
check('ok 2h ago: ok', 'ok', st(phvHealthRuleModSync('thunderstore', ['running' => null, 'last' => ['status' => 'ok', 'finished' => $ago(2)]], $NOW, $stale6)));
check('unchanged 12h ago (inside 13h): ok', 'ok', st(phvHealthRuleModSync('hexium', ['running' => null, 'last' => ['status' => 'unchanged', 'finished' => $ago(12)]], $NOW, $stale6)));
check('ok 13h ago: warn (stale)', 'warn', st(phvHealthRuleModSync('hexium', ['running' => null, 'last' => ['status' => 'ok', 'finished' => $ago(13)]], $NOW, $stale6)));
check('error: fail, quotes the error, links the log', ['fail', true, 'log:modSync.log'],
	[st($i = phvHealthRuleModSync('thunderstore', ['running' => null, 'last' => ['status' => 'error', 'finished' => $ago(1), 'error' => 'HTTP 503 from thunderstore.io']], $NOW, $stale6)),
	 str_contains($i['detail'], 'HTTP 503 from thunderstore.io'), $i['action']]);
check('stale (interrupted) run: warn', 'warn', st(phvHealthRuleModSync('thunderstore', ['running' => null, 'last' => ['status' => 'stale', 'finished' => $ago(1)]], $NOW, $stale6)));
check('ids are per source', ['mod_sync_thunderstore', 'mod_sync_hexium'],
	[phvHealthRuleModSync('thunderstore', [], $NOW, 1)['id'], phvHealthRuleModSync('hexium', [], $NOW, 1)['id']]);

echo "\n-- 7. backups --\n";
check('retention unset: warn, opens settings', ['warn', 'settings'], [st($i = phvHealthRuleBackups('', 'idle', '', false, 10)), $i['action']]);
check('last run error: fail, links the backup log', ['fail', 'log:worldBackups.log'], [st($i = phvHealthRuleBackups(24, 'error', '2026-10-20 01:00', true, 10)), $i['action']]);
check('volume 90% full: warn', 'warn', st(phvHealthRuleBackups(24, 'idle', '', true, 90)));
check('volume 89% full: ok', 'ok', st(phvHealthRuleBackups(24, 'idle', '', true, 89)));
check('ok, on its own volume: says so', ['ok', true], [st($i = phvHealthRuleBackups(24, 'idle', '2026-10-20 01:00 UTC', true, 10)), str_contains($i['detail'], 'own volume')]);
check('ok, shared with data: says so', true, str_contains(phvHealthRuleBackups(24, 'idle', '', false, null)['detail'], 'data volume'));

echo "\n-- 8. housekeeping and analytics --\n";
check('nothing recorded: unknown', 'unknown', st(phvHealthRuleHousekeeping(null, null)));
check('both idle: ok', 'ok', st(phvHealthRuleHousekeeping('idle', 'idle')));
check('log rotation error: warn naming it', ['warn', true], [st($i = phvHealthRuleHousekeeping('error', 'idle')), str_contains($i['detail'], 'log rotation')]);
check('analytics off: ok', 'ok', st(phvHealthRuleAnalytics(false, null, $NOW)));
check('on, never pushed: unknown', 'unknown', st(phvHealthRuleAnalytics(true, null, $NOW)));
check('on, last push ok: ok', 'ok', st(phvHealthRuleAnalytics(true, ['at' => $NOW - 3600, 'ok' => true, 'http_code' => '200'], $NOW)));
check('on, last push failed: warn (not fail: nothing depends on it)', 'warn', st(phvHealthRuleAnalytics(true, ['at' => $NOW - 3600, 'ok' => false, 'http_code' => '000'], $NOW)));
check('the shell record shape pushAnalytics.sh writes is the one read', 'ok',
	st(phvHealthRuleAnalytics(true, json_decode('{"at":1800000000,"http_code":"200","ok":true}', true), $NOW)));

echo "\n-- 9. the collector with injected inputs (no database) --\n";
$in = [
	'now' => $NOW,
	'settings' => ['gameDNS' => 'play.example.org', 'basePort' => 25000, 'steamApiKey' => 'K', 'backupsToKeep' => 24, 'analyticsEnabled' => 0, 'modSyncIntervalHours' => 6],
	'steamProbe' => ['code' => 200, 'at' => $NOW],
	'releaseCache' => ['versions' => ['2.0.15'], 'source' => 'api', '_mtime' => $NOW],
	'dataWritable' => true, 'dataPath' => '/opt/stateful',
	'syncRuns' => ['thunderstore' => ['running' => null, 'last' => ['status' => 'ok', 'finished' => $ago(1)]],
	               'hexium' => ['running' => null, 'last' => ['status' => 'error', 'finished' => $ago(1), 'error' => 'boom']]],
	'backup' => ['status' => 'idle', 'lastRun' => '', 'mounted' => false, 'perc' => 10],
	'housekeeping' => ['logRotate' => 'idle', 'utilization' => 'idle'],
	'analyticsRecord' => null,
];
$h = phvServerHealth(null, $in);
check('nine checks, in a fixed order', ['settings', 'steam_key', 'client_downloads', 'data_volume', 'mod_sync_thunderstore', 'mod_sync_hexium', 'backups', 'housekeeping', 'analytics'],
	array_column($h['checks'], 'id'));
check('counts match the states', ['ok' => 8, 'warn' => 0, 'fail' => 1, 'unknown' => 0], $h['counts']);
check('every check has the same record shape', true,
	count(array_filter($h['checks'], fn($c) => array_keys($c) !== ['id', 'name', 'state', 'detail', 'action', 'since'])) === 0);
check('checked_at is the clock it was given', $NOW, $h['checked_at']);
$in2 = $in; $in2['settings']['steamApiKey'] = ''; $in2['steamProbe'] = null; $in2['dataWritable'] = false; $in2['releaseCache'] = null;
$h2 = phvServerHealth(null, $in2);
check('a missing key, an unwritable volume and no release record: 2 fail + the hexium fail, 1 unknown',
	['fail' => 3, 'unknown' => 1], ['fail' => $h2['counts']['fail'], 'unknown' => $h2['counts']['unknown']]);
check('an unknown state is never counted as ok', 5, $h2['counts']['ok']);

echo "\n-- 10. wiring --\n";
$pub = file_get_contents("$WWW/public/authenticated.php");
check('the players\' page no longer warns about backup retention', 0, substr_count($pub, 'Backup retention is not configured'));
$api = file_get_contents("$WWW/admin/adminAPI.php");
check('adminAPI serves getServerHealth via phvServerHealth', [1, 1], [substr_count($api, "case 'getServerHealth':"), substr_count($api, 'phvServerHealth($pdo')]);
check('  and loads health.php and git.php (the release cache)', [1, 1],
	[substr_count($api, "require_once '/opt/stateless/nginx/www/includes/health.php';"), substr_count($api, "require_once '/opt/stateless/nginx/www/includes/git.php';")]);
$idx = file_get_contents("$WWW/admin/index.php");
check('the dashboard has the strip, the modal, the sidebar item, and asks on load', [1, 1, 1, 1],
	[substr_count($idx, 'id="serverHealthStrip"'), substr_count($idx, 'id="serverHealthOverlay"'),
	 substr_count($idx, 'showServerHealthModal(); return false;'), substr_count($idx, "        fetchServerHealth();\n")]);
check('  the old critical-config banner is gone (it is the first health check now)', 0, substr_count($idx, 'id="criticalConfigBanner"'));
check('  detail text is set with textContent, never innerHTML', [true, 0],
	[substr_count($idx, 'detail.textContent = c.detail;') === 1, substr_count($idx, 'innerHTML = c.detail')]);
check('  the log action only opens a plain file name', 1, substr_count($idx, "if (!/^[A-Za-z0-9_.-]+$/.test(file)) return null;"));
$sh = file_get_contents(__DIR__ . '/../container/engine/tools/pushAnalytics.sh');
check('pushAnalytics.sh records its outcome where the check reads it', [1, 1],
	[substr_count($sh, 'record_file="/opt/stateful/cache/analyticsPush.json"'), substr_count($sh, '"ok":%s')]);
check('  the record path is the one health.php defines', 1, substr_count(file_get_contents($SRC), "'/opt/stateful/cache/analyticsPush.json'"));

echo "\n-- mutation controls -- each must break the case it covers --\n";
$base = scenarios();
$isRoot = function_exists('posix_geteuid') ? posix_geteuid() === 0 : (trim((string)shell_exec('id -u')) === '0');
check('baseline: stale=warn, latest=warn, 1 steam call, unwritable=fail (as a user), no-retention=warn',
	['sync_stale_at_13h' => 'warn', 'downloads_via_latest' => 'warn', 'steam_calls_inside_backoff' => 1, 'unwritable_is_fail' => $isRoot ? 'ok' : 'fail', 'empty_retention_is_warn' => 'warn'], $base);
$mutations = [
	['stale sync never warns', "\tif (\$finished > 0 && (\$now - \$finished) >= \$stale) {", "\tif (false) {", 'sync_stale_at_13h', 'ok'],
	['releases/latest reads as a clean API answer', "\tif (\$source === 'releases/latest') {", "\tif (false) {", 'downloads_via_latest', 'ok'],
	['no Steam backoff: a dead network is asked on every load',
	 "\t\tif ((\$answered && \$age < PHV_HEALTH_STEAM_TTL) || (!\$answered && \$age < PHV_HEALTH_STEAM_BACKOFF)) {",
	 "\t\tif (\$answered && \$age < PHV_HEALTH_STEAM_TTL) {", 'steam_calls_inside_backoff', 2],
	['assume writable (no real write)', "\t\$ok = @file_put_contents(\$probe, '1') !== false;", "\t\$ok = true;", 'unwritable_is_fail', 'ok'],
	['missing retention reads as fine', "\tif (empty(\$retention)) {", "\tif (false) {", 'empty_retention_is_warn', 'ok'],
];
$orig = file_get_contents($SRC);
foreach ($mutations as [$label, $find, $repl, $case, $broken]) {
	if ($case === 'unwritable_is_fail' && $isRoot) {
		echo "  SKIP  $label: running as root, which ignores directory modes; run as an ordinary user to exercise it\n"; continue;
	}
	if (substr_count($orig, $find) !== 1) {
		$fail++; echo "  FAIL  mutation applies exactly once: $label (found " . substr_count($orig, $find) . ")\n"; continue;
	}
	$mut = "$TMP/health_mut.php";
	file_put_contents($mut, str_replace($find, $repl, $orig));
	$got = json_decode((string)shell_exec('PHV_HEALTH_SRC=' . escapeshellarg($mut) . ' php ' . escapeshellarg(__FILE__) . ' 2>/dev/null'), true);
	if (!is_array($got)) { $fail++; echo "  FAIL  mutation ran: $label\n"; continue; }
	check("$label => '$case' breaks", $broken, $got[$case] ?? null);
}

echo "\n$pass passed, $fail failed\n";
exit($fail === 0 ? 0 : 1);
