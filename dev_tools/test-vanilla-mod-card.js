// Oracle test for: "Select Mods (Optional)" must not be available on a VANILLA world.
//
// This asserts on REAL RENDERED GEOMETRY in a real browser, not on markup existing.
// The old build hid only #modSelectionArea (the tables), leaving the card header and the
// clone-from-another-world control on screen -- so a test that merely checked "the tables
// are gone" would PASS against the bug. Every assertion here is on the visible box of the
// header text and the clone control.
//
// Run it against the OLD image first: it MUST fail case 2. That is the control.
//
// Usage (installs playwright to /tmp so it never lands in the repo):
//   docker run --rm --network host -v "$PWD/dev_tools":/w -w /w \
//     mcr.microsoft.com/playwright:v1.47.0-jammy bash -c \
//     'npm i --silent --prefix /tmp/pw playwright@1.47.0 >/dev/null 2>&1;
//      NODE_PATH=/tmp/pw/node_modules node test-vanilla-mod-card.js http://127.0.0.1:8081'
//
// The npm version MUST match the image tag or chromium fails to launch.
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

// Visible == has a non-zero box AND is not clipped away. offsetParent is null for
// display:none subtrees, which is exactly the state we care about.
const VISIBLE = (sel) => `(() => {
    const el = document.querySelector(${JSON.stringify(sel)});
    if (!el) return { found: false, visible: false, w: 0, h: 0 };
    const r = el.getBoundingClientRect();
    const cs = getComputedStyle(el);
    return {
        found: true,
        visible: el.offsetParent !== null && r.width > 0 && r.height > 0 && cs.visibility !== 'hidden',
        w: Math.round(r.width), h: Math.round(r.height)
    };
})()`;

// Find the card header by its TEXT, so a renamed/moved id cannot make this pass vacuously.
const HEADER_BY_TEXT = `(() => {
    const els = Array.from(document.querySelectorAll('.card-panel-header'));
    const el = els.find(e => /Select Mods/i.test(e.textContent));
    if (!el) return { found: false, visible: false };
    const r = el.getBoundingClientRect();
    return { found: true, visible: el.offsetParent !== null && r.height > 0, h: Math.round(r.height) };
})()`;

