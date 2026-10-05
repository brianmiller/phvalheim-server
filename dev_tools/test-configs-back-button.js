// Oracle test: the browser's Back button returns to the Mod Configs picker.
//
// Editing a 29-mod world means going into world_configs.php and back out once per mod. Before
// this, Back from that page landed on a bare dashboard -- so every mod cost a fresh Mods ->
// Mod Configs -> find-your-place. The picker is now a real history entry.
//
// Asserted here rather than reasoned about because this is browser behaviour, not code
// behaviour: a page restored by Back is a fresh LOAD (or a bfcache restore), which is exactly
// why the state lives in the URL hash and not only in history.state. A unit test on the
// functions could not tell the difference; a real browser can.
//
// Requires the dev container with the admin UI on :8081 and one modded world:
//   dev_tools/devUp.sh
//
// Usage:
//   docker run --rm --network host -v "$PWD":/repo -w /repo/dev_tools \
//     mcr.microsoft.com/playwright:v1.47.0-jammy bash -c \
//     'npm i --silent --prefix /tmp/pw playwright@1.47.0 >/dev/null 2>&1;
//      NODE_PATH=/tmp/pw/node_modules node test-configs-back-button.js http://127.0.0.1:8081'

const { chromium } = require('playwright');

const BASE = process.argv[2] || 'http://127.0.0.1:8081';
let pass = 0, fail = 0;
const ok  = (m) => { console.log('  PASS  ' + m); pass++; };
const bad = (m, d) => { console.log('  FAIL  ' + m); console.log('        ' + d); fail++; };

const shown = (page, id) => page.evaluate(
	(i) => document.getElementById(i).classList.contains('show'), id);

