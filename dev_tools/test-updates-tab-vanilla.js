#!/usr/bin/env node
// Oracle test: the auto-update warnings describe the real count, a vanilla world is told to use
// a schedule, and the Player Counts switch is gone (2.58).
// requires: container
//
// WHAT IT GUARDS. Both places auto-updates can be switched on (Server Settings, the per-world
// Updates tab) carried a BETA badge and a red warning written for the log-scraped count. The
// count is the Companion's now. What stays true: a VANILLA world runs no Companion, so it can
// never be seen as quiet -- its Updates tab must say so and say what to do (a window plus
// "If players never leave: update anyway"). And the Player Counts section is retired.
//
// HOW. Opens the vanilla fixture world's Settings -> Updates and reads the Players row; opens a
// modded world's as the control (it must NOT carry the vanilla note); opens Server Settings and
// looks for the badge, the old warning and the retired switch. Rendered text, not source.
//
//   argv: admin base URL, a VANILLA world, a MODDED world (control)

const { chromium } = require('playwright');

const BASE = process.argv[2] || 'http://127.0.0.1:8081';
const VANILLA = process.argv[3] || 'test';
const MODDED = process.argv[4] || 'midgard';

let pass = 0, fail = 0;
const ok  = (m) => { console.log('  PASS  ' + m); pass++; };
const bad = (m, d) => { console.log('  FAIL  ' + m); if (d) console.log('        ' + d); fail++; };

(async () => {
    const browser = await chromium.launch();
    const page = await browser.newPage({ viewport: { width: 1557, height: 1000 } });
    const errors = [];
    page.on('pageerror', e => errors.push(String(e).slice(0, 200)));

    async function updatesTab(world) {
        await page.evaluate((w) => showSettingsModal(w), world);
        await page.waitForSelector('.backup-tab[data-tab="updatesTab"]', { state: 'attached', timeout: 15000 });
        await page.click('.backup-tab[data-tab="updatesTab"]');
        await page.waitForFunction(() => { const s = document.getElementById('au-status'); return s && !/Loading/.test(s.innerText); }, null, { timeout: 30000 });
        return page.evaluate(() => ({
            status: document.getElementById('au-status').innerText,
            tab: document.getElementById('updatesTab').textContent.replace(/\s+/g, ' '),
        }));
    }
    async function closeSettings() {
        await page.evaluate(() => { const o = document.querySelector('.mods-modal-overlay.show'); if (o) o.classList.remove('show'); });
    }

    try {
        await page.goto(BASE + '/index.php', { waitUntil: 'networkidle', timeout: 60000 });
        const kinds = await page.evaluate(() => fetch('adminAPI.php?action=getWorlds').then(r => r.json()).then(d => Object.fromEntries((d.worlds || []).map(w => [w.name, parseInt(w.vanilla, 10) || 0]))));
        if (kinds[VANILLA] !== 1) { console.log(`HARNESS: ${VANILLA} is not a vanilla world (${kinds[VANILLA]})`); process.exit(2); }
        if (kinds[MODDED] !== 0) { console.log(`HARNESS: ${MODDED} is not a modded world`); process.exit(2); }

        console.log('=== Updates tab: vanilla world ===');
        let v = await updatesTab(VANILLA);
        /not available on a vanilla world/.test(v.status) ? ok('the Players row says the count is not available on a vanilla world') : bad('the Players row says the count is not available on a vanilla world', v.status.slice(0, 300));
        /runs no PhValheim Companion/.test(v.status) && /maintenance window/.test(v.status) && /Update anyway/.test(v.status)
            ? ok('...and says why (no Companion) and what to do (a window + "Update anyway")') : bad('the vanilla note says why and what to do', v.status.slice(0, 400));
        !/Beta, and off by default/.test(v.tab) && !/reads one out of this world/.test(v.tab) ? ok('the old beta/log-scrape warning is gone from the Updates tab') : bad('the old warning is gone from the Updates tab');
        /PhValheim Companion on its/.test(v.tab) ? ok('the Updates tab explains the idle rule in terms of the Companion') : bad('the Updates tab explains the idle rule in terms of the Companion', v.tab.slice(0, 400));
        !/Per-World Update Settings\s*BETA/.test(v.tab) ? ok('the per-world panel heading carries no BETA badge') : bad('the per-world panel heading carries no BETA badge');
        await closeSettings();

        console.log('=== Updates tab: modded world (control) ===');
        v = await updatesTab(MODDED);
        !/not available on a vanilla world/.test(v.status) ? ok('control: a modded world\'s Players row does not carry the vanilla note') : bad('control: modded world has no vanilla note', v.status.slice(0, 300));
        !/turn on under Server Settings/.test(v.status) && !/\boff\b.*Player Counts/.test(v.status) ? ok('control: the retired "off" branch is gone') : bad('the retired off branch is gone', v.status.slice(0, 300));
        await closeSettings();

        console.log('=== Server Settings ===');
        await page.evaluate(() => showServerSettingsModal());
        await page.waitForSelector('#ss-autoUpdateIdleMinutes', { state: 'attached', timeout: 20000 });
        const ss = await page.evaluate(() => ({
            text: document.querySelector('.mods-modal-overlay.show') ? document.querySelector('.mods-modal-overlay.show').innerText : document.body.innerText,
            hasSwitch: !!document.getElementById('ss-playerCountsEnabled'),
            badges: Array.from(document.querySelectorAll('.mods-modal-overlay.show span')).filter(s => s.textContent.trim() === 'BETA').map(s => s.parentElement.textContent.trim().slice(0, 40)),
        }));
        ss.hasSwitch === false ? ok('the Player Counts switch is gone from Server Settings') : bad('the Player Counts switch is gone');
        !/Player Counts/.test(ss.text) ? ok('no "Player Counts" section is rendered') : bad('no "Player Counts" section is rendered');
        !ss.badges.some(b => /Automatic Updates/.test(b)) ? ok('Automatic Updates carries no BETA badge') : bad('Automatic Updates carries no BETA badge', ss.badges.join(' | '));
        !/beta feature and are off by default/.test(ss.text) && !/reads one\s+out of each world/.test(ss.text) ? ok('the old beta/log-scrape warning is gone from Server Settings') : bad('the old warning is gone from Server Settings');
        /vanilla world/.test(ss.text) && /Update anyway/.test(ss.text) ? ok('Server Settings tells the operator what a vanilla world needs') : bad('Server Settings tells the operator what a vanilla world needs');

        errors.length === 0 ? ok('no page errors') : bad('no page errors', errors.slice(0, 3).join(' | '));
    } catch (e) {
        bad('test ran to the end', String(e).slice(0, 300));
    } finally {
        await browser.close();
    }
    console.log(`\n${pass} passed, ${fail} failed`);
    process.exit(fail ? 1 : 0);
})();
