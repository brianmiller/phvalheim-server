// Oracle test: a crossplay world must never hand the player a launchable URL, and Launch! must
// explain how to join instead.
//
// The bug this locks down: Valheim's -joincode IS a real launch argument, so the button looked
// right and "worked" -- it just joined with NO character selected, because FejdStartup's
// -joincode path calls AutoJoinServer() -> JoinServer() and never SelectCharacter(). The client
// fell back to its built-in developer profile, so players entered the world as
// "Odev (Developer)" and gained a character they never created. Nothing in the UI could show
// that; only playing it did.
//
// Everything below drives the REAL shipped functions (updateWorldCards, showCrossplayJoin) on
// the REAL page, with payloads shaped exactly like api.php's. It does not re-implement them --
// an earlier card test built its own approximation of a card and happily agreed with four
// consecutive wrong layouts.
//
// Requires the dev container with the Steam-auth bypass on:
//   dev_tools/devUp.sh 76561198000000001
//
// Usage:
//   docker run --rm --network host -v "$PWD/..":/repo -w /repo/dev_tools \
//     mcr.microsoft.com/playwright:v1.47.0-jammy bash -c \
//     'npm i --silent --prefix /tmp/pw playwright@1.47.0 >/dev/null 2>&1;
//      NODE_PATH=/tmp/pw/node_modules node test-crossplay-join-modal.js'

const { chromium } = require('playwright');
const URL = process.env.PHV_URL || 'http://localhost:8080/';

let pass = 0, fail = 0;
const check = (name, ok, detail = '') => {
  if (ok) { pass++; console.log(`  PASS  ${name}`); }
  else { fail++; console.log(`  FAIL  ${name}${detail ? ' -- ' + detail : ''}`); }
};

