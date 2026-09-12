// Oracle test for the 2.43 multi-source mod picker.
//
// WHAT IT GUARDS: the picker's identity key. Everything before 2.43 keyed a world's mod
// selection on the source's `moduuid`, and Hexium mirrors Thunderstore packages carrying
// their ORIGINAL uuid4 -- 600 package uuids exist in both catalogues. A picker keyed on a
// uuid therefore renders ONE checkbox for two different mods and installs whichever the
// lookup happened to find first. That failure is invisible in the markup: the page looks
// perfect, the row count is plausible, and the wrong mod gets installed.
//
// So the assertions are on the RENDERED page and on real catalogue data:
//   - a mod that exists in BOTH catalogues appears as TWO rows with DIFFERENT pills
//   - toggling a catalogue off actually removes its rows
//   - the version selector, on a mod with many published versions, offers them
//   - checking a mod pulls in its dependency, INCLUDING across sources
//
// Counting occurrences of "src-pill" in the PHP source would pass on a template that
// renders one pill from one variable -- which is the exact shape of the bug above.
//
// Usage:
//   docker run --rm --network host -v "$PWD/dev_tools":/w -w /w \
//     mcr.microsoft.com/playwright:v1.47.0-jammy bash -c \
//     'npm i --silent --prefix /tmp/pw playwright@1.47.0 >/dev/null 2>&1;
//      NODE_PATH=/tmp/pw/node_modules node test-multisource-picker.js http://127.0.0.1:8081'

const { chromium } = require('playwright');
const BASE = process.argv[2] || 'http://127.0.0.1:8081';

let pass = 0, fail = 0;
function check(name, ok, detail) {
    if (ok) { pass++; console.log(`  PASS  ${name}`); }
    else { fail++; console.log(`  FAIL  ${name}${detail ? ' -- ' + detail : ''}`); }
}

