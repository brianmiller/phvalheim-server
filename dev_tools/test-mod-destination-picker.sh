#!/bin/bash
#
# The mod picker's Server / Client switches.
#
# The functions under test live inside two PHP files as inline JavaScript, so they are LIFTED
# out by name and driven in node -- the same approach as test-admin-joincode-chip.sh. Lifting
# rather than copying is the point: a copy of destinationCell() in this file would keep passing
# after the real one changed.
#
# Both picker pages carry their own copy of ~600 lines of this JS (new_world.php and
# edit_world.php). Every assertion below runs against BOTH, because a change applied to one
# file and not the other is the likeliest way this breaks, and it would be invisible until an
# operator used the other page.
#
# Usage:  dev_tools/test-mod-destination-picker.sh

REPO="$(cd "$(dirname "$0")/.." && pwd)"
ADMIN="$REPO/container/nginx/www/admin"
FAILED=0
PASSED=0

ok(){ printf '  \033[32mPASS\033[0m  %s\n' "$1"; PASSED=$((PASSED+1)); }
no(){ printf '  \033[31mFAIL\033[0m  %s\n' "$1"; [ -n "$2" ] && printf '        %s\n' "$2"; FAILED=$((FAILED+1)); }

command -v node >/dev/null 2>&1 || { echo "FATAL: node is not installed"; exit 2; }

for page in new_world.php edit_world.php; do
    f="$ADMIN/$page"
    echo
    echo "=== $page ==="

    # ---- structural: the column, the state, the handler ---------------------------------
    grep -q "var destSet = {};" "$f" \
        && ok "destSet exists" || no "no destSet in $page"
    grep -q "title: 'Installs on'" "$f" \
        && ok "the table has an Installs on column" || no "no Installs on column in $page"
    grep -q "destinationCell(uuid, isChecked)\]" "$f" \
        && ok "the row renders the destination cell" || no "the row has no destination cell"
    # The new column holds controls, so it must not be sortable -- clicking the header would
    # reorder rows under the operator mid-edit.
    grep -q "orderable: false, targets: \[0, 5\]" "$f" \
        && ok "the destination column is not sortable" || no "the destination column is sortable"
    grep -q "on('change', '.dest-toggle'" "$f" \
        && ok "the switches have a change handler" || no "no .dest-toggle handler"

    # ---- behavioural: lift the two pure functions and drive them -----------------------
    node - "$f" <<'NODEEOF'
const fs = require('fs');
const src = fs.readFileSync(process.argv[2], 'utf8');

function lift(name) {
    const start = src.indexOf('function ' + name + '(');
    if (start < 0) throw new Error('could not lift ' + name);
    let i = src.indexOf('{', start), depth = 0, end = -1;
    for (let j = i; j < src.length; j++) {
        if (src[j] === '{') depth++;
        else if (src[j] === '}') { depth--; if (depth === 0) { end = j + 1; break; } }
    }
    if (end < 0) throw new Error('unbalanced ' + name);
    return src.slice(start, end);
}

let checkedSet = {}, destSet = {}, pinSet = {}, neededDeps = {};
const body = lift('destinationCell') + '\n' + lift('getSelectedMods');
const run = new Function('checkedSet', 'destSet', 'pinSet', 'neededDeps',
    body + '\nreturn { destinationCell, getSelectedMods };');
const api = run(checkedSet, destSet, pinSet, neededDeps);

const results = [];
const chk = (cond, msg, why) => results.push([!!cond, msg, why || '']);

// --- destinationCell -----------------------------------------------------------------
checkedSet['10'] = true; destSet['10'] = [true, true];
let cell = api.destinationCell('10', true);
chk(/data-kind="server"/.test(cell) && /data-kind="client"/.test(cell),
    'a selected mod renders both switches');
chk((cell.match(/checked/g) || []).length === 2,
    'both switches are ON by default', 'got: ' + (cell.match(/checked/g) || []).length);

destSet['10'] = [true, false];
cell = api.destinationCell('10', true);
chk((cell.match(/checked/g) || []).length === 1,
    'server-only renders exactly one checked switch',
    'got ' + (cell.match(/checked/g) || []).length);
// The CONTROL: it must be the SERVER one that is checked, not just "one of them".
const srvIdx = cell.indexOf('data-kind="server"');
const cliIdx = cell.indexOf('data-kind="client"');
const srvSeg = cell.slice(srvIdx, cliIdx);
chk(/checked/.test(srvSeg) && !/checked/.test(cell.slice(cliIdx)),
    'and it is the Server switch, not the Client one',
    'a cell that checked the wrong one passes the count check above');

