<?php
/**
 * Hugin's Vibes -- the shared core (2.59).
 *
 * Everything that BOTH the web side (admin/vibesAPI.php, admin/hugins_vibes.php) and the
 * detached runner (engine/tools/vibes/vibes.php, run by the PHP CLI as the phvalheim user) need
 * to agree on lives here, once: paths, the settings document and its defaults, job rows and how
 * a job process is launched, identity validation, the state summary the page opens with.
 *
 * Read docs/HUGINS-VIBES-DESIGN.md first. Section numbers in comments refer to it.
 *
 * Rules:
 *   - No HTML here. No echo. Return values only.
 *   - Nothing here is root. The engine creates /opt/stateful/vibes at boot and chowns it to
 *     phvalheim; php-fpm and the runner both run as phvalheim.
 *   - Secrets (SCM tokens) never leave this file unmasked except to the runner, which reads
 *     them with vibesSettingsGet() directly. vibesSettingsForClient() is the ONLY shape the
 *     browser may receive.
 */

if (!defined('VIBES_ROOT'))          define('VIBES_ROOT', '/opt/stateful/vibes');
if (!defined('VIBES_TOOLS'))         define('VIBES_TOOLS', '/opt/stateless/engine/tools/vibes');
if (!defined('VIBES_PAK_FORMATS'))   define('VIBES_PAK_FORMATS', [1]);     // pak layouts this server understands (section 8)
if (!defined('VIBES_PAK_BASE'))      define('VIBES_PAK_BASE', 'jammy');    // the image's distro codename; a pak must match
if (!defined('VIBES_PAK_ASSET'))     define('VIBES_PAK_ASSET', 'phvalheim-vibes-buildpak-%s-linux-x64.tar.gz');
if (!defined('VIBES_SECRET_MASK'))   define('VIBES_SECRET_MASK', "\u{2022}\u{2022}\u{2022}\u{2022}");
if (!defined('VIBES_JOB_KINDS'))     define('VIBES_JOB_KINDS', ['install', 'uninstall', 'harvest', 'build', 'package', 'publish', 'unpublish', 'turn', 'push', 'pak-update', 'gc', 'import', 'scm-sync', 'publish-hosted']);

/* ------------------------------------------------------------------------------------------
 * Paths (section 3)
 * ---------------------------------------------------------------------------------------- */

function vibesPath($sub = '') {
	return rtrim(VIBES_ROOT . '/' . ltrim($sub, '/'), '/');
}
function vibesPakCurrent()        { return vibesPath('buildpak/current'); }
function vibesLibsDir()           { return vibesPath('libs'); }
function vibesProjectsDir()       { return vibesPath('projects'); }
function vibesProjectDir($slug)   { return vibesProjectsDir() . '/' . $slug; }
function vibesAssetsDir()         { return vibesPath('assets'); }
function vibesCatalogDir()        { return vibesPath('catalog'); }
function vibesDownloadsDir()      { return vibesPath('downloads'); }
function vibesLogsDir()           { return vibesPath('logs'); }
function vibesJobLog($jobId)      { return vibesLogsDir() . '/jobs/' . (int)$jobId . '.jsonl'; }
function vibesSessionLog($slug, $sessionId) { return vibesProjectDir($slug) . '/.vibes/sessions/' . (int)$sessionId . '.jsonl'; }

/** The pak's manifest.json as an array, or null when no pak is extracted. */
function vibesPakManifest() {
	$f = vibesPakCurrent() . '/manifest.json';
	if (!is_file($f)) return null;
	$m = json_decode((string)@file_get_contents($f), true);
	return is_array($m) ? $m : null;
}

/** libs/manifest.json (section 6.2), or null before the first harvest. */
function vibesLibsManifest() {
	$f = vibesLibsDir() . '/manifest.json';
	if (!is_file($f)) return null;
	$m = json_decode((string)@file_get_contents($f), true);
	return is_array($m) ? $m : null;
}

