<?php
/**
 * Oracle test: matching a config file to a mod by hand, against the real database.
 *
 * requires: container
 *
 * Run INSIDE a container built from this tree (real layout, real MariaDB):
 *   docker cp dev_tools/test-config-manual-match-live.php <c>:/tmp/t.php
 *   docker exec <c> php /tmp/t.php
 *
 * WHY: asked for on Discord (2026-10-19) -- "we detected an orphaned config file, does it match
 * one of your mods?" -- after a ValheimPlus fork's 471 settings sat under "Unmatched config
 * files". dev_tools/test-config-manual-match.sh pins the ownership RULE in isolation; this pins
 * what the editor's two actions do to the rows that rule reads, and what the editor then shows.
 *
 * The cases that matter most are the ones that could lose information quietly:
 *   - re-matching moves the claim; it must not leave the old mod a second, competing claim
 *   - undo on a row the DLL ALSO declares must keep the learned fact (only `manual` clears)
 *   - a mod not installed on this world, a path, or the loader's own file is refused
 *
 * Creates its own scratch world and cleans up after itself, pass or fail.
 */

require '/opt/stateless/nginx/www/includes/config_env_puller.php';
require '/opt/stateless/nginx/www/includes/phvalheim-frontend-config.php';
require_once '/opt/stateless/nginx/www/includes/db_gets.php';
require_once '/opt/stateless/nginx/www/includes/modconfigs.php';

$pass = 0; $fail = 0;
function check($name, $ok, $detail = '') {
    global $pass, $fail;
    if ($ok) { $pass++; echo "  PASS  $name\n"; }
    else { $fail++; echo "  FAIL  $name" . ($detail !== '' ? " -- $detail" : '') . "\n"; }
}
function one($pdo, $sql, $args = []) { $s = $pdo->prepare($sql); $s->execute($args); return $s->fetchColumn(); }
function guidRow($pdo, $modId, $guid) {
    $s = $pdo->prepare("SELECT learned, manual FROM mod_plugin_guids WHERE mod_id = ? AND guid = ?");
    $s->execute([$modId, $guid]);
    $r = $s->fetch(PDO::FETCH_ASSOC);
    return $r ? ((int)$r['learned'] . '/' . (int)$r['manual']) : 'none';
}
function fileEntry($pdo, $world, $file) {
    $p = modConfigEditorPayload($pdo, $world);
    foreach (($p['files'] ?? []) as $f) { if ($f['file'] === $file) { return [$f, $p]; } }
    return [null, $p];
}

$WORLD = 'zmanualmatch';
$FILE  = 'valheim_plus.cfg';                 // named by the mod, not after any GUID
$GUID  = 'valheim_plus';
$dir   = "/opt/stateful/games/valheim/worlds/$WORLD/game/BepInEx/config";

// Two real catalogue mods for the world, one more that it does NOT have.
$ids = $pdo->query("SELECT id FROM mods ORDER BY id LIMIT 3")->fetchAll(PDO::FETCH_COLUMN);
if (count($ids) < 3) { echo "need at least 3 catalogue mods (is the mod sync done?)\n"; exit(1); }
[$A, $B, $OTHER] = array_map('intval', $ids);

$cleanup = function () use ($pdo, $WORLD, $GUID, $dir) {
    $wid = one($pdo, "SELECT id FROM worlds WHERE name = ?", [$WORLD]);
    if ($wid) {
        $pdo->prepare("DELETE FROM mod_config_overrides WHERE world_id = ?")->execute([$wid]);
        $pdo->prepare("DELETE FROM world_mods WHERE world_id = ?")->execute([$wid]);
        $pdo->prepare("DELETE FROM worlds WHERE id = ?")->execute([$wid]);
    }
    $pdo->prepare("DELETE FROM mod_plugin_guids WHERE guid IN (?, 'mod_v2.0')")->execute([$GUID]);
    @unlink("$dir/valheim_plus.cfg"); @unlink("$dir/mod_v2.0.cfg");
    @rmdir($dir); @rmdir(dirname($dir)); @rmdir(dirname($dir, 2)); @rmdir(dirname($dir, 3));
};
$cleanup();
register_shutdown_function($cleanup);

