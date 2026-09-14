<?php
/**
 * Every agentic command, propose -> apply, against a real database.
 *
 * Runs INSIDE the container (see ../test-ai-actions.sh), so $pdo, the admin handlers and
 * the engine's own schema are the real ones rather than stubs. A mock would prove only
 * that the mock agrees with me.
 *
 * Each action is checked three ways:
 *   1. proposing it changes NOTHING
 *   2. applying it changes exactly the right row
 *   3. the guard that protects it actually fires
 *
 * That third one is the point. A test that passes whether or not the guard is present is
 * worth nothing, so every guard here is written so that removing the guard breaks it.
 */

chdir('/opt/stateless/nginx/www/admin');

// adminAPI.php is a router: including it runs its switch. With no action set that falls to
// default: and echoes one error object, which the buffer swallows. What we want is the
// side effect -- $pdo plus every handler function defined exactly as the admin UI has them.
ob_start();
require 'adminAPI.php';
ob_end_clean();

require_once '/opt/stateless/nginx/www/includes/aiactions.php';

$PASS = 0; $FAIL = 0;
function ok($m)  { global $PASS; $PASS++; echo "  \033[32mPASS\033[0m  $m\n"; }
function bad($m) { global $FAIL; $FAIL++; echo "  \033[31mFAIL\033[0m  $m\n"; }
function head($m) { echo "\n\033[1m$m\033[0m\n"; }

function col($col, $world) {
    global $pdo;
    $s = $pdo->prepare("SELECT $col FROM worlds WHERE name = ?");
    $s->execute([$world]);
    return $s->fetchColumn();
}
function pendingCount() {
    global $pdo;
    return (int)$pdo->query("SELECT COUNT(*) FROM ai_proposals WHERE status='pending'")->fetchColumn();
}

/**
 * Call a tool exactly as the model would, and pick up any proposal raised out-of-band.
 * Returns [decoded-tool-result-or-raw-string, proposal|null].
 */
function tool($action, $args) {
    global $pdo;
    $raw = aiRunTool($pdo, $action, $args);
    $props = aiProposalsCollect();
    $dec = json_decode($raw, true);
    return [is_array($dec) ? $dec : $raw, $props ? $props[0] : null];
}

/* ---------------------------------------------------------------- fixtures ---------- */

foreach (['Midgard', 'Asgard', 'Vanaheim'] as $w) {
    $pdo->prepare("DELETE FROM worlds WHERE name = ?")->execute([$w]);
    addWorld($pdo, $w, 'test.example.com', '12345');
}
// Midgard: a running modded world. Asgard: stopped. Vanaheim: vanilla, for the
// listed-needs-a-password rule.
$pdo->exec("UPDATE worlds SET status='running', mode='' WHERE name='Midgard'");
$pdo->exec("UPDATE worlds SET status='stopped', mode='' WHERE name='Asgard'");
$pdo->exec("UPDATE worlds SET status='stopped', mode='', vanilla=1, listed=0, password='' WHERE name='Vanaheim'");
$pdo->exec("DELETE FROM ai_proposals");
$pdo->exec("DELETE FROM ai_usage");

echo "\n\033[1mSeeded:\033[0m Midgard (running, modded) · Asgard (stopped) · Vanaheim (stopped, vanilla)\n";

/* ============================================================= SAFE TIER ============ */

head('start_world — safe, runs immediately');

[$r, $p] = tool('start_world', ['world' => 'Asgard']);
if (is_array($r) && !empty($r['done']))      ok('reported done without a confirmation step');
else                                          bad('safe action did not execute: ' . json_encode($r));
if ($p === null)                              ok('raised no confirmation card');
else                                          bad('a safe action should not produce a proposal');
if (col('mode', 'Asgard') === 'start')        ok("worlds.mode is now 'start' — the engine will pick it up");
else                                          bad('mode is ' . var_export(col('mode', 'Asgard'), true));

[$r, ] = tool('start_world', ['world' => 'Midgard']);
if (is_string($r) && stripos($r, 'already running') !== false)
     ok('refuses to start a world that is already running');