(async () => {
	const browser = await chromium.launch();
	const page = await browser.newPage();
	const errors = [];
	page.on('pageerror', e => errors.push(e.message));

	const clearOverlays = async () => {
		await page.evaluate(() => {
			document.querySelectorAll('.mods-modal-overlay.show').forEach((o) => {
				if (o.id !== 'modsHubOverlay' && o.id !== 'cfgModalOverlay') o.classList.remove('show');
			});
		});
	};

	await page.setViewportSize({ width: 1557, height: 1000 });
	await page.goto(BASE + '/index.php', { waitUntil: 'networkidle' });
	await page.waitForTimeout(6500);

	const world = await page.evaluate(() => {
		const a = document.querySelector('.worlds-table a[data-action="mods"]');
		return a ? a.closest('tr').getAttribute('data-world') : null;
	});
	if (!world) {
		bad('a modded world is available to test', 'no enabled Mods anchor on the dashboard');
		await browser.close();
		console.log(`\n${pass} passed, ${fail} failed`);
		process.exit(1);
	}

	// ---- open the picker: it must become a history entry ----
	await clearOverlays();
	await page.click(`tr[data-world="${world}"] a[data-action="mods"]`);
	await page.waitForSelector('#modsHubOverlay.show', { timeout: 5000 });
	await page.waitForFunction(
		() => !/Checking this world/.test(document.getElementById('modsHubCards').innerHTML),
		{ timeout: 8000 }).catch(() => {});
	await clearOverlays();
	await page.click('#modsHubCards .mods-hub-card:nth-child(2)');
	await page.waitForSelector('#cfgModalOverlay.show', { timeout: 5000 });

	const hash = await page.evaluate(() => location.hash);
	if (hash === '#mods-configs=' + encodeURIComponent(world)) {
		ok(`opening the picker records a history entry (${hash})`);
	} else {
		bad('opening the picker records a history entry',
			`hash is "${hash}", expected "#mods-configs=${encodeURIComponent(world)}"`);
	}

	// ---- THE ASK: navigate to world_configs.php, press Back, land back on the picker ----
	await page.goto(`${BASE}/world_configs.php?world=${encodeURIComponent(world)}`,
		{ waitUntil: 'domcontentloaded' });
	const onEditor = page.url().includes('world_configs.php');

	await page.goBack({ waitUntil: 'domcontentloaded' });
	// The picker re-opens from the hash on load, then fetches. Give it the load, not the fetch.
	await page.waitForFunction(
		() => document.getElementById('cfgModalOverlay')
		   && document.getElementById('cfgModalOverlay').classList.contains('show'),
		{ timeout: 8000 }).catch(() => {});

	const backOk = await shown(page, 'cfgModalOverlay');
	const backTitle = await page.evaluate(() => document.getElementById('cfgModalTitle').textContent);

	if (onEditor && backOk && backTitle.includes(world)) {
		ok(`Back from world_configs.php re-opens the picker for the right world ("${backTitle}")`);
	} else {
		bad('Back from world_configs.php re-opens the picker for the right world',
			`reachedEditor=${onEditor} pickerShown=${backOk} title="${backTitle}"`);
	}

	// The dashboard behind it must be intact, not just the modal floating over a blank page.
	const rows = await page.evaluate(() =>
		document.querySelectorAll('.worlds-table tbody tr[data-world]').length);
	if (rows > 0) {
		ok(`the dashboard behind the picker is rendered (${rows} rows)`);
	} else {
		bad('the dashboard behind the picker is rendered', 'no world rows after going back');
	}

	// ---- Back again must LEAVE the picker, not trap the operator in it ----
	await page.goBack({ waitUntil: 'domcontentloaded' }).catch(() => {});
	await page.waitForTimeout(800);
	const stillOpen = await shown(page, 'cfgModalOverlay');
	if (!stillOpen) {
		ok('a second Back leaves the picker rather than trapping the operator in it');
	} else {
		bad('a second Back leaves the picker', 'the picker is still showing after going back twice');
	}

	// ---- Forward returns to it, so the two buttons stay symmetric ----
	await page.goForward({ waitUntil: 'domcontentloaded' }).catch(() => {});
	await page.waitForFunction(
		() => document.getElementById('cfgModalOverlay')
		   && document.getElementById('cfgModalOverlay').classList.contains('show'),
		{ timeout: 5000 }).catch(() => {});
	if (await shown(page, 'cfgModalOverlay')) {
		ok('Forward re-opens the picker');
	} else {
		bad('Forward re-opens the picker', 'the picker did not come back on forward navigation');
	}

	// ---- closing by hand must not leave the hash behind ----
	await page.evaluate(() => window.closeConfigsModal());
	await page.waitForTimeout(600);
	const afterClose = await page.evaluate(() => ({
		open: document.getElementById('cfgModalOverlay').classList.contains('show'),
		hash: location.hash,
	}));
	if (!afterClose.open && !afterClose.hash.startsWith('#mods-configs=')) {
		ok('closing the picker by hand clears its history entry');
	} else {
		bad('closing the picker by hand clears its history entry',
			`open=${afterClose.open} hash="${afterClose.hash}" — a reload would re-open a dismissed modal`);
	}

	// ---- a deep link must work cold, which is what a bookmark or a reload is ----
	await page.goto(`${BASE}/index.php#mods-configs=${encodeURIComponent(world)}`,
		{ waitUntil: 'domcontentloaded' });
	await page.waitForFunction(
		() => document.getElementById('cfgModalOverlay')
		   && document.getElementById('cfgModalOverlay').classList.contains('show'),
		{ timeout: 8000 }).catch(() => {});
	if (await shown(page, 'cfgModalOverlay')) {
		ok('the picker opens from a cold URL (reload or bookmark)');
	} else {
		bad('the picker opens from a cold URL', 'the hash did not restore the modal on a fresh load');
	}

	if (errors.length === 0) {
		ok('no page errors while navigating');
	} else {
		bad('no page errors while navigating', errors.slice(0, 4).join(' | '));
	}

	await browser.close();
	console.log(`\n${pass} passed, ${fail} failed`);
	process.exit(fail === 0 ? 0 : 1);
})().catch((e) => { console.error('HARNESS ERROR: ' + e.message); process.exit(2); });
