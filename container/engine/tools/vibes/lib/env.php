<?php
/**
 * Hugin's Vibes -- the Build Pak environment and the one process runner (design section 6, 8).
 *
 * The runtime image has NO git, NO dotnet, NO node: every binary comes from the extracted Build
 * Pak under /opt/stateful/vibes/buildpak/current. This file is the only place that knows where
 * inside the pak each thing lives, so a pak layout change (pakFormat bump) is a change here.
 *
 * Includable from php-fpm: no echo, no exit, no $argv.
 */
require_once '/opt/stateless/nginx/www/includes/vibes.php';

/**
 * The environment every pak-backed process runs with. HOME is set because dotnet and git both
 * refuse to run without a writable one and php-fpm's user has none; the telemetry/nologo flags
 * keep dotnet's chatter out of the build logs the diagnostic parser reads.
 */
function vibesPakEnv($pakDir = null) {
	// $pakDir overrides `current` so a freshly extracted pak can be self-tested BEFORE it is swapped in.
	$pak = $pakDir ?: vibesPakCurrent();
	$home = vibesPath('');
	$xdg  = '/tmp/vibes-run-' . (function_exists('posix_geteuid') ? posix_geteuid() : (int)getmyuid());
	if (!is_dir($xdg)) { @mkdir($xdg, 0700, true); }
	$path = implode(':', [
		$pak . '/dotnet', $pak . '/tools', $pak . '/git/usr/bin',
		'/usr/local/sbin', '/usr/local/bin', '/usr/sbin', '/usr/bin', '/sbin', '/bin',
	]);
	return [
		'PATH'                                 => $path,
		'HOME'                                 => $home,
		// The container's own /tmp, never the data volume (which may be NFS). Downloads go to
		// vibesDownloadsDir() explicitly; this is only dotnet/git scratch.
		'TMPDIR'                               => '/tmp',
		// A NAMED MUTEX (NuGet's "NuGet-Migrations", taken by every restore) is backed by a file
		// that .NET 8 on Linux puts under $XDG_RUNTIME_DIR -- or, when that is unset, under the
		// user's /etc/passwd HOME, which must already exist and be writable (coreclr
		// pal/sharedmemory.cpp). phvalheim's passwd home is /opt, owned by root, so without this
		// every build died with "cannot open the device or file specified : 'NuGet-Migrations'"
		// while the same build as root passed. Created 0700 below, container-local, per uid.
		'XDG_RUNTIME_DIR'                      => $xdg,
		'DOTNET_ROOT'                          => $pak . '/dotnet',
		'DOTNET_CLI_HOME'                      => $home,
		'DOTNET_CLI_TELEMETRY_OPTOUT'          => '1',
		'DOTNET_NOLOGO'                        => '1',
		'DOTNET_SKIP_FIRST_TIME_EXPERIENCE'    => '1',
		// libicu70 is in the jammy image, so globalization can stay on (design section 6).
		'DOTNET_SYSTEM_GLOBALIZATION_INVARIANT' => '0',
		'NUGET_PACKAGES'                       => $pak . '/nuget',
		'GIT_EXEC_PATH'                        => $pak . '/git/usr/lib/git-core',
		'GIT_TEMPLATE_DIR'                     => $pak . '/git/usr/share/git-core/templates',
		'LD_LIBRARY_PATH'                      => $pak . '/git/usr/lib/x86_64-linux-gnu:' . $pak . '/git/usr/lib',
		'LANG'                                 => 'C.UTF-8',
		'LC_ALL'                               => 'C.UTF-8',
	];
}

/**
 * Absolute path of a pak binary by its short name, or null when the pak does not carry it.
 * 'dotnet' and 'git' are fixed by the layout; anything else is looked up in tools/ (the dotnet
 * tools installed with --tool-path: ilspycmd, the publicizer).
 */
function vibesPakBin($name, $pakDir = null) {
	$pak = $pakDir ?: vibesPakCurrent();
	$map = [
		'dotnet' => $pak . '/dotnet/dotnet',
		'git'    => $pak . '/git/usr/bin/git',
	];
	$p = $map[$name] ?? ($pak . '/tools/' . $name);
	return (is_file($p) && is_executable($p)) ? $p : null;
}

/**
 * The publicizer's executable name inside <pak>/tools is discovered, not assumed: `dotnet tool
 * install BepInEx.AssemblyPublicizer.Cli` registers the command `assembly-publicizer`, but a pak
 * built from a different package version could ship it under the package name. Checked in order;
 * null when none exists.
 */
function vibesPakPublicizer() {
	foreach (['assembly-publicizer', 'AssemblyPublicizer', 'BepInEx.AssemblyPublicizer.Cli', 'assembly-publicizer-cli'] as $n) {
		if ($b = vibesPakBin($n)) return $b;
	}
	$dir = vibesPakCurrent() . '/tools';
	if (is_dir($dir)) {
		foreach (scandir($dir) ?: [] as $f) {
			if ($f[0] !== '.' && stripos($f, 'publicizer') !== false && is_file("$dir/$f") && is_executable("$dir/$f")) return "$dir/$f";
		}
	}
	return null;
}

