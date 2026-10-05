// Oracle test: an operator can actually OPEN the Mods hub and get where it promises.
//
// 2.55 shipped a working mod-config editor with no entry point, and then an entry point that
// threw a ReferenceError the moment it rendered a row. Both passed every grep-based check
// written for them, because a string in a file says nothing about whether the page runs. So
// this clicks the button like a person and asserts what appears.
//
// Requires the dev container with the admin UI on :8081 and at least one modded world in each
// of 'stopped' and a non-stopped mode:
//   dev_tools/devUp.sh
//   docker exec phvalheim-dev /opt/stateless/engine/tools/sql \
//     "UPDATE worlds SET mode='repackaging' WHERE name='<some modded world>'"
//
// Usage:
//   docker run --rm --network host -v "$PWD":/repo -w /repo/dev_tools \
//     mcr.microsoft.com/playwright:v1.47.0-jammy bash -c \
//     'npm i --silent --prefix /tmp/pw playwright@1.47.0 >/dev/null 2>&1;
//      NODE_PATH=/tmp/pw/node_modules node test-mods-hub.js http://127.0.0.1:8081'

const { chromium } = require('playwright');

const BASE = process.argv[2] || 'http://127.0.0.1:8081';
let pass = 0, fail = 0;
const ok  = (m) => { console.log('  PASS  ' + m); pass++; };
const bad = (m, d) => { console.log('  FAIL  ' + m); console.log('        ' + d); fail++; };

