<?php
/*
 * Which client versions may the download menu offer?
 *
 * ONLY PUBLISHED RELEASES. This used to read git TAGS:
 *
 *     git ls-remote --refs --tags $repo | cut -d/ -f3- | sort -V -r | head -N
 *
 * which cannot answer the question. A GitHub pre-release creates a tag, and the
 * build commits its artifacts to master, so a tag plus a committed file was
 * enough to hand every player an unreleased build -- the 2.0.14 pre-release was
 * offered on the public page while 2.0.13 was still the published release.
 * "Is this published?" was never asked anywhere in the chain.
 *
 * The releases API answers it directly: each release carries `draft` and
 * `prerelease`, and both are excluded here.
 *
 * THREE STATES, and keeping them apart is the whole design:
 *
 *   a list  -- these versions are published (an EMPTY list is a real answer:
 *              the repo has no published release yet)
 *   null    -- we could not find out (network, rate limit, bad response)
 *
 * A failure must never be rendered as "no releases", and must never silently
 * fall back to tags, because falling back to tags is exactly the bug. On
 * failure we serve a STALE cache if we have one and otherwise offer nothing and
 * say so in the log. Nothing is worse than a wrong version.
 */

// Unauthenticated api.github.com allows 60 requests per hour per IP, and this
// runs on every render of the authenticated player page, so the cache is
// load-bearing rather than an optimisation. php-fpm runs as `phvalheim`
// (container/php-fpm/www.conf), which owns /opt/stateful.
if (!defined('PHV_RELEASE_CACHE')) {
	define('PHV_RELEASE_CACHE', '/opt/stateful/cache/clientReleases.json');
}
if (!defined('PHV_RELEASE_CACHE_TTL')) {
	define('PHV_RELEASE_CACHE_TTL', 3600);
}
// Where the cache goes when /opt/stateful/cache cannot be written. The engine chowns
// /opt/stateful to phvalheim at boot, but a volume that refuses it (some NAS and NFS
// mounts) left php-fpm unable to cache at all -- and then EVERY player page load spent one
// of the 60 unauthenticated API calls per hour this IP gets. Under /tmp it lasts until the
// container restarts, which is all a one-hour cache needs.
if (!defined('PHV_RELEASE_CACHE_FALLBACK')) {
	define('PHV_RELEASE_CACHE_FALLBACK', sys_get_temp_dir() . '/phvalheim-clientReleases.json');
}
// After BOTH sources fail with nothing cached, do not try again for this long. Each attempt
// can cost a player's page load up to 12 seconds; a dead network should cost one of them,
// not every one.
if (!defined('PHV_RELEASE_FAIL_BACKOFF')) {
	define('PHV_RELEASE_FAIL_BACKOFF', 300);
}

/* "https://github.com/owner/repo" (optionally .git, optionally trailing /) -> "owner/repo" */
function phvGitHubSlug($gitRepo) {
	if (!preg_match('~github\.com[:/]+([^/]+)/([^/]+?)(?:\.git)?/*$~i', (string)$gitRepo, $m)) {
		return null;
	}
	return $m[1] . '/' . $m[2];
}

/*
 * Published release versions, newest first. null means "could not determine".
 * Tag names are returned with any leading v stripped, because the download
 * paths are built from a bare version.
 */
function phvFetchPublishedReleases($slug) {
	$ch = curl_init("https://api.github.com/repos/$slug/releases?per_page=30");
	if ($ch === false) { return null; }
	curl_setopt_array($ch, [
		CURLOPT_RETURNTRANSFER => true,
		CURLOPT_CONNECTTIMEOUT => 3,
		// A player page must not hang on GitHub being slow.
		CURLOPT_TIMEOUT        => 6,
		// GitHub rejects requests with no User-Agent.
		CURLOPT_USERAGENT      => 'phvalheim-server',
		CURLOPT_HTTPHEADER     => ['Accept: application/vnd.github+json'],
	]);
	$body = curl_exec($ch);
	$code = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
	$err  = curl_error($ch);
	curl_close($ch);

	if ($body === false || $code !== 200) {
		error_log("phvalheim: GitHub releases lookup for $slug failed"
		        . " (http=$code" . ($err !== '' ? ", curl=$err" : '') . ')');
		return null;
	}
	$rows = json_decode($body, true);
	if (!is_array($rows)) {
		error_log("phvalheim: GitHub releases lookup for $slug returned unparseable JSON");
		return null;
	}
	return phvPublishedVersionsFromApi($rows);
}

