// Oracle test: world card layout.
//
// Three properties, and they pull against each other -- which is why this file exists:
//
//   1. Launch! sits directly under the world name, by the same amount on every card.
//   2. The vanilla hint is fully inside the card, not clipped off the bottom.
//   3. The info rows still EXPAND to fill the card. This is deliberate: the cards are meant to
//      look roomy, and the row spacing is what does it.
//
// HISTORY, because I broke (3) while fixing (1) and (2):
//
//   The card tables are `<table width=100% height=100%>`, so their rows expand to fill the
//   card. That is the roomy look. But the slack was shared by EVERY row including the one
//   between the name and Launch!, so that gap was 51px on a short card and 15px on a tall one.
//   The hint, a sibling div after a 100%-height table, was laid out past the bottom and clipped.
//
//   My first fix removed height=100% entirely. That fixed 1 and 2 and destroyed 3: every card
//   collapsed to its content (row pitch 97px -> 16px) and the UI came out visibly squashed.
//
//   The real fix keeps height=100% and pins ONLY the name, Launch and hint rows to
//   height:1px -- a minimum in table layout, so those cells still size to their text but stop
//   claiming a share of the slack. The info rows expand exactly as before. The hint moved
//   INSIDE the table so it cannot be pushed out of the card.
//
// Layout only, so it runs in real Chromium; jsdom does no layout and would pass on all of it.
//
// Usage -- mount the REPO ROOT, not just dev_tools (the source guards read authenticated.php):
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

const HINT = "Crossplay world &mdash; use the Launch button, or enter the join code above in " +
             "Valheim's <em>Join by code</em> box. It cannot be joined by IP.";

const infoRows = n => Array.from({ length: n }, (_, i) =>
    `<td class='card_worldInfo'>R${i}&nbsp;:</td><td class='card_worldInfo'>value${i}</td><tr>`).join('');

// Mirrors authenticated.php: 100%-height table, hint as the final ROW of that table.
const card = (rows, vanilla) => `<div class="catbox ${vanilla ? 'catbox-vanilla' : ''}"><table width=100% height=100% border=0>
<th class='card_worldName' colspan=2>World${rows}</th>
<tr><th class='card_worldLaunch' colspan=2><a class='card_worldLaunch launch-link' href='#'>Launch!</a></th>
<tr><td style='height: 12px;'</td>
<tr>${infoRows(rows)}
${vanilla ? `<tr><td class='vanilla-hint' colspan=2>${HINT}</td><tr>` : ''}
</table>${vanilla ? '' : "<table border=0 class='trophy-table'><tr><td class='trophy_icon'><img src='data:image/gif;base64,R0lGODlhAQABAAAAACw=' style='height:36px'></td></tr></table>"}</div>`;

// extraCss lets the control restore the OLD behaviour without rebuilding the markup.
const page = (extraCss = '') => `<html><head><link rel="stylesheet" href="${BASE}/css/phvalheimStyles.css">
<style>${extraCss}</style></head>
<body><div class="google_header" style="display:none"></div><div class="outer"><div class="inner"><div class="wrapper">
${card(4, true)}${card(5, false)}${card(13, false)}</div></div></div></body></html>`;

const measure = (p) => p.evaluate(() => [...document.querySelectorAll('.catbox')].map(box => {
    const name = box.querySelector('.card_worldName').getBoundingClientRect();
    const link = box.querySelector('a.card_worldLaunch').getBoundingClientRect();
    const cardBox = box.getBoundingClientRect();
    const infos = [...box.querySelectorAll('.card_worldInfo')];
    const r0 = infos[0].getBoundingClientRect();
    const r2 = infos[2] ? infos[2].getBoundingClientRect() : r0;
    const hint = box.querySelector('.vanilla-hint');
    return {
        vanilla: box.classList.contains('catbox-vanilla'),
        gap: Math.round(link.top - name.bottom),
        rowPitch: Math.round(r2.top - r0.top),
        cardH: Math.round(cardBox.height),
        hintClipped: hint ? hint.getBoundingClientRect().bottom > cardBox.bottom : null,
        hintH: hint ? Math.round(hint.getBoundingClientRect().height) : null,
    };
}));

(async () => {
    const browser = await chromium.launch();
    const p = await browser.newPage();
    await p.setViewportSize({ width: 1400, height: 1000 });

    await p.setContent(page(), { waitUntil: 'networkidle' });
    const now = await measure(p);

    console.log('\n1. Launch! sits just under the name, identically on every card');
    const gaps = now.map(c => c.gap);
    check('the gap is the same on all three cards', new Set(gaps).size === 1, `gaps: ${gaps}`);
    // Equal-but-huge would satisfy the above while looking exactly like the bug.
    check('and it is tight (<= 12px)', gaps.every(g => g <= 12), `gaps: ${gaps}`);

    console.log('\n2. The vanilla hint is inside the card');
    const van = now.find(c => c.vanilla);
    check('hint is not clipped', van.hintClipped === false);
    // A clipped-to-zero hint would also be "not clipped". It is three lines of text.
    check('and is actually rendered (multi-line)', van.hintH >= 30, `height ${van.hintH}px`);

    console.log('\n3. The cards are still roomy -- rows expand to fill');
    // THE REGRESSION I SHIPPED. Removing height=100% fixed 1 and 2 and collapsed every card to
    // its content: row pitch fell from ~97px to 16px. Without this assertion that fix looked
    // perfect and the UI was visibly squashed.
    check('info rows expand well beyond their natural height',
        now.every(c => c.rowPitch >= 30), `pitches: ${now.map(c => c.rowPitch)}`);
    check('cards fill the available height', now.every(c => c.cardH >= 400),
        `heights: ${now.map(c => c.cardH)}`);

    console.log('\nCONTROL: un-pinning the header rows brings the fault back');
    // Proves the pinning is what fixes it, not some accident of these three cards.
    await p.setContent(page('.catbox th.card_worldName, .catbox th.card_worldLaunch { height: auto; }'),
        { waitUntil: 'networkidle' });
    const unpinned = await measure(p);
    const badGaps = unpinned.map(c => c.gap);
    check('gaps differ between cards without the pin', new Set(badGaps).size > 1, `gaps: ${badGaps}`);

    console.log('\nGUARDS: the markup this file mirrors');
    const src = fs.readFileSync(path.join(__dirname, '../container/nginx/www/public/authenticated.php'), 'utf8');
    // height=100% must STAY. Removing it is the regression above, and nothing else in the test
    // would notice, since this file supplies its own markup.
    check('card tables still set height=100% (3 = header + both cards)',
        src.split('<table width=100% height=100% border=0>').length - 1 === 3);
    // The hint must be a table CELL, not a sibling div, or it can be pushed out of the card.
    check('the hint is a table cell inside the card table',
        /<td class='\$worldDimmed vanilla-hint' colspan=2>/.test(src));
    check('and is no longer a sibling div',
        !/<div class='vanilla-hint'>/.test(src));

    await browser.close();
    console.log(`\n${pass} passed, ${fail} failed`);
    process.exit(fail === 0 ? 0 : 1);
})();
