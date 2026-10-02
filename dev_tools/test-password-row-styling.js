// Oracle test: the password reveal row renders IDENTICALLY on a modded and a vanilla card.
//
// Why this exists. The password row (mask + SHOW + COPY) reuses the vanilla card's classes on
// the modded card added in 2.53. Five of the six CSS rules for those classes were scoped
// `.catbox-vanilla .vanilla-password-*`, and a modded card is a plain `.catbox` -- so the modded
// row inherited NONE of them and rendered as full-size default links with no spacing. Brian
// spotted it immediately; nothing in the build or the test suite could, because every check was
// on the markup, which was identical and correct.
//
// This measures COMPUTED STYLE on the real page, which is the only thing that answers "do they
// look the same". It loads authenticated.php rather than synthetic markup, for the reason
// probe-public-cards.js records: an approximation of a card passed every time while the live
// page was wrong.
//
// Setup it needs (a scratch container, never a live one -- this forges a session and seeds rows):
//   docker run -d --name phv-cssprobe -p 18080:8080 -v phv-cssprobe-data:/opt/stateful <image>
//   ... set setupComplete=2, insert one vanilla+password world and one modded+password world,
//   ... both with citizens='76561198000000001', then forge
//   ... /var/lib/php/sessions/sess_deadbeefdeadbeefdeadbeefdeadbeef
//
// Usage:
//   docker run --rm --network host -v "$PWD":/repo -w /repo/dev_tools \
//     mcr.microsoft.com/playwright:v1.47.0-jammy bash -c \
//     'npm i --silent --prefix /tmp/pw playwright@1.47.0 >/dev/null 2>&1;
//      NODE_PATH=/tmp/pw/node_modules node test-password-row-styling.js http://127.0.0.1:18080'

const { chromium } = require('playwright');
const BASE = process.argv[2] || 'http://127.0.0.1:18080';
const SHOT = process.argv[3] || '';

// The properties that actually make the row look like itself. letter-spacing and textTransform
// are what made the broken version obvious: lowercase "show"/"copy" at body size.
const PROPS = ['fontSize', 'textTransform', 'letterSpacing', 'textDecorationLine',
               'opacity', 'marginLeft', 'fontFamily'];

let pass = 0, fail = 0;
const ok = (n) => { pass++; console.log(`  PASS  ${n}`); };
const no = (n, d) => { fail++; console.log(`  FAIL  ${n}${d ? ` -- ${d}` : ''}`); };

(async () => {
    const browser = await chromium.launch();
    const ctx = await browser.newContext({ viewportSize: { width: 1400, height: 1400 } });
    await ctx.addCookies([{ name: 'PHPSESSID', value: 'deadbeefdeadbeefdeadbeefdeadbeef',
        domain: '127.0.0.1', path: '/' }]);
    const p = await ctx.newPage();
    await p.goto(`${BASE}/authenticated.php`, { waitUntil: 'networkidle' });

    const data = await p.evaluate((props) => {
        const out = {};
        for (const card of document.querySelectorAll('.catbox')) {
            const name = card.querySelector('.card_worldName')?.textContent?.trim() || '?';
            const wrap = card.querySelector('.vanilla-password');
            out[name] = {
                isVanillaCard: card.classList.contains('catbox-vanilla'),
                hasRow: !!wrap,
            };
            if (!wrap) continue;
            const mask = wrap.querySelector('.vanilla-password-mask');
            const acts = [...wrap.querySelectorAll('.vanilla-password-action')];
            const grab = (el) => {
                if (!el) return null;
                const cs = getComputedStyle(el);
                const o = {};
                for (const k of props) o[k] = cs[k];
                const r = el.getBoundingClientRect();
                o._h = Math.round(r.height);
                return o;
            };
            out[name].mask = grab(mask);
            out[name].actions = acts.map((a) => ({ text: a.textContent.trim(), ...grab(a) }));
            // Gap between the two action links, measured rather than assumed.
            if (acts.length === 2) {
                const a = acts[0].getBoundingClientRect(), b = acts[1].getBoundingClientRect();
                out[name].actionGap = Math.round(b.left - a.right);
            }
        }
        return out;
    }, PROPS);

    if (SHOT) await p.screenshot({ path: SHOT, fullPage: true });
    await browser.close();

    const names = Object.keys(data);
    console.log(`\npassword row styling -- cards found: ${names.join(', ') || '(none)'}\n`);

    const van = names.find((n) => data[n].isVanillaCard);
    const mod = names.find((n) => !data[n].isVanillaCard);
    if (!van || !mod) {
        no('both a vanilla and a modded card are on the page',
           `vanilla=${van || 'none'} modded=${mod || 'none'} -- the harness is not set up`);
        console.log(`\n${pass} passed, ${fail} failed`); process.exit(1);
    }
    if (!data[van].hasRow) no(`${van} (vanilla) has a password row`);  else ok(`${van} (vanilla) has a password row`);
    if (!data[mod].hasRow) no(`${mod} (modded) has a password row`);   else ok(`${mod} (modded) has a password row`);
    if (!data[van].hasRow || !data[mod].hasRow) {
        console.log(`\n${pass} passed, ${fail} failed`); process.exit(1);
    }

    // CONTROL FIRST. If the stylesheet did not load at all, both cards are unstyled and every
    // "they match" assertion below passes on two identically-broken rows. 16px/none/normal is
    // the browser default for a link, and it is exactly what the bug looked like.
    const a0 = data[van].actions[0];
    if (a0.fontSize !== '16px' && a0.textTransform === 'uppercase') {
        ok(`control: the vanilla row IS styled (${a0.fontSize}, ${a0.textTransform})`);
    } else {
        no('control: the vanilla row is UNSTYLED', `fontSize=${a0.fontSize} textTransform=${a0.textTransform} -- stylesheet missing; every match below is vacuous`);
    }

    // The mask.
    for (const k of PROPS) {
        if (!(k in (data[van].mask || {}))) continue;
        const v = data[van].mask[k], m = data[mod].mask[k];
        if (v === m) ok(`mask ${k} matches (${v})`);
        else no(`mask ${k} DIFFERS`, `vanilla=${v}  modded=${m}`);
    }

    // The SHOW / COPY links.
    if (data[van].actions.length !== data[mod].actions.length) {
        no('same number of action links', `vanilla=${data[van].actions.length} modded=${data[mod].actions.length}`);
    } else {
        ok(`both cards have ${data[van].actions.length} action links`);
        data[van].actions.forEach((va, i) => {
            const ma = data[mod].actions[i];
            for (const k of PROPS) {
                if (va[k] === ma[k]) ok(`${va.text} ${k} matches (${va[k]})`);
                else no(`${va.text} ${k} DIFFERS`, `vanilla=${va[k]}  modded=${ma[k]}`);
            }
            if (va._h === ma._h) ok(`${va.text} rendered height matches (${va._h}px)`);
            else no(`${va.text} rendered height DIFFERS`, `vanilla=${va._h}px modded=${ma._h}px`);
        });
    }

    if (data[van].actionGap !== undefined && data[mod].actionGap !== undefined) {
        if (data[van].actionGap === data[mod].actionGap) ok(`gap between SHOW and COPY matches (${data[van].actionGap}px)`);
        else no('gap between SHOW and COPY DIFFERS', `vanilla=${data[van].actionGap}px modded=${data[mod].actionGap}px`);
    }

    console.log(`\n${pass} passed, ${fail} failed`);
    process.exit(fail === 0 ? 0 : 1);
})();
