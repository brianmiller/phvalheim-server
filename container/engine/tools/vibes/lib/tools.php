<?php
/**
 * Hugin's Vibes -- the agent's tools (design 7.2).
 *
 * Every path goes through vibesSafeProjectPath(): no '..', no absolute paths, no symlink out of
 * the project, no .git internals. No tool reaches the network; `shell` runs an allow-list of
 * binaries with a tokenizer that refuses anything shell would interpret, unless the operator has
 * turned on agent.unrestricted_shell with the warning that sits beside that switch.
 *
 * forgeRunTool() takes $ctx BY REFERENCE: it records which files changed and whether a build ran,
 * which the turn's auto-build / auto-commit epilogue reads.
 */
require_once '/opt/stateless/nginx/www/includes/vibes.php';
require_once __DIR__ . '/env.php';
require_once __DIR__ . '/git.php';
require_once __DIR__ . '/build.php';
require_once __DIR__ . '/project.php';

const FORGE_TOOL_RESULT_CAP = 60000;     // bytes per result (as Hugin)
const FORGE_TURN_OUTPUT_CAP = 400000;    // bytes of tool output per turn

/** OpenAI-style definitions, the same shape aiToolDefinitions() returns (name, description, parameters). */
function forgeToolDefinitions() {
	$str = function ($d) { return ['type' => 'string', 'description' => $d]; };
	$int = function ($d) { return ['type' => 'integer', 'description' => $d]; };
	$bool = function ($d) { return ['type' => 'boolean', 'description' => $d]; };
	$obj = function ($props, $req = []) { return ['type' => 'object', 'properties' => $props ? $props : (object)[], 'required' => $req]; };
	return [
		['name' => 'list_files', 'description' => 'List the project tree with sizes (bin/, obj/ and .git/ are omitted). Optional subdirectory.', 'parameters' => $obj(['path' => $str('Directory relative to the project root; default the root.')])],
		['name' => 'read_file', 'description' => 'Read a project file, line-numbered. Use start/end for a window of a long file.', 'parameters' => $obj(['path' => $str('Path relative to the project root.'), 'start' => $int('First line (1-based).'), 'end' => $int('Last line (inclusive).')], ['path'])],
		['name' => 'write_file', 'description' => 'Create or overwrite a project file with the given content. Creates directories. Prefer edit_file for small changes.', 'parameters' => $obj(['path' => $str('Path relative to the project root.'), 'content' => $str('The complete new content.')], ['path', 'content'])],
		['name' => 'edit_file', 'description' => 'Replace one exact occurrence of `old` with `new` in a file. Fails if `old` matches zero or several times -- include enough context to be unique.', 'parameters' => $obj(['path' => $str('Path relative to the project root.'), 'old' => $str('Exact text to replace (must occur once).'), 'new' => $str('Replacement text.')], ['path', 'old', 'new'])],
		['name' => 'delete_file', 'description' => 'Delete a project file.', 'parameters' => $obj(['path' => $str('Path relative to the project root.')], ['path'])],
		['name' => 'search', 'description' => 'grep -rn through the project (bin/obj/.git excluded). Fixed string unless regex=true. Capped.', 'parameters' => $obj(['pattern' => $str('What to search for.'), 'path' => $str('Restrict to a file or directory.'), 'regex' => $bool('Treat pattern as an extended regex.')], ['pattern'])],
		['name' => 'build', 'description' => 'Run dotnet build and return the diagnostics (file, line, code, message). ALWAYS build before saying you are done.', 'parameters' => $obj(['configuration' => $str('Release (default) or Debug.')])],
		['name' => 'shell', 'description' => 'Run one allow-listed command in the project directory (dotnet, git, ls, cat, head, tail, grep, find, wc, sed -n, sort, uniq, diff, unzip -l, file). No pipes, redirects or network. 120 s limit.', 'parameters' => $obj(['cmd' => $str('The command line.')], ['cmd'])],
		['name' => 'game_source', 'description' => 'Search the DECOMPILED game source (libs/decompiled). kind=type returns a class file; kind=member finds a method/field by name; kind=text greps freely. Use this to confirm a game API exists before calling it.', 'parameters' => $obj(['query' => $str('Type name, member name (Type.Member or Member), or text.'), 'kind' => $str('type | member | text (default text).')], ['query'])],
		['name' => 'game_types', 'description' => 'List public game types whose name starts with a prefix (from the publicized assembly index).', 'parameters' => $obj(['prefix' => $str('Case-insensitive prefix, e.g. "Player" or "ZNet".')], ['prefix'])],
		['name' => 'knowledge', 'description' => 'Read one knowledge topic by name. The topic list is in your instructions.', 'parameters' => $obj(['topic' => $str('Topic file name without .md, e.g. harmony-patterns.')], ['topic'])],
		['name' => 'list_assets', 'description' => "List the operator's uploaded assets (icons, textures, audio, reference zips).", 'parameters' => $obj([])],
		['name' => 'read_asset', 'description' => 'Read a text asset, or describe a binary one (size, sha256).', 'parameters' => $obj(['path' => $str('Asset path relative to the assets library.')], ['path'])],
		['name' => 'copy_asset', 'description' => 'Copy an asset into the project.', 'parameters' => $obj(['path' => $str('Asset path.'), 'to' => $str('Destination path relative to the project root.')], ['path', 'to'])],
		['name' => 'manifest', 'description' => 'Read the Thunderstore manifest, or set fields (validated). The name is fixed.', 'parameters' => $obj(['set' => ['type' => 'object', 'description' => 'Fields to change: version_number, description, dependencies (list of Owner-Name-X.Y.Z), website_url.',
		                                                                                                                                                       'properties' => ['version_number' => $str('x.y.z'), 'description' => $str('<= 250 chars'), 'website_url' => $str('http(s) URL or empty'), 'dependencies' => ['type' => 'array', 'items' => ['type' => 'string'], 'description' => 'Owner-Name-X.Y.Z strings']]]])],
		['name' => 'note', 'description' => 'Append a dated bullet to .vibes/notes.md -- your durable memory for this project (decisions, open bugs, TODO). It is read into every future turn.', 'parameters' => $obj(['text' => $str('One note.')], ['text'])],
		['name' => 'git', 'description' => 'status | diff | log | commit (message required) | tag (message = tag name). No push: the operator pushes.', 'parameters' => $obj(['op' => $str('status, diff, log, commit or tag.'), 'message' => $str('Commit message or tag name.')], ['op'])],
	];
}

