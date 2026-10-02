#!/bin/bash
#
# 2.53 -- the admin dashboard's join-code chip must not accumulate on every poll.
#
# THE BUG THIS PINS
# launchButtonHtml() returns the Launch anchor AND the join-code chip as one string, but the
# poll finds the button with querySelector('[data-action="launch"]') -- which matches only the
# anchor. Assigning that combined string to launchBtn.outerHTML therefore inserted a fresh chip
# while leaving the previous one in place. The dashboard polls every 5 seconds, so a crossplay
# world's row grew one more copy of its join code every five seconds until it was unreadable.
#
# WHY THIS TEST IS AN ORACLE AND A GREP IS NOT
# "Does a chip exist" answers 1 the same whether there is one chip or thirty. The only question
# that separates the bug from the fix is HOW MANY chips exist after N polls, so that is what is
# asserted -- and it is asserted by running the REAL launchButtonHtml() and the REAL
# updateActionButtons() out of index.php against a minimal DOM, not by restating them here.
# A restated copy would pass while the shipped code stayed broken.

ROOT="$(cd "$(dirname "$0")/.." && pwd)"
INDEX="$ROOT/container/nginx/www/admin/index.php"

command -v node > /dev/null 2>&1 || { echo "SKIP: node is not installed"; exit 0; }

node - "$INDEX" <<'NODE'
const fs = require('fs');
const src = fs.readFileSync(process.argv[2], 'utf8');

let fails = 0;
const pass = m => console.log('  PASS: ' + m);
const fail = m => { console.log('  FAIL: ' + m); fails++; };

// ---------------------------------------------------------------------------------------
// Lift the two real functions. Verified after lifting: a silently-failed extraction would
// make every assertion below pass against nothing.
// ---------------------------------------------------------------------------------------
function lift(name) {
    const start = src.indexOf('function ' + name + '(');
    if (start < 0) { return null; }
    let i = src.indexOf('{', start), depth = 0;
    for (let j = i; j < src.length; j++) {
        if (src[j] === '{') depth++;
        else if (src[j] === '}') { depth--; if (depth === 0) return src.slice(start, j + 1); }
    }
    return null;
}

const launchSrc = lift('launchButtonHtml');
const updateSrc = lift('updateActionButtons');
if (!launchSrc) { console.log('FATAL: could not lift launchButtonHtml()'); process.exit(1); }
if (!updateSrc) { console.log('FATAL: could not lift updateActionButtons()'); process.exit(1); }
pass('lifted launchButtonHtml() and updateActionButtons() from index.php');

// ---------------------------------------------------------------------------------------
// Minimal DOM.
//
// NODE-BASED, not offset-based, and that distinction is the whole reason this harness is
// trustworthy. updateActionButtons() looks up launch, start and stop FIRST and only then
// starts assigning outerHTML. A model that handed back byte offsets into one big string had
// those offsets invalidated by the first assignment, and the later writes spliced into the
// middle of unrelated markup -- producing corrupted output that happened to look like the bug
// while not being it. A real DOM reference survives its siblings being replaced, so elements
// are modelled as their own records and identified by object identity.
// ---------------------------------------------------------------------------------------
function parseElements(html) {
    // The row is flat: a sequence of <a>...</a> and <span>...</span>, no nesting.
    const out = [];
    const re = /<(a|span)\b([^>]*)>([\s\S]*?)<\/\1>/g;
    let m;
    while ((m = re.exec(html)) !== null) {
        const attrs = m[2];
        const am = attrs.match(/data-action="([^"]+)"/);
        out.push({ html: m[0], action: am ? am[1] : null });
    }
    return out;
}

function makeRow(html) {
    const row = {
        kids: parseElements(html),
        get html() { return row.kids.map(k => k.html).join(''); },
        find(action) {
            const node = row.kids.find(k => k.action === action);
            if (!node) { return null; }
            return {
                get outerHTML() { return node.html; },
                set outerHTML(v) {
                    // Assigning markup that contains SEVERAL elements replaces this one node
                    // with all of them, which is what a browser does and is precisely the
                    // case that matters here: launchButtonHtml() returns anchor + chip.
                    const at = row.kids.indexOf(node);
                    row.kids.splice(at, 1, ...parseElements(v));
                },
                remove() {
                    const at = row.kids.indexOf(node);
                    if (at >= 0) { row.kids.splice(at, 1); }
                },
                set innerHTML(v) {}, set className(v) {}, set title(v) {}, set textContent(v) {},
                get parentNode() { return { querySelector: () => null }; },
                insertAdjacentElement() {},
            };
        },
        querySelector(sel) {
            const m = sel.match(/\[data-action="([^"]+)"\]/);
            if (m) { return row.find(m[1]); }
            return null;
        },
    };
    return row;
}

