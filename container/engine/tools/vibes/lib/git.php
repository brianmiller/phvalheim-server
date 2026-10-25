<?php
/**
 * Hugin's Vibes -- thin wrappers over the Build Pak's git (design 6, lib/git.php).
 *
 * /usr/bin/git does not exist in the image; every call goes through vibesPakBin('git') with the
 * pak environment. Arguments are always arrays (vibesRun), so a commit message written by the
 * model can never become shell. Tokens travel in GIT_CONFIG_* environment entries, never on the
 * command line (visible in /proc) and never in .git/config (a file the operator can export).
 */
require_once '/opt/stateless/nginx/www/includes/vibes.php';
require_once __DIR__ . '/env.php';

/**
 * The settings document the identity is read from. The runner and the API both call this once
 * after vibesSettingsGet(); without it the house name is used. A setter rather than a global so
 * the file is includable from php-fpm without side effects.
 */
function vibesGitSettings(array $doc = null) {
	static $cur = null;
	if ($doc !== null) $cur = $doc;
	return $cur;
}

/** Author/committer identity from settings author.display, else the house name. */
function vibesGitIdentity(array $doc = null) {
	$doc = $doc ?: vibesGitSettings();
	$display = trim((string)($doc ? vibesSetting($doc, 'author.display', '') : ''));
	if ($display === '') $display = "Hugin's Vibes";
	$name = preg_replace('/[<>\r\n]/', '', $display);
	return ['name' => $name, 'email' => 'vibes@phvalheim'];
}

/** Run git in a project. Returns vibesRun's array. $extraEnv is merged over the pak env. */
function vibesGit($slug, array $args, $extraEnv = [], $timeoutS = 120, $cwd = null) {
	$git = vibesPakBin('git');
	if (!$git) return ['ok' => false, 'code' => 127, 'out' => '', 'err' => vibesPakMissing() ?: 'The Build Pak has no git.', 'timedOut' => false, 'ms' => 0];
	$dir = $cwd ?: vibesProjectDir($slug);
	if (!is_dir($dir)) return ['ok' => false, 'code' => 1, 'out' => '', 'err' => "no project directory for '$slug'", 'timedOut' => false, 'ms' => 0];
	$id = vibesGitIdentity();
	$env = vibesPakEnv() + [
		'GIT_AUTHOR_NAME' => $id['name'], 'GIT_AUTHOR_EMAIL' => $id['email'],
		'GIT_COMMITTER_NAME' => $id['name'], 'GIT_COMMITTER_EMAIL' => $id['email'],
		'GIT_TERMINAL_PROMPT' => '0', 'GIT_CONFIG_NOSYSTEM' => '1',
	];
	foreach ($extraEnv as $k => $v) $env[$k] = $v;
	return vibesRun(array_merge([$git, '-c', 'core.hooksPath=/dev/null', '-c', 'protocol.file.allow=never', '-C', $dir], $args), $dir, $env, $timeoutS);
}

/** `git init -b main` plus the two settings a bare container needs (no global config exists). */
function vibesGitInit($slug) {
	$r = vibesGit($slug, ['init', '-q', '-b', 'main']);
	if (!$r['ok']) return [false, trim($r['err'])];
	vibesGit($slug, ['config', 'core.autocrlf', 'false']);
	vibesGit($slug, ['config', 'commit.gpgsign', 'false']);
	return [true, ''];
}

/** git add -A && commit. [ok, hash|error]. A clean tree returns [true, ''] -- nothing to commit is not a failure. */
function vibesGitCommit($slug, $msg) {
	$a = vibesGit($slug, ['add', '-A']);
	if (!$a['ok']) return [false, trim($a['err']) ?: 'git add failed'];
	$st = vibesGit($slug, ['status', '--porcelain']);
	if ($st['ok'] && trim($st['out']) === '') return [true, ''];
	$msg = trim((string)$msg) === '' ? 'vibe: update' : vibesSubstr(trim($msg), 0, 500);
	$c = vibesGit($slug, ['commit', '-q', '-m', $msg]);
	if (!$c['ok']) return [false, trim($c['err'] ?: $c['out']) ?: 'git commit failed'];
	$h = vibesGit($slug, ['rev-parse', '--short', 'HEAD']);
	return [true, trim($h['out'])];
}

