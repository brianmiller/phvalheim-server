#!/usr/bin/env php
<?php
/**
 * Oracle test: the migration-backup delete endpoint cannot be pointed outside its own folder.
 *
 * This is the one action in the config-migration feature that destroys data, and it takes a
 * FILENAME from the browser. The world tree it operates in also contains game/ -- which holds
 * the save files, where clearing the wrong directory has destroyed worlds on this project
 * before. So the containment check is not a nicety, and "I used basename()" is not a test.
 *
 * Run INSIDE the dev container, which has the real tree layout:
 *   docker cp dev_tools/test-config-migration-delete.php phvalheim-dev:/tmp/t.php
 *   docker exec phvalheim-dev php /tmp/t.php
 */

require_once '/opt/stateless/nginx/www/includes/phvalheim-frontend-config.php';
require_once '/opt/stateless/nginx/www/includes/modconfigs.php';

$pass = 0; $fail = 0;
function ok($m)      { echo "  PASS  $m\n"; $GLOBALS['pass']++; }
function bad($m, $d) { echo "  FAIL  $m\n        $d\n"; $GLOBALS['fail']++; }

// Pick a real world so worldIdByName() succeeds -- the function refuses an unknown world, and
// a test that fed it a fake name would exercise the refusal instead of the containment.
$world = $pdo->query("SELECT name FROM worlds LIMIT 1")->fetchColumn();
if (!$world) { echo "SKIPPED: the dev database has no worlds\n"; exit(0); }

$base   = MODCONFIG_WORLDS_ROOT . "/$world";
$parked = "$base/custom_configs/" . MODCONFIG_IMPORTED_DIR;
@mkdir($parked, 0775, true);

// Three fixtures: two legitimately inside the parked folder, one OUTSIDE it standing in for
// anything else on the tree -- the seed file, a save, the loader's config.
file_put_contents("$parked/keepme.cfg", "[A]\nx = 1\n");
file_put_contents("$parked/deleteme.cfg", "[A]\nx = 1\n");
$outside = "$base/custom_configs/DO-NOT-TOUCH.cfg";
file_put_contents($outside, "seed-ish\n");

// ---- 1. the ordinary case works ----
$r = modConfigDeleteMigrationBackups($pdo, $world, ['deleteme.cfg']);
if (!empty($r['ok']) && $r['deleted'] === 1 && !file_exists("$parked/deleteme.cfg")) {
    ok('a named file inside the parked folder is deleted');
} else {
    bad('a named file inside the parked folder is deleted', json_encode($r));
}

// ---- 2. THE ONE THAT MATTERS: traversal must not escape ----
// ../DO-NOT-TOUCH.cfg resolves outside the parked dir. basename() alone already defuses this
// one, which is exactly why the second case below exists too.
$r = modConfigDeleteMigrationBackups($pdo, $world, ['../DO-NOT-TOUCH.cfg']);
if (file_exists($outside)) {
    ok('a ../ traversal cannot delete a file outside the parked folder');
} else {
    bad('a ../ traversal cannot delete a file outside the parked folder',
        'DO-NOT-TOUCH.cfg was deleted — the endpoint can reach the rest of the world tree');
}

// ---- 3. an absolute path must not escape either ----
$r = modConfigDeleteMigrationBackups($pdo, $world, [$outside]);
if (file_exists($outside)) {
    ok('an absolute path cannot delete a file outside the parked folder');
} else {
    bad('an absolute path cannot delete a file outside the parked folder',
        'DO-NOT-TOUCH.cfg was deleted via an absolute path');
}

// ---- 4. a name that is not there is REPORTED, not silently counted as success ----
$r = modConfigDeleteMigrationBackups($pdo, $world, ['nosuchfile.cfg']);
$res = $r['results'][0] ?? [];
if (($r['deleted'] ?? -1) === 0 && empty($res['deleted']) && !empty($res['error'])) {
    ok('a missing file is reported as not deleted, with a reason');
} else {
    bad('a missing file is reported as not deleted, with a reason', json_encode($r));
}

// ---- 5. per-file outcomes, not just a count ----
file_put_contents("$parked/two.cfg", "[A]\nx = 1\n");
$r = modConfigDeleteMigrationBackups($pdo, $world, ['two.cfg', 'nosuchfile.cfg']);
if (count($r['results'] ?? []) === 2 && ($r['deleted'] ?? -1) === 1) {
    ok('a mixed batch returns one result per file, not a bare count');
} else {
    bad('a mixed batch returns one result per file, not a bare count', json_encode($r));
}

// ---- 6. the folder is tidied only once it is EMPTY ----
// keepme.cfg is still there, so the folder must survive. A cleanup that removed a folder the
// operator had deliberately kept a file in would be the same mistake one level up.
if (is_dir($parked) && file_exists("$parked/keepme.cfg")) {
    ok('the parked folder survives while a kept file is still in it');
} else {
    bad('the parked folder survives while a kept file is still in it',
        'the folder or the kept file is gone');
}

$r = modConfigDeleteMigrationBackups($pdo, $world, ['keepme.cfg']);
if (!is_dir($parked)) {
    ok('the parked folder is removed once the last file goes');
} else {
    bad('the parked folder is removed once the last file goes', 'the empty folder is still there');
}

// ---- 7. an empty selection is refused rather than treated as "delete all" ----
$r = modConfigDeleteMigrationBackups($pdo, $world, []);
if (!empty($r['error'])) {
    ok('an empty selection is refused, not read as "everything"');
} else {
    bad('an empty selection is refused, not read as "everything"', json_encode($r));
}

@unlink($outside);
echo "\n$pass passed, $fail failed\n";
exit($fail === 0 ? 0 : 1);
