<?php
/**
 * Oracle tests for the 2.55 save summary (what modConfigSaveOverrides reports back).
 *
 * THE BUGS THESE CATCH
 *
 * 1. REPORTING THE NEW VALUE AS THE OLD ONE. The whole point of the summary is "was X, now Y",
 *    and `from` is the only field that requires reading the row BEFORE writing it. Nothing read
 *    the previous value before this change, so getting it wrong is the single easiest mistake
 *    here -- and a from/to table where both columns show the new value looks entirely plausible.
 *    T2 sets a known value, saves a different one, and asserts both ends.
 *
 * 2. GUESSING WHO NEEDS A CHANGE. On a real world 15 of 40 override rows have no mod_id, so
 *    "unknown" is the common case, not an edge case. T3 asserts all three classifications with
 *    each other as the control: a classifier that answered 'players' for everything, or
 *    'unknown' for everything, fails.
 *
 * 3. OFFERING A 573 MB REBUILD THAT CHANGES NOTHING. needsPush must be false when every change
 *    is server-side. T4 carries the positive case alongside it, because a needsPush that was
 *    hardcoded false would pass a test that only ever checked the server-only batch.
 *
 * 4. CLAIMING WORK THAT DID NOT HAPPEN. Resetting a setting that has no stored override removes
 *    nothing, and listing it as a change would have the modal report a change the operator did
 *    not make. T5.
 *
 * 5. SWALLOWING A REFUSAL. A locked row that is silently dropped leaves the operator believing
 *    a value took effect. T6 asserts the refusal still comes back now that the return shape
 *    has grown.
 *
 * Runs against the LIVE database inside the container, wrapped in a transaction that is always
 * rolled back -- so it exercises the real query, the real joins and the real collations without
 * altering a single stored override.
 *
 * Usage (inside the container, as the phvalheim user):
 *   php /opt/stateless/nginx/www/../../dev_tools/test-save-summary.php <world>
 * In practice it is copied in and run by dev_tools/run-save-summary-test.sh
 */

include '/opt/stateless/nginx/www/includes/config_env_puller.php';
include '/opt/stateless/nginx/www/includes/phvalheim-frontend-config.php';
include '/opt/stateless/nginx/www/includes/db_gets.php';
require_once '/opt/stateless/nginx/www/includes/modcatalog.php';
require_once '/opt/stateless/nginx/www/includes/modconfigs.php';

$world = $argv[1] ?? '';
if ($world === '') { fwrite(STDERR, "usage: test-save-summary.php <world>\n"); exit(2); }

$P = 0; $F = 0;
function ok($m)  { global $P; $P++; echo "  PASS  $m\n"; }
function no($m)  { global $F; $F++; echo "  FAIL  $m\n"; }
function is_($m, $want, $got) {
    if ($want === $got) { ok($m); }
    else { no("$m (want " . var_export($want, true) . ", got " . var_export($got, true) . ")"); }
}

$worldId = worldIdByName($pdo, $world);
if (!$worldId) { fwrite(STDERR, "no such world: $world\n"); exit(2); }

