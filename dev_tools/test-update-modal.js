#!/usr/bin/env node
// Oracle test: Update goes through a modal, a RUNNING world can be updated, and the modal
// shows the engine's own event stream until the world is back (2.58).
// requires: container
//
// WHAT IT GUARDS. The row's Update button was greyed out while a world ran, although the engine
// has stopped, updated and restarted a running world since 2.55 (`wasRunning`). It is a door
// now, and both it and the stopped world's Update open a modal that says what will happen,
// warns about players on the world, and -- on proceeding -- follows the engine's events for the
// world until its operation record says the update closed, then until the world is running
// again. Closing the modal must not stop anything (the command is the engine's).
//
// HOW. Really does it: the fixture world is started, a player count is planted on its row, the
// row's Update link is clicked, the modal's text is read, Proceed is clicked, and the test
// waits (minutes) for the modal to report the update finished and the world running again --
// and checks the engine's own lines reached the pane. Cancel is proven first on the stopped
// world: no operation may start. Post hook (oracle-container-env.sh) stops the world.
//
//   argv: admin base URL, container, fixture world (a stopped MODDED world with mods)

const { chromium } = require('playwright');
const { execFileSync } = require('child_process');

const BASE = process.argv[2] || 'http://127.0.0.1:8081';
const CONTAINER = process.argv[3] || 'phvalheim-dev';
const WORLD = process.argv[4] || 'midgard';

