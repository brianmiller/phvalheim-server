// Oracle test: the REAL public world cards, measured in a real browser.
//
// This is the test that should have existed first. test-world-card-layout.js builds its own
// approximation of a card and measures that -- so it agreed with four consecutive wrong layout
// attempts. Everything here is measured on authenticated.php as served.
//
// What it caught the first time it was run, neither of which the synthetic test can see:
//
//   1. Seed and Server rendered BLANK on every card. .world-seed and .world-endpoint are
//      written for an inline element (display:block / inline-block, overflow:hidden) but those
//      classes land on the <td> itself, which takes the cell out of table layout. Pinning info
//      cells to height:1px then clipped the value away entirely.
//   2. The label column was a different width on every card -- 226px on a card with no MD5 and
//      no dates, 95px on one with a full hash -- because the width:100% table handed its
//      leftover width to whichever column would take it. Identical labels, wandering values.
//
// Requires the dev container with the Steam-auth bypass on:
//   dev_tools/devUp.sh 76561198000000001
//   docker exec phvalheim-dev /opt/stateless/engine/tools/sql \
//     "UPDATE worlds SET citizens='V_76561198000000001'"   # cards only render for a citizen
//
// Usage:
//   docker run --rm --network host -v "$PWD":/repo -w /repo/dev_tools \
//     mcr.microsoft.com/playwright:v1.47.0-jammy bash -c \
//     'npm i --silent --prefix /tmp/pw playwright@1.47.0 >/dev/null 2>&1;
//      NODE_PATH=/tmp/pw/node_modules node test-public-card-render.js http://127.0.0.1:8080'

const { chromium } = require('playwright');

const BASE = process.argv[2] || 'http://127.0.0.1:8080';

let pass = 0, fail = 0;
const check = (name, ok, detail) => {
    if (ok) { pass++; console.log(`  PASS  ${name}`); }
    else { fail++; console.log(`  FAIL  ${name}${detail ? ' -- ' + detail : ''}`); }
};

const MEASURE = () => {
    const textRect = el => { const r = document.createRange(); r.selectNodeContents(el);
                             return r.getBoundingClientRect(); };
    return [...document.querySelectorAll('.catbox')].map(box => {
        const dataRows = [...box.querySelectorAll('tr')]
            .filter(r => r.cells.length === 2 && r.cells[0].classList.contains('card_worldInfo'));
        const name = box.querySelector('.card_worldName');
        const link = box.querySelector('.card_worldLaunch a, a.card_worldLaunch');
        const tops = dataRows.map(r => Math.round(r.cells[0].getBoundingClientRect().top));
        return {
            world: box.dataset.world,
            labelColW: Math.round(dataRows[0].cells[0].getBoundingClientRect().width),
            colonGaps: dataRows
                .map(r => { const a = textRect(r.cells[0]), b = textRect(r.cells[1]);
                            return b.width ? Math.round(b.left - a.right) : null; })
                .filter(v => v !== null),
            // Every cell that HAS text must actually have a box to draw it in.
            collapsed: dataRows
                .filter(r => (r.cells[1].textContent || '').trim().length > 0 &&
                             r.cells[1].getBoundingClientRect().height < 10)
                .map(r => `${(r.cells[0].textContent || '').replace(/ /g, '').trim()}` +
                          `(${Math.round(r.cells[1].getBoundingClientRect().height)}px)`),
            pitches: tops.slice(1).map((v, i) => v - tops[i]),
            nameToLaunch: Math.round(link.getBoundingClientRect().top - textRect(name).bottom),
            cardH: Math.round(box.getBoundingClientRect().height),
            // Present on a vanilla card, absent on a modded one -- used to prove the page under
            // test actually contains both kinds.
            vanilla: box.classList.contains('catbox-vanilla'),
        };
    });
};

