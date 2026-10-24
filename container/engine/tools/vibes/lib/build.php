<?php
/**
 * Hugin's Vibes -- dotnet build and its diagnostics (design 6.3).
 *
 * The build runs with the pak environment and two properties, -p:VibesLibs and -p:VibesPak, so
 * the committed Directory.Build.props can keep server paths as DEFAULTS while a clone elsewhere
 * overrides them. Raw output goes to .vibes/builds/<n>.log; the parsed summary to last.json.
 */
require_once '/opt/stateless/nginx/www/includes/vibes.php';
require_once __DIR__ . '/env.php';

/** The project's csproj, or null. One per project; the template names it <Name>.csproj. */
function vibesProjectCsproj($slug) {
	$dir = vibesProjectDir($slug);
	$found = glob("$dir/*.csproj") ?: [];
	return $found ? $found[0] : null;
}

/**
 * Parse dotnet/MSBuild output into [{file,line,col,code,severity,message}], deduplicated.
 * dotnet prints every diagnostic twice (once from the compiler, once summarised with the
 * "[/path/proj.csproj]" suffix), and MSBuild errors (MSB..., NU...) carry no position.
 */
function vibesBuildParseDiagnostics($text) {
	$out = []; $seen = [];
	foreach (preg_split('/\r?\n/', (string)$text) as $line) {
		$line = trim($line);
		if ($line === '') continue;
		$d = null;
		if (preg_match('/^(.+?)\((\d+),(\d+)(?:,\d+,\d+)?\):\s+(error|warning)\s+([A-Z]{1,5}\d{3,5}):\s+(.*?)(?:\s+\[[^\]]+\])?$/', $line, $m)) {
			$d = ['file' => $m[1], 'line' => (int)$m[2], 'col' => (int)$m[3], 'code' => $m[5], 'severity' => $m[4], 'message' => $m[6]];
		} elseif (preg_match('/^(?:(.+?)\s*:\s+)?(error|warning)\s+([A-Z]{1,5}\d{3,5}):\s+(.*?)(?:\s+\[[^\]]+\])?$/', $line, $m)) {
			$file = $m[1] !== '' ? $m[1] : null;
			// "MSBUILD : error MSB1009" -- the tool's name is not a file.
			if ($file !== null && !preg_match('~[/.]~', $file)) $file = null;
			$d = ['file' => $file, 'line' => null, 'col' => null, 'code' => $m[3], 'severity' => $m[2], 'message' => $m[4]];
		}
		if ($d === null) continue;
		// Relative to the project when the path is inside it, so the UI can open the file.
		if ($d['file'] !== null && preg_match('~/projects/[a-z0-9-]+/(.+)$~', $d['file'], $pm)) $d['file'] = $pm[1];
		$key = implode('|', [$d['file'], $d['line'], $d['col'], $d['code'], $d['message']]);
		if (isset($seen[$key])) continue;
		$seen[$key] = true;
		$out[] = $d;
	}
	return $out;
}

/**
 * Build the project. Returns last.json's shape: {ok, errors[], warnings, ms, at, configuration,
 * log, diagnostics[]}. $pdo (optional) updates hv_projects.last_build_ok/at and status.
 */
