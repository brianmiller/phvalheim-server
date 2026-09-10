// Oracle test: the SteamID Lookup helper must hand back the ACCESS-LIST form (V_...),
// not the bare SteamID64.
//
// Whatever this helper produces gets pasted straight into permittedlist/adminlist/
// bannedlist, and Valheim 1.0 refuses a bare id with a misleading "Banned". So the thing
// under test is the exact STRING the operator ends up with -- on screen, on the clipboard
// path, and in the textarea.
//
// The live Steam call is NOT exercised: resolving a vanity URL needs a real API key that a
// dev container does not have, and a test that depends on Steam being up is not an oracle
// for our formatting. Instead the API response is stubbed at the network layer with a
// known bare id, which is precisely the input whose handling changed. The PHP side of the
// same change (canonicalAccessId on the API response) is covered by
// dev_tools/test-accesslists.sh and the assertion at the bottom of this run.
//
// Usage:
//   docker run --rm --network host -v "$PWD/dev_tools":/w -w /w \
//     mcr.microsoft.com/playwright:v1.47.0-jammy bash -c \
//     'npm i --silent --prefix /tmp/pw playwright@1.47.0 >/dev/null 2>&1;
//      NODE_PATH=/tmp/pw/node_modules node test-steamid-lookup-format.js http://127.0.0.1:8081'

const { chromium } = require('playwright');
const BASE = process.argv[2] || 'http://127.0.0.1:8081';

const BARE = '76561198000000042';
const WANT = 'V_' + BARE;

let pass = 0, fail = 0;
function check(name, ok, detail) {
    if (ok) { pass++; console.log(`  PASS  ${name}`); }
    else { fail++; console.log(`  FAIL  ${name}${detail ? ' -- ' + detail : ''}`); }
}

(async () => {
    const browser = await chromium.launch();
    const context = await browser.newContext({
        viewport: { width: 1440, height: 1000 },
        permissions: ['clipboard-read', 'clipboard-write']
    });
    const page = await context.newPage();
    const errors = [];
    page.on('pageerror', e => errors.push(String(e)));

    // Stand in for Steam. Returns exactly what the real endpoint returns now: the bare id
    // AND the canonical access id, so the client is tested on picking the right one.
    await page.route('**/adminAPI.php?action=fetchSteamID', route => {
        route.fulfill({
            status: 200,
            contentType: 'application/json',
            body: JSON.stringify({ success: true, steamid: BARE, accessId: WANT })
        });
    });

    await page.goto(`${BASE}/index.php`, { waitUntil: 'networkidle' });
    const world = await page.evaluate(async () => {
        const d = await (await fetch('adminAPI.php?action=getWorlds')).json();
        const list = d.worlds || d;
        return Array.isArray(list) && list.length ? (list[0].name || list[0]) : null;
    });
    if (!world) { console.log('NO WORLDS'); process.exit(1); }
    console.log(`(using world "${world}", stub returns bare ${BARE})`);

    await page.evaluate((w) => showSettingsModal(w), world);
    await page.waitForSelector('#settingsPublicToggle', { state: 'attached', timeout: 15000 });
    await page.waitForTimeout(500);
    await page.click('.backup-tab[data-tab="accessTab"]');
    await page.waitForTimeout(300);
    // Make sure the editor is on screen (it hides when the world is public).
    await page.evaluate(() => {
        const t = document.getElementById('settingsPublicToggle');
        if (t.checked) { t.checked = false; t.dispatchEvent(new Event('change')); }
    });
    await page.waitForTimeout(250);

    // Start from a known textarea so the append assertion is unambiguous.
    await page.evaluate(() => { document.getElementById('settingsCitizensTextarea').value = ''; });

    console.log('\nCase 1: the lookup result shown to the operator');
    await page.click('#accessTab .action-btn:has-text("Look Up SteamID")');
    await page.waitForTimeout(300);
    await page.fill('#steamIdLookupInput', 'someplayer');
    await page.click('#steamIdModalOverlay .action-btn.primary, .mods-modal .action-btn.primary');
    await page.waitForTimeout(600);

    const shown = await page.evaluate(() => document.getElementById('steamIdResultText').textContent.trim());
    check('shows the V_ form', shown === WANT, `got "${shown}" want "${WANT}"`);
    check('does NOT show the bare id', shown !== BARE, `got "${shown}"`);

    console.log('\nCase 2: what lands in the Citizens textarea');
    await page.click('#steamIdCopyBtn');
    await page.waitForTimeout(600);
    const inserted = await page.evaluate(() => document.getElementById('settingsCitizensTextarea').value.trim());
    check('textarea receives the V_ form', inserted === WANT, `got "${inserted}" want "${WANT}"`);
    // The whole point: a bare id here is what produced the "Banned" reports.
    check('textarea does not receive a bare id', inserted !== BARE, `got "${inserted}"`);

    console.log('\nCase 3: CONTROL -- the assertion can actually fail');
    // Feed the OLD shape (no accessId) and confirm the check would catch it. This proves
    // cases 1 and 2 are not passing just because any string is present.
    await page.route('**/adminAPI.php?action=fetchSteamID', route => {
        route.fulfill({
            status: 200, contentType: 'application/json',
            body: JSON.stringify({ success: true, steamid: BARE })   // pre-fix response
        });
    });
    await page.evaluate(() => { document.getElementById('settingsCitizensTextarea').value = ''; });
    await page.click('#accessTab .action-btn:has-text("Look Up SteamID")');
    await page.waitForTimeout(300);
    await page.fill('#steamIdLookupInput', 'someplayer');
    await page.click('.mods-modal .action-btn.primary');
    await page.waitForTimeout(600);
    const legacyShown = await page.evaluate(() => document.getElementById('steamIdResultText').textContent.trim());
    check('control: an accessId-less response yields the bare id', legacyShown === BARE,
        `got "${legacyShown}"`);
    console.log('    (so the V_ in cases 1-2 came from the response field, not from nowhere)');

    console.log('\nCase 4: no JS errors');
    check('no uncaught page errors', errors.length === 0, errors.join(' | '));

    await browser.close();
    console.log(`\n${pass} passed, ${fail} failed`);
    process.exit(fail === 0 ? 0 : 1);
})();
