// Real browser + admin API + detached runner + a looping model fixture. Driven by
// test-vibes-turn-limit-e2e.sh, which owns the disposable container.
//
// What 2.62 shipped wrong, and what this holds: a turn that ran long enough for one background
// state poll to see it left Send disabled and the job ring spinning after it ended, until the
// page was reloaded. Every check that the composer is usable is therefore preceded by a check
// that a poll really did see the job mid-turn -- without that stimulus the old code passes too.
const { execFileSync } = require('node:child_process');
const { chromium } = require('playwright');
const C = process.env.TURN_CONTAINER, base = 'http://127.0.0.1:' + process.env.TURN_PORT;
const ROUNDS_OVERRIDE = process.env.TURN_ROUNDS ? Number(process.env.TURN_ROUNDS) : null;
let passed = 0, failed = 0;
function check(name, ok, detail) { if (ok) { passed++; console.log('PASS ' + name); } else { failed++; console.log('FAIL ' + name + (detail ? ' :: ' + detail : '')); } return ok; }
function dex(args) { return execFileSync('docker', ['exec', '-u', 'phvalheim', C, ...args], { encoding: 'utf8' }).trim(); }
function fixture(file, value) { dex(['sh', '-c', 'echo "$0" > /tmp/vibes-turn-fixture/' + file, String(value)]); }
async function api(action, data = {}, method = 'POST') {
  const r = await fetch(base + '/vibesAPI.php?action=' + action + (method === 'GET' ? '&' + new URLSearchParams(data) : ''), { method, headers: { 'Content-Type': 'application/json' }, ...(method === 'POST' ? { body: JSON.stringify(data) } : {}) });
  const j = await r.json(); if (!r.ok || j.success === false) throw Error(action + ': ' + JSON.stringify(j)); return j;
}
const sleep = ms => new Promise(r => setTimeout(r, ms));

