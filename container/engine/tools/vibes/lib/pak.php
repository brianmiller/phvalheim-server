<?php
/**
 * Hugin's Vibes -- the Build Pak: find, download, verify, extract, self-test, swap (design 6.1, 8).
 *
 * Three states, kept apart exactly as includes/git.php does for client releases: a release found,
 * NO compatible release (a real answer), and COULD NOT FIND OUT (network, rate limit, bad JSON).
 * A failure is never rendered as "no release"; the UI shows the error sentence verbatim.
 */
require_once '/opt/stateless/nginx/www/includes/vibes.php';
require_once __DIR__ . '/env.php';

const VIBES_RELEASE_CACHE_TTL = 3600;

function vibesReleaseCacheFile() { return vibesDownloadsDir() . '/release-cache.json'; }

/** curl GET returning [code, body, err]; small bodies only (API JSON, manifests, .sha256). */
function vibesHttpGet($url, $headers = [], $timeout = 20) {
	$ch = curl_init($url);
	curl_setopt_array($ch, [
		CURLOPT_RETURNTRANSFER => true, CURLOPT_FOLLOWLOCATION => true, CURLOPT_MAXREDIRS => 5,
		CURLOPT_CONNECTTIMEOUT => 10, CURLOPT_TIMEOUT => $timeout,
		CURLOPT_USERAGENT => 'phvalheim-server-vibes', CURLOPT_HTTPHEADER => $headers,
	]);
	$body = curl_exec($ch);
	$code = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
	$err  = curl_error($ch);
	curl_close($ch);
	return [$code, $body === false ? '' : $body, $err];
}

/** Content-Length via HEAD, or null. */
function vibesHttpSize($url) {
	$ch = curl_init($url);
	curl_setopt_array($ch, [CURLOPT_NOBODY => true, CURLOPT_FOLLOWLOCATION => true, CURLOPT_RETURNTRANSFER => true,
	                        CURLOPT_CONNECTTIMEOUT => 10, CURLOPT_TIMEOUT => 20, CURLOPT_USERAGENT => 'phvalheim-server-vibes']);
	curl_exec($ch);
	$len = curl_getinfo($ch, CURLINFO_CONTENT_LENGTH_DOWNLOAD);
	curl_close($ch);
	return $len > 0 ? (int)$len : null;
}

/** Read <top>/manifest.json out of a pak tarball without extracting it (file:// overrides). */
function vibesPakManifestFromTar($tarFile) {
	$list = vibesRun(['/usr/bin/tar', '-tzf', $tarFile], null, null, 300);
	if (!$list['ok']) return null;
	$best = null;
	foreach (explode("\n", $list['out']) as $m) {
		$m = trim($m);
		if ($m === '' || basename($m) !== 'manifest.json') continue;
		// The pak's own manifest is at the top or one directory down; nuget packages can carry
		// deeper manifest.json files that are not ours.
		$depth = substr_count(trim($m, '/'), '/');
		if ($depth <= 1 && ($best === null || $depth < substr_count(trim($best, '/'), '/'))) $best = $m;
	}
	if ($best === null) return null;
	$r = vibesRun(['/usr/bin/tar', '-xzOf', $tarFile, $best], null, null, 300);
	if (!$r['ok']) return null;
	$j = json_decode($r['out'], true);
	return is_array($j) ? $j : null;
}

function vibesPakCompatible($m) {
	return is_array($m)
	    && in_array((int)($m['pakFormat'] ?? 0), VIBES_PAK_FORMATS, true)
	    && (string)($m['base'] ?? '') === VIBES_PAK_BASE;
}

/**
 * Which Build Pak would we install? {success, version, sizeBytes, components, pakFormat, base,
 * url, sha256Url, prerelease, error}. Honours buildpak.override_url (an https:// tarball or a
 * file:///opt/stateful/... path) and advanced.allow_prerelease_pak. Cached one hour unless $force.
 */
