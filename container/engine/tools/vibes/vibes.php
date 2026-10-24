#!/usr/bin/php
<?php
/**
 * Hugin's Vibes -- the runner (design section 6).
 *
 *   vibes.php job <id>                       run an hv_jobs row (the ONLY launch path the UI uses)
 *   vibes.php install | uninstall | harvest [world] | build <slug> [Release|Debug] | package <slug>
 *             | publish <slug> [version] | unpublish <slug> <version> | turn <sessionId> <msg-file>
 *             | push <slug> github|gitlab | pak-update | gc        dev-box forms: insert a row, run it inline
 *   vibes.php status                         JSON summary
 *
 * Runs as the phvalheim user (php-fpm launches it detached with setsid/nohup; see
 * includes/vibes.php vibesJobEnqueue). This file is the only one that may read $argv or exit;
 * every lib/*.php is includable from php-fpm.
 */
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }

require_once '/opt/stateless/nginx/www/includes/phvalheim-frontend-config.php';   // $pdo
require_once '/opt/stateless/nginx/www/includes/vibes.php';
require_once '/opt/stateless/nginx/www/includes/events.php';
// The model transport's per-call wall clock. aiproviders.php defines AI_HTTP_TIMEOUT at 120 s,
// sized for a chat reply; a coding turn streams whole source files and the first real turn on
// the dev box died at 120 s with 84 KB already received. The constant is `if (!defined)`, so
// defining it here, BEFORE lib/agent.php pulls aiproviders.php in, sizes it for the Forge loop
// without touching Hugin's chat. Per-call, bounded by the operator's turn timeout.
if (!defined('AI_HTTP_TIMEOUT')) {
	define('AI_HTTP_TIMEOUT', max(120, (int)vibesSetting(vibesSettingsGet($pdo), 'agent.turn_timeout_s', 900)));
}
foreach (['env', 'jobs', 'pak', 'libs', 'git', 'project', 'build', 'package', 'catalog', 'tools', 'agent', 'scm'] as $l) require_once __DIR__ . "/lib/$l.php";

$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
@mkdir(vibesLogsDir() . '/jobs', 0775, true);
$GLOBALS['hvSigTerm'] = false;
if (function_exists('pcntl_async_signals')) {
	pcntl_async_signals(true);
	pcntl_signal(SIGTERM, function () { $GLOBALS['hvSigTerm'] = true; });
}

$cmd = $argv[1] ?? 'status';
$rest = array_slice($argv, 2);

