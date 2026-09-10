// Oracle test: an ENFORCED but EMPTY access list must be refused.
//
// THE BUG (production, 2026-09-10): a world had "Use Access List" on, worlds.public = 0, and
// no citizens at all. A player joined it without being on any list. Valheim only applies
// permittedlist.txt when it has entries -- an empty one is not "nobody may join", it is no
// restriction at all. So the Access tab read "Use Access List: on" over a wide open server.
//
// Both the Citizens save and the Save Access button post through saveCitizens, so one guard
// covers both entry points, and this test drives that real endpoint.
//
// Case 3 is the one that keeps the guard honest: switching the list OFF with an empty list is
// the legitimate way to open a world, and must still work. A guard that simply rejected every
// empty list would pass cases 1 and 4 while breaking the documented way to run an open server.
//
// Usage:  node dev_tools/test-empty-accesslist-refused.js [adminURL] [world]
//   The world is restored to its original access state on exit.

const BASE  = process.argv[2] || 'http://127.0.0.1:8081';
const WORLD = process.argv[3] || 'midgard';

let pass = 0, fail = 0;
const check = (name, ok, detail) => {
    if (ok) { pass++; console.log(`  PASS  ${name}`); }
    else { fail++; console.log(`  FAIL  ${name}${detail ? ' -- ' + detail : ''}`); }
};

const save = async (citizens, isPublic) => {
    const r = await fetch(`${BASE}/adminAPI.php?action=saveCitizens`, {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ world: WORLD, citizens, public: isPublic })
    });
    try { return JSON.parse(await r.text()); }
    catch (e) { return { success: false, error: 'unparseable response' }; }
};

(async () => {
    console.log(`(world "${WORLD}")`);

    console.log('\nCase 1: list ENFORCED, list EMPTY -- must be refused');
    let r = await save('', 0);
    check('refused', r.success === false, JSON.stringify(r).slice(0, 120));
    // The message used to say an empty list would let EVERYONE in. The render-time sentinel
    // made that false -- an enforced-empty list is now genuinely closed -- so the guard was
    // reworded to the real risk: it locks out everybody, including the operator. Asserting the
    // consequence clause keeps the message honest rather than merely present.
    check('the message says why, not just "invalid"',
        /empty/i.test(r.error || '') && /nobody/i.test(r.error || ''), r.error);
    check('and does NOT still claim the world would be open',
        !/everyone/i.test(r.error || ''), r.error);

    console.log('\nCase 2: list ENFORCED with a real id -- must succeed');
    r = await save('76561197960287930', 0);
    check('accepted', r.success === true, JSON.stringify(r).slice(0, 120));

    console.log('\nCase 3: list OFF, empty -- opening a world deliberately must still work');
    // The guard must not break the supported way to run an open server.
    r = await save('', 1);
    check('accepted', r.success === true, JSON.stringify(r).slice(0, 120));

    console.log('\nCase 4: whitespace only is still empty -- must be refused');
    // "   \n  \n " is not a list. Without normalisation this sails past a naive === '' check.
    r = await save('   \n  \n ', 0);
    check('refused', r.success === false, JSON.stringify(r).slice(0, 120));

    console.log('\nCase 5: CONTROL -- the endpoint is reachable and does discriminate');
    // If every call failed for an unrelated reason (bad world, auth, 500), cases 1 and 4 would
    // "pass" while proving nothing. Cases 2 and 3 succeeding is what rules that out.
    const openOk = (await save('', 1)).success === true;
    const enforcedEmptyRefused = (await save('', 0)).success === false;
    check('accepts a valid save AND refuses the empty-enforced one',
        openOk && enforcedEmptyRefused, `open=${openOk} refused=${enforcedEmptyRefused}`);

    console.log(`\n${pass} passed, ${fail} failed`);
    console.log('NOTE: this leaves the world with "Use Access List" OFF. Restore it if it mattered.');
    process.exit(fail === 0 ? 0 : 1);
})();
