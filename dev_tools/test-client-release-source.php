<?php
/*
 * getGitReleases() must offer PUBLISHED releases only.
 *
 * The bug this guards: the old implementation read git TAGS, and a GitHub
 * pre-release creates a tag. 2.0.14 was offered to every player on a live
 * server while 2.0.13 was still the published release.
 *
 * The parsing and filtering are driven directly here with recorded API shapes.
 * The network call itself is NOT exercised -- that needs the container and
 * GitHub -- so the cache/failure behaviour is asserted through the real
 * getGitReleases() against a temporary cache instead.
 *
 * Run: php dev_tools/test-client-release-source.php
 */

define('PHV_RELEASE_CACHE', '/tmp/phv-test-clientReleases.json');
define('PHV_RELEASE_CACHE_TTL', 3600);
require __DIR__ . '/../container/nginx/www/includes/git.php';

$pass = 0; $fail = 0;
function check($what, $want, $got) {
	global $pass, $fail;
	$w = is_array($want) ? json_encode($want) : var_export($want, true);
	$g = is_array($got)  ? json_encode($got)  : var_export($got, true);
	if ($w === $g) { $pass++; echo "  PASS  $what\n"; }
	else { $fail++; echo "  FAIL  $what\n          want $w\n          got  $g\n"; }
}
function seedCache($versions, $age = 0) {
	file_put_contents(PHV_RELEASE_CACHE, json_encode(['fetched' => time(), 'versions' => $versions]));
	if ($age) { touch(PHV_RELEASE_CACHE, time() - $age); }
}
@unlink(PHV_RELEASE_CACHE);

echo "\n-- owner/repo parsing --\n";
check('https URL', 'brianmiller/phvalheim-client',
	phvGitHubSlug('https://github.com/brianmiller/phvalheim-client'));
check('trailing .git', 'brianmiller/phvalheim-client',
	phvGitHubSlug('https://github.com/brianmiller/phvalheim-client.git'));
check('trailing slash', 'brianmiller/phvalheim-client',
	phvGitHubSlug('https://github.com/brianmiller/phvalheim-client/'));
check('ssh form', 'brianmiller/phvalheim-client',
	phvGitHubSlug('git@github.com:brianmiller/phvalheim-client.git'));
// A non-GitHub remote must be reported, not silently treated as a repo named "".
check('a non-GitHub URL is unparseable', null,
	phvGitHubSlug('https://gitlab.com/brianmiller/phvalheim-client'));

echo "\n-- the filter itself, against a recorded API response --\n";
// THE regression, in the exact shape GitHub returned it on 2026-10-03: 2.0.14
// present and flagged prerelease, 2.0.13 the published Latest. Driven through
// the real filter, not a seeded cache, so deleting either flag check fails here.
$recorded = [
	['tag_name' => '2.0.14', 'prerelease' => true,  'draft' => false],
	['tag_name' => '2.0.13', 'prerelease' => false, 'draft' => false],
	['tag_name' => '2.0.12', 'prerelease' => false, 'draft' => false],
];
check('a pre-release is filtered out', ['2.0.13', '2.0.12'],
	phvPublishedVersionsFromApi($recorded));
check('a draft is filtered out', ['2.0.13'],
	phvPublishedVersionsFromApi([
		['tag_name' => '2.0.14', 'prerelease' => false, 'draft' => true],
		['tag_name' => '2.0.13', 'prerelease' => false, 'draft' => false],
	]));
// A repo with nothing published is a real, empty answer -- not a failure.
check('all pre-release means an empty published list', [],
	phvPublishedVersionsFromApi([['tag_name' => '2.0.14', 'prerelease' => true]]));
// Releases tagged v2.0.13 must still yield a bare version for the file paths.
check('a leading v is stripped', ['2.0.13'],
	phvPublishedVersionsFromApi([['tag_name' => 'v2.0.13']]));
// Absent flags default to published, which is how the API omits false fields.
check('missing flags count as published', ['2.0.13'],
	phvPublishedVersionsFromApi([['tag_name' => '2.0.13']]));

echo "\n-- the cache path, which is what callers actually hit --\n";
// THE regression case, stated the way it happened: 2.0.14 exists as a tag and
// as a pre-release; 2.0.13 is the published release. The answer must be 2.0.13.
seedCache(['2.0.13', '2.0.12']);
check('a pre-release tag is NOT offered', ['2.0.13'],
	getGitReleases('https://github.com/brianmiller/phvalheim-client', 1));
check('clientVersionsToRender still returns N', ['2.0.13', '2.0.12'],
	getGitReleases('https://github.com/brianmiller/phvalheim-client', 2));
// 0 or junk must not mean "none at all" -- that would empty the download menu.
check('a bogus render count floors at 1', ['2.0.13'],
	getGitReleases('https://github.com/brianmiller/phvalheim-client', 0));

echo "\n-- version order beats publication order --\n";
// GitHub returns releases newest-CREATED first. A 2.0.9 patch published after
// 2.0.13 must not become the offered version.
seedCache(['2.0.9', '2.0.13', '2.0.12']);
check('highest version wins regardless of cache order', ['2.0.13'],
	getGitReleases('https://github.com/brianmiller/phvalheim-client', 1));

echo "\n-- a stale cache still answers, and a broken repo URL does not --\n";
seedCache(['2.0.13'], 86400);
check('stale cache is served rather than nothing', ['2.0.13'],
	getGitReleases('https://github.com/brianmiller/phvalheim-client', 1));
check('an unparseable repo offers nothing', [],
	getGitReleases('not-a-github-url', 1));

echo "\n-- the source no longer reads tags --\n";
// A NEGATIVE. Keeping the versions right while the tag reader is still in the
// file would leave the bug one edit away, and this file is the only thing that
// would notice.
$src = file_get_contents(__DIR__ . '/../container/nginx/www/includes/git.php');
$code = preg_replace('~^\s*(\*|/\*|//).*$~m', '', $src); // drop comments; prose mentions tags
check('no ls-remote in the code', 0, preg_match('~ls-remote~', $code));
check('no shell_exec in the code', 0, preg_match('~shell_exec~', $code));
check('the releases API is what is called', 1,
	preg_match('~api\.github\.com/repos/~', $code));
check('draft and prerelease are both filtered', 1,
	(preg_match('~\[.draft.\]~', $code) && preg_match('~\[.prerelease.\]~', $code)) ? 1 : 0);

@unlink(PHV_RELEASE_CACHE);
echo "\n" . ($fail ? "FAILED: $fail of " . ($pass + $fail) . " checks failed\n"
                   : "OK: $pass checks passed\n");
exit($fail ? 1 : 0);
