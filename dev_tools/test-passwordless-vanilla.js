// Oracle test: a vanilla world may run with no password, but not while listed.
//
// VERIFIED AGAINST THE GAME before any of this was written, by running valheim_server directly
// and bypassing our own guards (world test123456, an installed vanilla world):
//
//   -public 1, no password   -> "Error bad password: The password is too short"   (dies)
//   -public 1, -password ... -> "Opened Steam server / Game server connected"      (runs)
//   -public 0, no password   -> "Opened Steam server / Game server connected"      (runs)
//
// So the rule is NOT "vanilla worlds need a password". It is "a LISTED server needs one".
// A passwordless, unlisted vanilla world is a legitimate configuration and the UI now allows
// it -- which means the listing control is what has to be gated, not the password field.
//
// This checks the gate in both places an operator can set it: the create form and the world
// Settings modal. The server re-checks in both paths too (createWorld and saveWorldOptions
// each refuse listed=1 with an empty password); these are the checks that stop an operator
// reaching that error in the first place.
//
// Usage:
//   docker run --rm --network host -v "$PWD":/repo -w /repo/dev_tools \
//     mcr.microsoft.com/playwright:v1.47.0-jammy bash -c \
//     'npm i --silent --prefix /tmp/pw playwright@1.47.0 >/dev/null 2>&1;
//      NODE_PATH=/tmp/pw/node_modules node test-passwordless-vanilla.js http://127.0.0.1:8081'

const { chromium } = require('playwright');

const BASE = process.argv[2] || 'http://127.0.0.1:8081';

let pass = 0, fail = 0;
const check = (name, ok, detail) => {
    if (ok) { pass++; console.log(`  PASS  ${name}`); }
    else { fail++; console.log(`  FAIL  ${name}${detail ? ' -- ' + detail : ''}`); }
};

(async () => {
    const browser = await chromium.launch();
    const p = await browser.newPage({ viewport: { width: 1400, height: 1200 } });

    console.log('\nCREATE FORM');
    await p.goto(`${BASE}/new_world.php`, { waitUntil: 'networkidle' });
    const setUp = async (password) => {
        await p.evaluate((pw) => {
            document.querySelector('#world').value = 'zpwtest';
            const v = document.querySelector('#vanillaWorld');
            if (!v.checked) { v.checked = true; toggleVanillaWorld(true); }
            document.querySelector('#vanillaPassword').value = pw;
            validateVanillaPassword();
        }, password);
        return p.evaluate(() => ({
            disabled: document.querySelector('#vanillaListed').disabled,
            checked: document.querySelector('#vanillaListed').checked,
            blockedShown: document.querySelector('#vanillaListedBlocked').offsetParent !== null,
            err: (document.querySelector('#vanillaPasswordError').textContent || '').trim(),
            errShown: document.querySelector('#vanillaPasswordError').offsetParent !== null,
        }));
    };

    let s = await setUp('');
    check('no password: listing is disabled', s.disabled, 'the toggle was still usable');
    check('and says why', s.blockedShown, 'no explanation shown');
    check('and no password is NOT itself an error', !s.errShown, `got "${s.err}"`);

    s = await setUp('hunter55');
    check('with a password: listing is available again', !s.disabled);
    check('and the blocked note is hidden', !s.blockedShown);

    // The important one. A disabled-but-ticked box still posts listed:1, so the form would be
    // submitting the exact combination it is telling the operator is impossible.
    await p.evaluate(() => { document.querySelector('#vanillaListed').checked = true; });
    s = await setUp('');
    check('a ticked box is UNTICKED when the password is cleared, not just greyed',
        !s.checked, 'still ticked -- the form would post listed:1');

    console.log('\nSETTINGS MODAL');
    // The modal lives on the dashboard, not the create form -- showSettingsModal() is not
    // defined on new_world.php.
    await p.goto(`${BASE}/index.php`, { waitUntil: 'networkidle' });
    // Needs a vanilla world to open. Any will do; the gate is on the field, not the world.
    const world = await p.evaluate(async (base) => {
        const r = await fetch(`${base}/adminAPI.php?action=getWorlds`);
        const j = await r.json();
        const v = (j.worlds || []).find(w => w.vanilla == 1);
        return v ? v.name : null;
    }, BASE);
    check('found a vanilla world to open settings on', world !== null,
        'no vanilla world in the dev container');

    if (world) {
        await p.evaluate((w) => showSettingsModal(w), world);
        // 'attached', not the default 'visible': the vanilla block sits on a tab that is not
        // the one the modal opens on, so the field is in the DOM but hidden. The gate is being
        // driven programmatically here, which is what the tab switch would do anyway.
        await p.waitForSelector('#settingsWorldPassword', { state: 'attached', timeout: 15000 });
        const modal = async (pw) => {
            await p.evaluate((v) => {
                document.querySelector('#settingsWorldPassword').value = v;
                syncListedAvailability();
            }, pw);
            return p.evaluate(() => ({
                disabled: document.querySelector('#settingsListedToggle').disabled,
                checked: document.querySelector('#settingsListedToggle').checked,
                // Own display, not offsetParent. The vanilla block lives on a tab that is not
                // the one the modal opens on, so every element in it has a hidden ancestor and
                // offsetParent is null whatever this note does -- the check would fail for a
                // reason that has nothing to do with the note.
                noteShown: getComputedStyle(document.querySelector('#settingsListedBlocked')).display !== 'none',
            }));
        };
        let m = await modal('');
        check('no password: the listing toggle is disabled', m.disabled);
        check('and explains itself', m.noteShown);
        check('and is unticked', !m.checked);

        m = await modal('hunter55');
        check('with a password: enabled again', !m.disabled);
        check('and the note is hidden', !m.noteShown);

        // Opening the modal must reflect the password the world ALREADY has. Wiring the sync
        // only to oninput would leave a passwordless world showing an enabled toggle until
        // someone happened to type in the field.
        const runsOnOpen = await p.evaluate(() => {
            const src = syncListedAvailability.toString();
            return typeof syncListedAvailability === 'function' && src.includes('settingsWorldPassword');
        });
        check('the sync reads the password field (so it can run on open)', runsOnOpen);
    }

    console.log('\nCONTROL: these checks can fail');
    // If syncListedAvailability() were a no-op, every "disabled" assertion above would need the
    // toggle to already be disabled in the markup. Prove the function is what disables it.
    await p.goto(`${BASE}/new_world.php`, { waitUntil: 'networkidle' });
    const driven = await p.evaluate(() => {
        const v = document.querySelector('#vanillaWorld');
        if (!v.checked) { v.checked = true; toggleVanillaWorld(true); }
        document.querySelector('#vanillaPassword').value = 'hunter55';
        validateVanillaPassword();
        const before = document.querySelector('#vanillaListed').disabled;
        document.querySelector('#vanillaPassword').value = '';
        validateVanillaPassword();
        return { before, after: document.querySelector('#vanillaListed').disabled };
    });
    check('the toggle is enabled with a password and disabled without',
        driven.before === false && driven.after === true, JSON.stringify(driven));

    await browser.close();
    console.log(`\n${pass} passed, ${fail} failed`);
    process.exit(fail === 0 ? 0 : 1);
})();
