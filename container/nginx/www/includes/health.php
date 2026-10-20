<?php
/*
 * Server health (2.56): one list of checks for the operator.
 *
 * WHY: a player reported "No published release is available right now" on Discord, and the
 * operator heard it from the player. PhValheim knew -- it had written the cause to the PHP error
 * log inside the container -- but nothing on the admin dashboard said so. The same was true of a
 * failed catalogue sync, a failed backup run, a data volume PHP cannot write to, and a Steam API
 * key Steam no longer accepts. Each of those was knowable and each was silent, or worse, shown to
 * the wrong audience (the backup-retention warning was on the PLAYERS' page).
 *
 * SHAPE: every check is the same record, so adding one is adding a rule function, not a UI:
 *   id, name, state (ok | warn | fail | unknown), detail (a plain cause), action (what to do),
 *   since (unix time the condition has held, when known).
 * 'unknown' is its own state. A check that could not be made says so; it never reads as OK.
 *
 * RULES are pure functions of plain values (phvHealthRule*) so the oracle test pins each state
 * without a database or the network. phvServerHealth() gathers the real inputs and applies them.
 * Nothing here is a heartbeat: the gathering happens when the dashboard asks (every minute,
 * asynchronously), and the two network probes are cached with a backoff so a dead network costs
 * one wait per five minutes, never one per page load.
 */

if (!defined('PHV_HEALTH_DISK_WARN'))     { define('PHV_HEALTH_DISK_WARN', 90); }          // percent used
if (!defined('PHV_HEALTH_STEAM_CACHE'))   { define('PHV_HEALTH_STEAM_CACHE', '/opt/stateful/cache/steamKeyCheck.json'); }
if (!defined('PHV_HEALTH_STEAM_TTL'))     { define('PHV_HEALTH_STEAM_TTL', 6 * 3600); }
if (!defined('PHV_HEALTH_STEAM_BACKOFF')) { define('PHV_HEALTH_STEAM_BACKOFF', 300); }
if (!defined('PHV_HEALTH_ANALYTICS_RECORD')) { define('PHV_HEALTH_ANALYTICS_RECORD', '/opt/stateful/cache/analyticsPush.json'); }
// Any id will do: Steam answers 200 with an empty player list for an id it does not know, and
// 403 for a key it does not accept. Only the HTTP status is read; no player data is kept.
if (!defined('PHV_HEALTH_STEAM_PROBE_ID')) { define('PHV_HEALTH_STEAM_PROBE_ID', '76561197960265728'); }

function phvHealthItem($id, $name, $state, $detail, $action = '', $since = null) {
	if (!in_array($state, ['ok', 'warn', 'fail', 'unknown'], true)) {
		$state = 'unknown';
	}
	return [
		'id'     => $id,
		'name'   => $name,
		'state'  => $state,
		'detail' => $detail,
		'action' => $action,   // '' | 'settings' | 'log:<file>' -- the dashboard maps these to a button
		'since'  => $since === null ? null : (int)$since,
	];
}

/* "3 minutes ago", "2 hours ago", "4 days ago" -- for the detail text. */
function phvHealthAgo($ts, $now) {
	$d = max(0, (int)$now - (int)$ts);
	if ($d < 60)     { return 'just now'; }
	if ($d < 3600)   { $n = intdiv($d, 60);    return $n . ' minute' . ($n == 1 ? '' : 's') . ' ago'; }
	if ($d < 86400)  { $n = intdiv($d, 3600);  return $n . ' hour' . ($n == 1 ? '' : 's') . ' ago'; }
	$n = intdiv($d, 86400); return $n . ' day' . ($n == 1 ? '' : 's') . ' ago';
}

/* ---------------------------------------------------------------------------------------------
 * The rules. Plain values in, one item out.
 * ------------------------------------------------------------------------------------------- */

function phvHealthRuleSettings($gameDNS, $basePort) {
	$missing = [];
	if (empty($gameDNS))  { $missing[] = 'Game DNS'; }
	if (empty($basePort)) { $missing[] = 'Base Port'; }
	if ($missing) {
		return phvHealthItem('settings', 'Required settings', 'fail',
			'Not configured: ' . implode(', ', $missing) . '. Players cannot be given a server address until these are set.',
			'settings');
	}
	return phvHealthItem('settings', 'Required settings', 'ok', 'Game DNS and Base Port are set.');
}