$pdo->prepare("INSERT INTO worlds (name, mode) VALUES (?, 'stopped')")->execute([$WORLD]);
$W = (int)one($pdo, "SELECT id FROM worlds WHERE name = ?", [$WORLD]);
foreach ([$A, $B] as $m) {
    $pdo->prepare("INSERT INTO world_mods (world_id, mod_id, is_dep, date_added, deploy_server, deploy_client)
                   VALUES (?, ?, 0, NOW(), 1, 1)")->execute([$W, $m]);
}
@mkdir($dir, 0775, true);
file_put_contents("$dir/$FILE", "[AdvancedBuildingMode]\n\n# Setting type: Boolean\n# Default value: false\nenabled = false\n");
$pdo->prepare("INSERT INTO mod_config_overrides (world_id, cfg_file, section, ckey, cvalue, mod_id, server_only, locked, origin, date_set)
               VALUES (?, ?, 'AdvancedBuildingMode', 'enabled', 'true', NULL, 0, 0, 'editor', NOW())")->execute([$W, $FILE]);
$stored = function () use ($pdo, $W, $FILE) {
    $v = one($pdo, "SELECT mod_id FROM mod_config_overrides WHERE world_id = ? AND cfg_file = ?", [$W, $FILE]);
    return $v === null ? 'NULL' : (string)$v;
};

echo "\nBefore any match\n";
[$f, $p] = fileEntry($pdo, $WORLD, $FILE);
check('the file is unmatched', $f !== null && $f['mod_id'] === null, json_encode($f['mod_id'] ?? 'missing'));
$cand = array_map(function ($c) { return $c['id']; }, $p['candidates'] ?? []);
check('candidates are exactly this world\'s mods', $cand == [$A, $B] || $cand == [$B, $A], json_encode($cand));

echo "\nMatch to A\n";
$r = modConfigMatchFile($pdo, $WORLD, $FILE, $A);
check('match succeeds', !empty($r['ok']), json_encode($r));
check('a MANUAL-only row for A (learned=0, manual=1)', guidRow($pdo, $A, $GUID) === '0/1', guidRow($pdo, $A, $GUID));
check('the saved setting moved to A', $stored() === (string)$A, $stored());
[$f] = fileEntry($pdo, $WORLD, $FILE);
check('the editor files it under A, marked matched by you',
    $f && $f['mod_id'] === $A && !empty($f['matched_manually']), json_encode([$f['mod_id'] ?? null, $f['matched_manually'] ?? null]));

echo "\nRe-match to B\n";
$r = modConfigMatchFile($pdo, $WORLD, $FILE, $B);
check('re-match succeeds', !empty($r['ok']), json_encode($r));
check('A\'s claim is gone entirely, not left competing', guidRow($pdo, $A, $GUID) === 'none', guidRow($pdo, $A, $GUID));
check('B now holds the only manual claim', guidRow($pdo, $B, $GUID) === '0/1', guidRow($pdo, $B, $GUID));
check('the saved setting followed to B', $stored() === (string)$B, $stored());

echo "\nRefusals\n";
$r = modConfigMatchFile($pdo, $WORLD, $FILE, $OTHER);
check('a mod not installed on this world is refused', empty($r['ok']), json_encode($r));
check('  and the claim is unchanged', guidRow($pdo, $B, $GUID) === '0/1' && guidRow($pdo, $OTHER, $GUID) === 'none');
foreach (['../escape.cfg', 'sub/dir.cfg', 'a\\b.cfg', 'BepInEx.cfg', 'notacfg.txt', '.cfg'] as $bad) {
    $r = modConfigMatchFile($pdo, $WORLD, $bad, $A);
    check("refuses '" . addcslashes($bad, "\\") . "'", empty($r['ok']), json_encode($r));
}
// The regex excludes backslash and NUL -- not the digit 0 or the letter x, which a mis-escaped
// pattern in the first cut of this code did.
file_put_contents("$dir/mod_v2.0.cfg", "[S]\nk = v\n");
$r = modConfigMatchFile($pdo, $WORLD, 'mod_v2.0.cfg', $A);
check("accepts a name containing 0 and x ('mod_v2.0.cfg')", !empty($r['ok']), json_encode($r));
@unlink("$dir/mod_v2.0.cfg");
$r = modConfigMatchFile($pdo, $WORLD, 'nosuchfile.cfg', $A);
check('refuses a file this world does not have', empty($r['ok']), json_encode($r));

echo "\nUndo\n";
$r = modConfigUnmatchFile($pdo, $WORLD, $FILE);
check('undo succeeds', !empty($r['ok']), json_encode($r));
check('the manual-only row is deleted', guidRow($pdo, $B, $GUID) === 'none', guidRow($pdo, $B, $GUID));
check('the saved setting is unowned again', $stored() === 'NULL', $stored());
[$f] = fileEntry($pdo, $WORLD, $FILE);
check('the editor shows it unmatched again', $f && $f['mod_id'] === null, json_encode($f['mod_id'] ?? 'missing'));
$r = modConfigUnmatchFile($pdo, $WORLD, $FILE);
check('a second undo is refused, not a silent no-op', empty($r['ok']), json_encode($r));

echo "\nUndo keeps a LEARNED fact\n";
// B's DLL also declares this GUID -- yet two learned claims (A and B) tie, so it was unmatched
// until the operator chose. Undo must take back the choice, not the DLL's declaration.
$pdo->prepare("INSERT INTO mod_plugin_guids (mod_id, guid, plugin_name, learned, manual) VALUES (?, ?, '', 1, 0), (?, ?, '', 1, 0)")
    ->execute([$A, $GUID, $B, $GUID]);
[$f] = fileEntry($pdo, $WORLD, $FILE);
check('two learned claims tie: unmatched', $f && $f['mod_id'] === null, json_encode($f['mod_id'] ?? 'missing'));
modConfigMatchFile($pdo, $WORLD, $FILE, $B);
check('matching B flags the existing learned row manual too (1/1)', guidRow($pdo, $B, $GUID) === '1/1', guidRow($pdo, $B, $GUID));
check('  A\'s learned row is untouched (1/0)', guidRow($pdo, $A, $GUID) === '1/0', guidRow($pdo, $A, $GUID));
[$f] = fileEntry($pdo, $WORLD, $FILE);
check('  the manual claim breaks the tie in the editor', $f && $f['mod_id'] === $B, json_encode($f['mod_id'] ?? 'missing'));
modConfigUnmatchFile($pdo, $WORLD, $FILE);
check('undo leaves B\'s learned row standing (1/0)', guidRow($pdo, $B, $GUID) === '1/0', guidRow($pdo, $B, $GUID));
[$f] = fileEntry($pdo, $WORLD, $FILE);
check('  and the tie is back: unmatched', $f && $f['mod_id'] === null, json_encode($f['mod_id'] ?? 'missing'));

echo "\n$pass passed, $fail failed\n";
exit($fail === 0 ? 0 : 1);
