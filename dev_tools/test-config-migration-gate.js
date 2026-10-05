// Oracle test: the migration review appears when an operator clicks Mods, Start or Settings.
//
// Those three controls are rendered in five places between the two PHP cards, the three JS row
// branches and the 5-second poll updater. The gate is ONE delegated capture-phase listener on
// document rather than an onclick on each, because threading a handler through five render
// sites is exactly the enumeration that shipped 2.53's hammertime bug -- and because an
// attached handler would not survive the poll replacing every row.
//
// Neither of those properties can be checked by reading the code: whether a capture listener
// really pre-empts an inline onclick, and whether the gate still fires on a row the poll has
// just rebuilt, are browser facts. So this clicks the real controls, and clicks one of them
// AFTER waiting out a poll tick.
//
// Requires the dev container and a fixture:
//   dev_tools/devUp.sh
//   W=<a stopped modded world>
//   docker exec phvalheim-dev sh -c "mkdir -p /opt/stateful/games/valheim/worlds/$W/custom_configs/.imported-pre-2.55 \
//     && printf '[A]\\n## Default value: 5\\nx = 9\\n' > .../gatefixture.cfg"
//
// Usage:
//   docker run --rm --network host -v "$PWD":/repo -w /repo/dev_tools \
//     mcr.microsoft.com/playwright:v1.47.0-jammy bash -c \
//     'npm i --silent --prefix /tmp/pw playwright@1.47.0 >/dev/null 2>&1;
//      NODE_PATH=/tmp/pw/node_modules node test-config-migration-gate.js http://127.0.0.1:8081 <world>'

const { chromium } = require('playwright');

const BASE = process.argv[2] || 'http://127.0.0.1:8081';
const WORLD = process.argv[3];
let pass = 0, fail = 0;
const ok  = (m) => { console.log('  PASS  ' + m); pass++; };
const bad = (m, d) => { console.log('  FAIL  ' + m); console.log('        ' + d); fail++; };

const gateShown = (page) => page.evaluate(
	() => document.getElementById('cfgMigOverlay').classList.contains('show'));

