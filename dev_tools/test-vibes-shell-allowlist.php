<?php
# Oracle: the Forge `shell` tool's allow-list (tools/vibes/lib/tools.php vibesShellCheck/Tokenize).
#
# The model writes the command line. Restricted mode must refuse everything shell would interpret
# (pipes, chaining, substitution, redirects), every binary off the list, the git/dotnet
# subcommands that reach the network, in-place sed, unzip that extracts, find that deletes, and
# any path that leaves the project. Each refusal is a sentence the model can act on.
#
# Usage: php dev_tools/test-vibes-shell-allowlist.php

require __DIR__ . '/vibes-test-lib.php';
vibesTestLoad('tools');

function allowed($cmd) { [$ok, $r] = vibesShellCheck($cmd, false); return $ok ? $r : false; }
function refused($cmd) { [$ok, $r] = vibesShellCheck($cmd, false); return $ok ? false : $r; }

# Tokenizer
check('tokenizer: quotes and escapes', vibesShellTokenize("grep -n 'a b' \"c d\" e\\ f") === ['grep', '-n', 'a b', 'c d', 'e f']);
check('tokenizer: unbalanced quote -> null', vibesShellTokenize("ls 'x") === null);
check('tokenizer: empty quoted arg kept', vibesShellTokenize("grep '' x") === ['grep', '', 'x']);

# Allowed
foreach (['ls -la src', 'cat src/Plugin.cs', 'head -n 20 README.md', 'tail -n 5 .vibes/notes.md', 'grep -rn Stamina src',
          'find . -name "*.cs"', 'wc -l src/Plugin.cs', 'sed -n 1,40p src/Plugin.cs', 'sort manifest.json', 'uniq x', 'diff a.cs b.cs',
          'unzip -l dist/Ns-Demo-0.1.0.zip', 'file icon.png', 'dotnet build -c Release', 'dotnet --version', 'git status', 'git log -3', 'git diff'] as $c) {
	check("allowed: $c", allowed($c) !== false, (string)refused($c));
}
$r = allowed("grep -rn 'Player.UseStamina' src");
check('allowed: argv is an array with the quoted pattern intact', is_array($r) && $r === ['grep', '-rn', 'Player.UseStamina', 'src']);
check('allowed: binary path is the bare name (resolved by the caller)', is_array($r) && $r[0] === 'grep');

# Refused: shell metacharacters
foreach (['ls | grep x', 'ls; rm -rf .', 'ls && cat x', 'echo $(whoami)', 'echo `id`', 'cat x > y', 'cat < x', "ls\nrm x", 'ls $HOME', 'ls {a,b}', 'ls (x)'] as $c) {
	check("refused metachar: " . str_replace("\n", '\n', $c), refused($c) !== false);
}
# Refused: binaries off the list
foreach (['rm -rf src', 'curl https://x', 'wget x', 'bash -c ls', 'sh', 'python3 x.py', 'php x.php', 'chmod 777 x', 'mv a b', 'cp a b', 'nc -l 80', '/bin/ls', '/usr/bin/env ls'] as $c) {
	$why = refused($c);
	check("refused binary: $c", $why !== false && strpos($why, 'not on the allowed list') !== false, (string)$why);
}
# Refused: dangerous forms of allowed binaries
check('refused: git push', strpos((string)refused('git push origin main'), 'not allowed') !== false);
check('refused: git fetch', refused('git fetch') !== false);
check('refused: git remote', refused('git remote add x https://e') !== false);
check('refused: git config', refused('git config user.name x') !== false);
check('refused: dotnet tool install', refused('dotnet tool install -g x') !== false);
check('refused: dotnet nuget push', refused('dotnet nuget push x') !== false);
check('refused: dotnet run', refused('dotnet run') !== false);
check('refused: sed without -n', refused('sed s/a/b/ x') !== false);
check('refused: sed -i', refused('sed -n -i s/a/b/ x') !== false);
check('refused: sed -ni (combined flag)', refused('sed -ni p x') !== false);
check('refused: unzip extracts', refused('unzip dist/x.zip') !== false);
check('refused: unzip -o', refused('unzip -o dist/x.zip -d y') !== false);
check('refused: find -delete', refused('find . -name "*.cs" -delete') !== false);
check('refused: find -exec', refused('find . -exec rm {} +') !== false || refused('find . -exec rm x') !== false);
# Refused: paths that leave the project
check('refused: absolute path outside the volume', strpos((string)refused('cat /etc/passwd'), 'outside') !== false);
check('refused: ..', refused('cat ../other/secret') !== false);
check('refused: ~', refused('ls ~/') !== false);
check('allowed: absolute path INSIDE the data volume (the libs manifest)', allowed('cat ' . VIBES_ROOT . '/libs/manifest.json') !== false, (string)refused('cat ' . VIBES_ROOT . '/libs/manifest.json'));
check('refused: empty', refused('   ') !== false);

# Unrestricted: operator opt-in; the string goes to bash -c as is
[$ok, $argv] = vibesShellCheck('ls | grep x', true);
check('unrestricted: pipes pass through bash -c', $ok && $argv === ['/bin/bash', '-c', 'ls | grep x']);

# The tool refuses through the same gate: forgeRunTool('shell') on a refused command never runs anything.
@mkdir(VIBES_ROOT . '/projects/demo', 0775, true);
$ctx = ['pdo' => null, 'doc' => vibesSettingsDefaults(), 'project' => ['slug' => 'demo'], 'slug' => 'demo', 'dir' => VIBES_ROOT . '/projects/demo', 'emit' => null, 'changed' => [], 'built' => false, 'lastBuild' => null, 'outBytes' => 0];
$out = forgeRunTool($ctx, 'shell', ['cmd' => 'cat /etc/passwd | head']);
check('forgeRunTool(shell): refused command returns the refusal sentence', strpos($out, 'Refused:') === 0, $out);
$out = forgeRunTool($ctx, 'shell', ['cmd' => 'ls']);
check('forgeRunTool(shell): an allowed command runs in the project dir (exit 0)', strpos($out, 'exit 0') === 0, substr($out, 0, 80));

vibesTestDone();