/* ------------------------------------------------------------------------------------------
 * The shell allow-list
 * ---------------------------------------------------------------------------------------- */

const FORGE_SHELL_ALLOW = ['dotnet', 'git', 'ls', 'cat', 'head', 'tail', 'grep', 'find', 'wc', 'sed', 'sort', 'uniq', 'diff', 'unzip', 'file'];

/** Conservative tokenizer: whitespace split, single/double quotes, backslash escapes. null on an unbalanced quote. */
function vibesShellTokenize($cmd) {
	$out = []; $cur = ''; $q = null; $has = false; $n = strlen($cmd);
	for ($i = 0; $i < $n; $i++) {
		$c = $cmd[$i];
		if ($q === "'") { if ($c === "'") $q = null; else $cur .= $c; continue; }
		if ($q === '"') { if ($c === '"') $q = null; elseif ($c === '\\' && $i + 1 < $n) $cur .= $cmd[++$i]; else $cur .= $c; continue; }
		if ($c === "'" || $c === '"') { $q = $c; $has = true; continue; }
		if ($c === '\\' && $i + 1 < $n) { $cur .= $cmd[++$i]; $has = true; continue; }
		if (ctype_space($c)) { if ($cur !== '' || $has) { $out[] = $cur; $cur = ''; $has = false; } continue; }
		$cur .= $c; $has = true;
	}
	if ($q !== null) return null;
	if ($cur !== '' || $has) $out[] = $cur;
	return $out;
}