destSet['10'] = [false, true];
cell = api.destinationCell('10', true);
const s2 = cell.slice(cell.indexOf('data-kind="server"'), cell.indexOf('data-kind="client"'));
chk(!/checked/.test(s2) && /checked/.test(cell.slice(cell.indexOf('data-kind="client"'))),
    'client-only is the mirror image');

// An unselected mod gets no operable control.
cell = api.destinationCell('99', false);
chk(!/dest-toggle/.test(cell), 'an unselected mod renders no switches');

// A dependency the operator did not tick is shown as derived, with no switches -- the engine
// walks the whole closure and this page must not pretend to.
neededDeps['77'] = true;
cell = api.destinationCell('77', false);
chk(/derived/.test(cell) && !/dest-toggle/.test(cell),
    'an untaken dependency shows "derived" and no switches');
chk(/title=/.test(cell), 'and explains itself on hover');

// --- getSelectedMods ------------------------------------------------------------------
Object.keys(checkedSet).forEach(k => delete checkedSet[k]);
Object.keys(destSet).forEach(k => delete destSet[k]);
checkedSet['1'] = true; destSet['1'] = [true, false];
checkedSet['2'] = true; destSet['2'] = [false, true];
checkedSet['3'] = true;   // no destSet entry at all
const sel = api.getSelectedMods();
const byId = {}; sel.forEach(m => byId[m.id] = m);

chk(byId[1] && byId[1].server === true && byId[1].client === false,
    'getSelectedMods carries server-only through',
    JSON.stringify(byId[1]));
chk(byId[2] && byId[2].server === false && byId[2].client === true,
    'and client-only');
chk(byId[3] && byId[3].server === true && byId[3].client === true,
    'a mod with no stored destination defaults to BOTH',
    'absent must not mean off -- that would narrow every pre-2.53 row to nothing');
chk(sel.length === 3, 'every checked mod is in the payload', 'got ' + sel.length);

let bad = 0;
results.forEach(([pass, msg, why]) => {
    if (pass) console.log('  \x1b[32mPASS\x1b[0m  ' + msg);
    else { bad++; console.log('  \x1b[31mFAIL\x1b[0m  ' + msg + (why ? '\n        ' + why : '')); }
});
process.exit(bad ? 1 : 0);
NODEEOF
    [ $? -eq 0 ] || FAILED=$((FAILED+1))
done

echo
echo "=== the PHP save/read path ==="
MC="$REPO/container/nginx/www/includes/modcatalog.php"

grep -q "IFNULL(wm.deploy_server,1) AS deploy_server" "$MC" \
    && ok "worldModSelection reads both destination columns" || no "selection query lacks the columns"
grep -q "'server' => (int)\$r\['deploy_server'\] === 1" "$MC" \
    && ok "...and returns them to the picker" || no "the columns are read but not returned"
grep -q "deploy_server, deploy_client)" "$MC" \
    && ok "saveWorldModSelection persists them" || no "the save path does not write them"

# Absent must mean BOTH. aiactions.php posts bare mod ids through this same function, so a
# falsy default would narrow every AI-created world's mods to nothing.
grep -q "array_key_exists('server', \$m) ? (bool)\$m\['server'\] : true" "$MC" \
    && ok "an absent flag defaults to true, not false" \
    || no "the save path's default for a missing flag is not true" \
         "aiactions.php posts bare ids; a false default installs those mods nowhere"
grep -q "set to install on neither the server nor the" "$MC" \
    && ok "both-off is reported, not silently dropped" || no "both-off is dropped quietly"
# The union across catalogue copies, mirroring fold_by_plugin() in worldMods.py.
grep -q 'dest\[\$win\[.id.\]\]\[0\] = \$dest\[\$win\[.id.\]\]\[0\] || \$dest\[\$loser\[.id.\]\]\[0\]' "$MC" \
    && ok "a collapsed duplicate hands its destinations to the winner" \
    || no "the duplicate collapse drops the loser's destinations" \
         "ticking one catalogue's copy for the client and the other's for the server loses a side"

php -l "$MC" >/dev/null 2>&1 && ok "modcatalog.php parses" || no "modcatalog.php has a syntax error"
for p in new_world.php edit_world.php; do
    php -l "$ADMIN/$p" >/dev/null 2>&1 && ok "$p parses" || no "$p has a syntax error"
done

echo
if [ "$FAILED" -gt 0 ]; then
    printf '\033[31m%d check group(s) failed\033[0m\n\n' "$FAILED"
    exit 1
fi
printf '\033[32mall checks passed\033[0m\n\n'
exit 0