/** Can a build happen right now? dotnet and git present under a readable manifest. */
function vibesPakReady() {
	return vibesPakMissing() === '';
}

/** A sentence naming what is missing from the pak, or '' when nothing is. */
function vibesPakMissing() {
	if (!is_dir(vibesPakCurrent())) return 'No Build Pak is installed (buildpak/current is missing). Run the install job first.';
	if (vibesPakManifest() === null) return 'The Build Pak has no readable manifest.json.';
	if (!vibesPakBin('dotnet'))     return 'The Build Pak has no dotnet/dotnet executable.';
	if (!vibesPakBin('git'))        return 'The Build Pak has no git/usr/bin/git executable.';
	return '';
}

/**
 * Run a program. $argv is an ARRAY -- never a shell string, so a project name, a commit message
 * or a model-written file path can never become shell. The process is started in its own
 * session (setsid) so the wall-clock timeout can kill the whole group: a hung `dotnet build`
 * has MSBuild node children that would otherwise outlive it and hold the obj/ lock.
 *
 * Returns ['ok', 'code', 'out', 'err', 'timedOut', 'ms']. $onLine(string $line, string $stream)
 * is called per complete line as it arrives, for progress events.
 */
function vibesRun(array $argv, $cwd = null, $env = null, $timeoutS = 600, $onLine = null, $stdin = null) {
	$t0 = microtime(true);
	$cmd = array_merge(['/usr/bin/setsid'], array_map('strval', $argv));
	$spec = [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']];
	$proc = @proc_open($cmd, $spec, $pipes, $cwd ?: null, $env ?: null);
	if (!is_resource($proc)) {
		return ['ok' => false, 'code' => 127, 'out' => '', 'err' => 'could not start ' . ($argv[0] ?? '?'), 'timedOut' => false, 'ms' => 0];
	}
	if ($stdin !== null) fwrite($pipes[0], $stdin);
	fclose($pipes[0]);
	stream_set_blocking($pipes[1], false);
	stream_set_blocking($pipes[2], false);
	$out = ''; $err = ''; $bufs = [1 => '', 2 => '']; $timedOut = false;
	$deadline = $t0 + max(1, (int)$timeoutS);
	$open = [1 => $pipes[1], 2 => $pipes[2]];
	$pid = proc_get_status($proc)['pid'] ?? 0;
	while ($open) {
		$r = array_values($open); $w = null; $x = null;
		$left = $deadline - microtime(true);
		if ($left <= 0) { $timedOut = true; break; }
		$n = @stream_select($r, $w, $x, (int)min(1, $left), 200000);
		if ($n === false) break;
		foreach ($open as $i => $s) {
			$chunk = fread($s, 65536);
			if ($chunk === '' || $chunk === false) {
				if (feof($s)) { fclose($s); unset($open[$i]); }
				continue;
			}
			if ($i === 1) $out .= $chunk; else $err .= $chunk;
			if ($onLine) {
				$bufs[$i] .= $chunk;
				while (($p = strpos($bufs[$i], "\n")) !== false) {
					$onLine(rtrim(substr($bufs[$i], 0, $p), "\r"), $i === 1 ? 'out' : 'err');
					$bufs[$i] = substr($bufs[$i], $p + 1);
				}
			}
		}
	}
	if ($timedOut) {
		// Negative pid = the whole process group that setsid created.
		if ($pid > 0) { @posix_kill(-$pid, 15); usleep(300000); @posix_kill(-$pid, 9); }
		foreach ($open as $s) @fclose($s);
	}
	foreach ([1, 2] as $i) if ($onLine && $bufs[$i] !== '') $onLine(rtrim($bufs[$i], "\r"), $i === 1 ? 'out' : 'err');
	$code = proc_close($proc);
	if ($timedOut && $code === 0) $code = 124;
	return ['ok' => !$timedOut && $code === 0, 'code' => $code, 'out' => $out, 'err' => $err, 'timedOut' => $timedOut,
	        'ms' => (int)round((microtime(true) - $t0) * 1000)];
}

/**
 * mbstring is NOT in the image's PHP (php -m: iconv yes, mbstring no), so every character-count
 * rule (Thunderstore's 250-char description, the 500-char error column) goes through these two.
 */

/** sha256 of a file via the hash extension (always present); '' when unreadable. */
function vibesSha256File($file) {
	$h = @hash_file('sha256', $file);
	return $h === false ? '' : $h;
}

/** rm -rf in PHP, refusing anything outside /opt/stateful/vibes -- the one place the runner may delete. */
function vibesRmTree($dir) {
	$real = realpath($dir);
	if ($real === false) return true;
	if (strncmp($real . '/', VIBES_ROOT . '/', strlen(VIBES_ROOT) + 1) !== 0 || $real === VIBES_ROOT) return false;
	$r = vibesRun(['/bin/rm', '-rf', '--', $real], null, null, 300);
	return $r['ok'];
}