(async () => {
	const browser = await chromium.launch();
	const page = await browser.newPage();
	const errors = [];
	// A ReferenceError inside a modal's own try/catch is reported to the operator as a
	// friendly "could not load" and leaves no trace anywhere else. Collect them.
	page.on('pageerror', e => errors.push(e.message));
	page.on('console', m => { if (m.type() === 'error') errors.push('console: ' + m.text()); });

	// A fresh dev database greets you with the What's New and Hugin notices, which are
	// full-screen overlays and swallow every click aimed at the table behind them. That is the
	// harness getting in the way, not the product failing -- but a click that silently retries
	// for 30s and then times out looks exactly like a broken button, so clear them explicitly
	// before each interaction rather than once at the start (the 5s poll can re-raise them).
	const clearOverlays = async () => {
		await page.evaluate(() => {
			document.querySelectorAll('.mods-modal-overlay.show').forEach((o) => {
				if (o.id !== 'modsHubOverlay' && o.id !== 'cfgModalOverlay') o.classList.remove('show');
			});
			document.querySelectorAll('.modal.show, .modal-backdrop').forEach(o => o.remove());
		});
	};

	await page.setViewportSize({ width: 1557, height: 1000 });
	await page.goto(BASE + '/index.php', { waitUntil: 'networkidle' });
	// Let the 5-second poll replace the server-rendered rows at least once, so this exercises
	// the JS row template and not only the PHP one. They have disagreed before.
	await page.waitForTimeout(6500);

	// ---- the row offers exactly one mods door ----
	const counts = await page.evaluate(() => ({
		mods: document.querySelectorAll('.worlds-table [data-action="mods"]').length,
		editMods: document.querySelectorAll('.worlds-table [data-action="edit-mods"]').length,
		modConfigs: document.querySelectorAll('.worlds-table [data-action="mod-configs"]').length,
		viewMods: document.querySelectorAll('.worlds-table [data-action="view-mods"]').length,
		rows: document.querySelectorAll('.worlds-table tbody tr[data-world]').length,
	}));

	if (counts.rows > 0 && counts.mods === counts.rows) {
		ok(`every world row has a Mods button (${counts.mods}/${counts.rows})`);
	} else {
		bad('every world row has a Mods button', JSON.stringify(counts));
	}

	const leftovers = counts.editMods + counts.modConfigs + counts.viewMods;
	if (leftovers === 0) {
		ok('the three old buttons are gone from every row');
	} else {
		bad('the three old buttons are gone from every row',
			`edit-mods=${counts.editMods} mod-configs=${counts.modConfigs} view-mods=${counts.viewMods} — a row rendered by the OTHER template still has them`);
	}

	// ---- opening the hub on a STOPPED world: both cards usable ----
	const stoppedWorld = await page.evaluate(() => {
		const a = document.querySelector('.worlds-table tr[data-section="offline"] a[data-action="mods"]');
		return a ? a.closest('tr').getAttribute('data-world') : null;
	});

	if (!stoppedWorld) {
		bad('a stopped modded world is available to test', 'no enabled Mods anchor in the offline table');
	} else {
		await clearOverlays();
		await page.click(`tr[data-world="${stoppedWorld}"] a[data-action="mods"]`);
		await page.waitForSelector('#modsHubOverlay.show', { timeout: 5000 });
		// The cards re-render once the live mode arrives from getWorlds.
		await page.waitForFunction(
			() => !/Checking this world/.test(document.getElementById('modsHubCards').innerHTML),
			{ timeout: 8000 }
		).catch(() => {});

		const hub = await page.evaluate(() => {
			const cards = Array.from(document.querySelectorAll('#modsHubCards .mods-hub-card'));
			return {
				title: document.getElementById('modsHubTitle').textContent,
				cards: cards.map(c => ({
					title: c.querySelector('.mods-hub-card-title').textContent.trim(),
					enabled: c.tagName === 'A' && !c.classList.contains('disabled'),
					body: c.querySelector('.mods-hub-card-body').textContent.trim().length,
					why: (c.querySelector('.mods-hub-why') || {}).textContent || '',
				})),
				installedSummary: document.getElementById('modsHubInstalledSummary').textContent,
			};
		});

		if (hub.cards.length === 2) {
			ok('the hub shows exactly two cards');
		} else {
			bad('the hub shows exactly two cards', `got ${hub.cards.length}: ${JSON.stringify(hub.cards)}`);
		}

		const named = hub.cards.map(c => c.title).join(' | ');
		if (/Mod Catalog/.test(named) && /Mod Configs/.test(named)) {
			ok(`both cards are named: ${named}`);
		} else {
			bad('both cards are named Mod Catalog and Mod Configs', named);
		}

		// Brian asked for "simple information explaining what each card will do" -- an empty
		// card body is the thing this is here to catch.
		const empty = hub.cards.filter(c => c.body < 40);
		if (empty.length === 0) {
			ok('both cards explain themselves (>=40 chars of body text)');
		} else {
			bad('both cards explain themselves', `${empty.map(c => c.title).join(', ')} has too little text`);
		}

		const bothOn = hub.cards.every(c => c.enabled);
		if (bothOn) {
			ok(`both cards are usable on a stopped world (${stoppedWorld})`);
		} else {
			bad(`both cards are usable on a stopped world (${stoppedWorld})`,
				hub.cards.map(c => `${c.title}=${c.enabled ? 'on' : 'off: ' + c.why}`).join('; '));
		}

		if (/\(\d+\)/.test(hub.installedSummary)) {
			ok(`the installed list is present and counted: "${hub.installedSummary.trim()}"`);
		} else {
			bad('the installed list is present and counted', `summary read "${hub.installedSummary}"`);
		}

		// ---- Mod Configs must actually open the picker, populated ----
		await clearOverlays();
		await page.click('#modsHubCards .mods-hub-card:nth-child(2)');
		await page.waitForSelector('#cfgModalOverlay.show', { timeout: 5000 });
		await page.waitForFunction(
			() => !/Loading/.test(document.getElementById('cfgModalList').textContent),
			{ timeout: 10000 }
		).catch(() => {});

		const picker = await page.evaluate(() => ({
			text: document.getElementById('cfgModalList').textContent.trim().slice(0, 160),
			rows: document.querySelectorAll('#cfgModalList li[data-cfgname]').length,
			modLinks: document.querySelectorAll('#cfgModalList a[href*="&mod="]').length,
			hubClosed: !document.getElementById('modsHubOverlay').classList.contains('show'),
		}));

		if (picker.hubClosed) {
			ok('the hub closes when the Configs picker opens');
		} else {
			bad('the hub closes when the Configs picker opens', 'both overlays are showing at once');
		}

		// A world that has never started has no cfg files yet, which is a legitimate empty
		// state and must NOT read as an error. Either populated rows, or that exact message.
		if (picker.rows > 0 && picker.modLinks === picker.rows) {
			ok(`the picker lists ${picker.rows} mods, each filtered by &mod=`);
		} else if (/No mod configs yet/.test(picker.text)) {
			ok('the picker shows the "no configs yet" empty state (world has never started)');
		} else {
			bad('the picker lists mods or shows its empty state',
				`rows=${picker.rows} modLinks=${picker.modLinks} text="${picker.text}"`);
		}
	}

	// ---- a non-stopped world must NOT be offered the catalogue ----
	const busyWorld = await page.evaluate(() => {
		const rows = Array.from(document.querySelectorAll('.worlds-table tr[data-world]'));
		for (const tr of rows) {
			const badge = tr.querySelector('.status-badge');
			const a = tr.querySelector('a[data-action="mods"]');
			if (a && badge && /Repackaging|Running/.test(badge.textContent)) {
				return tr.getAttribute('data-world');
			}
		}
		return null;
	});

	if (!busyWorld) {
		console.log('  SKIP  non-stopped world gating (no running/repackaging modded world in the dev data)');
	} else {
		await page.evaluate(() => document.getElementById('cfgModalOverlay').classList.remove('show'));
		await clearOverlays();
		await page.click(`tr[data-world="${busyWorld}"] a[data-action="mods"]`);
		await page.waitForSelector('#modsHubOverlay.show', { timeout: 5000 });
		await page.waitForFunction(
			() => !/Checking this world/.test(document.getElementById('modsHubCards').innerHTML),
			{ timeout: 8000 }
		).catch(() => {});

		const gate = await page.evaluate(() => {
			const cards = Array.from(document.querySelectorAll('#modsHubCards .mods-hub-card'));
			return cards.map(c => ({
				title: c.querySelector('.mods-hub-card-title').textContent.trim(),
				enabled: c.tagName === 'A' && !c.classList.contains('disabled'),
				why: ((c.querySelector('.mods-hub-why') || {}).textContent || '').trim(),
			}));
		});

		const cat = gate.find(c => /Catalog/.test(c.title));
		const cfg = gate.find(c => /Configs/.test(c.title));

		// THE RULE. Saving a mod list calls updateWorld(), and mode='update' always ends
		// stopped -- so offering this on a live world would drop every connected player.
		if (cat && !cat.enabled && cat.why.length > 0) {
			ok(`Mod Catalog is closed on a ${busyWorld} that is not stopped, and says why: "${cat.why}"`);
		} else {
			bad('Mod Catalog is closed on a non-stopped world and says why',
				cat ? `enabled=${cat.enabled} why="${cat.why}"` : 'no Mod Catalog card found');
		}

		// ...and the other card must still be open, which is the whole reason 2.55 added the
		// repackage path. Gating both would put back the bug this replaced.
		if (cfg && cfg.enabled) {
			ok('Mod Configs stays open on a running world');
		} else {
			bad('Mod Configs stays open on a running world',
				cfg ? `enabled=${cfg.enabled} why="${cfg.why}"` : 'no Mod Configs card found');
		}
	}

	// ---- the POPULATED branch, which the live dev data cannot reach ----
	//
	// The dev worlds have no mods and have never started, so every list above took its
	// empty-state branch. That is exactly the branch that was NOT broken: the bug Brian hit
	// was escapeHtml() inside the .map() that renders a mod row, which only runs when there is
	// at least one row. An all-green run against empty data would have said nothing about it.
	//
	// So stub the two endpoints with real-shaped payloads and drive the same render functions.
	// Nothing about the rendering code is replaced -- only the data source -- so a missing
	// helper or a bad template still throws here.
	await clearOverlays();
	const populated = await page.evaluate(async () => {
		const realFetch = window.fetch;
		window.fetch = (url, ...rest) => {
			if (String(url).includes('getWorldConfigMods')) {
				return Promise.resolve({ json: () => Promise.resolve({
					world: 'stub', generated: true, total: 2,
					mods: [
						{ mod_id: 27850, name: 'Azumatt-AzuClock', files: 1, entry_count: 18, modified_count: 3 },
						// A name with characters that must be escaped, and the unmatched bucket,
						// whose mod_id is null and must render WITHOUT a &mod= parameter.
						{ mod_id: null, name: 'Unmatched <config> & "files"', files: 5, entry_count: 929, modified_count: 15 },
					],
				}) });
			}
			if (String(url).includes('getWorldMods')) {
				return Promise.resolve({ json: () => Promise.resolve({
					success: true,
					mods: [
						{ name: 'Therzie-Wizardry', url: 'https://example.invalid/a' },
						{ name: 'Smoothbrain & <co>', url: 'https://example.invalid/b' },
					],
				}) });
			}
			return realFetch(url, ...rest);
		};

		await window.loadModsHubInstalled('stub');
		await window.showConfigsModal('stub');
		window.fetch = realFetch;

		const listed = Array.from(document.querySelectorAll('#cfgModalList li[data-cfgname]'));
		return {
			cfgRows: listed.length,
			withModParam: document.querySelectorAll('#cfgModalList a[href*="&mod="]').length,
			unmatchedHasNoModParam: listed.some(li => {
				const a = li.querySelector('a');
				return a && /Unmatched/.test(a.textContent) && !a.getAttribute('href').includes('&mod=');
			}),
			// If a name were interpolated raw, the "<config>" would have become an element.
			escaped: !!document.querySelector('#cfgModalList li a') &&
				document.querySelector('#cfgModalList').innerHTML.includes('&lt;config&gt;'),
			changedBadges: document.querySelectorAll('#cfgModalList .mods-count-badge').length,
			installedRows: document.querySelectorAll('#modsHubList li').length,
			installedEscaped: document.getElementById('modsHubList').innerHTML.includes('&lt;co&gt;'),
			installedSummary: document.getElementById('modsHubInstalledSummary').textContent,
		};
	});

	if (populated.cfgRows === 2 && populated.changedBadges === 2) {
		ok('the picker renders a populated list (2 mods, both "changed" badges)');
	} else {
		bad('the picker renders a populated list', JSON.stringify(populated));
	}

	if (populated.withModParam === 1 && populated.unmatchedHasNoModParam) {
		ok('a matched mod links with &mod=, the unmatched bucket links without it');
	} else {
		bad('a matched mod links with &mod=, the unmatched bucket links without it',
			`withModParam=${populated.withModParam} unmatchedClean=${populated.unmatchedHasNoModParam}`);
	}

	if (populated.escaped) {
		ok('a mod name containing markup is escaped, not injected');
	} else {
		bad('a mod name containing markup is escaped, not injected',
			'"<config>" did not survive as text — the name is being interpolated raw');
	}

	if (populated.installedRows === 2 && populated.installedEscaped
	    && /\(2\)/.test(populated.installedSummary)) {
		ok('the installed list renders, counts and escapes a populated payload');
	} else {
		bad('the installed list renders, counts and escapes a populated payload', JSON.stringify(populated));
	}

	// ---- COVERAGE. The 6-of-29 case, driven with the real numbers off Brian's server ----
	//
	// The picker was correct and still read as broken: it showed 6 rows for a world with 29
	// installed mods, with nothing saying the other 23 had simply not written a config file
	// yet. These cases pin the three things that has to get right -- the counts, the advice
	// (which differs by world mode and must not tell someone to restart a live world to chase
	// configs that will never appear), and the unmatched row being accounted for so the banner
	// and the visible list cannot disagree.
	const covCases = [
		{ name: 'stopped, partial', mode: 'stopped', installed: 29, configured: 5, waiting: 24,
		  unmatched_files: 3,
		  wantClass: 'warn', want: [/5 of 29/, /Start this world once/, /3<\/b> config files/],
		  notWant: [/All 29/] },
		{ name: 'running, partial', mode: 'running', installed: 29, configured: 5, waiting: 24,
		  unmatched_files: 0,
		  wantClass: 'warn', want: [/5 of 29/, /already had the chance/],
		  // Telling an operator to restart a RUNNING world would disconnect players to chase
		  // configs that may not exist. This is the case that assertion exists for.
		  notWant: [/Start this world once/] },
		{ name: 'complete', mode: 'running', installed: 12, configured: 12, waiting: 0,
		  unmatched_files: 0,
		  wantClass: 'ok', want: [/All <b>12<\/b>/], notWant: [/have written a config file\. /, /no config file yet/] },
	];

	for (const c of covCases) {
		const got = await page.evaluate((c) => {
			const waiting = Array.from({length: c.waiting}, (_, i) => ({mod_id: 1000 + i, name: `Waiting-Mod-${i}`}));
			window.renderConfigCoverage('stub', {
				installed: c.installed, configured: c.configured, mode: c.mode,
				waiting, unmatched_files: c.unmatched_files,
			});
			const box = document.querySelector('#cfgModalCoverage .cfg-coverage');
			return {
				html: box ? box.innerHTML : null,
				cls: box ? (box.classList.contains('ok') ? 'ok' : (box.classList.contains('warn') ? 'warn' : '?')) : null,
				waitingRows: document.querySelectorAll('#cfgModalWaiting .mods-list li').length,
				summary: (document.querySelector('#cfgModalWaiting summary') || {}).textContent || '',
			};
		}, c);

		if (!got.html) { bad(`coverage [${c.name}] renders a banner`, 'no .cfg-coverage element'); continue; }

		const missing = c.want.filter(re => !re.test(got.html));
		const leaked  = c.notWant.filter(re => re.test(got.html));
		if (got.cls === c.wantClass && missing.length === 0 && leaked.length === 0) {
			ok(`coverage [${c.name}]: ${c.wantClass} banner, correct counts and advice`);
		} else {
			bad(`coverage [${c.name}]: ${c.wantClass} banner, correct counts and advice`,
				`class=${got.cls} missing=${missing} leaked=${leaked} html="${got.html.slice(0, 180)}"`);
		}

		if (got.waitingRows === c.waiting) {
			ok(`coverage [${c.name}]: ${c.waiting} mod(s) named in the waiting list`);
		} else {
			bad(`coverage [${c.name}]: ${c.waiting} mod(s) named in the waiting list`,
				`rendered ${got.waitingRows} rows, summary "${got.summary}"`);
		}
	}

	// An older server's payload has no coverage fields. Saying nothing is correct; rendering
	// "undefined of undefined" is the failure mode this guards.
	const legacy = await page.evaluate(() => {
		window.renderConfigCoverage('stub', { mods: [], total: 0 });
		return document.getElementById('cfgModalCoverage').innerHTML;
	});
	if (legacy.trim() === '') {
		ok('a payload with no coverage fields renders no banner (old server, tab left open)');
	} else {
		bad('a payload with no coverage fields renders no banner', `rendered "${legacy}"`);
	}

	// ---- the 2.55 migration review modal ----
	//
	// Driven with the LIVE numbers off Brian's server: 10 parked files, 3 of which hold 12
	// settings the database does not have. The report endpoint is stubbed rather than
	// fixtured because the at-risk state is the whole point and it cannot be produced on
	// demand -- it exists because something deleted override rows after the import wrote them.
	//
	// What must hold: safe files pre-ticked, at-risk files NOT pre-ticked, and the REASON
	// visible per file. An unticked checkbox on its own does not tell an operator that the
	// file is the last copy of 6 settings.
	await clearOverlays();
	const mig = await page.evaluate(() => {
		window.renderConfigMigration({
			totals: { files: 10, at_risk: 3 },
			worlds: [{
				world: 'VikingOutlaws', db_rows: 40, db_files: 7, at_risk: 3,
				files: [
					{ file: 'zolantris.ValheimRAFT.cfg', state: 'at_risk', rows_in_db: 0, changed: 6 },
					{ file: 'Azumatt.SleepSkip.cfg', state: 'at_risk', rows_in_db: 0, changed: 4 },
					{ file: 'spectralmemories.fasterboats.cfg', state: 'at_risk', rows_in_db: 0, changed: 2 },
					{ file: 'Azumatt.AzuClock.cfg', state: 'accounted', rows_in_db: 4, changed: null },
					{ file: 'ValheimFoodConfig.cfg', state: 'accounted', rows_in_db: 11, changed: null },
					{ file: 'unreadable.cfg', state: 'at_risk', rows_in_db: 0, changed: -1 },
					{ file: 'alldefaults.cfg', state: 'empty', rows_in_db: 0, changed: 0 },
				],
			}],
		});
		const rows = Array.from(document.querySelectorAll('#cfgMigBody input[data-mig-file]'));
		const byFile = {};
		for (const r of rows) {
			byFile[r.dataset.migFile] = {
				checked: r.checked,
				risk: !!r.closest('label').querySelector('.cfg-mig-why.risk'),
				why: r.closest('label').querySelector('.cfg-mig-why').textContent.trim(),
			};
		}
		return {
			count: rows.length,
			byFile,
			banner: (document.querySelector('#cfgMigBody .cfg-coverage') || {}).className || '',
			bannerText: (document.querySelector('#cfgMigBody .cfg-coverage') || {}).textContent || '',
			intro: document.getElementById('cfgMigIntro').textContent.trim().length,
		};
	});

	if (mig.count === 7) {
		ok('the migration review lists every parked file (7)');
	} else {
		bad('the migration review lists every parked file', `rendered ${mig.count} rows`);
	}

	const risky = ['zolantris.ValheimRAFT.cfg', 'Azumatt.SleepSkip.cfg',
	               'spectralmemories.fasterboats.cfg', 'unreadable.cfg'];
	const wrongTick = risky.filter(f => !mig.byFile[f] || mig.byFile[f].checked);
	if (wrongTick.length === 0) {
		ok('no at-risk or unreadable file is pre-ticked for deletion');
	} else {
		bad('no at-risk or unreadable file is pre-ticked for deletion',
			`pre-ticked: ${wrongTick.join(', ')} — a careless click would destroy the only copy`);
	}

	const safe = ['Azumatt.AzuClock.cfg', 'ValheimFoodConfig.cfg', 'alldefaults.cfg'];
	const notTicked = safe.filter(f => !mig.byFile[f] || !mig.byFile[f].checked);
	if (notTicked.length === 0) {
		ok('every accounted-for and empty file IS pre-ticked');
	} else {
		bad('every accounted-for and empty file IS pre-ticked', `not ticked: ${notTicked.join(', ')}`);
	}

	// The reason must name the number of settings at stake, not just say "at risk".
	const raft = mig.byFile['zolantris.ValheimRAFT.cfg'] || {};
	if (raft.risk && /6 changed setting/.test(raft.why) && /only copy/.test(raft.why)) {
		ok(`an at-risk file states what is at stake ("${raft.why.slice(0, 64)}…")`);
	} else {
		bad('an at-risk file states what is at stake', `risk=${raft.risk} why="${raft.why}"`);
	}

	const unread = mig.byFile['unreadable.cfg'] || {};
	if (/could not be read/.test(unread.why)) {
		ok('an unreadable file says so rather than being called empty');
	} else {
		bad('an unreadable file says so rather than being called empty', `why="${unread.why}"`);
	}

	if (/warn/.test(mig.banner) && /3 files cannot be accounted for/.test(mig.bannerText)) {
		ok('the banner warns, with the count');
	} else {
		bad('the banner warns, with the count', `class="${mig.banner}" text="${mig.bannerText.slice(0, 90)}"`);
	}

	if (mig.intro > 120) {
		ok('the modal explains that a migration happened');
	} else {
		bad('the modal explains that a migration happened', `intro is only ${mig.intro} chars`);
	}

	// All-clear state: green, and no waiting list.
	const clear = await page.evaluate(() => {
		window.renderConfigMigration({ totals: { files: 2, at_risk: 0 }, worlds: [{
			world: 'w', db_rows: 9, db_files: 2, at_risk: 0,
			files: [{ file: 'a.cfg', state: 'accounted', rows_in_db: 5, changed: null },
			        { file: 'b.cfg', state: 'accounted', rows_in_db: 4, changed: null }],
		}] });
		const b = document.querySelector('#cfgMigBody .cfg-coverage');
		return { cls: b ? b.className : '', text: b ? b.textContent : '',
		         ticked: document.querySelectorAll('#cfgMigBody input:checked').length };
	});
	if (/ok/.test(clear.cls) && clear.ticked === 2 && /loses nothing/.test(clear.text)) {
		ok('an all-accounted-for server gets the green all-clear with everything ticked');
	} else {
		bad('an all-accounted-for server gets the green all-clear', JSON.stringify(clear));
	}

	// Nothing parked at all -- the done state, which must not read as an error.
	const none = await page.evaluate(() => {
		window.renderConfigMigration({ totals: { files: 0, at_risk: 0 }, worlds: [] });
		const b = document.querySelector('#cfgMigBody .cfg-coverage');
		return { cls: b ? b.className : '', text: b ? b.textContent : '' };
	});
	if (/ok/.test(none.cls) && /Nothing left to review/.test(none.text)) {
		ok('a server with nothing parked says so, in the non-alarming style');
	} else {
		bad('a server with nothing parked says so', JSON.stringify(none));
	}

	if (errors.length === 0) {
		ok('no page errors while driving the hub');
	} else {
		bad('no page errors while driving the hub', errors.slice(0, 5).join(' | '));
	}

	await browser.close();
	console.log(`\n${pass} passed, ${fail} failed`);
	process.exit(fail === 0 ? 0 : 1);
})().catch((e) => { console.error('HARNESS ERROR: ' + e.message); process.exit(2); });