try {
	switch ($cmd) {
		case 'job':
			$id = (int)($rest[0] ?? 0);
			if ($id <= 0) cliDie("usage: vibes.php job <id>");
			exit(runJob($pdo, $id) ? 0 : 1);
		case 'status':
			echo json_encode(cliStatus($pdo), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES), "\n";
			exit(0);
		case 'install':    exit(runInline($pdo, 'install', null, null, ['decompile' => !in_array('--no-decompile', $rest, true)]));
		case 'uninstall':  exit(runInline($pdo, 'uninstall', null, null, ['keep_projects' => in_array('--keep-projects', $rest, true), 'keep_catalog' => in_array('--keep-catalog', $rest, true),
		                                                                    'keep_assets' => in_array('--keep-assets', $rest, true), 'wipe_settings' => in_array('--wipe-settings', $rest, true)]));
		case 'harvest':    exit(runInline($pdo, 'harvest', null, null, isset($rest[0]) ? ['world' => $rest[0]] : []));
		case 'build':      exit(runInline($pdo, 'build', cliProjectId($pdo, $rest[0] ?? ''), null, isset($rest[1]) ? ['configuration' => $rest[1]] : []));
		case 'package':    exit(runInline($pdo, 'package', cliProjectId($pdo, $rest[0] ?? ''), null, []));
		case 'publish':    exit(runInline($pdo, 'publish', cliProjectId($pdo, $rest[0] ?? ''), null, isset($rest[1]) ? ['version' => $rest[1]] : []));
		case 'unpublish':  if (!isset($rest[1])) cliDie('usage: vibes.php unpublish <slug> <version>');
		                   exit(runInline($pdo, 'unpublish', cliProjectId($pdo, $rest[0]), null, ['version' => $rest[1]]));
		case 'turn': {
			$sid = (int)($rest[0] ?? 0); $file = $rest[1] ?? '';
			if ($sid <= 0 || !is_file($file)) cliDie('usage: vibes.php turn <sessionId> <message-file>');
			$s = $pdo->prepare("SELECT project_id FROM hv_sessions WHERE id = ?"); $s->execute([$sid]);
			$projectId = $s->fetchColumn();
			if ($projectId === false) cliDie("no session $sid");
			exit(runInline($pdo, 'turn', (int)$projectId, $sid, ['text' => (string)file_get_contents($file)]));
		}
		case 'push':       if (!isset($rest[1])) cliDie('usage: vibes.php push <slug> github|gitlab');
		                   exit(runInline($pdo, 'push', cliProjectId($pdo, $rest[0]), null, ['remote' => $rest[1]]));
		case 'pak-update': exit(runInline($pdo, 'pak-update', null, null, []));
		case 'gc':         exit(runInline($pdo, 'gc', null, null, []));
		default: cliDie("unknown command '$cmd'. Commands: job status install uninstall harvest build package publish unpublish turn push pak-update gc");
	}
} catch (Throwable $e) {
	fwrite(STDERR, "vibes: " . $e->getMessage() . "\n");
	hvLog('ERROR', 'fatal: ' . $e->getMessage());
	exit(1);
}

/* ------------------------------------------------------------------------------------------ */

function cliDie($msg) { fwrite(STDERR, "$msg\n"); exit(2); }

function cliProjectId(PDO $pdo, $slug) {
	if (!vibesValidSlug($slug)) cliDie("a project slug is required");
	$s = $pdo->prepare("SELECT id FROM hv_projects WHERE slug = ?"); $s->execute([$slug]);
	$id = $s->fetchColumn();
	if ($id === false) cliDie("no project with slug '$slug'");
	return (int)$id;
}

/** The dev-box forms do the SAME work through the SAME functions: insert a row, run it here. */
function runInline(PDO $pdo, $kind, $projectId, $sessionId, array $args) {
	if ($c = vibesJobConflict($pdo, $kind, $projectId)) cliDie($c);
	$s = $pdo->prepare("INSERT INTO hv_jobs (kind, project_id, session_id, status, args) VALUES (?, ?, ?, 'queued', ?)");
	$s->execute([$kind, $projectId, $sessionId, json_encode($args, JSON_UNESCAPED_SLASHES)]);
	$id = (int)$pdo->lastInsertId();
	fwrite(STDERR, "job $id ($kind) -> " . vibesJobLog($id) . "\n");
	$ok = runJob($pdo, $id, true);
	$row = vibesJob($pdo, $id);
	echo json_encode(['jobId' => $id, 'status' => $row['status'], 'error' => $row['error'], 'result' => $row['result']], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES), "\n";
	return $ok ? 0 : 1;
}

function cliStatus(PDO $pdo) {
	$st = vibesState($pdo);
	$st['runner'] = [
		'pakReady' => vibesPakReady(), 'pakMissing' => vibesPakMissing(), 'publicizer' => vibesPakPublicizer(), 'ilspycmd' => vibesPakBin('ilspycmd'),
		'templates' => vibesTemplates(), 'knowledge' => array_column(forgeKnowledgeIndex(), 'topic'),
		'harvestCandidates' => vibesHarvestCandidates(), 'harvestStale' => vibesHarvestStale(),
		'php' => PHP_VERSION, 'user' => function_exists('posix_geteuid') ? posix_getpwuid(posix_geteuid())['name'] : null,
	];
	return $st;
}

/* ------------------------------------------------------------------------------------------
 * Jobs
 * ---------------------------------------------------------------------------------------- */

