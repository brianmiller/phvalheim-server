// Oracle test for the Access tab refactor.
//
// WHAT IT REPLACED: the same five-line "Easiest way to get a player's ID" note was
// stamped above EACH of the three lists (Citizens / Admins / Banned). Three copies
// on screen, and a screen reader read the whole procedure out three times. The
// "Look Up SteamID" button existed only on Citizens, and copySteamId() appended to
// the Citizens textarea unconditionally -- so there was no way to look an id up
// while editing Admins or Banned, and any result went to the wrong list.
//
// The assertions are on the RENDERED page: how many times the help text actually
// appears, which textarea a lookup result lands in, and whether the counts track
// the content. Counting occurrences in index.php would pass on a template that
// renders the same string three times from one variable -- which is exactly the
// shape of the bug this replaced.
//
// Usage:
//   docker run --rm --network host -v "$PWD/dev_tools":/w -w /w \
//     mcr.microsoft.com/playwright:v1.47.0-jammy bash -c \
//     'npm i --silent --prefix /tmp/pw playwright@1.47.0 >/dev/null 2>&1;
//      NODE_PATH=/tmp/pw/node_modules node test-access-tab-refactor.js http://127.0.0.1:8081'

const { chromium } = require('playwright');
const BASE = process.argv[2] || 'http://127.0.0.1:8081';

let pass = 0, fail = 0;
function check(name, ok, detail) {
    if (ok) { pass++; console.log(`  PASS  ${name}`); }
    else { fail++; console.log(`  FAIL  ${name}${detail ? ' -- ' + detail : ''}`); }
}