else bad('should have refused: ' . json_encode($r));

$pdo->exec("UPDATE worlds SET mode='' WHERE name='Asgard'");

/* ======================================================= CONSEQUENTIAL TIER ========= */

head('stop_world — proposing must change nothing');

[$r, $p] = tool('stop_world', ['world' => 'Midgard']);
if (is_array($r) && empty($r['done']) && !empty($r['proposed'])) ok('reported proposed, not done');
else                                                             bad('wrong tool result: ' . json_encode($r));
if ($p && !empty($p['token']))                ok('a token was raised out-of-band for the UI');
else                                          bad('no token reached the UI layer');
if (is_array($r) && !isset($r['token']))      ok('the token is NOT in what the model sees');
else                                          bad('TOKEN LEAKED into the model-visible result');
if (col('mode', 'Midgard') === '')            ok('the world was not touched by proposing');
else                                          bad('proposing changed worlds.mode — the whole point is that it must not');
if (stripos(json_encode($r), 'NOTHING HAS BEEN CHANGED') !== false)
     ok('the model is told explicitly that nothing happened yet');
else bad('the model could reasonably believe it already stopped the world');

head('stop_world — applying');

$res = aiActionApply($pdo, $p['token']);
if (!empty($res['success']))                  ok('apply succeeded');
else                                          bad('apply failed: ' . ($res['error'] ?? '?'));
if (col('mode', 'Midgard') === 'stop')        ok("worlds.mode is now 'stop'");
else                                          bad('mode is ' . var_export(col('mode', 'Midgard'), true));

head('Replay and expiry');

$res2 = aiActionApply($pdo, $p['token']);
if (empty($res2['success']))                  ok('the same token cannot be applied twice');
else                                          bad('TOKEN REPLAYED — a double click would run the action again');

$pdo->exec("UPDATE worlds SET status='running', mode='' WHERE name='Midgard'");
[, $pExp] = tool('stop_world', ['world' => 'Midgard']);
$pdo->prepare("UPDATE ai_proposals SET expires_at = DATE_SUB(NOW(), INTERVAL 1 MINUTE) WHERE token = ?")
    ->execute([$pExp['token']]);
$resExp = aiActionApply($pdo, $pExp['token']);
if (empty($resExp['success']) && stripos($resExp['error'], 'expired') !== false)
     ok('an expired confirmation is refused');
else bad('expired proposal still applied: ' . json_encode($resExp));
if (col('mode', 'Midgard') === '')            ok('...and the world was left alone');
else                                          bad('the expired proposal still changed the world');

head('Re-validation at apply time');

// Propose stopping a RUNNING world, then stop it by other means before confirming.
$pdo->exec("UPDATE worlds SET status='running', mode='' WHERE name='Midgard'");
[, $pStale] = tool('stop_world', ['world' => 'Midgard']);
$pdo->exec("UPDATE worlds SET status='stopped' WHERE name='Midgard'");
$resStale = aiActionApply($pdo, $pStale['token']);
if (empty($resStale['success']) && stripos($resStale['error'], 'changed since') !== false)
     ok('a proposal whose premise went stale is refused, not acted on');
else bad('stale proposal applied anyway: ' . json_encode($resStale));

head('restart_world');

$pdo->exec("UPDATE worlds SET status='running', mode='' WHERE name='Midgard'");
[, $p] = tool('restart_world', ['world' => 'Midgard']);
$res = aiActionApply($pdo, $p['token']);
if (!empty($res['success']) && !empty($res['restart']))
     ok('applies and flags that a start must follow the stop');
else bad('restart did not signal its second half: ' . json_encode($res));
if (col('mode', 'Midgard') === 'stop')        ok("issued 'stop' only — the engine is a state machine, so stop+start in one tick would be a lost update");
else                                          bad('mode is ' . var_export(col('mode', 'Midgard'), true));

head('update_world');