/**
 * [ok, argv] or [false, sentence]. Restricted mode refuses shell metacharacters anywhere, binaries
 * off the list, git/dotnet subcommands that reach the network, `sed` without -n or with -i,
 * `unzip` without -l, absolute paths and `..` -- the project directory is the whole world.
 */
function vibesShellCheck($cmd, $unrestricted = false) {
	$cmd = trim((string)$cmd);
	if ($cmd === '') return [false, 'The command is empty.'];
	if (strlen($cmd) > 4000) return [false, 'The command is too long.'];
	if ($unrestricted) return [true, ['/bin/bash', '-c', $cmd]];
	if (preg_match('/[|;&`$<>\n\r(){}]/', $cmd)) return [false, 'Pipes, redirects, command chaining and substitutions are not allowed; run one plain command, or ask the operator to enable the unrestricted shell.'];
	$argv = vibesShellTokenize($cmd);
	if ($argv === null || !$argv) return [false, 'Unbalanced quotes in the command.'];
	$bin = $argv[0];
	// A path to a binary is refused even when its name is listed: the runner resolves the real
	// executable itself (pak first), and "/bin/ls" would otherwise read as "any path I like".
	if (strpos($bin, '/') !== false) return [false, "'$bin' is not on the allowed list (name the command without a path: " . implode(', ', FORGE_SHELL_ALLOW) . ')'];
	if (!in_array($bin, FORGE_SHELL_ALLOW, true)) return [false, "'$bin' is not on the allowed list (" . implode(', ', FORGE_SHELL_ALLOW) . ')'];
	foreach (array_slice($argv, 1) as $a) {
		if ($a !== '' && $a[0] === '/' && strncmp($a, VIBES_ROOT . '/', strlen(VIBES_ROOT) + 1) !== 0) return [false, "Absolute path '$a' is outside /opt/stateful/vibes."];
		if (preg_match('~(^|/)\.\.(/|$)~', $a)) return [false, "'..' is not allowed in paths."];
		if ($a[0] === '~') return [false, 'Home-relative paths are not allowed.'];
	}
	$sub = $argv[1] ?? '';
	if ($bin === 'git' && in_array($sub, ['push', 'fetch', 'pull', 'clone', 'remote', 'submodule', 'ls-remote', 'daemon', 'config'], true)) return [false, "git $sub is not allowed here (the operator pushes from the Git tab)."];
	if ($bin === 'dotnet' && !in_array($sub, ['build', 'test', 'restore', 'clean', 'msbuild', '--version', '--info', '--list-sdks', '--list-runtimes'], true)) return [false, "dotnet $sub is not allowed; use build, test, restore, clean or --version."];
	if ($bin === 'sed' && (!in_array('-n', $argv, true) || preg_grep('/^-[a-zA-Z]*i/', array_slice($argv, 1)))) return [false, 'sed is allowed only as `sed -n ...` (printing), never in-place.'];
	if ($bin === 'unzip' && !in_array('-l', $argv, true) && !in_array('-Z1', $argv, true)) return [false, 'unzip is allowed only for listing (`unzip -l`).'];
	if ($bin === 'find' && (in_array('-delete', $argv, true) || in_array('-exec', $argv, true) || in_array('-execdir', $argv, true) || in_array('-ok', $argv, true))) return [false, 'find may list only (no -delete/-exec).'];
	return [true, $argv];
}

/* ------------------------------------------------------------------------------------------
 * Running a tool
 * ---------------------------------------------------------------------------------------- */

function forgeCtx(PDO $pdo, array $doc, array $project, HvEmitter $emit = null) {
	return ['pdo' => $pdo, 'doc' => $doc, 'project' => $project, 'slug' => $project['slug'], 'dir' => vibesProjectDir($project['slug']),
	        'emit' => $emit, 'changed' => [], 'built' => false, 'lastBuild' => null, 'outBytes' => 0];
}

function forgePath(array $ctx, $rel) {
	$p = vibesSafeProjectPath($ctx['slug'], (string)$rel);
	return $p;
}