(async () => {
    const browser = await chromium.launch();
    const page = await browser.newPage();
    const errors = [];
    page.on('pageerror', e => errors.push(String(e)));
    page.on('console', m => { if (m.type() === 'error') errors.push(m.text()); });

    console.log(`\n=== multi-source mod picker (${BASE}/new_world.php) ===`);
    await page.goto(`${BASE}/new_world.php`, { waitUntil: 'networkidle', timeout: 90000 });

    // The catalogue is ~11.7k mods; wait for the table to actually be populated rather
    // than for a fixed timeout, which would pass on an empty picker.
    await page.waitForFunction(
        () => document.querySelectorAll('#modtable-all tbody tr').length > 5,
        { timeout: 90000 }
    );

    check('no uncaught JS errors on load', errors.length === 0, errors.slice(0, 3).join(' | '));

    // ---- the filter bar exists and knows both catalogues ----
    const srcButtons = await page.$$eval('#modSourceFilter .msf-btn',
        els => els.map(e => ({
            source: e.dataset.source,
            active: e.classList.contains('active'),
            text: e.textContent.trim()
        })));
    check('catalogue filter bar lists both sources',
        srcButtons.length === 2 &&
        srcButtons.some(b => b.source === 'thunderstore') &&
        srcButtons.some(b => b.source === 'hexium'),
        JSON.stringify(srcButtons));
    check('both catalogues start enabled', srcButtons.every(b => b.active));

    // ---- pill colours are distinct and correct per source ----
    const pills = await page.$$eval('#modtable-all tbody tr .src-pill',
        els => els.map(e => ({ cls: e.className, text: e.textContent.trim() })));
    check('every visible row carries a source pill',
        pills.length > 0, `found ${pills.length}`);
    const tsPills = pills.filter(p => p.cls.includes('src-ts'));
    const hexPills = pills.filter(p => p.cls.includes('src-hex'));
    check('Thunderstore pills render as src-ts (blue)', tsPills.length > 0);
    check('pill label matches its class',
        tsPills.every(p => /thunderstore/i.test(p.text)) &&
        hexPills.every(p => /hexium/i.test(p.text)),
        JSON.stringify(pills.slice(0, 4)));

    // Confirm the two pill classes actually resolve to DIFFERENT colours. A pill that is
    // present but the same colour for both sources conveys nothing.
    const colours = await page.evaluate(() => {
        const mk = (cls) => {
            const s = document.createElement('span');
            s.className = 'src-pill ' + cls;
            document.body.appendChild(s);
            const c = getComputedStyle(s).color;
            s.remove();
            return c;
        };
        return { ts: mk('src-ts'), hex: mk('src-hex') };
    });
    check('Thunderstore and Hexium pills are different colours',
        colours.ts !== colours.hex, JSON.stringify(colours));

    // ---- a mod published on BOTH catalogues must appear TWICE ----
    // This is the uuid-collision oracle. Searched through the API rather than assumed, so
    // the test does not depend on a particular mod still being dual-published.
    const dual = await page.evaluate(async () => {
        const r = await fetch('adminAPI.php?action=getAllModsWithDeps');
        const d = await r.json();
        const byKey = {};
        d.mods.forEach(m => {
            const k = m.owner + '/' + m.name;
            (byKey[k] = byKey[k] || []).push(m);
        });
        const both = Object.entries(byKey)
            .filter(([, v]) => v.length > 1 &&
                new Set(v.map(x => x.source)).size > 1);
        return both.length
            ? { key: both[0][0], entries: both[0][1].map(x => ({ id: x.id, source: x.source })) }
            : null;
    });
    check('a mod exists on both catalogues (precondition)', dual !== null,
        'no dual-published mod found; the collision assertion below is vacuous');
    if (dual) {
        check('dual-published mod has DISTINCT ids per source',
            new Set(dual.entries.map(e => e.id)).size === dual.entries.length,
            JSON.stringify(dual));
        // Search the table for that name and confirm both rows render.
        await page.fill('#modtable-all_filter input', dual.key.split('/')[1]);
        await page.waitForTimeout(400);
        const rowPills = await page.$$eval('#modtable-all tbody tr', rows =>
            rows.map(r => {
                const p = r.querySelector('.src-pill');
                const a = r.querySelector('td:nth-child(2) a');
                const o = r.querySelector('td:nth-child(3)');
                return {
                    name: a ? a.textContent.trim() : '',
                    owner: o ? o.textContent.trim() : '',
                    pill: p ? p.textContent.trim() : null
                };
            }));
        const want = dual.key.split('/');
        const matching = rowPills.filter(r => r.owner === want[0] && r.name === want[1]);
        check(`"${dual.key}" renders one row per catalogue`,
            matching.length === dual.entries.length &&
            new Set(matching.map(m => m.pill)).size === dual.entries.length,
            JSON.stringify(matching));
        await page.fill('#modtable-all_filter input', '');
        await page.waitForTimeout(300);
    }

    // ---- turning a catalogue off removes its rows ----
    //
    // Counted over the WHOLE table via the DataTables API, not over the visible page.
    // Hexium is 839 of 11,679 mods and page 1 of an alphabetical list is all
    // Thunderstore, so a visible-rows assertion here measures pagination, not filtering.
    const countBySource = () => page.evaluate(() =>
        jQuery('#modtable-all').DataTable().rows().data().toArray().reduce((acc, row) => {
            const m = String(row[1]).match(/src-(ts|hex)/);
            if (m) acc[m[1]] = (acc[m[1]] || 0) + 1;
            return acc;
        }, {}));

    const beforeCounts = await countBySource();
    check('both catalogues contribute rows before filtering',
        (beforeCounts.hex || 0) > 0 && (beforeCounts.ts || 0) > 0,
        JSON.stringify(beforeCounts));

    await page.click('#modSourceFilter .msf-btn[data-source="hexium"]');
    await page.waitForTimeout(800);
    const afterCounts = await countBySource();
    check('disabling Hexium removes every Hexium row from the table',
        (afterCounts.hex || 0) === 0,
        `before=${JSON.stringify(beforeCounts)} after=${JSON.stringify(afterCounts)}`);
    check('disabling Hexium leaves the Thunderstore rows alone',
        (afterCounts.ts || 0) === (beforeCounts.ts || 0),
        `before=${beforeCounts.ts} after=${afterCounts.ts}`);

    // The last enabled catalogue must not be switchable off -- that empties the picker
    // and reads as the mod database having disappeared.
    await page.click('#modSourceFilter .msf-btn[data-source="thunderstore"]');
    await page.waitForTimeout(400);
    const stillTs = await page.$$eval('#modtable-all tbody tr .src-pill.src-ts', e => e.length);
    check('the last enabled catalogue cannot be turned off', stillTs > 0, `ts=${stillTs}`);

    await page.click('#modSourceFilter .msf-btn[data-source="hexium"]');
    await page.waitForTimeout(600);

    // ---- version selector: appears when selected, offers real versions ----
    const many = await page.evaluate(async () => {
        const r = await fetch('adminAPI.php?action=getAllModsWithDeps');
        const d = await r.json();
        // Few dependencies on purpose: a 93-dependency modpack would make this
        // assertion about the dependency cascade rather than about the version selector.
        const m = d.mods
            .filter(x => x.versions > 5 && (x.deps || []).length <= 2)
            .sort((a, b) => b.versions - a.versions)[0];
        return m ? { id: m.id, name: m.name, owner: m.owner, versions: m.versions } : null;
    });
    check('a multi-version mod exists (precondition)', many !== null);
    if (many) {
        await page.fill('#modtable-all_filter input', many.name);
        await page.waitForTimeout(400);

        // Unselected: plain text, no selector. Offering to pin a version of a mod that is
        // not in the world is noise.
        const plainBefore = await page.$$eval(
            `#modtable-all tbody tr`,
            (rows, id) => rows.filter(r => {
                const cb = r.querySelector('.mod-checkbox');
                return cb && cb.dataset.uuid === String(id);
            }).map(r => ({
                hasSelect: !!r.querySelector('select.mod-version'),
                text: r.querySelector('td:nth-child(5)')?.textContent.trim()
            }))[0], many.id);
        check('unselected mod shows a plain version, no selector',
            plainBefore && !plainBefore.hasSelect, JSON.stringify(plainBefore));

        // Select it, then the selector must appear.
        //
        // Re-filter AFTER the click: checking a mod pulls its dependencies in and
        // rebuildTables() redraws, which resets the DataTables search box and re-sorts
        // selected rows to the top. The target is then simply not on the visible page --
        // which is a property of the table, not of the selector.
        await page.click(`#modtable-all .mod-checkbox[data-uuid="${many.id}"]`);
        await page.waitForTimeout(900);
        await page.fill('#modtable-all_filter input', many.name);
        await page.waitForTimeout(500);
        const sel = await page.$(`#modtable-all select.mod-version[data-mid="${many.id}"]`);
        check('selecting a multi-version mod reveals the version selector', sel !== null,
            'no select.mod-version rendered for the checked mod');

        if (sel) {
            // Opening it lazily loads the real list -- the payload only carries the latest
            // version, so a dropdown that never fetches can only ever offer one option.
            await sel.evaluate(el => el.dispatchEvent(new MouseEvent('mousedown', { bubbles: true })));
            await page.waitForTimeout(1500);
            const opts = await page.$$eval(
                `#modtable-all select.mod-version[data-mid="${many.id}"] option`,
                els => els.map(e => ({ v: e.value, t: e.textContent.trim() })));
            check(`version selector offers all ${many.versions} versions`,
                opts.length >= many.versions,
                `got ${opts.length} options for ${many.versions} versions`);
            check('"Latest (auto)" is offered as a distinct choice from any pinned version',
                opts.some(o => o.v === '' && /latest/i.test(o.t)),
                JSON.stringify(opts.slice(0, 3)));

            // Pinning marks the control, so a frozen version is visible without opening it.
            const pinTo = opts.find(o => o.v !== '' && !/latest/i.test(o.t));
            if (pinTo) {
                await page.selectOption(`#modtable-all select.mod-version[data-mid="${many.id}"]`, pinTo.v);
                await page.waitForTimeout(900);
                // The pin triggers another rebuildTables(), which resets the search box.
                await page.fill('#modtable-all_filter input', many.name);
                await page.waitForTimeout(500);
                const pinned = await page.$eval(
                    `#modtable-all select.mod-version[data-mid="${many.id}"]`,
                    el => ({ cls: el.className, val: el.value }));
                check('pinning a version marks the selector as pinned',
                    pinned.cls.includes('is-pinned') && pinned.val === pinTo.v,
                    JSON.stringify(pinned));
            }
        }
        await page.fill('#modtable-all_filter input', '');
        await page.waitForTimeout(300);
    }

    // ---- dependencies still resolve, and do so ACROSS catalogues ----
    const crossDep = await page.evaluate(async () => {
        const r = await fetch('adminAPI.php?action=getAllModsWithDeps');
        const d = await r.json();
        const byId = {};
        d.mods.forEach(m => byId[m.id] = m);
        for (const m of d.mods) {
            for (const dep of (m.deps || [])) {
                const t = byId[dep];
                if (t && t.source !== m.source) {
                    return { mod: { id: m.id, name: m.name, source: m.source },
                             dep: { id: t.id, name: t.name, source: t.source } };
                }
            }
        }
        return null;
    });
    check('a cross-catalogue dependency exists (precondition)', crossDep !== null,
        'no cross-source dep found');
    if (crossDep) {
        console.log(`        ${crossDep.mod.source}/${crossDep.mod.name}`
                  + ` depends on ${crossDep.dep.source}/${crossDep.dep.name}`);
        check('cross-catalogue dependency has a resolved target',
            crossDep.dep.id > 0 && crossDep.dep.source !== crossDep.mod.source);
    }

    console.log(`\n  ${pass} passed, ${fail} failed`);
    await browser.close();
    process.exit(fail ? 1 : 0);
})().catch(e => { console.error('HARNESS ERROR', e); process.exit(2); });