/* ------------------------------------------------------------------------------------------
 * Settings document (section 4.1)
 * ---------------------------------------------------------------------------------------- */

function vibesSettingsDefaults() {
	return [
		'schema'   => 1,
		'author'   => ['namespace' => '', 'display' => '', 'url' => ''],
		'defaults' => ['template' => 'basic-plugin', 'provider_id' => null, 'model' => '', 'configuration' => 'Release'],
		'agent'    => ['max_rounds' => 40, 'turn_timeout_s' => 900, 'model_timeout_s' => 900, 'unrestricted_shell' => false,
		               'context_budget_tokens' => 60000, 'auto_build' => true, 'auto_commit' => true],
		'libs'     => ['source_world' => '', 'decompile' => true, 'publicize' => true],
		'buildpak' => ['repo' => 'brianmiller/phvalheim-vibes-buildpak', 'channel' => 'stable',
		               'auto_check' => true, 'last_check' => null, 'available' => null,
		               // A local file or URL that replaces the GitHub lookup entirely. Exists for
		               // the dev box and for air-gapped operators; empty = GitHub (section 13.5).
		               'override_url' => ''],
		'assets'   => ['max_upload_mb' => 64],
		'thunderstore' => ['token'=>'', 'team'=>'', 'categories'=>''],
		'hexium' => ['token'=>'', 'team'=>'', 'categories'=>''],
		'github'   => ['enabled' => false, 'token' => '', 'owner' => '', 'visibility' => 'private', 'api' => 'https://api.github.com'],
		'gitlab'   => ['enabled' => false, 'token' => '', 'host' => 'https://gitlab.com', 'group' => '', 'visibility' => 'private'],
		'advanced' => ['keep_transcripts_days' => 0, 'log_level' => 'info', 'nuget_online' => true,
		               'dotnet_verbosity' => 'minimal', 'allow_prerelease_pak' => false],
	];
}

/** Recursive merge where $over wins but only for keys the defaults know (unknown keys are kept too). */
function vibesMergeSettings($defaults, $over) {
	if (!is_array($over)) return $defaults;
	foreach ($over as $k => $v) {
		if (isset($defaults[$k]) && is_array($defaults[$k]) && is_array($v) && !array_is_list($defaults[$k])) {
			$defaults[$k] = vibesMergeSettings($defaults[$k], $v);
		} else {
			$defaults[$k] = $v;
		}
	}
	return $defaults;
}

/** The settings document merged over the defaults. Never fails: a broken column reads as defaults. */
function vibesSettingsGet($pdo) {
	$doc = null;
	try {
		$raw = $pdo->query("SELECT vibesSettings FROM settings LIMIT 1")->fetchColumn();
		if ($raw !== false && $raw !== null && $raw !== '') $doc = json_decode((string)$raw, true);
	} catch (Throwable $e) { $doc = null; }
	return vibesMergeSettings(vibesSettingsDefaults(), is_array($doc) ? $doc : []);
}

/** Persist a full document (already merged). Returns true/false. */
function vibesSettingsSave($pdo, $doc) {
	$doc['schema'] = 1;
	$s = $pdo->prepare("UPDATE settings SET vibesSettings = ?");
	return $s->execute([json_encode($doc, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)]);
}

/** Dotted-path read: vibesSetting($doc, 'agent.max_rounds', 40). */
function vibesSetting($doc, $path, $default = null) {
	$cur = $doc;
	foreach (explode('.', $path) as $k) {
		if (!is_array($cur) || !array_key_exists($k, $cur)) return $default;
		$cur = $cur[$k];
	}
	return $cur;
}

/** Secret fields, as dotted paths. The single list every mask/unmask step reads. */
function vibesSecretPaths() { return ['github.token', 'gitlab.token', 'thunderstore.token', 'hexium.token']; }

function vibesMaskSecret($v) {
	$v = (string)$v;
	if ($v === '') return '';
	return VIBES_SECRET_MASK . substr($v, -4);
}
function vibesIsMaskedSecret($v) {
	return is_string($v) && strncmp($v, VIBES_SECRET_MASK, strlen(VIBES_SECRET_MASK)) === 0;
}