function runJob(PDO $pdo, $id, $echo = false) {
	$job = hvJobClaim($pdo, $id);
	if (!$job) { hvLog('WARN', "job $id is not queued (already claimed, cancelled or unknown)"); return false; }
	$emit = new HvEmitter(vibesJobLog($id));
	$doc = vibesSettingsGet($pdo);
	vibesGitSettings($doc);
	$isCancelled = function () use ($pdo, $id) { return $GLOBALS['hvSigTerm'] || hvIsCancelled($pdo, $id); };
	$step = function ($label, $progress = -1, $detail = '') use ($pdo, $id, $emit, $isCancelled, $echo) {
		if ($echo) fwrite(STDERR, sprintf("  [%3s%%] %s%s\n", $progress < 0 ? '  -' : $progress, $label, $detail !== '' ? " -- $detail" : ''));
		hvJobProgress($pdo, $id, $emit, $label, $progress, $detail);
		if ($isCancelled()) throw new HvCancelled('cancelled');
	};
	$project = $job['project_id'] ? vibesProjectRow($pdo, $job['project_id']) : null;
	$args = is_array($job['args']) ? $job['args'] : [];
	hvLog('INFO', "job $id {$job['kind']} started" . ($project ? " for {$project['slug']}" : ''));
	try {
		if ($job['project_id'] && !$project) throw new RuntimeException('The project no longer exists.');
		switch ($job['kind']) {
			case 'install':    $r = jobInstall($pdo, $doc, $args, $step, $isCancelled); break;
			case 'uninstall':  $r = jobUninstall($pdo, $doc, $args, $step); break;
			case 'harvest':    $r = jobHarvest($pdo, $doc, $args, $step); break;
			case 'build':      $r = jobBuild($pdo, $doc, $project, $args, $step, $emit); break;
			case 'package':    $r = jobPackage($pdo, $project, $step); break;
			case 'publish':    $r = jobPublish($pdo, $project, $args, $step); break;
			case 'unpublish':  $r = jobUnpublish($pdo, $project, $args, $step); break;
			case 'turn':       $r = jobTurn($pdo, $job, $project, $args, $emit, $isCancelled); break;
			case 'push':       $r = jobPush($pdo, $doc, $project, $args, $step); break;
			case 'pak-update': $r = jobPakUpdate($pdo, $doc, $step, $isCancelled); break;
			case 'gc':         $r = jobGc($pdo, $doc, $step); break;
			default: throw new RuntimeException("Unknown job kind '{$job['kind']}'.");
		}
		if (!is_array($r) || empty($r['success'])) throw new RuntimeException(is_array($r) && isset($r['error']) ? (string)$r['error'] : 'failed');
		unset($r['success']);
		hvJobFinish($pdo, $id, $emit, $r);
		hvLog('INFO', "job $id {$job['kind']} done");
		return true;
	} catch (HvCancelled $e) {
		// vibesJobCancel already wrote status/finished/error; a final event closes the stream.
		$emit->emit(['type' => 'error', 'error' => 'Cancelled by the operator']);
		hvLog('NOTICE', "job $id {$job['kind']} cancelled");
		return false;
	} catch (Throwable $e) {
		$msg = $e->getMessage();
		if ($msg === 'cancelled') { $emit->emit(['type' => 'error', 'error' => 'Cancelled by the operator']); return false; }
		hvJobFail($pdo, $id, $emit, $msg);
		hvLog('ERROR', "job $id {$job['kind']} failed: $msg");
		if ($job['kind'] === 'turn' && $job['session_id']) $pdo->prepare("UPDATE hv_sessions SET status='error' WHERE id=?")->execute([(int)$job['session_id']]);
		return false;
	}
}