function forgeEmit(array $ctx, array $ev) { if ($ctx['emit']) $ctx['emit']->emit($ev); }

function forgeTrim($s, $cap = FORGE_TOOL_RESULT_CAP) {
	if (strlen($s) <= $cap) return $s;
	return substr($s, 0, $cap) . "\n\n[... truncated at " . number_format($cap) . " bytes; narrow the request]";
}

/** Run one tool. Returns the text the model sees. Never throws: a failure is a sentence. */
function forgeRunTool(array &$ctx, $name, $args) {
	$args = is_array($args) ? $args : [];
	try {
		$r = forgeRunToolInner($ctx, $name, $args);
	} catch (Throwable $e) {
		$r = "Tool '$name' failed: " . $e->getMessage();
	}
	$r = forgeTrim((string)$r);
	$ctx['outBytes'] += strlen($r);
	if ($ctx['outBytes'] > FORGE_TURN_OUTPUT_CAP) {
		$r = "[Tool output budget for this turn (400 KB) is exhausted. Summarise what you know and finish, or ask the operator to continue in a new message.]";
	}
	return $r;
}

function forgeRunToolInner(array &$ctx, $name, array $a) {
	$slug = $ctx['slug']; $dir = $ctx['dir'];
	switch ($name) {
	case 'list_files': {
		$sub = trim((string)($a['path'] ?? ''), '/');
		if ($sub !== '' && forgePath($ctx, $sub) === null) return "Refused: '$sub' is not inside the project.";
		$lines = [];
		foreach (vibesProjectTree($slug) as $e) {
			if ($sub !== '' && strncmp($e['path'], $sub . '/', strlen($sub) + 1) !== 0 && $e['path'] !== $sub) continue;
			if (preg_match('~^(dist|\.vibes/(sessions|builds))(/|$)~', $e['path'])) continue;
			$lines[] = ($e['kind'] === 'dir' ? '[dir]  ' : str_pad(number_format($e['size']), 9, ' ', STR_PAD_LEFT) . '  ') . $e['path'];
		}
		return $lines ? implode("\n", $lines) : '(empty)';
	}
	case 'read_file': {
		$p = forgePath($ctx, $a['path'] ?? '');
		if ($p === null) return "Refused: '" . ($a['path'] ?? '') . "' is not a path inside the project.";
		if (!is_file($p)) return "No such file: {$a['path']}";
		if (filesize($p) > 2 * 1024 * 1024) return 'The file is larger than 2 MB; read a window with start/end after searching.';
		$lines = preg_split('/\r?\n/', (string)file_get_contents($p));
		$start = max(1, (int)($a['start'] ?? 1)); $end = (int)($a['end'] ?? 0);
		if ($end <= 0 || $end > count($lines)) $end = count($lines);
		$out = [];
		for ($i = $start; $i <= $end; $i++) $out[] = str_pad((string)$i, 5, ' ', STR_PAD_LEFT) . "  " . ($lines[$i - 1] ?? '');
		return implode("\n", $out);
	}
	case 'write_file': {
		$rel = (string)($a['path'] ?? '');
		$p = forgePath($ctx, $rel);
		if ($p === null) return "Refused: '$rel' is not a path inside the project.";
		if (preg_match('~^(\.vibes/(sessions|builds|project\.json)|bin/|obj/|dist/)~', ltrim($rel, '/'))) return "Refused: '$rel' is generated or private; edit source files only.";
		$content = (string)($a['content'] ?? '');
		$existed = is_file($p);
		$before = $existed ? (string)file_get_contents($p) : '';
		@mkdir(dirname($p), 0775, true);
		if (file_put_contents($p, $content) === false) return "Could not write $rel.";
		[$adds, $dels] = forgeLineDelta($before, $content);
		$ctx['changed'][$rel] = true;
		forgeEmit($ctx, ['type' => 'file_changed', 'path' => $rel, 'kind' => $existed ? 'edit' : 'create', 'adds' => $adds, 'dels' => $dels]);
		return ($existed ? 'Overwrote ' : 'Created ') . "$rel (" . strlen($content) . ' bytes, +' . $adds . ' -' . $dels . ').';
	}
	case 'edit_file': {
		$rel = (string)($a['path'] ?? '');
		$p = forgePath($ctx, $rel);
		if ($p === null) return "Refused: '$rel' is not a path inside the project.";
		if (!is_file($p)) return "No such file: $rel";
		$old = (string)($a['old'] ?? ''); $new = (string)($a['new'] ?? '');
		if ($old === '') return 'old must not be empty.';
		$text = (string)file_get_contents($p);
		$n = substr_count($text, $old);
		if ($n === 0) return "old was not found in $rel (check whitespace and indentation exactly).";
		if ($n > 1) return "old matches $n times in $rel; include more surrounding context so it matches once.";
		$pos = strpos($text, $old);
		$text2 = substr($text, 0, $pos) . $new . substr($text, $pos + strlen($old));
		file_put_contents($p, $text2);
		[$adds, $dels] = forgeLineDelta($old, $new);
		$ctx['changed'][$rel] = true;
		forgeEmit($ctx, ['type' => 'file_changed', 'path' => $rel, 'kind' => 'edit', 'adds' => $adds, 'dels' => $dels]);
		$lineNo = substr_count(substr($text, 0, $pos), "\n") + 1;
		$hunk = "@@ $rel line $lineNo @@\n" . preg_replace('/^/m', '-', $old) . "\n" . preg_replace('/^/m', '+', $new);
		return "Edited $rel.\n$hunk";
	}
	case 'delete_file': {
		$rel = (string)($a['path'] ?? '');
		$p = forgePath($ctx, $rel);
		if ($p === null) return "Refused: '$rel' is not a path inside the project.";
		if (!is_file($p)) return "No such file: $rel";
		if (in_array(ltrim($rel, '/'), ['manifest.json', 'icon.png', 'README.md', '.vibes/notes.md', 'Directory.Build.props'], true) || substr($rel, -7) === '.csproj') return "Refused: $rel is required by the package or the build.";
		@unlink($p);
		$ctx['changed'][$rel] = true;
		forgeEmit($ctx, ['type' => 'file_changed', 'path' => $rel, 'kind' => 'delete', 'adds' => 0, 'dels' => 0]);
		return "Deleted $rel.";
	}
	case 'search': {
		$pattern = (string)($a['pattern'] ?? '');
		if ($pattern === '') return 'pattern is required.';
		$where = '.';
		if (!empty($a['path'])) { if (forgePath($ctx, $a['path']) === null) return "Refused: '{$a['path']}' is not inside the project."; $where = ltrim((string)$a['path'], '/'); }
		$argv = ['/bin/grep', '-rnI', '--exclude-dir=bin', '--exclude-dir=obj', '--exclude-dir=.git', '--exclude-dir=dist', '--exclude-dir=sessions', '--exclude-dir=builds', '-m', '50'];
		$argv[] = !empty($a['regex']) ? '-E' : '-F';
		$argv[] = '-e'; $argv[] = $pattern; $argv[] = '--'; $argv[] = $where;
		$r = vibesRun($argv, $dir, null, 30);
		if ($r['code'] === 1) return 'No matches.';
		if (!$r['ok']) return 'grep failed: ' . trim($r['err']);
		$lines = explode("\n", trim($r['out']));
		if (count($lines) > 200) $lines = array_merge(array_slice($lines, 0, 200), ['[... ' . (count($lines) - 200) . ' more lines]']);
		return implode("\n", $lines);
	}
	case 'build': {
		$res = vibesBuild($slug, $a['configuration'] ?? null, $ctx['pdo'], $ctx['doc']);
		$ctx['built'] = true; $ctx['lastBuild'] = $res;
		forgeEmit($ctx, ['type' => 'build', 'ok' => $res['ok'], 'errors' => $res['errors'], 'warnings' => $res['warnings'], 'ms' => $res['ms']]);
		return forgeBuildText($res);
	}
	case 'shell': {
		$unrestricted = (bool)vibesSetting($ctx['doc'], 'agent.unrestricted_shell', false);
		[$ok, $argv] = vibesShellCheck((string)($a['cmd'] ?? ''), $unrestricted);
		if (!$ok) return "Refused: $argv";
		if (!$unrestricted) {
			// Resolve the binary: pak first (dotnet, git), then the image's /usr/bin, /bin.
			$bin = $argv[0];
			$path = vibesPakBin($bin) ?: (is_file("/usr/bin/$bin") ? "/usr/bin/$bin" : (is_file("/bin/$bin") ? "/bin/$bin" : null));
			if ($path === null) return "Refused: '$bin' is not installed (the Build Pak is " . (vibesPakReady() ? 'present' : 'missing') . ').';
			$argv[0] = $path;
		}
		// No network: a dead proxy makes every HTTP client fail fast instead of hanging.
		$env = vibesPakEnv() + ['http_proxy' => 'http://127.0.0.1:9', 'https_proxy' => 'http://127.0.0.1:9', 'HTTP_PROXY' => 'http://127.0.0.1:9', 'HTTPS_PROXY' => 'http://127.0.0.1:9', 'no_proxy' => ''];
		$r = vibesRun($argv, $dir, $env, 120);
		$text = $r['out'] . ($r['err'] !== '' ? "\n[stderr]\n" . $r['err'] : '');
		if ($r['timedOut']) $text .= "\n[killed after 120 s]";
		return "exit {$r['code']}\n" . ($text === '' ? '(no output)' : $text);
	}
	case 'game_source': return forgeGameSource((string)($a['query'] ?? ''), (string)($a['kind'] ?? 'text'));
	case 'game_types': {
		$prefix = (string)($a['prefix'] ?? '');
		$f = vibesLibsDir() . '/types.txt';
		if (!is_file($f)) return 'The game types index does not exist yet (harvest has not run, or the pak has no ilspycmd).';
		$out = [];
		foreach (file($f, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $t) {
			$short = ($p = strrpos($t, '.')) !== false ? substr($t, $p + 1) : $t;
			if ($prefix === '' || stripos($short, $prefix) === 0 || stripos($t, $prefix) === 0) { $out[] = $t; if (count($out) >= 300) { $out[] = '[... more; use a longer prefix]'; break; } }
		}
		return $out ? implode("\n", $out) : "No public type starts with '$prefix'.";
	}
	case 'knowledge': {
		$topic = (string)($a['topic'] ?? '');
		if (!preg_match('/^[a-z0-9-]{1,64}$/', $topic)) return 'topic must be a lowercase name like harmony-patterns.';
		$f = VIBES_TOOLS . "/knowledge/$topic.md";
		if (!is_file($f)) return "No knowledge topic '$topic'. The available topics are listed in your instructions.";
		return (string)file_get_contents($f);
	}
	case 'list_assets': {
		$root = vibesAssetsDir();
		$out = [];
		$it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::SELF_FIRST);
		foreach ($it as $f) { $rel = substr($f->getPathname(), strlen($root) + 1); $out[] = ($f->isDir() ? '[dir]  ' : str_pad(number_format($f->getSize()), 9, ' ', STR_PAD_LEFT) . '  ') . $rel; if (count($out) > 500) break; }
		return $out ? implode("\n", $out) : 'The assets library is empty (the operator uploads files on the Assets tab).';
	}
	case 'read_asset': {
		$p = forgeAssetPath($a['path'] ?? '');
		if ($p === null || !is_file($p)) return 'No such asset.';
		if (preg_match('/\.(txt|md|json|cs|xml|yml|yaml|cfg|csv|ini)$/i', $p) && filesize($p) < 1024 * 1024) return (string)file_get_contents($p);
		return basename($p) . ': binary, ' . number_format(filesize($p)) . ' bytes, sha256 ' . vibesSha256File($p) . ($dim = vibesPngSize($p) ? ", PNG {$dim[0]}x{$dim[1]}" : '');
	}
	case 'copy_asset': {
		$src = forgeAssetPath($a['path'] ?? '');
		if ($src === null || !is_file($src)) return 'No such asset.';
		$rel = (string)($a['to'] ?? '');
		$dst = forgePath($ctx, $rel);
		if ($dst === null) return "Refused: '$rel' is not a path inside the project.";
		@mkdir(dirname($dst), 0775, true);
		if (!@copy($src, $dst)) return "Could not copy to $rel.";
		$ctx['changed'][$rel] = true;
		forgeEmit($ctx, ['type' => 'file_changed', 'path' => $rel, 'kind' => 'create', 'adds' => 0, 'dels' => 0]);
		return "Copied " . basename($src) . " to $rel (" . number_format(filesize($dst)) . ' bytes).';
	}
	case 'manifest': {
		if (!empty($a['set']) && is_array($a['set'])) {
			$cur = vibesProjectManifestRead($slug);
			[$ok, $errors] = vibesProjectManifestWrite($slug, array_merge($cur, $a['set']));
			if (!$ok) return 'manifest.json NOT changed: ' . implode(' ', $errors);
			$ctx['changed']['manifest.json'] = true;
			forgeEmit($ctx, ['type' => 'file_changed', 'path' => 'manifest.json', 'kind' => 'edit', 'adds' => 0, 'dels' => 0]);
			if (isset($a['set']['version_number'])) {
				// Keep the csproj and PluginVersion in step; a manifest-only bump ships a DLL that lies about itself.
				vibesProjectBumpVersion($slug, (string)$a['set']['version_number'], $ctx['pdo']);
			}
		}
		return json_encode(vibesProjectManifestRead($slug), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
	}
	case 'note': {
		$text = trim(preg_replace('/\s+/', ' ', (string)($a['text'] ?? '')));
		if ($text === '') return 'text is required.';
		$f = "$dir/.vibes/notes.md";
		file_put_contents($f, (is_file($f) && substr((string)file_get_contents($f), -1) !== "\n" ? "\n" : '') . '- ' . date('Y-m-d') . ": $text\n", FILE_APPEND);
		$ctx['changed']['.vibes/notes.md'] = true;
		return 'Noted.';
	}
	case 'git': {
		vibesGitSettings($ctx['doc']);
		switch ((string)($a['op'] ?? '')) {
			case 'status': $s = vibesGitStatus($slug); return $s ? implode("\n", array_map(function ($e) { return "{$e['status']}\t{$e['path']}"; }, $s)) : 'Clean working tree.';
			case 'diff':   $d = vibesGitDiff($slug); return $d === '' ? 'No uncommitted changes.' : $d;
			case 'log':    return implode("\n", array_map(function ($e) { return "{$e['short']}  {$e['date']}  {$e['message']}"; }, vibesGitLog($slug, 30))) ?: 'No commits.';
			case 'commit': { $msg = trim((string)($a['message'] ?? '')); if ($msg === '') return 'message is required for commit.'; [$ok, $h] = vibesGitCommit($slug, $msg); return $ok ? ($h === '' ? 'Nothing to commit.' : "Committed $h.") : "Commit failed: $h"; }
			case 'tag':    { [$ok, $e] = vibesGitTag($slug, (string)($a['message'] ?? '')); return $ok ? 'Tagged.' : "Tag failed: $e"; }
			default: return 'op must be status, diff, log, commit or tag.';
		}
	}
	default: return "Unknown tool '$name'.";
	}
}

/** A path inside the operator's assets library, or null (same discipline as vibesSafeProjectPath). */
function forgeAssetPath($rel) {
	$rel = str_replace('\\', '/', (string)$rel);
	if ($rel === '' || $rel[0] === '/' || preg_match('~(^|/)\.\.(/|$)~', $rel)) return null;
	$root = realpath(vibesAssetsDir());
	if ($root === false) return null;
	$real = realpath("$root/$rel");
	if ($real === false || strncmp($real . '/', $root . '/', strlen($root) + 1) !== 0) return null;
	return $real;
}

function forgeLineDelta($before, $after) {
	$a = $before === '' ? [] : explode("\n", $before);
	$b = $after === '' ? [] : explode("\n", $after);
	$common = count(array_intersect($a, $b));
	return [max(0, count($b) - $common), max(0, count($a) - $common)];
}

function forgeBuildText(array $res) {
	$lines = [($res['ok'] ? 'BUILD SUCCEEDED' : 'BUILD FAILED') . " ({$res['configuration']}, " . round($res['ms'] / 1000, 1) . " s, {$res['warnings']} warning(s))"];
	foreach ($res['diagnostics'] ?? $res['errors'] as $d) {
		$lines[] = sprintf('%s %s: %s%s', strtoupper($d['severity']), $d['code'], $d['file'] !== null ? $d['file'] . ($d['line'] !== null ? "({$d['line']},{$d['col']})" : '') . ': ' : '', $d['message']);
		if (count($lines) > 80) { $lines[] = '[... more diagnostics in the build log]'; break; }
	}
	return implode("\n", $lines);
}

/** game_source: a type's file, a member by name, or free text, from libs/decompiled. */
function forgeGameSource($query, $kind) {
	$root = vibesLibsDir() . '/decompiled';
	if (!is_dir($root)) return 'The game source has not been decompiled (Settings -> Libraries -> decompile, then harvest), so this tool has nothing to search. game_types still works if the index exists.';
	$query = trim($query);
	if ($query === '' || strlen($query) > 200) return 'query is required.';
	if ($kind === 'type') {
		$name = preg_replace('/[^A-Za-z0-9_.]/', '', $query);
		$hits = array_merge(glob("$root/$name.cs") ?: [], glob("$root/*/$name.cs") ?: []);
		if (!$hits) { $r = vibesRun(['/bin/grep', '-rlE', "(class|struct|enum|interface)\\s+$name\\b", '--include=*.cs', $root], null, null, 30); $hits = array_filter(explode("\n", trim($r['out']))); }
		if (!$hits) return "No type '$name' in the decompiled source. Try game_types with a prefix.";
		$f = array_values($hits)[0];
		$text = (string)file_get_contents($f);
		return substr($f, strlen($root) + 1) . ":\n" . (strlen($text) > 50000 ? substr($text, 0, 50000) . "\n[... truncated; use kind=member for one method]" : $text);
	}
	if ($kind === 'member') {
		$parts = explode('.', $query);
		$member = preg_replace('/[^A-Za-z0-9_]/', '', end($parts));
		$type = count($parts) > 1 ? preg_replace('/[^A-Za-z0-9_]/', '', $parts[count($parts) - 2]) : null;
		$files = $type ? array_merge(glob("$root/$type.cs") ?: [], glob("$root/*/$type.cs") ?: []) : [$root];
		if (!$files) return "No type '$type' in the decompiled source.";
		$argv = array_merge(['/bin/grep', '-rnE', '-A', '25', "\\b$member\\s*(\\(|=|;|\\{)", '--include=*.cs'], $files);
		$r = vibesRun($argv, null, null, 30);
		if ($r['code'] === 1) return "No member '$member'" . ($type ? " in $type" : '') . '.';
		$out = str_replace($root . '/', '', $r['out']);
		return strlen($out) > 50000 ? substr($out, 0, 50000) . "\n[... truncated; name the type as Type.Member]" : $out;
	}
	$r = vibesRun(['/bin/grep', '-rnF', '-m', '40', '--include=*.cs', '-e', $query, $root], null, null, 30);
	if ($r['code'] === 1) return 'No matches in the decompiled source.';
	$lines = explode("\n", trim(str_replace($root . '/', '', $r['out'])));
	if (count($lines) > 300) $lines = array_merge(array_slice($lines, 0, 300), ['[... more; narrow the query]']);
	return implode("\n", $lines);
}
