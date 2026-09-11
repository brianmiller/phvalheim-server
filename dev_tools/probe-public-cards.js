// Measure the REAL public UI world cards -- not synthetic markup.
//
// test-world-card-layout.js builds its own approximation of a card and passed every time while
// the live page was still wrong. This loads authenticated.php itself, so what it measures is
// what Brian sees.
//
// Needs a forged session (plain PHP file sessions) AND a citizen row, or the page renders with
// no cards at all -- getMyWorlds() only returns worlds the steamID is a citizen of:
//   docker exec phvalheim-dev sh -c 'f=/var/lib/php/sessions/sess_deadbeefdeadbeefdeadbeefdeadbeef;
//     printf "steamID|s:17:\"76561198000000001\";login_time|i:%s;" "$(date +%s)" > $f;
//     chown phvalheim:phvalheim $f'
//   docker exec phvalheim-dev /opt/stateless/engine/tools/sql \
//     "UPDATE worlds SET citizens='76561198000000001'"     # <-- SAVE AND RESTORE THE OLD VALUES
//
// Usage:
//   docker run --rm --network host -v "$PWD":/repo -w /repo/dev_tools \
//     mcr.microsoft.com/playwright:v1.47.0-jammy bash -c \
//     'npm i --silent --prefix /tmp/pw playwright@1.47.0 >/dev/null 2>&1;
//      NODE_PATH=/tmp/pw/node_modules node probe-public-cards.js http://127.0.0.1:8080 /repo/out.png'

const { chromium } = require('playwright');

const BASE = process.argv[2] || 'http://127.0.0.1:8080';
const SHOT = process.argv[3] || '';

(async () => {
    const browser = await chromium.launch();
    const ctx = await browser.newContext({ viewportSize: { width: 1400, height: 1200 } });
    // Hex only -- PHP validates the session id against its sid charset and silently issues a
    // fresh one otherwise, which lands you on the login page with zero cards and no error.
    await ctx.addCookies([{ name: 'PHPSESSID', value: 'deadbeefdeadbeefdeadbeefdeadbeef',
        domain: '127.0.0.1', path: '/' }]);
    const p = await ctx.newPage();
    await p.goto(`${BASE}/authenticated.php`, { waitUntil: 'networkidle' });
    if (!(await p.$('.catbox'))) {
        console.error(`NO CARDS -- landed on ${p.url()} (session not accepted?)`);
        process.exit(1);
    }

    const data = await p.evaluate(() => {
        const r = el => { const b = el.getBoundingClientRect(); return {
            t: Math.round(b.top), b: Math.round(b.bottom), h: Math.round(b.height),
            l: Math.round(b.left), w: Math.round(b.width) }; };
        return [...document.querySelectorAll('.catbox')].map(box => {
            const name = box.querySelector('.card_worldName');
            const link = box.querySelector('a.card_worldLaunch') || box.querySelector('.card_worldLaunch');
            const infos = [...box.querySelectorAll('td.card_worldInfo')];
            // Left-column cells only -- one per visual row.
            const rows = infos.filter((_, i) => i % 2 === 0).map(e => Math.round(e.getBoundingClientRect().top));
            const hint = box.querySelector('.vanilla-hint');
            const trophies = box.querySelector('.trophy-table');
            const slack = box.querySelector('.card-slack');
            let nameText = null;
            if (name && name.firstChild && name.firstChild.nodeType === 3) {
                const rg = document.createRange();
                rg.setStart(name.firstChild, 0); rg.setEnd(name.firstChild, name.firstChild.length);
                nameText = r({ getBoundingClientRect: () => rg.getBoundingClientRect() });
            }
            return {
                world: (name ? name.textContent : '?').trim().split('\n')[0],
                card: r(box),
                name: name ? r(name) : null,
                nameText,
                link: link ? r(link) : null,
                rowTops: rows,
                pitches: rows.slice(1).map((v, i) => v - rows[i]),
                hint: hint ? r(hint) : null,
                trophies: trophies ? r(trophies) : null,
                slack: slack ? r(slack) : null,
            };
        });
    });

    if (SHOT) await p.screenshot({ path: SHOT, fullPage: true });
    console.log(JSON.stringify(data, null, 1));
    await browser.close();
})();
