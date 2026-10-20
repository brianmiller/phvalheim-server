<?php
/*
 * Oracle test: the client download menu survives the GitHub releases API being unavailable.
 *
 * THE REPORT (Discord, 2026-10-20): a player's download popover said "No published release is
 * available right now" while 2.0.15 was published. Not reproducible on a standard install.
 * getGitReleases() says that in exactly one case: the API lookup FAILED and there was no cache.
 * The API gives each public IP 60 unauthenticated calls an hour -- shared with every other
 * GitHub user behind a hosting provider's or carrier's NAT -- and a volume php-fpm cannot write
 * to means no cache is ever kept, so every page load spends a call. One bad hour and every
 * player on that server gets no download link.
 *
 * What is pinned here, with the PRIMARY cache location deliberately unwritable throughout so
 * every case also proves the /tmp fallback:
 *   1. the redirect parse: a tag is a version; anything else is no answer
 *   2. API fails, nothing cached -> the newest published release from releases/latest
 *   3. ...and it is cached, so the next page load costs no network at all
 *   4. API fails with a stale cache -> latest merged with the older published versions
 *   5. both fail with a stale cache -> the stale cache (unchanged behaviour)
 *   6. both fail with nothing cached -> [] and a backoff, so the next load does not wait again
 *   7. the backoff expires -> the sources are tried again
 *   8. the API working is still the source of record (latest is never called)
 *
 * Mutation controls re-run the decisive cases against a copy of git.php with one decision
 * broken; each must fail. Network is never used: both sources are injected.
 *
 * Usage: php dev_tools/test-client-release-fallback.php
 */

$SRC = __DIR__ . '/../container/nginx/www/includes/git.php';
$TMP = sys_get_temp_dir() . '/phv-relfb-' . getmypid();
@mkdir($TMP, 0777, true);

// Child mode: one mutated copy of git.php, run the decisive cases, print JSON.
$child = getenv('PHV_RELFB_SRC');
if ($child !== false && $child !== '') { $SRC = $child; }

define('PHV_RELEASE_CACHE', '/proc/phv-not-writable/clientReleases.json');
define('PHV_RELEASE_CACHE_FALLBACK', "$TMP/fallback.json");
define('PHV_RELEASE_CACHE_TTL', 3600);
define('PHV_RELEASE_FAIL_BACKOFF', 300);
require $SRC;

register_shutdown_function(function () use ($TMP) {
	foreach (glob("$TMP/*") as $f) { @unlink($f); }
	@rmdir($TMP);
});

const REPO = 'https://github.com/brianmiller/phvalheim-client';

$calls = ['api' => 0, 'latest' => 0];
function src($result, $name) {
	return function () use ($result, $name) { $GLOBALS['calls'][$name]++; return $result; };
}
function reset_cache() { @unlink(PHV_RELEASE_CACHE_FALLBACK); clearstatcache(); $GLOBALS['calls'] = ['api' => 0, 'latest' => 0]; }
function seed($data, $age = 0) {
	file_put_contents(PHV_RELEASE_CACHE_FALLBACK, json_encode($data));
	if ($age) { touch(PHV_RELEASE_CACHE_FALLBACK, time() - $age); }
	clearstatcache();
}
function cache() { clearstatcache(); return json_decode((string)@file_get_contents(PHV_RELEASE_CACHE_FALLBACK), true); }