(async () => {
    const browser = await chromium.launch();
    const page = await browser.newPage({ viewport: { width: 1280, height: 1000 } });
    const errors = [];
    page.on('pageerror', e => errors.push(String(e).slice(0, 200)));

    await page.goto(`${BASE}/index.php`, { waitUntil: 'networkidle', timeout: 60000 });

    const world = await page.evaluate(() => {
        const el = document.querySelector('[onclick*="showSettingsModal"]');
        if (!el) return null;
        const m = el.getAttribute('onclick').match(/showSettingsModal\('([^']+)'/);
        return m ? m[1] : null;
    });
    if (!world) { console.log('NO WORLDS'); process.exit(1); }
    console.log(`(using world "${world}")`);

    await page.evaluate((w) => showSettingsModal(w), world);
    await page.waitForSelector('#settingsAccessListToggle', { state: 'attached', timeout: 15000 });
    await page.waitForTimeout(600);
    await page.click('.backup-tab[data-tab="accessTab"]');
    await page.waitForTimeout(400);

    // Citizens only renders while the access list is enforced.
    await page.evaluate(() => {
        const t = document.getElementById('settingsAccessListToggle');
        if (!t.checked) { t.checked = true; t.dispatchEvent(new Event('change')); }
    });
    await page.waitForTimeout(300);

    // ---------------------------------------------------------------- case 1
    console.log('\nCase 1: the ID help appears ONCE, not once per list');
    const help = await page.evaluate(() => {
        const tab = document.getElementById('accessTab');
        // textContent, NOT innerText: the question here is "how many COPIES exist in
        // the markup", and innerText omits the collapsed disclosure entirely, which
        // would report 0 copies for a page carrying three.
        const txt = tab.textContent;
        const count = (s) => txt.split(s).length - 1;
        return {
            // A phrase that occurs exactly ONCE per copy of the help. Counting "F2"
            // instead counts mentions of the key -- the one block names it twice.
            helpCopies: count('Have them join any world'),
            disclosures: tab.querySelectorAll('details.pv-disclosure').length,
            // The old banner's opening words. Any occurrence means a copy survived.
            oldBanner: count('Easiest way to get'),
            perLineHints: count('One ID per line')
        };
    });
    check('exactly one disclosure element', help.disclosures === 1, `found ${help.disclosures}`);
    check('the ID instructions exist once, not once per list', help.helpCopies === 1,
        `copies=${help.helpCopies}`);
    check('the old repeated banner is gone', help.oldBanner === 0, `x${help.oldBanner}`);
    // The short format hint SHOULD be per-field -- that is hint text, and GOV.UK
    // wants a format example next to each input. Only the long note is shared.
    check('short format hint is per-list (3)', help.perLineHints === 3, `x${help.perLineHints}`);

    // ---------------------------------------------------------------- case 2
    console.log('\nCase 2: the long help is collapsed until asked for');
    // Measure the DETAILS element's own height, not a descendant's. Chromium hides a
    // closed <details> subtree with content-visibility, which leaves descendants
    // reporting their last laid-out rect -- so a child rect says "visible" on a
    // disclosure that is correctly collapsed and painting nothing.
    const collapsed = await page.evaluate(() => {
        const d = document.querySelector('#accessTab details.pv-disclosure');
        const summaryH = d.querySelector('summary').getBoundingClientRect().height;
        const closedH = d.getBoundingClientRect().height;
        d.open = true;
        const openH = d.getBoundingClientRect().height;
        d.open = false;
        return {
            open: d.open,
            closedH: Math.round(closedH),
            openH: Math.round(openH),
            summaryH: Math.round(summaryH),
            f2VisibleWhenClosed: document.getElementById('accessTab').innerText.includes('F2')
        };
    });
    check('disclosure starts closed', collapsed.open === false, `open=${collapsed.open}`);
    // Closed, the element is its summary and nothing more (a few px of border).
    check('collapsed to just its summary row',
        collapsed.closedH <= collapsed.summaryH + 6,
        `closed=${collapsed.closedH} summary=${collapsed.summaryH}`);
    check('the instructions are not on screen while closed', !collapsed.f2VisibleWhenClosed);
    check('opening it adds real height', collapsed.openH > collapsed.closedH + 20,
        `closed=${collapsed.closedH} open=${collapsed.openH}`);

    // ---------------------------------------------------------------- case 3
    console.log('\nCase 3: every list has its own lookup, aimed at itself');
    const lookups = await page.evaluate(() => {
        return [...document.querySelectorAll('#accessTab .pv-list-lookup')]
            .map(b => (b.getAttribute('onclick') || '').match(/openSteamIdLookup\('([^']+)'\)/))
            .map(m => m ? m[1] : null);
    });
    check('three lookup buttons', lookups.length === 3, JSON.stringify(lookups));
    check('each aims at a DIFFERENT textarea', new Set(lookups).size === 3, JSON.stringify(lookups));
    check('they aim at the three access textareas',
        ['settingsCitizensTextarea', 'settingsAdminsTextarea', 'settingsBannedTextarea']
            .every(id => lookups.includes(id)), JSON.stringify(lookups));

    // The real oracle: drive the lookup from the BANNED list and see where the id
    // lands. Before this change it always went to Citizens.
    const routed = await page.evaluate(async () => {
        const before = {
            c: document.getElementById('settingsCitizensTextarea').value,
            b: document.getElementById('settingsBannedTextarea').value
        };
        openSteamIdLookup('settingsBannedTextarea');
        document.getElementById('steamIdResultText').textContent = 'V_99999999999999999';
        // clipboard may be unavailable headless; copySteamId chains off it.
        navigator.clipboard.writeText = () => Promise.resolve();
        await copySteamId();
        await new Promise(r => setTimeout(r, 250));
        return {
            citizensChanged: document.getElementById('settingsCitizensTextarea').value !== before.c,
            bannedHasIt: document.getElementById('settingsBannedTextarea').value.includes('V_99999999999999999')
        };
    });
    check('a lookup opened from Banned lands in BANNED', routed.bannedHasIt, JSON.stringify(routed));
    check('...and does NOT touch Citizens', !routed.citizensChanged, JSON.stringify(routed));

    // ---------------------------------------------------------------- case 4
    console.log('\nCase 4: heading counts reflect the list, and track edits');
    const counts = await page.evaluate(async () => {
        const ta = document.getElementById('settingsAdminsTextarea');
        ta.value = 'V_1\nV_2\n\n   \nV_3';           // blank + whitespace lines must not count
        ta.dispatchEvent(new Event('input'));
        await new Promise(r => setTimeout(r, 150));
        const afterTyping = document.getElementById('adminsCount').textContent.trim();
        ta.value = '';
        ta.dispatchEvent(new Event('input'));
        await new Promise(r => setTimeout(r, 150));
        const afterClear = document.getElementById('bannedCount') ? document.getElementById('adminsCount').textContent.trim() : null;
        return { afterTyping, afterClear };
    });
    check('counts non-blank lines only (3)', counts.afterTyping === '3', `got ${counts.afterTyping}`);
    check('drops to 0 when emptied', counts.afterClear === '0', `got ${counts.afterClear}`);

    // A count that never moves would pass a "is it a number" check, so assert it
    // actually responded to the two different inputs above.
    check('the count is derived, not static', counts.afterTyping !== counts.afterClear,
        `${counts.afterTyping} vs ${counts.afterClear}`);

    // ---------------------------------------------------------------- case 5
    console.log('\nCase 5: Banned is visually distinguished, without a red panel');
    const banned = await page.evaluate(() => {
        const warn = document.querySelector('#accessTab .pv-warn');
        const dangerBtns = [...document.querySelectorAll('#accessTab .action-btn.danger')]
            .map(b => b.innerText.trim());
        return {
            hasWarn: !!warn && warn.getBoundingClientRect().height > 0,
            warnText: warn ? warn.innerText.slice(0, 60) : null,
            dangerBtns
        };
    });
    check('Banned carries a warning line', banned.hasWarn, banned.warnText);
    check('only the Banned save is danger-styled',
        banned.dangerBtns.length === 1 && /Banned/.test(banned.dangerBtns[0]),
        JSON.stringify(banned.dangerBtns));

    // ---------------------------------------------------------------- case 6
    console.log('\nCase 6: all three lists are on one scrollable page (not tabs)');
    const layout = await page.evaluate(() => {
        // textContent, and case-insensitively: .pv-section-title is text-transform:
        // uppercase, and innerText returns the RENDERED case -- so a match on
        // "Citizens" finds nothing on a heading that reads CITIZENS.
        const vis = (sel) => {
            const el = [...document.querySelectorAll('#accessTab h6')]
                .find(h => h.textContent.trim().toLowerCase().startsWith(sel.toLowerCase()));
            if (!el) return null;
            const r = el.getBoundingClientRect();
            return { top: Math.round(r.top), h: Math.round(r.height) };
        };
        return { c: vis('Citizens'), a: vis('Admins'), b: vis('Banned') };
    });
    const ordered = layout.c && layout.a && layout.b &&
        layout.c.top < layout.a.top && layout.a.top < layout.b.top;
    check('Citizens, Admins, Banned all present and in order', ordered, JSON.stringify(layout));

    // ---------------------------------------------------------------- case 7
    console.log('\nCase 7: no JS errors');
    check('no uncaught page errors', errors.length === 0, errors.join(' | '));

    await browser.close();
    console.log(`\n${pass} passed, ${fail} failed`);
    process.exit(fail === 0 ? 0 : 1);
})();
