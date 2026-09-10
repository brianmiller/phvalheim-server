// Oracle test for three Settings-modal layout reports:
//   1. General tab -- "These two apply immediately" was jammed against the bottom of
//      the Behaviour panel (.pv-section-hint carries a -0.4rem top margin meant for
//      hints under a section TITLE, not under a panel).
//   2. Options tab -- "Changes here need a world restart" sat stranded BELOW the
//      actions bar; it belongs on the same line as Save World Options.
//   3. Access tab -- Public World belongs at the TOP, and switching it on must hide
//      the Citizens editor while leaving Save Settings reachable.
//
// Every assertion measures real geometry via getBoundingClientRect in a real browser.
// Checking "the element exists" or "the class is present" would pass against all three
// bugs -- each one is purely a matter of WHERE the box landed.
//
// Run it against the OLD build first: cases 1, 2 and 3-order MUST fail. That is the control.
//
// Usage (installs playwright to /tmp so it never lands in the repo):
//   docker run --rm --network host -v "$PWD/dev_tools":/w -w /w \
//     mcr.microsoft.com/playwright:v1.47.0-jammy bash -c \
//     'npm i --silent --prefix /tmp/pw playwright@1.47.0 >/dev/null 2>&1;
//      NODE_PATH=/tmp/pw/node_modules node test-settings-modal-layout.js http://127.0.0.1:8081'
//
// NOTE: after `docker cp`ing PHP into a running container, restart php-fpm8 or OPcache
// serves the previous compile and this whole file measures a no-op.

const { chromium } = require('playwright');

const BASE = process.argv[2] || 'http://127.0.0.1:8081';

let pass = 0, fail = 0;
function check(name, ok, detail) {
    if (ok) { pass++; console.log(`  PASS  ${name}`); }
    else { fail++; console.log(`  FAIL  ${name}${detail ? ' -- ' + detail : ''}`); }
}

// Rect of the first element matching `sel`, or null. Text-matched variants below find
// the element by its VISIBLE COPY so a renamed id cannot make a case pass vacuously.
const rectOf = (sel) => `(() => {
    const el = document.querySelector(${JSON.stringify(sel)});
    if (!el) return null;
    const r = el.getBoundingClientRect();
    return { top: r.top, bottom: r.bottom, left: r.left, right: r.right,
             h: Math.round(r.height), w: Math.round(r.width),
             visible: el.offsetParent !== null && r.height > 0 };
})()`;

const rectOfText = (selector, needle) => `(() => {
    const els = Array.from(document.querySelectorAll(${JSON.stringify(selector)}));
    const el = els.find(e => e.textContent && e.textContent.indexOf(${JSON.stringify(needle)}) !== -1);
    if (!el) return null;
    const r = el.getBoundingClientRect();
    return { top: r.top, bottom: r.bottom, left: r.left, right: r.right,
             h: Math.round(r.height), w: Math.round(r.width),
             visible: el.offsetParent !== null && r.height > 0 };
})()`;