// The decisive scenarios, shared by the parent and the mutation children.
function scenarios() {
	$out = [];
	reset_cache();
	$out['latest_when_api_down'] = getGitReleases(REPO, 1, src(null, 'api'), src(['2.0.15'], 'latest'));
	reset_cache();
	$out['both_down_then_backoff'] = [getGitReleases(REPO, 1, src(null, 'api'), src(null, 'latest')),
	                                  $GLOBALS['calls']];
	getGitReleases(REPO, 1, src(null, 'api'), src(null, 'latest'));
	$out['second_load_network_calls'] = $GLOBALS['calls'];
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

echo "\n-- 1. the releases/latest redirect, parsed --\n";
check('302 to a tag is that version', ['2.0.15'],
	phvVersionFromLatestRedirect(302, 'https://github.com/brianmiller/phvalheim-client/releases/tag/2.0.15'));
check('a leading v is stripped', ['2.0.15'],
	phvVersionFromLatestRedirect(302, 'https://github.com/x/y/releases/tag/v2.0.15'));
// A repo with nothing published redirects to the release LIST. That is not a version.
check('a redirect to the release list is no answer', null,
	phvVersionFromLatestRedirect(302, 'https://github.com/x/y/releases'));
check('a 200 (no redirect) is no answer', null, phvVersionFromLatestRedirect(200, ''));
check('a 404 is no answer', null, phvVersionFromLatestRedirect(404, ''));

echo "\n-- 2/3. API down, nothing cached --\n";
reset_cache();
check('offers the newest published release from releases/latest', ['2.0.15'],
	getGitReleases(REPO, 1, src(null, 'api'), src(['2.0.15'], 'latest')));
check('  the cache landed in the FALLBACK location (primary is unwritable)',
	['2.0.15', 'releases/latest'], [cache()['versions'][0] ?? null, cache()['source'] ?? null]);
check('  primary location was not written', false, file_exists(PHV_RELEASE_CACHE));
$calls = ['api' => 0, 'latest' => 0];
check('the next page load is served from that cache', ['2.0.15'],
	getGitReleases(REPO, 1, src(null, 'api'), src(null, 'latest')));
check('  ...with no network call at all', ['api' => 0, 'latest' => 0], $calls);

echo "\n-- 4. API down, a stale cache with older versions --\n";
reset_cache();
seed(['fetched' => time() - 86400, 'versions' => ['2.0.13', '2.0.12']], 86400);
check('latest is offered first, the older published ones after it', ['2.0.15', '2.0.13'],
	getGitReleases(REPO, 2, src(null, 'api'), src(['2.0.15'], 'latest')));

echo "\n-- 5. both down, a stale cache --\n";
reset_cache();
seed(['fetched' => time() - 86400, 'versions' => ['2.0.13']], 86400);
check('the stale cache is served', ['2.0.13'],
	getGitReleases(REPO, 1, src(null, 'api'), src(null, 'latest')));

echo "\n-- 6/7. both down, nothing cached --\n";
reset_cache();
check('offers nothing (never guesses)', [],
	getGitReleases(REPO, 1, src(null, 'api'), src(null, 'latest')));
check('  both sources were tried once', ['api' => 1, 'latest' => 1], $calls);
check('  a failure is recorded', true, !empty(cache()['failed_at']));
$calls = ['api' => 0, 'latest' => 0];
getGitReleases(REPO, 1, src(null, 'api'), src(null, 'latest'));
check('the next load inside the backoff makes NO network call', ['api' => 0, 'latest' => 0], $calls);
seed(['failed_at' => time() - 400, 'slug' => 'x'], 400);
$calls = ['api' => 0, 'latest' => 0];
check('after the backoff the sources are tried again, and can succeed', ['2.0.15'],
	getGitReleases(REPO, 1, src(null, 'api'), src(['2.0.15'], 'latest')));
check('  (both were asked)', ['api' => 1, 'latest' => 1], $calls);

echo "\n-- 8. the API is still the source of record --\n";
reset_cache();
check('API up: its list is used', ['2.0.15', '2.0.14'],
	getGitReleases(REPO, 2, src(['2.0.15', '2.0.14', '2.0.13'], 'api'), src(['9.9.9'], 'latest')));
check('  releases/latest is never called when the API answers', 0, $calls['latest']);

echo "\n-- mutation controls -- each must break the case it covers --\n";
// The counters in scenarios() are CUMULATIVE over two page loads. Unbroken, only the first load
// touches the network ({1,1}); a mutant that retries shows {2,2}. Asserting the baseline here is
// what makes the {2,2} below a difference rather than an assumption.
$base = scenarios();
check('baseline: two loads, network touched only by the first', ['api' => 1, 'latest' => 1],
	$base['second_load_network_calls']);
$mutations = [
	['no releases/latest fallback', '	$latest = $fetchLatest($slug);', '	$latest = null;',
	 'latest_when_api_down', []],
	['no backoff: every load retries a dead network',
	 '	        && (time() - (int)$c[\'failed_at\']) < PHV_RELEASE_FAIL_BACKOFF) {',
	 '	        && false) {',
	 'second_load_network_calls', ['api' => 2, 'latest' => 2]],
	['no fallback cache path: an unwritable volume caches nothing',
	 "	return array_values(array_unique([PHV_RELEASE_CACHE, PHV_RELEASE_CACHE_FALLBACK]));",
	 "	return [PHV_RELEASE_CACHE];",
	 'second_load_network_calls', ['api' => 2, 'latest' => 2]],
];
$orig = file_get_contents($SRC);
foreach ($mutations as [$label, $find, $repl, $case, $broken]) {
	if (substr_count($orig, $find) !== 1) {
		$fail++; echo "  FAIL  mutation applies exactly once: $label (found " . substr_count($orig, $find) . ")\n"; continue;
	}
	$mut = "$TMP/git_mut.php";
	file_put_contents($mut, str_replace($find, $repl, $orig));
	$got = json_decode((string)shell_exec('PHV_RELFB_SRC=' . escapeshellarg($mut) . ' php '
		. escapeshellarg(__FILE__) . ' 2>/dev/null'), true);
	if (!is_array($got)) { $fail++; echo "  FAIL  mutation ran: $label\n"; continue; }
	check("$label => '$case' breaks", $broken, $got[$case]);
}

echo "\n$pass passed, $fail failed\n";
exit($fail === 0 ? 0 : 1);
