// Oracle test for the 2.43 catalogue sync panel and its Settings fields.
//
// WHAT IT GUARDS: 2.43 REMOVED the three Thunderstore-only status rows this panel replaces
// (#syncLastTs, #syncLocalTime, #syncRemoteTime). fetchSyncStatus() wrote into them with a
// bare `document.getElementById(id).textContent = ...`, which throws on null -- and because
// the backup and log-rotation rows were assigned AFTER those lines, one missing element
// silently took the rest of the Sync card down with it. So the first assertion is that the
// page has no uncaught errors AND that the surviving rows still populate.
//
// The rest asserts the panel renders real data from mod_sync_runs rather than a spinner:
// per-source state, totals, and the sync-now link. Grepping index.php for "modSyncPanel"
// would pass on a div that never gets filled.
//
// Usage:
//   docker run --rm --network host -v "$PWD/dev_tools":/w -w /w \
//     mcr.microsoft.com/playwright:v1.47.0-jammy bash -c \
//     'npm i --silent --prefix /tmp/pw playwright@1.47.0 >/dev/null 2>&1;
//      NODE_PATH=/tmp/pw/node_modules node test-modsync-panel.js http://127.0.0.1:8081'

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

    console.log(`\n=== catalogue sync panel (${BASE}/) ===`);
    await page.goto(`${BASE}/`, { waitUntil: 'networkidle', timeout: 90000 });
    await page.waitForFunction(
        () => document.querySelectorAll('#modSyncPanel .ms-src').length > 0,
        { timeout: 60000 }
    ).catch(() => {});

    check('no uncaught JS errors on the dashboard',
        errors.length === 0, errors.slice(0, 3).join(' | '));

    // ---- the manual sync nav item is GONE, and nothing it drove is left behind ----
    //
    // Asserted on the SERVED page, not the source: a leftover onclick referring to a
    // function that no longer exists throws only when clicked, which no page-load check
    // would ever notice. The identifiers must be absent from the delivered markup too --
    // an HTML comment mentioning them would ship on every request.
    const removed = await page.evaluate(() => {
        const html = document.documentElement.outerHTML;
        return {
            navItem: !!document.getElementById('tsSyncTool'),
            stopBtn: !!document.getElementById('tsSyncStop'),
            icon: !!document.getElementById('tsSyncIcon'),
            confirmFn: typeof window.confirmThunderstoreSync,
            stopFn: typeof window.stopThunderstoreSync,
            statusFn: typeof window.updateTsSyncStatus,
            strayMarkup: ['tsSyncTool', 'confirmThunderstoreSync', 'stopThunderstoreSync',
                          'manual_ts_sync_start', 'Thunderstore Sync']
                .filter(s => html.includes(s)),
            // The log link must follow the rename; tsSync.log is only written pre-2.43.
            logLink: !!document.querySelector('a[href*="logfile=modSync.log"]'),
            staleLogLink: !!document.querySelector('a[href*="logfile=tsSync.log"]')
        };
    });
    check('the manual sync nav item is gone',
        !removed.navItem && !removed.stopBtn && !removed.icon, JSON.stringify(removed));
    check('its JS helpers are gone too',
        removed.confirmFn === 'undefined' && removed.stopFn === 'undefined' &&
        removed.statusFn === 'undefined',
        `confirm=${removed.confirmFn} stop=${removed.stopFn} status=${removed.statusFn}`);
    check('no stray references survive in the served markup',
        removed.strayMarkup.length === 0, removed.strayMarkup.join(', '));
    check('the log link points at modSync.log',
        removed.logLink && !removed.staleLogLink, JSON.stringify(removed));

    // The stop endpoint went with it. A 'stopTsSync' action that still worked would mean
    // the UI and the API disagree about whether stopping a sync is a thing.
    const stopGone = await page.evaluate(async () => {
        const r = await fetch('adminAPI.php?action=stopTsSync');
        const t = await r.text();
        return t.trim();
    });
    check('the stopTsSync endpoint no longer does anything',
        !/"success"\s*:\s*true/.test(stopGone), stopGone.slice(0, 120));

    // The removed-element regression: these rows must STILL be written, which only
    // happens if fetchSyncStatus() survived the missing Thunderstore rows.
    const survivors = await page.evaluate(() => ({
        backup: (document.getElementById('syncBackupTime') || {}).textContent,
        rotate: (document.getElementById('syncLogRotateTime') || {}).textContent,
        goneTs: !!document.getElementById('syncLastTs')
    }));
    check('the Thunderstore-only rows are gone (replaced by the panel)', !survivors.goneTs);
    check('backup + log-rotation rows still populate after that removal',
        !!(survivors.backup || '').trim() && !!(survivors.rotate || '').trim(),
        JSON.stringify(survivors));

    // ---- one block per catalogue, with real numbers ----
    const blocks = await page.$$eval('#modSyncPanel .ms-src', els => els.map(e => ({
        pill: (e.querySelector('.src-pill') || {}).textContent,
        pillCls: (e.querySelector('.src-pill') || {}).className,
        state: ((e.querySelector('.ms-state') || {}).textContent || '').trim(),
        totals: ((e.querySelector('.ms-totals') || {}).textContent || '').replace(/\s+/g, ' ').trim(),
        hasSyncLink: !!e.querySelector('.ms-sync-one'),
        gridPairs: [...e.querySelectorAll('.ms-grid b')].map(b => b.textContent.trim())
    })));
    check('one block per catalogue', blocks.length === 2, JSON.stringify(blocks.map(b => b.pill)));
    check('each block carries its source pill',
        blocks.every(b => b.pill && /src-(ts|hex)/.test(b.pillCls || '')),
        JSON.stringify(blocks.map(b => b.pillCls)));
    check('blocks use DIFFERENT pill classes',
        new Set(blocks.map(b => (b.pillCls.match(/src-(ts|hex)/) || [])[0])).size === 2);
    check('each block reports a state',
        blocks.every(b => b.state.length > 0), JSON.stringify(blocks.map(b => b.state)));

    // Totals must be real counts read from the catalogue, not placeholders.
    const totalsOk = blocks.every(b => /[1-9][\d,]*\s+mods/.test(b.totals) &&
                                       /[1-9][\d,]*\s+versions/.test(b.totals));
    check('each block reports real mod + version counts on disk',
        totalsOk, JSON.stringify(blocks.map(b => b.totals)));

    check('each block offers a per-catalogue sync link',
        blocks.every(b => b.hasSyncLink));

    const cache = await page.$eval('#modSyncPanel .ms-cache',
        e => e.textContent.replace(/\s+/g, ' ').trim()).catch(() => null);
    check('the on-disk mod cache is reported', cache !== null && /archive/.test(cache), cache);

    // ---- Settings modal: catalogue keys, documented as optional ----
    console.log('\n=== Settings -> Mod Catalogues ===');
    const opened = await page.evaluate(() => {
        if (typeof openSettingsModal === 'function') { openSettingsModal(); return 'fn'; }
        const btn = document.querySelector('[onclick*="ettings"]');
        if (btn) { btn.click(); return 'click'; }
        return null;
    });
    if (!opened) {
        check('settings modal could be opened', false, 'no opener found');
    } else {
        await page.waitForFunction(
            () => !!document.getElementById('ss-hexiumApiKey'), { timeout: 30000 }
        ).catch(() => {});
        const fields = await page.evaluate(() => {
            const g = id => document.getElementById(id);
            return {
                tsKey: !!g('ss-thunderstoreApiKey'),
                hexKey: !!g('ss-hexiumApiKey'),
                tsEnabled: g('ss-thunderstoreEnabled') ? g('ss-thunderstoreEnabled').value : null,
                hexEnabled: g('ss-hexiumEnabled') ? g('ss-hexiumEnabled').value : null,
                interval: g('ss-modSyncIntervalHours') ? g('ss-modSyncIntervalHours').value : null,
                // The keys are genuinely optional; the UI has to say so, or an operator
                // reads two empty key fields as an unfinished setup.
                saysOptional: /neither needs an api key/i.test(document.body.innerText)
            };
        });
        check('Thunderstore API key field exists', fields.tsKey);
        check('Hexium API key field exists', fields.hexKey);
        check('per-catalogue enable toggles exist and default on',
            fields.tsEnabled === '1' && fields.hexEnabled === '1',
            JSON.stringify(fields));
        check('sync interval is configurable',
            fields.interval !== null && parseInt(fields.interval, 10) > 0, fields.interval);
        check('the UI states that no API key is required', fields.saysOptional);
    }

    console.log(`\n  ${pass} passed, ${fail} failed`);
    await browser.close();
    process.exit(fail ? 1 : 0);
})().catch(e => { console.error('HARNESS ERROR', e); process.exit(2); });