(async () => {
	if (!WORLD) { console.error('usage: ... <world-with-parked-files>'); process.exit(2); }
	const browser = await chromium.launch();
	const page = await browser.newPage();
	const errors = [];
	page.on('pageerror', e => errors.push(e.message));

	const clearOverlays = async () => {
		await page.evaluate(() => {
			document.querySelectorAll('.mods-modal-overlay.show').forEach((o) => {
				o.classList.remove('show');
			});
		});
	};
	// cfgMigByWorld and cfgMigSeen are top-level `const` bindings, which live in the script's
	// global LEXICAL scope and are NOT properties of window -- only `var` and function
	// declarations land there. Reading them as window.x returned undefined, which made the
	// first run of this test report the fixture as missing while the page had it correctly.
	// Referenced bare instead. (The functions are `function` declarations, so window.fn does
	// work for those, which is why the other browser tests were unaffected.)
	const resetGate = async () => {
		await page.evaluate(() => {
			document.getElementById('cfgMigOverlay').classList.remove('show');
			for (const k of Object.keys(cfgMigSeen)) delete cfgMigSeen[k];
		});
		// WAIT for it to actually be gone before the next click. Without this the previous
		// iteration's open modal was still intercepting pointer events, and Playwright
		// retried the click for 30s and then died -- which reads exactly like a broken
		// button rather than like the harness getting in its own way.
		await page.waitForFunction(
			() => !document.querySelector('.mods-modal-overlay.show'),
			{ timeout: 5000 }
		).catch(() => {});
	};

	await page.setViewportSize({ width: 1557, height: 1000 });
	await page.goto(BASE + '/index.php', { waitUntil: 'networkidle' });
	await page.waitForTimeout(6500);   // let the poll rebuild the rows at least once

	// The fixture has to be visible to the page, or every assertion below passes vacuously.
	const pending = await page.evaluate((w) => cfgMigByWorld[w] || 0, WORLD);
	if (pending > 0) {
		ok(`the page knows ${WORLD} has ${pending} parked file(s)`);
	} else {
		bad('the page knows the fixture world has parked files',
			`cfgMigByWorld[${WORLD}] is ${pending} — the rest of this test would pass for the wrong reason`);
		await browser.close();
		console.log(`\n${pass} passed, ${fail} failed`);
		process.exit(1);
	}

	// ---- the three gated actions ----
	for (const action of ['mods', 'settings', 'start']) {
		await resetGate();
		await clearOverlays();

		const sel = `tr[data-world="${WORLD}"] [data-action="${action}"]`;
		const el = await page.$(sel);
		if (!el) { console.log(`  SKIP  ${action}: not present on this row`); continue; }
		const disabled = await el.evaluate(n => n.classList.contains('disabled'));
		if (disabled) { console.log(`  SKIP  ${action}: disabled on this row`); continue; }

		// reflowActionGroups() parks buttons that did not fit into the "..." menu, which is
		// display:none until opened -- so the control is present but invisible, and Playwright
		// retried the click for 30s and died. That is the product working as designed, and a
		// real operator opens the menu first, so do that. Measured on this very row earlier:
		// a 31-character world name pushes Settings and Delete behind the trigger.
		if (!(await el.isVisible())) {
			// Open the "..." menu this control is actually parked in. A row has TWO action
			// groups -- Actions and Configure -- each with its own trigger, so a selector
			// scoped to the ROW opens the first one and leaves the control still hidden.
			// Settings lives in the Configure group's menu, Start in the Actions one.
			const opened = await el.evaluate((n) => {
				const g = n.closest('.action-group');
				const t = g && g.querySelector('.action-overflow-trigger');
				if (!t) return false;
				t.click();
				return true;
			});
			if (!opened) { console.log(`  SKIP  ${action}: hidden and no overflow trigger in its group`); continue; }
			await page.waitForTimeout(300);
			if (!(await el.isVisible())) {
				console.log(`  SKIP  ${action}: still hidden after opening its overflow menu`);
				continue;
			}
		}

		const urlBefore = page.url();
		await el.click();
		await page.waitForTimeout(900);

		const shown = await gateShown(page);
		const navigated = page.url() !== urlBefore;

		if (shown && !navigated) {
			ok(`clicking ${action} shows the review first, and does not act yet`);
		} else {
			bad(`clicking ${action} shows the review first, and does not act yet`,
				`gateShown=${shown} navigated=${navigated} — the action ran before the review`);
		}

		// The review must be SCOPED to this world, or a gate in front of Start confronts the
		// operator with every other world's leftovers.
		if (shown) {
			const scoped = await page.evaluate((w) => {
				const t = document.getElementById('cfgMigIntro').textContent;
				const heads = Array.from(document.querySelectorAll('#cfgMigBody .cfg-mig-world-head'))
					.map(h => h.textContent);
				return { names: t.includes(w), worlds: heads.length, mentions: heads.join('|') };
			}, WORLD);
			if (scoped.names && scoped.worlds === 1) {
				ok(`the ${action} gate is scoped to ${WORLD} alone`);
			} else {
				bad(`the ${action} gate is scoped to ${WORLD} alone`,
					`namesWorld=${scoped.names} worldsListed=${scoped.worlds} (${scoped.mentions})`);
			}

			// Continue must exist and must not be a dead end.
			const hasContinue = await page.evaluate(() =>
				!!document.querySelector('#cfgMigMsg button'));
			if (hasContinue) {
				ok(`the ${action} gate offers Continue`);
			} else {
				bad(`the ${action} gate offers Continue`,
					'no Continue button — the operator is stuck behind a chore');
			}
		}
	}

	// ---- Continue actually performs the action that was gated ----
	await resetGate();
	await clearOverlays();
	await page.click(`tr[data-world="${WORLD}"] [data-action="mods"]`);
	await page.waitForTimeout(900);
	await page.evaluate(() => document.querySelector('#cfgMigMsg button').click());
	await page.waitForSelector('#modsHubOverlay.show', { timeout: 6000 }).catch(() => {});
	const hubOpen = await page.evaluate(() =>
		document.getElementById('modsHubOverlay').classList.contains('show'));
	if (hubOpen) {
		ok('Continue carries on to the thing that was clicked (the Mods hub opened)');
	} else {
		bad('Continue carries on to the thing that was clicked',
			'the hub did not open — Continue swallowed the action');
	}

	// ---- and does not gate again for that world ----
	await page.evaluate(() => document.getElementById('modsHubOverlay').classList.remove('show'));
	await clearOverlays();
	await page.click(`tr[data-world="${WORLD}"] [data-action="mods"]`);
	await page.waitForTimeout(900);
	if (!(await gateShown(page))) {
		ok('the gate does not fire twice for the same world after Continue');
	} else {
		bad('the gate does not fire twice for the same world after Continue',
			'it re-prompted — this would nag on every click');
	}

	// ---- a world with NOTHING parked must never be gated ----
	await resetGate();
	await clearOverlays();
	const otherWorld = await page.evaluate((w) => {
		const rows = Array.from(document.querySelectorAll('.worlds-table tr[data-world]'));
		for (const tr of rows) {
			const n = tr.getAttribute('data-world');
			if (n !== w && !cfgMigByWorld[n] && tr.querySelector('[data-action="mods"]:not(.disabled)')) {
				return n;
			}
		}
		return null;
	}, WORLD);

	if (!otherWorld) {
		console.log('  SKIP  clean-world case: no other world with an enabled Mods button');
	} else {
		await page.click(`tr[data-world="${otherWorld}"] [data-action="mods"]`);
		await page.waitForTimeout(900);
		const gated = await gateShown(page);
		const hub = await page.evaluate(() =>
			document.getElementById('modsHubOverlay').classList.contains('show'));
		if (!gated && hub) {
			ok(`a world with nothing parked (${otherWorld}) is not gated and opens straight up`);
		} else {
			bad(`a world with nothing parked (${otherWorld}) is not gated`,
				`gated=${gated} hubOpen=${hub}`);
		}
	}

	// ---- the gate survives the 5-second poll rebuilding the row ----
	// This is the property a delegated listener has and an attached handler does not, and it
	// cannot be read off the source.
	await resetGate();
	await page.evaluate(() => {
		document.querySelectorAll('.mods-modal-overlay.show').forEach(o => o.classList.remove('show'));
	});
	await page.waitForTimeout(6500);
	await page.click(`tr[data-world="${WORLD}"] [data-action="mods"]`);
	await page.waitForTimeout(900);
	if (await gateShown(page)) {
		ok('the gate still fires on a row the 5-second poll has rebuilt');
	} else {
		bad('the gate still fires on a row the 5-second poll has rebuilt',
			'the poll replaced the row and the gate stopped working — a delegated listener should survive this');
	}

	if (errors.length === 0) {
		ok('no page errors while driving the gate');
	} else {
		bad('no page errors while driving the gate', errors.slice(0, 4).join(' | '));
	}

	await browser.close();
	console.log(`\n${pass} passed, ${fail} failed`);
	process.exit(fail === 0 ? 0 : 1);
})().catch((e) => { console.error('HARNESS ERROR: ' + e.message); process.exit(2); });
