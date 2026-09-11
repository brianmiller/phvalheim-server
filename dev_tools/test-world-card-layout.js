// Oracle test: world card layout.
//
// Three properties:
//
//   1. Launch! sits under the world name, visibly separated, by the same amount on every card.
//   2. The vanilla hint is fully inside the card, not clipped off the bottom.
//   3. Row spacing is tight and IDENTICAL on every card, whatever its height.
//
// HISTORY -- this took four attempts, and each wrong one measured clean:
//
//   The card tables are `<table width=100% height=100%>`, so the table fills the card. A card is
//   a flex item and stretches to the tallest card in its row, and that leftover height was
//   shared by EVERY row. Three consequences: the name-to-Launch gap varied with card height
//   (51px / 44px / 15px), the hint (a sibling div after the table) was pushed past the bottom
//   and clipped, and an online modded card next to a taller vanilla one had its rows 112px
//   apart while identical offline cards beside it sat at 16px.
//
//   Attempt 1 removed height=100%. Fixed the gap and the hint; every card then collapsed to its
//   content and the UI came out squashed.
//   Attempt 2 restored it and pinned only the name/Launch/hint rows. Fixed those two, left the
//   data rows spreading -- which is the uneven spacing above.
//   Attempt 3 was a broken scripted revert that left comment prose rendering on every card.
//
//   Now: height=100% stays, EVERY data row is pinned to its natural height, and one empty
//   .card-slack row asks for all the leftover. The slack collects in one place at the bottom
//   instead of being spread through the data. The hint sits after that row, which puts it where
//   the boss trophies sit on a modded card, and inside the table so it cannot be pushed out.
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
<tr>${infoRows(rows)}<td colspan=2 class='card-slack'></td><tr>
${vanilla ? `<td class='vanilla-hint' colspan=2>${HINT}</td><tr>` : ''}
</table>${vanilla ? '' : "<table border=0 class='trophy-table'><tr><td class='trophy_icon'><img src='data:image/gif;base64,R0lGODlhAQABAAAAACw=' style='height:36px'></td></tr></table>"}</div>`;

// extraCss lets the control restore the OLD behaviour without rebuilding the markup.
const page = (extraCss = '') => `<html><head><link rel="stylesheet" href="${BASE}/css/phvalheimStyles.css">
<style>${extraCss}</style></head>
<body><div class="google_header" style="display:none"></div><div class="outer"><div class="inner"><div class="wrapper">
${card(4, true)}${card(5, false)}${card(13, false)}</div></div></div></body></html>`;

const measure = (p) => p.evaluate(() => [...document.querySelectorAll('.catbox')].map(box => {
    const th = box.querySelector('.card_worldName');
    const range = document.createRange();
    range.setStart(th.firstChild, 0); range.setEnd(th.firstChild, th.firstChild.length);
    const name = range.getBoundingClientRect();
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
    // Text-to-text, and >= 8px: they were 2px apart once the rows stopped absorbing slack,
    // which is correct spacing-wise but reads as one block.
    check('and visibly separated (8-20px)', gaps.every(g => g >= 8 && g <= 20), `gaps: ${gaps}`);

    console.log('\n2. The vanilla hint is inside the card');
    const van = now.find(c => c.vanilla);
    check('hint is not clipped', van.hintClipped === false);
    // A clipped-to-zero hint would also be "not clipped". It is three lines of text.
    check('and is actually rendered (multi-line)', van.hintH >= 30, `height ${van.hintH}px`);

    console.log('\n3. Row spacing is tight and IDENTICAL on every card');
    // Superseded: rows used to expand to fill, which meant an online modded card sharing a row
    // with a taller vanilla card had its rows 112px apart while the offline cards beside it sat
    // at 16px -- same card, different neighbours, different spacing. A single empty .card-slack
    // row now takes the leftover instead, so the data rows are unaffected by card height.
    const pitches = now.map(c => c.rowPitch);
    check('the same on all three cards', new Set(pitches).size === 1, `pitches: ${pitches}`);
    check('and tight (<= 24px)', pitches.every(p => p <= 24), `pitches: ${pitches}`);
    // The cards must still FILL their row -- the slack moved to the bottom, it did not vanish.
    check('cards still fill the available height', now.every(c => c.cardH >= 400),
        `heights: ${now.map(c => c.cardH)}`);

    console.log('\nCONTROL: without the slack row the spacing goes uneven again');
    // Proves .card-slack is what fixes it, not an accident of these three cards. Neutralising
    // it hands the leftover height back to the data rows.
    await p.setContent(page('.catbox td.card-slack { height: 1px; } .catbox td.card_worldInfo { height: auto; }'),
        { waitUntil: 'networkidle' });
    const unpinned = await measure(p);
    const badPitch = unpinned.map(c => c.rowPitch);
    check('row pitch differs between cards without it', new Set(badPitch).size > 1, `pitches: ${badPitch}`);

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
    check('both card tables carry a .card-slack row',
        (src.split("class='card-slack'").length - 1) === 2);

    await browser.close();
    console.log(`\n${pass} passed, ${fail} failed`);
    process.exit(fail === 0 ? 0 : 1);
})();
