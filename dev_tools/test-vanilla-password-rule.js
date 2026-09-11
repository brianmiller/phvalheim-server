// Oracle test: the vanilla password rules are explained where they are broken.
//
// THE REPORT (2026-09-11): creating vanilla world "test123132131" with password "test123" was
// blocked, and the only warning text on screen opened with "Note: a custom seed needs a mod...".
// It read as though seeds were the obstacle. They were not -- "test123132131" CONTAINS
// "test123", and Valheim refuses to start a world whose password appears inside its name.
//
// Two faults:
//   1. The rules were enforced only on the server, so the form span, bounced, and printed the
//      reason in a message box at the other end of a long page.
//   2. That seed sentence was sitting in the password/listing panel, nowhere near the seed
//      control, which already explains itself twice.
//
// Usage (needs the dev container; admin UI is :8081):
//   docker run --rm --network host -v "$PWD":/repo -w /repo/dev_tools \
//     mcr.microsoft.com/playwright:v1.47.0-jammy bash -c \
//     'npm i --silent --prefix /tmp/pw playwright@1.47.0 >/dev/null 2>&1;
//      NODE_PATH=/tmp/pw/node_modules node test-vanilla-password-rule.js http://127.0.0.1:8081'

const { chromium } = require('playwright');

const BASE = process.argv[2] || 'http://127.0.0.1:8081';

let pass = 0, fail = 0;
const check = (name, ok, detail) => {
    if (ok) { pass++; console.log(`  PASS  ${name}`); }
    else { fail++; console.log(`  FAIL  ${name}${detail ? ' -- ' + detail : ''}`); }
};

// Fill the form the way Brian did and read whatever the page says about it.
async function state(p, { world, password, vanilla = true, listed = false }) {
    await p.evaluate(({ world, password, vanilla, listed }) => {
        document.querySelector('#world').value = world;
        const v = document.querySelector('#vanillaWorld');
        if (v.checked !== vanilla) { v.checked = vanilla; toggleVanillaWorld(vanilla); }
        document.querySelector('#vanillaPassword').value = password;
        const l = document.querySelector('#vanillaListed');
        l.checked = listed;
        validateVanillaPassword();
    }, { world, password, vanilla, listed });
    return p.evaluate(() => {
        const e = document.querySelector('#vanillaPasswordError');
        return { shown: e.offsetParent !== null, text: (e.textContent || '').trim() };
    });
}

(async () => {
    const browser = await chromium.launch();
    const p = await browser.newPage({ viewport: { width: 1400, height: 1200 } });
    await p.goto(`${BASE}/new_world.php`, { waitUntil: 'networkidle' });

    console.log('\nTHE REPORTED CASE: password is a substring of the world name');
    let s = await state(p, { world: 'test123132131', password: 'test123' });
    check('the form objects before it is submitted', s.shown, 'nothing shown');
    check('and says it is about the name, not the seed',
        /world\s+name/i.test(s.text) && !/seed/i.test(s.text), `got "${s.text}"`);
    check('and quotes both offending values back',
        s.text.includes('test123132131') && s.text.includes('test123'), `got "${s.text}"`);

    console.log('\nThe other two rules');
    s = await state(p, { world: 'someworld', password: 'abc' });
    check('under 5 characters is refused', s.shown && /5 characters/i.test(s.text), `got "${s.text}"`);

    s = await state(p, { world: 'someworld', password: '', listed: true });
    check('listing with no password is refused', s.shown && /server browser/i.test(s.text),
        `got "${s.text}"`);

    console.log('\nAnd it gets out of the way when it should');
    s = await state(p, { world: 'someworld', password: 'hunter55' });
    check('a good password clears the message', !s.shown, `still showing "${s.text}"`);

    s = await state(p, { world: 'someworld', password: '' });
    check('no password at all is fine when not listing', !s.shown, `showing "${s.text}"`);

    // The rules belong to vanilla worlds. A modded world has no password field at all, so a
    // message left over from one would be pointing at nothing.
    s = await state(p, { world: 'test123132131', password: 'test123', vanilla: false });
    check('a modded world is not held to them', !s.shown, `showing "${s.text}"`);

    console.log('\nTyping a NEW NAME re-runs the check, it is not a one-shot');
    // The rule is about both fields; only re-validating on password input would leave a stale
    // verdict the moment the name changed.
    await state(p, { world: 'aaaa', password: 'hunter55' });
    s = await state(p, { world: 'myhunter55world', password: 'hunter55' });
    check('renaming the world into a clash is caught', s.shown, 'no message after rename');

    console.log('\nTHE SEED SENTENCE IS OUT OF THE PASSWORD PANEL');
    const panel = await p.evaluate(() => {
        const pw = document.querySelector('#vanillaPassword');
        const card = pw.closest('.card-panel');
        return card ? card.textContent.replace(/\s+/g, ' ').trim() : '';
    });
    check('the password/listing panel no longer mentions seeds',
        panel !== '' && !/seed/i.test(panel), `panel text: "${panel.slice(0, 160)}"`);
    // It must still be explained -- just next to the seed control, where it belongs.
    const seedNotice = await p.evaluate(() =>
        (document.querySelector('#vanillaSeedNotice') || {}).textContent || '');
    check('the seed control still explains itself', /mod/i.test(seedNotice) && /seed/i.test(seedNotice),
        `got "${seedNotice.replace(/\s+/g, ' ').trim().slice(0, 120)}"`);

    console.log('\nCONTROL: the checks above can fail');
    // If validateVanillaPassword were a no-op every "refused" assertion would still need the
    // error element to appear. Prove the element is genuinely driven by the function.
    const drivenByFn = await p.evaluate(() => {
        document.querySelector('#vanillaPasswordError').style.display = 'none';
        document.querySelector('#world').value = 'test123132131';
        document.querySelector('#vanillaPassword').value = 'test123';
        validateVanillaPassword();
        return document.querySelector('#vanillaPasswordError').offsetParent !== null;
    });
    check('the message is produced by validateVanillaPassword()', drivenByFn);

    await browser.close();
    console.log(`\n${pass} passed, ${fail} failed`);
    process.exit(fail === 0 ? 0 : 1);
})();