(async () => {
    const browser = await chromium.launch();
    const page = await browser.newPage({ viewport: { width: 1440, height: 1000 } });
    const errors = [];
    page.on('pageerror', e => errors.push(String(e)));

    await page.goto(`${BASE}/index.php`, { waitUntil: 'networkidle' });

    // Drive the modal the way the page does, using whatever world actually exists.
    const world = await page.evaluate(async () => {
        const r = await fetch('adminAPI.php?action=getWorlds');
        const d = await r.json();
        const list = d.worlds || d;
        return Array.isArray(list) && list.length ? (list[0].name || list[0]) : null;
    });
    if (!world) { console.log('NO WORLDS -- cannot exercise the settings modal'); process.exit(1); }
    console.log(`(using world "${world}")`);

    await page.evaluate((w) => showSettingsModal(w), world);
    // state:'attached', NOT the default 'visible' -- the .switch checkbox is
    // appearance:none + opacity:0 by design, so it is never "visible" to playwright.
    await page.waitForSelector('#settingsPublicToggle', { state: 'attached', timeout: 15000 });
    await page.waitForTimeout(600);

    // ---------------------------------------------------------------- case 1
    console.log('\nCase 1: General tab -- hint has air under the Behaviour panel');
    await page.click('.backup-tab[data-tab="settingsTab"]');
    await page.waitForTimeout(300);
    const behaviourPanel = await page.evaluate(`(() => {
        const t = Array.from(document.querySelectorAll('#settingsTab .pv-section-title'))
            .find(e => /Behaviour/i.test(e.textContent));
        if (!t) return null;
        const panel = t.parentElement.querySelector('.pv-panel');
        if (!panel) return null;
        const r = panel.getBoundingClientRect();
        return { bottom: r.bottom, h: Math.round(r.height) };
    })()`);
    const generalHint = await page.evaluate(rectOfText('#settingsTab .pv-section-hint', 'apply immediately'));
    if (!behaviourPanel || !generalHint) {
        check('found the Behaviour panel and its hint', false, JSON.stringify({ behaviourPanel, generalHint }));
    } else {
        const gap = Math.round(generalHint.top - behaviourPanel.bottom);
        // The bug produced a NEGATIVE gap (the -0.4rem pull). Anything >= 6px reads as deliberate.
        check('hint sits >=6px below the panel', gap >= 6, `gap=${gap}px`);
        check('hint is visible', generalHint.visible, JSON.stringify(generalHint));
    }

    // ---------------------------------------------------------------- case 2
    console.log('\nCase 2: Options tab -- restart hint shares a line with Save World Options');
    await page.click('.backup-tab[data-tab="optionsTab"]');
    await page.waitForTimeout(300);
    const saveBtn = await page.evaluate(rectOfText('#optionsTab .action-btn', 'Save World Options'));
    const optHint = await page.evaluate(rectOfText('#optionsTab .pv-section-hint', 'world restart'));
    if (!saveBtn || !optHint) {
        check('found the Save button and the restart hint', false, JSON.stringify({ saveBtn, optHint }));
    } else {
        // "Same plane" == their vertical spans overlap. The bug had the hint entirely below.
        const overlap = Math.min(saveBtn.bottom, optHint.bottom) - Math.max(saveBtn.top, optHint.top);
        check('hint overlaps the button vertically', overlap > 0,
            `overlap=${Math.round(overlap)}px hintTop=${Math.round(optHint.top)} btnTop=${Math.round(saveBtn.top)}`);
        check('hint is LEFT of the button', optHint.left < saveBtn.left,
            `hintLeft=${Math.round(optHint.left)} btnLeft=${Math.round(saveBtn.left)}`);
        check('hint is visible', optHint.visible, JSON.stringify(optHint));
    }

    // ---------------------------------------------------------------- case 3
    console.log('\nCase 3: Access tab -- Public World on top, Citizens editor follows it');
    await page.click('.backup-tab[data-tab="accessTab"]');
    await page.waitForTimeout(300);

    // Start from the not-public state so the editor is on screen to begin with.
    await page.evaluate(() => {
        const t = document.getElementById('settingsPublicToggle');
        if (t.checked) { t.checked = false; t.dispatchEvent(new Event('change')); }
    });
    await page.waitForTimeout(250);

    const publicRow = await page.evaluate(rectOfText('#accessTab span', 'Public World'));
    const citizensHdr = await page.evaluate(rectOfText('#accessTab h6', 'Citizens'));
    const adminsHdr = await page.evaluate(rectOfText('#accessTab h6', 'Admins'));
    if (!publicRow || !citizensHdr) {
        check('found Public World and the Citizens heading', false, JSON.stringify({ publicRow, citizensHdr }));
    } else {
        check('Public World is ABOVE the Citizens heading', publicRow.top < citizensHdr.top,
            `public=${Math.round(publicRow.top)} citizens=${Math.round(citizensHdr.top)}`);
        if (adminsHdr) {
            check('Public World is above Admins too', publicRow.top < adminsHdr.top,
                `public=${Math.round(publicRow.top)} admins=${Math.round(adminsHdr.top)}`);
        }
    }

    const editorBefore = await page.evaluate(rectOf('#settingsCitizensTextarea'));
    check('Citizens editor visible while NOT public', !!editorBefore && editorBefore.visible,
        JSON.stringify(editorBefore));

    // Flip it on through the real event path.
    await page.evaluate(() => {
        const t = document.getElementById('settingsPublicToggle');
        t.checked = true; t.dispatchEvent(new Event('change'));
    });
    await page.waitForTimeout(250);

    const editorAfter = await page.evaluate(rectOf('#settingsCitizensTextarea'));
    const lookupAfter = await page.evaluate(rectOfText('#accessTab .action-btn', 'Look Up SteamID'));
    const saveAfter = await page.evaluate(rectOfText('#accessTab .action-btn', 'Save Settings'));
    const publicAfter = await page.evaluate(rectOfText('#accessTab span', 'Public World'));
    check('Citizens editor HIDDEN when public', !!editorAfter && !editorAfter.visible, JSON.stringify(editorAfter));
    check('Look Up SteamID hidden with it', !lookupAfter || !lookupAfter.visible, JSON.stringify(lookupAfter));
    check('Save Settings STILL reachable', !!saveAfter && saveAfter.visible, JSON.stringify(saveAfter));
    check('Public World still on screen', !!publicAfter && publicAfter.visible, JSON.stringify(publicAfter));

    // The list must survive the round trip -- hiding must not blank the value that
    // saveSettingsCitizens() posts back.
    const textPreserved = await page.evaluate(() => {
        const ta = document.getElementById('settingsCitizensTextarea');
        return ta ? ta.value : null;
    });
    check('textarea still holds its value while hidden (not blanked)', textPreserved !== null,
        `value=${JSON.stringify(String(textPreserved).slice(0, 40))}`);

    // Back off again -- the editor must return.
    await page.evaluate(() => {
        const t = document.getElementById('settingsPublicToggle');
        t.checked = false; t.dispatchEvent(new Event('change'));
    });
    await page.waitForTimeout(250);
    const editorBack = await page.evaluate(rectOf('#settingsCitizensTextarea'));
    check('Citizens editor returns when public is switched off', !!editorBack && editorBack.visible,
        JSON.stringify(editorBack));

    // ---------------------------------------------------------------- case 4
    console.log('\nCase 4: no JS errors');
    check('no uncaught page errors', errors.length === 0, errors.join(' | '));

    await browser.close();
    console.log(`\n${pass} passed, ${fail} failed`);
    process.exit(fail === 0 ? 0 : 1);
})();
