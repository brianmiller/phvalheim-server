<?php
/**
 * Hugin's Vibes -- the JSON API (2.59). docs/HUGINS-VIBES-DESIGN.md section 10.6.
 *
 * Two hats, one file:
 *   - Endpoint: vibesAPI.php?action=<name>. Every answer is JSON. A failure is
 *     {success:false, error:"<a sentence>"} with a 4xx (5xx when it is our fault), never a
 *     PHP warning in the body -- the handlers at the top turn warnings into exceptions and
 *     exceptions into JSON, and anything an included library echoes is thrown away and logged.
 *   - Library: admin/hugins_vibes.php defines VIBES_API_LIBRARY and require_once's this file to
 *     call vibesApiState() for the state it server-renders. One definition of "state", two
 *     readers, so the page and the API cannot disagree about which of the eight states we are in.
 *
 * Trust boundary: port 8081 is the admin interface; like adminAPI.php there is no per-request
 * session check here. Everything else that matters is checked: identities through the
 * validators in includes/vibes.php, paths through vibesSafeProjectPath(), methods per action.
 *
 * The heavy lifting (git, dotnet, the pak, the catalogue) lives in the runner's library under
 * /opt/stateless/engine/tools/vibes/lib/. We load whatever is there and guard every call with
 * function_exists(): a missing library is a 503 with a sentence, and `state` reports it so the
 * page can show a banner instead of a blank workspace.
 */

include '/opt/stateless/nginx/www/includes/config_env_puller.php';
include '/opt/stateless/nginx/www/includes/phvalheim-frontend-config.php';
require_once '/opt/stateless/nginx/www/includes/vibes.php';
require_once '/opt/stateless/nginx/www/includes/aiproviders.php';
require_once '/opt/stateless/nginx/www/includes/events.php';

/* ------------------------------------------------------------------------------------------
 * Failure plumbing: a request fails as JSON or not at all
 * ---------------------------------------------------------------------------------------- */

/** An error the operator caused or can act on: carries the HTTP status it deserves. */
class VibesHttpError extends Exception {
	public $status;
	public $extra;
	public function __construct($message, $status = 400, $extra = []) {
		parent::__construct($message);
		$this->status = (int)$status;
		$this->extra  = $extra;
	}
}

