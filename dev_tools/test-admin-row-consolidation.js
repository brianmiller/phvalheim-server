// Oracle test: a RUNNING world's row is no taller than a stopped one (2.58).
// requires: container
//
// WHAT IT GUARDS. Until 2.58 the Resources cell stacked a third item, PLAYERS, on every running
// world and a fourth, UPDATE, when one was waiting: an 82px / 105px row next to 60px stopped
// rows, and the whole table reflowed on every start and stop. 2.58 moves the player count into
// the Status pill ("Running · 3") and the update into a badge on the Settings button, where its
// tab is. Three render sites must agree -- the PHP online row, the JS row template and
// updateWorldRow(), which rewrites the pill and the button on every 5-second poll -- and the
// last one is the trap: a count the PHP rendered that the poll then wipes looks right for
// exactly five seconds. test-admin-row-layout.js measures the real rows but the dev box never
// has a running world with players, so it has never seen this case.
//
// HOW. The engine is stopped for the duration (it would reconcile a process-less 'running' row
// back to stopped within 2s), the fixture world is set running with a player count and an
// update waiting, and the page is read at three widths AFTER a poll has landed. Then the
// stimulus is withdrawn -- count and update cleared in the database -- and the row must follow
// on the next poll: that is what proves the pill and badge are READ, not pasted. The JS
// template is exercised directly through createWorldRow(). A stopped modded row is the control
// for the height bound and for "no count on a stopped pill".
//
// Usage:  node test-admin-row-consolidation.js [adminBase] [container] [moddedWorld]
//   adminBase    http://127.0.0.1:8081
//   container    phvalheim-dev
//   moddedWorld  a STOPPED, non-vanilla world (it is set running on paper and restored)

const { chromium } = require('playwright');
const { execFileSync } = require('child_process');

const BASE = process.argv[2] || 'http://127.0.0.1:8081';
const CONTAINER = process.argv[3] || 'phvalheim-dev';
const WORLD = process.argv[4] || 'midgard';
const WIDTHS = [1280, 1557, 1920];

let pass = 0, fail = 0;
const ok  = (m) => { console.log('  PASS  ' + m); pass++; };
const bad = (m, d) => { console.log('  FAIL  ' + m); if (d) console.log('        ' + d); fail++; };