(async () => {
  const browser = await chromium.launch();
  const page = await browser.newPage({ viewport: { width: 1400, height: 1000 } });
  const apiSeen = [];
  page.on('response', async r => {
    if (r.url().includes('api.php')) { try { apiSeen.push(await r.text()); } catch {} }
  });
  await page.goto(URL, { waitUntil: 'networkidle' });

  // The public page can open a one-time notice modal on load; its backdrop swallows every
  // click and made the first attempt at this test time out after 62 retries. Close whatever is
  // open and wait for the backdrop to actually leave the DOM before touching the card.
  // Close via bootstrap and WAIT FOR ITS OWN hidden event. An earlier version stripped the
  // .show class directly as a fallback, which leaves bootstrap internal _isShown true -- the
  // next show() then returns early as a no-op and the modal never reopens, which showed up as
  // "element is not visible" on a later click. Never hand-edit bootstrap state.
  const closeModals = async () => {
    await page.evaluate(() => {
      const open = [...document.querySelectorAll('.modal.show')];
      if (!open.length) return Promise.resolve();
      return Promise.all(open.map(m => new Promise(res => {
        m.addEventListener('hidden.bs.modal', res, { once: true });
        bootstrap.Modal.getOrCreateInstance(m).hide();
        setTimeout(res, 2000);
      })));
    });
    await page.waitForFunction(
      () => !document.querySelector('.modal.show') && !document.querySelector('.modal-backdrop'),
      { timeout: 5000 }
    ).catch(() => {});
  };
  await closeModals();

  // Open by clicking Launch!, then wait for bootstrap shown.bs.modal -- NOT for the .show
  // class. The class lands at the START of the fade, so waiting on it returned while the modal
  // was still transitioning, and a hide() issued during a transition is ignored by bootstrap.
  // That left the modal open and the next click landed on it instead of the card.
  const openJoinModal = async () => {
    await page.evaluate(() => {
      window.__shown = new Promise(res => document.getElementById('crossplayJoinModal')
        .addEventListener('shown.bs.modal', res, { once: true }));
    });
    await (await page.$('.launch-link')).click();
    await page.evaluate(() => window.__shown).catch(() => {});
  };

  console.log('\nNo crossplay launch URL may exist anywhere');
  const html = await page.content();
  // Match the URL, NOT the bare token: the code comments explaining why -joincode was removed
  // contain the word, so /-joincode/ failed against a correct page. A check that cannot tell
  // the fix from the explanation of the fix is worthless.
  const JOINCODE_URL = /steam:\/\/[^"'\s]*-joincode/;
  check('page emits no steam:// -joincode link',
    !JOINCODE_URL.test(html), 'the dead launch URL is back in the server-rendered page');
  check('api.php returns no -joincode url',
    !apiSeen.some(t => JOINCODE_URL.test(t)), 'the 5s poll would restore the button');

  // Source-level: the two backends must not be able to build one at all.
  const src = require('fs');
  const dbg = src.readFileSync('../container/nginx/www/includes/db_gets.php', 'utf8');
  const api = src.readFileSync('../container/nginx/www/public/api.php', 'utf8');
  check('getVanillaJoinInfo builds no -joincode href', !JOINCODE_URL.test(dbg));
  check('api.php builds no -joincode steamUrl', !/steam:\/\/run\/892970\/\/-joincode/.test(api));

  console.log('\nLaunch! opens the modal, carrying the CURRENT code');
  // Feed updateWorldCards the exact shape api.php emits for a live crossplay world.
  const card = await page.$('.catbox');
  check('a card exists to drive', !!card);
  const worldName = await page.$eval('.card_worldName', el => el.textContent.trim()).catch(() => null);

  const drive = async (joinCode, playfab = true) => page.evaluate(([name, joinCode, playfab]) => {
    updateWorldCards([{
      name, vanilla: true, online: true, launchString: 'x',
      connection: { playfab, joinCode, steamUrl: playfab ? null : 'steam://run/892970//+connect host:1' }
    }]);
  }, [worldName, joinCode, playfab]);

  await drive('123456');
  const link = await page.$('.launch-link');
  check('poll renders Launch! for a crossplay world',
    (await link.innerText()).trim() === 'Launch!', `got "${(await link.innerText()).trim()}"`);
  check('and gives it NO launchable href',
    ['#', '', null].includes(await link.getAttribute('href')),
    `href=${await link.getAttribute('href')}`);

  await closeModals();
  await openJoinModal();
  check('clicking Launch! opens the join modal',
    await page.isVisible('#crossplayJoinModal.show'));
  check('modal shows the join code',
    (await page.textContent('#crossplayJoinCode')).trim() === '123456',
    `got "${(await page.textContent('#crossplayJoinCode')).trim()}"`);
  check('modal tells the player to pick their character',
    /pick your character/i.test(await page.textContent('.crossplay-join-steps')));

  // THE ONE THAT MATTERS after a restart: the code is reissued, and a modal populated once at
  // load would keep handing out the dead one.
  await closeModals();
  await drive('654321');
  await openJoinModal();
  check('after the world restarts, the modal shows the NEW code',
    (await page.textContent('#crossplayJoinCode')).trim() === '654321',
    `got "${(await page.textContent('#crossplayJoinCode')).trim()}" -- a stale code cannot join`);
  await closeModals();

  // The modal must be REACHABLE and DISMISSABLE. This is the gap that let a broken modal ship:
  // every earlier close went through closeModals(), which calls bootstrap hide() directly, so
  // the suite never touched the Close button and never noticed the backdrop was painting over
  // the whole dialog (equal z-index, backdrop later in DOM). Assert on hit-testing and on a
  // real click.
  console.log('\nThe modal is reachable and dismissable by clicking');
  await drive('123456');
  await openJoinModal();

  const topmost = await page.evaluate(() => {
    const m = document.getElementById('crossplayJoinModal');
    const c = m.querySelector('.modal-content').getBoundingClientRect();
    const overDialog = document.elementFromPoint(c.left + c.width / 2, c.top + c.height / 2);
    const btn = m.querySelector('.btn-close');
    const b = btn.getBoundingClientRect();
    const overBtn = document.elementFromPoint(b.left + b.width / 2, b.top + b.height / 2);
    return {
      dialogCovered: !(overDialog && m.contains(overDialog)),
      closeCovered: !(overBtn && (overBtn === btn || btn.contains(overBtn))),
      covering: (overBtn && overBtn.className || '').toString(),
      modalZ: getComputedStyle(m).zIndex,
      backdropZ: (() => { const bd = document.querySelector('.modal-backdrop'); return bd ? getComputedStyle(bd).zIndex : 'none'; })(),
    };
  });
  check('nothing covers the dialog', !topmost.dialogCovered,
    `covered by ${topmost.covering}`);
  check('nothing covers the Close button', !topmost.closeCovered,
    `covered by ${topmost.covering} -- the modal cannot be dismissed`);
  check('the modal sits above its backdrop',
    parseInt(topmost.modalZ, 10) > parseInt(topmost.backdropZ === 'none' ? '0' : topmost.backdropZ, 10),
    `modal z=${topmost.modalZ} backdrop z=${topmost.backdropZ}`);

  // A real click, not hide().
  await page.click('#crossplayJoinModal .btn-close');
  await page.waitForTimeout(800);
  check('clicking Close actually dismisses it',
    !(await page.isVisible('#crossplayJoinModal.show')));
  check('and the backdrop is cleaned up',
    await page.evaluate(() => !document.querySelector('.modal-backdrop')));

  // CONTROL for the three hit-tests above: put the z-index back to the broken value and confirm
  // they turn red. Without this they could be asserting nothing.
  await page.addStyleTag({ content: '#crossplayJoinModal.modal { z-index: 1050 !important; }' });
  await drive('123456');
  await openJoinModal();
  const broken = await page.evaluate(() => {
    const m = document.getElementById('crossplayJoinModal');
    const btn = m.querySelector('.btn-close');
    const b = btn.getBoundingClientRect();
    const hit = document.elementFromPoint(b.left + b.width / 2, b.top + b.height / 2);
    return !(hit && (hit === btn || btn.contains(hit)));
  });
  check('CONTROL: at the old z-index the Close button IS covered', broken,
    'the reachability checks cannot see the bug they guard');
  await closeModals();

  console.log('\nNo code yet, and the crossplay -> normal transition');
  await drive(null);
  check('no code yet shows "starting…", not a dead Launch!',
    (await page.innerText('.launch-link')).trim().startsWith('starting'),
    `got "${(await page.innerText('.launch-link')).trim()}"`);

  // A world that stops being crossplay must lose the modal handler, or the click is swallowed
  // and the real launch link silently does nothing.
  await drive(null, false);
  const href = await page.$eval('.launch-link', el => el.getAttribute('href'));
  check('a world that leaves crossplay gets a real launch href',
    /^steam:\/\/run\/892970\/\/\+connect/.test(href || ''), `href=${href}`);
  const stale = await page.$eval('.launch-link', el => !!el.onclick || el.hasAttribute('data-joincode'));
  check('and the modal handler is gone', !stale,
    'the stale onclick would swallow the click and the launch link would do nothing');

  console.log('\nCONTROL: these checks can fail');
  await page.evaluate(() => {
    const a = document.querySelector('.launch-link');
    a.href = 'steam://run/892970//-joincode 999';
  });
  const ctl = JOINCODE_URL.test(await page.content());
  check('a reintroduced -joincode link WOULD be caught', ctl,
    'the no--joincode assertions cannot see the thing they guard');

  await browser.close();
  console.log(`\n${pass} passed, ${fail} failed`);
  process.exit(fail === 0 ? 0 : 1);
})();
