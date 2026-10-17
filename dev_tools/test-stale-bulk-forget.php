<?php
/*
 * Oracle test: the bulk "forget" control never selects a setting that is still being applied.
 *
 * THE HAZARD. The unmatched-settings panel lists two kinds of row that read almost identically
 * and have opposite consequences:
 *
 *   "this version of the mod does not use this setting"  -- dead. Can never apply again.
 *   "the mod has not written this config yet"            -- STILL APPLIED. It will match again
 *                                                           the next time that mod loads.
 *
 * Until now the only thing separating them was that English sentence, and every row had a
 * delete button beside it. Adding a bulk control to that panel is adding a single click that
 * can destroy an arbitrary number of live settings, and the saved value is the only copy --
 * `reset: true` deletes the row outright. A dozen overrides are already suspected lost to the
 * unconfirmed single-row version of this button.
 *
 * So the selection must key on a FLAG computed where the difference is known, never on the
 * prose. Part 1 EXECUTES the real classification block with stubbed inputs rather than
 * grepping it, because the thing that matters is the polarity of that flag and a grep cannot
 * see polarity -- `'safe_to_forget' => false` and `=> true` differ by four characters and a
 * source assertion that merely finds the key present would pass on either.
 *
 * Usage:  php dev_tools/test-stale-bulk-forget.php
 */

$repo = dirname(__DIR__);
$MC   = "$repo/container/nginx/www/includes/modconfigs.php";
$WC   = "$repo/container/nginx/www/admin/world_configs.php";

$pass = 0; $fail = 0;
function pass($m) { global $pass; echo "  PASS: $m\n"; $pass++; }
function fail($m, $d = '') { global $fail; echo "  FAIL: $m\n"; if ($d) echo "        $d\n"; $fail++; }

echo "\n== part 1: the flag's POLARITY, by running the real classification ==\n";

// Extract the real block: from `$stale = [];` up to (not including) the `return [`.
$src = file_get_contents($MC);
$a = strpos($src, '$stale = [];');
$b = strpos($src, 'return [', $a);
if ($a === false || $b === false) {
    fail('could not extract the classification block from modconfigs.php',
         'extraction is stale; every assertion below would be vacuous');
    echo "\n$pass passed, $fail failed\n";
    exit(1);
}
$block = substr($src, $a, $b - $a);

if (strpos($block, 'safe_to_forget') === false) {
    fail('the extracted block does not mention safe_to_forget',
         'the flag is not being set where the classification happens');
    echo "\n$pass passed, $fail failed\n";
    exit(1);
}
pass('extracted the real classification block (' . strlen($block) . ' bytes)');

/*
 * Drive it. $localFor is the closure the real code calls to find a self-installed plugin that
 * reads a file; stubbing it is how the local_dll downgrade gets exercised.
 */
// Parameter names must match the names the REAL block references -- it reads
// $undocumentedOverrides, and calling the parameter $undocumented left that undefined, so
// both "mod dropped the setting" cases produced 0 rows and read as failures of the code
// rather than of this harness.
function classify(array $overrides, array $seenKeys, array $undocumentedOverrides,
                  array $fileDocumented, array $dllByFile) {
    global $block;
    $localFor = function ($f) use ($dllByFile) { return $dllByFile[$f] ?? null; };
    $stale = null;
    eval($block);          // defines $stale from the REAL source
    return $stale;
}

// Case A: the mod has not written its config yet -> NOT safe. This is the dangerous row.
$r = classify(
    ['k1' => ['cfg_file' => 'a.cfg', 'section' => 'S', 'ckey' => 'K', 'cvalue' => 'v']],
    [],                 // k1 unseen -> "not written yet"
    [], [], []
);
if (count($r) === 1) {
    pass('a "not written yet" row is produced');
    $r[0]['safe_to_forget'] === false
        ? pass('"the mod has not written this config yet" -> safe_to_forget FALSE')
        : fail('a still-applied setting is flagged SAFE TO FORGET',
               'the bulk control would tick it and destroy a live override');
    strpos($r[0]['reason'], 'has not written') !== false
        ? pass('and it carries the matching reason text')
        : fail('reason text does not match the branch', $r[0]['reason']);
} else {
    fail('expected 1 stale row, got ' . count($r));
}

