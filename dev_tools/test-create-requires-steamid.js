// Oracle test: creating a RESTRICTED world must require a real SteamID64.
//
// THE BUG this closes: "Only players on the access list" could be chosen without naming a
// single player. That produced worlds.public=0 with an empty citizens list -- and Valheim
// enforces permittedlist.txt only when it has ENTRIES, so an empty one restricts nobody. The
// world came up wide open while its Access tab said restricted.
//
// WHY THE ENDPOINT AND NOT THE FORM: new_world.php validates too, for a fast message, but the
// endpoint is reachable directly. A check that lives only in the browser is not a check. These
// cases drive adminAPI.php itself.
//
// Every case here rejects BEFORE a world is created, so nothing is installed and nothing needs
// cleaning up. Case 3 deliberately omits the world name: it must fail on the NAME, which proves
// an open world got past the access validation rather than being rejected by it.
//
// Usage:  node dev_tools/test-create-requires-steamid.js [adminURL]

const BASE = process.argv[2] || 'http://127.0.0.1:8081';

let pass = 0, fail = 0;
const check = (name, ok, detail) => {
    if (ok) { pass++; console.log(`  PASS  ${name}`); }
    else { fail++; console.log(`  FAIL  ${name}${detail ? ' -- ' + detail : ''}`); }
};

const create = async (body) => {
    const r = await fetch(`${BASE}/adminAPI.php?action=createWorld`, {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify(body)
    });
    try { return JSON.parse(await r.text()); }
    catch (e) { return { error: 'unparseable response' }; }
};

(async () => {
    console.log('\nCase 1: restricted, NO id -- must be refused');
    let r = await create({ world: 'ztest_reject_1', accessOpen: 0 });
    check('refused', /player ID/i.test(r.error || ''), JSON.stringify(r).slice(0, 140));
    check('explains that an empty list is not "nobody"',
        /everyone|nobody/i.test(r.error || ''), r.error);

    console.log('\nCase 2: restricted, malformed id -- must be refused');
    for (const bad of ['123', '7656119796028793', '765611979602879301', 'abcdefghijklmnopq', '76561197960287 30']) {
        r = await create({ world: 'ztest_reject_2', accessOpen: 0, accessFirstId: bad });
        check(`"${bad}" refused`, /SteamID64/i.test(r.error || ''), JSON.stringify(r).slice(0, 100));
    }

    console.log('\nCase 3: OPEN world needs no id at all');
    // Must fail on the MISSING NAME, not on access. If this ever reports a player-ID error,
    // the validation is running for open worlds too and the "anyone can join" path is broken.
    r = await create({ world: '', accessOpen: 1 });
    check('rejected for the world name, not for a missing player ID',
        /name/i.test(r.error || '') && !/player ID/i.test(r.error || ''), r.error);

    console.log('\nCase 4: omitting accessOpen entirely must NOT create an open world');
    // An older admin page, a script, or a replayed request sends no accessOpen. Defaulting to
    // "open" there would silently reintroduce the bug for every such caller, so absence must
    // mean restricted -- and therefore must demand an ID.
    r = await create({ world: 'ztest_reject_4' });
    check('treated as restricted, so an id is demanded',
        /player ID/i.test(r.error || ''), JSON.stringify(r).slice(0, 140));

    console.log('\nCase 5: CONTROL -- a well-formed id passes access validation');
    // Without this every case above could be "passing" because createWorld rejects everything.
    // A valid id must get PAST access validation; it then fails on the duplicate/short name,
    // which is a different error entirely.
    r = await create({ world: '', accessOpen: 0, accessFirstId: '76561197960287930' });
    check('a valid id is not rejected as an id problem',
        !/player ID|SteamID64/i.test(r.error || ''), r.error);

    console.log(`\n${pass} passed, ${fail} failed`);
    process.exit(fail === 0 ? 0 : 1);
})();