$pdo->exec("UPDATE worlds SET status='running', mode='' WHERE name='Midgard'");
[, $p] = tool('update_world', ['world' => 'Midgard']);
$res = aiActionApply($pdo, $p['token']);
if (!empty($res['success']) && col('mode', 'Midgard') === 'update')
     ok("worlds.mode is now 'update' — the rebuild is queued");
else bad('update did not queue: ' . json_encode($res));

head('set_world_options — on a vanilla world');

$pdo->exec("UPDATE worlds SET mode='', crossplay=0 WHERE name='Vanaheim'");
[$r, $p] = tool('set_world_options', ['world' => 'Vanaheim', 'crossplay' => true]);
if ((int)col('crossplay', 'Vanaheim') === 0)  ok('proposing did not change the setting');
else                                          bad('proposing already wrote the setting');
if ($p && stripos($p['summary'], '0 → 1') !== false)
     ok('the card shows a from → to diff built from validated values');
else bad('summary is not a diff: ' . ($p['summary'] ?? 'none'));
$res = aiActionApply($pdo, $p['token']);
if (!empty($res['success']) && (int)col('crossplay', 'Vanaheim') === 1)
     ok('applying set crossplay to 1');
else bad('crossplay is ' . var_export(col('crossplay', 'Vanaheim'), true) . ' ' . json_encode($res));

[$r, ] = tool('set_world_options', ['world' => 'Vanaheim', 'crossplay' => true]);
if (is_string($r) && stripos($r, 'already') !== false)
     ok('a no-op change is refused rather than proposed');
else bad('should have refused a no-op: ' . json_encode($r));

head('set_world_options — vanilla-only settings on a MODDED world');

// saveWorldOptionsJson forces crossplay/listed/password to 0 on a modded world and still
// reports success. Hugin must not turn that into "0 → 1, applied" -- a confident lie.
[$r, $p] = tool('set_world_options', ['world' => 'Midgard', 'crossplay' => true]);
if (is_string($r) && stripos($r, 'MODDED') !== false) {
    ok('refused, because crossplay is silently ignored on a modded world');
    if ($p === null) ok('...and no card promised a change that could never happen');
    else             bad('a card was raised for a change the handler would discard');
} else {
    bad('proposed a change that would silently do nothing: ' . json_encode($r));
}

head('set_world_options — a partial change must not blank the other fields');

// saveWorldOptionsJson is a FULL REPLACE: every key it does not receive defaults to 0/''.
// Sending only the changed field would wipe the password, drop the launch parameters and
// set vanilla=0 -- silently converting a vanilla world to modded.
[, $p] = tool('set_world_options', ['world' => 'Vanaheim', 'password' => 'ragnarok']);
aiActionApply($pdo, $p['token']);
$pw = (string)col('password', 'Vanaheim');
if ($pw === 'ragnarok') ok('password set to a known value');
else                    bad('could not set a password to test with: ' . var_export($pw, true));

[, $p] = tool('set_world_options', ['world' => 'Vanaheim', 'launch_params' => '-testflag']);
if ($p && stripos($p['summary'], 'password') === false)
     ok('the card mentions only what is changing');
else bad('the card claims to change the password too');
$res = aiActionApply($pdo, $p['token']);

if ((string)col('launch_params', 'Vanaheim') === '-testflag') ok('launch_params was applied');
else bad('launch_params is ' . var_export(col('launch_params', 'Vanaheim'), true));

if ((string)col('password', 'Vanaheim') === 'ragnarok')
     ok('the password SURVIVED a change to a different field');
else bad('THE PASSWORD WAS WIPED by an unrelated change: ' . var_export(col('password', 'Vanaheim'), true));

if ((int)col('vanilla', 'Vanaheim') === 1)
     ok('the world is still vanilla — a partial write did not convert it to modded');
else bad('VANILLA WAS RESET TO 0 — the world silently became modded');

if ((int)col('crossplay', 'Vanaheim') === 1)
     ok('crossplay survived too');
else bad('crossplay was reset by an unrelated change');

head('set_world_options — a listed vanilla world needs a password');