/*
 * The newest PUBLISHED release, from github.com/<repo>/releases/latest -- a web redirect, not
 * the API. Returns [version] or null.
 *
 * WHY: a player reported the download menu saying "No published release is available right
 * now" while 2.0.15 was published. The only way getGitReleases() says that is the API lookup
 * failing with no cache to fall back on, and the API has one quota for everything behind this
 * server's IP: 60 unauthenticated calls an hour, shared with every other GitHub user on a
 * hosting provider's or a carrier's NAT. One failed lookup left players with no download.
 *
 * This endpoint answers the same question for the case that matters -- GitHub's "latest" is by
 * definition the newest release that is neither a draft nor a pre-release, so the published-
 * only rule this file exists for still holds -- and it is not metered by the API rate limit
 * (verified: the response carries no x-ratelimit headers). It cannot list older releases, so
 * it is the fallback, not the source.
 */
function phvFetchLatestPublishedRelease($slug) {
	$ch = curl_init("https://github.com/$slug/releases/latest");
	if ($ch === false) { return null; }
	curl_setopt_array($ch, [
		CURLOPT_NOBODY         => true,
		CURLOPT_RETURNTRANSFER => true,
		CURLOPT_FOLLOWLOCATION => false,
		CURLOPT_CONNECTTIMEOUT => 3,
		CURLOPT_TIMEOUT        => 6,
		CURLOPT_USERAGENT      => 'phvalheim-server',
	]);
	curl_exec($ch);
	$code = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
	$loc  = (string)curl_getinfo($ch, CURLINFO_REDIRECT_URL);
	curl_close($ch);
	return phvVersionFromLatestRedirect($code, $loc);
}

/*
 * The parse, apart from the HTTP call so it can be driven with recorded responses. A repo with
 * no published release redirects to the releases LIST, not to a tag -- which must read as "no
 * answer", never as a version.
 */
function phvVersionFromLatestRedirect($code, $location) {
	if ($code < 300 || $code >= 400) { return null; }
	if (!preg_match('~/releases/tag/([^/?#]+)/?$~', (string)$location, $m)) { return null; }
	$tag = ltrim(rawurldecode($m[1]), 'vV');
	return $tag === '' ? null : [$tag];
}

/*
 * The filter this whole file exists for, kept SEPARATE from the HTTP call so it
 * can be driven with recorded API responses.
 *
 * It was inlined in phvFetchPublishedReleases() at first, which made it
 * untestable without a network, so the only coverage was a grep for the word
 * "prerelease" in the source -- a check that passes whether or not the flag is
 * actually honoured. Mutation-checked: delete either flag below and
 * test-client-release-source.php fails.
 */
function phvPublishedVersionsFromApi($rows) {
	$versions = [];
	foreach ($rows as $r) {
		if (!is_array($r)) { continue; }
		if (!empty($r['draft']) || !empty($r['prerelease'])) { continue; }
		$tag = isset($r['tag_name']) ? ltrim((string)$r['tag_name'], 'vV') : '';
		if ($tag !== '') { $versions[] = $tag; }
	}
	return $versions;
}

/*
 * Newest-first by VERSION, then the top $want.
 *
 * Every return path goes through here on purpose. Sorting only the API response
 * was a real bug: the cache is what callers hit almost every time, and reading
 * it back unsorted served whatever order happened to be stored. The API orders
 * by creation date, so a patch to an old version published after a newer
 * release would have gone out as "the latest client". Caught by
 * dev_tools/test-client-release-source.php.
 */
function phvTopVersions($versions, $want) {
	usort($versions, function ($a, $b) { return version_compare($b, $a); });
	return array_slice($versions, 0, max(1, (int)$want));
}

/*
 * The cache, wherever it could be written. The primary location first, then the fallback;
 * whichever was written most recently wins if both exist.
 *
 * clearstatcache() is not decoration. PHP caches filemtime() for the life of a request, so a
 * file aged in the same request still reads as fresh -- which is how the old test's "stale
 * cache is served" case passed for months without ever reaching the stale path.
 */