/** 6.1. Every step resumable: a healthy pak is not re-downloaded, a fresh harvest is not redone. */
function jobInstall(PDO $pdo, array $doc, array $args, $step, $isCancelled) {
	$step('Checking preconditions', 1);
	$ai = vibesAiStatus($pdo);
	if (!$ai['ok']) return ['success' => false, 'error' => $ai['reason'] . ' Hugin\'s Vibes needs an enabled AI provider with a model.'];
	$step('Finding a Build Pak', 3);
	$info = vibesPakReady() ? ['success' => true, 'version' => vibesPakManifest()['version'] ?? null, 'sizeBytes' => vibesPakManifest()['sizeBytes'] ?? null] : vibesPakReleaseInfo($doc);
	if (!$info['success']) return ['success' => false, 'error' => $info['error']];
	$disk = vibesDiskStatus($info['sizeBytes'] ?? null);
	if (!vibesPakReady() && !$disk['ok']) return ['success' => false, 'error' => sprintf('Not enough free disk: %s free, %s required (2 x pak + 1 GiB).', vibesHumanBytes($disk['free']), vibesHumanBytes($disk['required']))];
	if (!vibesPakReady()) {
		$res = vibesPakInstall($info, $step, $isCancelled);
		if (!$res['success']) return ['success' => false, 'error' => $res['error']];
		$pakVersion = $res['version'];
	} else {
		$pakVersion = (string)(vibesPakManifest()['version'] ?? '');
		$step('Checking the toolchain', 60, "Build Pak $pakVersion already installed");
	}
	$gameBuild = null; $harvested = false;
	if (vibesHarvestSourceWorld($doc) !== null) {
		if (vibesLibsManifest() === null || !is_file(vibesLibsDir() . '/assembly_valheim.dll')) {
			$doc2 = $doc; if (array_key_exists('decompile', $args)) $doc2['libs']['decompile'] = (bool)$args['decompile'];
			$h = vibesHarvest($doc2, function ($l, $p, $d = '') use ($step) { $step($l, 60 + (int)($p * 0.25), $d); });
			if (!$h['success']) return ['success' => false, 'error' => $h['error']];
			$gameBuild = $h['manifest']['gameBuild']; $harvested = true;
		} else { $gameBuild = vibesLibsManifest()['gameBuild'] ?? null; $harvested = true; }
	} else {
		$step('Skipping harvest', 85, 'no world has game files yet');
	}
	$smoke = null;
	if ($harvested) {
		$step('Compiling a test mod', 88, 'basic-plugin template, offline');
		$smoke = smokeBuild($doc);
		if (!$smoke['ok']) return ['success' => false, 'error' => 'The smoke build failed, so the install is not green: ' . $smoke['error']];
	}
	$pdo->prepare("UPDATE settings SET vibesInstalled = 1, vibesBuildpakVersion = ?")->execute([$pakVersion]);
	hvEvent($pdo, 'notice', "Hugin's Vibes installed (Build Pak $pakVersion)" . ($harvested ? '' : ' -- no game files yet; harvest after the first world is created'));
	$step('Installed', 100, "Build Pak $pakVersion");
	return ['success' => true, 'pak' => $pakVersion, 'gameBuild' => $gameBuild, 'harvested' => $harvested, 'smoke' => $smoke ? true : null];
}

/** Compile the basic-plugin template in downloads/smoke. A green smoke build is the only honest "installed". */
function smokeBuild(array $doc) {
	$dir = vibesDownloadsDir() . '/smoke';
	vibesRmTree($dir);
	$vars = ['Name' => 'VibesSmoke', 'Namespace' => 'PhValheim', 'Guid' => 'phvalheim.vibessmoke', 'Description' => 'smoke', 'Version' => '0.0.1', 'Slug' => 'vibes-smoke', 'Year' => date('Y')];
	if (!vibesTemplateCopy(vibesTemplatesDir() . '/basic-plugin', $dir, $vars)) return ['ok' => false, 'error' => 'could not copy the template'];
	$doc['advanced']['nuget_online'] = false;
	[$r, $argsUsed] = vibesBuildRunDotnet($dir, "$dir/VibesSmoke.csproj", 'Release', $doc, 900);
	@file_put_contents(vibesLogsDir() . '/smoke-build.log', implode(' ', $argsUsed) . "\n\n" . $r['out'] . "\n" . $r['err']);
	$diags = vibesBuildParseDiagnostics($r['out'] . "\n" . $r['err']);
	$errors = array_filter($diags, function ($d) { return $d['severity'] === 'error'; });
	$dll = "$dir/bin/Release/net472/VibesSmoke.dll";
	if (!$r['ok'] || $errors || !is_file($dll)) {
		$first = $errors ? array_values($errors)[0] : null;
		return ['ok' => false, 'error' => ($first ? "{$first['code']}: {$first['message']}" : ('dotnet exited ' . $r['code'])) . ' (logs/smoke-build.log)'];
	}
	vibesRmTree($dir);
	return ['ok' => true, 'ms' => $r['ms']];
}