function sql(q) {
    return execFileSync('docker', ['exec', CONTAINER, 'mysql', '-N', '-e', q, 'phvalheim'], { encoding: 'utf8' }).trim();
}
function sv(cmd) {
    return execFileSync('docker', ['exec', CONTAINER, 'supervisorctl', cmd, 'phvalheim'], { encoding: 'utf8' }).trim();
}
const q = (s) => "'" + String(s).replace(/\\/g, '\\\\').replace(/'/g, "\\'") + "'";

// The count on the row is READ, every two minutes, from the Companion's roster file by
// playerMonitor (cron) -- a database value with no file behind it is cleared as unknown on the
// next tick, and with player counts switched off every observation is cleared. So the fixture
// is the file, not the row: plant it, and the row follows (which is itself the thing to test).
// It cost this test two widths once, when a cron tick fell between them (2026-10-23).
const STATS_DIR = `/opt/stateful/games/valheim/worlds/${WORLD}/game/BepInEx/data/PhValheim.Companion`;
function plantRoster(count) {
    const names = Array.from({ length: count }, (_, i) => `{"name":"oracle-${i + 1}","character_id":"${i + 1}:1"}`).join(',');
    const json = `{"timestamp_utc":"now","source":"companion","version":"test","player_count":${count},"players":[${names}]}`;
    execFileSync('docker', ['exec', CONTAINER, 'bash', '-c', `mkdir -p '${STATS_DIR}' && printf '%s\n' '${json}' > '${STATS_DIR}/player_stats.json' && chown -R phvalheim:phvalheim '${STATS_DIR}'`]);
}
function removeRoster() { try { execFileSync('docker', ['exec', CONTAINER, 'rm', '-f', `${STATS_DIR}/player_stats.json`]); } catch (e) { /* already gone */ } }

// Everything the fixture touches on the world row, read back verbatim for the restore.
const COLS = ['mode', 'player_count', 'player_count_at', 'player_count_source',
    'update_available_game', 'update_available_mods', 'update_state', 'update_mods_list'];

(async () => {
    const pre = sql(`SELECT IFNULL(vanilla,0), mode FROM worlds WHERE name=${q(WORLD)}`).split('\t');
    if (pre.length < 2) { console.log(`HARNESS: world ${WORLD} not found`); process.exit(2); }
    if (pre[0] !== '0' || pre[1] !== 'stopped') {
        console.log(`HARNESS: ${WORLD} must be a stopped modded world (vanilla=${pre[0]}, mode=${pre[1]})`); process.exit(2);
    }
    // A plain sentinel for NULL: mysql's batch output escapes backslashes, so '\N' comes back
    // as '\\N' and a backslash sentinel never matches (the first run of this failed its own
    // restore on exactly that and left the fixture's player count behind).
    const saved = sql(`SELECT ${COLS.map(c => `IFNULL(${c},'__NULL__')`).join(',')} FROM worlds WHERE name=${q(WORLD)}`).split('\t');
    const restoreSql = `UPDATE worlds SET ${COLS.map((c, i) => `${c}=${saved[i] === '__NULL__' ? 'NULL' : q(saved[i])}`).join(',')} WHERE name=${q(WORLD)}`;

    const browser = await chromium.launch();
    const page = await browser.newPage();
    const errors = [];
    page.on('pageerror', e => errors.push(String(e).slice(0, 200)));

    let engineStopped = false;
    try {
        sv('stop'); engineStopped = true;
        plantRoster(3);
        // The stimulus: running, 3 players seen just now, one game update and two mod updates.
        sql(`UPDATE worlds SET mode='running', player_count=3, player_count_at=NOW(), player_count_source='companion',
             update_available_game=1, update_available_mods=2, update_state='idle',
             update_mods_list='Oracle-FixtureMod 1.0.0 -> 1.1.0\\nOracle-OtherMod 2.0.0 -> 2.0.1' WHERE name=${q(WORLD)}`);

        const readRows = () => page.evaluate((WORLD) => {
            const rows = {};
            for (const tr of document.querySelectorAll('.worlds-table tbody tr[data-world]')) {
                const tds = Array.from(tr.children);
                const pill = tds[0].querySelector('.status-badge');
                const players = pill && pill.querySelector('.status-players');
                const settings = tr.querySelector('[data-action="settings"]');
                const badge = settings && settings.querySelector('.update-badge');
                const res = tds[3].querySelector('.world-resources');
                const pr = pill ? pill.getBoundingClientRect() : null;
                const cr = tds[0].getBoundingClientRect();
                const group = tds[2].querySelector('.action-group');
                rows[tr.getAttribute('data-world')] = {
                    section: tr.getAttribute('data-section'),
                    height: Math.round(tr.getBoundingClientRect().height),
                    // The two cells that decide a row's height. Below 1400px a crossplay row's
                    // action group wraps its join-code chip onto a second line BY DESIGN (see
                    // test-admin-row-layout.js), which is not what this test is about; the
                    // Resources cell is.
                    resH: res ? Math.round(res.getBoundingClientRect().height) : null,
                    groupH: group ? Math.round(group.getBoundingClientRect().height) : null,
                    pillText: pill ? pill.textContent.replace(/\s+/g, ' ').trim() : null,
                    pillCount: players ? players.textContent.replace(/\s+/g, ' ').trim() : null,
                    pillGlyph: !!(players && players.querySelector('.status-players-glyph')),
                    updateIsLink: !!tr.querySelector('a[data-action="update"]'),
                    updateTag: (tr.querySelector('[data-action="update"]') || {}).tagName || null,
                    pillTitle: players ? (players.getAttribute('title') || '') : '',
                    // An ellipsised pill hides the count it was given: scrollWidth is NOT clamped
                    // by the cell, so this sees what the pill wanted.
                    pillClipped: pill ? pill.scrollWidth > pill.clientWidth + 1 : null,
                    pillSpill: pr ? Math.round(pr.right - cr.right) : null,
                    badgeText: badge ? badge.textContent.trim() : null,
                    badgeTitle: badge ? (badge.getAttribute('title') || '') : '',
                    settingsVisible: settings ? getComputedStyle(settings).display !== 'none' : null,
                    items: res ? res.querySelectorAll('.world-resource-item').length : null,
                    labels: res ? Array.from(res.querySelectorAll('.resource-label')).map(e => e.textContent.trim()) : [],
                    modded: !!(res && res.querySelector('.world-load-bar')),
                };
            }
            return rows;
        }, WORLD);

        // THE SERVED HTML FIRST, before any JavaScript runs. Every measurement below is taken
        // after a poll, and the poll's JSON carries the count and the update flags -- so a PHP
        // render that dropped them (getWorldsData() never copied them into its row array, for
        // every release up to 2.58) looked complete five seconds after load and empty on load.
        console.log('\n=== the server-rendered row (no JavaScript) ===');
        const raw = await (await page.request.get(BASE + '/index.php')).text();
        const rowHtml = (raw.match(new RegExp(`<tr data-world="${WORLD}"[^>]*>([\\s\\S]*?)</tr>`)) || [])[1] || '';
        rowHtml ? ok('the served page has the fixture row') : bad('the served page has the fixture row', 'no <tr data-world=...>');
        /class="status-players world-players-value"[^>]*><svg class="status-players-glyph"[^>]*>[\s\S]*?<\/svg>3</.test(rowHtml)
            ? ok('PHP render: the pill carries the player glyph and "3" before any poll')
            : bad('PHP render: the pill carries the player glyph and "3" before any poll', rowHtml.replace(/\s+/g, ' ').slice(0, 300));
        // "->" arrives entity-encoded (&gt;) from htmlspecialchars; the browser decodes it.
        /class="update-badge" title="[^"]*Oracle-FixtureMod 1\.0\.0 -(?:&gt;|>) 1\.1\.0[^"]*">3</.test(rowHtml)
            ? ok('PHP render: Settings carries badge "3" naming the mod before any poll')
            : bad('PHP render: Settings carries badge "3" naming the mod before any poll', (rowHtml.match(/update-badge[^<]*/) || ['no badge'])[0].slice(0, 200));

        for (const width of WIDTHS) {
            await page.setViewportSize({ width, height: 1000 });
            await page.goto(BASE + '/index.php', { waitUntil: 'networkidle', timeout: 60000 });
            // AFTER a poll: the PHP render alone is not what the operator looks at.
            await page.waitForTimeout(6000);
            const rows = await readRows();
            const r = rows[WORLD];
            console.log(`\n=== ${width}px ===`);
            // Every number below is computed geometry, which is meaningless if the stylesheet
            // did not arrive. This passed 43/43 once on a page nginx served with NO stylesheet
            // (a docker cp left the file 0660): every row was 118px and the pill was plain text.
            const styled = await page.evaluate(() => { const p = document.querySelector('.worlds-table .status-badge'); return !!p && getComputedStyle(p).display === 'inline-flex'; });
            if (!styled) { bad(`${width}px: the stylesheet is applied (status pill is inline-flex)`, 'phvalheimStyles.css did not load -- nothing measured below means anything'); continue; }
            if (!r) { bad(`${width}px: fixture row present`, `no tr[data-world="${WORLD}"]`); continue; }
            const control = Object.entries(rows).find(([n, x]) => n !== WORLD && x.section === 'offline' && x.modded);
            console.log(`  ${WORLD} [${r.section}] h=${r.height}px pill="${r.pillText}" badge=${r.badgeText} items=${r.items} labels=${r.labels.join('/')}`
                + (control ? `   control ${control[0]} h=${control[1].height}px pill="${control[1].pillText}" badge=${control[1].badgeText}` : '   (no stopped modded control row)'));

            r.section === 'online' ? ok(`${width}px: fixture world rendered in the online section`)
                : bad(`${width}px: fixture world rendered in the online section`, `section=${r.section} (is the engine stopped?)`);
            r.pillCount === '3' ? ok(`${width}px: the Status pill carries the player count ("${r.pillText}")`)
                : bad(`${width}px: the Status pill carries the player count`, `pill="${r.pillText}" count=${JSON.stringify(r.pillCount)}`);
            /^3 players online\. /.test(r.pillTitle) && /Companion/.test(r.pillTitle) && /source: companion/.test(r.pillTitle) && !/Approximate|world log|best-effort/i.test(r.pillTitle)
                ? ok(`${width}px: the count's title says what it is ("3 players online"), credits the Companion and names its source`)
                : bad(`${width}px: the count's title says what it is, credits the Companion and names its source`, r.pillTitle.slice(0, 160));
            r.pillGlyph ? ok(`${width}px: the pill marks the number with the player glyph`) : bad(`${width}px: the pill marks the number with the player glyph`, 'no .status-players-glyph inside .status-players');
            r.updateIsLink ? ok(`${width}px: a RUNNING world's Update button is a door (a link), not a disabled span`) : bad(`${width}px: a RUNNING world's Update button is a door`, `update button tag=${r.updateTag}`);
            r.pillClipped === false && r.pillSpill <= 1 ? ok(`${width}px: the pill with its count is neither ellipsised nor spilling (${r.pillSpill}px)`)
                : bad(`${width}px: the pill with its count is neither ellipsised nor spilling`, `clipped=${r.pillClipped} spill=${r.pillSpill}px`);
            r.badgeText === '3' ? ok(`${width}px: Settings carries the update badge "3" (1 game + 2 mods)`)
                : bad(`${width}px: Settings carries the update badge "3"`, `badge=${JSON.stringify(r.badgeText)}`);
            /Valheim server/.test(r.badgeTitle) && /2 mods/.test(r.badgeTitle) ? ok(`${width}px: the badge's title names what is waiting`)
                : bad(`${width}px: the badge's title names what is waiting`, r.badgeTitle.slice(0, 120));
            // "1" told the operator nothing about WHICH mod (the maintainer, 2026-10-23). The
            // hover text now lists them, one per line, installed -> available.
            /Oracle-FixtureMod 1\.0\.0 -> 1\.1\.0/.test(r.badgeTitle) && /Oracle-OtherMod/.test(r.badgeTitle)
                ? ok(`${width}px: the badge's title names the mods themselves`)
                : bad(`${width}px: the badge's title names the mods themselves`, r.badgeTitle.slice(0, 200));
            r.settingsVisible ? ok(`${width}px: the Settings button is visible on a running row (the badge has somewhere to be)`)
                : bad(`${width}px: the Settings button is visible on a running row`, `display none`);
            r.items === 2 && !r.labels.includes('PLAYERS') && !r.labels.includes('UPDATE')
                ? ok(`${width}px: Resources holds MEM and HEALTH only (${r.labels.join(', ')})`)
                : bad(`${width}px: Resources holds MEM and HEALTH only`, `${r.items} items: ${r.labels.join(', ')}`);
            if (control) {
                const [cn, c] = control;
                // THE COMPLAINT. Before 2.58 this row's Resources cell measured 65px (88px with
                // an update) against the stopped row's 43px, and the row 82px / 105px against 60.
                r.resH <= c.resH + 1 ? ok(`${width}px: running row's Resources cell (${r.resH}px) is no taller than the stopped modded row ${cn}'s (${c.resH}px)`)
                    : bad(`${width}px: running row's Resources cell is no taller than a stopped modded row's`, `${r.resH}px vs ${cn} ${c.resH}px`);
                if (r.groupH !== null && r.groupH > 40) {
                    console.log(`  SKIP  ${width}px: whole-row height -- the action group wrapped (${r.groupH}px: a join-code chip below 1400px wraps by design)`);
                } else {
                    r.height <= c.height + 2 ? ok(`${width}px: running row (${r.height}px) is no taller than the stopped modded row ${cn} (${c.height}px)`)
                        : bad(`${width}px: running row is no taller than a stopped modded row`, `${r.height}px vs ${cn} ${c.height}px`);
                }
                c.pillCount === null ? ok(`${width}px: control -- a stopped row's pill carries no count`)
                    : bad(`${width}px: control -- a stopped row's pill carries no count`, `"${c.pillText}"`);
            } else {
                bad(`${width}px: a stopped modded control row exists`, 'need a second non-vanilla stopped world');
            }
        }

        // ---- the poll keeps the pill and badge in step with the data ----
        console.log('\n=== the poll follows the database (1557px) ===');
        await page.setViewportSize({ width: 1557, height: 1000 });
        await page.goto(BASE + '/index.php', { waitUntil: 'networkidle', timeout: 60000 });
        await page.waitForTimeout(6000);
        let r = (await readRows())[WORLD];
        r && r.pillCount === '3' && r.badgeText === '3' ? ok('after one poll: count and badge survive updateWorldRow()')
            : bad('after one poll: count and badge survive updateWorldRow()', r ? `count=${JSON.stringify(r.pillCount)} badge=${JSON.stringify(r.badgeText)}` : 'row missing');

        // Withdraw the stimulus; the page is NOT reloaded.
        plantRoster(7);
        sql(`UPDATE worlds SET player_count=7, update_available_game=0, update_available_mods=1, update_mods_list='Oracle-OtherMod 2.0.0 -> 2.0.1' WHERE name=${q(WORLD)}`);
        await page.waitForTimeout(6500);
        r = (await readRows())[WORLD];
        r && r.pillCount === '7' ? ok('next poll: the pill shows the NEW count (7) without a reload')
            : bad('next poll: the pill shows the NEW count (7) without a reload', r ? `count=${JSON.stringify(r.pillCount)}` : 'row missing');
        r && r.badgeText === '1' && /1 mod\b/.test(r.badgeTitle) && !/Valheim server/.test(r.badgeTitle)
            ? ok('next poll: the badge shows the NEW total (1) and names only the mod')
            : bad('next poll: the badge shows the NEW total (1) and names only the mod', r ? `badge=${JSON.stringify(r.badgeText)} title="${r.badgeTitle.slice(0, 80)}"` : 'row missing');
        r && /Oracle-OtherMod/.test(r.badgeTitle) && !/Oracle-FixtureMod/.test(r.badgeTitle)
            ? ok('next poll: the hover list followed the database (OtherMod only) -- the poll re-reads the names')
            : bad('next poll: the hover list followed the database (OtherMod only)', r ? r.badgeTitle.slice(0, 200) : 'row missing');

        removeRoster();
        sql(`UPDATE worlds SET player_count_at=NULL, update_available_mods=0 WHERE name=${q(WORLD)}`);
        await page.waitForTimeout(6500);
        r = (await readRows())[WORLD];
        r && r.pillCount === null ? ok('next poll: no observation -> no count in the pill (not a confident 0)')
            : bad('next poll: no observation -> no count in the pill', r ? `count=${JSON.stringify(r.pillCount)}` : 'row missing');
        r && r.badgeText === null ? ok('next poll: nothing waiting -> the badge is gone')
            : bad('next poll: nothing waiting -> the badge is gone', r ? `badge=${JSON.stringify(r.badgeText)}` : 'row missing');

        // ---- the JS row template, driven directly ----
        console.log('\n=== createWorldRow() template ===');
        const tpl = await page.evaluate((WORLD) => {
            if (typeof createWorldRow !== 'function') return { missing: true };
            const base = { name: WORLD, mode: 'running', vanilla: 0, modCount: 4, beta: 0, crossplay: 0, update_state: 'idle',
                player_count: 5, player_count_at: '2026-10-23 00:00:00', player_count_source: 'companion',
                update_available_game: 0, update_available_mods: 1, update_mods_list: 'Oracle-TemplateMod 3.0 -> 3.1' };
            const online = createWorldRow(base, 'online');
            const offline = createWorldRow({ ...base, mode: 'stopped', update_available_mods: 0 }, 'offline');
            const read = (tr) => ({
                count: (tr.querySelector('.status-badge .status-players') || {}).textContent || null,
                badge: (tr.querySelector('[data-action="settings"] .update-badge') || {}).textContent || null,
                badgeTitle: (tr.querySelector('[data-action="settings"] .update-badge') || { getAttribute: () => '' }).getAttribute('title') || '',
                items: tr.querySelectorAll('.world-resource-item').length,
                labels: Array.from(tr.querySelectorAll('.resource-label')).map(e => e.textContent.trim()),
            });
            return { online: read(online), offline: read(offline) };
        }, WORLD);
        if (tpl.missing) {
            bad('createWorldRow() is reachable from the page', 'not a global function');
        } else {
            tpl.online.count && tpl.online.count.trim() === '5' ? ok('template: online row pill carries the count "5"')
                : bad('template: online row pill carries the count "5"', JSON.stringify(tpl.online));
            tpl.online.badge === '1' ? ok('template: online row Settings carries badge "1"')
                : bad('template: online row Settings carries badge "1"', JSON.stringify(tpl.online));
            /Oracle-TemplateMod 3\.0 -> 3\.1/.test(tpl.online.badgeTitle) ? ok('template: the badge names the mod')
                : bad('template: the badge names the mod', JSON.stringify(tpl.online.badgeTitle));
            tpl.online.items === 2 && !tpl.online.labels.includes('PLAYERS') ? ok('template: online row Resources has MEM and HEALTH only')
                : bad('template: online row Resources has MEM and HEALTH only', JSON.stringify(tpl.online));
            tpl.offline.count === null && tpl.offline.badge === null ? ok('template: offline row has no count and no badge')
                : bad('template: offline row has no count and no badge', JSON.stringify(tpl.offline));
        }

        errors.length === 0 ? ok('no page errors') : bad('no page errors', errors.slice(0, 3).join(' | '));
    } finally {
        removeRoster();
        try { sql(restoreSql); } catch (e) { console.log('RESTORE FAILED: ' + e.message); }
        if (engineStopped) { try { sv('start'); } catch (e) { console.log('ENGINE START FAILED: ' + e.message); } }
        await browser.close();
    }
    console.log(`\n${pass} passed, ${fail} failed`);
    process.exit(fail === 0 ? 0 : 1);
})().catch((e) => { console.error('HARNESS ERROR: ' + e.message); process.exit(2); });
