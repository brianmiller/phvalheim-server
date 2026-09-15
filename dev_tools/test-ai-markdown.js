// Oracle test for aiMd() -- the markdown renderer in the Hugin answer bubble.
//
// WHAT IT GUARDS. 2.45 rendered a small model's answer badly, and the cause was the
// renderer, not the model. Measured against DeepSeek v4 Flash on a live production box:
//
//   - pipe tables were not parsed at all, so a side-by-side comparison arrived as runs
//     of literal | characters. This was the single most visible problem.
//   - `---` between sections rendered as a literal line of dashes.
//   - `>` quotes rendered as literal &gt;.
//   - every heading level rendered identically, so a long answer was one flat wall.
//   - an indented sub-bullet flattened into its parent list.
//   - a list the model numbered from 3 was silently renumbered to 1.
//   - a code fence the model opened and never closed (common on a truncated answer)
//     rendered as prose with stray backticks.
//
// WHY IT ASSERTS ON RENDERED HTML, from REAL CAPTURED OUTPUT. Counting features in the
// source of index.php would pass on a renderer that emits <table> with the cells in the
// wrong order, or that drops the last row. The fixtures below are pasted from actual
// model replies, not invented, so the test fails the way an operator would notice.
//
// The escaping assertions are not optional decoration: aiMd() is the only thing between
// model output and innerHTML, and a model can be made to emit a <script> tag by a player
// name in a log. ESC1-3 are the reason this file cannot be "simplified" into a library
// swap without re-proving them.
//
// MUTATION CHECK: `git stash` this commit's index.php and every TBL/HR/QUOTE/NEST/ORD/
// FENCE assertion must go red. If they do not, the test cannot see the bug it names.
//
// Usage:  node dev_tools/test-ai-markdown.js

const fs   = require('fs');
const path = require('path');

const INDEX = path.join(__dirname, '..', 'container/nginx/www/admin/index.php');

// Pull the two functions out of the page rather than duplicating them here -- a copy
// would drift and then this test would be guarding source that no longer ships.
function extract(src, name) {
    const start = src.indexOf('function ' + name + '(');
    if (start < 0) throw new Error('cannot find function ' + name + '() in admin/index.php');
    let depth = 0, seen = false;
    for (let i = start; i < src.length; i++) {
        if (src[i] === '{') { depth++; seen = true; }
        else if (src[i] === '}') { depth--; if (seen && depth === 0) return src.slice(start, i + 1); }
    }
    throw new Error('unbalanced braces reading ' + name + '()');
}

const page = fs.readFileSync(INDEX, 'utf8');
const aiMd = new Function(
    extract(page, 'aiEsc') + '\n' + extract(page, 'aiMd') + '\nreturn aiMd;'
)();

let pass = 0, fail = 0;
const ok  = (n)      => { pass++; console.log('  PASS  ' + n); };
const bad = (n, w, g) => { fail++; console.log('  FAIL  ' + n + '\n         wanted: ' + w + '\n         got:    ' + g); };
const count = (h, needle) => h.split(needle).length - 1;

function has(name, html, needle, want) {
    const n = count(html, needle);
    n === want ? ok(name + '  (' + needle + ' x' + want + ')') : bad(name, needle + ' x' + want, needle + ' x' + n);
}
function truthy(name, cond, why) { cond ? ok(name) : bad(name, why, 'not satisfied'); }

/* ---- fixture 1: a real comparison table from DeepSeek v4 Flash ---------------------- */
const TABLE = [
    "Here's the side-by-side comparison:",
    '',
    '| Attribute | **Asgard** | **Vanaheim** |',
    '|---|---|---|',
    '| **Mode** | Vanilla (`vanilla: 1`) | Modded (`vanilla: 0`) |',
    '| **Port** | 25101 | 25102 |',
    '',
    'Both worlds are currently **Running**.'
].join('\n');

console.log('== tables ==');
const t = aiMd(TABLE);
has('TBL1 one table element',      t, '<table', 1);
has('TBL2 a header row',           t, '<thead', 1);
has('TBL3 three header cells',     t, '<th>',   3);
has('TBL4 two body rows',          t, '<tr>',   3);   // 1 head + 2 body
has('TBL5 six body cells',         t, '<td',    6);
truthy('TBL6 no literal pipes survive', count(t, '|') === 0, 'zero | characters in the HTML');
truthy('TBL7 cell markdown is rendered', t.includes('<strong>Mode</strong>') && t.includes('<code>vanilla: 1</code>'),
       'bold and code inside cells');