/** 6.5 */
function jobUninstall(PDO $pdo, array $doc, array $args, $step) {
	$keepProjects = !empty($args['keep_projects']); $keepCatalog = !empty($args['keep_catalog']); $keepAssets = !empty($args['keep_assets']);
	$freed = 0; $sizes = function ($d) { return vibesDirSize($d); };
	if (!$keepCatalog) {
		$step('Checking the catalogue', 5);
		$w = vibesCatalogWipe($pdo);
		if (!$w['success']) return $w;
		$freed += $sizes(vibesCatalogDir()); vibesRmTree(vibesCatalogDir()); @mkdir(vibesCatalogDir(), 0775, true);
	}
	$step('Removing the Build Pak and libraries', 30);
	foreach (['buildpak', 'libs', 'downloads', 'logs/jobs'] as $d) { $freed += $sizes(vibesPath($d)); vibesRmTree(vibesPath($d)); @mkdir(vibesPath($d), 0775, true); }
	if (!$keepProjects) {
		$step('Removing projects', 60);
		$freed += $sizes(vibesProjectsDir()); vibesRmTree(vibesProjectsDir()); @mkdir(vibesProjectsDir(), 0775, true);
		$pdo->exec("DELETE FROM hv_sessions"); $pdo->exec("DELETE FROM hv_projects");
		$pdo->exec("DELETE FROM hv_jobs WHERE status NOT IN ('running')");
	}
	if (!$keepAssets) { $step('Removing assets', 80); $freed += $sizes(vibesAssetsDir()); vibesRmTree(vibesAssetsDir()); @mkdir(vibesAssetsDir(), 0775, true); }
	$pdo->exec("UPDATE settings SET vibesInstalled = 0, vibesBuildpakVersion = NULL" . (!empty($args['wipe_settings']) ? ", vibesSettings = NULL" : ''));
	hvEvent($pdo, 'notice', "Hugin's Vibes removed (" . vibesHumanBytes($freed) . ' freed' . ($keepProjects ? ', projects kept' : '') . ($keepCatalog ? ', catalogue kept' : '') . ')');
	$step('Removed', 100, vibesHumanBytes($freed) . ' freed');
	return ['success' => true, 'freedBytes' => $freed, 'keptProjects' => $keepProjects, 'keptCatalog' => $keepCatalog, 'keptAssets' => $keepAssets];
}

function jobHarvest(PDO $pdo, array $doc, array $args, $step) {
	if ($m = vibesPakMissing()) return ['success' => false, 'error' => $m];
	$h = vibesHarvest($doc, $step, isset($args['world']) ? (string)$args['world'] : null);
	if (!$h['success']) return $h;
	hvEvent($pdo, 'notice', "Hugin's Vibes harvested game libraries from world '{$h['manifest']['sourceWorld']}' (build {$h['manifest']['gameBuild']})");
	return ['success' => true, 'gameBuild' => $h['manifest']['gameBuild'], 'sourceWorld' => $h['manifest']['sourceWorld'], 'decompiled' => $h['manifest']['decompiled'], 'files' => count($h['manifest']['files'])];
}

