#!/usr/bin/env node
// Oracle test: Delete goes through a modal that names what goes and wants the world's name
// typed back; the browser confirm() is gone (2.58).
// requires: container
//
// HOW. Clicks Delete on a stopped fixture row and reads the modal; proves the button stays
// disabled until the exact name is typed and that "Keep the world" sends nothing. Then types
// the name and presses Delete with the worldAction request INTERCEPTED (answered by the test),
// so the wiring is proven -- the right cmd and world on the wire -- without deleting anything.
// A world is also never deleted by the page itself: the command is the engine's.
//
//   argv: admin base URL, container, a stopped fixture world

const { chromium } = require('playwright');
const { execFileSync } = require('child_process');

const BASE = process.argv[2] || 'http://127.0.0.1:8081';
const CONTAINER = process.argv[3] || 'phvalheim-dev';
const WORLD = process.argv[4] || 'test2';

let pass = 0, fail = 0;
const ok  = (m) => { console.log('  PASS  ' + m); pass++; };
const bad = (m, d) => { console.log('  FAIL  ' + m); if (d) console.log('        ' + d); fail++; };
const sleep = (ms) => new Promise(r => setTimeout(r, ms));
function sql(q) { return execFileSync('docker', ['exec', CONTAINER, 'mysql', '-N', '-e', q, 'phvalheim'], { encoding: 'utf8' }).trim(); }
const q = (s) => "'" + String(s).replace(/\\/g, '\\\\').replace(/'/g, "\\'") + "'";

(async () => {
    const pre = sql(`SELECT mode FROM worlds WHERE name=${q(WORLD)}`);
    if (!pre) { console.log(`HARNESS: world ${WORLD} not found`); process.exit(2); }
    if (pre !== 'stopped') { console.log(`HARNESS: ${WORLD} must be stopped (mode=${pre})`); process.exit(2); }

    const browser = await chromium.launch();
    const page = await browser.newPage({ viewport: { width: 1557, height: 1000 } });
    const errors = [];
    page.on('pageerror', e => errors.push(String(e).slice(0, 200)));
    page.on('dialog', d => { bad('no browser dialog (confirm) is used', d.message()); d.dismiss(); });
    const sent = [];
    await page.route(/adminAPI\.php\?action=worldAction/, route => {
        sent.push(route.request().url());
        route.fulfill({ status: 200, contentType: 'application/json', body: JSON.stringify({ success: true, intercepted: true }) });
    });
    const shown = () => page.evaluate(() => document.getElementById('deleteWorldOverlay').classList.contains('show'));
    const disabled = () => page.$eval('#dwProceed', b => b.disabled);

    try {
        await page.goto(BASE + '/index.php', { waitUntil: 'networkidle', timeout: 60000 });
        console.log('=== Delete modal ===');
        const link = await page.$(`tr[data-world="${WORLD}"] a[data-action="delete"]`);
        link ? ok('a stopped world\'s Delete is a link') : bad('a stopped world\'s Delete is a link');
        await link.click();
        await page.waitForFunction(() => document.getElementById('deleteWorldOverlay').classList.contains('show') && document.getElementById('dwProceed'), null, { timeout: 15000 });
        ok('Delete opens the modal');
        const text = await page.evaluate(() => document.getElementById('dwBody').innerText);
        /world save/.test(text) && /mods/.test(text) && /client package/.test(text) ? ok('the modal names what goes: the save, the mods, the client package') : bad('the modal names what goes', text.slice(0, 300));
        /Backups already taken/.test(text) ? ok('the modal says backups are kept') : bad('the modal says backups are kept', text.slice(0, 300));
        (await disabled()) ? ok('the Delete button starts disabled') : bad('the Delete button starts disabled');
        await page.fill('#dwConfirm', WORLD.slice(0, -1));
        (await disabled()) ? ok('a near-miss name keeps it disabled') : bad('a near-miss name keeps it disabled');
        await page.fill('#dwConfirm', WORLD);
        (await disabled()) === false ? ok('the exact name enables it') : bad('the exact name enables it');
        await page.click('#dwBody .uw-actions button:not(#dwProceed)');
        await sleep(500);
        (await shown()) === false ? ok('"Keep the world" closes the modal') : bad('"Keep the world" closes the modal');
        sent.length === 0 ? ok('...and sent nothing') : bad('"Keep the world" sent nothing', sent.join(' '));
        sql(`SELECT mode FROM worlds WHERE name=${q(WORLD)}`) === 'stopped' ? ok('the world is untouched (still stopped)') : bad('the world is untouched');

        // The proceed path, on the wire but answered by the test.
        await page.click(`tr[data-world="${WORLD}"] a[data-action="delete"]`);
        await page.waitForSelector('#dwConfirm', { timeout: 15000 });
        await page.fill('#dwConfirm', WORLD);
        await page.click('#dwProceed');
        await sleep(1500);
        const del = sent.find(u => /cmd=delete/.test(u));
        del && del.includes('world=' + encodeURIComponent(WORLD)) ? ok(`Delete sends worldAction cmd=delete for the world (intercepted, not executed)`) : bad('Delete sends worldAction cmd=delete for the world', sent.join(' ') || 'nothing sent');
        (await shown()) === false ? ok('the modal closes after sending') : bad('the modal closes after sending');
        sql(`SELECT COUNT(*) FROM worlds WHERE name=${q(WORLD)}`) === '1' ? ok('control: the world still exists (the request was intercepted)') : bad('control: the world still exists');

        // Static: the old confirm() is gone.
        const src = require('fs').readFileSync(require('path').join(__dirname, '..', 'container/nginx/www/admin/index.php'), 'utf8');
        !src.includes("confirm('Are you sure you want to delete this world?')") ? ok("the browser confirm('Are you sure you want to delete this world?') is gone from index.php") : bad('the old confirm() is gone');

        errors.length === 0 ? ok('no page errors') : bad('no page errors', errors.slice(0, 3).join(' | '));
    } catch (e) {
        bad('test ran to the end', String(e).slice(0, 300));
    } finally {
        await browser.close();
    }
    console.log(`\n${pass} passed, ${fail} failed`);
    process.exit(fail ? 1 : 0);
})();
