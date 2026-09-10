// Oracle test for the mod browser's "All" tab.
//
// Contract:
//   1. "All" is the landing tab on BOTH new_world.php and edit_world.php.
//   2. There is no "Available" tab any more.
//   3. "All" contains EVERY mod -- selected ones included, not just the leftovers.
//   4. Selected mods sit at the TOP of "All".
//
// (3) is the one a lazy test gets wrong: the old "Available" table was also full of
// rows, so "the table has rows" passes against both designs. The assertion that
// separates them is that a mod which is CHECKED still appears in All -- and near the top.
//
// Usage:
//   docker run --rm --network host -v "$PWD/dev_tools":/w -w /w \
//     mcr.microsoft.com/playwright:v1.47.0-jammy bash -c \
//     'npm i --silent --prefix /tmp/pw playwright@1.47.0 >/dev/null 2>&1;
//      NODE_PATH=/tmp/pw/node_modules node test-mod-all-tab.js http://127.0.0.1:8081 [world]'

const { chromium } = require('playwright');
const BASE = process.argv[2] || 'http://127.0.0.1:8081';
const EDIT_WORLD = process.argv[3] || null;

let pass = 0, fail = 0;
function check(name, ok, detail) {
    if (ok) { pass++; console.log(`  PASS  ${name}`); }
    else { fail++; console.log(`  FAIL  ${name}${detail ? ' -- ' + detail : ''}`); }
}

// DataTables clones the table for its fixed header -- that clone has no id and no rows.
// Always select the real one by id, or every row count reads 0.
const TABLE = 'table[id^="modtable-"]';

// "Selected on top" over the rows of the CURRENT page. Two shapes count as correct:
//   - some checked, some not, and every checked row precedes every unchecked one
//   - the page is ENTIRELY checked rows (firstUnchecked === -1) -- which is what you get
//     when the selection is bigger than one page. Ticking one mod cascade-selects its
//     dependencies, so a single click can easily fill page 1.
// Treating the all-checked page as a failure marked a correct result as a bug.
function selectedAreOnTop(info) {
    if (info.checkedCount === 0) return false;
    if (info.firstUnchecked === -1) return true;
    return info.lastChecked >= 0 && info.lastChecked < info.firstUnchecked;
}

async function waitForRows(page) {
    await page.waitForFunction((sel) => {
        const pane = Array.from(document.querySelectorAll('.mod-pane')).find(p => p.offsetParent !== null);
        const t = pane && pane.querySelector(sel);
        return t && t.querySelectorAll('tbody tr').length > 1;
    }, TABLE, { timeout: 60000 }).catch(() => console.log('  (note) table never drew rows'));
}

const landingState = (sel) => `(() => {
    const tabs = Array.from(document.querySelectorAll('#modTabBar .pv-tab'))
        .map(t => ({ label: t.textContent.replace(/\\s+/g,' ').trim(), active: t.classList.contains('active') }));
    const pane = Array.from(document.querySelectorAll('.mod-pane')).find(p => p.offsetParent !== null);
    const table = pane && pane.querySelector(${JSON.stringify(sel)});
    return {
        tabs,
        visiblePane: pane ? pane.id : null,
        rows: table ? table.querySelectorAll('tbody tr').length : 0
    };
})()`;