// Case B: the mod documented its file and dropped the key -> safe.
$r = classify(
    [], [],
    ['b.cfg' => [['cfg_file' => 'b.cfg', 'section' => 'S', 'ckey' => 'Gone', 'cvalue' => 'v']]],
    ['b.cfg' => true],  // documented -> the key really is absent
    []
);
if (count($r) === 1) {
    pass('a "mod dropped the setting" row is produced');
    $r[0]['safe_to_forget'] === true
        ? pass('"this version of the mod does not use this setting" -> safe_to_forget TRUE')
        : fail('a dead setting is NOT flagged safe', 'the easy button would never select anything');
} else {
    fail('expected 1 stale row, got ' . count($r));
}

// Case C: THE DOWNGRADE. Same as B, but one of the operator's own plugins reads that file.
// The panel's claim ("no mod uses this any more") is false for it, so the flag must agree
// with the warning the row already prints.
$r = classify(
    [], [],
    ['c.cfg' => [['cfg_file' => 'c.cfg', 'section' => 'S', 'ckey' => 'K', 'cvalue' => 'v']]],
    ['c.cfg' => true],
    ['c.cfg' => 'MyPlugin.dll']
);
if (count($r) === 1) {
    $r[0]['safe_to_forget'] === false
        ? pass('a local_dll downgrades safe -> NOT safe (the row warns; the flag agrees)')
        : fail('a file read by a self-installed plugin is flagged safe',
               'the row says "Do not forget it" and the checkbox would be ticked anyway');
    $r[0]['local_dll'] === 'MyPlugin.dll'
        ? pass('and the dll is reported on the row')
        : fail('local_dll not carried through');
} else {
    fail('expected 1 stale row, got ' . count($r));
}

// Case D: an UNDOCUMENTED file is not stale at all -- a file just created has no metadata on
// anything, so every freshly-applied setting would otherwise be reported as broken.
$r = classify([], [],
    ['d.cfg' => [['cfg_file' => 'd.cfg', 'section' => 'S', 'ckey' => 'K', 'cvalue' => 'v']]],
    ['d.cfg' => false], []);
count($r) === 0
    ? pass('an override in an UNDOCUMENTED file is not listed as stale at all')
    : fail('a freshly-created config produced ' . count($r) . ' stale row(s)',
           'every applied setting on a new file would be offered for deletion');

echo "\n== part 2: the UI ticks safe rows only ==\n";

$ui = file_get_contents($WC);

/*
 * `checked` must be conditional on the per-row flag, not unconditional.
 *
 * Captured with a fixed-length substr, NOT a non-greedy match to the first ">". The input tag
 * contains PHP echo expressions, and a PHP close tag ends with ">" -- so the lazy match
 * stopped there and truncated the capture before `checked`, reporting a correctly-gated
 * checkbox as ungated. A ">" inside the thing you are matching is not a tag boundary.
 *
 * And this is a BLOCK comment on purpose: a PHP close tag written literally inside a `//`
 * comment ENDS PHP MODE, so the first draft of this explanation broke the file it was
 * explaining. Same shape as a "--" inside an XML comment.
 */
$cbStart = strpos($ui, 'class="stale-pick"');
$cb = $cbStart === false ? '' : substr($ui, $cbStart, 900);
$cb !== '' ? pass('the per-row checkbox exists') : fail('no .stale-pick checkbox rendered');
strpos($cb, 'data-safe=') !== false
    ? pass('each box carries data-safe from the server flag')
    : fail('the checkbox does not expose the safe flag to JS');
