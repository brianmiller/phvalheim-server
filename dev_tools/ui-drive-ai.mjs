/**
 * Drive the real AI Helper in a real browser and capture what an operator actually sees.
 *
 * Reading the JSON off the API is not the same test: the panel renders Markdown, streams
 * deltas, and scopes every question to whatever #aiWorldSelect happens to hold. Bugs in
 * "which context did it answer about" and "does the answer read like PhValheim" only exist
 * on screen, so they have to be looked at on screen.
 *
 *   node dev_tools/ui-drive-ai.mjs --url http://host:8001 --out /tmp/aishots \
 *        --ask "system:what is the health of this server?" \
 *        --ask "Ironbound:why will this world not start?"
 *
 * An --ask is "<world>:<question>"; the world "system" means the All/none scope.
 * Requires playwright (see dev_tools/README-ui-drive.md).
 */
import { chromium } from 'playwright';
import { mkdirSync, writeFileSync } from 'fs';

const arg = (n, d) => { const i = process.argv.indexOf(n); return i > 0 ? process.argv[i + 1] : d; };
const asks = process.argv.reduce((a, v, i) =>
    (v === '--ask' ? [...a, process.argv[i + 1]] : a), []);

const URL_    = arg('--url', 'http://127.0.0.1:8001');
const OUT     = arg('--out', '/tmp/aishots');
const TIMEOUT = parseInt(arg('--timeout', '180000'), 10);

mkdirSync(OUT, { recursive: true });

const log = (...m) => console.log(...m);
const slug = s => s.toLowerCase().replace(/[^a-z0-9]+/g, '-').slice(0, 40).replace(/-$/, '');

const browser = await chromium.launch();
const page = await browser.newPage({ viewport: { width: 1500, height: 1100 } });

// Surface client-side breakage instead of silently screenshotting a dead panel.
const consoleErrors = [];
page.on('pageerror', e => consoleErrors.push('pageerror: ' + e.message));
page.on('console', m => { if (m.type() === 'error') consoleErrors.push('console: ' + m.text()); });

await page.goto(`${URL_}/index.php`, { waitUntil: 'networkidle', timeout: 60000 });
log('page:', await page.title());

await page.click('#aiHelperBtn');
await page.waitForSelector('#aiPanel.open, #aiPanel[style*="display: flex"], #aiPanel', { timeout: 10000 });
await page.waitForTimeout(2500);                       // providers + diagnostics load async

const provider = await page.locator('#aiModelSelect').inputValue().catch(() => '(none)');
const worlds   = await page.locator('#aiWorldSelect option').allTextContents();
log('provider:', provider);
log('worlds in scope picker:', worlds.length, JSON.stringify(worlds.slice(0, 6)));

await page.screenshot({ path: `${OUT}/00-panel-open.png`, fullPage: false });

const results = [];

for (const [n, spec] of asks.entries()) {
    const sep      = spec.indexOf(':');
    const world    = spec.slice(0, sep);
    const question = spec.slice(sep + 1);
    const tag      = `${String(n + 1).padStart(2, '0')}-${slug(world)}-${slug(question)}`;

    // Scope FIRST. The panel reads #aiWorldSelect at send time, so setting it after typing
    // would ask the previous world's question.
    const opts = await page.locator('#aiWorldSelect option').all();
    let picked = '';
    for (const o of opts) {
        const label = (await o.textContent() || '').trim();
        const value = await o.getAttribute('value') || '';
        if (world === 'system' ? value === '' : (value === world || label === world)) { picked = value; break; }
    }
    await page.selectOption('#aiWorldSelect', picked);
    log(`\n[${tag}] scope=${picked === '' ? '(system/all)' : picked}  q=${question}`);

    const before = await page.locator('#aiMessages .ai-msg').count();

    await page.fill('#aiInput', question);
    await page.click('#aiSendBtn');

    // Wait for a NEW assistant turn to finish streaming: the send button re-enables.
    await page.waitForFunction(
        c => document.querySelectorAll('#aiMessages .ai-msg').length > c + 1,
        before, { timeout: TIMEOUT }
    ).catch(() => log('  (no new message appeared before timeout)'));
    await page.waitForFunction(
        () => !document.getElementById('aiSendBtn').disabled,
        null, { timeout: TIMEOUT }
    ).catch(() => log('  (send button never re-enabled)'));
    await page.waitForTimeout(1200);

    const msgs   = await page.locator('#aiMessages .ai-msg').allTextContents();
    const answer = msgs[msgs.length - 1] || '(nothing rendered)';
    const trace  = await page.locator('#aiMessages .ai-trace, #aiMessages .ai-tool').allTextContents();

    await page.locator('#aiMessages').evaluate(el => el.scrollTop = el.scrollHeight);
    await page.screenshot({ path: `${OUT}/${tag}.png`, fullPage: false });
    writeFileSync(`${OUT}/${tag}.txt`, `WORLD: ${picked || '(system)'}\nQ: ${question}\n\n${answer}\n`);

    log(`  tools: ${trace.join(' | ').slice(0, 160) || '(none shown)'}`);
    log(`  answer ${answer.length} chars -> ${tag}.txt`);
    results.push({ tag, world: picked || '(system)', question, chars: answer.length, answer });
}

if (consoleErrors.length) {
    log('\nCLIENT ERRORS:');
    [...new Set(consoleErrors)].slice(0, 10).forEach(e => log('  ' + e.slice(0, 160)));
}

writeFileSync(`${OUT}/results.json`, JSON.stringify(results, null, 2));
log(`\nshots + transcripts in ${OUT}`);
await browser.close();
