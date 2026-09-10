// Oracle test: the "access list is empty" heads-up, in a REAL browser.
//
// Driven through Chromium rather than jsdom because the thing under test is whether the modal
// is actually VISIBLE -- it is shown by adding a class whose display rule lives in the
// stylesheet, and jsdom does not do layout, so it would report the overlay as shown either way.
//
// The two must-NOT-show cases are what give this teeth. A modal that appeared for every world
// would pass a show-only test while training operators to click straight through it.
//
// Usage (playwright lives in the image, not in this repo):
//   docker run --rm --network host -v "$PWD/dev_tools":/w -w /w \
//     mcr.microsoft.com/playwright:v1.47.0-jammy bash -c \
//     'npm i --silent --prefix /tmp/pw playwright@1.47.0 >/dev/null 2>&1;
//      NODE_PATH=/tmp/pw/node_modules node test-empty-accesslist-modal.js http://127.0.0.1:8081'

const { chromium } = require('playwright');

const BASE = process.argv[2] || 'http://127.0.0.1:8081';
const SENTINEL_WORLD = 'test2';

let pass = 0, fail = 0;
const check = (name, ok, detail) => {
    if (ok) { pass++; console.log(`  PASS  ${name}`); }
    else { fail++; console.log(`  FAIL  ${name}${detail ? ' -- ' + detail : ''}`); }
};

(async () => {
    const browser = await chromium.launch();
    const page = await browser.newPage();
    await page.goto(`${BASE}/index.php`, { waitUntil: 'networkidle' });

    // Drive the real predicate with a synthetic citizens payload -- the same shape
    // getCitizens returns -- so this tests the decision, not the fetch.
    const decide = (citizens) => page.evaluate((c) => {
        document.getElementById('emptyAccessListOverlay').classList.remove('show');
        maybeWarnEmptyAccessList('SomeWorld', c);
        const el = document.getElementById('emptyAccessListOverlay');
        return {
            hasClass: el.classList.contains('show'),
            visible: window.getComputedStyle(el).display !== 'none',
            world: document.getElementById('emptyAccessListWorld').textContent
        };
    }, citizens);

    console.log('\nMust warn: list ENFORCED and empty');
    let r = await decide({ public: 0, citizens: '' });
    check('modal is shown', r.hasClass, 'class not applied');
    check('and is actually visible, not just class-flagged', r.visible,
        'the .show rule did not make it visible -- a jsdom test would have missed this');
    check('names the world', r.world === 'SomeWorld', `got "${r.world}"`);

    console.log('\nMust warn: whitespace-only list is still empty');
    r = await decide({ public: 0, citizens: '   \n  ' });
    check('modal is shown', r.visible);

    console.log('\nMust NOT warn: list enforced WITH a player');
    r = await decide({ public: 0, citizens: '76561197960287930' });
    check('stays hidden', !r.visible, 'warned about a populated list');

    console.log('\nMust NOT warn: list switched OFF (deliberately open)');
    // Running an open world is supported. Nagging here is how a real warning gets ignored.
    r = await decide({ public: 1, citizens: '' });
    check('stays hidden', !r.visible, 'warned about a deliberately open world');

    // From here on, drive the REAL Settings modal rather than calling the predicate directly.
    // That renders the tab panes switchSettingsTab() needs, and it also tests the WIRING --
    // that opening Settings actually consults the predicate. Calling the predicate by hand
    // would pass even if showSettingsModal() never called it.
    console.log(`\nEnd to end: opening Settings on an enforced-but-empty world ("${SENTINEL_WORLD}")`);
    const opened = await page.evaluate(async (w) => {
        await showSettingsModal(w);
        const el = document.getElementById('emptyAccessListOverlay');
        return {
            visible: window.getComputedStyle(el).display !== 'none',
            world: document.getElementById('emptyAccessListWorld').textContent
        };
    }, SENTINEL_WORLD);
    check('the modal appears on its own', opened.visible,
        'showSettingsModal did not consult the predicate');
    check('and names the world', opened.world === SENTINEL_WORLD, `got "${opened.world}"`);

    console.log('\n"Take me to Access" actually switches tab');
    const after = await page.evaluate(() => {
        dismissEmptyAccessList(true);
        return {
            closed: window.getComputedStyle(document.getElementById('emptyAccessListOverlay')).display === 'none',
            active: document.querySelector('#settingsTabBar .backup-tab.active')?.dataset.tab,
            paneShown: window.getComputedStyle(document.getElementById('accessTab')).display !== 'none'
        };
    });
    check('modal closes', after.closed);
    check('Access tab becomes active', after.active === 'accessTab', `active tab is "${after.active}"`);
    check('and its pane is actually on screen', after.paneShown,
        'the tab button highlighted but the pane stayed hidden');

    console.log('\n"Later" closes without switching tab');
    const later = await page.evaluate(async (w) => {
        await showSettingsModal(w);
        dismissEmptyAccessList();
        return {
            closed: window.getComputedStyle(document.getElementById('emptyAccessListOverlay')).display === 'none',
            active: document.querySelector('#settingsTabBar .backup-tab.active')?.dataset.tab
        };
    }, SENTINEL_WORLD);
    check('modal closes', later.closed);
    check('and the tab is left alone', later.active === 'settingsTab', `active tab is "${later.active}"`);

    await browser.close();
    console.log(`\n${pass} passed, ${fail} failed`);
    process.exit(fail === 0 ? 0 : 1);
})();
