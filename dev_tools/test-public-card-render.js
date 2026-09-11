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
            // Anything hanging off the bottom of the card. Two separate bugs have been exactly
            // this -- the crossplay hint, then the boss trophies -- because both were siblings
            // after a height:100% table, which lays them out past the card. The trophies were
            // hidden by an outsized padding-bottom for as long as that padding existed.
            overflowing: [...box.querySelectorAll('table, img, div')]
                .filter(el => el.getBoundingClientRect().height > 0 &&
                              el.getBoundingClientRect().bottom >
                              box.getBoundingClientRect().bottom + 0.5)
                .map(el => `${el.tagName.toLowerCase()}.${el.className || '-'}` +
                     `(+${Math.round(el.getBoundingClientRect().bottom -
                                     box.getBoundingClientRect().bottom)}px)`),
            // Present on a vanilla card, absent on a modded one -- used to prove the page under
            // test actually contains both kinds.
            vanilla: box.classList.contains('catbox-vanilla'),
        };
    });
};

(async () => {
    const browser = await chromium.launch();
    const p = await browser.newPage({ viewport: { width: 1400, height: 1200 } });
    // The page refreshes its cards from api.php every 5 seconds and re-renders them in place.
    // This test measures the same cards twice (at two viewports) and compares -- a poll landing
    // between the two rewrites the DOM under it, which showed up as a phantom 1px drift in one
    // row of one card. Block the poll so the comparison is of layout, not of timing.
    await p.route('**/api.php*', route => route.abort());
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

    console.log('\n1b. Nothing hangs off the bottom of a card');
    const over = cards.filter(c => c.overflowing.length);
    check('every element is inside its card', over.length === 0,
        over.map(c => `${c.world}: ${c.overflowing.join(', ')}`).join(' | '));

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
    // This is deliberately NOT "every card of a kind measures the same". Two vanilla cards can
    // legitimately differ by a pixel or two: the Access row holds a badge, and a CROSSPLAY
    // badge is not the same size as an IN SERVER BROWSER one. Asserting equality across cards
    // made the suite fail the moment a crossplay world existed, which is a fixture accident,
    // not a regression.
    //
    // The bug this section exists for was that a card stretched to match a taller NEIGHBOUR and
    // spread the leftover through its data rows -- so the same card measured differently
    // depending on what sat beside it. Test that directly: re-measure each card at a viewport
    // that reshuffles the rows, and require its own spacing to be unchanged.
    const pitches = [...new Set(cards.flatMap(c => c.pitches))];
    check('every row is tight (<= 26px)', pitches.every(v => v <= 26), `pitches: ${pitches}`);

    const before = new Map(cards.map(c => [c.world, c.pitches.join(',')]));
    // A FRESH page at the wider size, not setViewportSize() on this one. Resizing relayouts an
    // existing document and a badge's fractional height can round a pixel differently than it
    // does on a first layout -- that produced a reproducible 1px "drift" that no user would ever
    // see, because nobody loads the page narrow and then widens it to compare. A second page is
    // what a wider screen actually looks like.
    const wide = await browser.newPage({ viewport: { width: 1700, height: 1400 } });
    await wide.route('**/api.php*', route => route.abort());
    await wide.goto(`${BASE}/authenticated.php`, { waitUntil: 'networkidle' });
    const reflowed = await wide.evaluate(MEASURE);
    await wide.close();
    // Guard: if the viewport change did not actually regroup the cards, the comparison is
    // vacuous and would pass no matter what.
    const regrouped = new Set(reflowed.map(c => c.cardH)).size !==
                      new Set(cards.map(c => c.cardH)).size ||
                      reflowed.some(c => c.cardH !== cards.find(x => x.world === c.world).cardH);
    check('the wider viewport really does reshuffle the rows', regrouped,
        'nothing moved -- the neighbour check below proves nothing');
    // Tolerance of 1px, and it is load-bearing rather than a fudge: when a card stretches to a
    // taller neighbour the leftover height goes to .card-slack, but the browser distributes a
    // table's spare height across rows and a single rounding pixel can still land on a row with
    // a fractional height (the Access row, which holds a badge). The bug being guarded against
    // was a card's rows moving 90px apart because of its neighbours. Anything above a pixel is
    // that bug coming back; a pixel is arithmetic.
    const drift = (a, b) => {
        const x = a.split(',').map(Number), y = b.split(',').map(Number);
        if (x.length !== y.length) { return Infinity; }
        return Math.max(...x.map((v, i) => Math.abs(v - y[i])));
    };
    const drifted = reflowed
        .map(c => ({ world: c.world, was: before.get(c.world), now: c.pitches.join(','),
                     by: drift(before.get(c.world) || '', c.pitches.join(',')) }))
        .filter(c => c.by > 1);
    check('no card changes its own row spacing when its neighbours change',
        drifted.length === 0,
        drifted.map(c => `${c.world}: [${c.was}] -> [${c.now}] (${c.by}px)`).join(' | '));

    console.log('\n5. Launch sits under the name, separated, by the same amount everywhere');
    const n2l = [...new Set(cards.map(c => c.nameToLaunch))];
    check('the same on every card', n2l.length === 1,
        cards.map(c => `${c.world}=${c.nameToLaunch}`).join(' '));
    // 6-14px. The band is there to catch the two ways this has actually gone wrong: rows
    // absorbing slack and pushing Launch tens of pixels below the name, and the opposite --
    // 2px, which read as one wrapped line rather than a name and its status. 7px is the
    // deliberate value; the name and Launch are a heading and its subtitle, not two entries.
    check('and visibly separated (6-14px)', n2l.every(v => v >= 6 && v <= 14), `${n2l}`);

    console.log('\n6. Cards sharing a row are the same height');
    // A modded card and a vanilla card have different content, and at the default viewport they
    // never share a row -- so this has to be measured somewhere wide enough to mix them. The
    // card is a flex item AND (since the trophy fix) a flex container; making it a container
    // must not cost it its own align-self: stretch.
    await p.setViewportSize({ width: 1700, height: 1200 });
    const rows = await p.evaluate(() => {
        const byTop = {};
        for (const box of document.querySelectorAll('.catbox')) {
            const r = box.getBoundingClientRect();
            const k = Math.round(r.top);
            (byTop[k] = byTop[k] || []).push({
                world: box.dataset.world, vanilla: box.classList.contains('catbox-vanilla'),
                h: Math.round(r.height) });
        }
        return Object.values(byTop);
    });
    const mixed = rows.filter(r => r.length > 1 && new Set(r.map(c => c.vanilla)).size > 1);
    check('a row mixing modded and vanilla cards exists to check', mixed.length > 0,
        'widen the viewport or add a world -- this check proved nothing');
    for (const row of mixed) {
        check(`row [${row.map(c => c.world).join(', ')}] is one height`,
            new Set(row.map(c => c.h)).size === 1, row.map(c => `${c.world}=${c.h}`).join(' '));
    }
    await p.setViewportSize({ width: 1400, height: 1200 });

    console.log('\nCONTROL: these checks can fail');
    // Re-create the original fault: a pinned height is only harmless because those cells are
    // real table cells, where height is a minimum. Take display:table-cell away and the same
    // pin collapses them again, which is exactly what shipped. Pinning height ALONE no longer
    // does anything -- that is the point of the fix, and why this control has to remove the
    // display too or it would pass for the wrong reason.
    await p.addStyleTag({ content:
        '.catbox td.world-seed, .catbox td.world-endpoint' +
        ' { display: block !important; overflow: hidden !important; height: 1px !important; }' });
    const broken = await p.evaluate(MEASURE);
    check('re-pinning seed/endpoint height collapses them again',
        broken.some(c => c.collapsed.length > 0),
        'the collapse check cannot see the bug it was written for');

    await browser.close();
    console.log(`\n${pass} passed, ${fail} failed`);
    process.exit(fail === 0 ? 0 : 1);
})();
