// Oracle test: toggling a mod checkbox must not move the operator in the list.
//
// THE BUG THIS CATCHES: every toggle rebuilds both DataTables from checkedSet (a selection
// changes other rows' badges and ordering), and the rebuild called a bare .draw(). In
// DataTables, .draw() means .draw(true) -- "reset paging" -- so every single click sent the
// operator back to page 1 of 11,600+ mods. Replacing the rows also resets the scroll body's
// scrollTop even when the page is held, so both have to be preserved.
//
// WHY THIS TEST CAN SEE IT: it navigates to a LATER PAGE and scrolls DOWN before clicking,
// then asserts the page index and scroll offset are unchanged afterwards. A test that clicks
// a checkbox on page 1 at scroll 0 passes under the bug -- there is nowhere to be sent back
// from. It also asserts the checkbox actually ended up checked, so "preserved position"
// cannot be satisfied by the click silently doing nothing.
//
// Usage:
//   docker run --rm --network host -v "$PWD/dev_tools":/w -w /w \
//     mcr.microsoft.com/playwright:v1.47.0-jammy bash -c \
//     'npm i --silent --prefix /tmp/pw playwright@1.47.0 >/dev/null 2>&1;
//      NODE_PATH=/tmp/pw/node_modules node test-picker-position.js http://127.0.0.1:8081'

const { chromium } = require('playwright');
const BASE = process.argv[2] || 'http://127.0.0.1:8081';
// Both pickers are the same code in two files, so both must be checked -- patching one and
// assuming the other matched is how they drift.
const PAGE = process.argv[3] || 'new_world.php';

let pass = 0, fail = 0;
function check(name, ok, detail) {
    if (ok) { pass++; console.log(`  PASS  ${name}`); }
    else { fail++; console.log(`  FAIL  ${name}${detail ? ' -- ' + detail : ''}`); }
}

// Read the "All / Available" table's paging + scroll state straight from DataTables.
const readState = (page) => page.evaluate(() => {
    const dt = window.jQuery('#modtable-all').DataTable();
    const info = dt.page.info();
    const body = window.jQuery(dt.table().container()).find('.dataTables_scrollBody');
    return {
        page: info.page,
        pages: info.pages,
        scrollTop: Math.round(body.scrollTop()),
        rowCount: dt.rows().count(),
        firstRowName: (dt.row(0).data() || [])[1] || '',
    };
});