function vibesBuild($slug, $configuration = null, $pdo = null, $doc = null) {
	$dir = vibesProjectDir($slug);
	$doc = $doc ?: vibesSettingsDefaults();
	$configuration = in_array($configuration, ['Release', 'Debug'], true) ? $configuration : (string)vibesSetting($doc, 'defaults.configuration', 'Release');
	$at = date('c');
	$fail = function ($msg) use ($slug, $configuration, $at, $pdo) {
		$res = ['ok' => false, 'errors' => [['file' => null, 'line' => null, 'col' => null, 'code' => 'VIBES', 'severity' => 'error', 'message' => $msg]],
		        'warnings' => 0, 'ms' => 0, 'at' => $at, 'configuration' => $configuration, 'log' => null, 'diagnostics' => []];
		vibesBuildRecord($slug, $res, $pdo);
		return $res;
	};
	if (!is_dir($dir)) return $fail("No project directory for '$slug'.");
	if ($missing = vibesPakMissing()) return $fail($missing);
	$csproj = vibesProjectCsproj($slug);
	if (!$csproj) return $fail('The project has no .csproj file.');
	if (!is_dir(vibesLibsDir()) || !is_file(vibesLibsDir() . '/assembly_valheim.dll')) return $fail('The game assemblies have not been harvested yet (libs/assembly_valheim.dll is missing). Run Harvest first.');

	$buildsDir = "$dir/.vibes/builds";
	@mkdir($buildsDir, 0775, true);
	$n = 1;
	foreach (glob("$buildsDir/*.log") ?: [] as $f) $n = max($n, (int)basename($f, '.log') + 1);
	$logFile = "$buildsDir/$n.log";

	[$r, $args] = vibesBuildRunDotnet($dir, $csproj, $configuration, $doc, 900);
	$text = $r['out'] . ($r['err'] !== '' ? "\n--- stderr ---\n" . $r['err'] : '');
	@file_put_contents($logFile, "# dotnet build $configuration @ $at\n# " . implode(' ', array_map('escapeshellarg', $args)) . "\n\n" . $text);
	$diags = vibesBuildParseDiagnostics($text);
	$errors = array_values(array_filter($diags, function ($d) { return $d['severity'] === 'error'; }));
	$warnings = count($diags) - count($errors);
	$ok = $r['ok'] && !$errors;
	if (!$ok && !$errors) {
		$errors[] = ['file' => null, 'line' => null, 'col' => null, 'code' => 'VIBES', 'severity' => 'error',
		             'message' => $r['timedOut'] ? 'dotnet build exceeded its 15-minute limit and was stopped.' : ('dotnet build exited ' . $r['code'] . ' without a diagnostic; see the log.')];
	}
	$res = ['ok' => $ok, 'errors' => $errors, 'warnings' => $warnings, 'ms' => $r['ms'], 'at' => $at,
	        'configuration' => $configuration, 'log' => ".vibes/builds/$n.log", 'diagnostics' => $diags];
	vibesBuildRecord($slug, $res, $pdo);
	return $res;
}

/**
 * The dotnet invocation itself, shared by project builds and the install smoke build (which
 * compiles a template in downloads/smoke, not a project). Returns [vibesRun result, argv].
 */
function vibesBuildRunDotnet($dir, $csproj, $configuration, array $doc, $timeoutS = 900) {
	$pak = vibesPakCurrent();
	$args = [vibesPakBin('dotnet'), 'build', $csproj, '-c', $configuration, '-nologo',
	         '-v', (string)vibesSetting($doc, 'advanced.dotnet_verbosity', 'minimal'),
	         '-p:VibesLibs=' . vibesLibsDir(), '-p:VibesPak=' . $pak,
	         '-p:TreatWarningsAsErrors=false', '-consoleLoggerParameters:NoSummary;ForceNoAlign'];
	if (is_file("$pak/NuGet.Config")) $args[] = '-p:RestoreConfigFile=' . $pak . '/NuGet.Config';
	// Offline = the pak's warmed cache is the ONLY source; a missing package then fails fast
	// with NU1101 instead of hanging on a network the container may not have.
	if (!(bool)vibesSetting($doc, 'advanced.nuget_online', true)) $args[] = '-p:RestoreSources=' . $pak . '/nuget';
	return [vibesRun($args, $dir, vibesPakEnv(), $timeoutS), $args];
}

/** last.json + hv_projects.last_build_ok/at; draft -> built on the first green build. */
function vibesBuildRecord($slug, array $res, $pdo) {
	vibesWriteJson(vibesProjectDir($slug) . '/.vibes/builds/last.json', $res);
	if ($pdo) {
		try {
			$pdo->prepare("UPDATE hv_projects SET last_build_ok=?, last_build_at=NOW() WHERE slug=?")->execute([$res['ok'] ? 1 : 0, $slug]);
			if ($res['ok']) $pdo->prepare("UPDATE hv_projects SET status='built' WHERE slug=? AND status='draft'")->execute([$slug]);
		} catch (Throwable $e) { /* a build result is on disk regardless */ }
	}
}

function vibesBuildLast($slug) {
	$v = vibesReadJson(vibesProjectDir($slug) . '/.vibes/builds/last.json', null);
	return is_array($v) ? $v : null;
}

/** The built plugin DLL (newest across configurations), or null. */
function vibesBuildOutputDll($slug, $name) {
	$best = null; $bt = 0;
	foreach (glob(vibesProjectDir($slug) . "/bin/*/net472/$name.dll") ?: [] as $f) {
		$t = filemtime($f);
		if ($t > $bt) { $bt = $t; $best = $f; }
	}
	return $best;
}
