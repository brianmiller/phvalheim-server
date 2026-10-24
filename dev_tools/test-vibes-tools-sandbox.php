<?php
# Oracle: the Forge tools stay inside the project (tools/vibes/lib/tools.php + vibesSafeProjectPath).
#
# The model chooses every path. '..', absolute paths, a symlink that points out of the project
# and .git internals must all be refused by read_file/write_file/edit_file/delete_file/search,
# and edit_file must match exactly once (zero or two matches = refuse, file untouched). Each
# refusal is asserted on the FILESYSTEM, not just on the returned sentence: the file outside
# must still not exist / be unchanged after the call.
#
# Usage: php dev_tools/test-vibes-tools-sandbox.php

require __DIR__ . '/vibes-test-lib.php';
vibesTestLoad('tools');
vibesTestLoad('jobs');

$slug = 'demo'; $dir = VIBES_ROOT . "/projects/$slug";
@mkdir("$dir/src", 0775, true); @mkdir("$dir/.git", 0775, true); @mkdir("$dir/.vibes", 0775, true);
@mkdir(VIBES_ROOT . '/assets/sub', 0775, true);
$outside = VIBES_ROOT . '/outside'; @mkdir($outside); file_put_contents("$outside/secret.txt", 'SECRET');
file_put_contents("$dir/src/Plugin.cs", "line one\nline two\nline two\nline four\n");
file_put_contents("$dir/manifest.json", '{"name":"Demo","version_number":"0.1.0","description":"d","website_url":"","dependencies":[]}');
file_put_contents("$dir/.git/config", '[core]');
file_put_contents("$dir/.vibes/notes.md", "# notes\n");
symlink($outside, "$dir/escape");
symlink("$outside/secret.txt", "$dir/src/link.cs");
file_put_contents(VIBES_ROOT . '/assets/sub/a.txt', 'asset');

$events = VIBES_ROOT . '/events.jsonl';
$emit = new HvEmitter($events);
$ctx = ['pdo' => null, 'doc' => vibesSettingsDefaults(), 'project' => ['slug' => $slug], 'slug' => $slug, 'dir' => $dir, 'emit' => $emit, 'changed' => [], 'built' => false, 'lastBuild' => null, 'outBytes' => 0];
$run = function ($tool, $args) use (&$ctx) { return forgeRunTool($ctx, $tool, $args); };
$refused = function ($out) { return strpos($out, 'Refused:') === 0; };

# vibesSafeProjectPath directly
check('safe: plain path ok', vibesSafeProjectPath($slug, 'src/Plugin.cs') === "$dir/src/Plugin.cs");
check('safe: nonexistent path under an existing dir ok', vibesSafeProjectPath($slug, 'src/New.cs') === "$dir/src/New.cs");
foreach (['../x', 'src/../../x', '/etc/passwd', '.git/config', 'escape/secret.txt', 'src/link.cs', '', 'a/..', 'src\\..\\..\\x'] as $p) {
	check("safe: '$p' -> null", vibesSafeProjectPath($slug, $p) === null);
}
check('safe: bad slug -> null', vibesSafeProjectPath('../demo', 'src/Plugin.cs') === null);

# read_file
check('read_file: line numbers', strpos($run('read_file', ['path' => 'src/Plugin.cs']), "    1  line one") === 0);
check('read_file: window', trim($run('read_file', ['path' => 'src/Plugin.cs', 'start' => 2, 'end' => 2])) === '2  line two');
foreach (['../outside/secret.txt', '/etc/hostname', 'escape/secret.txt', 'src/link.cs', '.git/config'] as $p) {
	$out = $run('read_file', ['path' => $p]);
	check("read_file refused: $p", $refused($out) && strpos($out, 'SECRET') === false, substr($out, 0, 60));
}

# write_file
$out = $run('write_file', ['path' => '../outside/pwned.txt', 'content' => 'x']);
check('write_file refused: .. -- and nothing written outside', $refused($out) && !file_exists("$outside/pwned.txt"));
$out = $run('write_file', ['path' => 'escape/pwned.txt', 'content' => 'x']);
check('write_file refused: through symlink -- nothing written', $refused($out) && !file_exists("$outside/pwned.txt"));
$out = $run('write_file', ['path' => '.git/hooks/post-commit', 'content' => '#!/bin/sh']);
check('write_file refused: .git internals', $refused($out) && !file_exists("$dir/.git/hooks/post-commit"));
$out = $run('write_file', ['path' => '.vibes/project.json', 'content' => '{}']);
check('write_file refused: .vibes/project.json is private', $refused($out));
$out = $run('write_file', ['path' => 'src/New/Thing.cs', 'content' => "a\nb\n"]);
check('write_file: creates dirs and the file', is_file("$dir/src/New/Thing.cs") && file_get_contents("$dir/src/New/Thing.cs") === "a\nb\n", $out);
check('write_file: recorded in ctx.changed', isset($ctx['changed']['src/New/Thing.cs']));
$ev = array_map(fn($l) => json_decode($l, true), array_filter(explode("\n", file_get_contents($events))));
$fc = array_values(array_filter($ev, fn($e) => $e['type'] === 'file_changed'));
check('write_file: emitted one file_changed event with kind=create and seq/t', count($fc) === 1 && $fc[0]['kind'] === 'create' && $fc[0]['path'] === 'src/New/Thing.cs' && isset($fc[0]['seq'], $fc[0]['t']), json_encode($fc));

