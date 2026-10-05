// Oracle test: the mod config editor collapses its cards, one open at a time.
//
// "Show all settings" on a real modpack is 23 cards and 2,700+ rows, one mod contributing
// 1,026. Every card open at once is what made that view unusable and what the mod picker was
// added in front of. These are the rules, and each one is a rendered behaviour that reading
// the source cannot confirm:
//
//   - more than one card  -> all collapsed except the first
//   - exactly one card    -> not collapsible at all, and open. The ?mod= view is one card, and
//                            making the operator click to reveal the only thing they asked
//                            for is a step for nothing.
//   - opening one         -> every other closes
//   - clicking an open one -> it closes, so the full list of mods is reachable
//   - the header's buttons -> must NOT toggle. Reset all to defaults is destructive and
//                            confirmed; folding the card up under the operator mid-action is
//                            how that gets mis-clicked.
//   - unsaved edits        -> marked on the HEADER, because a collapsed card hides every
//                            per-field marker it contains
//
// Requires the dev container, and a world whose config tree has at least two parsed files:
//   dev_tools/devUp.sh
//
// Usage:
//   docker run --rm --network host -v "$PWD":/repo -w /repo/dev_tools \
//     mcr.microsoft.com/playwright:v1.47.0-jammy bash -c \
//     'npm i --silent --prefix /tmp/pw playwright@1.47.0 >/dev/null 2>&1;
//      NODE_PATH=/tmp/pw/node_modules node test-config-accordion.js http://127.0.0.1:8081 <world>'

const { chromium } = require('playwright');

const BASE = process.argv[2] || 'http://127.0.0.1:8081';
const WORLD = process.argv[3];
// A world whose config tree holds exactly ONE file. The single-card rule is a rule Brian
// asked for by name, so it is proven against a real one-card page rather than inferred from
// the ?mod= filter -- which SKIPPED when the fixture mods had no catalogue entry, and a
// skipped assertion protects nothing.
const SOLO_WORLD = process.argv[4];
let pass = 0, fail = 0;
const ok  = (m) => { console.log('  PASS  ' + m); pass++; };
const bad = (m, d) => { console.log('  FAIL  ' + m); console.log('        ' + d); fail++; };

const state = (page) => page.evaluate(() => {
	const cards = Array.from(document.querySelectorAll('.cfg-file-card'));
	return cards.map(c => ({
		file: c.dataset.file,
		collapsible: c.classList.contains('collapsible'),
		collapsed: c.classList.contains('collapsed'),
		edited: c.classList.contains('cfg-card-edited'),
		bodyVisible: !!c.querySelector('.cfg-file-body') &&
			getComputedStyle(c.querySelector('.cfg-file-body')).display !== 'none',
		chevron: !!c.querySelector('.cfg-chevron'),
		inputsInDom: c.querySelectorAll('.cfg-input').length,
	}));
});