function vibesGitLog($slug, $n = 30) {
	$n = max(1, min(500, (int)$n));
	$r = vibesGit($slug, ['log', "-$n", '--date=iso-strict', '--pretty=format:%H%x1f%h%x1f%ad%x1f%an%x1f%s']);
	if (!$r['ok']) return [];
	$out = [];
	foreach (explode("\n", $r['out']) as $line) {
		if ($line === '') continue;
		$p = explode("\x1f", $line);
		if (count($p) < 5) continue;
		$out[] = ['hash' => $p[0], 'short' => $p[1], 'date' => $p[2], 'author' => $p[3], 'message' => $p[4]];
	}
	return $out;
}

/** Diff of the working tree against $ref (default HEAD), optionally one path; capped at 200 KB. */
function vibesGitDiff($slug, $ref = 'HEAD', $path = null) {
	$args = ['diff', '--no-color', '--stat=120', '-p'];
	if ($ref !== null && $ref !== '' && preg_match('/^[A-Za-z0-9_.\/~^-]{1,80}$/', $ref) && $ref[0] !== '-') $args[] = $ref;
	if ($path !== null && $path !== '') {
		if (vibesSafeProjectPath($slug, $path) === null) return '';
		$args[] = '--'; $args[] = $path;
	}
	$r = vibesGit($slug, $args);
	$text = $r['ok'] ? $r['out'] : '';
	if (strlen($text) > 200 * 1024) $text = substr($text, 0, 200 * 1024) . "\n[... diff truncated at 200 KB]\n";
	return $text;
}

/** [{path, status}] from porcelain v1: 'M', 'A', 'D', '??', 'R' ... */
function vibesGitStatus($slug) {
	$r = vibesGit($slug, ['status', '--porcelain', '--untracked-files=all']);
	if (!$r['ok']) return [];
	$out = [];
	foreach (explode("\n", $r['out']) as $line) {
		if (strlen($line) < 4) continue;
		$status = trim(substr($line, 0, 2));
		$path = substr($line, 3);
		if (strpos($path, ' -> ') !== false) $path = substr($path, strrpos($path, ' -> ') + 4);
		$out[] = ['path' => $path, 'status' => $status];
	}
	return $out;
}

function vibesGitIsDirty($slug) { return count(vibesGitStatus($slug)) > 0; }

/** Annotated tag; refuses names git would. [ok, error]. */
function vibesGitTag($slug, $tag, $message = '') {
	if (!preg_match('/^[A-Za-z0-9][A-Za-z0-9._-]{0,60}$/', (string)$tag)) return [false, "Tag name '$tag' is not acceptable."];
	$r = vibesGit($slug, ['tag', '-a', $tag, '-m', $message === '' ? $tag : $message]);
	return [$r['ok'], $r['ok'] ? '' : trim($r['err'])];
}

/** Add or replace a named remote (github|gitlab) pointing at an https clone URL. */
function vibesGitRemoteSet($slug, $name, $url) {
	if (!in_array($name, ['github', 'gitlab'], true)) return [false, 'remote must be github or gitlab'];
	if (!preg_match('~^https://[A-Za-z0-9.-]+(:\d+)?/[A-Za-z0-9._/-]+(\.git)?$~', $url)) return [false, "Refusing remote URL '$url'."];
	$has = vibesGit($slug, ['remote', 'get-url', $name]);
	$r = $has['ok'] ? vibesGit($slug, ['remote', 'set-url', $name, $url]) : vibesGit($slug, ['remote', 'add', $name, $url]);
	return [$r['ok'], $r['ok'] ? '' : trim($r['err'])];
}

/**
 * Push main and tags with a token that lives ONLY in the process environment:
 * GIT_CONFIG_COUNT/KEY_0/VALUE_0 = http.<url>.extraHeader "Authorization: Basic base64(user:token)",
 * GitHub's user being x-access-token and GitLab's oauth2. [ok, outputText].
 */
function vibesGitPush($slug, $name, $url, $token, $which) {
	$user = $which === 'gitlab' ? 'oauth2' : 'x-access-token';
	$origin = preg_replace('~^(https://[^/]+)/.*$~', '$1', $url);
	$env = [
		'GIT_CONFIG_COUNT'   => '1',
		'GIT_CONFIG_KEY_0'   => "http.$origin/.extraHeader",
		'GIT_CONFIG_VALUE_0' => 'Authorization: Basic ' . base64_encode($user . ':' . $token),
	];
	$r = vibesGit($slug, ['push', '-u', $name, 'main', '--tags'], $env, 600);
	// Never let the header leak through an error message.
	$text = str_replace($token, '[token]', trim($r['out'] . "\n" . $r['err']));
	return [$r['ok'], $text];
}
