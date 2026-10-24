<?php
/**
 * Oracle test: the Build Pak lookup prefers a stable release and FALLS BACK to a pre-release (2.59).
 *
 * THE BUG. Build Pak 1.0.0 is published as a pre-release for the 2.59 cycle. The lookup skipped
 * pre-releases unless a hidden Advanced toggle was on, so the first operator to test saw "no
 * release carries the expected assets", clicked Download & Install anyway, and the job failed in
 * five milliseconds. The pick is now: newest fitting STABLE release; if none, newest fitting
 * pre-release, flagged `fallback` with a note. A stable release always beats a pre-release when
 * the toggle is off; with the toggle on, the newest fitting release of either kind wins.
 *
 * HOW. vibesPakPickRelease() is pure apart from the manifest fetcher, which the test supplies, so
 * GitHub's release list is a fixture here. The incompatible-pak and no-asset rows are controls.
 */
require __DIR__ . '/vibes-test-lib.php';
vibesTestLoad('pak');

$pass = 0; $fail = 0;
function ok($m) { global $pass; echo "  PASS  $m\n"; $pass++; }
function bad($m, $d = '') { global $fail; echo "  FAIL  $m\n"; if ($d !== '') echo "        $d\n"; $fail++; }

$tar = function ($v) { return sprintf(VIBES_PAK_ASSET, $v); };
$rel = function ($v, $pre, $withAssets = true, $published = '2026-10-24T09:00:00Z') use ($tar) {
	$assets = $withAssets ? [
		['name' => $tar($v), 'size' => 237765003, 'browser_download_url' => "https://x/$v/" . $tar($v)],
		['name' => $tar($v) . '.sha256', 'browser_download_url' => "https://x/$v/" . $tar($v) . '.sha256'],
		['name' => 'manifest.json', 'browser_download_url' => "https://x/$v/manifest.json"],
	] : [['name' => 'README.md', 'browser_download_url' => "https://x/$v/README.md"]];
	return ['tag_name' => "v$v", 'draft' => false, 'prerelease' => $pre, 'published_at' => $published, 'assets' => $assets];
};
$manifests = [
	'1.0.0' => ['pakFormat' => 1, 'base' => VIBES_PAK_BASE, 'version' => '1.0.0', 'components' => ['dotnet-sdk' => '8.0.426']],
	'1.1.0' => ['pakFormat' => 1, 'base' => VIBES_PAK_BASE, 'version' => '1.1.0', 'components' => ['dotnet-sdk' => '8.0.427']],
	'1.2.0' => ['pakFormat' => 1, 'base' => VIBES_PAK_BASE, 'version' => '1.2.0', 'components' => ['dotnet-sdk' => '8.0.428']],
	'9.0.0' => ['pakFormat' => 99, 'base' => 'noble', 'version' => '9.0.0'],
];
$fetch = function ($url) use ($manifests) { foreach ($manifests as $v => $m) if (strpos($url, "/$v/") !== false) return $m; return null; };

echo "=== Build Pak release pick: stable first, pre-release fallback ===\n";

// 1. Only a pre-release exists, toggle OFF (the 2.59 situation): fall back, flagged.
$r = vibesPakPickRelease([$rel('1.0.0', true)], false, $fetch);
$r['result'] && $r['result']['version'] === '1.0.0' ? ok("toggle off, only pre-release 1.0.0 published: it is picked anyway") : bad("fallback pick", json_encode($r));
!empty($r['fallback']) && !empty($r['result']['fallback']) && !empty($r['result']['prerelease']) ? ok("the pick is flagged fallback + prerelease") : bad("fallback flags", json_encode($r['result']));
isset($r['result']['note']) && strpos($r['result']['note'], 'No stable Build Pak') === 0 ? ok("the note says no stable pak exists yet") : bad("note", $r['result']['note'] ?? '');

// 2. Stable AND a newer pre-release, toggle OFF: the stable wins (no fallback).
$r = vibesPakPickRelease([$rel('1.2.0', true), $rel('1.1.0', false), $rel('1.0.0', true)], false, $fetch);
$r['result'] && $r['result']['version'] === '1.1.0' && empty($r['fallback']) ? ok("toggle off, stable 1.1.0 beats newer pre-release 1.2.0; not a fallback") : bad("stable wins", json_encode($r['result']));
empty($r['result']['prerelease']) && !isset($r['result']['note']) ? ok("a stable pick carries no pre-release flag and no note") : bad("stable flags", json_encode($r['result']));

// 3. Same list, toggle ON: the newest release of either kind wins.
$r = vibesPakPickRelease([$rel('1.2.0', true), $rel('1.1.0', false), $rel('1.0.0', true)], true, $fetch);
$r['result'] && $r['result']['version'] === '1.2.0' && !empty($r['result']['prerelease']) && empty($r['fallback']) ? ok("toggle on, pre-release 1.2.0 is chosen directly (not a fallback)") : bad("toggle on pick", json_encode($r['result']));

// 4. Controls: no assets at all -> nothing, sawAny false; incompatible pak -> nothing, listed.
$r = vibesPakPickRelease([$rel('1.0.0', true, false)], false, $fetch);
$r['result'] === null && $r['sawAny'] === false ? ok("control: a release without the assets is not a candidate (sawAny=false)") : bad("no-asset control", json_encode($r));
$r = vibesPakPickRelease([$rel('9.0.0', false)], false, $fetch);
$r['result'] === null && $r['sawAny'] === true && count($r['incompatible']) === 1 ? ok("control: an incompatible pak is seen, refused and named: " . $r['incompatible'][0]) : bad("incompatible control", json_encode($r));

// 5. Drafts are never candidates, pre-release or not.
$d = $rel('1.2.0', false); $d['draft'] = true;
$r = vibesPakPickRelease([$d, $rel('1.0.0', true)], false, $fetch);
$r['result'] && $r['result']['version'] === '1.0.0' ? ok("a draft stable is skipped; the pre-release fallback is used") : bad("draft skipped", json_encode($r['result']));

echo "=== $pass passed, $fail failed ===\n";
exit($fail ? 1 : 0);