/** The ONLY shape the browser may receive: tokens masked to "••••" + last four. */
function vibesSettingsForClient($doc) {
	foreach (vibesSecretPaths() as $p) {
		[$a, $b] = explode('.', $p, 2);
		if (isset($doc[$a][$b])) $doc[$a][$b] = vibesMaskSecret($doc[$a][$b]);
	}
	return $doc;
}

/**
 * Apply a client-submitted document over the stored one. A masked token means "unchanged";
 * an empty string means "clear it". Unknown top-level keys are dropped; the schema is ours.
 * Returns [doc, errors[]] -- errors are human sentences, the caller decides what to do.
 */
function vibesSettingsApplyClient($stored, $incoming) {
	$errors = [];
	if (!is_array($incoming)) return [$stored, ['Settings must be an object.']];
	$known = array_keys(vibesSettingsDefaults());
	foreach ($incoming as $k => $v) if (!in_array($k, $known, true)) unset($incoming[$k]);
	foreach (vibesSecretPaths() as $p) {
		[$a, $b] = explode('.', $p, 2);
		if (isset($incoming[$a]) && array_key_exists($b, $incoming[$a]) && vibesIsMaskedSecret($incoming[$a][$b])) {
			$incoming[$a][$b] = $stored[$a][$b] ?? '';
		}
	}
	$doc = vibesMergeSettings($stored, $incoming);

	// Validation of the fields whose wrong values would hurt later rather than now.
	$ns = (string)vibesSetting($doc, 'author.namespace', '');
	if ($ns !== '' && !vibesValidNamespace($ns)) $errors[] = 'Author namespace may contain letters, digits and underscores only (it becomes the catalogue owner).';
	foreach (['agent.max_rounds' => [1, 200], 'agent.turn_timeout_s' => [30, 7200], 'agent.model_timeout_s' => [30, 7200], 'agent.context_budget_tokens' => [4000, 400000], 'assets.max_upload_mb' => [1, 1024], 'advanced.keep_transcripts_days' => [0, 3650]] as $path => [$lo, $hi]) {
		$v = vibesSetting($doc, $path);
		if (!is_numeric($v) || $v < $lo || $v > $hi) $errors[] = "$path must be a number between $lo and $hi.";
	}
	if (!in_array(vibesSetting($doc, 'defaults.configuration'), ['Release', 'Debug'], true)) $errors[] = 'Configuration must be Release or Debug.';
	if (!in_array(vibesSetting($doc, 'advanced.dotnet_verbosity'), ['quiet', 'minimal', 'normal', 'detailed'], true)) $errors[] = 'dotnet verbosity must be quiet, minimal, normal or detailed.';
	if (!in_array(vibesSetting($doc, 'advanced.log_level'), ['debug', 'info', 'warn'], true)) $errors[] = 'Log level must be debug, info or warn.';
	foreach (['github.visibility', 'gitlab.visibility'] as $p) {
		if (!in_array(vibesSetting($doc, $p), ['private', 'public', 'internal'], true)) $errors[] = "$p must be private, public or internal.";
	}
	foreach (['gitlab.host', 'github.api'] as $p) {
		$u = (string)vibesSetting($doc, $p, '');
		if ($u !== '' && !preg_match('~^https://[A-Za-z0-9.-]+(:\d+)?(/.*)?$~', $u)) $errors[] = "$p must be an https:// URL.";
	}
	$repo = (string)vibesSetting($doc, 'buildpak.repo', '');
	if (!preg_match('~^[A-Za-z0-9_.-]+/[A-Za-z0-9_.-]+$~', $repo)) $errors[] = 'Build Pak repository must look like owner/repo.';
	$ov = (string)vibesSetting($doc, 'buildpak.override_url', '');
	if ($ov !== '' && !preg_match('~^(https://|file:///opt/stateful/)~', $ov)) $errors[] = 'Build Pak override must be an https:// URL or a file:///opt/stateful/... path.';
	return [$doc, $errors];
}