/* ---- find real candidates, so this tests the actual joins ------------------------------ */
// A mod this world deploys to players' clients.
$clientMod = $pdo->query(
    "SELECT wm.mod_id FROM world_mods wm WHERE wm.world_id = $worldId
       AND IFNULL(wm.deploy_client,1) = 1 LIMIT 1")->fetchColumn();
// A mod it does NOT deploy to clients, if any -- this is what makes 'server' distinguishable
// from 'players' without relying on server_only.
$serverOnlyMod = $pdo->query(
    "SELECT wm.mod_id FROM world_mods wm WHERE wm.world_id = $worldId
       AND IFNULL(wm.deploy_client,1) = 0 LIMIT 1")->fetchColumn();

echo "\n=== save summary oracles for '$world' ===\n";
echo "  (client-deployed mod id: " . var_export($clientMod, true)
   . ", client-excluded mod id: " . var_export($serverOnlyMod, true) . ")\n\n";

$pdo->beginTransaction();

try {
    $F_FILE = 'zz.SaveSummaryProbe.cfg';   // never a real mod; rolled back regardless

    /* ---- T1: the return carries the new reporting fields at all --------------------- */
    echo "T1  the save returns a per-change summary, not just counts\n";
    $r = modConfigSaveOverrides($pdo, $world, [
        ['file' => $F_FILE, 'section' => 'S', 'key' => 'Alpha', 'value' => 'one',
         'mod_id' => $clientMod ?: null],
    ]);
    is_('ok', true, $r['ok']);
    foreach (['changes', 'tally', 'needsPush', 'worldMode'] as $k) {
        isset($r[$k]) ? ok("returns '$k'") : no("missing '$k'");
    }
    is_('one change reported', 1, count($r['changes']));
    is_('saved count still correct', 1, $r['saved']);

    /* ---- T2: from is the OLD value, to is the NEW one ------------------------------- */
    echo "\nT2  'was' is the previous value, not the new one\n";
    $r2 = modConfigSaveOverrides($pdo, $world, [
        ['file' => $F_FILE, 'section' => 'S', 'key' => 'Alpha', 'value' => 'two',
         'mod_id' => $clientMod ?: null],
    ]);
    $c = $r2['changes'][0];
    is_("from == the value stored a moment ago", 'one', $c['from']);
    is_("to   == the value just saved",          'two', $c['to']);
    is_("action is 'changed' for an existing override", 'changed', $c['action']);

    // And the first write of a key must report from=null -- "was at the mod's default" is a
    // different statement from "was an empty string", and the modal renders them differently.
    $r3 = modConfigSaveOverrides($pdo, $world, [
        ['file' => $F_FILE, 'section' => 'S', 'key' => 'Fresh', 'value' => 'x',
         'mod_id' => $clientMod ?: null],
    ]);
    is_("a brand new override reports from = null", null, $r3['changes'][0]['from']);
    is_("...and action 'set'", 'set', $r3['changes'][0]['action']);

    /* ---- T3: reach, all three, each the control for the others --------------------- */
    echo "\nT3  reach: players / server / unknown\n";
    $batch = [
        ['file' => $F_FILE, 'section' => 'S', 'key' => 'ToPlayers', 'value' => 'v',
         'mod_id' => $clientMod ?: null],
        ['file' => $F_FILE, 'section' => 'S', 'key' => 'SecretKey', 'value' => 'v',
         'mod_id' => $clientMod ?: null, 'server_only' => 1],
        ['file' => $F_FILE, 'section' => 'S', 'key' => 'Orphan',   'value' => 'v'],  // no mod_id
    ];
    $r4 = modConfigSaveOverrides($pdo, $world, $batch);
    $byKey = [];
    foreach ($r4['changes'] as $ch) { $byKey[$ch['key']] = $ch; }

    if ($clientMod) {
        is_('a client-deployed mod reaches players', 'players', $byKey['ToPlayers']['reach']);
    } else {
        echo "  SKIP  this world deploys no mod to clients; cannot assert 'players'\n";
    }
    is_('server_only never reaches players', 'server', $byKey['SecretKey']['reach']);
    is_('an unattributed file is honestly unknown', 'unknown', $byKey['Orphan']['reach']);

    // The discrimination check: the three must not all be the same answer.
    $distinct = count(array_unique([
        $byKey['ToPlayers']['reach'], $byKey['SecretKey']['reach'], $byKey['Orphan']['reach']]));
    ($distinct >= ($clientMod ? 3 : 2))
        ? ok("the classifier produces $distinct distinct answers, so it discriminates")
        : no("every change got the same reach -- the classifier is not reading anything");

    // deploy_client=0 is a real branch of the classifier and this world happens to have no
    // such mod, so CREATE the condition rather than skipping it. Inside the transaction, so
    // the flip is rolled back with everything else -- but the classifier is still reading the
    // real column through the real join.
    //
    // A skip here would be the worst of both worlds: a branch that looks covered in the output
    // and is not. The reach map is built once per call, so this must run as its own save.
    if (!$serverOnlyMod && $clientMod) {
        $pdo->exec("UPDATE world_mods SET deploy_client = 0
                     WHERE world_id = $worldId AND mod_id = " . (int)$clientMod);
        $serverOnlyMod = $clientMod;
        echo "  (temporarily set mod $clientMod deploy_client=0 inside the transaction)\n";
    }
    if ($serverOnlyMod) {
        $r5 = modConfigSaveOverrides($pdo, $world, [
            ['file' => $F_FILE, 'section' => 'S', 'key' => 'NotOnClients', 'value' => 'v',
             'mod_id' => (int)$serverOnlyMod],
        ]);
        is_('a mod excluded from the client payload reads as server (deploy_client=0)',
            'server', $r5['changes'][0]['reach']);
        is_('...so that batch needs no push', false, $r5['needsPush']);
    } else {
        echo "  SKIP  this world has no mods at all; deploy_client=0 not exercised\n";
    }

    /* ---- T4: needsPush, both directions -------------------------------------------- */
    echo "\nT4  needsPush is true only when something must reach players\n";
    $serverOnlyBatch = modConfigSaveOverrides($pdo, $world, [
        ['file' => $F_FILE, 'section' => 'S', 'key' => 'OnlyServer1', 'value' => 'a',
         'mod_id' => $clientMod ?: null, 'server_only' => 1],
        ['file' => $F_FILE, 'section' => 'S', 'key' => 'OnlyServer2', 'value' => 'b',
         'mod_id' => $clientMod ?: null, 'server_only' => 1],
    ]);
    is_('an all-server batch needs no push', false, $serverOnlyBatch['needsPush']);
    is_('...and tallies 2 server-side', 2, $serverOnlyBatch['tally']['server']);
    is_('...and 0 for players', 0, $serverOnlyBatch['tally']['players']);

    // CONTROL: the positive case, in the same run. Without this, a hardcoded `false` passes.
    $pushBatch = modConfigSaveOverrides($pdo, $world, [
        ['file' => $F_FILE, 'section' => 'S', 'key' => 'NeedsPush', 'value' => 'c'],
    ]);
    is_('CONTROL: an unattributed change does need a push', true, $pushBatch['needsPush']);

    /* ---- T5: a reset that removes nothing is not a change -------------------------- */
    echo "\nT5  a no-op reset is not reported as a change\n";
    $noop = modConfigSaveOverrides($pdo, $world, [
        ['file' => $F_FILE, 'section' => 'S', 'key' => 'NeverExisted', 'reset' => 1],
    ]);
    is_('removed 0', 0, $noop['removed']);
    is_('and reports no changes', 0, count($noop['changes']));

    // A reset that DOES remove something must report from=<old> and to=null.
    $realReset = modConfigSaveOverrides($pdo, $world, [
        ['file' => $F_FILE, 'section' => 'S', 'key' => 'Alpha', 'reset' => 1],
    ]);
    is_('a real reset removes 1', 1, $realReset['removed']);
    is_('...reports the value it removed', 'two', $realReset['changes'][0]['from']);
    is_('...and to = null (back to the mod default)', null, $realReset['changes'][0]['to']);
    is_('...with action reset', 'reset', $realReset['changes'][0]['action']);

    /* ---- T6: refusals survive the new return shape --------------------------------- */
    echo "\nT6  refusals are still reported\n";
    $bad = modConfigSaveOverrides($pdo, $world, [
        ['file' => 'BepInEx.cfg', 'section' => 'S', 'key' => 'Enabled', 'value' => 'false'],
        ['file' => '../escape.cfg', 'section' => 'S', 'key' => 'K', 'value' => 'v'],
    ]);
    (count($bad['refused']) === 2)
        ? ok('the loader config and a path-escape are both refused')
        : no('expected 2 refusals, got ' . count($bad['refused']));
    is_('a refused item is not counted as a change', 0, count($bad['changes']));

    /* ---- T7: worldMode is the real mode -------------------------------------------- */
    echo "\nT7  worldMode reflects the world's actual state\n";
    $live = $pdo->query("SELECT mode FROM worlds WHERE id = $worldId")->fetchColumn();
    is_('worldMode matches worlds.mode', (string)$live, $r['worldMode']);

} finally {
    $pdo->rollBack();
}

/* ---- the rollback must have left nothing behind ------------------------------------- */
echo "\nT8  the probe left no rows behind\n";
$left = $pdo->query(
    "SELECT COUNT(*) FROM mod_config_overrides
      WHERE world_id = $worldId AND cfg_file = 'zz.SaveSummaryProbe.cfg'")->fetchColumn();
is_('no probe rows remain after rollback', 0, (int)$left);

echo "\n=== $P passed, $F failed ===\n";
exit($F === 0 ? 0 : 1);