function vibesPakReleaseInfo(array $doc, $force = false) {
	$base = ['success' => false, 'version' => null, 'sizeBytes' => null, 'components' => null, 'pakFormat' => null,
	         'base' => null, 'url' => null, 'sha256Url' => null, 'prerelease' => false, 'error' => null];
	$override = trim((string)vibesSetting($doc, 'buildpak.override_url', ''));
	if ($override !== '') {
		if (strncmp($override, 'file://', 7) === 0) {
			$path = substr($override, 7);
			if (strncmp($path, '/opt/stateful/', 14) !== 0 || !is_file($path)) {
				return ['error' => "Build Pak override '$override' is not a readable file under /opt/stateful."] + $base;
			}
			$m = vibesPakManifestFromTar($path);
			if ($m === null) return ['error' => "The tarball at '$path' has no manifest.json at its top level -- it is not a Build Pak."] + $base;
			if (!vibesPakCompatible($m)) return ['error' => sprintf("The pak at '%s' is pakFormat %s for '%s'; this server accepts formats %s for '%s'.", $path, $m['pakFormat'] ?? '?', $m['base'] ?? '?', implode(',', VIBES_PAK_FORMATS), VIBES_PAK_BASE)] + $base;
			return ['success' => true, 'version' => (string)($m['version'] ?? 'local'), 'sizeBytes' => (int)filesize($path),
			        'components' => $m['components'] ?? null, 'pakFormat' => (int)$m['pakFormat'], 'base' => $m['base'],
			        'url' => $override, 'sha256Url' => is_file($path . '.sha256') ? 'file://' . $path . '.sha256' : null, 'prerelease' => false, 'error' => null];
		}
		if (strncmp($override, 'https://', 8) !== 0) return ['error' => 'Build Pak override must be https:// or file:///opt/stateful/...'] + $base;
		// Version and components are only known after download (install fills them from the
		// extracted manifest); size from a HEAD so the disk check can still run.
		return ['success' => true, 'version' => null, 'sizeBytes' => vibesHttpSize($override), 'url' => $override,
		        'sha256Url' => $override . '.sha256', 'components' => null, 'pakFormat' => null, 'base' => null, 'prerelease' => false, 'error' => null];
	}

	$repo = (string)vibesSetting($doc, 'buildpak.repo', 'brianmiller/phvalheim-vibes-buildpak');
	$allowPre = (bool)vibesSetting($doc, 'advanced.allow_prerelease_pak', false);
	$cacheFile = vibesReleaseCacheFile();
	$cache = vibesReadJson($cacheFile, []);
	$key = $repo . '|' . ($allowPre ? 'pre' : 'stable');
	clearstatcache();
	if (!$force && isset($cache[$key]) && isset($cache[$key]['_t']) && (time() - (int)$cache[$key]['_t']) < VIBES_RELEASE_CACHE_TTL) {
		$c = $cache[$key]; unset($c['_t']);
		return $c + $base;
	}

	// 60 unauthenticated requests per hour per IP, shared with everything behind this NAT
	// (git.php learned this the hard way). One list call plus one manifest fetch per candidate.
	[$code, $body, $err] = vibesHttpGet("https://api.github.com/repos/$repo/releases?per_page=20",
	                                    ['Accept: application/vnd.github+json', 'X-GitHub-Api-Version: 2022-11-28']);
	if ($code !== 200 || ($rows = json_decode($body, true)) === null || !is_array($rows)) {
		$stale = $cache[$key] ?? null;
		if (is_array($stale) && !empty($stale['success'])) { unset($stale['_t']); return $stale + $base; }
		$why = $code === 403 || $code === 429 ? 'GitHub API rate limit reached; try again in an hour' : ($err !== '' ? "curl: $err" : "HTTP $code");
		return ['error' => "Could not look up Build Pak releases for $repo ($why)."] + $base;
	}

	$fetch = function ($url) { [$mc, $mb] = vibesHttpGet($url); return $mc === 200 ? json_decode($mb, true) : null; };
	$pick = vibesPakPickRelease($rows, $allowPre, $fetch);
	$result = $pick['result'];
	if ($result === null) {
		$result = ['success' => false, 'error' => !$pick['sawAny']
			? "No Build Pak release in $repo carries the expected assets (checked stable and pre-release)."
			: 'No Build Pak release fits this server (' . VIBES_PAK_BASE . ', formats ' . implode(',', VIBES_PAK_FORMATS) . '); seen: ' . implode(', ', $pick['incompatible']) . '.'] + $base;
	}
	$cache[$key] = $result + ['_t' => time()];
	vibesWriteJson($cacheFile, $cache);
	return $result;
}