/* ------------------------------------------------------------------------------------------
 * Identity (section 5): what may be a project name, namespace, slug, version
 * ---------------------------------------------------------------------------------------- */

/** Thunderstore's rule for package names, which is also a valid C# identifier start. */
function vibesValidName($name)      { return is_string($name) && preg_match('/^[A-Za-z][A-Za-z0-9_]{1,95}$/', $name) === 1; }
function vibesValidNamespace($ns)   { return is_string($ns)   && preg_match('/^[A-Za-z0-9_]{1,80}$/', $ns) === 1; }
function vibesValidVersion($v)      { return is_string($v)    && preg_match('/^\d+\.\d+\.\d+$/', $v) === 1; }
function vibesValidSlug($s)         { return is_string($s)    && preg_match('/^[a-z0-9][a-z0-9-]{0,63}$/', $s) === 1; }

/** A directory-safe slug from a mod name: NoMovementPenalty -> no-movement-penalty. */
function vibesSlugify($name) {
	$s = preg_replace('/([a-z0-9])([A-Z])/', '$1-$2', (string)$name);
	$s = strtolower(preg_replace('/[^A-Za-z0-9]+/', '-', $s));
	$s = trim(preg_replace('/-+/', '-', $s), '-');
	return substr($s === '' ? 'vibe' : $s, 0, 64);
}

/** The BepInEx GUID a project uses; fixed at creation and never changed after publishing. */
function vibesGuid($namespace, $name) { return strtolower($namespace . '.' . $name); }

/**
 * A path INSIDE a project, or null. The agent's tools and the API's file endpoints both go
 * through this: no '..', no absolute paths, no symlink escaping the project, no .git internals.
 */
function vibesSafeProjectPath($slug, $rel) {
	if (!vibesValidSlug($slug)) return null;
	$rel = str_replace('\\', '/', (string)$rel);
	if ($rel === '' || $rel[0] === '/' || preg_match('~(^|/)\.\.(/|$)~', $rel) || preg_match('~(^|/)\.git(/|$)~', $rel)) return null;
	$root = realpath(vibesProjectDir($slug));
	if ($root === false) return null;
	$full = $root . '/' . $rel;
	// Resolve the deepest EXISTING ancestor so a symlink anywhere in the path cannot escape.
	$probe = $full;
	while (!file_exists($probe) && $probe !== $root) $probe = dirname($probe);
	$real = realpath($probe);
	if ($real === false || strncmp($real . '/', $root . '/', strlen($root) + 1) !== 0) return null;
	return $full;
}

/* ------------------------------------------------------------------------------------------
 * Jobs (section 6): a row first, a detached process second
 * ---------------------------------------------------------------------------------------- */

/** The job currently running (or queued) for a project, or server-wide when $projectId is null. */
function vibesJobOpen($pdo, $projectId = null) {
	if ($projectId === null) {
		$s = $pdo->query("SELECT * FROM hv_jobs WHERE status IN ('queued','running') ORDER BY id DESC LIMIT 1");
	} else {
		$s = $pdo->prepare("SELECT * FROM hv_jobs WHERE status IN ('queued','running') AND project_id = ? ORDER BY id DESC LIMIT 1");
		$s->execute([(int)$projectId]);
	}
	$r = $s->fetch(PDO::FETCH_ASSOC);
	return $r ? vibesJobRow($r) : null;
}

function vibesJobOpenAll($pdo) {
	$out = [];
	foreach ($pdo->query("SELECT * FROM hv_jobs WHERE status IN ('queued','running') ORDER BY id") as $r) $out[] = vibesJobRow($r);
	return $out;
}

function vibesJob($pdo, $id) {
	$s = $pdo->prepare("SELECT * FROM hv_jobs WHERE id = ?");
	$s->execute([(int)$id]);
	$r = $s->fetch(PDO::FETCH_ASSOC);
	return $r ? vibesJobRow($r) : null;
}

