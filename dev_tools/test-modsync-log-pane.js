// Oracle test for the per-catalogue live sync log pane.
//
// WHAT IT GUARDS: that the pane shows THIS catalogue's real lines, and keeps up while a sync
// is running. Both failure modes are invisible to a static check:
//
//   - The panel is re-rendered from scratch every status refresh. A log pane built inside
//     that render, or a handler bound to its button, is destroyed every couple of seconds --
//     the pane looks fine on load and then silently stops updating, or the toggle stops
//     working after the first repaint.
//   - The engine syncs sources one after another through a module-global "current run". If
//     that is not reset between them, Hexium's lines are recorded against Thunderstore's run
//     and each pane shows a plausible log that belongs to the other provider.
//
// So this drives the real page: expands each pane, asserts the lines are the right
// provider's, then starts a REAL sync and asserts the pane grows while it runs.
//
// Usage:
//   docker run --rm --network host -v "$PWD/dev_tools":/w -w /w \
//     mcr.microsoft.com/playwright:v1.47.0-jammy bash -c \
//     'npm i --silent --prefix /tmp/pw playwright@1.47.0 >/dev/null 2>&1;
//      NODE_PATH=/tmp/pw/node_modules node test-modsync-log-pane.js http://127.0.0.1:8081'

const { chromium } = require('playwright');
const BASE = process.argv[2] || 'http://127.0.0.1:8081';
const CONTAINER = process.argv[3] || 'phvalheim-dev';

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

    console.log(`\n=== per-catalogue sync log pane (${BASE}/) ===`);
    await page.goto(`${BASE}/`, { waitUntil: 'networkidle', timeout: 90000 });
    await page.waitForFunction(
        () => document.querySelectorAll('#modSyncPanel .ms-src').length > 0, { timeout: 60000 });

    check('no uncaught JS errors on load', errors.length === 0, errors.slice(0, 3).join(' | '));

    // ---- one pane per catalogue, collapsed by default ----
    const panes = await page.$$eval('#modSyncPanel .ms-log-wrap', els => els.map(e => ({
        toggleSource: (e.querySelector('.ms-log-toggle') || {}).dataset?.source,
        preId: (e.querySelector('pre.ms-log') || {}).id,
        collapsed: (e.querySelector('pre.ms-log') || {}).style?.display === 'none'
    })));
    check('one log pane per catalogue', panes.length === 2, JSON.stringify(panes));
    check('each pane is tied to a source',
        panes.every(p => p.toggleSource && p.preId === 'ms-log-' + p.toggleSource),
        JSON.stringify(panes));
    // Collapsed by default: the panel is a status card, and two always-open log tails would
    // push everything else off screen.
    check('panes start collapsed', panes.every(p => p.collapsed));

    // ---- expanding fetches that provider's lines ----
    // `modSyncLogState` is declared with `const` at the top level of a classic <script>, so
    // it is a global LEXICAL binding and is NOT a property of window. page.evaluate runs in
    // global scope, so the bare name resolves -- `window.modSyncLogState` would be undefined.
    const readPane = (src) => page.evaluate(s => {
        const el = document.getElementById('ms-log-' + s);
        if (!el) return null;
        let runId = null;
        try { runId = (modSyncLogState[s] || {}).runId ?? null; } catch (e) { /* not yet defined */ }
        return {
            visible: el.style.display !== 'none',
            text: el.innerText,
            lineCount: el.querySelectorAll('.msl').length,
            detailCount: el.querySelectorAll('.msl-detail').length,
            runId: runId
        };
    }, src);

    for (const src of ['thunderstore', 'hexium']) {
        await page.click(`.ms-log-toggle[data-source="${src}"]`);
        await page.waitForFunction(
            s => {
                const el = document.getElementById('ms-log-' + s);
                return el && el.style.display !== 'none' && el.querySelectorAll('.msl').length > 1;
            }, src, { timeout: 30000 }).catch(() => {});
        const p = await readPane(src);
        check(`${src}: pane expands and shows lines`,
            p && p.visible && p.lineCount > 1, JSON.stringify(p && { v: p.visible, n: p.lineCount }));

        // The cross-contamination oracle: each pane must contain its OWN provider's label and
        // not the other's. A shared module-global run id is exactly how this breaks.
        const own = src === 'hexium' ? /Hexium/ : /Thunderstore/;
        const other = src === 'hexium' ? /Thunderstore:/ : /Hexium:/;
        check(`${src}: log names its own catalogue`, p && own.test(p.text),
            p ? p.text.slice(0, 90) : 'no pane');
        check(`${src}: log does NOT contain the other catalogue's lines`,
            p && !other.test(p.text),
            p ? (p.text.match(other) || [''])[0] : 'no pane');

        // Timestamps make a log readable; without them it is just a wall of sentences.
        check(`${src}: lines carry a timestamp`,
            p && /\d\d:\d\d:\d\d/.test(p.text), p ? p.text.slice(0, 60) : '');
    }

    // ---- the per-mod detail toggle actually filters ----
    const before = await readPane('hexium');
    await page.uncheck('.ms-log-detail-cb[data-source="hexium"]');
    await page.waitForTimeout(500);
    const after = await readPane('hexium');
    check('unchecking per-mod detail removes the detail lines',
        before.detailCount > 0 && after.detailCount === 0 && after.lineCount < before.lineCount,
        `before=${before.lineCount}/${before.detailCount} after=${after.lineCount}/${after.detailCount}`);
    await page.check('.ms-log-detail-cb[data-source="hexium"]');
    await page.waitForTimeout(500);
    const restored = await readPane('hexium');
    check('re-checking restores them', restored.detailCount === before.detailCount,
        `${restored.detailCount} vs ${before.detailCount}`);

    // ---- phase timings are shown ----
    const timings = await page.$$eval('#modSyncPanel .ms-timing', els => els.map(e => e.textContent.trim()));
    check('phase timings are rendered', timings.length > 0, JSON.stringify(timings.slice(0, 4)));
    check('timings name a phase and a duration',
        timings.some(t => /\d+\.\d\ds$/.test(t)), JSON.stringify(timings.slice(0, 3)));

    // ---- the pane survives a panel repaint ----
    // renderModSyncSource() rebuilds the whole panel on every status poll. If the pane's
    // state lived in the DOM it would collapse and empty itself here.
    const spawn = require('child_process');
    spawn.execSync(`docker exec ${CONTAINER} mysql -uroot phvalheim ` +
        `-e "UPDATE mod_sync_runs SET phase='poked' WHERE id=(SELECT MAX(id) FROM (SELECT id FROM mod_sync_runs) x);"`,
        { stdio: 'ignore' });
    await page.evaluate(() => refreshModSyncPanel());
    await page.waitForTimeout(1200);
    const survived = await readPane('hexium');
    check('pane stays open and populated across a panel repaint',
        survived && survived.visible && survived.lineCount > 1,
        JSON.stringify(survived && { v: survived.visible, n: survived.lineCount }));

    // ---- LIVE: the pane grows while a sync is actually running ----
    const baseline = await readPane('thunderstore');
    console.log(`\n  starting a real forced sync (thunderstore) -- baseline ${baseline.lineCount} lines`);
    spawn.spawn('docker', ['exec', CONTAINER,
        '/opt/stateless/engine/tools/modSync.py', '--source', 'thunderstore',
        '--trigger', 'manual', '--force'], { detached: true, stdio: 'ignore' }).unref();

    // Poll the PAGE, not the database: the assertion is that the UI keeps up.
    //
    // Growth is measured WITHIN the new run, not against the previous run's total. A new run
    // resets the pane (its lines belong to a different run id), so it starts at a handful of
    // lines and climbs -- it may never exceed the old run's 114. Comparing against the
    // baseline therefore tests nothing, which is how this assertion failed on a pane that
    // was streaming perfectly.
    // Three EXPLICIT stages, not one loop with a compound break. A combined condition can be
    // satisfied by growth belonging to the PREVIOUS run and exit before the switch has
    // happened -- which reports a stale run as the live one and passes the growth assertion
    // for the wrong reason.
    const tick = async () => {
        await page.waitForTimeout(1000);
        await page.evaluate(() => refreshModSyncPanel()).catch(() => {});
        const live = await page.evaluate(
            () => !!document.querySelector('#modSyncPanel .ms-src-live'));
        return { live, pane: await readPane('thunderstore') };
    };

    // 1. the pane must move onto the NEW run
    let sawRunning = false, newRunId = null;
    for (let i = 0; i < 90; i++) {
        const t = await tick();
        if (t.live) sawRunning = true;
        if (t.pane && t.pane.runId && t.pane.runId !== baseline.runId) {
            newRunId = t.pane.runId;
            break;
        }
    }
    check('the panel showed the sync as running', sawRunning);
    check('the pane switched to the new run', newRunId !== null,
        `still showing run ${baseline.runId}`);

    // 2. and its line count must climb while that run is in flight
    let growthSeen = false, first = null, last = null;
    for (let i = 0; i < 90 && newRunId; i++) {
        const t = await tick();
        if (!t.pane || t.pane.runId !== newRunId) continue;
        if (first === null) { first = last = t.pane.lineCount; continue; }
        if (t.pane.lineCount > last) growthSeen = true;
        last = t.pane.lineCount;
        if (growthSeen) break;
    }
    check('the log pane grew while the sync was running', growthSeen,
        `run ${newRunId}: ${first} -> ${last}`);

    // 3. and it must end describing a FINISHED run rather than stalling mid-phase
    let done = null;
    for (let i = 0; i < 120; i++) {
        const t = await tick();
        done = t.pane;
        if (done && /sync complete in/.test(done.text)) break;
    }
    check('the log ends with a completion line',
        done && /sync complete in/.test(done.text),
        done ? done.text.slice(-120) : 'no pane');
    check('the log includes a phase-timing breakdown',
        done && /phase timings/.test(done.text));

    check('still no uncaught JS errors', errors.length === 0, errors.slice(0, 3).join(' | '));

    console.log(`\n  ${pass} passed, ${fail} failed`);
    await browser.close();
    process.exit(fail ? 1 : 0);
})().catch(e => { console.error('HARNESS ERROR', e); process.exit(2); });