function jobBuild(PDO $pdo, array $doc, array $project, array $args, $step, HvEmitter $emit) {
	$step('Building', -1, $project['name']);
	$b = vibesBuild($project['slug'], $args['configuration'] ?? null, $pdo, $doc);
	$emit->emit(['type' => 'build', 'ok' => $b['ok'], 'errors' => $b['errors'], 'warnings' => $b['warnings'], 'ms' => $b['ms']]);
	if (!$b['ok']) return ['success' => false, 'error' => count($b['errors']) . ' build error(s): ' . vibesSubstr($b['errors'][0]['message'], 0, 200), 'build' => $b];
	return ['success' => true, 'build' => ['ok' => true, 'warnings' => $b['warnings'], 'ms' => $b['ms'], 'log' => $b['log']]];
}

function jobPackage(PDO $pdo, array $project, $step) {
	$step('Packaging', -1, $project['name']);
	$p = vibesPackage($project['slug']);
	if (!$p['success']) return $p;
	return ['success' => true, 'zip' => $p['zip'], 'size' => $p['size'], 'entries' => $p['entries'], 'version' => $p['version']];
}

function jobPublish(PDO $pdo, array $project, array $args, $step) {
	$m = vibesProjectManifestRead($project['slug']);
	$version = isset($args['version']) && vibesValidVersion((string)$args['version']) ? (string)$args['version'] : ($m['version_number'] ?? null);
	if (!$version) return ['success' => false, 'error' => 'manifest.json has no version_number.'];
	$zip = vibesProjectDir($project['slug']) . "/dist/{$project['namespace']}-{$project['name']}-$version.zip";
	if (!is_file($zip)) {
		if ($version !== ($m['version_number'] ?? null)) return ['success' => false, 'error' => "dist has no package for $version and the manifest is at {$m['version_number']}; package that version first."];
		$step('Packaging', 20, $version);
		$p = vibesPackage($project['slug']);
		if (!$p['success']) return $p;
	}
	$step('Publishing to the catalogue', 60, "$version");
	$r = vibesCatalogPublish($pdo, $project, $version);
	if (!$r['success']) return $r;
	hvEvent($pdo, 'notice', "Hugin's Vibes published {$project['namespace']}-{$project['name']} $version to the local catalogue");
	vibesGitTag($project['slug'], "v$version", "Published $version");
	return ['success' => true, 'version' => $version, 'zip' => $r['zip'], 'deps' => $r['deps'], 'modId' => $r['modId']];
}

function jobUnpublish(PDO $pdo, array $project, array $args, $step) {
	$step('Removing from the catalogue', -1, (string)($args['version'] ?? ''));
	$r = vibesCatalogUnpublish($pdo, $project, (string)($args['version'] ?? ''));
	if (!$r['success']) return $r;
	hvEvent($pdo, 'notice', "Hugin's Vibes unpublished {$project['namespace']}-{$project['name']} {$args['version']}");
	return ['success' => true, 'version' => (string)$args['version'], 'removedMod' => $r['removedMod'], 'latest' => $r['latest']];
}

function jobTurn(PDO $pdo, array $job, array $project, array $args, HvEmitter $emit, $isCancelled) {
	if (!$job['session_id']) return ['success' => false, 'error' => 'A turn needs a session.'];
	$s = $pdo->prepare("SELECT * FROM hv_sessions WHERE id = ? AND project_id = ?"); $s->execute([(int)$job['session_id'], (int)$project['id']]);
	$session = $s->fetch(PDO::FETCH_ASSOC);
	if (!$session) return ['success' => false, 'error' => 'No such session for this project.'];
	$emit->attachSession(vibesSessionLog($project['slug'], $session['id']));
	$r = forgeTurn($pdo, $session, $project, (string)($args['text'] ?? ''), $emit, $isCancelled);
	if (!$r['success']) return $r;
	// `done` carries usage/model/rounds (7.3); hvJobFinish adds the type.
	return ['success' => true, 'usage' => $r['usage'], 'model' => $r['model'], 'rounds' => $r['rounds']];
}