function vibesJobRow($r) {
	$r['id']         = (int)$r['id'];
	$r['project_id'] = $r['project_id'] === null ? null : (int)$r['project_id'];
	$r['session_id'] = $r['session_id'] === null ? null : (int)$r['session_id'];
	$r['progress']   = (int)$r['progress'];
	$r['pid']        = $r['pid'] === null ? null : (int)$r['pid'];
	$r['args']       = $r['args']   === null ? null : json_decode($r['args'], true);
	$r['result']     = $r['result'] === null ? null : json_decode($r['result'], true);
	return $r;
}

/**
 * Serialisation rule: one job per project at a time, and install/uninstall/harvest/pak-update
 * are server-wide and exclusive with everything. Returns a human sentence or null when clear.
 */
function vibesJobConflict($pdo, $kind, $projectId) {
	$global = in_array($kind, ['install', 'uninstall', 'harvest', 'pak-update', 'gc'], true);
	foreach (vibesJobOpenAll($pdo) as $j) {
		$jGlobal = in_array($j['kind'], ['install', 'uninstall', 'harvest', 'pak-update', 'gc'], true);
		if ($global || $jGlobal) return "Hugin is busy with '{$j['kind']}' (job {$j['id']}); wait for it to finish.";
		if ($projectId !== null && $j['project_id'] === (int)$projectId) return "This Vibe already has a '{$j['kind']}' running (job {$j['id']}).";
	}
	return null;
}

/**
 * Insert the row and launch the runner detached. Returns ['success'=>true,'jobId'=>n] or
 * ['success'=>false,'error'=>...]. The launch is the same setsid/nohup shape as updateChecker.py
 * (adminAPI.php), appending to the runner's own log; the job's EVENTS go to logs/jobs/<id>.jsonl.
 */
function vibesJobEnqueue($pdo, $kind, $projectId = null, $sessionId = null, $args = null) {
	if (!in_array($kind, VIBES_JOB_KINDS, true)) return ['success' => false, 'error' => "Unknown job kind '$kind'."];
	if ($c = vibesJobConflict($pdo, $kind, $projectId)) return ['success' => false, 'error' => $c];
	$s = $pdo->prepare("INSERT INTO hv_jobs (kind, project_id, session_id, status, args) VALUES (?, ?, ?, 'queued', ?)");
	$s->execute([$kind, $projectId === null ? null : (int)$projectId, $sessionId === null ? null : (int)$sessionId,
	             $args === null ? null : json_encode($args, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)]);
	$id = (int)$pdo->lastInsertId();
	@mkdir(vibesLogsDir() . '/jobs', 0775, true);
	$log = escapeshellarg(vibesLogsDir() . '/vibes.log');
	$cmd = 'setsid nohup /usr/bin/php ' . escapeshellarg(VIBES_TOOLS . '/vibes.php') . ' job ' . $id . " >> $log 2>&1 < /dev/null &";
	exec($cmd);
	return ['success' => true, 'jobId' => $id];
}

/** Ask a job to stop. The runner checks between rounds/tools; the kill is a courtesy nudge. */
function vibesJobCancel($pdo, $id) {
	$j = vibesJob($pdo, $id);
	if (!$j) return ['success' => false, 'error' => 'No such job.'];
	if (!in_array($j['status'], ['queued', 'running'], true)) return ['success' => false, 'error' => "Job {$id} is already {$j['status']}."];
	$pdo->prepare("UPDATE hv_jobs SET status='cancelled', finished=NOW(), error='Cancelled by the operator' WHERE id = ? AND status IN ('queued','running')")->execute([(int)$id]);
	if ($j['pid']) { @posix_kill((int)$j['pid'], 15); }
	return ['success' => true];
}

/* ------------------------------------------------------------------------------------------
 * State (section 10.6 `state`): what the page needs to pick a state of section 10.3
 * ---------------------------------------------------------------------------------------- */