/**
 * Choose the release to install from GitHub's release list. PURE apart from $fetchManifest, so
 * dev_tools/test-vibes-pak-prerelease-fallback.php can drive it with fixture rows.
 *
 * Order of preference: the newest STABLE release that carries the assets and fits this server.
 * When there is none and pre-releases are not allowed, the newest fitting PRE-RELEASE is
 * returned anyway, flagged `fallback` with a `note` -- during a release cycle the only pak that
 * exists may be a pre-release (2.59: Build Pak 1.0.0), and the first operator to test hit
 * "no release carries the expected assets" with a hidden Advanced toggle as the only way out.
 * A stable release, once published, wins over any pre-release regardless of the toggle's
 * default; the toggle only matters when an operator wants a NEWER pre-release over a stable.
 *
 * Returns ['result' => array|null, 'sawAny' => bool, 'incompatible' => [..], 'fallback' => bool].
 */
function vibesPakPickRelease(array $rows, $allowPre, callable $fetchManifest) {
	// $mode: 'stable' (pre-releases skipped), 'all', or 'pre' (ONLY pre-releases -- the fallback
	// pass must not rescan, refetch and re-list the stable rows the first pass already judged).
	$scan = function ($mode) use ($rows, $fetchManifest) {
		$sawAny = false; $incompatible = [];
		foreach ($rows as $rel) {
			if (!empty($rel['draft'])) continue;
			$isPre = !empty($rel['prerelease']);
			if ($mode === 'stable' && $isPre) continue;
			if ($mode === 'pre' && !$isPre) continue;
			$tag = ltrim((string)($rel['tag_name'] ?? ''), 'v');
			$assets = [];
			foreach ($rel['assets'] ?? [] as $a) $assets[$a['name']] = $a;
			$tarName = sprintf(VIBES_PAK_ASSET, $tag);
			if (!isset($assets[$tarName]) || !isset($assets['manifest.json'])) continue;
			$sawAny = true;
			$m = $fetchManifest($assets['manifest.json']['browser_download_url']);
			if (!is_array($m)) continue;
			if (!vibesPakCompatible($m)) { $incompatible[] = sprintf('%s (format %s, %s)', $tag, $m['pakFormat'] ?? '?', $m['base'] ?? '?'); continue; }
			return [[
				'success' => true, 'version' => (string)($m['version'] ?? $tag), 'sizeBytes' => (int)($assets[$tarName]['size'] ?? ($m['sizeBytes'] ?? 0)),
				'components' => $m['components'] ?? null, 'pakFormat' => (int)$m['pakFormat'], 'base' => $m['base'],
				'url' => $assets[$tarName]['browser_download_url'], 'sha256Url' => $assets[$tarName . '.sha256']['browser_download_url'] ?? null,
				'prerelease' => !empty($rel['prerelease']), 'published' => $rel['published_at'] ?? null, 'error' => null,
			], $sawAny, $incompatible];
		}
		return [null, $sawAny, $incompatible];
	};
	[$r, $sawAny, $incompatible] = $scan($allowPre ? 'all' : 'stable');
	$fallback = false;
	if ($r === null && !$allowPre) {
		[$r2, $sawAny2, $incompatible2] = $scan('pre');
		$sawAny = $sawAny || $sawAny2; $incompatible = array_merge($incompatible, $incompatible2);
		if ($r2 !== null) {
			$r = $r2; $fallback = true;
			$r['fallback'] = true;
			$r['note'] = 'No stable Build Pak has been released yet; this is pre-release ' . $r['version'] . '. A stable release will be preferred automatically once one exists.';
		}
	}
	return ['result' => $r, 'sawAny' => $sawAny, 'incompatible' => $incompatible, 'fallback' => $fallback];
}

