<?php
# Shared loader for the Hugin's Vibes standalone oracles (dev_tools/test-vibes-*.php).
#
# The runner's lib files begin with `require_once '/opt/stateless/nginx/www/includes/vibes.php'`
# and include each other with `__DIR__ . '/x.php'` -- absolute in-image paths that do not exist
# on a dev box. The alternative to this loader is a COPY of each function inside the test, and a
# copy keeps passing after the shipped one changes, which is the only failure a test must catch.
# So the REAL source is loaded, with exactly those two path shapes rewritten to the repo checkout.
# Nothing else in the file is touched; a syntax error in the shipped file fails the test here.
#
# VIBES_ROOT (the data volume) is pointed at a throwaway directory so a test can create projects,
# assets and logs without touching /opt/stateful. Define it before including this file to choose.

$VIBES_REPO = dirname(__DIR__);
if (!defined('VIBES_ROOT')) define('VIBES_ROOT', sys_get_temp_dir() . '/vibes-test-' . getmypid());
if (!defined('VIBES_TOOLS')) define('VIBES_TOOLS', $VIBES_REPO . '/container/engine/tools/vibes');
@mkdir(VIBES_ROOT, 0775, true);

require_once $VIBES_REPO . '/container/nginx/www/includes/vibes.php';

function vibesTestLoad($lib) {
	global $VIBES_REPO;
	static $loaded = [];
	if (isset($loaded[$lib])) return;
	$loaded[$lib] = true;
	$file = "$VIBES_REPO/container/engine/tools/vibes/lib/$lib.php";
	if (!is_file($file)) { fwrite(STDERR, "missing lib $file\n"); exit(1); }
	$src = file_get_contents($file);
	// Pull in the libs this one requires, through the same loader, before evaluating it.
	if (preg_match_all("~require_once __DIR__ \. '/([a-z]+)\.php';~", $src, $m)) foreach ($m[1] as $dep) vibesTestLoad($dep);
	$src = preg_replace("~^require_once '/opt/stateless/nginx/www/includes/vibes\.php';\s*$~m", '', $src);
	$src = preg_replace("~^require_once '/opt/stateless/nginx/www/includes/aiproviders\.php';\s*$~m", "require_once '$VIBES_REPO/container/nginx/www/includes/aiproviders.php';", $src);
	$src = preg_replace("~^require_once __DIR__ \. '/[a-z]+\.php';\s*$~m", '', $src);
	$src = preg_replace('~^<\?php\s*~', '', $src);
	eval($src);
}

$pass = 0; $fail = 0;
function check($name, $ok, $detail = '') {
	global $pass, $fail;
	if ($ok) { $pass++; echo "  PASS  $name\n"; }
	else { $fail++; echo "  FAIL  $name" . ($detail !== '' ? " -- $detail" : '') . "\n"; }
}
function vibesTestDone() {
	global $pass, $fail;
	echo "\n$pass passed, $fail failed\n";
	if (is_dir(VIBES_ROOT) && strpos(VIBES_ROOT, sys_get_temp_dir()) === 0) shell_exec('rm -rf ' . escapeshellarg(VIBES_ROOT));
	exit($fail === 0 ? 0 : 1);
}
