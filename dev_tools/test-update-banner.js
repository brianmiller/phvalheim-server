#!/usr/bin/env node
//
// The "Update started…" banner has to come DOWN.
//
// Reported from a real server after a fully successful auto-update: backup, stop, update and
// start all worked, the panel below read idle and "up to date" -- and the banner still said
// "Update started…". Two callers set it (Update Now, Rebuild Mods) and nothing ever took it
// down, so it claimed an update was running for as long as the tab stayed open.
//
// The clearing rule is EXTRACTED FROM index.php and eval'd here, not re-implemented. A
// re-implementation would answer these questions the same way whether or not the page was
// fixed -- the failure mode that let a What's New bug pass 18 tests earlier in this release.
//
// Run: dev_tools/test-update-banner.js

const fs = require('fs');
const path = require('path');

const INDEX = path.join(__dirname, '..', 'container', 'nginx', 'www', 'admin', 'index.php');
const src = fs.readFileSync(INDEX, 'utf8');

let pass = 0, fail = 0;
const ok  = (m) => { pass++; console.log('  PASS  ' + m); };
const bad = (m) => { fail++; console.log('  FAIL  ' + m); };
const check = (m, expected, actual) => {
    if (JSON.stringify(expected) === JSON.stringify(actual)) ok(m);
    else bad(`${m}\n          expected: ${JSON.stringify(expected)}\n          actual:   ${JSON.stringify(actual)}`);
};

console.log('\nupdate banner tests\n');

// ---- extract the clearing rule ----------------------------------------------------------
const start = src.indexOf("const actionStatus = document.getElementById('updateActionStatus');");
const endMark = 'delete actionStatus.dataset.transientAt;';
const end = src.indexOf(endMark, start);
if (start < 0 || end < 0) {
    console.log('  FAIL  could not extract the banner-clearing rule from index.php');
    process.exit(1);
}
const RULE = src.slice(start, src.indexOf('}', end) + 1);
ok(`extracted the clearing rule (${RULE.split('\n').length} lines)`);

// ---- harness ----------------------------------------------------------------------------
// $1 age of the banner in ms (null = no banner stamp, i.e. an error message)
// $2 is the world still busy?
function run(ageMs, busy, text = 'Update started…') {
    const el = { textContent: text, style: { color: 'var(--warning)' }, dataset: {} };
    if (ageMs !== null) el.dataset.transientAt = String(Date.now() - ageMs);
    const document = { getElementById: (id) => (id === 'updateActionStatus' ? el : null) };
    // eslint-disable-next-line no-eval
    eval(RULE);
    return el;
}

// ---- the reported bug -------------------------------------------------------------------
let el = run(60000, false);
check('a finished update clears the banner', '', el.textContent);
check('...and drops the stamp with it', undefined, el.dataset.transientAt);

// ---- the race it must NOT lose ----------------------------------------------------------
// The engine only picks the world up on its next 2s tick, so an immediate refresh legitimately
// sees idle before the update has begun. Clearing there wipes the banner a second after the
// click, which reads as "nothing happened".
el = run(2000, false);
check('a banner one second old is left alone -- the engine has not picked it up yet',
      'Update started…', el.textContent);

// ---- while it is actually running -------------------------------------------------------
el = run(60000, true);
check('a running update keeps its banner', 'Update started…', el.textContent);

// ---- an error is not progress -----------------------------------------------------------
// The error paths delete the stamp precisely so this rule cannot eat them.
el = run(null, false, 'Could not start the update.');
check('an unstamped error message is never cleared here',
      'Could not start the update.', el.textContent);

// ---- both setters stamp, or the rule can never fire -------------------------------------
const stampedSetters = (src.match(/status\.dataset\.transientAt = String\(Date\.now\(\)\)/g) || []).length;
check('both Update Now and Rebuild Mods stamp their banner', 2, stampedSetters);

// ---- every error path unstamps ----------------------------------------------------------
// 4 sites: Update Now (!success + catch), Rebuild Mods (!success + catch). Miss one and that
// error silently disappears ~10s later.
const unstamped = (src.match(/delete status\.dataset\.transientAt;/g) || []).length;
check('every error path drops the stamp so its message survives', 4, unstamped);

// ---- the banner element still exists ----------------------------------------------------
check('the banner element is still in the markup', 1,
      (src.match(/id="updateActionStatus"/g) || []).length);

console.log(`\n  ${pass} passed, ${fail} failed`);
process.exit(fail ? 1 : 0);