function jobPush(PDO $pdo, array $doc, array $project, array $args, $step) {
	$r = vibesScmPush($pdo, $doc, (string)($args['remote'] ?? ''), $project, $step);
	if (!$r['success']) return $r;
	hvEvent($pdo, 'notice', "Hugin's Vibes pushed {$project['name']} to {$r['path']}");
	return ['success' => true, 'url' => $r['url'], 'path' => $r['path'], 'created' => $r['created']];
}

/** Download/verify/selftest/swap a newer pak, smoke build, then prune (the previous pak stays). */
function jobPakUpdate(PDO $pdo, array $doc, $step, $isCancelled) {
	$step('Finding a Build Pak', 2);
	$info = vibesPakReleaseInfo($doc, true);
	if (!$info['success']) return ['success' => false, 'error' => $info['error']];
	$cur = vibesPakManifest()['version'] ?? null;
	if ($info['version'] !== null && $cur !== null && $info['version'] === $cur) { $step('Up to date', 100, "Build Pak $cur"); return ['success' => true, 'pak' => $cur, 'updated' => false]; }
	$disk = vibesDiskStatus($info['sizeBytes'] ?? null);
	if (!$disk['ok']) return ['success' => false, 'error' => sprintf('Not enough free disk: %s free, %s required.', vibesHumanBytes($disk['free']), vibesHumanBytes($disk['required']))];
	$res = vibesPakInstall($info, $step, $isCancelled);
	if (!$res['success']) return ['success' => false, 'error' => $res['error']];
	if (vibesLibsManifest() !== null) {
		$step('Compiling a test mod', 92, 'with the new pak');
		$smoke = smokeBuild($doc);
		if (!$smoke['ok']) {
			// Roll back to the pak that worked: it was kept for exactly this.
			if ($cur !== null && is_dir(vibesPath("buildpak/$cur"))) vibesPakSwapCurrent($cur);
			return ['success' => false, 'error' => "The new Build Pak {$res['version']} failed the smoke build and was rolled back to $cur: {$smoke['error']}"];
		}
	}
	$pdo->prepare("UPDATE settings SET vibesBuildpakVersion = ?")->execute([$res['version']]);
	$doc['buildpak']['available'] = null; $doc['buildpak']['last_check'] = date('c');
	vibesSettingsSave($pdo, $doc);
	hvEvent($pdo, 'notice', "Hugin's Vibes updated the Build Pak to {$res['version']}" . ($cur ? " (from $cur)" : ''));
	$step('Updated', 100, "Build Pak {$res['version']}");
	return ['success' => true, 'pak' => $res['version'], 'previous' => $cur, 'updated' => true];
}

/** bin/obj of every project, in-flight downloads, build logs beyond the last 20, transcripts past their retention. */
function jobGc(PDO $pdo, array $doc, $step) {
	$freed = 0; $before = vibesDirSize(VIBES_ROOT);
	$step('Pruning build output', 10);
	foreach (glob(vibesProjectsDir() . '/*', GLOB_ONLYDIR) ?: [] as $p) {
		foreach (['bin', 'obj'] as $d) vibesRmTree("$p/$d");
		$logs = glob("$p/.vibes/builds/*.log") ?: [];
		usort($logs, function ($a, $b) { return (int)basename($b, '.log') <=> (int)basename($a, '.log'); });
		foreach (array_slice($logs, 20) as $old) @unlink($old);
		$days = (int)vibesSetting($doc, 'advanced.keep_transcripts_days', 0);
		if ($days > 0) foreach (glob("$p/.vibes/sessions/*.jsonl") ?: [] as $t) if (filemtime($t) < time() - $days * 86400) @unlink($t);
	}
	$step('Cleaning downloads', 60);
	vibesPakCleanDownloads();
	vibesPakPrune();
	$step('Pruning job logs', 80);
	foreach (glob(vibesLogsDir() . '/jobs/*.jsonl') ?: [] as $f) if (filemtime($f) < time() - 30 * 86400) @unlink($f);
	$freed = max(0, $before - vibesDirSize(VIBES_ROOT));
	$step('Done', 100, vibesHumanBytes($freed) . ' freed');
	return ['success' => true, 'freedBytes' => $freed];
}
