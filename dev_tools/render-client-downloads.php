<?php
// Render the actual download menu with deterministic release discovery and no database.
// Used by both the regression test and the live release-delivery check.
$os = $argv[1] ?? 'Linux';
$version = $argv[2] ?? '2.0.17';
if (!in_array($os, ['Linux', 'Windows'], true) || !preg_match('/^\d+\.\d+\.\d+$/D', $version)) {
    fwrite(STDERR, "Usage: php render-client-downloads.php Linux|Windows x.y.z\n");
    exit(2);
}
$fixture = sys_get_temp_dir() . '/phv-download-render-' . bin2hex(random_bytes(8));
mkdir($fixture . '/includes', 0700, true);
mkdir($fixture . '/public', 0700);
register_shutdown_function(function () use ($fixture) {
    foreach (glob($fixture . '/includes/*') as $file) { unlink($file); }
    rmdir($fixture . '/includes');
    rmdir($fixture . '/public');
    rmdir($fixture);
});
copy(__DIR__ . '/../container/nginx/www/includes/clientDownloadButton.php', $fixture . '/includes/menu.php');
file_put_contents($fixture . '/includes/phvalheim-frontend-config.php', '<?php');
file_put_contents($fixture . '/includes/git.php', '<?php function getGitReleases($repo, $count) { return [$GLOBALS["fixtureVersion"]]; }');
$GLOBALS['fixtureVersion'] = $version;
chdir($fixture . '/public');
require $fixture . '/includes/menu.php';
populateDownloadMenu($os, 'https://github.com/brianmiller/phvalheim-client', 1);