/** Is Vibes usable right now? Requires an enabled default provider with a model. */
function vibesAiStatus($pdo) {
	if (!function_exists('aiDefaultProvider')) require_once __DIR__ . '/aiproviders.php';
	$p = null;
	try { $p = aiDefaultProvider($pdo); } catch (Throwable $e) { $p = null; }
	if (!$p)                       return ['ok' => false, 'reason' => 'No AI provider is configured.', 'provider' => '', 'model' => ''];
	if (empty($p['enabled']))      return ['ok' => false, 'reason' => "The default AI provider '{$p['label']}' is disabled.", 'provider' => $p['label'], 'model' => ''];
	if (trim((string)($p['model'] ?? '')) === '') return ['ok' => false, 'reason' => "No model is selected for '{$p['label']}'.", 'provider' => $p['label'], 'model' => ''];
	return ['ok' => true, 'reason' => '', 'provider' => $p['label'], 'model' => $p['model'], 'kind' => $p['kind'], 'id' => (int)$p['id']];
}

function vibesDiskStatus($pakSizeBytes = null) {
	$base = is_dir(VIBES_ROOT) ? VIBES_ROOT : '/opt/stateful';
	$free = @disk_free_space($base);
	$free = $free === false ? null : (int)$free;
	// Section 3: free >= 2 x pak + 1 GiB. Without a known pak size, require 2 GiB.
	$required = $pakSizeBytes ? (2 * (int)$pakSizeBytes + 1073741824) : 2147483648;
	return ['free' => $free, 'required' => $required, 'ok' => $free !== null && $free >= $required];
}

function vibesSettingsRow($pdo) {
	try {
		$r = $pdo->query("SELECT vibesInstalled, vibesBuildpakVersion, vibesNoticeShown FROM settings LIMIT 1")->fetch(PDO::FETCH_ASSOC);
	} catch (Throwable $e) { $r = null; }
	return [
		'installed'   => (int)($r['vibesInstalled'] ?? 0) === 1,
		'pakVersion'  => $r['vibesBuildpakVersion'] ?? null,
		'noticeShown' => (int)($r['vibesNoticeShown'] ?? 0) === 1,
	];
}

function vibesState($pdo) {
	$row  = vibesSettingsRow($pdo);
	$doc  = vibesSettingsGet($pdo);
	$pak  = vibesPakManifest();
	$libs = vibesLibsManifest();
	$pakOk = $pak !== null
	      && in_array((int)($pak['pakFormat'] ?? 0), VIBES_PAK_FORMATS, true)
	      && (string)($pak['base'] ?? '') === VIBES_PAK_BASE;
	$pakProblem = '';
	if ($row['installed'] && $pak === null)                      $pakProblem = 'The Build Pak is missing from the data volume (restored from an older backup?). Re-download it.';
	elseif ($pak !== null && !in_array((int)($pak['pakFormat'] ?? 0), VIBES_PAK_FORMATS, true)) $pakProblem = 'This Build Pak is a format this server does not understand. Update it.';
	elseif ($pak !== null && (string)($pak['base'] ?? '') !== VIBES_PAK_BASE) $pakProblem = "This Build Pak was built for '{$pak['base']}' and the server image is '" . VIBES_PAK_BASE . "'. Update it.";
	return [
		'installed'   => $row['installed'],
		'noticeShown' => $row['noticeShown'],
		'pak'         => ['version' => $pak['version'] ?? $row['pakVersion'], 'present' => $pak !== null, 'compatible' => $pakOk,
		                  'problem' => $pakProblem, 'components' => $pak['components'] ?? null,
		                  'available' => vibesSetting($doc, 'buildpak.available'), 'lastCheck' => vibesSetting($doc, 'buildpak.last_check')],
		'libs'        => ['present' => $libs !== null, 'gameVersion' => $libs['gameVersion'] ?? null, 'gameBuild' => $libs['gameBuild'] ?? null, 'sourceWorld' => $libs['sourceWorld'] ?? null,
		                  'harvestedAt' => $libs['harvestedAt'] ?? null, 'decompiled' => is_dir(vibesLibsDir() . '/decompiled')],
		'ai'          => vibesAiStatus($pdo),
		'disk'        => vibesDiskStatus($pak['sizeBytes'] ?? null),
		'jobs'        => vibesJobOpenAll($pdo),
		'author'      => vibesSetting($doc, 'author'),
		'scm'         => ['github' => (bool)vibesSetting($doc, 'github.enabled'), 'gitlab' => (bool)vibesSetting($doc, 'gitlab.enabled')],
		'root'        => VIBES_ROOT,
	];
}