/** Emit JSON and stop. Anything buffered before us (a library's stray echo) is logged, not sent. */
function vibesOut($data, $status = 200) {
	while (ob_get_level() > 0) {
		$junk = ob_get_clean();
		if (trim((string)$junk) !== '') error_log('vibesAPI: discarded stray output: ' . substr($junk, 0, 300));
	}
	if (!headers_sent()) {
		http_response_code($status);
		header('Content-Type: application/json');
		header('Cache-Control: no-store');
	}
	if (!array_key_exists('success', $data)) $data['success'] = $status < 400;
	$json = json_encode($data, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
	if ($json === false) $json = json_encode($data, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE | JSON_PARTIAL_OUTPUT_ON_ERROR);
	echo $json;
	exit;
}

function vibesFail($message, $status = 400, $extra = []) {
	vibesOut(array_merge($extra, ['success' => false, 'error' => (string)$message]), $status);
}

/** Install the handlers only when we are the endpoint; the page has its own idea of errors. */
function vibesApiArmHandlers() {
	ob_start();
	set_error_handler(function ($no, $str, $file, $line) {
		// Respect @ and error_reporting(): a suppressed notice must stay suppressed, or every
		// @file_get_contents() in the shared core becomes a 500.
		if (!(error_reporting() & $no)) return false;
		throw new ErrorException($str, 0, $no, $file, $line);
	});
	set_exception_handler(function ($e) {
		if ($e instanceof VibesHttpError) vibesFail($e->getMessage(), $e->status, $e->extra);
		error_log('vibesAPI: ' . get_class($e) . ': ' . $e->getMessage() . ' @ ' . $e->getFile() . ':' . $e->getLine());
		vibesFail('Server error: ' . $e->getMessage(), 500);
	});
	register_shutdown_function(function () {
		$e = error_get_last();
		if ($e && in_array($e['type'], [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR], true)) {
			error_log('vibesAPI fatal: ' . $e['message'] . ' @ ' . $e['file'] . ':' . $e['line']);
			vibesFail('Server error: ' . $e['message'], 500);
		}
	});
}

/* ------------------------------------------------------------------------------------------
 * The runner's library: loaded if present, every call guarded
 * ---------------------------------------------------------------------------------------- */

/** Every runner function this API may call, so `state` can list exactly what is missing. */
function vibesRunnerFunctions() {
	return ['vibesPakReleaseInfo', 'vibesTemplates', 'vibesProjectCreate', 'vibesProjectRow', 'vibesProjectTree',
	        'vibesProjectManifestRead', 'vibesProjectManifestWrite', 'vibesProjectDelete', 'vibesProjectExportTar',
	        'vibesGitLog', 'vibesGitDiff', 'vibesGitStatus', 'vibesGitCommit', 'vibesBuildLast', 'vibesCatalogVersions',
	        'vibesScmTest', 'vibesPakReady'];
}

/** require_once every lib/*.php once. A broken library file is logged and treated as absent. */
function vibesRunnerLoad() {
	static $done = false;
	if ($done) return;
	$done = true;
	foreach (glob(VIBES_TOOLS . '/lib/*.php') ?: [] as $f) {
		try { require_once $f; }
		catch (Throwable $e) { error_log('vibesAPI: runner library ' . basename($f) . ' failed to load: ' . $e->getMessage()); }
	}
}

function vibesRunnerStatus() {
	vibesRunnerLoad();
	$missing = array_values(array_filter(vibesRunnerFunctions(), fn($fn) => !function_exists($fn)));
	return ['present' => is_dir(VIBES_TOOLS . '/lib'), 'runner' => is_file(VIBES_TOOLS . '/vibes.php'),
	        'missing' => $missing, 'ok' => $missing === [] && is_file(VIBES_TOOLS . '/vibes.php')];
}

/** Call a runner function or fail with a 503 that names it. */
function vibesRunner($fn, ...$args) {
	vibesRunnerLoad();
	if (!function_exists($fn)) throw new VibesHttpError("The runner library is missing $fn() under " . VIBES_TOOLS . "/lib. This server image is incomplete; rebuild or update it.", 503, ['runnerMissing' => true]);
	return $fn(...$args);
}

/* ------------------------------------------------------------------------------------------
 * Input helpers
 * ---------------------------------------------------------------------------------------- */

/** GET + POST form fields + a JSON body, merged; the body wins. */
function vibesInput() {
	$in = $_GET;
	foreach ($_POST as $k => $v) $in[$k] = $v;
	$raw = file_get_contents('php://input');
	if ($raw !== '' && $raw !== false && stripos($_SERVER['CONTENT_TYPE'] ?? '', 'application/json') !== false) {
		$body = json_decode($raw, true);
		if (!is_array($body)) throw new VibesHttpError('The request body is not valid JSON.', 400);
		foreach ($body as $k => $v) $in[$k] = $v;
	}
	return $in;
}

function vibesNeed($in, $key, $what = null) {
	if (!isset($in[$key]) || (is_string($in[$key]) && trim($in[$key]) === '')) throw new VibesHttpError(($what ?: "'$key'") . ' is required.', 400);
	return $in[$key];
}

function vibesNeedInt($in, $key, $what = null) {
	$v = vibesNeed($in, $key, $what);
	if (!is_numeric($v) || (int)$v <= 0) throw new VibesHttpError(($what ?: "'$key'") . ' must be a positive integer.', 400);
	return (int)$v;
}

function vibesBool($v) {
	if (is_bool($v)) return $v;
	return in_array(strtolower((string)$v), ['1', 'true', 'yes', 'on'], true);
}

/** "8M" / "2G" from php.ini to bytes. */
function vibesIniBytes($v) {
	$v = trim((string)$v);
	if ($v === '' || $v === '-1' || $v === '0') return PHP_INT_MAX;
	$n = (float)$v;
	switch (strtoupper(substr($v, -1))) {
		case 'G': $n *= 1024;
		case 'M': $n *= 1024;
		case 'K': $n *= 1024;
	}
	return (int)$n;
}

/* ------------------------------------------------------------------------------------------
 * Projects & sessions: the rows this API owns outright
 * ---------------------------------------------------------------------------------------- */

function vibesProjectRaw($pdo, $id) {
	$s = $pdo->prepare("SELECT * FROM hv_projects WHERE id = ?");
	$s->execute([(int)$id]);
	$r = $s->fetch(PDO::FETCH_ASSOC);
	if (!$r) throw new VibesHttpError("No Vibe with id $id.", 404);
	$r['id'] = (int)$r['id'];
	$r['provider_id'] = $r['provider_id'] === null ? null : (int)$r['provider_id'];
	$r['last_build_ok'] = $r['last_build_ok'] === null ? null : (int)$r['last_build_ok'];
	return $r;
}

function vibesSessionRaw($pdo, $id) {
	$s = $pdo->prepare("SELECT s.*, p.slug FROM hv_sessions s JOIN hv_projects p ON p.id = s.project_id WHERE s.id = ?");
	$s->execute([(int)$id]);
	$r = $s->fetch(PDO::FETCH_ASSOC);
	if (!$r) throw new VibesHttpError("No session with id $id.", 404);
	foreach (['id', 'project_id', 'turns', 'tokens_in', 'tokens_out'] as $k) $r[$k] = (int)$r[$k];
	return $r;
}

function vibesSessionsFor($pdo, $projectId) {
	$s = $pdo->prepare("SELECT id, project_id, title, status, turns, tokens_in, tokens_out, started, updated, summary IS NOT NULL AS compacted FROM hv_sessions WHERE project_id = ? ORDER BY updated DESC, id DESC");
	$s->execute([(int)$projectId]);
	$out = [];
	foreach ($s as $r) {
		foreach (['id', 'project_id', 'turns', 'tokens_in', 'tokens_out', 'compacted'] as $k) $r[$k] = (int)$r[$k];
		$out[] = $r;
	}
	return $out;
}

/** Worlds that list THIS project's published package, from the generic (source='vibes') map. */
function vibesProjectWorlds($pdo, $row, $using = null) {
	$using = $using ?? vibesWorldsUsing($pdo);
	$full = $row['namespace'] . '-' . $row['name'];
	$out = [];
	foreach ($using as $world => $mods) if (in_array($full, $mods, true)) $out[] = $world;
	return $out;
}

/** Events with seq > $after from a JSONL file; a half-written last line is skipped, not fatal. */
function vibesReadEvents($file, $after = 0, $max = 5000) {
	$out = [];
	if (!is_file($file)) return $out;
	$fh = @fopen($file, 'r');
	if (!$fh) return $out;
	while (($line = fgets($fh)) !== false) {
		$ev = json_decode($line, true);
		if (!is_array($ev) || !isset($ev['seq'])) continue;
		if ((int)$ev['seq'] <= $after) continue;
		$out[] = $ev;
		if (count($out) >= $max) break;
	}
	fclose($fh);
	return $out;
}

/** Worlds that have the dedicated server files on disk -- what a harvest can read from. */
function vibesWorldsWithServerFiles() {
	$out = [];
	foreach (glob('/opt/stateful/games/valheim/worlds/*/game/valheim_server_Data/Managed/assembly_valheim.dll') ?: [] as $f) {
		$out[] = basename(dirname(dirname(dirname(dirname($f)))));
	}
	sort($out);
	return $out;
}

/** The effective upload cap: the operator's setting, bounded by what PHP will accept. */
function vibesUploadMaxBytes($doc) {
	$mb = (int)vibesSetting($doc, 'assets.max_upload_mb', 64);
	return (int)min($mb * 1048576, vibesIniBytes(ini_get('upload_max_filesize')), vibesIniBytes(ini_get('post_max_size')));
}

/** Enqueue or fail: a conflict sentence from the core is a 409, anything else a 500. */
function vibesEnqueueOrFail($pdo, $kind, $projectId = null, $sessionId = null, $args = null) {
	$r = vibesJobEnqueue($pdo, $kind, $projectId, $sessionId, $args);
	if (!$r['success']) {
		$busy = stripos($r['error'], 'busy') !== false || stripos($r['error'], 'already') !== false;
		throw new VibesHttpError($r['error'], $busy ? 409 : 500);
	}
	return ['jobId' => $r['jobId'], 'job' => vibesJob($pdo, $r['jobId'])];
}

function vibesRequireInstalled($pdo) {
	if (!vibesSettingsRow($pdo)['installed']) throw new VibesHttpError("Hugin's Vibes is not installed yet. Install it first.", 409);
}

/* ------------------------------------------------------------------------------------------
 * `state` -- shared with the page
 * ---------------------------------------------------------------------------------------- */

function vibesApiState($pdo) {
	global $phvalheimVersion;
	$s = vibesState($pdo);
	$doc = vibesSettingsGet($pdo);
	$s['runner'] = vibesRunnerStatus();
	$s['worldsWithServerFiles'] = vibesWorldsWithServerFiles();
	try { $s['projectCount'] = (int)$pdo->query("SELECT COUNT(*) FROM hv_projects")->fetchColumn(); }
	catch (Throwable $e) { $s['projectCount'] = 0; }
	$s['pakReady'] = function_exists('vibesPakReady') ? (bool)vibesPakReady() : (bool)$s['pak']['compatible'];
	$s['limits'] = ['uploadMaxBytes' => vibesUploadMaxBytes($doc)];
	$s['decompileDefault'] = (bool)vibesSetting($doc, 'libs.decompile', true);
	$s['serverVersion'] = (string)($phvalheimVersion ?: '');
	$s['pakRepo'] = (string)vibesSetting($doc, 'buildpak.repo', '');
	// The newest install/uninstall job of the last day, OPEN OR NOT. A job that fails faster than
	// the page's state refresh (the pre-release gate failed in 5 ms) left no trace on screen: the
	// router saw no open job and showed the intro again. The intro now reports this one.
	try {
		$st = $pdo->query("SELECT * FROM hv_jobs WHERE kind IN ('install','uninstall') AND created > NOW() - INTERVAL 1 DAY ORDER BY id DESC LIMIT 1");
		$row = $st->fetch(PDO::FETCH_ASSOC);
		$s['lastJob'] = $row ? vibesJobRow($row) : null;
	} catch (Throwable $e) { $s['lastJob'] = null; }
	return $s;
}

/* ------------------------------------------------------------------------------------------
 * Actions. Each takes ($pdo, $in) and returns the array to emit; throw VibesHttpError to fail.
 * ---------------------------------------------------------------------------------------- */

function vibesAct_state($pdo, $in) { return vibesApiState($pdo); }

function vibesAct_pakInfo($pdo, $in) {
	$doc = vibesSettingsGet($pdo);
	$r = vibesRunner('vibesPakReleaseInfo', $doc, vibesBool($in['force'] ?? false));
	// 200, not 502: "no release" or "GitHub unreachable" is an answer about the world, not a
	// gateway failure, and a 502 in nginx's log sent the first tester hunting for a dead upstream.
	if (empty($r['success'])) return ['success' => false, 'error' => $r['error'] ?? 'The Build Pak release could not be reached.', 'reachable' => false, 'httpStatus' => 200];
	$r['reachable'] = true;
	$r['sizeHuman'] = isset($r['sizeBytes']) ? vibesHumanBytes($r['sizeBytes']) : null;
	$r['disk'] = vibesDiskStatus($r['sizeBytes'] ?? null);
	return $r;
}

function vibesAct_install($pdo, $in) {
	$ai = vibesAiStatus($pdo);
	if (!$ai['ok']) throw new VibesHttpError("Hugin's Vibes needs an AI provider: " . $ai['reason'] . ' Add one in Server Settings > AI Helper.', 412);
	$size = isset($in['pakSizeBytes']) && is_numeric($in['pakSizeBytes']) ? (int)$in['pakSizeBytes'] : null;
	$disk = vibesDiskStatus($size);
	if (!$disk['ok']) throw new VibesHttpError('Not enough free space on the data volume: ' . vibesHumanBytes($disk['free'] ?? 0) . ' free, ' . vibesHumanBytes($disk['required']) . ' required.', 412);
	$r = vibesEnqueueOrFail($pdo, 'install', null, null, ['decompile' => vibesBool($in['decompile'] ?? true)]);
	// The notice is what the modal IS; once they press Install they have read it.
	$pdo->exec("UPDATE settings SET vibesNoticeShown = 1");
	return $r;
}

function vibesAct_uninstall($pdo, $in) {
	if (($in['confirm'] ?? '') !== 'DELETE') throw new VibesHttpError("Type DELETE to confirm removing Hugin's Vibes.", 400);
	$args = ['keep_projects' => vibesBool($in['keep_projects'] ?? false), 'keep_catalog' => vibesBool($in['keep_catalog'] ?? false),
	         'keep_assets' => vibesBool($in['keep_assets'] ?? false), 'wipe_settings' => vibesBool($in['wipe_settings'] ?? false)];
	return vibesEnqueueOrFail($pdo, 'uninstall', null, null, $args);
}

function vibesAct_harvest($pdo, $in) {
	vibesRequireInstalled($pdo);
	$args = [];
	if (!empty($in['world'])) {
		if (!in_array($in['world'], vibesWorldsWithServerFiles(), true)) throw new VibesHttpError("World '{$in['world']}' has no dedicated server files to harvest from.", 400);
		$args['world'] = $in['world'];
	}
	return vibesEnqueueOrFail($pdo, 'harvest', null, null, $args);
}

function vibesAct_pakUpdate($pdo, $in) { vibesRequireInstalled($pdo); return vibesEnqueueOrFail($pdo, 'pak-update', null, null, []); }
function vibesAct_gc($pdo, $in)        { vibesRequireInstalled($pdo); return vibesEnqueueOrFail($pdo, 'gc', null, null, []); }

function vibesAct_projects($pdo, $in) {
	$using = vibesWorldsUsing($pdo);
	$open  = [];
	foreach (vibesJobOpenAll($pdo) as $j) if ($j['project_id'] !== null) $open[$j['project_id']] = $j;
	$last = [];
	try {
		foreach ($pdo->query("SELECT s.* FROM hv_sessions s JOIN (SELECT project_id, MAX(id) AS id FROM hv_sessions GROUP BY project_id) m ON m.id = s.id") as $r) $last[(int)$r['project_id']] = ['id' => (int)$r['id'], 'title' => $r['title'], 'status' => $r['status'], 'updated' => $r['updated']];
	} catch (Throwable $e) { /* no sessions yet */ }
	$out = [];
	foreach ($pdo->query("SELECT * FROM hv_projects ORDER BY updated DESC, id DESC") as $r) {
		$r['id'] = (int)$r['id'];
		$r['provider_id'] = $r['provider_id'] === null ? null : (int)$r['provider_id'];
		$r['last_build_ok'] = $r['last_build_ok'] === null ? null : (int)$r['last_build_ok'];
		$r['size'] = vibesDirSize(vibesProjectDir($r['slug']));
		$r['worlds'] = vibesProjectWorlds($pdo, $r, $using);
		$r['lastSession'] = $last[$r['id']] ?? null;
		$r['openJob'] = $open[$r['id']] ?? null;
		$out[] = $r;
	}
	return ['projects' => $out];
}

function vibesAct_templates($pdo, $in) { return ['templates' => vibesRunner('vibesTemplates')]; }

function vibesAct_createProject($pdo, $in) {
	vibesRequireInstalled($pdo);
	$doc  = vibesSettingsGet($pdo);
	$name = trim((string)vibesNeed($in, 'name', 'A mod name'));
	if (!vibesValidName($name)) throw new VibesHttpError('The name must start with a letter and contain only letters, digits and underscores (PascalCase, no spaces), 2 to 96 characters.', 400);
	$ns = trim((string)($in['namespace'] ?? '')) ?: (string)vibesSetting($doc, 'author.namespace', '');
	if ($ns === '') throw new VibesHttpError('Choose an author namespace (it becomes the catalogue owner). Set a default under Settings > General.', 400);
	if (!vibesValidNamespace($ns)) throw new VibesHttpError('The namespace may contain letters, digits and underscores only.', 400);
	$desc = trim((string)($in['description'] ?? ''));
	if (vibesStrlen($desc) > 250) throw new VibesHttpError('The description must be 250 characters or fewer (Thunderstore\'s limit).', 400);
	$tpl = trim((string)($in['template'] ?? '')) ?: (string)vibesSetting($doc, 'defaults.template', 'basic-plugin');
	if (!preg_match('/^[a-z0-9-]{1,32}$/', $tpl)) throw new VibesHttpError('Unknown template.', 400);
	$r = vibesRunner('vibesProjectCreate', $pdo, ['name' => $name, 'namespace' => $ns, 'description' => $desc, 'template' => $tpl]);
	if (empty($r['success'])) throw new VibesHttpError($r['error'] ?? 'The project could not be created.', 400);
	phvEvent($pdo, 'notice', "Vibe '$ns-$name' created from template '$tpl'");
	return ['project' => $r['project']];
}

function vibesAct_project($pdo, $in) {
	$id  = vibesNeedInt($in, 'id', 'A Vibe id');
	$raw = vibesProjectRaw($pdo, $id);
	vibesRunnerLoad();
	$row = function_exists('vibesProjectRow') ? vibesProjectRow($pdo, $id) : $raw;
	if (!$row) throw new VibesHttpError("No Vibe with id $id.", 404);
	$row['worlds']    = vibesProjectWorlds($pdo, $raw);
	$row['sessions']  = vibesSessionsFor($pdo, $id);
	$row['openJob']   = vibesJobOpen($pdo, $id);
	$row['tree']      = function_exists('vibesProjectTree')     ? vibesProjectTree($raw['slug'])        : [];
	$row['gitLog']    = function_exists('vibesGitLog')          ? vibesGitLog($raw['slug'], 30)         : [];
	$row['lastBuild'] = function_exists('vibesBuildLast')       ? vibesBuildLast($raw['slug'])          : null;
	$row['catalog']   = function_exists('vibesCatalogVersions') ? vibesCatalogVersions($pdo, $id)       : [];
	$row['size']      = $row['size'] ?? vibesDirSize(vibesProjectDir($raw['slug']));
	$row['guid']      = $row['guid'] ?? vibesGuid($raw['namespace'], $raw['name']);
	$row['runnerMissing'] = vibesRunnerStatus()['missing'];
	return ['project' => $row];
}

function vibesAct_updateProject($pdo, $in) {
	$id  = vibesNeedInt($in, 'id', 'A Vibe id');
	$row = vibesProjectRaw($pdo, $id);
	$sets = []; $vals = [];
	if (array_key_exists('description', $in)) {
		$d = trim((string)$in['description']);
		if (vibesStrlen($d) > 250) throw new VibesHttpError('The description must be 250 characters or fewer.', 400);
		$sets[] = 'description = ?'; $vals[] = $d;
	}
	if (array_key_exists('version', $in)) {
		if (!vibesValidVersion($in['version'])) throw new VibesHttpError('The version must look like 1.2.3.', 400);
		$sets[] = 'version = ?'; $vals[] = $in['version'];
	}
	if (array_key_exists('provider_id', $in)) {
		$p = $in['provider_id'] === null || $in['provider_id'] === '' ? null : (int)$in['provider_id'];
		if ($p !== null && !aiProvider($pdo, $p)) throw new VibesHttpError("No AI provider with id $p.", 400);
		$sets[] = 'provider_id = ?'; $vals[] = $p;
	}
	// The model is stored verbatim and never validated (section 4): the operator may type one
	// the provider's list does not know yet.
	if (array_key_exists('model', $in)) { $sets[] = 'model = ?'; $vals[] = vibesSubstr(trim((string)$in['model']), 0, 190); }
	foreach (['github_remote', 'gitlab_remote'] as $k) {
		if (!array_key_exists($k, $in)) continue;
		$v = trim((string)$in[$k]);
		if ($v !== '' && !preg_match('~^[A-Za-z0-9_.-]+(/[A-Za-z0-9_.-]+)+$~', $v)) throw new VibesHttpError("$k must look like owner/repo.", 400);
		$sets[] = "$k = ?"; $vals[] = $v;
	}
	if (!$sets) throw new VibesHttpError('Nothing to update.', 400);
	$vals[] = $id;
	$pdo->prepare("UPDATE hv_projects SET " . implode(', ', $sets) . " WHERE id = ?")->execute($vals);
	// The manifest mirrors the row for the fields Thunderstore reads; keep them in step when we can.
	if (function_exists('vibesProjectManifestRead') && function_exists('vibesProjectManifestWrite') && (array_key_exists('description', $in) || array_key_exists('version', $in))) {
		try {
			$m = vibesProjectManifestRead($row['slug']);
			if (array_key_exists('description', $in)) $m['description'] = trim((string)$in['description']);
			if (array_key_exists('version', $in))     $m['version_number'] = $in['version'];
			vibesProjectManifestWrite($row['slug'], $m);
		} catch (Throwable $e) { error_log('vibesAPI: manifest sync failed: ' . $e->getMessage()); }
	}
	return ['project' => vibesProjectRaw($pdo, $id)];
}

function vibesAct_deleteProject($pdo, $in) {
	$id  = vibesNeedInt($in, 'id', 'A Vibe id');
	$row = vibesProjectRaw($pdo, $id);
	if (($in['confirm'] ?? '') !== $row['name']) throw new VibesHttpError("Type the Vibe's name ({$row['name']}) to confirm.", 400);
	if ($j = vibesJobOpen($pdo, $id)) throw new VibesHttpError("A '{$j['kind']}' job is running on this Vibe; stop it or wait before deleting.", 409);
	$r = vibesRunner('vibesProjectDelete', $pdo, $id);
	if (empty($r['success']) && isset($r['success'])) throw new VibesHttpError($r['error'] ?? 'The Vibe could not be deleted.', 500);
	phvEvent($pdo, 'warning', "Vibe '{$row['namespace']}-{$row['name']}' deleted");
	return ['deleted' => $id];
}

/** Not JSON: streams a tar.gz. Failures before the first byte are still JSON. */
function vibesAct_exportProject($pdo, $in) {
	$id  = vibesNeedInt($in, 'id', 'A Vibe id');
	$row = vibesProjectRaw($pdo, $id);
	@mkdir(vibesDownloadsDir(), 0775, true);
	$tmp = vibesDownloadsDir() . '/export-' . $row['slug'] . '-' . getmypid() . '.tar.gz';
	if (!vibesRunner('vibesProjectExportTar', $row['slug'], $tmp) || !is_file($tmp)) throw new VibesHttpError('The export could not be written.', 500);
	while (ob_get_level() > 0) ob_end_clean();
	header('Content-Type: application/gzip');
	header('Content-Length: ' . filesize($tmp));
	header('Content-Disposition: attachment; filename="' . $row['namespace'] . '-' . $row['name'] . '-' . date('Ymd-His') . '.tar.gz"');
	readfile($tmp);
	@unlink($tmp);
	exit;
}

function vibesAct_sessions($pdo, $in) {
	$id = vibesNeedInt($in, 'project', 'A Vibe id');
	vibesProjectRaw($pdo, $id);
	return ['sessions' => vibesSessionsFor($pdo, $id)];
}

function vibesAct_newSession($pdo, $in) {
	$id  = vibesNeedInt($in, 'project', 'A Vibe id');
	$row = vibesProjectRaw($pdo, $id);
	$title = vibesSubstr(trim((string)($in['title'] ?? '')), 0, 120);
	$pdo->prepare("INSERT INTO hv_sessions (project_id, title) VALUES (?, ?)")->execute([$id, $title]);
	$sid = (int)$pdo->lastInsertId();
	@mkdir(vibesProjectDir($row['slug']) . '/.vibes/sessions', 0775, true);
	return ['session' => vibesSessionRaw($pdo, $sid)];
}

function vibesAct_renameSession($pdo, $in) {
	$sid   = vibesNeedInt($in, 'session', 'A session id');
	$title = vibesSubstr(trim((string)vibesNeed($in, 'title', 'A title')), 0, 120);
	vibesSessionRaw($pdo, $sid);
	$pdo->prepare("UPDATE hv_sessions SET title = ? WHERE id = ?")->execute([$title, $sid]);
	return ['session' => vibesSessionRaw($pdo, $sid)];
}

function vibesAct_transcript($pdo, $in) {
	$sid   = vibesNeedInt($in, 'session', 'A session id');
	$after = (int)($in['after'] ?? 0);
	$s = vibesSessionRaw($pdo, $sid);
	$events = vibesReadEvents(vibesSessionLog($s['slug'], $sid), $after);
	return ['session' => $s, 'events' => $events, 'job' => vibesJobOpen($pdo, $s['project_id'])];
}

function vibesAct_turn($pdo, $in) {
	vibesRequireInstalled($pdo);
	$sid  = vibesNeedInt($in, 'session', 'A session id');
	$text = trim((string)vibesNeed($in, 'text', 'A message'));
	if (vibesStrlen($text) > 32000) throw new VibesHttpError('Keep a single message under 32,000 characters.', 400);
	$s = vibesSessionRaw($pdo, $sid);
	if ($s['title'] === '') {
		$pdo->prepare("UPDATE hv_sessions SET title = ? WHERE id = ?")->execute([vibesSubstr(preg_replace('/\s+/', ' ', $text), 0, 120), $sid]);
	}
	$r = vibesEnqueueOrFail($pdo, 'turn', $s['project_id'], $sid, ['text' => $text]);
	$r['session'] = vibesSessionRaw($pdo, $sid);
	return $r;
}

function vibesAct_cancel($pdo, $in) {
	$id = vibesNeedInt($in, 'jobId', 'A job id');
	$r  = vibesJobCancel($pdo, $id);
	if (!$r['success']) throw new VibesHttpError($r['error'], stripos($r['error'], 'No such') !== false ? 404 : 409);
	return ['job' => vibesJob($pdo, $id)];
}

function vibesAct_job($pdo, $in) {
	$id = vibesNeedInt($in, 'id', 'A job id');
	$j  = vibesJob($pdo, $id);
	if (!$j) throw new VibesHttpError("No job with id $id.", 404);
	return ['job' => $j];
}

function vibesAct_jobs($pdo, $in) {
	$recent = [];
	foreach ($pdo->query("SELECT * FROM hv_jobs WHERE status NOT IN ('queued','running') ORDER BY id DESC LIMIT 25") as $r) $recent[] = vibesJobRow($r);
	return ['open' => vibesJobOpenAll($pdo), 'recent' => $recent];
}

function vibesAct_jobLog($pdo, $in) {
	$id = vibesNeedInt($in, 'id', 'A job id');
	$j  = vibesJob($pdo, $id);
	if (!$j) throw new VibesHttpError("No job with id $id.", 404);
	return ['job' => $j, 'events' => vibesReadEvents(vibesJobLog($id), (int)($in['after'] ?? 0))];
}

/** Language by extension, for the viewer's highlighter and the label on the tab. */
function vibesLangFor($path) {
	$ext = strtolower(pathinfo($path, PATHINFO_EXTENSION));
	$map = ['cs' => 'csharp', 'json' => 'json', 'csproj' => 'xml', 'props' => 'xml', 'targets' => 'xml', 'xml' => 'xml', 'config' => 'xml',
	        'md' => 'markdown', 'txt' => 'text', 'cfg' => 'ini', 'yml' => 'yaml', 'yaml' => 'yaml', 'sh' => 'shell', 'gitignore' => 'text', 'log' => 'text'];
	if ($ext === '' && basename($path) === '.gitignore') return 'text';
	return $map[$ext] ?? 'text';
}

function vibesAct_file($pdo, $in) {
	$id   = vibesNeedInt($in, 'id', 'A Vibe id');
	$rel  = (string)vibesNeed($in, 'path', 'A file path');
	$row  = vibesProjectRaw($pdo, $id);
	$full = vibesSafeProjectPath($row['slug'], $rel);
	if ($full === null) throw new VibesHttpError('That path is outside the Vibe.', 400);
	if (!is_file($full)) throw new VibesHttpError("No file at $rel.", 404);
	$size = filesize($full);
	$cap  = 512 * 1024;
	$data = (string)file_get_contents($full, false, null, 0, min($size, $cap));
	$binary = strpos(substr($data, 0, 8192), "\0") !== false || !vibesUtf8Ok($data);
	return ['path' => $rel, 'size' => $size, 'truncated' => $size > $cap, 'binary' => $binary, 'lang' => vibesLangFor($rel),
	        'content' => $binary ? null : $data, 'mtime' => filemtime($full)];
}

function vibesAct_saveFile($pdo, $in) {
	$id   = vibesNeedInt($in, 'id', 'A Vibe id');
	$rel  = (string)vibesNeed($in, 'path', 'A file path');
	if (!array_key_exists('content', $in) || !is_string($in['content'])) throw new VibesHttpError("'content' is required.", 400);
	$row  = vibesProjectRaw($pdo, $id);
	if ($j = vibesJobOpen($pdo, $id)) throw new VibesHttpError("Hugin is working on this Vibe ('{$j['kind']}'); wait for him before editing files.", 409);
	$full = vibesSafeProjectPath($row['slug'], $rel);
	if ($full === null) throw new VibesHttpError('That path is outside the Vibe.', 400);
	if (strlen($in['content']) > 2 * 1024 * 1024) throw new VibesHttpError('Files over 2 MB cannot be edited here.', 413);
	@mkdir(dirname($full), 0775, true);
	if (file_put_contents($full, $in['content']) === false) throw new VibesHttpError("Could not write $rel.", 500);
	$commit = null;
	vibesRunnerLoad();
	if (function_exists('vibesGitCommit')) {
		// vibesGitCommit answers [ok, hashOrError]; hand the browser named keys.
		try { [$ok, $h] = vibesGitCommit($row['slug'], 'operator edit: ' . $rel); $commit = ['ok' => (bool)$ok, 'hash' => $ok ? $h : '', 'error' => $ok ? '' : $h]; }
		catch (Throwable $e) { $commit = ['ok' => false, 'hash' => '', 'error' => $e->getMessage()]; }
	}
	return ['path' => $rel, 'size' => filesize($full), 'commit' => $commit];
}

function vibesAct_build($pdo, $in) {
	vibesRequireInstalled($pdo);
	$id = vibesNeedInt($in, 'id', 'A Vibe id'); vibesProjectRaw($pdo, $id);
	$args = [];
	if (!empty($in['configuration'])) {
		if (!in_array($in['configuration'], ['Release', 'Debug'], true)) throw new VibesHttpError('Configuration must be Release or Debug.', 400);
		$args['configuration'] = $in['configuration'];
	}
	return vibesEnqueueOrFail($pdo, 'build', $id, null, $args);
}

function vibesAct_package($pdo, $in) {
	vibesRequireInstalled($pdo);
	$id = vibesNeedInt($in, 'id', 'A Vibe id'); vibesProjectRaw($pdo, $id);
	return vibesEnqueueOrFail($pdo, 'package', $id, null, []);
}

function vibesAct_publish($pdo, $in) {
	vibesRequireInstalled($pdo);
	$id = vibesNeedInt($in, 'id', 'A Vibe id'); vibesProjectRaw($pdo, $id);
	$args = [];
	if (!empty($in['version'])) {
		if (!vibesValidVersion($in['version'])) throw new VibesHttpError('The version must look like 1.2.3.', 400);
		$args['version'] = $in['version'];
	}
	return vibesEnqueueOrFail($pdo, 'publish', $id, null, $args);
}

function vibesAct_unpublish($pdo, $in) {
	vibesRequireInstalled($pdo);
	$id = vibesNeedInt($in, 'id', 'A Vibe id'); vibesProjectRaw($pdo, $id);
	$v  = (string)vibesNeed($in, 'version', 'A version');
	if (!vibesValidVersion($v)) throw new VibesHttpError('The version must look like 1.2.3.', 400);
	return vibesEnqueueOrFail($pdo, 'unpublish', $id, null, ['version' => $v]);
}

function vibesAct_push($pdo, $in) {
	vibesRequireInstalled($pdo);
	$id = vibesNeedInt($in, 'id', 'A Vibe id'); vibesProjectRaw($pdo, $id);
	$remote = (string)vibesNeed($in, 'remote', 'A remote (github or gitlab)');
	if (!in_array($remote, ['github', 'gitlab'], true)) throw new VibesHttpError('remote must be github or gitlab.', 400);
	$doc = vibesSettingsGet($pdo);
	if (!vibesSetting($doc, "$remote.enabled")) throw new VibesHttpError(ucfirst($remote) . ' is not enabled under Settings > ' . ucfirst($remote) . '.', 409);
	return vibesEnqueueOrFail($pdo, 'push', $id, null, ['remote' => $remote]);
}

function vibesAct_gitLog($pdo, $in) {
	$id  = vibesNeedInt($in, 'id', 'A Vibe id');
	$row = vibesProjectRaw($pdo, $id);
	$n   = max(1, min(500, (int)($in['n'] ?? 30)));
	return ['log' => vibesRunner('vibesGitLog', $row['slug'], $n)];
}

function vibesAct_gitDiff($pdo, $in) {
	$id  = vibesNeedInt($in, 'id', 'A Vibe id');
	$row = vibesProjectRaw($pdo, $id);
	$ref = (string)($in['ref'] ?? 'HEAD');
	// A ref is an argument to git; keep it to the characters a hash or a simple name uses.
	if (!preg_match('/^[A-Za-z0-9_.~^\/-]{1,80}$/', $ref) || $ref[0] === '-') throw new VibesHttpError('That does not look like a git ref.', 400);
	$path = isset($in['path']) && $in['path'] !== '' ? (string)$in['path'] : null;
	if ($path !== null && vibesSafeProjectPath($row['slug'], $path) === null) throw new VibesHttpError('That path is outside the Vibe.', 400);
	$diff = (string)vibesRunner('vibesGitDiff', $row['slug'], $ref, $path);
	$cap  = 400 * 1024;
	return ['ref' => $ref, 'path' => $path, 'diff' => strlen($diff) > $cap ? substr($diff, 0, $cap) : $diff, 'truncated' => strlen($diff) > $cap];
}

function vibesAct_gitStatus($pdo, $in) {
	$id  = vibesNeedInt($in, 'id', 'A Vibe id');
	$row = vibesProjectRaw($pdo, $id);
	return ['status' => vibesRunner('vibesGitStatus', $row['slug'])];
}

function vibesAct_notes($pdo, $in) {
	$id   = vibesNeedInt($in, 'id', 'A Vibe id');
	$row  = vibesProjectRaw($pdo, $id);
	$full = vibesSafeProjectPath($row['slug'], '.vibes/notes.md');
	return ['content' => $full !== null && is_file($full) ? (string)file_get_contents($full) : ''];
}

function vibesAct_saveNotes($pdo, $in) {
	$id   = vibesNeedInt($in, 'id', 'A Vibe id');
	if (!array_key_exists('content', $in) || !is_string($in['content'])) throw new VibesHttpError("'content' is required.", 400);
	$row  = vibesProjectRaw($pdo, $id);
	$full = vibesSafeProjectPath($row['slug'], '.vibes/notes.md');
	if ($full === null) throw new VibesHttpError('The Vibe directory is missing.', 404);
	@mkdir(dirname($full), 0775, true);
	if (file_put_contents($full, $in['content']) === false) throw new VibesHttpError('Could not write the notes.', 500);
	$commit = null;
	vibesRunnerLoad();
	if (function_exists('vibesGitCommit') && !vibesJobOpen($pdo, $id)) {
		try { [$ok, $h] = vibesGitCommit($row['slug'], 'operator edit: .vibes/notes.md'); $commit = ['ok' => (bool)$ok, 'hash' => $ok ? $h : '', 'error' => $ok ? '' : $h]; }
		catch (Throwable $e) { $commit = ['ok' => false, 'hash' => '', 'error' => $e->getMessage()]; }
	}
	return ['size' => filesize($full), 'commit' => $commit];
}

/** Assets: operator-uploaded files under /opt/stateful/vibes/assets, flat, safe names only. */
function vibesAssetSafeName($name) {
	$n = basename(str_replace('\\', '/', (string)$name));
	$n = preg_replace('/[^A-Za-z0-9._ -]+/', '_', $n);
	$n = trim($n, '. ');
	if ($n === '' || strlen($n) > 120) return null;
	return $n;
}

function vibesAct_assets($pdo, $in) {
	$doc = vibesSettingsGet($pdo);
	$out = [];
	$dir = vibesAssetsDir();
	foreach (glob($dir . '/*') ?: [] as $f) {
		if (!is_file($f)) continue;
		$name = basename($f);
		$ext  = strtolower(pathinfo($name, PATHINFO_EXTENSION));
		$usedBy = 0;
		// "Used by": projects whose files mention the asset's name. A grep per asset is cheap at
		// this scale (a handful of projects, tens of MB) and honest about what "used" means.
		$pd = vibesProjectsDir();
		if (is_dir($pd) && (glob($pd . '/*', GLOB_ONLYDIR) ?: [])) {
			$cmd = 'grep -rlF --exclude-dir=.git --exclude-dir=bin --exclude-dir=obj --exclude-dir=dist -- ' . escapeshellarg($name) . ' ' . escapeshellarg($pd) . ' 2>/dev/null | awk -F/ \'{print $(5)}\' | sort -u | wc -l';
			$usedBy = (int)trim((string)@shell_exec($cmd));
		}
		$out[] = ['name' => $name, 'size' => filesize($f), 'mtime' => filemtime($f),
		          'image' => in_array($ext, ['png', 'jpg', 'jpeg', 'gif', 'webp', 'svg'], true), 'usedBy' => $usedBy];
	}
	return ['assets' => $out, 'maxBytes' => vibesUploadMaxBytes($doc), 'dir' => $dir];
}

function vibesAct_uploadAsset($pdo, $in) {
	$doc = vibesSettingsGet($pdo);
	$max = vibesUploadMaxBytes($doc);
	if (empty($_FILES['file'])) {
		// PHP drops the whole request past post_max_size and leaves $_FILES empty; say so.
		$len = (int)($_SERVER['CONTENT_LENGTH'] ?? 0);
		if ($len > 0 && $len > vibesIniBytes(ini_get('post_max_size'))) throw new VibesHttpError('The upload is larger than this server accepts (' . vibesHumanBytes(vibesIniBytes(ini_get('post_max_size'))) . ').', 413);
		throw new VibesHttpError("A multipart field named 'file' is required.", 400);
	}
	$f = $_FILES['file'];
	if ($f['error'] !== UPLOAD_ERR_OK) {
		$why = [UPLOAD_ERR_INI_SIZE => 'it is larger than PHP allows', UPLOAD_ERR_FORM_SIZE => 'it is too large', UPLOAD_ERR_PARTIAL => 'it arrived incomplete', UPLOAD_ERR_NO_FILE => 'no file was sent'];
		throw new VibesHttpError('Upload failed: ' . ($why[$f['error']] ?? 'error ' . $f['error']) . '.', 400);
	}
	if ($f['size'] > $max) throw new VibesHttpError('The file is ' . vibesHumanBytes($f['size']) . '; the limit is ' . vibesHumanBytes($max) . ' (Settings > Assets).', 413);
	$name = vibesAssetSafeName($f['name']);
	if ($name === null) throw new VibesHttpError('That file name cannot be stored; rename it to letters, digits, dots, dashes or underscores.', 400);
	@mkdir(vibesAssetsDir(), 0775, true);
	$dest = vibesAssetsDir() . '/' . $name;
	if (is_file($dest) && !vibesBool($in['overwrite'] ?? false)) throw new VibesHttpError("An asset named $name already exists. Delete it first or upload with overwrite.", 409);
	if (!move_uploaded_file($f['tmp_name'], $dest)) throw new VibesHttpError('The file could not be stored on the data volume.', 500);
	@chmod($dest, 0644);
	return ['asset' => ['name' => $name, 'size' => filesize($dest), 'mtime' => filemtime($dest)]];
}

function vibesAct_deleteAsset($pdo, $in) {
	$name = vibesAssetSafeName(vibesNeed($in, 'name', 'An asset name'));
	if ($name === null) throw new VibesHttpError('Bad asset name.', 400);
	$f = vibesAssetsDir() . '/' . $name;
	if (!is_file($f)) throw new VibesHttpError("No asset named $name.", 404);
	if (!@unlink($f)) throw new VibesHttpError("Could not delete $name.", 500);
	return ['deleted' => $name];
}

/** Not JSON: serves an asset's bytes (image previews). The assets dir is not under the web root. */
function vibesAct_asset($pdo, $in) {
	$name = vibesAssetSafeName(vibesNeed($in, 'name', 'An asset name'));
	if ($name === null) throw new VibesHttpError('Bad asset name.', 400);
	$f = vibesAssetsDir() . '/' . $name;
	if (!is_file($f)) throw new VibesHttpError("No asset named $name.", 404);
	$mime = (new finfo(FILEINFO_MIME_TYPE))->file($f) ?: 'application/octet-stream';
	// SVG is a script container; serve it as text so an uploaded file can never run as a page.
	if (stripos($mime, 'svg') !== false || stripos($mime, 'html') !== false) $mime = 'text/plain';
	while (ob_get_level() > 0) ob_end_clean();
	header('Content-Type: ' . $mime);
	header('Content-Length: ' . filesize($f));
	header('X-Content-Type-Options: nosniff');
	header('Content-Disposition: inline; filename="' . $name . '"');
	readfile($f);
	exit;
}

function vibesAct_settings($pdo, $in) {
	$doc = vibesSettingsGet($pdo);
	$providers = [];
	foreach (aiProviders($pdo, false) as $p) $providers[] = ['id' => $p['id'], 'label' => $p['label'], 'kind' => $p['kind'], 'model' => $p['model'], 'enabled' => $p['enabled'], 'is_default' => $p['is_default']];
	vibesRunnerLoad();
	$templates = function_exists('vibesTemplates') ? vibesTemplates() : [];
	$libs = vibesLibsManifest();
	$libFiles = [];
	foreach (glob(vibesLibsDir() . '/*.dll') ?: [] as $f) $libFiles[] = ['name' => basename($f), 'size' => filesize($f)];
	return ['settings' => vibesSettingsForClient($doc), 'providers' => $providers, 'templates' => $templates,
	        'worldsWithServerFiles' => vibesWorldsWithServerFiles(), 'libs' => $libs, 'libFiles' => $libFiles,
	        'uploadMaxBytes' => vibesUploadMaxBytes($doc), 'phpUploadMax' => vibesIniBytes(ini_get('upload_max_filesize')),
	        'pak' => vibesPakManifest(), 'row' => vibesSettingsRow($pdo)];
}

function vibesAct_saveSettings($pdo, $in) {
	$incoming = $in['settings'] ?? null;
	if (!is_array($incoming)) throw new VibesHttpError("A 'settings' object is required.", 400);
	$stored = vibesSettingsGet($pdo);
	[$doc, $errors] = vibesSettingsApplyClient($stored, $incoming);
	if ($errors) throw new VibesHttpError(implode(' ', $errors), 400, ['errors' => $errors]);
	if (!vibesSettingsSave($pdo, $doc)) throw new VibesHttpError('The settings could not be saved.', 500);
	phvEvent($pdo, 'notice', "Hugin's Vibes settings saved (" . implode(', ', array_slice(array_keys($incoming), 0, 8)) . ')');
	return ['settings' => vibesSettingsForClient($doc)];
}

/** Test with the STORED document overlaid by whatever is unsaved in the form (masked tokens resolve). */
function vibesScmTestAction($pdo, $in, $which) {
	$stored = vibesSettingsGet($pdo);
	$doc = $stored;
	if (isset($in['settings']) && is_array($in['settings'])) [$doc] = vibesSettingsApplyClient($stored, $in['settings']);
	if ((string)vibesSetting($doc, "$which.token", '') === '') throw new VibesHttpError('Enter a ' . ucfirst($which) . ' token first.', 400);
	$r = vibesRunner('vibesScmTest', $doc, $which);
	if (empty($r['success'])) throw new VibesHttpError($r['error'] ?? ucfirst($which) . ' did not accept the token.', 502);
	return $r;
}
function vibesAct_testGitHub($pdo, $in) { return vibesScmTestAction($pdo, $in, 'github'); }
function vibesAct_testGitLab($pdo, $in) { return vibesScmTestAction($pdo, $in, 'gitlab'); }

function vibesAct_aiModels($pdo, $in) {
	$pid = vibesNeedInt($in, 'provider', 'A provider id');
	$p = aiProvider($pdo, $pid);
	if (!$p) throw new VibesHttpError("No AI provider with id $pid.", 404);
	$r = aiCachedModels($pdo, $p, vibesBool($in['force'] ?? false));
	return ['models' => $r['models'] ?? [], 'error' => $r['error'] ?? '', 'cached' => !empty($r['cached'])];
}

function vibesAct_dismissNotice($pdo, $in) {
	$pdo->exec("UPDATE settings SET vibesNoticeShown = 1");
	return ['noticeShown' => true];
}

/* ------------------------------------------------------------------------------------------
 * Dispatch
 * ---------------------------------------------------------------------------------------- */

/** action => required method. GET actions read; everything that writes or launches is POST. */
function vibesActions() {
	return [
		'state' => 'GET', 'pakInfo' => 'GET', 'projects' => 'GET', 'templates' => 'GET', 'project' => 'GET',
		'exportProject' => 'GET', 'sessions' => 'GET', 'transcript' => 'GET', 'job' => 'GET', 'jobs' => 'GET', 'jobLog' => 'GET',
		'file' => 'GET', 'gitLog' => 'GET', 'gitDiff' => 'GET', 'gitStatus' => 'GET', 'notes' => 'GET', 'assets' => 'GET',
		'asset' => 'GET', 'settings' => 'GET', 'aiModels' => 'GET',
		'install' => 'POST', 'uninstall' => 'POST', 'harvest' => 'POST', 'pakUpdate' => 'POST', 'gc' => 'POST',
		'createProject' => 'POST', 'updateProject' => 'POST', 'deleteProject' => 'POST', 'newSession' => 'POST',
		'renameSession' => 'POST', 'turn' => 'POST', 'cancel' => 'POST', 'saveFile' => 'POST', 'build' => 'POST',
		'package' => 'POST', 'publish' => 'POST', 'unpublish' => 'POST', 'push' => 'POST', 'saveNotes' => 'POST',
		'uploadAsset' => 'POST', 'deleteAsset' => 'POST', 'saveSettings' => 'POST', 'testGitHub' => 'POST',
		'testGitLab' => 'POST', 'dismissNotice' => 'POST',
	];
}

function vibesApiDispatch($pdo) {
	$action  = (string)($_GET['action'] ?? '');
	$actions = vibesActions();
	if ($action === '' || !isset($actions[$action])) vibesFail($action === '' ? 'No action given.' : "Unknown action '$action'.", 404);
	$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
	if ($method !== $actions[$action]) vibesFail("$action requires {$actions[$action]}.", 405);
	$fn = 'vibesAct_' . $action;
	$in = vibesInput();
	$out = $fn($pdo, $in);
	if (!is_array($out)) $out = ['result' => $out];
	// An action may answer "no, and here is why" without throwing (pakInfo when GitHub is down):
	// it names its own status, or gets a 400 by default.
	$status = (int)($out['httpStatus'] ?? ((isset($out['success']) && $out['success'] === false) ? 400 : 200));
	unset($out['httpStatus']);
	vibesOut($out, $status);
}

if (!defined('VIBES_API_LIBRARY')) {
	vibesApiArmHandlers();
	vibesApiDispatch($pdo);
}