/**
 * Download $url to $dest with progress events at every >= 2%. Supports a file:// source (a copy)
 * so the dev box can install from a local tarball through the same code path.
 */
function vibesPakDownload($url, $dest, $sizeHint, $onProgress, $isCancelled = null) {
	@mkdir(dirname($dest), 0775, true);
	if (strncmp($url, 'file://', 7) === 0) {
		$src = substr($url, 7);
		if (!@copy($src, $dest)) return [false, "could not copy $src"];
		$onProgress(100, filesize($dest), filesize($dest));
		return [true, null];
	}
	$fh = @fopen($dest, 'wb');
	if (!$fh) return [false, "cannot write $dest"];
	$lastPct = -1; $cancelled = false;
	$ch = curl_init($url);
	curl_setopt_array($ch, [
		CURLOPT_FILE => $fh, CURLOPT_FOLLOWLOCATION => true, CURLOPT_MAXREDIRS => 5, CURLOPT_FAILONERROR => true,
		CURLOPT_CONNECTTIMEOUT => 20, CURLOPT_TIMEOUT => 7200, CURLOPT_LOW_SPEED_LIMIT => 1024, CURLOPT_LOW_SPEED_TIME => 120,
		CURLOPT_USERAGENT => 'phvalheim-server-vibes', CURLOPT_NOPROGRESS => false,
		CURLOPT_PROGRESSFUNCTION => function ($res, $dlTotal, $dlNow) use (&$lastPct, &$cancelled, $onProgress, $sizeHint, $isCancelled) {
			$total = $dlTotal > 0 ? $dlTotal : (int)$sizeHint;
			if ($total > 0) {
				$pct = (int)floor($dlNow * 100 / $total);
				if ($pct >= $lastPct + 2 || ($pct === 100 && $lastPct !== 100)) {
					$lastPct = $pct;
					$onProgress($pct, $dlNow, $total);
					if ($isCancelled && $isCancelled()) { $cancelled = true; return 1; } // non-zero aborts curl
				}
			}
			return 0;
		},
	]);
	$ok = curl_exec($ch);
	$err = curl_error($ch);
	curl_close($ch);
	fclose($fh);
	if ($cancelled) return [false, 'cancelled'];
	if ($ok === false || !is_file($dest) || filesize($dest) === 0) { @unlink($dest); return [false, "download failed: " . ($err ?: 'empty file')]; }
	return [true, null];
}

/** Expected sha256 from a .sha256 asset ("<hex>  <name>" or bare hex); null when unavailable. */
function vibesPakExpectedSha($sha256Url) {
	if (!$sha256Url) return null;
	if (strncmp($sha256Url, 'file://', 7) === 0) $body = (string)@file_get_contents(substr($sha256Url, 7));
	else { [$c, $body] = vibesHttpGet($sha256Url); if ($c !== 200) return null; }
	return preg_match('/\b([a-f0-9]{64})\b/i', $body, $m) ? strtolower($m[1]) : null;
}

/**
 * The whole pak install: download -> sha256 -> extract to <ver>.tmp -> selftest -> rename -> swap
 * `current` -> prune. Resumable: a pak directory that already self-tests is swapped in without a
 * download. $step($label, $progress, $detail) reports; $isCancelled() is polled between phases.
 * Returns ['success' => bool, 'version' => ..., 'error' => ...].
 */