let pass = 0, fail = 0;
const ok  = (m) => { console.log('  PASS  ' + m); pass++; };
const bad = (m, d) => { console.log('  FAIL  ' + m); if (d) console.log('        ' + d); fail++; };
const sleep = (ms) => new Promise(r => setTimeout(r, ms));
function sql(q) { return execFileSync('docker', ['exec', CONTAINER, 'mysql', '-N', '-e', q, 'phvalheim'], { encoding: 'utf8' }).trim(); }
const q = (s) => "'" + String(s).replace(/\\/g, '\\\\').replace(/'/g, "\\'") + "'";
const mode = () => sql(`SELECT mode FROM worlds WHERE name=${q(WORLD)}`);
const opCount = () => parseInt(sql(`SELECT COUNT(*) FROM phv_operations WHERE world=${q(WORLD)}`), 10) || 0;
async function waitMode(want, seconds) {
    for (let i = 0; i < seconds; i += 3) { if (mode() === want) return true; await sleep(3000); }
    return mode() === want;
}

(async () => {
    const pre = sql(`SELECT IFNULL(vanilla,0), mode, (SELECT COUNT(*) FROM world_mods WHERE world_id=worlds.id) FROM worlds WHERE name=${q(WORLD)}`).split('\t');
    if (pre.length < 3) { console.log(`HARNESS: world ${WORLD} not found`); process.exit(2); }
    if (pre[0] !== '0' || pre[1] !== 'stopped' || parseInt(pre[2], 10) < 1) {
        console.log(`HARNESS: ${WORLD} must be a stopped modded world with mods (vanilla=${pre[0]}, mode=${pre[1]}, mods=${pre[2]})`); process.exit(2);
    }
    const engine = execFileSync('docker', ['exec', CONTAINER, 'supervisorctl', 'status', 'phvalheim'], { encoding: 'utf8' });
    if (!/RUNNING/.test(engine)) { console.log('HARNESS: the engine is not running'); process.exit(2); }

    const browser = await chromium.launch();
    const page = await browser.newPage({ viewport: { width: 1557, height: 1000 } });
    const errors = [];
    page.on('pageerror', e => errors.push(String(e).slice(0, 200)));
    const overlayShown = () => page.evaluate(() => document.getElementById('updateWorldOverlay').classList.contains('show'));
    const bodyText = () => page.evaluate(() => document.getElementById('uwBody').innerText);

    try {
        await page.goto(BASE + '/index.php', { waitUntil: 'networkidle', timeout: 60000 });

        console.log('=== Update modal: stopped world, Cancel ===');
        const ops0 = opCount();
        const stoppedLink = await page.$(`tr[data-world="${WORLD}"] a[data-action="update"]`);
        stoppedLink ? ok('a stopped world\'s Update is a link') : bad('a stopped world\'s Update is a link');
        await stoppedLink.click();
        await page.waitForFunction(() => document.getElementById('updateWorldOverlay').classList.contains('show') && /Update now|Cancel/.test(document.getElementById('uwBody').innerText), null, { timeout: 15000 });
        let t = await bodyText();
        /stays stopped/i.test(t) ? ok('the modal says a stopped world stays stopped') : bad('the modal says a stopped world stays stopped', t.slice(0, 200));
        /Update it/.test(t) && /mods/.test(t) ? ok('the modal describes the update step (Valheim + mods)') : bad('the modal describes the update step', t.slice(0, 200));
        await page.click('#uwBody .uw-actions button:not(#uwProceed)');
        await sleep(500);
        (await overlayShown()) === false ? ok('Cancel closes the modal') : bad('Cancel closes the modal');
        await sleep(4000);
        mode() === 'stopped' && opCount() === ops0 ? ok('Cancel started nothing (mode still stopped, no new operation)') : bad('Cancel started nothing', `mode=${mode()} ops ${ops0}->${opCount()}`);

        console.log('=== Update modal: RUNNING world, players on it, proceed, live log, comes back ===');
        sql(`UPDATE worlds SET mode='start' WHERE name=${q(WORLD)}`);
        (await waitMode('running', 180)) ? ok(`the fixture world really started (mode=running)`) : bad('the fixture world really started', `mode=${mode()} after 180s`);
        if (mode() !== 'running') throw new Error('cannot continue without a running world');
        // Players on it: the Companion reports 0 on an empty server, so plant a count on the row
        // the way the monitor would. The modal reads the row the moment it opens.
        sql(`UPDATE worlds SET player_count=2, player_count_at=NOW(), player_count_source='companion' WHERE name=${q(WORLD)}`);
        // Wait for the poll to turn the row's Update into a link.
        await page.waitForSelector(`tr[data-world="${WORLD}"] a[data-action="update"]`, { timeout: 20000 });
        ok('a RUNNING world\'s Update is a link (the 5s poll re-emitted the row)');
        const ops1 = opCount();
        await page.click(`tr[data-world="${WORLD}"] a[data-action="update"]`);
        await page.waitForFunction(() => document.getElementById('updateWorldOverlay').classList.contains('show') && document.getElementById('uwProceed'), null, { timeout: 15000 });
        t = await bodyText();
        /Stop the world/.test(t) && /Update it/.test(t) && /Start it again/.test(t) ? ok('the modal lists the three steps: stop, update, start again') : bad('the modal lists the three steps', t.slice(0, 300));
        /2 players are on this world right now/.test(t) ? ok('the modal warns that 2 players are on the world') : bad('the modal warns about the players on the world', t.slice(0, 300));
        const proceedText = await page.$eval('#uwProceed', b => b.textContent.trim());
        /Stop, update and restart/.test(proceedText) ? ok(`the proceed button says what it does ("${proceedText}")`) : bad('the proceed button says what it does', proceedText);
        const t0 = Date.now();
        await page.click('#uwProceed');
        await page.waitForSelector('#uwLog', { timeout: 15000 });
        ok('proceeding switches the modal to the live log');
        await sleep(6000);
        opCount() > ops1 ? ok('the engine opened an operation for the world') : bad('the engine opened an operation', `ops ${ops1}->${opCount()}`);
        // Wait for the modal's own verdict (the operation record), up to 12 minutes.
        await page.waitForFunction(() => { const b = document.getElementById('uwStatus'); return b && (b.classList.contains('ok') || b.classList.contains('bad')); }, null, { timeout: 720000 });
        const verdict = await page.evaluate(() => ({ ok: document.getElementById('uwStatus').classList.contains('ok'), text: document.getElementById('uwStatusText').innerText, lines: Array.from(document.querySelectorAll('#uwLog .uw-line')).map(l => l.innerText) }));
        verdict.ok ? ok(`the modal reports the update finished (${Math.round((Date.now() - t0) / 1000)}s)`) : bad('the modal reports the update finished', verdict.text);
        verdict.lines.length >= 3 ? ok(`the live log carried the engine's lines (${verdict.lines.length})`) : bad('the live log carried the engine\'s lines', JSON.stringify(verdict.lines).slice(0, 300));
        verdict.lines.some(l => /Stopping it for the update/.test(l)) ? ok('...including the engine saying it stopped the running world for the update') : bad('the log shows the engine stopping the world', JSON.stringify(verdict.lines).slice(0, 400));
        verdict.lines.some(l => /starting .* again/.test(l)) ? ok('...and that it is starting it again afterwards') : bad('the log shows the restart', JSON.stringify(verdict.lines).slice(0, 400));
        // The modal then watches the row until the world is running again.
        await page.waitForFunction(() => /running again/.test(document.getElementById('uwStatusText').innerText), null, { timeout: 240000 }).then(
            () => ok('the modal reports the world running again'),
            () => bad('the modal reports the world running again', `status: ${verdict.text}; mode=${mode()}`));
        mode() === 'running' ? ok('the world IS running again (database)') : bad('the world is running again', `mode=${mode()}`);
        // Closing the modal is just closing it.
        await page.click('#uwBody .uw-actions button');
        await sleep(500);
        (await overlayShown()) === false ? ok('Close closes the modal') : bad('Close closes the modal');
        await sleep(3000);
        mode() === 'running' ? ok('closing changed nothing (world still running)') : bad('closing changed nothing', `mode=${mode()}`);

        errors.length === 0 ? ok('no page errors') : bad('no page errors', errors.slice(0, 3).join(' | '));
    } catch (e) {
        bad('test ran to the end', String(e).slice(0, 300));
    } finally {
        await browser.close();
    }
    console.log(`\n${pass} passed, ${fail} failed`);
    process.exit(fail ? 1 : 0);
})();
