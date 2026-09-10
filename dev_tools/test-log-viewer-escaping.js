// Oracle test for the log viewer's blank-line explosion.
//
// THE BUG: log text went into the page unescaped. Valheim stack traces are full of
// angle-bracketed fragments (<207a02655d0b483ca679ce75910d2c5e>, <ZNet::SaveWorld>,
// <DelayedSave>). The browser parsed them as unknown TAGS, swallowed following lines
// into elements that never closed, and relocated the <br> separators -- producing runs
// of up to 63 blank lines while other entries were glued together with no break.
//
// The assertions are on the RENDERED text (innerText) and on the DOM the parser built,
// because that is where the damage happened -- the log FILE was always fine. Counting
// blank lines in the file would pass against the bug.
//
// Usage:
//   docker run --rm --network host -v "$PWD/dev_tools":/w -w /w \
//     mcr.microsoft.com/playwright:v1.47.0-jammy bash -c \
//     'npm i --silent --prefix /tmp/pw playwright@1.47.0 >/dev/null 2>&1;
//      NODE_PATH=/tmp/pw/node_modules node test-log-viewer-escaping.js http://127.0.0.1:8081 <logfile>'

const { chromium } = require('playwright');
const BASE = process.argv[2] || 'http://127.0.0.1:8081';
const LOGFILE = process.argv[3];

let pass = 0, fail = 0;
function check(name, ok, detail) {
    if (ok) { pass++; console.log(`  PASS  ${name}`); }
    else { fail++; console.log(`  FAIL  ${name}${detail ? ' -- ' + detail : ''}`); }
}

(async () => {
    if (!LOGFILE) { console.log('usage: ... <base> <logfile>'); process.exit(1); }
    const browser = await chromium.launch();
    const page = await browser.newPage({ viewport: { width: 1600, height: 900 } });
    const errors = [];
    page.on('pageerror', e => errors.push(String(e).slice(0, 200)));

    await page.goto(`${BASE}/readLog.php?logfile=${encodeURIComponent(LOGFILE)}`,
        { waitUntil: 'networkidle', timeout: 60000 });
    await page.waitForTimeout(4000);

    const m = await page.evaluate(() => {
        const el = document.getElementById('logContent');
        if (!el) return { missing: true };
        const lines = el.innerText.split('\n');
        let blanks = 0, longestRun = 0, run = 0;
        for (const l of lines) {
            if (l.trim() === '') { blanks++; run++; if (run > longestRun) longestRun = run; }
            else run = 0;
        }
        // Elements the HTML parser invented from log text. A real page here should only
        // contain span/br/p/div -- anything else came from an unescaped angle bracket.
        const allowed = new Set(['SPAN', 'BR', 'P', 'DIV', 'B', 'I', 'STRONG', 'EM']);
        const invented = {};
        el.querySelectorAll('*').forEach(n => {
            if (!allowed.has(n.tagName)) invented[n.tagName] = (invented[n.tagName] || 0) + 1;
        });
        return {
            totalLines: lines.length,
            blanks,
            pctBlank: Math.round((blanks / lines.length) * 1000) / 10,
            longestRun,
            inventedTagKinds: Object.keys(invented).length,
            inventedSample: Object.keys(invented).slice(0, 5),
            // The literal text must survive escaping and be READABLE, not consumed.
            showsGuidText: /<[0-9a-f]{16,}>/.test(el.innerText),
            renderedChars: el.innerText.length
        };
    });

    if (m.missing) { console.log('#logContent not found'); process.exit(1); }
    console.log(`\n(rendered ${m.totalLines} lines, ${m.blanks} blank = ${m.pctBlank}%, longest run ${m.longestRun})`);

    console.log('\nCase 1: the HTML parser invented no elements from log text');
    check('no tags conjured out of <...> fragments',
        m.inventedTagKinds === 0, JSON.stringify(m.inventedSample));

    console.log('\nCase 2: no runs of blank lines');
    // One blank line can be legitimate (Valheim emits some itself and a replacement
    // banner adds spacing). A RUN is always the parser relocating <br>s.
    check('longest blank run <= 1', m.longestRun <= 1, `longestRun=${m.longestRun}`);
    check('blank lines under 10% of the view', m.pctBlank < 10, `${m.pctBlank}%`);

    console.log('\nCase 3: the bracketed text is still visible to the operator');
    // The point of escaping is that the GUIDs become readable, not that they vanish.
    check('assembly GUIDs render as text', m.showsGuidText,
        'expected e.g. <207a02655d0b483ca679ce75910d2c5e> in the visible text');

    console.log('\nCase 4: no JS errors');
    check('no uncaught page errors', errors.length === 0, errors.join(' | '));

    await browser.close();
    console.log(`\n${pass} passed, ${fail} failed`);
    process.exit(fail === 0 ? 0 : 1);
})();