(async () => {
    const browser = await chromium.launch();
    const p = await browser.newPage({ viewport: { width: 1400, height: 1200 } });
    await p.goto(`${BASE}/authenticated.php`, { waitUntil: 'networkidle' });

    if (!(await p.$('.catbox'))) {
        console.error(`NO CARDS at ${p.url()} -- is the bypass on and is the steamID a citizen?`);
        process.exit(1);
    }
    const cards = await p.evaluate(MEASURE);

    console.log(`\nPRECONDITIONS (${cards.length} cards)`);
    // Without both kinds on the page the alignment checks below are trivially satisfiable.
    check('the page has at least one modded and one vanilla card',
        cards.some(c => c.vanilla) && cards.some(c => !c.vanilla),
        `vanilla: ${cards.filter(c => c.vanilla).length}/${cards.length}`);

    console.log('\n1. Nothing is clipped away');
    const clipped = cards.filter(c => c.collapsed.length);
    check('every value that has text has a box to draw it in', clipped.length === 0,
        clipped.map(c => `${c.world}: ${c.collapsed.join(', ')}`).join(' | '));

    console.log('\n2. The label column is identical on every card');
    const widths = [...new Set(cards.map(c => c.labelColW))];
    check('same width on all cards', widths.length === 1,
        cards.map(c => `${c.world}=${c.labelColW}`).join(' '));

    console.log('\n3. The colon-to-value gap is identical and visible');
    const gaps = [...new Set(cards.flatMap(c => c.colonGaps))];
    check('the same everywhere', gaps.length === 1, `gaps: ${gaps}`);
    // Shrink-to-fit label cells sit flush against the value without explicit padding, and the
    // page's blanket `padding: 0 !important` strips it unless the rule is !important too.
    check('and at least 6px', gaps.every(g => g >= 6), `gaps: ${gaps}`);

    console.log('\n4. Row spacing is tight and does not depend on a card\'s neighbours');
    // NOT "one pitch everywhere": a vanilla card legitimately has taller rows where the value
    // is a chip (Server), a button pair (Password) or a badge (Access). The property that
    // matters is that two cards of the SAME KIND measure the same -- which is exactly what
    // broke when a card stretched to match a taller neighbour and spread the slack through its
    // data rows.
    for (const kind of [true, false]) {
        const group = cards.filter(c => c.vanilla === kind);
        if (group.length < 2) {
            console.log(`  SKIP  only ${group.length} ${kind ? 'vanilla' : 'modded'} card(s) to compare`);
            continue;
        }
        const seqs = [...new Set(group.map(c => c.pitches.join(',')))];
        check(`${kind ? 'vanilla' : 'modded'} cards all have the same row spacing`,
            seqs.length === 1, group.map(c => `${c.world}=[${c.pitches}]`).join(' '));
    }
    const pitches = [...new Set(cards.flatMap(c => c.pitches))];
    check('and every row is tight (<= 24px)', pitches.every(v => v <= 24), `pitches: ${pitches}`);

    console.log('\n5. Launch sits under the name, separated, by the same amount everywhere');
    const n2l = [...new Set(cards.map(c => c.nameToLaunch))];
    check('the same on every card', n2l.length === 1,
        cards.map(c => `${c.world}=${c.nameToLaunch}`).join(' '));
    check('and visibly separated (10-20px)', n2l.every(v => v >= 10 && v <= 20), `${n2l}`);

    console.log('\nCONTROL: these checks can fail');
    // Re-apply the exact rule that hid Seed and Server. If the page still measures clean after
    // this, the test is not looking at what it thinks it is.
    await p.addStyleTag({ content:
        '.catbox td.card_worldInfo.world-seed, .catbox td.card_worldInfo.world-endpoint' +
        ' { height: 1px !important; }' });
    const broken = await p.evaluate(MEASURE);
    check('re-pinning seed/endpoint height collapses them again',
        broken.some(c => c.collapsed.length > 0),
        'the collapse check cannot see the bug it was written for');

    await browser.close();
    console.log(`\n${pass} passed, ${fail} failed`);
    process.exit(fail === 0 ? 0 : 1);
})();
