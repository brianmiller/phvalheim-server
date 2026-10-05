// Oracle test: the "Unmatched config files" row narrows to those files, and "Back to Mods"
// really goes BACK.
//
// Two bugs this covers, both of which look fine in the source:
//
//   1. The picker built its link as `&mod=<id>` and, for the unmatched bucket, `mod_id` is
//      null -- so it appended NOTHING and the row opened every config in the world. The row
//      says "unmatched" and showed the opposite. Unmatched cannot be expressed as ?mod=, so it
//      needs its own parameter.
//   2. "Back to Mods" pointed at edit_world.php (the mod PICKER), then at history.back().
//      Back is whatever the previous entry happens to be: arriving from the migration review
//      modal, or from "show all configs" on this page, leaves a previous entry with no picker
//      hash, so it landed on a bare dashboard. It is now a plain link to
//      index.php#mods-configs=<world>, which opens Mods > Mod Configs from every route in.
//
// Whether a link lands on the right SET of cards, and whether the dashboard really reopens the
// list from a hash, are browser facts -- so this clicks the real controls on the real pages.
//
// Fixture (dev container): one world with BOTH a matched mod config and >=2 unmatched ones.
//   docker exec phvalheim-dev sh -c '
//     mysql -uroot phvalheim -e "INSERT IGNORE INTO world_mods (world_id,mod_id) VALUES (1,1);"
//     C=/opt/stateful/games/valheim/worlds/midgard/game/BepInEx/config; mkdir -p "$C"
//     printf "[General]\n# Default value: 30\nMaxSailSpeed = 45\n" > "$C/SkyheimExtended.cfg"
//     printf "[General]\n# Default value: 5\nMystery = 9\n"        > "$C/nobody.knows.this.cfg"
//     printf "[General]\n# Default value: 1\nOther = 4\n"          > "$C/alsounmatched.cfg"
//     chown -R phvalheim:phvalheim "$C"'
//
// Usage:
//   docker run --rm --network host -v "$PWD":/repo -w /repo/dev_tools \
//     mcr.microsoft.com/playwright:v1.47.0-jammy bash -c \
//     'npm i --silent --prefix /tmp/pw playwright@1.47.0 >/dev/null 2>&1;
//      NODE_PATH=/tmp/pw/node_modules node test-configs-unmatched-back.js \
//        http://127.0.0.1:8081 midgard SkyheimExtended.cfg'

const { chromium } = require('playwright');

const BASE    = process.argv[2] || 'http://127.0.0.1:8081';
const WORLD   = process.argv[3];
const MATCHED = process.argv[4];   // a .cfg that DOES belong to an installed mod
let pass = 0, fail = 0;
const ok  = (m) => { console.log('  PASS  ' + m); pass++; };
const bad = (m, d) => { console.log('  FAIL  ' + m); console.log('        ' + d); fail++; };

const cards = (page) => page.evaluate(() =>
	Array.from(document.querySelectorAll('.cfg-file-card')).map(c => c.dataset.file));