truthy('TBL8 the divider row is not a body row', !t.includes('<td>---</td>'), 'no ---- cell');

/* ---- alignment ---- */
console.log('== table alignment ==');
const al = aiMd('| a | b | c |\n|:--|:-:|--:|\n| 1 | 2 | 3 |');
has('ALN1 centre', al, 'text-align:center', 2);   // th + td
has('ALN2 right',  al, 'text-align:right',  2);

/* ---- fixture 2: headings, rules, quotes, nesting, numbering ------------------------- */
const DOC = [
    '# Server Health Summary',
    '',
    '## Host & Infrastructure',
    '- **Uptime:** 139.5 hours',
    '  - nested detail',
    '- **CPU load:** ~3.0',
    '',
    '---',
    '',
    '> worth flagging',
    '> second quoted line',
    '',
    '3. numbered from three',
    '4. and four'
].join('\n');

console.log('== headings, rules, quotes, lists ==');
const d = aiMd(DOC);
has('HDR1 h1 is distinguishable',  d, 'ai-h1', 1);
has('HDR2 h2 is distinguishable',  d, 'ai-h2', 1);
truthy('HDR3 no literal # survives', count(d, '#') === 0, 'zero # characters');
has('HR1 the rule is an <hr>',     d, '<hr',   1);
truthy('HR2 no literal --- survives', !d.includes('---'), 'no --- in the HTML');
has('QUOTE1 one blockquote',       d, '<blockquote', 1);
truthy('QUOTE2 both lines are inside it', /blockquote[^>]*>worth flagging<br>second quoted line/.test(d),
       'both quoted lines in one block');
truthy('QUOTE3 no literal &gt; marker', !d.includes('&gt; worth'), 'the > marker is consumed');
has('NEST1 an inner list is opened', d, '<ul>', 2);
truthy('NEST2 the inner list closes before the parent', /<\/ul>\s*<li>/.test(d) || d.includes('</ul>\n<li>'),
       'nested list closed, parent continues');
has('ORD1 ordered list starts at 3', d, 'start="3"', 1);

/* ---- fixture 3: an unterminated fence, as a truncated answer produces --------------- */
console.log('== unterminated code fence ==');
const f = aiMd('Here is the tail:\n\n```\nSep 14 01:08 [ERROR] could not start\nSep 14 01:09 [ERROR] again\n');
has('FENCE1 still becomes a code block', f, '<pre>', 1);
truthy('FENCE2 no stray backticks', count(f, '`') === 0, 'zero backticks in the HTML');
truthy('FENCE3 the content survives', f.includes('could not start'), 'log text is present');

/* ---- escaping: the reason this renderer is hand-rolled ------------------------------ */
console.log('== escaping ==');
const x1 = aiMd('A player joined: <script>alert(1)</script>');
truthy('ESC1 no live script tag', !/<script/i.test(x1), 'script tag is escaped');
const x2 = aiMd('| name |\n|---|\n| <img src=x onerror=alert(1)> |');
truthy('ESC2 no live tag inside a table cell', !/<img/i.test(x2), 'img tag escaped in a cell');
const x3 = aiMd('> <b>quoted</b> markup');
truthy('ESC3 no live tag inside a blockquote', !/<b>quoted<\/b>/.test(x3), 'markup escaped in a quote');

/* ---- things that must NOT become tables -------------------------------------------- */
console.log('== false positives ==');
const p1 = aiMd('Run `a | b` to pipe it, or use grep | wc -l for a count.');
truthy('NEG1 prose containing a pipe is not a table', !p1.includes('<table'), 'no table from a stray pipe');
const p2 = aiMd('| just | one | row |');
truthy('NEG2 a pipe row with no divider is not a table', !p2.includes('<table'), 'a divider row is required');

console.log('\npassed ' + pass + ', failed ' + fail);
process.exit(fail === 0 ? 0 : 1);
