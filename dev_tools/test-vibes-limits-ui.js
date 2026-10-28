#!/usr/bin/env node
// requires: container
// Real settings form/API; restore the prior agent section. Transcript events are
// fixtures routed only in this browser, so no model call or project is created.
const { chromium } = require('playwright');
const BASE = process.argv[2] || 'http://127.0.0.1:8081';
let passed = 0;
function check(name, condition) { if (!condition) throw new Error(name); console.log('PASS ' + name); passed++; }
(async () => {
  const browser = await chromium.launch({ headless: true });
  const page = await browser.newPage({ viewport: { width: 1440, height: 1000 } });
  const errors = []; page.on('pageerror', e => errors.push(e.message));
  let prior;
  const field = name => page.locator('[data-setting="agent.' + name + '"]');
  try {
    await page.goto(BASE + '/hugins_vibes.php');
    await page.waitForFunction(() => document.body.dataset.vbReady === '1');
    prior = await page.evaluate(async () => (await Vibes.api('settings')).settings.agent);
    await page.evaluate(() => Vibes.settings.open('limits'));
    check('three independent limit controls are visible', await field('max_rounds').isVisible() && await field('turn_timeout_s').isVisible() && await field('model_timeout_s').isVisible());
    check('response limit loads persisted/default value', await field('model_timeout_s').inputValue() === String(prior.model_timeout_s / 60));
    for (const value of ['0.49', '120.01']) {
      await field('model_timeout_s').fill(value);
      check('form rejects response limit ' + value, !(await field('model_timeout_s').evaluate(e => e.checkValidity())));
    }
    const next = prior.model_timeout_s === 1200 ? 1201 : 1200;
    await field('model_timeout_s').fill(String(next / 60));
    const saved = page.waitForResponse(r => r.url().includes('vibesAPI.php') && r.request().method() === 'POST');
    await page.locator('form[data-stab="limits"] button[type="submit"]').click();
    check('settings form saves successfully', (await saved).ok());
    await page.reload();
    await page.waitForFunction(() => document.body.dataset.vbReady === '1');
    await page.evaluate(() => Vibes.settings.open('limits'));
    check('response limit survives reload', await field('model_timeout_s').inputValue() === String(next / 60));
    check('round limit unchanged by response edit', await field('max_rounds').inputValue() === String(prior.max_rounds));
    check('turn limit unchanged by response edit', await field('turn_timeout_s').inputValue() === String(prior.turn_timeout_s / 60));
    const copy = await page.locator('form[data-stab="limits"]').innerText();
    check('help distinguishes rounds and time and explains continuation', copy.includes('Continue') && copy.includes('between tools') && copy.includes('next turn'));
    const beforePreset = await page.evaluate(async () => (await Vibes.api('settings')).settings.agent);
    for (const [preset, values] of Object.entries({ standard: ['100', '15', '15'], long: ['200', '30', '15'], slow: ['100', '60', '30'] })) {
      await page.locator('[data-limit-preset="' + preset + '"]').click();
      check(preset + ' preset fills all three controls', JSON.stringify(await Promise.all(['max_rounds', 'turn_timeout_s', 'model_timeout_s'].map(k => field(k).inputValue()))) === JSON.stringify(values));
    }
    check('presets do not save silently', JSON.stringify(await page.evaluate(async () => (await Vibes.api('settings')).settings.agent)) === JSON.stringify(beforePreset));
    await page.locator('[data-limit-preset="standard"]').click();
    const presetSaved = page.waitForResponse(r => r.url().includes('action=saveSettings'));
    await page.locator('form[data-stab="limits"] button[type="submit"]').click(); await presetSaved;
    const standard = await page.evaluate(async () => (await Vibes.api('settings')).settings.agent);
    check('Standard saves 100 rounds and 15-minute limits as seconds', standard.max_rounds === 100 && standard.turn_timeout_s === 900 && standard.model_timeout_s === 900);
    check('saving limits preserves other agent settings', Object.keys(prior).filter(k => !['max_rounds', 'turn_timeout_s', 'model_timeout_s'].includes(k)).every(k => standard[k] === prior[k]));
    await field('turn_timeout_s').fill('1'); await field('model_timeout_s').fill('2');
    check('shorter turn limit explained', (await page.locator('#vbLimitsSummary').textContent()).includes('turn limit is shorter'));
    // Legacy second values need to survive a save even when not whole minutes.
    await page.evaluate(() => Vibes.post('saveSettings', { settings: { agent: { turn_timeout_s: 61, model_timeout_s: 31 } } }));
    await page.evaluate(() => Vibes.settings.load());
    const roundtrip = page.waitForResponse(r => r.url().includes('action=saveSettings'));
    await page.locator('form[data-stab="limits"] button[type="submit"]').click(); await roundtrip;
    const precise = await page.evaluate(async () => (await Vibes.api('settings')).settings.agent);
    check('non-minute saved values round-trip exactly', precise.turn_timeout_s === 61 && precise.model_timeout_s === 31);
    await page.setViewportSize({ width: 390, height: 844 });
    check('response limit remains visible on mobile', await field('model_timeout_s').isVisible());
    check('mobile controls stay inside viewport', await field('model_timeout_s').evaluate(e => { const r = e.getBoundingClientRect(); return r.left >= 0 && r.right <= innerWidth; }));
    await page.evaluate(() => { Vibes.closeModal('vbSettings'); document.getElementById('vbComposerLimits').click(); });
    await page.waitForFunction(() => document.querySelector('form[data-stab="limits"]').classList.contains('active'));
    check('composer shortcut opens limits directly', await field('max_rounds').isVisible());
    await page.evaluate(() => { Vibes.closeModal('vbSettings'); Vibes.S.project = null; Vibes.vibeTab.handle({ type: 'done', stop_reason: 'round_limit' }, true); });
    check('live stop is shown as resumable', (await page.locator('#vbLiveStatus').textContent()).includes('Continue'));
    await page.evaluate(() => Vibes.vibeTab.handle({ type: 'job', status: 'done' }, true));
    check('terminal job polling preserves stopped status', (await page.locator('#vbLiveStatus').textContent()).includes('Continue'));
    let transcript = [{ type: 'user', text: 'Task', seq: 1 }, { type: 'assistant', text: 'Saved. Send Continue.', seq: 2 }, { type: 'done', stop_reason: 'round_limit', seq: 3 }];
    await page.route('**/vibesAPI.php*', async route => {
      if (new URL(route.request().url()).searchParams.get('action') === 'transcript')
        return route.fulfill({ json: { success: true, events: transcript, job: null } });
      return route.continue();
    });
    // Avoid a long-lived session stream; its transport is covered by existing tests.
    await page.evaluate(() => { Vibes.Stream = class { close() {} }; });
    await page.evaluate(() => Vibes.vibeTab.openSession({ id: 987654, tokens_in: 0, tokens_out: 0 }));
    check('reopening stopped transcript restores paused status', (await page.locator('#vbLiveStatus').textContent()).includes('Continue'));
    transcript = [...transcript, { type: 'user', text: 'Continue', seq: 4 }, { type: 'assistant', text: 'Finished', seq: 5 }, { type: 'done', seq: 6 }];
    await page.evaluate(() => Vibes.vibeTab.openSession({ id: 987654, tokens_in: 0, tokens_out: 0 }));
    check('later completed turn clears previous stopped status', !(await page.locator('#vbLiveStatus').textContent()).includes('Continue'));
    check('no browser exceptions', errors.length === 0);
  } finally {
    try {
      if (prior) {
        await page.evaluate(agent => Vibes.post('saveSettings', { settings: { agent } }), prior);
        check('prior agent settings restored', JSON.stringify(await page.evaluate(async () => (await Vibes.api('settings')).settings.agent)) === JSON.stringify(prior));
      }
    } finally { await browser.close(); }
  }
  console.log(passed + ' passed, 0 failed');
})().catch(e => { console.error('FAIL ' + e.message); process.exitCode = 1; });
