#!/usr/bin/env php
<?php
/**
 * Oracle test for aiStripNarration() -- removing a model's thinking-out-loud from the top
 * of a Hugin answer.
 *
 * WHAT IT GUARDS. Small models narrate their investigation into the final answer, and the
 * last round has no tool call after it for the client to key off. Measured on DeepSeek v4
 * Flash against a live production box, a health summary opened with three paragraphs of
 * working-out before the first heading. The fixtures marked CAPTURED below are verbatim
 * from real replies, not invented.
 *
 * WHY THE FALSE-POSITIVE CASES MATTER MORE THAN THE REST. This function deletes text the
 * operator asked for. A rule keen enough to catch every narration would eat real answers
 * that happen to open with "I", so the KEEP cases are the real specification: the strip is
 * only allowed to fire on leading, structure-free, first-person process talk, and it must
 * never return nothing.
 *
 * MUTATION CHECK: make the narration regex match everything (drop the anchors) and KEEP1-6
 * must go red. Make it match nothing and STRIP1-5 go red.
 *
 * Usage:  php dev_tools/test-ai-narration.php
 */

require_once __DIR__ . '/../container/nginx/www/includes/aicontext.php';

$pass = 0; $fail = 0;
function ok($n)            { global $pass; $pass++; echo "  PASS  $n\n"; }
function bad($n, $w, $g)   { global $fail; $fail++; echo "  FAIL  $n\n         wanted: $w\n         got:    $g\n"; }

/** The answer must start with $needle after stripping. */
function starts($name, $in, $needle) {
    $out = aiStripNarration($in);
    if (strpos(ltrim($out), $needle) === 0) ok($name);
    else bad($name, "starts with " . json_encode($needle), json_encode(substr(ltrim($out), 0, 70)));
}
/** The text must come back completely untouched. */
function keep($name, $in) {
    $out = aiStripNarration($in);
    if ($out === $in) ok($name);
    else bad($name, 'unchanged', json_encode(substr($out, 0, 70)));
}

echo "== strips real narration (CAPTURED from DeepSeek v4 Flash) ==\n";

// CAPTURED: the health-summary reply, verbatim opening.
starts('STRIP1 three narration paragraphs before a heading',
"I have enough to summarise the server health. The engine errors about 'northlands', 'znopw1', 'ztestpw1' all concern worlds that are no longer in the current world list — historical noise.

Let me be careful about the \"no log yet\" — there are no valheimworld_Asgard or valheimworld_Vanaheim log files in list_logs.

Now I can write the summary.

# Server Health Summary

## Host — Healthy",
'# Server Health Summary');

// CAPTURED: the first rundown reply.
starts('STRIP2 "My answer above is complete"',
"I have everything needed. My answer above is complete. Let me add a note about the diagnostics.

The rundown I gave stands. Both worlds are marked Running in the database.",
'The rundown I gave stands.');

starts('STRIP3 a single "Let me" paragraph',
"Let me check the access lists before answering.

Asgard is gated by its password.",
'Asgard is gated by its password.');

starts('STRIP4 narration straight into a table',
"Now that I have both worlds, I can compare them.

| World | Mode |\n|---|---|\n| Asgard | vanilla |",
'| World | Mode |');

starts('STRIP5 an "Okay, let me..." opener',
"Okay, let me pull the world list first.

Two worlds are configured.",
'Two worlds are configured.');

// CAPTURED: narration that OPENS with a finding-shaped sentence, so the leading-paragraph
// walk stops at once. Only the cut-to-first-heading pass reaches this.
starts('STRIP6 narration interleaved with a finding, then a heading',
"The engine errors about northlands/znopw1 and the analytics permission errors are from Sep 11-12 (past, ~3 days ago), not current.

Let me check the current date context. Logs last modified Sep 15 18:33.

Let me verify whether there's a more recent engine log activity. It's not. So Asgard/Vanaheim marked running but have never produced a log.

Let me quickly look at what the engine says around the current time for these worlds.

I have enough to answer. The two \"running\" worlds have no supervisor process, no log, and no backup.

# Server Health Summary

## Overall verdict",
'# Server Health Summary');

echo "\n== leaves real answers alone ==\n";

// The KEEP cases are the specification. Each one opens in a way a keen rule would eat.
keep('KEEP1 an answer that opens with a finding',
"Both worlds are marked running but have never written a log — and neither has a log file at all on disk.");

keep('KEEP2 an answer whose first paragraph is a heading',
"# Server Health Summary\n\nEverything is healthy.");

keep('KEEP3 an answer opening with a list',
"- Asgard is password protected\n- Vanaheim uses its CITIZENS list");

keep('KEEP4 first person that is a FINDING, not narration',
"I checked both world logs and neither has written a line since Sep 12.");

keep('KEEP5 an offer to continue, at the end',
"Asgard is gated by its password.\n\nLet me know if you want the CITIZENS list too.");

// "Nothing" must not be caught by the `now ...` branch -- the \b anchors are what stop it.
keep('KEEP6 a word merely beginning with "no" is not the "now" opener',
"Nothing needs changing.");

// The gate on STRIP6. ONE lead paragraph before a heading is how a great many good answers
// are written, and it must survive even when it opens in the first person.
keep('KEEP7 a single lead paragraph before a heading survives',
"I found one thing worth fixing, covered below.\n\n# Server Health Summary\n\nEverything else is fine.");

// Two lead paragraphs, but neither is narration -- still an answer.
keep('KEEP8 two non-narration lead paragraphs before a heading survive',
"Asgard is password protected and healthy.\n\nVanaheim is gated by its CITIZENS list.\n\n# Details\n\nBoth are running.");

// Narration before a LIST rather than a heading: the second pass requires a heading, so
// this falls to the conservative first pass, which stops on the finding-shaped opener.
keep('KEEP9 the second pass does not fire without a heading',
"Both worlds look fine to me.\n\nLet me double check the ports.\n\nLet me also confirm the backups.\n\n- Asgard: 25101\n- Vanaheim: 25102");

echo "\n== safety ==\n";

// If it all looks like narration, 2.45 behaviour beats an empty bubble.
$allNarration = "Let me check the logs.\n\nNow I can answer.";
$out = aiStripNarration($allNarration);
if ($out === $allNarration) ok('SAFE1 an all-narration reply is returned intact, never emptied');
else bad('SAFE1 an all-narration reply is returned intact, never emptied', 'unchanged', json_encode($out));

foreach (['' => 'empty string', '   ' => 'whitespace only'] as $in => $label) {
    $out = aiStripNarration($in);
    if ($out === $in) ok("SAFE2 $label is passed through");
    else bad("SAFE2 $label is passed through", 'unchanged', json_encode($out));
}

$out = aiStripNarration("Let me look.\n\n" . str_repeat('x', 100));
if (strlen($out) === 100) ok('SAFE3 the kept body is not truncated or re-wrapped');
else bad('SAFE3 the kept body is not truncated or re-wrapped', '100 chars', strlen($out) . ' chars');

echo "\npassed $pass, failed $fail\n";
exit($fail === 0 ? 0 : 1);