strpos($cb, "\$sSafe ? 'checked' : ''") !== false
    ? pass('it is pre-ticked ONLY when $sSafe')
    : fail('checkbox default is not gated on $sSafe', 'unsafe rows may start ticked');

// THE REGRESSION ASSERTION: no control may tick every row.
preg_match('~function selectStale\(which\)\s*\{(.*?)\n\t\t\}~s', $ui, $m);
$sel = $m[1] ?? '';
if ($sel === '') {
    fail('could not extract selectStale()', 'part 2 proves nothing');
} else {
    strpos($sel, "dataset.safe === '1'") !== false
        ? pass('selectStale keys on dataset.safe')
        : fail('selectStale does not key on the flag', $sel);
    preg_match("~which === 'all'~", $sel)
        ? fail('selectStale offers a "select all"',
               'that reaches the still-applied rows, which is the whole thing being prevented')
        : pass('there is NO "select all" path');
    // It must not decide by reading the reason sentence.
    preg_match('~has not written|does not use this setting~', $sel)
        ? fail('selectStale matches the English reason text',
               'one reworded sentence away from selecting live settings')
        : pass('selectStale never matches the reason prose');
}

// The still-applied rows must be visibly marked, not merely unticked.
strpos($ui, 'STILL APPLIED') !== false
    ? pass('unsafe rows are badged STILL APPLIED')
    : fail('nothing visually distinguishes a still-applied row');

echo "\n== part 3: nothing deletes without a confirmation ==\n";

foreach (['dropStale' => 'the single-row button', 'dropStaleSelected' => 'the bulk button'] as $fn => $what) {
    if (!preg_match('~function ' . $fn . '\(~', $ui)) { fail("$fn is missing"); continue; }
    // confirm() must appear BEFORE the post() in the function body, or it is decoration.
    preg_match('~function ' . $fn . '\([^)]*\)\s*\{(.*?)\n\t\t\}~s', $ui, $m);
    $body = $m[1] ?? '';
    $c = strpos($body, 'confirm(');
    $p = strpos($body, "post('saveModConfigs'");
    if ($body === '')              { fail("could not read $fn's body"); }
    elseif ($c === false)          { fail("$what deletes with no confirmation", 'unrecoverable on a misclick'); }
    elseif ($p === false)          { fail("$what never posts", 'extraction stale'); }
    elseif ($c < $p)               { pass("$what confirms BEFORE posting"); }
    else                           { fail("$what confirms after posting", 'the delete already happened'); }
}

// The bulk confirmation must NAME the still-applied entries, not just count them.
preg_match('~function dropStaleSelected\(\)\s*\{(.*?)\n\t\t\}~s', $ui, $m);
$bulk = $m[1] ?? '';
strpos($bulk, 'dataset.label') !== false
    ? pass('the bulk confirmation names the still-applied settings')
    : fail('the confirmation only counts them', 'a number is not something the operator can check');
strpos($bulk, 'STILL BEING APPLIED') !== false
    ? pass('and says plainly that they are still in effect')
    : fail('the confirmation does not say what is at stake');

// One endpoint, not two: saveModConfigs already accepts an array.
substr_count($ui, "post('saveModConfigs'") >= 2
    ? pass('bulk reuses saveModConfigs rather than a second endpoint')
    : fail('bulk does not go through saveModConfigs');

echo "\n== part 4: the button cannot fire on an empty selection ==\n";
strpos($ui, "btn.disabled = (picked.length === 0)") !== false
    ? pass('Forget selected is disabled with nothing picked')
    : fail('the bulk button is enabled with an empty selection');
preg_match('~if \(!picked\.length\) \{ return; \}~', $bulk)
    ? pass('and dropStaleSelected returns early anyway (belt and braces)')
    : fail('no empty-selection guard inside dropStaleSelected');

echo "\n$pass passed, $fail failed\n";
exit($fail === 0 ? 0 : 1);