(async () => {
  let browser;
  try {
    // ---- 1. An upgraded install reaches the new limits without anyone opening Settings ----
    const agent = (await api('settings', {}, 'GET')).settings.agent;
    check('saved 100-round / 15-minute stock values follow the new default on upgrade', agent.max_rounds === 300 && agent.turn_timeout_s === 3600, JSON.stringify(agent));
    check('response limit is not changed by the upgrade', agent.model_timeout_s === 900, String(agent.model_timeout_s));
    let rounds = agent.max_rounds;
    if (ROUNDS_OVERRIDE) { await api('saveSettings', { settings: { agent: { max_rounds: ROUNDS_OVERRIDE } } }); rounds = ROUNDS_OVERRIDE; console.log('NOTE running at an explicit limit of ' + rounds + ' rounds'); }
    else {
      const high = await fetch(base + '/vibesAPI.php?action=saveSettings', { method: 'POST', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify({ settings: { agent: { max_rounds: 10000, turn_timeout_s: 86400 } } }) });
      check('10,000 rounds and a 24-hour turn are accepted', high.ok && (await high.json()).success !== false);
      const over = await fetch(base + '/vibesAPI.php?action=saveSettings', { method: 'POST', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify({ settings: { agent: { max_rounds: 10001 } } }) });
      check('10,001 rounds is refused', !over.ok || (await over.json()).success === false);
      await api('saveSettings', { settings: { agent: { max_rounds: 300, turn_timeout_s: 3600 } } });
    }

    const project = (await api('createProject', { name: 'OracleTurnLimit', namespace: 'Fixture', description: 'Temporary turn-limit verification', template: 'basic-plugin' })).project;
    browser = await chromium.launch({ headless: true });
    const page = await browser.newPage({ viewport: { width: 1440, height: 1000 } });
    const errors = []; page.on('pageerror', e => errors.push(e.message));
    let pollsThatSawTheTurn = 0;
    page.on('response', async r => {
      if (!r.url().includes('action=state')) return;
      try { if (((await r.json()).jobs || []).some(j => j.kind === 'turn' && j.project_id === project.id)) pollsThatSawTheTurn++; } catch (e) { /* navigation */ }
    });
    let transcriptRequests = 0;
    page.on('request', r => { if (r.url().includes('action=transcript')) transcriptRequests++; });
    // Session streams this page holds open. Each one occupies a browser connection (six per
    // host) and a PHP worker (five in the pool) until the page is closed.
    const openStreams = new Set();
    const isSessionStream = r => r.url().includes('vibesStream.php') && r.url().includes('session=');
    page.on('request', r => { if (isSessionStream(r)) openStreams.add(r); });
    page.on('requestfinished', r => openStreams.delete(r));
    page.on('requestfailed', r => openStreams.delete(r));
    await page.goto(base + '/hugins_vibes.php');
    await page.waitForFunction(() => document.body.dataset.vbReady === '1');
    await page.evaluate(id => Vibes.workspace.select(id), project.id);
    const composer = () => page.evaluate(() => ({
      sendDisabled: document.getElementById('vbComposerSend').disabled, textDisabled: document.getElementById('vbComposerText').disabled,
      hint: document.getElementById('vbComposerHint').textContent, ringHidden: document.getElementById('vbWsJob').hidden,
      stopHidden: document.getElementById('vbComposerStop').hidden, live: document.getElementById('vbLiveStatus').textContent }));
    const usable = c => !c.sendDisabled && !c.textDisabled && c.ringHidden && c.stopHidden;

    if (!ROUNDS_OVERRIDE) {
      await page.evaluate(() => Vibes.settings.open('limits'));
      const shown = await page.evaluate(() => ['max_rounds', 'turn_timeout_s', 'model_timeout_s'].map(k => { const e = document.querySelector('[data-setting="agent.' + k + '"]'); return [e.value, e.max]; }));
      check('Settings form shows 300 rounds / 60 minutes and the raised ceilings', JSON.stringify(shown) === JSON.stringify([['300', '10000'], ['60', '1440'], ['15', '120']]), JSON.stringify(shown));
      await page.evaluate(() => Vibes.closeModal('vbSettings'));
    }

    // ---- 2. A real turn runs into the round limit ----------------------------------------
    await page.locator('#vbComposerText').fill('Keep writing the notes file.');
    const t0 = Date.now();
    await page.locator('#vbComposerSend').click();
    await page.waitForFunction(() => document.getElementById('vbComposerSend').disabled && !document.getElementById('vbWsJob').hidden, null, { timeout: 15000 });
    // The library card of a Vibe with a turn running carries a spinning ring and "turn…".
    const libraryCard = () => page.evaluate(() => { const c = document.querySelector('#vbLibList .vb-lib-card.active'); return c ? { ring: !!c.querySelector('.vb-ring'), text: c.querySelector('.vb-lib-card-meta').textContent } : null; });
    let ringWhileRunning = false;
    try { await page.waitForFunction(() => !!document.querySelector('#vbLibList .vb-lib-card.active .vb-ring'), null, { timeout: 15000 }); ringWhileRunning = true; } catch (e) { /* reported below */ }
    const stopText = 'Stopped after ' + rounds + ' tool rounds';
    await page.waitForFunction(t => document.getElementById('vbTranscript').textContent.includes(t), stopText, { timeout: Number(process.env.TURN_WAIT_MS || 300000) });
    const took = (Date.now() - t0) / 1000;
    // The stop message is on screen one event before `done` is written; wait for the runner.
    let done = {};
    for (let i = 0; i < 40 && done.type !== 'done'; i++) { done = JSON.parse(dex(['sh', '-c', 'tail -n 1 /opt/stateful/vibes/projects/' + project.slug + '/.vibes/sessions/*.jsonl'])); if (done.type !== 'done') await sleep(250); }
    check('the turn stopped at the round limit, server side', done.type === 'done' && done.stop_reason === 'round_limit' && done.rounds === rounds, JSON.stringify(done).slice(0, 200));
    check('STIMULUS: a background state poll saw the turn while it ran', pollsThatSawTheTurn > 0, pollsThatSawTheTurn + ' polls in ' + took.toFixed(1) + 's');
    await sleep(3000);   // the page reloads the project and transcript after `done`
    let c = await composer();
    check('after the limit: Send is enabled, the job ring and Stop are gone', usable(c), JSON.stringify(c));
    check('after the limit: status says how to resume', c.live.includes('Continue'), c.live);
    check('STIMULUS: the library card showed a ring while the turn ran', ringWhileRunning);
    let card = await libraryCard();
    check('after the turn: the library card has stopped spinning', card && !card.ring && !card.text.includes('turn'), JSON.stringify(card));
    await sleep(26000);  // one full 20-second poll cycle must not lock it again
    c = await composer();
    check('26 seconds later it is still usable', usable(c), JSON.stringify(c));
    card = await libraryCard();
    check('26 seconds later the library card is still at rest', card && !card.ring && !card.text.includes('turn'), JSON.stringify(card));

    // ---- 3. The next message goes through WITHOUT a reload --------------------------------
    fixture('mode', 'finish'); fixture('delay_ms', 200);
    await page.locator('#vbComposerText').fill('Continue');
    let sent = false;
    const transcriptRequestsBeforeTurn = transcriptRequests;
    try { await page.locator('#vbComposerSend').click({ timeout: 5000 }); sent = true; } catch (e) { /* a disabled button cannot be clicked */ }
    check('Send accepts a click after the limit', sent);
    let finished = false;
    if (sent) { try { await page.waitForFunction(() => /Finished\.\s*$/.test([...document.querySelectorAll('#vbTranscript .vb-msg.assistant .vb-bubble')].pop()?.textContent || ''), null, { timeout: 60000 }); finished = true; } catch (e) { /* reported below */ } }
    check('"Continue" ran and answered in the same page', finished);
    await sleep(2500);
    c = await composer();
    check('after the follow-up: composer usable, status no longer says stopped', usable(c) && !c.live.includes('Continue'), JSON.stringify(c));
    // The live stream drew the turn; fetching and rebuilding the whole conversation when it ends
    // is what blanked the chat after every turn.
    check('a finished turn does not re-request the transcript', finished && transcriptRequests === transcriptRequestsBeforeTurn, (transcriptRequests - transcriptRequestsBeforeTurn) + ' request(s)');

    // ---- 3b. A long conversation opens quickly and is never shown empty --------------------
    // Reported on a 3,845-event session: 5.7 seconds to open, 4.6 of them in 2,935 layouts,
    // because a scroll position was read after every replayed event. Layout COUNT is the oracle,
    // not a duration: it does not depend on how fast the machine running this is.
    const events = Number(dex(['sh', '-c', 'cat /opt/stateful/vibes/projects/' + project.slug + '/.vibes/sessions/*.jsonl | wc -l']));
    check('STIMULUS: the session on screen is a long one', events > 1000, events + ' events');
    const cdp = await page.context().newCDPSession(page);
    await cdp.send('Performance.enable');
    const layoutCount = async () => (await cdp.send('Performance.getMetrics')).metrics.find(m => m.name === 'LayoutCount').value;
    const nodesBefore = await page.evaluate(() => document.querySelectorAll('#vbTranscript *, #vbForgeList *').length);
    const layoutsBefore = await layoutCount();
    await page.evaluate(() => Vibes.vibeTab.openSession(Vibes.S.session));
    const layouts = (await layoutCount()) - layoutsBefore;
    const nodesAfter = await page.evaluate(() => document.querySelectorAll('#vbTranscript *, #vbForgeList *').length);
    check('STIMULUS: reopening rebuilt the whole conversation', nodesAfter > 1000 && Math.abs(nodesAfter - nodesBefore) < 20, nodesBefore + ' nodes live, ' + nodesAfter + ' after reopening');
    check('reopening it lays the page out a handful of times, not once per event', layouts < 50, layouts + ' layouts for ' + events + ' events');
    // Slow the transcript down so an emptied pane would be on screen long enough to be painted.
    const slowTranscript = url => url.href.includes('action=transcript');
    await page.route(slowTranscript, async r => { await sleep(700); await r.continue(); });
    const fewestBubbles = await page.evaluate(async () => {
      const t = document.getElementById('vbTranscript'); let fewest = Infinity, frames = 0, on = true;
      const tick = () => { fewest = Math.min(fewest, t.children.length); frames++; if (on) requestAnimationFrame(tick); };
      requestAnimationFrame(tick);
      await Vibes.vibeTab.openSession(Vibes.S.session);
      await new Promise(r => requestAnimationFrame(() => requestAnimationFrame(r)));
      on = false; return { fewest, frames };
    });
    await page.unroute(slowTranscript);
    check('STIMULUS: frames were painted while the transcript was on its way', fewestBubbles.frames > 10, fewestBubbles.frames + ' frames');
    check('the chat is never painted empty while a session reloads', fewestBubbles.fewest > 0, 'fewest children in any frame: ' + fewestBubbles.fewest);

    // ---- 3c. Two reloads at once (a turn ending as a build finishes) -----------------------
    // Both used to replay into the same pane and both opened a stream; the first stream was
    // never closed. Enough of those and the browser has no connection left for this server.
    await page.evaluate(async () => { await Promise.all([Vibes.workspace.reload(), Vibes.workspace.reload(), Vibes.workspace.reload()]); });
    await sleep(1500);
    const nodesAfterOverlap = await page.evaluate(() => document.querySelectorAll('#vbTranscript *, #vbForgeList *').length);
    check('overlapping reloads draw the conversation once', Math.abs(nodesAfterOverlap - nodesAfter) < 20, nodesAfter + ' nodes before, ' + nodesAfterOverlap + ' after');
    check('overlapping reloads leave exactly one stream open', openStreams.size === 1, openStreams.size + ' session stream(s) open');

    // ---- 4. A turn whose end the page never hears about still unlocks ----------------------
    // The stream is the only messenger of `done`. Cut it (a dropped connection, a slept laptop)
    // and the old page waited for ever; the state poll has to notice the job is gone.
    await page.route('**/vibesStream.php*', r => r.abort());
    await page.reload();
    await page.waitForFunction(() => document.body.dataset.vbReady === '1');
    await page.evaluate(id => Vibes.workspace.select(id), project.id);
    fixture('delay_ms', 2500);
    await page.locator('#vbComposerText').fill('One more.');
    await page.locator('#vbComposerSend').click();
    await page.waitForFunction(() => document.getElementById('vbComposerSend').disabled, null, { timeout: 15000 });
    let recovered = false;
    try { await page.waitForFunction(() => !document.getElementById('vbComposerSend').disabled && document.getElementById('vbWsJob').hidden, null, { timeout: 75000 }); recovered = true; } catch (e) { /* reported below */ }
    check('with the stream cut, the composer unlocks from the state poll', recovered, JSON.stringify(await composer()));
    const bubbles = await page.evaluate(() => [...document.querySelectorAll('#vbTranscript .vb-msg.assistant .vb-bubble')].map(b => b.textContent.trim()).slice(-1)[0] || '');
    check('...and the answer it missed is on screen', recovered && bubbles === 'Finished.', bubbles.slice(0, 80));
    await page.unroute('**/vibesStream.php*');

    // ---- 5. The stream's two-hour lifetime is a reconnect, not the end ---------------------
    let streamRequests = 0, afterOnReopen = null;
    // A job id nothing else uses: the page's own session stream must not be counted here.
    await page.route('**/vibesStream.php?job=987654*', r => {
      streamRequests++;
      if (streamRequests === 1) return r.fulfill({ status: 200, contentType: 'text/event-stream', body: 'data: {"type":"token","seq":41,"text":""}\n\ndata: {"type":"eos","reason":"timeout"}\n\n' });
      if (afterOnReopen === null) afterOnReopen = new URL(r.request().url()).searchParams.get('after');
      return r.abort();
    });
    await page.evaluate(() => { window.__s = new Vibes.Stream({ job: 987654 }, () => {}, 7); });
    await sleep(1500);
    check('a stream ended by the server lifetime reopens from the last event', streamRequests >= 2 && afterOnReopen === '41', streamRequests + ' requests, after=' + afterOnReopen);
    await page.evaluate(() => window.__s.close());

    check('no browser exceptions', errors.length === 0, errors.join(' | '));
    console.log('turn of ' + rounds + ' rounds took ' + took.toFixed(1) + 's');
  } catch (e) { failed++; console.log('FAIL harness: ' + e.message.split('\n')[0]); }
  finally { if (browser) await browser.close(); }
  console.log(passed + ' passed, ' + failed + ' failed');
  process.exitCode = failed ? 1 : 0;
})();