# edit_file must match once
$before = file_get_contents("$dir/src/Plugin.cs");
$out = $run('edit_file', ['path' => 'src/Plugin.cs', 'old' => 'line two', 'new' => 'LINE 2']);
check('edit_file: two matches -> refused, file untouched', strpos($out, 'matches 2 times') !== false && file_get_contents("$dir/src/Plugin.cs") === $before, $out);
$out = $run('edit_file', ['path' => 'src/Plugin.cs', 'old' => 'line nine', 'new' => 'x']);
check('edit_file: zero matches -> refused, file untouched', strpos($out, 'was not found') !== false && file_get_contents("$dir/src/Plugin.cs") === $before);
$out = $run('edit_file', ['path' => 'src/Plugin.cs', 'old' => "line one\n", 'new' => "LINE ONE\n"]);
check('edit_file: one match -> applied', strpos(file_get_contents("$dir/src/Plugin.cs"), "LINE ONE\nline two") === 0 && strpos($out, '@@ src/Plugin.cs line 1 @@') !== false, $out);
check('edit_file: hunk shows -old +new', strpos($out, "-line one") !== false && strpos($out, "+LINE ONE") !== false);
check('edit_file refused: ..', $refused($run('edit_file', ['path' => '../outside/secret.txt', 'old' => 'SECRET', 'new' => 'x'])) && file_get_contents("$outside/secret.txt") === 'SECRET');
check('edit_file: empty old refused', $run('edit_file', ['path' => 'src/Plugin.cs', 'old' => '', 'new' => 'x']) === 'old must not be empty.');

# delete_file
check('delete_file refused: manifest.json', $refused($run('delete_file', ['path' => 'manifest.json'])) && is_file("$dir/manifest.json"));
check('delete_file refused: ..', $refused($run('delete_file', ['path' => '../outside/secret.txt'])) && is_file("$outside/secret.txt"));
check('delete_file refused: symlink out', $refused($run('delete_file', ['path' => 'src/link.cs'])) && is_file("$outside/secret.txt"));
check('delete_file: ok', $run('delete_file', ['path' => 'src/New/Thing.cs']) === 'Deleted src/New/Thing.cs.' && !file_exists("$dir/src/New/Thing.cs"));

# search stays inside
check('search refused: path ..', $refused($run('search', ['pattern' => 'SECRET', 'path' => '../outside'])));
$out = $run('search', ['pattern' => 'SECRET']);
check('search: the symlinked-out file is not followed into (no SECRET hit)', strpos($out, 'SECRET') === false || strpos($out, 'link.cs') === false, $out);
check('search: finds project text', strpos($run('search', ['pattern' => 'line two']), 'src/Plugin.cs') !== false);

# list_files hides what it must
$out = $run('list_files', []);
check('list_files: no .git entries', strpos($out, '.git') === false);
check('list_files refused: ..', $refused($run('list_files', ['path' => '..'])));

# assets library has the same discipline
check('read_asset: ok', $run('read_asset', ['path' => 'sub/a.txt']) === 'asset');
check('read_asset refused: ..', $run('read_asset', ['path' => '../outside/secret.txt']) === 'No such asset.');
check('read_asset refused: absolute', $run('read_asset', ['path' => "$outside/secret.txt"]) === 'No such asset.');
check('copy_asset refused: destination ..', $refused($run('copy_asset', ['path' => 'sub/a.txt', 'to' => '../outside/a.txt'])) && !file_exists("$outside/a.txt"));

# knowledge topic names are constrained to the directory
check('knowledge refused: path-like topic', strpos($run('knowledge', ['topic' => '../../etc/passwd']), 'topic must be') === 0);
check('knowledge refused: unknown topic', strpos($run('knowledge', ['topic' => 'nope']), 'No knowledge topic') === 0);
check('knowledge: a shipped topic reads', strpos($run('knowledge', ['topic' => 'pitfalls']), '# Pitfalls') === 0);

# note appends a dated bullet
$run('note', ['text' => "remember   this\nthing"]);
check('note: dated bullet appended, whitespace folded', (bool)preg_match('/^- \d{4}-\d{2}-\d{2}: remember this thing$/m', file_get_contents("$dir/.vibes/notes.md")));

# result cap
file_put_contents("$dir/big.txt", str_repeat("x", 70000));
$out = $run('read_file', ['path' => 'big.txt']);
check('tool result capped at 60 KB with a marker', strlen($out) < 61000 && strpos($out, 'truncated at') !== false);

check('unknown tool', $run('nope', []) === "Unknown tool 'nope'.");

vibesTestDone();