(async () => {
	if (!WORLD || !MATCHED) { console.error('usage: ... <world> <matched.cfg>'); process.exit(2); }
	const browser = await chromium.launch();
	const page = await browser.newPage();
	const errors = [];
	page.on('pageerror', e => errors.push(e.message));

	await page.setViewportSize({ width: 1557, height: 1000 });
	await page.goto(BASE + '/index.php', { waitUntil: 'networkidle' });

	// ---- the unfiltered page is the control: it must contain MORE than the unmatched ones ----
	await page.goto(`${BASE}/world_configs.php?world=${encodeURIComponent(WORLD)}`,
		{ waitUntil: 'domcontentloaded' });
	const all = await cards(page);
	if (all.length >= 3 && all.includes(MATCHED)) {
		ok(`the unfiltered page shows all ${all.length} files, including the matched one`);
	} else {
		bad('the unfiltered page shows all files including the matched one',
			`cards=${all.join(', ')} — without a control, "the filter works" cannot be told apart `
			+ 'from "the world only has unmatched files"');
		await browser.close();
		console.log(`\n${pass} passed, ${fail} failed`);
		process.exit(1);
	}

	// ---- open the picker and click the Unmatched row ----
	await page.goto(BASE + '/index.php', { waitUntil: 'networkidle' });
	await page.evaluate((w) => showConfigsModal(w), WORLD);
	await page.waitForFunction(
		() => document.querySelectorAll('#cfgModalList a').length > 0, { timeout: 8000 })
		.catch(() => {});

	const unmatchedLink = await page.evaluate(() => {
		const a = Array.from(document.querySelectorAll('#cfgModalList a'))
			.find(x => /unmatched/i.test(x.textContent));
		return a ? a.getAttribute('href') : null;
	});
	if (unmatchedLink && /[?&]unmatched=1/.test(unmatchedLink)) {
		ok(`the Unmatched row carries a filter ("${unmatchedLink}")`);
	} else {
		bad('the Unmatched row carries a filter',
			`href was "${unmatchedLink}" — with no filter this row opens every mod in the world`);
	}

	await Promise.all([
		page.waitForNavigation({ waitUntil: 'domcontentloaded' }),
		page.evaluate(() => Array.from(document.querySelectorAll('#cfgModalList a'))
			.find(x => /unmatched/i.test(x.textContent)).click()),
	]);

	const shown = await cards(page);
	if (shown.length > 0 && !shown.includes(MATCHED) && shown.length < all.length) {
		ok(`it opens ONLY the unmatched files (${shown.join(', ')})`);
	} else {
		bad('it opens ONLY the unmatched files',
			`cards=${shown.join(', ')} — the matched mod ${MATCHED} must not be here`);
	}
	// And the page must say it is filtered, with a way out. A silent filter reads as "these are
	// all the configs this world has".
	const escape = await page.evaluate(() => {
		const a = Array.from(document.querySelectorAll('a'))
			.find(x => /show all configs for this world/i.test(x.textContent));
		return a ? a.textContent.trim().replace(/\s+/g, ' ') : null;
	});
	if (escape && /unmatched/i.test(escape)) {
		ok(`the filtered page names the filter and offers a way out ("${escape}")`);
	} else {
		bad('the filtered page names the filter and offers a way out',
			`banner was "${escape}" — a silent filter looks like the whole world`);
	}

	// ---- Back to Mods opens the Mod Configs LIST ----
	// Deliberately NOT measured on the route we just took. The previous entry here IS the
	// picker, so history.back() and a link to the picker hash are indistinguishable -- the
	// assertion would pass either way. The route that tells them apart is one whose previous
	// entry has no picker hash, which is every other way into this page (the migration review
	// modal, "show all configs" on this page, a bookmark). That is the control below; this
	// first click only establishes the happy path still works.
	const urlBefore = page.url();
	const clickBack = () => Promise.all([
		page.waitForNavigation({ waitUntil: 'domcontentloaded' }).catch(() => {}),
		page.evaluate(() => Array.from(document.querySelectorAll('a'))
			.find(x => /back to mods/i.test(x.textContent)).click()),
	]);
	const pickerState = () => page.evaluate(() => {
		const o = document.getElementById('cfgModalOverlay');
		return { url: location.href,
		         shown: !!o && o.classList.contains('show'),
		         rows: document.querySelectorAll('#cfgModalList a').length };
	});

	await clickBack();
	await page.waitForTimeout(1200);
	let st = await pickerState();

	if (/index\.php/.test(st.url) && st.url !== urlBefore && !/edit_world\.php/.test(st.url)) {
		ok(`Back to Mods returns to the dashboard (${st.url.replace(BASE, '')})`);
	} else {
		bad('Back to Mods returns to the dashboard',
			`landed on ${st.url} — edit_world.php is the mod PICKER, not the config list`);
	}
	if (st.shown && st.rows > 0) {
		ok(`the Mod Configs list is open (${st.rows} rows)`);
	} else {
		bad('the Mod Configs list is open',
			'it landed on a bare dashboard — the operator has to re-open Mods > Mod Configs by hand');
	}

	// ---- the control: a route whose previous entry is NOT the picker ----
	// It must be a REAL in-app click, not page.goto: goto sends no Referer, so the old
	// referrer-guarded history.back() fell straight through to its href and this case passed on
	// the broken code. "Show all configs for this world" is a genuine same-site click, so the
	// referrer IS ours and the previous entry is the FILTERED config page -- exactly the shape
	// the migration review modal produces. history.back() returns to that page and never leaves
	// world_configs.php at all; the link goes to the Mod Configs list from anywhere.
	await page.goto(BASE + '/index.php', { waitUntil: 'networkidle' });
	await page.evaluate((w) => showConfigsModal(w), WORLD);
	await page.waitForFunction(
		() => document.querySelectorAll('#cfgModalList a').length > 0, { timeout: 8000 })
		.catch(() => {});
	await Promise.all([
		page.waitForNavigation({ waitUntil: 'domcontentloaded' }),
		page.evaluate(() => Array.from(document.querySelectorAll('#cfgModalList a'))
			.find(x => /unmatched/i.test(x.textContent)).click()),
	]);
	await Promise.all([
		page.waitForNavigation({ waitUntil: 'domcontentloaded' }),
		page.evaluate(() => Array.from(document.querySelectorAll('a'))
			.find(x => /show all configs for this world/i.test(x.textContent)).click()),
	]);
	await clickBack();
	await page.waitForTimeout(1200);
	st = await pickerState();

	if (/index\.php/.test(st.url) && st.shown && st.rows > 0) {
		ok(`it reaches the list from a route whose Back is the previous config page (${st.rows} rows)`);
	} else {
		bad('it reaches the list from a route whose Back is the previous config page',
			`url=${st.url} modal=${st.shown} rows=${st.rows} — Back here returns to the config `
			+ 'page the operator just left, so the button never reaches Mods > Mod Configs');
	}
	if (st.url.includes('#mods-configs=')) {
		ok('the landing URL carries the picker hash, so a reload still shows the list');
	} else {
		bad('the landing URL carries the picker hash', `url=${st.url}`);
	}

	if (errors.length === 0) {
		ok('no page errors');
	} else {
		bad('no page errors', errors.slice(0, 4).join(' | '));
	}

	await browser.close();
	console.log(`\n${pass} passed, ${fail} failed`);
	process.exit(fail === 0 ? 0 : 1);
})().catch((e) => { console.error('HARNESS ERROR: ' + e.message); process.exit(2); });
