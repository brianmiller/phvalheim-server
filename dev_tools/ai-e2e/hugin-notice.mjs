// The "meet Hugin" one-shot modal: it appears once, it explains itself, and it goes away
// for good.
//
// A one-shot dialog has exactly two ways to be wrong, and both are invisible from the code:
// it never shows, or it shows forever. Only a real page load can tell you which.

import { chromium } from 'playwright';

const BASE = process.env.PHV_UI_BASE || 'http://127.0.0.1:19081';

let pass = 0, fail = 0;
const ok  = m => { pass++; console.log(`  \x1b[32mPASS\x1b[0m  ${m}`); };
const bad = m => { fail++; console.log(`  \x1b[31mFAIL\x1b[0m  ${m}`); };

const browser = await chromium.launch();
const page = await browser.newPage({ viewport: { width: 1100, height: 1000 } });
const errors = [];
page.on('pageerror', e => errors.push(e.message));

// domcontentloaded, NOT networkidle: the admin page polls world status, system stats and
// health on several timers, so the network is never idle and that wait is a coin flip.
await page.goto(`${BASE}/index.php`, { waitUntil: 'domcontentloaded' });
await page.waitForTimeout(800);

const modal = page.locator('#huginNoticeOverlay');
if (await modal.count() && await modal.isVisible()) ok('the modal appears on first load');
else                                                bad('no Hugin modal on a server that has never seen one');

if (await modal.count()) {
    const text = (await modal.textContent()).replace(/\s+/g, ' ').trim();

    if (/Hello, I.m Hugin/.test(text)) ok('he introduces himself by name');
    else                               bad(`unexpected heading: ${text.slice(0, 60)}`);

    if (/you.*press Apply|press.*Apply/i.test(text))
         ok('it says the operator is the one who presses Apply');
    else bad('the consent model is not stated');

    if (/Server Settings.*AI Helper/i.test(text))
         ok('it points at Server Settings -> AI Helper to get started');
    else bad('no pointer to where you set a provider up');

    // The raven must be drawn at the size asked for. .ai-hugin pins 34px and a CSS rule
    // beats the width= attribute huginSvg() writes, so this once silently rendered tiny.
    const svg = modal.locator('svg.hugin-hello');
    if (await svg.count()) {
        const box = await svg.boundingBox();
        if (box && box.width > 40 && box.height > 40)
             ok(`the raven renders at ${Math.round(box.width)}x${Math.round(box.height)}`);
        else bad(`the raven has no size: ${JSON.stringify(box)}`);
    } else {
        bad('no Hugin artwork in the modal');
    }

    const z = await modal.evaluate(el => getComputedStyle(el).zIndex);
    if (Number(z) >= 1075) ok(`it stacks above the other notices (z-index ${z})`);
    else                   bad(`z-index ${z} would put it under the Ollama/What's New dialogs`);

    // animations: 'disabled' is required, not cosmetic -- Hugin breathes on an `infinite`
    // keyframe and the capture would otherwise wait on an animation that never ends.
    await page.screenshot({ path: '/tmp/phv-hugin-notice.png', animations: 'disabled' });

    // index.php auto-opens Server Settings 500ms after load when required configuration is
    // missing, which is true on every fresh test container. It sits below this modal, but it
    // still makes actionability nondeterministic. Close it first.
    await page.evaluate(() => {
        const s = document.getElementById('serverSettingsOverlay');
        if (s) s.classList.remove('show');
    });

    try {
        await modal.locator('button:has-text("Maybe later")').click({ timeout: 15000 });
        ok('Maybe later is clickable');
    } catch (e) {
        bad('could not click Maybe later: ' + e.message.split('\n')[0]);
    }

    if (!(await modal.isVisible())) ok('...and it closes the dialog');
    else                            bad('the modal stayed up after Maybe later');

    // Assert the OUTCOME by polling the server, not by intercepting the request.
    //
    // page.waitForResponse() + response.text() was tried and is unusable here: against this
    // continuously-polling page the body read never returned, hanging the run past ten
    // minutes with no error at all. Measured directly, the endpoint answers in 59ms with
    // {"success":true}. The request plumbing was fine; observing it that way was not.
    //
    // What actually matters is that the server stops sending the modal, so ask it that.
    let settled = false;
    for (let i = 0; i < 20 && !settled; i++) {
        const r = await page.evaluate(async () => {
            const res = await fetch('index.php?cachebust=' + Date.now(), { cache: 'no-store' });
            return { status: res.status, hasModal: (await res.text()).includes('huginNoticeOverlay') };
        });
        if (r.status === 200 && !r.hasModal) settled = true;
        else await page.waitForTimeout(250);
    }
    if (settled) ok('the server stops rendering it once dismissed');
    else         bad('the server still renders the modal 5s after dismissal');
}

// And prove it for real with a fresh page load.
await page.reload({ waitUntil: 'domcontentloaded' });
await page.waitForTimeout(800);
const again = page.locator('#huginNoticeOverlay');
const stillThere = (await again.count()) > 0 && await again.isVisible();
if (!stillThere) ok('a real reload does not show it again');
else             bad('the modal reappeared; it would nag on every single page load');

if (!errors.length) ok('no uncaught JavaScript errors');
else                bad('JS errors: ' + errors.slice(0, 3).join(' | '));

await browser.close();
console.log(`\n${pass} passed, ${fail} failed`);
console.log('screenshot: /tmp/phv-hugin-notice.png');
process.exit(fail ? 1 : 0);