/* $probe: null (not probed) or ['code' => int, 'at' => unixtime]. */
function phvHealthRuleSteamKey($key, $probe, $now) {
	if (empty($key)) {
		return phvHealthItem('steam_key', 'Steam API key', 'fail',
			'Not set. Players cannot sign in to the public page without it.', 'settings');
	}
	if (!is_array($probe) || !isset($probe['code'])) {
		return phvHealthItem('steam_key', 'Steam API key', 'unknown', 'Set, but not yet verified with Steam.');
	}
	$code = (int)$probe['code'];
	$at = (int)($probe['at'] ?? $now);
	if ($code === 200) {
		return phvHealthItem('steam_key', 'Steam API key', 'ok', 'Steam accepted the key (verified ' . phvHealthAgo($at, $now) . ').');
	}
	if ($code === 403 || $code === 401) {
		return phvHealthItem('steam_key', 'Steam API key', 'fail',
			'Steam rejected the key (HTTP ' . $code . ', ' . phvHealthAgo($at, $now) . '). Players will fail to sign in. Check it at steamcommunity.com/dev/apikey.',
			'settings', $at);
	}
	return phvHealthItem('steam_key', 'Steam API key', 'unknown',
		'Steam could not be reached to verify it (' . ($code > 0 ? 'HTTP ' . $code : 'no response') . ', ' . phvHealthAgo($at, $now) . ').', '', $at);
}

/* $cache is the client release cache record (git.php): versions, source, fetched, failed_at, _mtime. */
function phvHealthRuleClientDownloads($cache, $now, $ttl) {
	$name = 'Client downloads';
	if (!is_array($cache)) {
		return phvHealthItem('client_downloads', $name, 'unknown', 'No release lookup has been recorded yet.');
	}
	$versions = (isset($cache['versions']) && is_array($cache['versions'])) ? $cache['versions'] : [];
	$mtime = (int)($cache['_mtime'] ?? $cache['fetched'] ?? 0);
	$source = (string)($cache['source'] ?? '');
	if (!$versions) {
		$since = (int)($cache['failed_at'] ?? $mtime);
		return phvHealthItem('client_downloads', $name, 'fail',
			'Players opening the download menu see no download link. GitHub has not answered a release lookup since ' . phvHealthAgo($since, $now)
			. '. Its API allows 60 lookups an hour per internet address, shared with everyone behind the same one; check the container can reach github.com.',
			'', $since);
	}
	$latest = (string)$versions[0];
	if ($source === 'releases/latest') {
		return phvHealthItem('client_downloads', $name, 'warn',
			'Offering ' . $latest . '. GitHub\'s release API refused the lookup (' . phvHealthAgo($mtime, $now) . '), likely its per-address rate limit; the version came from the releases page instead. Players can still download.',
			'', $mtime);
	}
	if (($now - $mtime) >= $ttl) {
		return phvHealthItem('client_downloads', $name, 'warn',
			'Offering ' . $latest . ' from a list last refreshed ' . phvHealthAgo($mtime, $now) . '. GitHub has not answered since; a newer client release would not be offered until it does.',
			'', $mtime);
	}
	return phvHealthItem('client_downloads', $name, 'ok', 'Offering ' . $latest . ' (checked with GitHub ' . phvHealthAgo($mtime, $now) . ').');
}

function phvHealthRuleDataVolume($writable, $path) {
	if ($writable) {
		return phvHealthItem('data_volume', 'Data volume', 'ok', $path . ' is writable.');
	}
	return phvHealthItem('data_volume', 'Data volume', 'fail',
		$path . ' is not writable by the web server. Settings, mod configs, the client download cache and world files cannot be saved. Check the volume\'s ownership and mount options (some NAS and NFS mounts refuse the chown PhValheim does at startup).');
}

/* $run is one source's entry from modSyncStatus(): ['running' => row|null, 'last' => row|null]. */
function phvHealthRuleModSync($source, $run, $now, $stale) {
	$id = 'mod_sync_' . preg_replace('/[^a-z0-9]/', '', strtolower($source));
	$name = ucfirst($source) . ' catalogue';
	if (!empty($run['running'])) {
		return phvHealthItem($id, $name, 'ok', 'Syncing now (' . ($run['running']['phase'] ?? 'starting') . ').');
	}
	$last = $run['last'] ?? null;
	if (!is_array($last)) {
		return phvHealthItem($id, $name, 'unknown', 'Has not synced yet. The first sync runs within the hour after startup.');
	}
	$finished = !empty($last['finished']) ? strtotime($last['finished']) : (!empty($last['started']) ? strtotime($last['started']) : 0);
	$status = (string)($last['status'] ?? '');
	if ($status === 'error') {
		$err = trim((string)($last['error'] ?? ''));
		return phvHealthItem($id, $name, 'fail',
			'The last sync failed ' . phvHealthAgo($finished, $now) . ($err !== '' ? ': ' . $err : '.') . ' Mod updates are not being noticed until a sync succeeds.',
			'log:modSync.log', $finished);
	}
	if ($status === 'stale') {
		return phvHealthItem($id, $name, 'warn',
			'The last sync was interrupted ' . phvHealthAgo($finished, $now) . ' (PhValheim restarted mid-sync). The next hourly run will retry.',
			'log:modSync.log', $finished);
	}
	if ($finished > 0 && ($now - $finished) >= $stale) {
		return phvHealthItem($id, $name, 'warn',
			'Last synced ' . phvHealthAgo($finished, $now) . '; no sync has completed since, which is longer than the configured interval. Mod updates may be missed.',
			'log:modSync.log', $finished);
	}
	return phvHealthItem($id, $name, 'ok', 'Synced ' . phvHealthAgo($finished, $now) . ' (' . $status . ').');
}