function vibesPakInstall(array $info, $step, $isCancelled) {
	$dl = vibesDownloadsDir();
	@mkdir($dl, 0775, true);
	$ver = $info['version'];
	if ($ver !== null && is_dir(vibesPath("buildpak/$ver")) && vibesPakSelftest(vibesPath("buildpak/$ver"))[0]) {
		$step('Checking the toolchain', 90, "Build Pak $ver is already extracted and healthy");
		vibesPakSwapCurrent($ver);
		vibesPakPrune();
		return ['success' => true, 'version' => $ver, 'error' => null];
	}
	$tar = $dl . '/' . ($ver !== null ? sprintf(VIBES_PAK_ASSET, $ver) : 'buildpak-override.tar.gz');
	$sizeTxt = $info['sizeBytes'] ? ' (' . vibesHumanBytes($info['sizeBytes']) . ')' : '';
	$label = "Downloading Build Pak$sizeTxt";
	$step($label, 0, '');
	[$ok, $err] = vibesPakDownload($info['url'], $tar, $info['sizeBytes'], function ($pct, $now, $total) use ($step, $label) {
		$step($label, $pct, vibesHumanBytes($now) . ' of ' . vibesHumanBytes($total));
	}, $isCancelled);
	if (!$ok) { @unlink($tar); return ['success' => false, 'version' => $ver, 'error' => $err === 'cancelled' ? 'cancelled' : "Build Pak download failed: $err"]; }
	if ($isCancelled()) { @unlink($tar); return ['success' => false, 'version' => $ver, 'error' => 'cancelled']; }

	$step('Verifying the download', -1, 'sha256');
	$expected = vibesPakExpectedSha($info['sha256Url'] ?? null);
	$actual = vibesSha256File($tar);
	if ($expected !== null && $expected !== $actual) {
		@unlink($tar);
		return ['success' => false, 'version' => $ver, 'error' => "Build Pak checksum mismatch (expected $expected, got $actual). The download was discarded."];
	}
	if ($expected === null && strncmp($info['url'], 'file://', 7) !== 0 && empty($info['_allowUnverified'])) {
		// A release without its .sha256 asset is a release we did not build. Refuse rather than
		// extract and run an unverified toolchain.
		@unlink($tar);
		return ['success' => false, 'version' => $ver, 'error' => 'The Build Pak release has no .sha256 asset; refusing to install an unverified toolchain.'];
	}

	if ($ver === null) {
		$m = vibesPakManifestFromTar($tar);
		if (!is_array($m) || !vibesPakCompatible($m)) { @unlink($tar); return ['success' => false, 'version' => null, 'error' => 'The downloaded tarball is not a Build Pak this server accepts (manifest.json missing or incompatible).']; }
		$ver = (string)($m['version'] ?? 'override');
	}
	if (!preg_match('/^[A-Za-z0-9._-]{1,32}$/', $ver)) { @unlink($tar); return ['success' => false, 'version' => $ver, 'error' => "Refusing pak version string '$ver'."]; }

	$tmp = vibesPath("buildpak/$ver.tmp");
	vibesRmTree($tmp);
	@mkdir($tmp, 0775, true);
	$step('Unpacking', -1, vibesHumanBytes(filesize($tar)));
	$r = vibesRun(['/usr/bin/tar', '-xzf', $tar, '-C', $tmp], null, null, 1800);
	if (!$r['ok']) { vibesRmTree($tmp); @unlink($tar); return ['success' => false, 'version' => $ver, 'error' => 'Extracting the Build Pak failed: ' . trim(substr($r['err'], 0, 300))]; }
	// A tarball built with a top-level directory is hoisted so <ver>/manifest.json is the layout.
	if (!is_file("$tmp/manifest.json")) {
		$entries = array_values(array_diff(scandir($tmp) ?: [], ['.', '..']));
		if (count($entries) === 1 && is_dir("$tmp/{$entries[0]}") && is_file("$tmp/{$entries[0]}/manifest.json")) {
			$inner = "$tmp/{$entries[0]}";
			foreach (array_diff(scandir($inner) ?: [], ['.', '..']) as $e) @rename("$inner/$e", "$tmp/$e");
			@rmdir($inner);
		}
	}
	if (!is_file("$tmp/manifest.json")) { vibesRmTree($tmp); return ['success' => false, 'version' => $ver, 'error' => 'The extracted Build Pak has no manifest.json at its top level.']; }
	@unlink($tar);
	if ($isCancelled()) { vibesRmTree($tmp); return ['success' => false, 'version' => $ver, 'error' => 'cancelled']; }

	$step('Checking the toolchain', -1, 'selftest.sh');
	[$ok, $detail] = vibesPakSelftest($tmp);
	if (!$ok) { vibesRmTree($tmp); return ['success' => false, 'version' => $ver, 'error' => "The Build Pak failed its self-test: $detail"]; }

	$final = vibesPath("buildpak/$ver");
	vibesRmTree($final);
	if (!@rename($tmp, $final)) return ['success' => false, 'version' => $ver, 'error' => "Could not move the pak into place ($final)."];
	vibesPakSwapCurrent($ver);
	vibesPakPrune();
	vibesPakCleanDownloads();
	return ['success' => true, 'version' => $ver, 'error' => null, 'selftest' => $detail];
}