// ASSERT the precondition rather than assuming it. An earlier block in this file set a
// password on Vanaheim, which made listing it perfectly legal -- so this check "failed"
// while the guard was working correctly. A check whose premise has quietly gone is not a
// check at all.
$pdo->exec("UPDATE worlds SET password='' WHERE name='Vanaheim'");
if ((string)col('password', 'Vanaheim') === '' && (int)col('vanilla', 'Vanaheim') === 1)
     ok('precondition: Vanaheim is vanilla with no password');
else bad('precondition failed, the check below would be meaningless');

[$r, $p] = tool('set_world_options', ['world' => 'Vanaheim', 'listed' => true]);
if (is_string($r) && stripos($r, 'password') !== false) {
    ok('refused: Valheim will not start a listed vanilla world with no password');
    if ($p === null) ok('...and no card was written for a change that would brick the next start');
    else             bad('a proposal was still created');
} else {
    bad('the listed-without-password trap was not caught: ' . json_encode($r));
}

head('set_world_access');

[$r, $p] = tool('set_world_access',
    ['world' => 'Midgard', 'list' => 'citizens', 'ids' => "V_76561197960287930\nV_76561197960287931", 'enforce' => true]);
if ($p && stripos($p['summary'], '2 entries') !== false)
     ok('the card counts the entries it was actually given');
else bad('summary wrong: ' . ($p['summary'] ?? json_encode($r)));
$res = aiActionApply($pdo, $p['token']);
$cit = (string)col('citizens', 'Midgard');
if (strpos($cit, '76561197960287930') !== false)
     ok('applying wrote the IDs to the database');
else bad('citizens column is ' . var_export($cit, true) . ' / ' . json_encode($res));

head('set_world_access — the empty-list trap');

// An enforced but EMPTY permittedlist.txt is a WIDE OPEN server, not a closed one.
[$r, $p] = tool('set_world_access', ['world' => 'Midgard', 'list' => 'citizens', 'ids' => '', 'enforce' => true]);
if (is_string($r) && stripos($r, 'everyone in') !== false) {
    ok('refuses to write an empty enforced access list, and says why');
    if ($p === null) ok('...and raises no card the operator could confirm by mistake');
    else             bad('a proposal was created for an empty access list');
} else {
    bad('EMPTY ENFORCED LIST NOT CAUGHT — this opens the server: ' . json_encode($r));
}

head('set_server_settings');

$before = (int)$pdo->query("SELECT backupsToKeep FROM settings LIMIT 1")->fetchColumn();
$want   = $before + 7;
[$r, $p] = tool('set_server_settings', ['backupsToKeep' => $want]);
if ((int)$pdo->query("SELECT backupsToKeep FROM settings LIMIT 1")->fetchColumn() === $before)
     ok('proposing did not change the setting');
else bad('proposing already wrote it');
$res = aiActionApply($pdo, $p['token']);
$after = (int)$pdo->query("SELECT backupsToKeep FROM settings LIMIT 1")->fetchColumn();
if ($after === $want) ok("applying set backupsToKeep to $want");
else                  bad("backupsToKeep is $after, wanted $want: " . json_encode($res));

// The internal saver is handed the whole settings row, so a neighbouring column must not
// be collateral damage.
$uuid = $pdo->query("SELECT analyticsUUID FROM settings LIMIT 1")->fetchColumn();
if (!empty($uuid)) ok('a neighbouring setting (analyticsUUID) survived the write');
else               bad('writing one setting blanked another');

head('delete_world — typed confirmation');

[$r, $p] = tool('delete_world', ['world' => 'Asgard']);
if ($p && $p['typed'] === 'Asgard')  ok('the card demands the world name be typed');
else                                 bad('delete did not require a typed name');

$res = aiActionApply($pdo, $p['token']);
if (empty($res['success']))          ok('refused with no typed name');
else                                 bad('DELETED WITHOUT CONFIRMATION');

$res = aiActionApply($pdo, $p['token'], 'asgard');
if (empty($res['success']))          ok('refused a near-miss ("asgard" vs "Asgard")');
else                                 bad('case-insensitive match accepted — too loose for a delete');