const escapeAttr = s => String(s).replace(/&/g, '&amp;').replace(/"/g, '&quot;').replace(/</g, '&lt;');
const encodeURIComponentSafe = encodeURIComponent;
const reflowActionGroups = () => {};

const launchButtonHtml = eval('(' + launchSrc.replace(/^function\s+launchButtonHtml/, 'function') + ')');
const updateActionButtons = eval('(' + updateSrc.replace(/^function\s+updateActionButtons/, 'function') + ')');

// A running crossplay modded world -- the only combination that renders a chip.
const world = {
    name: 'test123', mode: 'running', vanilla: 0, modCount: 7, beta: 0,
    launchHref: 'phvalheim://?abc', launchPlayfab: true, launchJoinCode: '572979',
    restartPending: [],
};

const freshRow = () => makeRow(
    '<td>' + launchButtonHtml(world) +
    '<span class="action-btn disabled" data-action="start">Start</span>' +
    '<a href="#" class="action-btn" data-action="stop">Stop</a>' +
    '<span class="action-btn disabled" data-action="edit-mods">Edit Mods</span>' +
    '<span class="action-btn disabled" data-action="update">Update</span>' +
    '<span class="action-btn disabled" data-action="delete">Delete</span>' +
    '</td>');

const chips = r => (r.html.match(/join-code-chip/g) || []).length;
const codes = r => (r.html.match(/572979/g) || []).length;

console.log('');
console.log('== harness fidelity (checked before anything is concluded from it) ==');
// An earlier cut of this harness invalidated its own element references on the first write
// and spliced later writes into unrelated markup. It still "failed on the buggy code", for
// the wrong reason. So the model is verified before its verdicts are trusted: every button
// must survive a poll exactly once, and no text may be lost or duplicated.
{
    const r = freshRow();
    const before = ['launch', 'start', 'stop', 'edit-mods', 'update', 'delete']
        .map(a => (r.html.match(new RegExp('data-action="' + a + '"', 'g')) || []).length);
    updateActionButtons(r, world);
    const after = ['launch', 'start', 'stop', 'edit-mods', 'update', 'delete']
        .map(a => (r.html.match(new RegExp('data-action="' + a + '"', 'g')) || []).length);
    const okBefore = before.every(n => n === 1);
    const okAfter = after.every(n => n === 1);
    okBefore && okAfter
        ? pass('all six buttons present exactly once before and after a poll')
        : fail('button counts before=' + before.join(',') + ' after=' + after.join(',') + ' (want all 1)');

    // No stray tag fragments: every < opens a tag that closes.
    const opens = (r.html.match(/</g) || []).length, closes = (r.html.match(/>/g) || []).length;
    opens === closes
        ? pass('markup is balanced after a poll (no spliced fragments)')
        : fail('markup unbalanced: ' + opens + ' < vs ' + closes + ' >');
}

console.log('');
console.log('== a freshly rendered row ==');
let row = freshRow();
chips(row) === 1 ? pass('renders exactly 1 chip') : fail('renders ' + chips(row) + ' chips, expected 1');

console.log('');
console.log('== after repeated polls (the actual bug) ==');
for (const n of [1, 2, 12, 120]) {
    row = freshRow();
    for (let i = 0; i < n; i++) { updateActionButtons(row, world); }
    const c = chips(row), d = codes(row);
    if (c === 1 && d === 1) {
        pass(n + ' poll(s) -> still exactly 1 chip and 1 join code');
    } else {
        fail(n + ' poll(s) -> ' + c + ' chips and ' + d + ' copies of the code, expected 1 and 1');
    }
}

console.log('');
console.log('== the chip must disappear when the world stops ==');
// A stale code advertises a lobby that no longer exists, so stopping must clear it.
row = freshRow();
updateActionButtons(row, world);
updateActionButtons(row, Object.assign({}, world, { mode: 'stopped' }));
chips(row) === 0
    ? pass('a stopped world carries no chip')
    : fail('a stopped world still shows ' + chips(row) + ' chip(s) advertising a dead lobby');

console.log('');
console.log('== a non-crossplay world never gets one (control) ==');
// Without this, an implementation that renders a chip unconditionally would pass everything above.
const plain = Object.assign({}, world, { launchPlayfab: false, launchJoinCode: null });
const plainRow = makeRow('<td>' + launchButtonHtml(plain) +
    '<span class="action-btn disabled" data-action="start">Start</span>' +
    '<a href="#" class="action-btn" data-action="stop">Stop</a>' +
    '<span class="action-btn disabled" data-action="edit-mods">Edit Mods</span>' +
    '<span class="action-btn disabled" data-action="update">Update</span>' +
    '<span class="action-btn disabled" data-action="delete">Delete</span></td>');
updateActionButtons(plainRow, plain);
updateActionButtons(plainRow, plain);
chips(plainRow) === 0
    ? pass('a non-crossplay world shows no chip after polling')
    : fail('a non-crossplay world grew ' + chips(plainRow) + ' chip(s)');

console.log('');
if (fails === 0) { console.log('ALL PASS'); process.exit(0); }
console.log(fails + ' FAILURE(S)');
process.exit(1);
NODE