/** Run <pak>/selftest.sh (or our own minimum when the pak has none). [ok, detail]. */
function vibesPakSelftest($pakDir) {
	$env = vibesPakEnv($pakDir);
	$m = vibesReadJson("$pakDir/manifest.json", null);
	if (!is_array($m)) return [false, 'manifest.json unreadable'];
	$script = $pakDir . '/' . ($m['selftest'] ?? 'selftest.sh');
	if (is_file($script)) {
		$r = vibesRun(['/bin/bash', $script], $pakDir, $env, 300);
		$text = trim($r['out'] . ($r['err'] !== '' ? "\n" . $r['err'] : ''));
		return [$r['ok'], $r['ok'] ? $text : ($text === '' ? "exit {$r['code']}" : substr($text, -600))];
	}
	// No selftest shipped: the two binaries a build cannot do without.
	foreach (['dotnet', 'git'] as $b) {
		$bin = vibesPakBin($b, $pakDir);
		if (!$bin) return [false, "$b is missing from the pak"];
		$r = vibesRun([$bin, '--version'], $pakDir, $env, 120);
		if (!$r['ok']) return [false, "$b --version failed: " . trim(substr($r['err'] ?: $r['out'], 0, 300))];
	}
	return [true, 'dotnet and git answer --version (pak ships no selftest.sh)'];
}

/** Point buildpak/current at <ver> atomically: symlink to a temp name, rename over current. */
function vibesPakSwapCurrent($ver) {
	$dir = vibesPath('buildpak');
	$tmpLink = "$dir/current.new." . getmypid();
	@unlink($tmpLink);
	if (!@symlink($ver, $tmpLink)) return false;
	return @rename($tmpLink, "$dir/current");
}

/** Remove every pak but the current one and the one before it (the rollback the design keeps). */
function vibesPakPrune() {
	$dir = vibesPath('buildpak');
	$cur = @readlink("$dir/current");
	$paks = [];
	foreach (scandir($dir) ?: [] as $e) {
		if ($e[0] === '.' || $e === 'current' || !is_dir("$dir/$e") || is_link("$dir/$e")) continue;
		if (substr($e, -4) === '.tmp') { vibesRmTree("$dir/$e"); continue; }
		$paks[$e] = filemtime("$dir/$e");
	}
	unset($paks[$cur]);
	arsort($paks);
	$keep = array_slice(array_keys($paks), 0, 1);
	foreach ($paks as $v => $_) if (!in_array($v, $keep, true)) vibesRmTree("$dir/$v");
}

/** downloads/ holds only in-flight files; the release cache is the one thing kept. */
function vibesPakCleanDownloads() {
	$dl = vibesDownloadsDir();
	foreach (scandir($dl) ?: [] as $e) {
		if ($e[0] === '.' || $e === 'release-cache.json') continue;
		if (is_dir("$dl/$e")) vibesRmTree("$dl/$e"); else @unlink("$dl/$e");
	}
}
