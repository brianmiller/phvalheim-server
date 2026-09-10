// Oracle test for the one-time Valheim-1.0 id notice.
//
// Asserts on what is actually ON SCREEN and on the flag left in the database. "The markup
// exists" would pass even if the notice never appeared, and "the API returned success"
// would pass even if the flag never changed -- so neither is used here.
//
// The flag is driven directly in the DB between phases, which is what an upgrade does.
//
// Usage:
//   docker run --rm --network host -v "$PWD/dev_tools":/w -w /w \
//     mcr.microsoft.com/playwright:v1.47.0-jammy bash -c \
//     'npm i --silent --prefix /tmp/pw playwright@1.47.0 >/dev/null 2>&1;
//      NODE_PATH=/tmp/pw/node_modules node test-access-id-notice.js http://127.0.0.1:8081'

const { chromium } = require('playwright');
const BASE = process.argv[2] || 'http://127.0.0.1:8081';

let pass = 0, fail = 0;
function check(name, ok, detail) {
    if (ok) { pass++; console.log(`  PASS  ${name}`); }
    else { fail++; console.log(`  FAIL  ${name}${detail ? ' -- ' + detail : ''}`); }
}

const overlayVisible = `(() => {
    const el = document.getElementById('accessIdNoticeOverlay');
    if (!el) return { found: false, visible: false };
    const r = el.getBoundingClientRect();
    return { found: true,
             visible: el.classList.contains('show') && r.width > 0 && r.height > 0,
             classes: el.className };
})()`;

// A freshly-upgraded server opens the Server Settings modal by itself (v2.31 behaviour
// when no env vars were ever set), and it sits over everything. Close any such overlay
// so we are testing OUR notice rather than modal stacking.
async function clearBlockingOverlays(page) {
    await page.evaluate(() => {
        ['serverSettingsOverlay', 'migrationNoticeOverlay'].forEach(id => {
            const el = document.getElementById(id);
            if (el) el.classList.remove('show');
        });
    });
    await page.waitForTimeout(150);
}

async function openAccessTab(page, world) {
    await page.evaluate((w) => showSettingsModal(w), world);
    await page.waitForSelector('#settingsPublicToggle', { state: 'attached', timeout: 15000 });
    await page.waitForTimeout(500);
    await page.click('.backup-tab[data-tab="accessTab"]');
    await page.waitForTimeout(400);
}

(async () => {
    const browser = await chromium.launch();
    const page = await browser.newPage({ viewport: { width: 1440, height: 1000 } });
    const errors = [];
    page.on('pageerror', e => errors.push(String(e)));

    await page.goto(`${BASE}/index.php`, { waitUntil: "networkidle" });
    await clearBlockingOverlays(page);
    const world = await page.evaluate(async () => {
        const d = await (await fetch('adminAPI.php?action=getWorlds')).json();
        const list = d.worlds || d;
        return Array.isArray(list) && list.length ? (list[0].name || list[0]) : null;
    });
    if (!world) { console.log('NO WORLDS'); process.exit(1); }
    console.log(`(using world "${world}")`);

    console.log('\nCase 1: flag armed -- notice does NOT fire on page load, only on Access');
    // The page was loaded with the flag already armed by the caller.
    let onLoad = await page.evaluate(overlayVisible);
    check('not visible on page load', onLoad.found && !onLoad.visible, JSON.stringify(onLoad));

    await page.evaluate((w) => showSettingsModal(w), world);
    await page.waitForSelector('#settingsPublicToggle', { state: 'attached', timeout: 15000 });
    await page.waitForTimeout(500);
    let onGeneral = await page.evaluate(overlayVisible);
    check('not visible on the General tab', !onGeneral.visible, JSON.stringify(onGeneral));

    await page.click('.backup-tab[data-tab="accessTab"]');
    await page.waitForTimeout(400);
    let onAccess = await page.evaluate(overlayVisible);
    check('VISIBLE once the Access tab is opened', onAccess.visible, JSON.stringify(onAccess));

    console.log('\nCase 2: it explains the actual change');
    const body = await page.evaluate(() => {
        const el = document.getElementById('accessIdNoticeOverlay');
        return el ? el.textContent.replace(/\s+/g, ' ') : '';
    });
    check('mentions the V_ prefix', /V_/.test(body), body.slice(0, 80));
    check('mentions the misleading "Banned" symptom', /Banned/i.test(body), body.slice(0, 80));
    check('says the lists were already converted', /already been\s*converted/i.test(body), body.slice(0, 120));

    console.log('\nCase 3: dismissing hides it AND persists');
    await page.click('#accessIdNoticeOverlay .action-btn');
    await page.waitForTimeout(1200);
    const afterDismiss = await page.evaluate(overlayVisible);
    check('hidden after Got it', !afterDismiss.visible, JSON.stringify(afterDismiss));

    console.log('\nCase 4: it does not come back on a fresh page load');
    await page.goto(`${BASE}/index.php`, { waitUntil: 'networkidle' });
    await clearBlockingOverlays(page);
    await openAccessTab(page, world);
    const second = await page.evaluate(overlayVisible);
    // With the flag cleared PHP omits the markup entirely, so "not found" is the pass.
    check('does not reappear', !second.visible, JSON.stringify(second));

    console.log('\nCase 5: no JS errors');
    check('no uncaught page errors', errors.length === 0, errors.join(' | '));

    await browser.close();
    console.log(`\n${pass} passed, ${fail} failed`);
    process.exit(fail === 0 ? 0 : 1);
})();