async function runPage(page, label, url, expectSelected) {
    console.log(`\n=== ${label} : ${url} ===`);
    await page.goto(url, { waitUntil: 'networkidle', timeout: 60000 });
    await page.waitForSelector('#modTabBar', { timeout: 20000 });
    await waitForRows(page);

    const s = await page.evaluate(landingState(TABLE));
    const activeTab = s.tabs.find(t => t.active);

    check(`${label}: lands on the All tab`,
        !!activeTab && /^All\b/.test(activeTab.label) && s.visiblePane === 'modPaneAll',
        JSON.stringify(s));
    check(`${label}: no "Available" tab remains`,
        !s.tabs.some(t => /Available/i.test(t.label)),
        JSON.stringify(s.tabs));
    check(`${label}: All is populated on arrival`, s.rows > 1, JSON.stringify(s));

    // --- the discriminating assertion -------------------------------------------
    // Read the checkbox state of the rows actually rendered in All. Under the old
    // "Available" design NONE of them could be checked, because checked mods were
    // filtered out into the other table.
    const rowsInfo = await page.evaluate((sel) => {
        const pane = Array.from(document.querySelectorAll('.mod-pane')).find(p => p.offsetParent !== null);
        const t = pane.querySelector(sel);
        const rows = Array.from(t.querySelectorAll('tbody tr'));
        const checked = rows.map(r => {
            const cb = r.querySelector('input.mod-checkbox');
            return cb ? cb.checked : null;
        });
        return {
            total: rows.length,
            checkedCount: checked.filter(Boolean).length,
            // index of the last checked row and the first unchecked one: if selected
            // are pinned to the top, every checked row precedes every unchecked row.
            lastChecked: checked.lastIndexOf(true),
            firstUnchecked: checked.indexOf(false)
        };
    }, TABLE);

    if (expectSelected) {
        check(`${label}: All CONTAINS selected mods (not just leftovers)`,
            rowsInfo.checkedCount > 0, JSON.stringify(rowsInfo));
        check(`${label}: selected mods are at the TOP`,
            selectedAreOnTop(rowsInfo), JSON.stringify(rowsInfo));
    } else {
        console.log(`    (nothing selected on this page yet -- ordering checked below)`);
    }
    return rowsInfo;
}

(async () => {
    const browser = await chromium.launch();
    const page = await browser.newPage({ viewport: { width: 1440, height: 1000 } });
    const errors = [];
    page.on('pageerror', e => errors.push(String(e)));

    // --- new_world.php: nothing selected initially, so SELECT one and re-check ---
    await runPage(page, 'new_world', `${BASE}/new_world.php`, false);

    console.log('\nTicking the first mod in All, then re-checking the order');
    await page.evaluate((sel) => {
        const pane = Array.from(document.querySelectorAll('.mod-pane')).find(p => p.offsetParent !== null);
        // Tick something well down the list so "it was already first" cannot explain a pass.
        const rows = pane.querySelectorAll(sel + ' tbody tr');
        const cb = rows[Math.min(7, rows.length - 1)].querySelector('input.mod-checkbox');
        cb.click();
    }, TABLE);
    await page.waitForTimeout(1200);

    const after = await page.evaluate((sel) => {
        const pane = Array.from(document.querySelectorAll('.mod-pane')).find(p => p.offsetParent !== null);
        const rows = Array.from(pane.querySelectorAll(sel + ' tbody tr'));
        const checked = rows.map(r => { const cb = r.querySelector('input.mod-checkbox'); return cb ? cb.checked : null; });
        return { total: rows.length, checkedCount: checked.filter(Boolean).length,
                 lastChecked: checked.lastIndexOf(true), firstUnchecked: checked.indexOf(false) };
    }, TABLE);
    check('new_world: the ticked mod stays in All', after.checkedCount > 0, JSON.stringify(after));
    check('new_world: it moved to the TOP of All', selectedAreOnTop(after), JSON.stringify(after));

    // --- edit_world.php: a world with an existing selection ---------------------
    if (EDIT_WORLD) {
        await runPage(page, 'edit_world', `${BASE}/edit_world.php?world=${encodeURIComponent(EDIT_WORLD)}`, true);
    } else {
        console.log('\n(no world given -- skipping edit_world.php; pass one as argv[3])');
    }

    console.log('\nNo JS errors');
    check('no uncaught page errors', errors.length === 0, errors.join(' | '));

    await browser.close();
    console.log(`\n${pass} passed, ${fail} failed`);
    process.exit(fail === 0 ? 0 : 1);
})();
