// Oracle test: world card layout -- Launch! sits just under the name, and the vanilla hint is
// not clipped off the bottom of the card.
//
// THE BUG (both symptoms, one cause):
//
//   The card tables were `<table width=100% height=100%>`. A card is a flex item in .wrapper and
//   stretches to the height of the TALLEST card in its row. A 100%-height table then absorbs
//   that slack by SPREADING ITS ROWS -- so the gap between the world name and Launch! varied
//   with how tall the card happened to be: measured at 82px, 55px and 17px on three cards of the
//   same page. It also let the table claim the full content box, pushing the vanilla hint below
//   the card and clipping the last line of "...It cannot be joined by IP."
//
//   With auto height the rows keep their natural size, the slack falls to the bottom of the
//   card where it belongs, and the hint sits inside.
//
// Run in a REAL browser: this is entirely about layout, and jsdom does none. Measuring the gap
// on a single card would also prove nothing -- the bug is that the gap DIFFERS between cards,
// so the assertion has to compare cards of different heights on one page.
//
// Usage -- mount the REPO ROOT, not just dev_tools: the source guard at the end reads
// ../container/nginx/www/public/authenticated.php.
//   docker run --rm --network host -v "$PWD":/repo -w /repo/dev_tools \
//     mcr.microsoft.com/playwright:v1.47.0-jammy bash -c \
//     'npm i --silent --prefix /tmp/pw playwright@1.47.0 >/dev/null 2>&1;
//      NODE_PATH=/tmp/pw/node_modules node test-world-card-layout.js http://127.0.0.1:8080'

const { chromium } = require('playwright');
const fs = require('fs');
const path = require('path');

const BASE = process.argv[2] || 'http://127.0.0.1:8080';

let pass = 0, fail = 0;
const check = (name, ok, detail) => {
    if (ok) { pass++; console.log(`  PASS  ${name}`); }
    else { fail++; console.log(`  FAIL  ${name}${detail ? ' -- ' + detail : ''}`); }
};

// Card markup mirrors authenticated.php: name row, launch row, a 12px spacer, then info rows.
// tableAttrs is the variable under test, so the same builder can reproduce the OLD behaviour.
const infoRows = n =>
    Array.from({ length: n }, (_, i) =>
        `<td class='card_worldInfo'>R${i}&nbsp;:</td><td class='card_worldInfo'>v</td><tr>`).join('');

const vanillaCard = (attrs) => `<div class="catbox catbox-vanilla"><table width=100% ${attrs} border=0>
<th class='card_worldName' colspan=2>BayArea</th>
<tr><th class='card_worldLaunch' colspan=2><a class='card_worldLaunch launch-link' href='#'>Launch!</a></th>
<tr><td style='height: 12px;'</td>
<tr>${infoRows(4)}</table>
<div class='vanilla-hint'>Crossplay world &mdash; use the Launch button, or enter the join code above in Valheim's <em>Join by code</em> box. It cannot be joined by IP.</div></div>`;

const moddedCard = (attrs, rows) => `<div class="catbox"><table width=100% ${attrs} border=0>
<th class='card_worldName' colspan=2>Midgard</th>
<tr><th class='card_worldLaunch' colspan=2><a class='card_worldLaunch launch-link' href='#'>Launch!</a></th>
<tr><td style='height: 12px;'</td>
<tr>${infoRows(rows)}</table>
<table border=0 class='trophy-table'><tr><td class='trophy_icon'><img src='data:image/gif;base64,R0lGODlhAQABAAAAACw=' style='height:36px'></td></tr></table></div>`;

const page = (attrs) => `<html><head><link rel="stylesheet" href="${BASE}/css/phvalheimStyles.css"></head>
<body><div class="google_header" style="display:none"></div><div class="outer"><div class="inner"><div class="wrapper">
${vanillaCard(attrs)}${moddedCard(attrs, 4)}${moddedCard(attrs, 14)}</div></div></div></body></html>`;

const measure = (p) => p.evaluate(() => [...document.querySelectorAll('.catbox')].map(box => {
    const name = box.querySelector('.card_worldName').getBoundingClientRect();
    const link = box.querySelector('a.card_worldLaunch').getBoundingClientRect();
    const card = box.getBoundingClientRect();
    const hint = box.querySelector('.vanilla-hint');
    return {
        kind: box.classList.contains('catbox-vanilla') ? 'vanilla' : 'modded',
        gap: Math.round(link.top - name.bottom),
        hintClipped: hint ? hint.getBoundingClientRect().bottom > card.bottom : null,
    };
}));

(async () => {
    const browser = await chromium.launch();
    const p = await browser.newPage();
    await p.setViewportSize({ width: 1400, height: 900 });

    console.log('\nCurrent markup: the gap is the same on every card');
    await p.setContent(page(''), { waitUntil: 'networkidle' });
    const now = await measure(p);
    const gaps = now.map(c => c.gap);
    check('all three cards have the same name-to-Launch gap',
        new Set(gaps).size === 1, `gaps: ${gaps.join(', ')}`);
    // A shared-but-huge gap would satisfy the check above while still looking wrong.
    check('and it is tight (<= 12px)', gaps.every(g => g <= 12), `gaps: ${gaps.join(', ')}`);

    console.log('\nThe vanilla hint is fully inside its card');
    const van = now.find(c => c.kind === 'vanilla');
    check('hint is not clipped by the card', van.hintClipped === false);

    console.log('\nCONTROL: the old markup still reproduces both faults');
    // Without this the test proves nothing about the FIX -- a page where every card happened to
    // be the same height would pass the assertions above with the bug fully present.
    await p.setContent(page('height=100%'), { waitUntil: 'networkidle' });
    const old = await measure(p);
    const oldGaps = old.map(c => c.gap);
    check('height=100% makes the gap differ between cards',
        new Set(oldGaps).size > 1, `gaps: ${oldGaps.join(', ')}`);
    check('height=100% clips the hint',
        old.find(c => c.kind === 'vanilla').hintClipped === true);

    console.log('\nGUARD: the card tables in authenticated.php have no height attribute');
    // The measurements above use this file's own copy of the markup, so they would keep passing
    // if the real page regressed. This ties them together.
    const src = fs.readFileSync(path.join(__dirname, '../container/nginx/www/public/authenticated.php'), 'utf8');
    const cardTables = src.split('\n').filter(l => l.includes('<table width=100%') && l.includes('height=100%'));
    // The page HEADER table (avatar / welcome) legitimately keeps height=100%; only the two
    // inside .catbox matter. Those sit far below it, so any hit here is a card table.
    check('no card table sets height=100%',
        cardTables.length <= 1, `${cardTables.length} table(s) still have it`);

    await browser.close();
    console.log(`\n${pass} passed, ${fail} failed`);
    process.exit(fail === 0 ? 0 : 1);
})();