function phvHealthRuleBackups($retention, $lastStatus, $lastRun, $mounted, $perc) {
	$where = $mounted ? 'on its own volume' : 'on the data volume';
	if (empty($retention)) {
		return phvHealthItem('backups', 'World backups', 'warn',
			'Backup retention is not configured, so no backups are being kept. Set how many to keep in Server Settings.', 'settings');
	}
	if ((string)$lastStatus === 'error') {
		return phvHealthItem('backups', 'World backups', 'fail',
			'The last backup run failed' . (!empty($lastRun) ? ' (' . $lastRun . ')' : '') . '. See the backup log for the cause.', 'log:worldBackups.log');
	}
	if ($perc !== null && (int)$perc >= PHV_HEALTH_DISK_WARN) {
		return phvHealthItem('backups', 'World backups', 'warn',
			'The backup volume is ' . (int)$perc . '% full. Backups will start failing when it fills; lower the retention or add space.', 'settings');
	}
	return phvHealthItem('backups', 'World backups', 'ok',
		'Keeping ' . (int)$retention . ' per world ' . $where . (!empty($lastRun) ? ', last run ' . $lastRun : '') . '.');
}

function phvHealthRuleHousekeeping($logRotate, $utilization) {
	$bad = [];
	if ((string)$logRotate === 'error')   { $bad[] = 'log rotation'; }
	if ((string)$utilization === 'error') { $bad[] = 'utilisation monitor'; }
	if ($bad) {
		return phvHealthItem('housekeeping', 'Housekeeping tasks', 'warn',
			'The last run failed: ' . implode(', ', $bad) . '. See phvalheim.log.', 'log:phvalheim.log');
	}
	if (empty($logRotate) && empty($utilization)) {
		return phvHealthItem('housekeeping', 'Housekeeping tasks', 'unknown', 'No run recorded yet.');
	}
	return phvHealthItem('housekeeping', 'Housekeeping tasks', 'ok', 'Log rotation and the utilisation monitor ran without error.');
}

/* $record: null or ['at' => unixtime, 'ok' => bool, 'http_code' => string], written by pushAnalytics.sh. */
function phvHealthRuleAnalytics($enabled, $record, $now) {
	if (!$enabled) {
		return phvHealthItem('analytics', 'Usage analytics', 'ok', 'Off.');
	}
	if (!is_array($record) || !isset($record['ok'])) {
		return phvHealthItem('analytics', 'Usage analytics', 'unknown', 'On; no push has been recorded yet (runs at startup and daily).');
	}
	$at = (int)($record['at'] ?? 0);
	if (!empty($record['ok'])) {
		return phvHealthItem('analytics', 'Usage analytics', 'ok', 'Last push succeeded ' . phvHealthAgo($at, $now) . '.');
	}
	return phvHealthItem('analytics', 'Usage analytics', 'warn',
		'The last push failed ' . phvHealthAgo($at, $now) . ' (HTTP ' . ($record['http_code'] ?? '000') . '). Nothing on your server depends on it; it retries daily.',
		'log:phvalheim.log', $at);
}

/* $r: null (event log unavailable) or ['count' => n, 'last_t' => ts|null, 'last' => ['message','world']|null]. */
function phvHealthRuleRecentErrors($r, $now) {
	if (!is_array($r)) {
		return phvHealthItem('recent_errors', 'Recent errors', 'unknown', 'The event log could not be read.');
	}
	if ((int)$r['count'] === 0) {
		return phvHealthItem('recent_errors', 'Recent errors', 'ok', 'No errors logged in the last hour.');
	}
	$last = $r['last'] ?? null;
	$what = $last ? (' Latest' . (!empty($last['world']) ? " (world '" . $last['world'] . "')" : '') . ': ' . (strlen((string)$last['message']) > 140 ? substr((string)$last['message'], 0, 137) . '...' : (string)$last['message'])) : '';
	return phvHealthItem('recent_errors', 'Recent errors', 'warn',
		(int)$r['count'] . ' error' . ((int)$r['count'] === 1 ? '' : 's') . ' logged in the last hour, the latest ' . phvHealthAgo((int)$r['last_t'], $now) . '.' . $what,
		'logs', (int)$r['last_t']);
}

