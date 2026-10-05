// Oracle test: the "Unmatched config files" row narrows to those files, and "Back to Mods"
// really goes BACK.
//
// Two bugs this covers, both of which look fine in the source:
//
//   1. The picker built its link as `&mod=<id>` and, for the unmatched bucket, `mod_id` is
//      null -- so it appended NOTHING and the row opened every config in the world. The row
//      says "unmatched" and showed the opposite. Unmatched cannot be expressed as ?mod=, so it
//      needs its own parameter.
//   2. "Back to Mods" was a plain link to edit_world.php, which is a different page from the
//      one the operator came from. It is now the browser's Back when the referrer is ours, so
//      the Configs modal comes back with its filter and scroll intact, and falls back to
//      index.php#mods-configs=<world> on a direct hit.
//
// Whether a link lands on the right SET of cards, and whether an onclick really suppresses its
// own href, are browser facts -- so this clicks the real controls on the real pages.
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

	// ---- Back to Mods really goes BACK ----
	const urlBefore = page.url();
	await Promise.all([
		page.waitForNavigation({ waitUntil: 'domcontentloaded' }).catch(() => {}),
		page.evaluate(() => Array.from(document.querySelectorAll('a'))
			.find(x => /back to mods/i.test(x.textContent)).click()),
	]);
	await page.waitForTimeout(1200);

	const landed = page.url();
	if (/index\.php/.test(landed) && landed !== urlBefore) {
		ok(`Back to Mods returns to the dashboard (${landed.replace(BASE, '')})`);
	} else {
		bad('Back to Mods returns to the dashboard', `landed on ${landed}`);
	}
	// It must NOT be edit_world.php: that is the mod PICKER, a different page from the one the
	// operator came from, which is what "back" was doing before.
	if (!/edit_world\.php/.test(landed)) {
		ok('Back to Mods does not divert to edit_world.php');
	} else {
		bad('Back to Mods does not divert to edit_world.php',
			'it still navigates to the mod picker instead of going back');
	}
	// Landing on index.php does NOT prove Back was used: the href fallback points at the same
	// URL on purpose, so the two are indistinguishable by location alone. The discriminator is
	// the FORWARD entry -- history.back() leaves one, an href navigation does not. Without this
	// the suite would pass with the onclick removed entirely.
	const wentForward = await page.goForward({ waitUntil: 'domcontentloaded' })
		.then(() => page.url()).catch(() => null);
	if (wentForward && /world_configs\.php/.test(wentForward)) {
		ok('it really used history.back() — Forward returns to the config page');
	} else {
		bad('it really used history.back()',
			`Forward went to ${wentForward} — a plain href navigation leaves no forward entry, `
			+ 'so the modal\'s filter and scroll position are lost even though the URL looks right');
	}
	// Back again, to leave the page where the remaining assertions expect it.
	await page.goBack({ waitUntil: 'domcontentloaded' }).catch(() => {});
	await page.waitForTimeout(1200);

	// The real payoff: the Configs modal is open again.
	const modalBack = await page.evaluate(() => {
		const o = document.getElementById('cfgModalOverlay');
		return { shown: !!o && o.classList.contains('show'),
		         rows: document.querySelectorAll('#cfgModalList a').length };
	});
	if (modalBack.shown) {
		ok(`the Configs modal is open again after Back (${modalBack.rows} rows)`);
	} else {
		bad('the Configs modal is open again after Back',
			'it landed on a bare dashboard — the whole point of Back is not having to re-open it');
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