(async () => {
    const browser = await chromium.launch();
    const page = await browser.newPage({ viewport: { width: 1440, height: 1000 } });
    const errors = [];
    page.on('pageerror', e => errors.push(String(e)));

    await page.goto(`${BASE}/new_world.php`, { waitUntil: 'networkidle' });
    await page.waitForSelector('#vanillaWorld', { timeout: 15000 });
    // Let the mod DataTables finish loading (~9,000 rows) before measuring anything.
    await page.waitForFunction(
        () => document.querySelector('#availableModCount') &&
              parseInt(document.querySelector('#availableModCount').textContent, 10) > 0,
        { timeout: 60000 }
    ).catch(() => console.log('  (note) mod list never populated -- widths case will be skipped'));
    // The count badge updates BEFORE DataTables finishes drawing, so waiting on the badge
    // alone measures an empty tbody and reports a bug that is not there. Wait for rows.
    // Select the real table BY ID. DataTables inserts a header-clone <table> with no id
    // and no rows as the first table in the pane, so `pane.querySelector('table')` finds
    // the clone and reports 0 rows for a table that is fully populated.
    await page.waitForFunction(() => {
        const pane = Array.from(document.querySelectorAll('.mod-pane')).find(p => p.offsetParent !== null);
        const t = pane && pane.querySelector('table[id^="modtable-"]');
        return t && t.querySelectorAll('tbody tr').length > 1;
    }, { timeout: 60000 }).catch(() => console.log('  (note) visible mod table never drew rows'));

    // ---------------------------------------------------------------- case 0
    // Converting the two stacked mod tables into tabs made this page open on "Selected",
    // which on a world being created is empty BY DEFINITION -- it rendered "No data
    // available in table" and hid all ~11,000 mods behind a tab. It reads as "the mod
    // list is empty". Assert on the VISIBLE pane's real rows: a count badge or a hidden
    // table would both pass while the operator sees nothing.
    console.log('\nCase 0: on arrival, the catalogue is ON SCREEN without clicking anything');
    const landing = await page.evaluate(`(() => {
        const visiblePane = Array.from(document.querySelectorAll('.mod-pane'))
            .find(p => p.offsetParent !== null);
        if (!visiblePane) return { pane: null };
        const table = visiblePane.querySelector('table[id^="modtable-"]');
        const bodyText = table ? (table.querySelector('tbody') || {}).innerText || '' : '';
        const activeTab = document.querySelector('#modTabBar .pv-tab.active');
        return {
            pane: visiblePane.id,
            tab: activeTab ? activeTab.textContent.replace(/\\s+/g, ' ').trim() : null,
            rows: table ? table.querySelectorAll('tbody tr').length : 0,
            placeholder: /No data available|No matching records/i.test(bodyText)
        };
    })()`);
    check('a mod pane is visible on arrival', !!landing.pane, JSON.stringify(landing));
    check('it shows real mod rows, not the empty placeholder',
        landing.rows > 1 && !landing.placeholder, JSON.stringify(landing));

    console.log('\nCase 1: MODDED (vanilla unchecked) -- mod selection is offered');
    let hdr = await page.evaluate(HEADER_BY_TEXT);
    let clone = await page.evaluate(VISIBLE('#copyFromWorld'));
    let tabs = await page.evaluate(VISIBLE('#modTabBar'));
    let notice = await page.evaluate(VISIBLE('#vanillaNoModsNotice'));
    check('"Select Mods" header visible', hdr.found && hdr.visible, JSON.stringify(hdr));
    check('mod tab bar visible', tabs.visible, JSON.stringify(tabs));
    check('vanilla no-mods notice hidden', !notice.visible, JSON.stringify(notice));

    const widthsBefore = await page.evaluate(() => {
        const t = document.querySelector('#modtable-available');
        return t ? Math.round(t.getBoundingClientRect().width) : 0;
    });

    console.log('\nCase 2: VANILLA checked -- NOTHING mod-related is on screen');
    await page.check('#vanillaWorld');
    await page.waitForTimeout(300);
    hdr = await page.evaluate(HEADER_BY_TEXT);
    clone = await page.evaluate(VISIBLE('#copyFromWorld'));
    const cloneBtn = await page.evaluate(VISIBLE('#copyButton'));
    tabs = await page.evaluate(VISIBLE('#modTabBar'));
    notice = await page.evaluate(VISIBLE('#vanillaNoModsNotice'));
    const cfgBox = await page.evaluate(VISIBLE('#cloneCustomConfigs'));
    check('"Select Mods" header NOT visible', !hdr.visible, JSON.stringify(hdr));
    check('clone-from-world select NOT visible', !clone.visible, JSON.stringify(clone));
    check('clone button NOT visible', !cloneBtn.visible, JSON.stringify(cloneBtn));
    check('"also clone custom_configs" NOT visible', !cfgBox.visible, JSON.stringify(cfgBox));
    check('mod tab bar NOT visible', !tabs.visible, JSON.stringify(tabs));
    check('vanilla no-mods notice IS visible', notice.visible, JSON.stringify(notice));

    console.log('\nCase 3: back to MODDED -- everything returns, columns re-measured');
    await page.uncheck('#vanillaWorld');
    await page.waitForTimeout(400);
    hdr = await page.evaluate(HEADER_BY_TEXT);
    clone = await page.evaluate(VISIBLE('#copyFromWorld'));
    notice = await page.evaluate(VISIBLE('#vanillaNoModsNotice'));
    check('"Select Mods" header visible again', hdr.visible, JSON.stringify(hdr));
    check('clone-from-world select visible again', clone.visible, JSON.stringify(clone));
    check('vanilla no-mods notice hidden again', !notice.visible, JSON.stringify(notice));

    const widthsAfter = await page.evaluate(() => {
        const t = document.querySelector('#modtable-available');
        return t ? Math.round(t.getBoundingClientRect().width) : 0;
    });
    if (widthsBefore > 0) {
        // A DataTable measured inside display:none comes back collapsed. Allow 2px slop.
        check('mod table width not collapsed after hide/show',
            Math.abs(widthsAfter - widthsBefore) <= 2,
            `before=${widthsBefore} after=${widthsAfter}`);
    }

    console.log('\nCase 4: no JS errors');
    check('no uncaught page errors', errors.length === 0, errors.join(' | '));

    await browser.close();
    console.log(`\n${pass} passed, ${fail} failed`);
    process.exit(fail === 0 ? 0 : 1);
})();
