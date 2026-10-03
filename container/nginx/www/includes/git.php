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

function phvReadReleaseCache($requireFresh) {
	if (!is_readable(PHV_RELEASE_CACHE)) { return null; }
	if ($requireFresh && (time() - (int)filemtime(PHV_RELEASE_CACHE)) >= PHV_RELEASE_CACHE_TTL) {
		return null;
	}
	$c = json_decode((string)file_get_contents(PHV_RELEASE_CACHE), true);
	if (!is_array($c) || !isset($c['versions']) || !is_array($c['versions'])) { return null; }
	return $c['versions'];
}

function getGitReleases($gitRepo, $clientVersionsToRender) {
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

	$versions = phvFetchPublishedReleases($slug);
	if ($versions !== null) {
		@mkdir(dirname(PHV_RELEASE_CACHE), 0775, true);
		@file_put_contents(PHV_RELEASE_CACHE,
			json_encode(['fetched' => time(), 'slug' => $slug, 'versions' => $versions]));
		return phvTopVersions($versions, $want);
	}

	// Lookup failed. A stale answer is still an answer about PUBLISHED releases.
	$stale = phvReadReleaseCache(false);
	if ($stale !== null) {
		error_log('phvalheim: serving client download versions from a STALE cache');
		return phvTopVersions($stale, $want);
	}

	error_log('phvalheim: no published client release could be determined and no cache'
	        . ' exists; offering no downloads rather than guessing from tags');
	return [];
}