/* ---------------------------------------------------------------------------------------------
 * Probes with a cache and a backoff. Each returns the record it would also have cached.
 * ------------------------------------------------------------------------------------------- */

/* Ask Steam whether it accepts the key. Cached PHV_HEALTH_STEAM_TTL per key; after a probe that
 * got no answer, not asked again for PHV_HEALTH_STEAM_BACKOFF. $fetch(url) -> int HTTP code
 * (0 = no response) is injectable for tests. */
function phvHealthSteamProbe($key, $now, $fetch = null) {
	if (empty($key)) { return null; }
	$fp = md5($key);
	clearstatcache();
	$c = is_readable(PHV_HEALTH_STEAM_CACHE) ? json_decode((string)@file_get_contents(PHV_HEALTH_STEAM_CACHE), true) : null;
	if (is_array($c) && ($c['key'] ?? '') === $fp && isset($c['code'], $c['at'])) {
		$age = $now - (int)$c['at'];
		$code = (int)$c['code'];
		$answered = ($code === 200 || $code === 403 || $code === 401);
		if (($answered && $age < PHV_HEALTH_STEAM_TTL) || (!$answered && $age < PHV_HEALTH_STEAM_BACKOFF)) {
			return $c;
		}
	}
	if ($fetch === null) { $fetch = 'phvHealthHttpCode'; }
	$url = 'https://api.steampowered.com/ISteamUser/GetPlayerSummaries/v0002/?key=' . rawurlencode($key)
	     . '&steamids=' . PHV_HEALTH_STEAM_PROBE_ID;
	$rec = ['key' => $fp, 'code' => (int)$fetch($url), 'at' => $now];
	@mkdir(dirname(PHV_HEALTH_STEAM_CACHE), 0775, true);
	@file_put_contents(PHV_HEALTH_STEAM_CACHE, json_encode($rec));
	return $rec;
}

/* HEAD-less GET that only reports the status code. 0 when nothing answered. */
function phvHealthHttpCode($url) {
	if (!function_exists('curl_init')) { return 0; }
	$ch = curl_init($url);
	curl_setopt_array($ch, [
		CURLOPT_RETURNTRANSFER => true,
		CURLOPT_CONNECTTIMEOUT => 3,
		CURLOPT_TIMEOUT        => 6,
		CURLOPT_USERAGENT      => 'phvalheim-server',
	]);
	curl_exec($ch);
	$code = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
	curl_close($ch);
	return $code;
}

function phvHealthReadJson($path) {
	clearstatcache();
	if (!is_readable($path)) { return null; }
	$j = json_decode((string)@file_get_contents($path), true);
	return is_array($j) ? $j : null;
}

/* ---------------------------------------------------------------------------------------------
 * The collector. $in overrides any input (tests, and the dashboard's own cheap render).
 * ------------------------------------------------------------------------------------------- */