function phvReleaseCachePaths() {
	return array_values(array_unique([PHV_RELEASE_CACHE, PHV_RELEASE_CACHE_FALLBACK]));
}

function phvLoadReleaseCache() {
	clearstatcache();
	$best = null;
	foreach (phvReleaseCachePaths() as $p) {
		if (!is_readable($p)) { continue; }
		$c = json_decode((string)@file_get_contents($p), true);
		if (!is_array($c)) { continue; }
		$c['_mtime'] = (int)filemtime($p);
		if ($best === null || $c['_mtime'] > $best['_mtime']) { $best = $c; }
	}
	return $best;
}

function phvReadReleaseCache($requireFresh) {
	$c = phvLoadReleaseCache();
	if ($c === null || !isset($c['versions']) || !is_array($c['versions'])) { return null; }
	if ($requireFresh && (time() - $c['_mtime']) >= PHV_RELEASE_CACHE_TTL) { return null; }
	return $c['versions'];
}

/* Write to the first location that accepts it. Returns the path written, or null. */
function phvWriteReleaseCache($data) {
	$json = json_encode($data);
	foreach (phvReleaseCachePaths() as $p) {
		@mkdir(dirname($p), 0775, true);
		if (@file_put_contents($p, $json) !== false) { return $p; }
	}
	error_log('phvalheim: cannot write the client release cache anywhere; every player page'
	        . ' load will query GitHub until this is fixed');
	return null;
}

/*
 * $fetchApi / $fetchLatest are the two network sources, passed in so the whole decision can be
 * driven without a network. Production callers never pass them.
 */
function getGitReleases($gitRepo, $clientVersionsToRender,
                        $fetchApi = 'phvFetchPublishedReleases',
                        $fetchLatest = 'phvFetchLatestPublishedRelease') {
	$want = max(1, (int)$clientVersionsToRender);

	$slug = phvGitHubSlug($gitRepo);
	if ($slug === null) {
		error_log("phvalheim: cannot parse a GitHub owner/repo out of '$gitRepo';"
		        . ' no client downloads will be offered');
		return [];
	}

	$fresh = phvReadReleaseCache(true);
	if ($fresh !== null) {
		return phvTopVersions($fresh, $want);
	}

	// Both sources failed recently and nothing is cached: do not make this page wait on them
	// again yet.
	$c = phvLoadReleaseCache();
	$stale = ($c !== null && isset($c['versions']) && is_array($c['versions'])) ? $c['versions'] : null;
	if ($stale === null && $c !== null && !empty($c['failed_at'])
	        && (time() - (int)$c['failed_at']) < PHV_RELEASE_FAIL_BACKOFF) {
		return [];
	}

	$versions = $fetchApi($slug);
	if ($versions !== null) {
		phvWriteReleaseCache(['fetched' => time(), 'slug' => $slug, 'source' => 'api',
		                      'versions' => $versions]);
		return phvTopVersions($versions, $want);
	}

	// The API failed. Ask the un-metered endpoint for the newest published release, and keep
	// any older versions a previous lookup knew about -- they were published when it looked.
	$latest = $fetchLatest($slug);
	if ($latest !== null) {
		$merged = array_values(array_unique(array_merge($latest, $stale ?? [])));
		phvWriteReleaseCache(['fetched' => time(), 'slug' => $slug, 'source' => 'releases/latest',
		                      'versions' => $merged]);
		error_log('phvalheim: GitHub releases API unavailable; offering the latest published'
		        . ' client from github.com/' . $slug . '/releases/latest instead');
		return phvTopVersions($merged, $want);
	}

	// Both failed. A stale answer is still an answer about PUBLISHED releases.
	if ($stale !== null) {
		error_log('phvalheim: serving client download versions from a STALE cache');
		return phvTopVersions($stale, $want);
	}

	phvWriteReleaseCache(['failed_at' => time(), 'slug' => $slug]);
	error_log('phvalheim: no published client release could be determined from GitHub (API and'
	        . ' releases/latest both failed) and no cache exists; offering no downloads rather'
	        . ' than guessing from tags. Retrying in ' . PHV_RELEASE_FAIL_BACKOFF . 's.');
	return [];
}