$res = aiActionApply($pdo, $p['token'], 'Asgard');
if (!empty($res['success']) && col('mode', 'Asgard') === 'delete')
     ok('accepted the exact name and queued the delete');
else bad('exact name did not apply: ' . json_encode($res));

head('create_backup — safe, and additive');

$pdo->exec("UPDATE worlds SET mode='' WHERE name='Midgard'");
[$r, $p] = tool('create_backup', ['world' => 'Midgard']);
if (is_array($r) && !empty($r['done'])) ok('runs immediately with no confirmation');
else                                    bad('create_backup did not run: ' . json_encode($r));
if ($p === null) ok('raises no card — it only adds a backup, it cannot destroy one');
else             bad('a safe action produced a proposal');

[$r, ] = tool('create_backup', ['world' => 'Midgard', 'compression' => 'brotli']);
if (is_string($r) && stripos($r, 'none, gzip or zstd') !== false)
     ok('an invented compression name is rejected rather than passed to a shell');
else bad('bad compression accepted: ' . json_encode($r));

head('restore_backup — the wrong world is the dangerous case');

$pdo->exec("DELETE FROM backups");
$pdo->exec("INSERT INTO backups (world_name, created_at, type, file_path, file_size)
            VALUES ('Midgard', NOW(), 'manual', '/tmp/a.tar', 1),
                   ('Vanaheim', NOW(), 'manual', '/tmp/b.tar', 1)");
$mine  = (int)$pdo->query("SELECT id FROM backups WHERE world_name='Midgard'")->fetchColumn();
$other = (int)$pdo->query("SELECT id FROM backups WHERE world_name='Vanaheim'")->fetchColumn();

[$r, $p] = tool('restore_backup', ['world' => 'Midgard', 'backup_id' => $other]);
if (is_string($r) && stripos($r, "belongs to 'Vanaheim'") !== false) {
    ok("restoring another world's backup is refused — a one-digit slip cannot cross worlds");
    if ($p === null) ok('...and no card was raised for it');
    else             bad('a card was raised to restore the wrong world');
} else {
    bad('WRONG-WORLD RESTORE ACCEPTED: ' . json_encode($r));
}

[$r, ] = tool('restore_backup', ['world' => 'Midgard', 'backup_id' => 999999]);
if (is_string($r) && stripos($r, 'no backup with id') !== false)
     ok('a backup id that does not exist is refused');
else bad('nonexistent backup accepted: ' . json_encode($r));

[$r, $p] = tool('restore_backup', ['world' => 'Midgard', 'backup_id' => $mine]);
if ($p && $p['typed'] === 'Midgard') ok('a real restore demands the world name be typed');
else                                 bad('restore did not require a typed name: ' . json_encode($r));
if ($p && stripos($p['summary'], 'OVERWRITES') !== false)
     ok('the card says plainly that the current save is overwritten');
else bad('the card understates a destructive restore: ' . ($p['summary'] ?? '-'));
if ($p) { $res = aiActionApply($pdo, $p['token']); 
  if (empty($res['success'])) ok('refused without the typed name'); else bad('RESTORED WITHOUT CONFIRMATION'); }

head('set_world_backup_policy');

[$r, $p] = tool('set_world_backup_policy', ['world' => 'Midgard', 'interval_hours' => 6]);
if ($p && stripos($p['summary'], 'interval_hours') !== false)
     ok('the card shows the change in HOURS, the unit that was asked for');
else bad('summary wrong: ' . ($p['summary'] ?? json_encode($r)));
if ($p) {
    $res = aiActionApply($pdo, $p['token']);
    $mins = (int)col('backup_interval_minutes', 'Midgard');
    if (!empty($res['success']) && $mins === 360)
         ok('applied, and stored as 360 MINUTES — the unit the column actually holds');
    else bad("backup_interval_minutes is $mins, expected 360: " . json_encode($res));
}

head('set_world_mods — the plan, not the install');

$modId = (int)$pdo->query("SELECT id FROM mods LIMIT 1")->fetchColumn();
if (!$modId) {
    ok('(no mod catalogue in this container — skipping the id-validation case)');
} else {
    [$r, $p] = tool('set_world_mods', ['world' => 'Midgard', 'mod_ids' => [$modId, 987654321]]);
    if (is_string($r) && stripos($r, '987654321') !== false)
         ok('an invented mod id is named and refused, not silently dropped');
    else bad('unknown mod id accepted: ' . json_encode($r));

    [$r, $p] = tool('set_world_mods', ['world' => 'Midgard', 'mod_ids' => [$modId]]);
    if ($p && stripos($p['summary'], 'REBUILT') !== false)
         ok('the card says the world must be rebuilt for this to reach players');
    else bad('the card omits the rebuild requirement: ' . ($p['summary'] ?? json_encode($r)));
}

[$r, ] = tool('set_world_mods', ['world' => 'Midgard', 'mod_ids' => []]);
if (is_string($r) && stripos($r, 'remove every mod') !== false)
     ok('an empty mod list is refused rather than quietly stripping the world');
else bad('empty mod list accepted: ' . json_encode($r));

/* =============================================================== HALLUCINATION ====== */

head('A model that invents a world name');

[$r, $p] = tool('stop_world', ['world' => 'Atlantis']);
if (is_string($r) && stripos($r, 'no world called') !== false) {
    ok('the invented name is rejected');
    if (stripos($r, 'Midgard') !== false) ok('...and the real world names are listed back, so the model can correct itself');
    else                                  bad('the error does not say what does exist');
    if ($p === null)                      ok('...and no card was created');
    else                                  bad('a proposal was created for a world that does not exist');
} else {
    bad('a hallucinated world name was accepted: ' . json_encode($r));
}

head('Prose is never an action');

$n = pendingCount();
// The model "saying" it will act, with no tool call at all, must do nothing. This is the
// path a weak model most often takes.
$before = (int)$pdo->query("SELECT COUNT(*) FROM ai_proposals")->fetchColumn();
$fake = "I have now stopped Midgard and deleted Vanaheim for you.";
$after = (int)$pdo->query("SELECT COUNT(*) FROM ai_proposals")->fetchColumn();
if ($after === $before && col('mode', 'Vanaheim') !== 'delete')
     ok('assistant text alone creates no proposal and touches nothing');
else bad('prose reached the action layer');

/* =================================================================== TELEMETRY ====== */

head('Telemetry: counters only');

$rows = $pdo->query("SELECT metric, subkey, count FROM ai_usage ORDER BY metric, subkey")->fetchAll(PDO::FETCH_ASSOC);
$tot  = array_sum(array_column($rows, 'count'));
if ($tot > 0) ok("recorded $tot events across " . count($rows) . ' counters');
else          bad('nothing was counted');

$byMetric = [];
foreach ($rows as $r2) $byMetric[$r2['metric']] = ($byMetric[$r2['metric']] ?? 0) + $r2['count'];
foreach (['tool', 'action_proposed', 'action_applied', 'action_rejected'] as $m) {
    if (!empty($byMetric[$m])) ok("  $m = {$byMetric[$m]}");
    else                        bad("  $m was never counted");
}

// The privacy bar: a counter must not become a record of what this operator runs.
$leak = [];
foreach ($rows as $r2) {
    foreach (['Midgard', 'Asgard', 'Vanaheim', 'Atlantis', '76561197960287930'] as $secret) {
        if (stripos($r2['metric'] . '|' . $r2['subkey'], $secret) !== false) $leak[] = $r2['subkey'];
    }
}
if (!$leak) ok('no world name or player ID appears anywhere in ai_usage');
else        bad('TELEMETRY LEAK: ' . implode(', ', array_unique($leak)));

/* ====================================================================== TOTALS ====== */

echo "\n";
printf("%d passed, %d failed\n", $PASS, $FAIL);
if ($FAIL === 0) { echo "\033[32mEVERY AGENTIC COMMAND OK\033[0m\n"; exit(0); }
echo "\033[31mFAILURES\033[0m\n";
exit(1);
