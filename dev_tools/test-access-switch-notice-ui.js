// Oracle test for the access-switch notice in the browser.
//
// The novel risk here is CHAINING. An upgrader can have BOTH notices armed -- the id-format
// one and the switch one. They are separate overlays on the same tab, so showing them
// together stacks two modals in the same place and only the top one is readable. They must
// appear one after the other, and BOTH must be seen.
//
// Assertions are on which overlay is actually visible at each step, not on "the markup
// exists" -- both overlays are in the DOM the whole time, so a markup check passes on a page
// that never shows either.
//
// ARM BOTH FLAGS FIRST (this test is not self-arming, and dismissing is what it proves):
//   docker exec <container> mysql -e "update phvalheim.settings set accessIdNoticeShown=0, accessSwitchNoticeShown=0"
//
// Usage:
//   docker run --rm --network host -v "$PWD/dev_tools":/w -w /w \
//     mcr.microsoft.com/playwright:v1.47.0-jammy bash -c \
//     'npm i --silent --prefix /tmp/pw playwright@1.47.0 >/dev/null 2>&1;
//      NODE_PATH=/tmp/pw/node_modules node test-access-switch-notice-ui.js http://127.0.0.1:8081'

const { chromium } = require('playwright');
const BASE = process.argv[2] || 'http://127.0.0.1:8081';

let pass = 0, fail = 0;
function check(name, ok, detail) {
    if (ok) { pass++; console.log(`  PASS  ${name}`); }
    else { fail++; console.log(`  FAIL  ${name}${detail ? ' -- ' + detail : ''}`); }
}

const visible = (id) => {
    const el = document.getElementById(id);
    if (!el) return { found: false, visible: false };
    return { found: true, visible: el.classList.contains('show') && el.getBoundingClientRect().height > 0 };
};

(async () => {
    const browser = await chromium.launch();
    const page = await browser.newPage({ viewport: { width: 1440, height: 1000 } });
    const errors = [];
    page.on('pageerror', e => errors.push(String(e).slice(0, 160)));

    await page.goto(`${BASE}/index.php`, { waitUntil: 'networkidle', timeout: 60000 });

    const armed = await page.evaluate(() => !!document.getElementById('accessSwitchNoticeOverlay'));
    if (!armed) {
        console.log('\nPRECONDITION NOT MET: the switch notice is dismissed, so PHP did not render it.');
        console.log('  docker exec <container> mysql -e "update phvalheim.settings set accessIdNoticeShown=0, accessSwitchNoticeShown=0"');
        process.exit(2);
    }

    const world = await page.evaluate(() => {
        const el = document.querySelector('[onclick*="showSettingsModal"]');
        const m = el && el.getAttribute('onclick').match(/showSettingsModal\('([^']+)'/);
        return m ? m[1] : null;
    });
    if (!world) { console.log('NO WORLDS'); process.exit(1); }
    console.log(`(using world "${world}")`);

    console.log('\nCase 1: it waits for the Access tab');
    let sw = await page.evaluate(visible, 'accessSwitchNoticeOverlay');
    check('not shown on page load', sw.found && !sw.visible, JSON.stringify(sw));

    await page.evaluate((w) => showSettingsModal(w), world);
    await page.waitForSelector('#settingsAccessListToggle', { state: 'attached', timeout: 15000 });
    await page.waitForTimeout(600);
    sw = await page.evaluate(visible, 'accessSwitchNoticeOverlay');
    check('not shown on the General tab', !sw.visible, JSON.stringify(sw));

    await page.click('.backup-tab[data-tab="accessTab"]');
    await page.waitForTimeout(600);
    sw = await page.evaluate(visible, 'accessSwitchNoticeOverlay');
    check('SHOWN once the Access tab is opened', sw.visible, JSON.stringify(sw));

    console.log('\nCase 2: one at a time, not stacked');
    const idAlso = await page.evaluate(visible, 'accessIdNoticeOverlay');
    check('the id notice is NOT also on screen', idAlso.found && !idAlso.visible, JSON.stringify(idAlso));

    console.log('\nCase 3: it says the thing that matters');
    const text = await page.evaluate(() => document.getElementById('accessSwitchNoticeOverlay').innerText);
    check('names both the old and new label',
        /Public World/.test(text) && /Use Access List/.test(text));
    check('says nothing about the world changed', /[Nn]othing about your worlds changed/.test(text));
    check('warns that flipping it DOES change access', /will<\/em>? change|will change who can join/i.test(text),
        text.slice(-140));

    console.log('\nCase 4: dismissing shows the id notice next, not nothing');
    await page.evaluate(() => dismissAccessSwitchNotice());
    await page.waitForTimeout(900);
    sw = await page.evaluate(visible, 'accessSwitchNoticeOverlay');
    const idNow = await page.evaluate(visible, 'accessIdNoticeOverlay');
    check('the switch notice closed', !sw.visible, JSON.stringify(sw));
    check('the id notice follows it', idNow.visible, JSON.stringify(idNow));

    console.log('\nCase 5: the dismissal persisted');
    await page.evaluate(() => dismissAccessIdNotice());
    await page.waitForTimeout(700);
    await page.goto(`${BASE}/index.php`, { waitUntil: 'networkidle', timeout: 60000 });
    const gone = await page.evaluate(() => ({
        sw: !!document.getElementById('accessSwitchNoticeOverlay'),
        id: !!document.getElementById('accessIdNoticeOverlay')
    }));
    // PHP must stop rendering them entirely once the flags are back to 1.
    check('switch notice not re-rendered after reload', !gone.sw, JSON.stringify(gone));
    check('id notice not re-rendered either', !gone.id, JSON.stringify(gone));

    console.log('\nCase 6: no JS errors');
    check('no uncaught page errors', errors.length === 0, errors.join(' | '));

    await browser.close();
    console.log(`\n${pass} passed, ${fail} failed`);
    process.exit(fail === 0 ? 0 : 1);
})();
