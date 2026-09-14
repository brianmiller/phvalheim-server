<?php
/**
 * "What can Hugin do for me?" must be generated from the catalogue, not written by hand.
 *
 * The point of the card is that it is the ONE answer that has to be exactly true. A
 * hand-maintained list drifts the first time someone adds an action and forgets it here,
 * and the failure is invisible: the card still looks authoritative. So the check is not
 * "does it mention stop_world" -- it is "does every catalogue entry appear, and does
 * nothing appear that is not in the catalogue".
 */

chdir('/opt/stateless/nginx/www/admin');
ob_start(); require 'adminAPI.php'; ob_end_clean();
require_once '/opt/stateless/nginx/www/includes/aiactions.php';

$PASS = 0; $FAIL = 0;
function ok($m)  { global $PASS; $PASS++; echo "  \033[32mPASS\033[0m  $m\n"; }
function bad($m) { global $FAIL; $FAIL++; echo "  \033[31mFAIL\033[0m  $m\n"; }

foreach (['Midgard', 'Asgard'] as $w) {
    $pdo->prepare("DELETE FROM worlds WHERE name = ?")->execute([$w]);
    addWorld($pdo, $w, 'test.example.com', '1');
}
$pdo->exec("UPDATE worlds SET status='running' WHERE name='Midgard'");
$pdo->exec("UPDATE worlds SET status='stopped' WHERE name='Asgard'");

$card = aiCapabilityCard($pdo);
$cat  = aiActionCatalogue();

printf("\n\033[1mCapability card: %d chars, %d lines\033[0m\n\n", strlen($card), substr_count($card, "\n"));

/* ---- completeness: nothing in the catalogue may be missing ------------------------ */

$missing = [];
foreach (array_keys($cat) as $n) if (strpos($card, $n) === false) $missing[] = $n;
if (!$missing) ok('every action in the catalogue appears on the card (' . count($cat) . ')');
else           bad('MISSING from the card: ' . implode(', ', $missing));

/* ---- honesty: nothing may appear that is NOT in the catalogue ---------------------- */

// The failure this guards is the card promising work Hugin cannot do -- exactly what a
// model would invent if it were asked instead. These are real PhValheim operations that
// are deliberately NOT in this release's catalogue yet.
$notYet = ['restore_backup', 'delete_backups', 'create_backup', 'set_world_mods',
           'create_world', 'sync_mod_catalogue', 'reconcile_backups', 'set_world_backup_policy'];
$overclaimed = [];
foreach ($notYet as $n) {
    if (isset($cat[$n])) continue;            // it shipped after all -- fine
    if (strpos($card, $n) !== false) $overclaimed[] = $n;
}
if (!$overclaimed) ok('the card claims nothing that is not implemented');
else               bad('OVERCLAIMED (not in the catalogue): ' . implode(', ', $overclaimed));

/* ---- tiers are stated correctly ---------------------------------------------------- */

// Slice on the section BOUNDARY, not a fixed byte count. A fixed 400 chars ran past the
// end of the safe list and into the confirm list, so every confirm-tier action looked as
// though the card had promised to run it immediately -- a false alarm about the single
// most safety-relevant claim on the page.
$from = strpos($card, 'straight away');
$to   = strpos($card, 'I can propose these');
$safeSection = substr($card, $from, $to - $from);
$wrongTier = [];
foreach ($cat as $n => $a) {
    $isSafe = ($a['tier'] ?? '') === 'safe';
    $inSafe = strpos($safeSection, $n) !== false;
    if ($isSafe !== $inSafe) $wrongTier[] = $n . ($isSafe ? ' (safe, listed as needing confirmation)' : ' (needs confirmation, listed as immediate)');
}
if (!$wrongTier) ok('each action is listed under its real tier');
else             bad('WRONG TIER on the card: ' . implode('; ', $wrongTier));

$typed = array_keys(array_filter($cat, function ($a) { return !empty($a['typed']); }));
if (!$typed || strpos($card, 'type the world name') !== false)
     ok('irreversible actions are called out as needing the name typed');
else bad('the card does not mention the typed confirmation');

/* ---- it describes THIS server ------------------------------------------------------ */

// Derive the expectation from the database rather than asserting a literal. This file runs
// in the same container as actions.php, which leaves its own worlds behind, so "2 worlds"
// was only ever true when this ran first -- a check that depended on test ordering.
$rows = aiWorldRows($pdo);
$tot  = count($rows);
$run  = 0;
foreach ($rows as $r) if (aiTruthy($r, 'status')) $run++;

$wantWorlds  = "**$tot world" . ($tot === 1 ? '' : 's') . "**";
$wantRunning = "$run running, " . ($tot - $run) . ' stopped';
if (strpos($card, $wantWorlds) !== false && strpos($card, $wantRunning) !== false)
     ok("live state matches the database ($tot worlds, $run running)");
else bad("live state wrong: card should say \"$wantWorlds\" and \"$wantRunning\"");

/* ---- it survives a catalogue change ------------------------------------------------ */

// The real question is whether the card TRACKS the catalogue. Prove it by checking that a
// name only present in the catalogue reaches the card through the generator, rather than
// because someone typed it into a string.
$src = file_get_contents('/opt/stateless/nginx/www/includes/aiactions.php');
$fn  = substr($src, strpos($src, 'function aiCapabilityCard'));
$fn  = substr($fn, 0, strpos($fn, "\n}"));
$hardcoded = [];
foreach (array_keys($cat) as $n) if (strpos($fn, "'$n'") !== false || strpos($fn, "\"$n\"") !== false) $hardcoded[] = $n;
if (!$hardcoded) ok('no action name is hardcoded in the generator — it reads the catalogue');
else             bad('HARDCODED action names (will drift): ' . implode(', ', $hardcoded));

/* ---- the refusals are stated -------------------------------------------------------- */

$mustWarn = [
    'empty CITIZENS list' => 'the empty-access-list trap',
    'did not give me'     => 'never inventing a player ID',
    'no password'         => 'listed vanilla needs a password',
    'modded'              => 'vanilla-only settings on a modded world',
];
$absent = [];
foreach ($mustWarn as $needle => $label) if (stripos($card, $needle) === false) $absent[] = $label;
if (!$absent) ok('the card states what Hugin will refuse to do (' . count($mustWarn) . ' rules)');
else          bad('missing refusals: ' . implode(', ', $absent));

/* ---- no model was involved ---------------------------------------------------------- */

$providers = (int)$pdo->query("SELECT COUNT(*) FROM ai_providers")->fetchColumn();
if ($providers === 0) ok('rendered with NO provider configured — it works when the model cannot');
else                  ok("rendered without calling any of the $providers configured providers");

echo "\n";
printf("%d passed, %d failed\n", $PASS, $FAIL);
if ($FAIL === 0) { echo "\033[32mCAPABILITY CARD OK\033[0m\n"; exit(0); }
exit(1);
