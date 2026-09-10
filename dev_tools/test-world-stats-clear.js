// Oracle test: a world's resource readouts must be CLEARED when it stops.
//
// THE BUG (seen in production on dooble, 2026-09-10): the dashboard showed memory bars and
// tick health for worlds that were offline or mid-update. Both APIs are correct -- they
// return only worlds where mode='running' (and, for health, whose tick_stats.json is under
// 30s old). The fault was on the client: updateWorldCharts() iterated the PAYLOAD and
// updateWorldHealth() iterated Object.entries(payload), so a world that dropped out of the
// response was simply never visited again. Its last drawn numbers stayed on screen forever,
// looking live.
//
// The test drives the two update functions directly, because that is where the defect is.
// Going through the real poller would need a genuinely running world AND a stop, and would
// still only prove it for whichever world happened to be up.
//
// The shape that matters is the SECOND call: a payload that omits the world. Asserting only
// that a reported world renders passes against the bug -- that half always worked.
//
// Usage:
//   docker run --rm --network host -v "$PWD/dev_tools":/w -w /w \
//     mcr.microsoft.com/playwright:v1.47.0-jammy bash -c \
//     'npm i --silent --prefix /tmp/pw playwright@1.47.0 >/dev/null 2>&1;
//      NODE_PATH=/tmp/pw/node_modules node test-world-stats-clear.js http://127.0.0.1:8081'

const { chromium } = require('playwright');
const BASE = process.argv[2] || 'http://127.0.0.1:8081';

let pass = 0, fail = 0;
function check(name, ok, detail) {
    if (ok) { pass++; console.log(`  PASS  ${name}`); }
    else { fail++; console.log(`  FAIL  ${name}${detail ? ' -- ' + detail : ''}`); }
}

(async () => {
    const browser = await chromium.launch();
    const page = await browser.newPage({ viewport: { width: 1600, height: 1000 } });
    const errors = [];
    page.on('pageerror', e => errors.push(String(e).slice(0, 160)));

    await page.goto(`${BASE}/index.php`, { waitUntil: 'networkidle', timeout: 60000 });
    await page.waitForTimeout(1500);

    // A VANILLA world deliberately has no HEALTH row -- no BepInEx means no TickMonitor,
    // so there is no tick rate to show. Pick a world that actually has one, or the health
    // assertions below test nothing and report a bug that is not there.
    const world = await page.evaluate(() => {
        const all = [...document.querySelectorAll('.world-resources[data-world]')];
        const withHealth = all.find(c => c.querySelector('.world-load-fill'));
        return withHealth ? withHealth.dataset.world : (all[0] ? all[0].dataset.world : null);
    });
    if (!world) { console.log('NO .world-resources CONTAINERS -- cannot run'); process.exit(1); }
    const hasHealth = await page.evaluate((w) =>
        !!document.querySelector(`.world-resources[data-world="${w}"] .world-load-fill`), world);
    console.log(`(using world "${world}", health row: ${hasHealth ? 'yes' : 'NO -- vanilla, health cases skipped'})`);

    const read = (w) => page.evaluate((w) => {
        const c = document.querySelector(`.world-resources[data-world="${w}"]`);
        const mem = c.querySelector('.world-mem-value');
        const fill = c.querySelector('.world-load-fill');
        const val = c.querySelector('.world-load-value');
        return {
            mem: mem ? mem.textContent.trim() : null,
            tps: val ? val.textContent.trim() : null,
            fillWidth: fill ? fill.style.width : null
        };
    }, w);

    // ---------------------------------------------------------------- case 1
    console.log('\nCase 1: a running world renders (this half always worked)');
    await page.evaluate((w) => {
        updateWorldCharts([{ name: w, mem: 42, memFormatted: '1.4 GB' }]);
        updateWorldHealth({ [w]: { tick_health_pct: 96, measured_tps: 60 } });
    }, world);
    await page.waitForTimeout(300);
    const live = await read(world);
    check('memory shows the reported value', live.mem === '1.4 GB', JSON.stringify(live));
    check('tick health shows TPS', /60\s*TPS/.test(live.tps || ''), JSON.stringify(live));
    check('load bar has width', live.fillWidth === '96%', JSON.stringify(live));

    // ---------------------------------------------------------------- case 2
    console.log('\nCase 2: THE BUG -- the world stops, so it drops out of the payload');
    // This is exactly what the API does when mode leaves 'running': the world is simply
    // absent. No "stopped" flag arrives, because there is no record to send.
    await page.evaluate(() => {
        updateWorldCharts([]);
        updateWorldHealth({});
    }, world);
    await page.waitForTimeout(300);
    const cleared = await read(world);
    check('memory readout cleared', cleared.mem === '—', JSON.stringify(cleared));
    check('TPS readout cleared', cleared.tps === '—', JSON.stringify(cleared));
    check('load bar emptied', cleared.fillWidth === '0%', JSON.stringify(cleared));

    // ---------------------------------------------------------------- case 3
    console.log('\nCase 3: CONTROL -- the two states genuinely differ');
    // If the update functions were no-ops, or the selectors were wrong, both reads would
    // be identical and every assertion above could pass on an inert page.
    check('running and stopped render differently',
        JSON.stringify(live) !== JSON.stringify(cleared),
        `${JSON.stringify(live)} vs ${JSON.stringify(cleared)}`);

    // ---------------------------------------------------------------- case 4
    console.log('\nCase 4: a world still running is NOT cleared alongside it');
    // The sweep must be surgical. Clearing every container on each poll would also pass
    // case 2, while blanking the readouts of worlds that are perfectly healthy.
    const two = await page.evaluate((w) => {
        const all = [...document.querySelectorAll('.world-resources[data-world]')].map(e => e.dataset.world);
        return all.length >= 2 ? [all[0], all[1]] : null;
    }, world);
    if (!two) {
        console.log('  (skipped: dev container has only one world card)');
    } else {
        await page.evaluate(([a, b]) => {
            updateWorldCharts([{ name: a, mem: 10, memFormatted: '900 MB' },
                               { name: b, mem: 20, memFormatted: '2.1 GB' }]);
            updateWorldHealth({ [a]: { tick_health_pct: 99, measured_tps: 60 },
                                [b]: { tick_health_pct: 80, measured_tps: 55 } });
        }, two);
        await page.waitForTimeout(250);
        // Now only the FIRST stops.
        await page.evaluate(([a, b]) => {
            updateWorldCharts([{ name: b, mem: 20, memFormatted: '2.1 GB' }]);
            updateWorldHealth({ [b]: { tick_health_pct: 80, measured_tps: 55 } });
        }, two);
        await page.waitForTimeout(250);
        const stopped = await read(two[0]);
        const survivor = await read(two[1]);
        check(`"${two[0]}" (stopped) was cleared`, stopped.mem === '—', JSON.stringify(stopped));
        check(`"${two[1]}" (still up) kept its readings`,
            survivor.mem === '2.1 GB' && /55\s*TPS/.test(survivor.tps || ''), JSON.stringify(survivor));
    }

    console.log('\nCase 5: no JS errors');
    check('no uncaught page errors', errors.length === 0, errors.join(' | '));

    await browser.close();
    console.log(`\n${pass} passed, ${fail} failed`);
    process.exit(fail === 0 ? 0 : 1);
})();