/* ------------------------------------------------------------------------------------------
 * UTF-8 string helpers. The image has NO mbstring (php8.1-mbstring is not installed and the
 * 2.58 CVE diet is not adding packages for prose limits), so mb_* is a fatal "undefined
 * function" at runtime -- the first createProject on the dev box died on mb_strlen(). iconv is
 * in core. The runner's lib/env.php defines the same two names; the guards let either load first.
 * ---------------------------------------------------------------------------------------- */
if (!function_exists('vibesStrlen')) {
	function vibesStrlen($s) { $n = @iconv_strlen((string)$s, 'UTF-8'); return $n === false ? strlen((string)$s) : $n; }
}
if (!function_exists('vibesSubstr')) {
	function vibesSubstr($s, $start, $len = null) {
		$s = (string)$s;
		$r = $len === null ? @iconv_substr($s, $start, PHP_INT_MAX, 'UTF-8') : @iconv_substr($s, $start, $len, 'UTF-8');
		return $r === false ? ($len === null ? substr($s, $start) : substr($s, $start, $len)) : $r;
	}
}
if (!function_exists('vibesUtf8Ok')) {
	function vibesUtf8Ok($s) { return preg_match('//u', (string)$s) === 1; }
}

/* ------------------------------------------------------------------------------------------
 * Small shared utilities
 * ---------------------------------------------------------------------------------------- */

function vibesReadJson($file, $default = null) {
	if (!is_file($file)) return $default;
	$v = json_decode((string)@file_get_contents($file), true);
	return $v === null ? $default : $v;
}

/** Write-then-rename so a reader never sees a half-written document. */
function vibesWriteJson($file, $data) {
	@mkdir(dirname($file), 0775, true);
	$tmp = $file . '.tmp.' . getmypid();
	if (@file_put_contents($tmp, json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . "\n") === false) return false;
	return @rename($tmp, $file);
}

/** Bytes to "212 MB" / "1.4 GB" for UI copy. */
function vibesHumanBytes($b) {
	$b = (float)$b;
	foreach (['B', 'KB', 'MB', 'GB', 'TB'] as $u) {
		if ($b < 1024 || $u === 'TB') return ($u === 'B' ? (int)$b : ($b >= 100 ? round($b) : round($b, 1))) . ' ' . $u;
		$b /= 1024;
	}
	return '';
}

/** du -s of a directory, bytes; 0 when absent. Cheap enough for a project (tens of MB). */
function vibesDirSize($dir) {
	if (!is_dir($dir)) return 0;
	$out = @shell_exec('du -sb ' . escapeshellarg($dir) . ' 2>/dev/null');
	return (int)trim((string)$out);
}

/** The worlds that have a vibes mod in their list: [name => [mod full_name, ...]]. */
function vibesWorldsUsing($pdo, $modId = null) {
	$sql = "SELECT w.name AS world, m.full_name
	          FROM world_mods wm
	          JOIN mods m   ON m.id = wm.mod_id
	          JOIN worlds w ON w.id = wm.world_id
	         WHERE m.source = 'vibes'" . ($modId !== null ? " AND m.id = " . (int)$modId : "") . "
	         ORDER BY w.name, m.full_name";
	$out = [];
	try {
		foreach ($pdo->query($sql) as $r) $out[$r['world']][] = $r['full_name'];
	} catch (Throwable $e) { /* mid-migration: no rows is the honest answer */ }
	return $out;
}