(async () => {
	if (!WORLD) { console.error('usage: ... <world>'); process.exit(2); }
	const browser = await chromium.launch();
	const page = await browser.newPage();
	const errors = [];
	page.on('pageerror', e => errors.push(e.message));

	await page.setViewportSize({ width: 1557, height: 1000 });
	await page.goto(`${BASE}/world_configs.php?world=${encodeURIComponent(WORLD)}`,
		{ waitUntil: 'domcontentloaded' });

	let cards = await state(page);
	if (cards.length < 2) {
		bad('the test world renders at least two config cards',
			`only ${cards.length} card(s) — the multi-card rules below cannot be exercised, so this suite would pass for the wrong reason`);
		await browser.close();
		console.log(`\n${pass} passed, ${fail} failed`);
		process.exit(1);
	}
	ok(`the test world renders ${cards.length} config cards`);

	// ---- initial state: first open, rest closed ----
	const openOnLoad = cards.filter(c => !c.collapsed);
	if (openOnLoad.length === 1 && !cards[0].collapsed) {
		ok('on load exactly one card is open, and it is the first');
	} else {
		bad('on load exactly one card is open, and it is the first',
			`${openOnLoad.length} open; first collapsed=${cards[0].collapsed}`);
	}

	if (cards.every(c => c.collapsible && c.chevron)) {
		ok('every card is collapsible and shows a chevron');
	} else {
		bad('every card is collapsible and shows a chevron',
			cards.filter(c => !c.collapsible || !c.chevron).map(c => c.file).join(', '));
	}

	// The collapsed bodies must really be hidden, not merely class-tagged.
	const wrongBody = cards.filter(c => c.collapsed === c.bodyVisible);
	if (wrongBody.length === 0) {
		ok('collapsed cards hide their body and open ones show it');
	} else {
		bad('collapsed cards hide their body and open ones show it',
			wrongBody.map(c => `${c.file} collapsed=${c.collapsed} visible=${c.bodyVisible}`).join('; '));
	}

	// And the inputs must still be IN the dom while collapsed, or an edit made before
	// collapsing would be silently dropped from the save.
	if (cards.filter(c => c.collapsed).every(c => c.inputsInDom > 0)) {
		ok('a collapsed card keeps its inputs in the DOM (edits survive collapsing)');
	} else {
		bad('a collapsed card keeps its inputs in the DOM',
			'a collapsed body has no .cfg-input — edits made before collapsing would not save');
	}

	// ---- opening the second closes the first ----
	await page.click('.cfg-file-card:nth-of-type(2) .cfg-file-head, .cfg-file-card:nth-child(2) .cfg-file-head')
		.catch(async () => {
			await page.evaluate(() => document.querySelectorAll('.cfg-file-card')[1]
				.querySelector('.cfg-file-head').click());
		});
	await page.waitForTimeout(400);
	cards = await state(page);
	const openNow = cards.filter(c => !c.collapsed);
	if (openNow.length === 1 && cards[0].collapsed && !cards[1].collapsed) {
		ok('opening the second card closes the first (one open at a time)');
	} else {
		bad('opening the second card closes the first',
			`open=${openNow.map(c => c.file).join(',')} first collapsed=${cards[0].collapsed}`);
	}

	// ---- clicking an OPEN card closes it: everything collapsed is a reachable state ----
	await page.evaluate(() => document.querySelectorAll('.cfg-file-card')[1]
		.querySelector('.cfg-file-head').click());
	await page.waitForTimeout(400);
	cards = await state(page);
	if (cards.every(c => c.collapsed)) {
		ok('clicking an open card closes it, so the whole mod list is viewable at once');
	} else {
		bad('clicking an open card closes it',
			`${cards.filter(c => !c.collapsed).length} still open — there is no way to see the full list`);
	}

	// ---- a header BUTTON must not toggle the card ----
	// Open one, then click its Reset button and dismiss the confirm. The card must stay open.
	await page.evaluate(() => document.querySelectorAll('.cfg-file-card')[0]
		.querySelector('.cfg-file-head').click());
	await page.waitForTimeout(300);
	page.once('dialog', d => d.dismiss());
	const btnClicked = await page.evaluate(() => {
		const b = document.querySelectorAll('.cfg-file-card')[0]
			.querySelector('.cfg-file-head button');
		if (!b) return false;
		b.click();
		return true;
	});
	await page.waitForTimeout(500);
	cards = await state(page);
	if (!btnClicked) {
		console.log('  SKIP  header-button case: no button in the header');
	} else if (!cards[0].collapsed) {
		ok('clicking a button in the header does not fold the card up');
	} else {
		bad('clicking a button in the header does not fold the card up',
			'the card collapsed — an operator confirming Reset would watch their card vanish');
	}

	// ---- an unsaved edit marks the HEADER, and survives collapsing ----
	await page.evaluate(() => {
		const card = document.querySelectorAll('.cfg-file-card')[0];
		const el = card.querySelector('.cfg-input');
		if (!el) return;
		el.value = String(el.value) + 'zz';
		el.dispatchEvent(new Event('input', { bubbles: true }));
	});
	await page.waitForTimeout(300);
	cards = await state(page);
	if (cards[0].edited) {
		ok('an unsaved edit marks its card header');
	} else {
		bad('an unsaved edit marks its card header',
			'no cfg-card-edited — collapsing would hide the unsaved work entirely');
	}

	// Collapse it and the marker must persist: that is the whole point.
	await page.evaluate(() => document.querySelectorAll('.cfg-file-card')[1]
		.querySelector('.cfg-file-head').click());
	await page.waitForTimeout(400);
	cards = await state(page);
	if (cards[0].collapsed && cards[0].edited) {
		ok('the unsaved marker stays on a collapsed card');
	} else {
		bad('the unsaved marker stays on a collapsed card',
			`collapsed=${cards[0].collapsed} edited=${cards[0].edited}`);
	}

	// ---- SINGLE card: not collapsible, and open ----
	if (!SOLO_WORLD) {
		bad('a single-card world was supplied to test the one-card rule',
			'pass a world with exactly one config file as the 4th argument — the rule would otherwise go unproven');
	} else {
		await page.goto(`${BASE}/world_configs.php?world=${encodeURIComponent(SOLO_WORLD)}`,
			{ waitUntil: 'domcontentloaded' });
		const one = await state(page);
		if (one.length !== 1) {
			bad(`${SOLO_WORLD} renders exactly one card`,
				`rendered ${one.length} — this is the fixture for the single-card rule`);
		} else {
			if (!one[0].collapsible && !one[0].collapsed && one[0].bodyVisible) {
				ok('a single-card page is not collapsible and is already open');
			} else {
				bad('a single-card page is not collapsible and is already open',
					`collapsible=${one[0].collapsible} collapsed=${one[0].collapsed} visible=${one[0].bodyVisible}`);
			}
			if (!one[0].chevron) {
				ok('a single card shows no chevron (nothing to toggle)');
			} else {
				bad('a single card shows no chevron', 'a chevron implies a toggle that does nothing');
			}
			// Clicking its header must do nothing at all -- no handler, so no way to hide the
			// only content on the page.
			await page.evaluate(() => document.querySelector('.cfg-file-head').click());
			await page.waitForTimeout(300);
			const after = await state(page);
			if (!after[0].collapsed && after[0].bodyVisible) {
				ok('clicking a single card\'s header cannot hide it');
			} else {
				bad('clicking a single card\'s header cannot hide it',
					'the only content on the page collapsed away');
			}
		}
	}

	if (errors.length === 0) {
		ok('no page errors while driving the accordion');
	} else {
		bad('no page errors while driving the accordion', errors.slice(0, 4).join(' | '));
	}

	await browser.close();
	console.log(`\n${pass} passed, ${fail} failed`);
	process.exit(fail === 0 ? 0 : 1);
})().catch((e) => { console.error('HARNESS ERROR: ' + e.message); process.exit(2); });