function phvServerHealth($pdo, $in = []) {
	$now = $in['now'] ?? time();

	if (!isset($in['settings'])) {
		$row = [];
		try {
			$cols = [];
			foreach ($pdo->query("DESCRIBE settings") as $r) { $cols[$r['Field']] = true; }
			$want = array_values(array_filter(['gameDNS', 'basePort', 'steamApiKey', 'backupsToKeep', 'analyticsEnabled', 'modSyncIntervalHours'],
				fn($c) => isset($cols[$c])));
			if ($want) {
				$row = $pdo->query("SELECT " . implode(', ', $want) . " FROM settings LIMIT 1")->fetch(PDO::FETCH_ASSOC) ?: [];
			}
		} catch (Throwable $e) { $row = []; }
		$in['settings'] = $row;
	}
	$s = $in['settings'];

	$checks = [];
	$checks[] = phvHealthRuleSettings($s['gameDNS'] ?? '', $s['basePort'] ?? '');

	$steamKey = (string)($s['steamApiKey'] ?? '');
	$probe = array_key_exists('steamProbe', $in) ? $in['steamProbe'] : phvHealthSteamProbe($steamKey, $now);
	$checks[] = phvHealthRuleSteamKey($steamKey, $probe, $now);

	if (!array_key_exists('releaseCache', $in)) {
		// Refreshes the cache if it is stale (one network wait per 5 minutes at worst, see git.php),
		// so the verdict is about now, not about the last time a player opened the menu.
		if (function_exists('getGitReleases') && !empty($in['clientRepo'])) {
			getGitReleases($in['clientRepo'], 1);
		}
		$in['releaseCache'] = function_exists('phvLoadReleaseCache') ? phvLoadReleaseCache() : null;
	}
	$checks[] = phvHealthRuleClientDownloads($in['releaseCache'], $now, defined('PHV_RELEASE_CACHE_TTL') ? PHV_RELEASE_CACHE_TTL : 3600);

	$path = $in['dataPath'] ?? '/opt/stateful';
	$writable = array_key_exists('dataWritable', $in) ? $in['dataWritable'] : phvHealthDirWritable($path);
	$checks[] = phvHealthRuleDataVolume($writable, $path);

	$runs = array_key_exists('syncRuns', $in) ? $in['syncRuns']
	      : (function_exists('modSyncStatus') ? modSyncStatus($pdo) : []);
	$stale = phvHealthSyncStaleAfter($s['modSyncIntervalHours'] ?? null);
	foreach ($runs as $src => $run) {
		$checks[] = phvHealthRuleModSync($src, $run, $now, $stale);
	}

	if (!array_key_exists('backup', $in)) {
		$in['backup'] = [
			'status'  => function_exists('getLastWorldBackupExecStatus') ? getLastWorldBackupExecStatus($pdo) : null,
			'lastRun' => function_exists('getLastWorldBackupExecTime') ? getLastWorldBackupExecTime($pdo) : null,
			'mounted' => function_exists('isBackupPathMounted') ? isBackupPathMounted() : false,
			'perc'    => is_dir('/opt/stateful/backups') && function_exists('getUsedDiskPerc') ? (int)getUsedDiskPerc('/opt/stateful/backups') : null,
		];
	}
	$b = $in['backup'];
	$checks[] = phvHealthRuleBackups($s['backupsToKeep'] ?? '', $b['status'] ?? null, $b['lastRun'] ?? null, !empty($b['mounted']), $b['perc'] ?? null);

	if (!array_key_exists('housekeeping', $in)) {
		$in['housekeeping'] = [
			'logRotate'   => function_exists('getLastLogRotateExecStatus') ? getLastLogRotateExecStatus($pdo) : null,
			'utilization' => function_exists('getLastUtilizationMonitorExecStatus') ? getLastUtilizationMonitorExecStatus($pdo) : null,
		];
	}
	$checks[] = phvHealthRuleHousekeeping($in['housekeeping']['logRotate'] ?? null, $in['housekeeping']['utilization'] ?? null);

	$record = array_key_exists('analyticsRecord', $in) ? $in['analyticsRecord'] : phvHealthReadJson(PHV_HEALTH_ANALYTICS_RECORD);
	$checks[] = phvHealthRuleAnalytics((int)($s['analyticsEnabled'] ?? 1) === 1, $record, $now);

	if (!array_key_exists('recentErrors', $in)) {
		$in['recentErrors'] = null;
		if ($pdo && function_exists('phvRecentErrors')) { try { $in['recentErrors'] = phvRecentErrors($pdo); } catch (Throwable $e) { $in['recentErrors'] = null; } }
	}
	$checks[] = phvHealthRuleRecentErrors($in['recentErrors'], $now);

	$counts = ['ok' => 0, 'warn' => 0, 'fail' => 0, 'unknown' => 0];
	foreach ($checks as $c) { $counts[$c['state']]++; }
	return ['checks' => $checks, 'counts' => $counts, 'checked_at' => $now];
}

/* The cron runs hourly but modSync.py only syncs when settings.modSyncIntervalHours (default 6)
 * has elapsed, and records nothing when it skips. So "stale" is two missed intervals plus an
 * hour of slack, never a fixed number. */
function phvHealthSyncStaleAfter($intervalHours) {
	$h = (int)$intervalHours;
	if ($h < 1) { $h = 6; }
	return $h * 2 * 3600 + 3600;
}

/* A real write, not is_writable(): is_writable() answers from the mode bits and is wrong on
 * read-only and root-squashed mounts. */
function phvHealthDirWritable($path) {
	if (!is_dir($path)) { return false; }
	$probe = rtrim($path, '/') . '/.phv-health-' . getmypid();
	$ok = @file_put_contents($probe, '1') !== false;
	@unlink($probe);
	return $ok;
}