(async () => {
    const browser = await chromium.launch();
    const page = await browser.newPage({ viewport: { width: 1400, height: 900 } });
    const errors = [];
    page.on('pageerror', e => errors.push(String(e)));
    page.on('console', m => { if (m.type() === 'error') errors.push(m.text()); });

    console.log(`\n=== mod picker keeps its position on toggle (${BASE}/${PAGE}) ===`);
    await page.goto(`${BASE}/${PAGE}`, { waitUntil: 'networkidle', timeout: 90000 });
    await page.waitForFunction(
        () => window.jQuery && window.jQuery.fn.DataTable
              && window.jQuery('#modtable-all tbody tr').length > 3,
        { timeout: 60000 });
    check('no uncaught JS errors on load', errors.length === 0, errors.slice(0, 2).join(' | '));

    const initial = await readState(page);
    check('the available-mods table is paginated', initial.pages > 2,
        `${initial.rowCount} rows over ${initial.pages} page(s)`);
    if (initial.pages < 3) {
        console.log('  SKIP  too few mods to page through; this test cannot see the bug');
        await browser.close();
        process.exit(0);
    }

    // Move somewhere the bug is visible: a later page, scrolled down.
    await page.evaluate(() => window.jQuery('#modtable-all').DataTable().page(3).draw(false));
    await page.waitForTimeout(400);
    await page.evaluate(() => {
        const dt = window.jQuery('#modtable-all').DataTable();
        window.jQuery(dt.table().container()).find('.dataTables_scrollBody').scrollTop(160);
    });
    await page.waitForTimeout(300);

    const before = await readState(page);
    check('moved to a later page and scrolled down',
        before.page === 3 && before.scrollTop > 100,
        `page=${before.page} scrollTop=${before.scrollTop}`);

    // The target must be FULLY VISIBLE at the current scroll offset.
    //
    // page.click() scrolls its target into view first. Picking a fixed row (say nth-child(3))
    // means that at scrollTop=160 the row sits ABOVE the viewport, so Playwright scrolls the
    // body back to 0 before the click even happens -- and then the assertion blames the
    // product for a reset the test itself caused. Choosing a row already on screen keeps the
    // click from moving anything.
    const target = await page.evaluate(() => {
        const dt = window.jQuery('#modtable-all').DataTable();
        const body = window.jQuery(dt.table().container()).find('.dataTables_scrollBody')[0];
        const top = body.scrollTop, bottom = top + body.clientHeight;
        for (const tr of Array.from(body.querySelectorAll('tbody tr'))) {
            const cb = tr.querySelector('.mod-checkbox');
            if (!cb) continue;
            const rt = tr.offsetTop, rb = rt + tr.offsetHeight;
            if (rt > top + 20 && rb < bottom - 20) {
                return { uuid: cb.dataset.uuid, wasChecked: cb.checked };
            }
        }
        return null;
    });
    check('found a fully-visible checkbox to click', target !== null,
        'no row sits entirely inside the scroll viewport');
    if (!target) { await browser.close(); process.exit(1); }

    // Measured immediately before the click, so any residual auto-scroll is in the baseline.
    const scrollAtClick = await page.evaluate(() => {
        const dt = window.jQuery('#modtable-all').DataTable();
        return Math.round(window.jQuery(dt.table().container())
            .find('.dataTables_scrollBody').scrollTop());
    });
    await page.click(`#modtable-all tbody tr .mod-checkbox[data-uuid="${target.uuid}"]`);
    // The rebuild is synchronous but give the redraw a frame to settle.
    await page.waitForTimeout(600);

    const after = await readState(page);

    // ---- the oracle ----
    check('the table stayed on the same page after toggling',
        after.page === before.page,
        `was page ${before.page}, now page ${after.page}` +
        (after.page === 0 ? ' (reset to the first page -- this is the reported bug)' : ''));

    // Scroll is restored to within a row's height; exact equality is too strict because the
    // row set changes (a selected mod moves to the top group) and can shorten the body.
    check('the scroll position was preserved',
        Math.abs(after.scrollTop - scrollAtClick) <= 40,
        `was ${scrollAtClick}px at click, now ${after.scrollTop}px` +
        (after.scrollTop === 0 ? ' (snapped to the top)' : ''));

    // And the click must actually have done its job -- otherwise "position preserved" is
    // trivially true for a no-op.
    const nowChecked = await page.evaluate(
        u => { const set = (typeof checkedSet !== 'undefined') ? checkedSet : {}; return !!set[u]; },
        target.uuid);
    check('the mod is now selected', nowChecked !== target.wasChecked || nowChecked === true,
        `checkedSet[${target.uuid}]=${nowChecked}`);

    // Unchecking must behave the same way. Navigate to a later page FIRST: under the bug the
    // previous toggle already dumped us on page 0, and comparing 0 to 0 passes while proving
    // nothing -- the assertion has to start somewhere it can fall from.
    await page.evaluate(() => window.jQuery('#modtable-all').DataTable().page(2).draw(false));
    await page.waitForTimeout(400);
    const beforeUncheck = await readState(page);
    check('moved to a later page before the uncheck leg', beforeUncheck.page === 2,
        `page=${beforeUncheck.page}`);
    const cbSel = `#modtable-all tbody tr .mod-checkbox[data-uuid="${target.uuid}"]`;
    if (await page.$(cbSel)) {
        await page.click(cbSel);
        await page.waitForTimeout(600);
        const afterUncheck = await readState(page);
        check('deselecting also keeps the page',
            afterUncheck.page === beforeUncheck.page,
            `was ${beforeUncheck.page}, now ${afterUncheck.page}`);
    } else {
        console.log('  note: selected row moved out of view; skipping the uncheck leg');
    }

    // ---- checkbox sizing ----
    const box = await page.evaluate(() => {
        const cb = document.querySelector('#modtable-all .mod-checkbox');
        if (!cb) return null;
        const r = cb.getBoundingClientRect();
        return { w: Math.round(r.width), h: Math.round(r.height) };
    });
    check('mod checkboxes render at the smaller size',
        box && box.w <= 15 && box.w >= 12 && box.h <= 15,
        JSON.stringify(box));

    // A settings checkbox must NOT have shrunk -- the restyle is scoped to the picker.
    const other = await page.evaluate(() => {
        const cb = document.querySelector('input[type="checkbox"]:not(.mod-checkbox)');
        if (!cb) return null;
        const r = cb.getBoundingClientRect();
        return { w: Math.round(r.width) };
    });
    // Scope check, phrased as a COMPARISON rather than a fixed floor. The classless
    // "Also clone ..." inputs render 15px from a pre-existing rule that declares 16px, so a
    // flat `>= 16` threshold fails on untouched markup and reads as a leak that is not there.
    // What actually matters is that the picker box is smaller than checkboxes elsewhere --
    // which is false both if the restyle leaked and if it never applied.
    if (other && other.w > 0 && box) {
        check('the picker box is smaller than checkboxes elsewhere on the page',
            box.w < other.w,
            `picker=${box.w}px other=${other.w}px`);
    }

    check('still no uncaught JS errors', errors.length === 0, errors.slice(0, 2).join(' | '));

    console.log(`\n  ${pass} passed, ${fail} failed`);
    await browser.close();
    process.exit(fail ? 1 : 0);
})().catch(e => { console.error('HARNESS ERROR', e); process.exit(2); });
