// Drive the confirm-card in a real browser: ask Hugin to stop a world, see the card,
// click Apply, and prove the database changed.
//
// The backend suite already proves propose -> apply works. This proves the OPERATOR can
// reach it: that the token survives the SSE round trip, that the card renders, and that
// Apply posts the right thing. None of that is visible to a PHP test.

import { chromium } from 'playwright';

const BASE = process.env.PHV_UI_BASE || 'http://127.0.0.1:19081';
const WORLD = process.env.PHV_UI_WORLD || 'Midgard';

let pass = 0, fail = 0;
const ok  = m => { pass++; console.log(`  \x1b[32mPASS\x1b[0m  ${m}`); };
const bad = m => { fail++; console.log(`  \x1b[31mFAIL\x1b[0m  ${m}`); };

const browser = await chromium.launch();
const page = await browser.newPage({ viewport: { width: 1100, height: 1000 } });
const errors = [];
page.on('pageerror', e => errors.push(e.message));

await page.goto(`${BASE}/index.php`, { waitUntil: 'networkidle' });

// Any one-shot modal (What's New, migration notice) sits above the panel and would eat
// the clicks below. Dismiss by class rather than by guessing which one is present.
await page.evaluate(() => {
    document.querySelectorAll('.mods-modal-overlay.show, .modal-overlay.show')
        .forEach(o => o.classList.remove('show'));
});

await page.click('#aiHelperBtn');
await page.waitForTimeout(800);

// Select the mock provider + model, exactly as an operator would.
await page.evaluate(() => {
    const sel = document.getElementById('aiProviderSelect');
    if (sel && sel.options.length) { sel.selectedIndex = 0; sel.dispatchEvent(new Event('change')); }
});
await page.waitForTimeout(600);
await page.evaluate(() => {
    const m = document.getElementById('aiModelSelect');
    if (m && m.options.length) { m.selectedIndex = 0; m.dispatchEvent(new Event('change')); }
    if (typeof aiModelPickSync === 'function') aiModelPickSync();
});

await page.fill('#aiInput', `stop ${WORLD}`);
await page.keyboard.press('Enter');

// Wait for the card rather than a fixed sleep: a timeout here is a real failure, not a
// slow machine.
let appeared = true;
try {
    await page.waitForSelector('.ai-proposal', { timeout: 25000 });
} catch (e) {
    appeared = false;
}

if (appeared) ok('a confirm card appeared after the model asked to stop a world');
else          bad('no .ai-proposal card rendered within 25s');

if (appeared) {
    const card = page.locator('.ai-proposal').first();

    const body = (await card.locator('.ai-proposal-body').textContent()).trim();
    if (body.includes(WORLD) && /disconnect/i.test(body))
        ok(`the card states the real consequence: "${body.slice(0, 72)}…"`);
    else
        bad(`card body does not describe the change: "${body}"`);

    const head = (await card.locator('.ai-proposal-title').textContent()).trim();
    if (/waiting for your confirmation/i.test(head)) ok('it is framed as a decision, not as something already done');
    else                                             bad(`unexpected card title: "${head}"`);

    // stop_world is reversible, so it must NOT demand a typed name -- that is reserved for
    // the destructive ones. Asking for it everywhere would train the operator to ignore it.
    if (await card.locator('.ai-proposal-name').count() === 0)
        ok('no typed confirmation demanded for a reversible action');
    else
        bad('stop_world asked for a typed name; that is reserved for irreversible actions');

    await page.screenshot({ path: '/tmp/phv-proposal-card.png' });

    // NOTHING may have happened yet.
    const before = await (await fetch(`${BASE}/adminAPI.php?action=getWorlds`)).json();
    const wBefore = (before.worlds || []).find(w => w.name === WORLD) || {};
    if ((wBefore.mode || '') !== 'stop') ok('the world was NOT touched while the card sat unanswered');
    else                                 bad('the world changed before Apply was clicked');

    await card.locator('.ai-proposal-apply').click();
    await page.waitForSelector('.ai-proposal.applied', { timeout: 20000 }).catch(() => {});

    if (await page.locator('.ai-proposal.applied').count() > 0)
        ok('the card settled into an applied state');
    else {
        const note = await card.locator('.ai-proposal-note').textContent().catch(() => '?');
        bad(`Apply did not settle the card — note says: "${note}"`);
    }

    if (await card.locator('.ai-proposal-apply').count() === 0)
        ok('the Apply button is gone, so it cannot be clicked twice');
    else
        bad('Apply is still clickable after applying');

    await page.waitForTimeout(1200);
    const after = await (await fetch(`${BASE}/adminAPI.php?action=getWorlds`)).json();
    const wAfter = (after.worlds || []).find(w => w.name === WORLD) || {};

    // Accept anything in the stop FAMILY, not the literal 'stop'.
    //
    // The engine is running in this container and polls worlds.mode every two seconds, so
    // by the time we look it has usually already consumed 'stop' and moved the world to
    // 'stopping'. Asserting the literal value made a fully working end-to-end run look
    // like a failure -- and 'stopping' is stronger evidence than 'stop': it means the click
    // reached the database AND the engine acted on it.
    const mode = (wAfter.mode || '') + '/' + (wAfter.status || '');
    if (/stop/.test(mode)) ok(`the engine picked the change up — mode/status is '${mode}'`);
    else                   bad(`world did not move after Apply — mode/status is '${mode}'`);

    await page.screenshot({ path: '/tmp/phv-proposal-applied.png' });
}

if (!errors.length) ok('no uncaught JavaScript errors on the page');
else                bad('JS errors: ' + errors.slice(0, 3).join(' | '));

await browser.close();
console.log(`\n${pass} passed, ${fail} failed`);
console.log('screenshots: /tmp/phv-proposal-card.png, /tmp/phv-proposal-applied.png');
process.exit(fail ? 1 : 0);
