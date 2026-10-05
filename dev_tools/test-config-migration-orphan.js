// Oracle test: the migration review separates "nobody can read this any more" from "this is
// the only copy of a live setting".
//
// The complaint this exists for: VikingOutlaws listed three parked files as "cannot be
// accounted for ... deleting them would lose those settings", all three unticked. Two of those
// mods are not in that world at all, so nothing on that server can ever read those files --
// they are safe, and the modal said the opposite of that. The operator cannot tell the two
// cases apart by looking, so the page has to.
//
// The rules, each a rendered behaviour that reading the report code cannot confirm:
//
//   - a parked file whose mod the world NO LONGER HAS   -> ticked, and says so in words
//   - a parked file whose mod is STILL INSTALLED        -> unticked, names the mod
//   - the two get SEPARATE banners, because they are safe/unsafe for different reasons
//   - "Delete ticked originals" therefore starts with the orphans already selected
//
// The orphan test must be ATTRIBUTION, not "is the .cfg on disk": an installed mod that has
// never run writes no config either, and calling that safe would invite deleting settings the
// world's first start is about to need. This drives the real page, so it exercises whichever
// test the code actually uses.
//
// Fixture (dev container). One mod attached to the world, two parked files -- one named after
// that mod, one named after a mod the world does not have. Note `# Default value:` with ONE
// hash: the parser's RE_META_DEFAULT does not match `##`, and a `##` fixture parses as zero
// changed settings, which classifies as 'empty' and would pass this suite for the wrong reason.
//
//   docker exec phvalheim-dev sh -c '
//     mysql -uroot phvalheim -e "INSERT IGNORE INTO world_mods (world_id, mod_id) VALUES (1,1);"
//     P=/opt/stateful/games/valheim/worlds/midgard/custom_configs/.imported-pre-2.55
//     mkdir -p "$P"
//     printf "[General]\n# Default value: 30\nMaxSailSpeed = 45\n" > "$P/SkyheimExtended.cfg"
//     printf "[General]\n# Default value: 5\nSleepDelay = 9\n" > "$P/zolantris.GoneForever.cfg"
//     chown -R phvalheim:phvalheim "$P"'
//
// Usage:
//   docker run --rm --network host -v "$PWD":/repo -w /repo/dev_tools \
//     mcr.microsoft.com/playwright:v1.47.0-jammy bash -c \
//     'npm i --silent --prefix /tmp/pw playwright@1.47.0 >/dev/null 2>&1;
//      NODE_PATH=/tmp/pw/node_modules node test-config-migration-orphan.js \
//        http://127.0.0.1:8081 midgard zolantris.GoneForever.cfg SkyheimExtended.cfg'

const { chromium } = require('playwright');

const BASE   = process.argv[2] || 'http://127.0.0.1:8081';
const WORLD  = process.argv[3];
const ORPHAN = process.argv[4];   // parked file whose mod the world no longer has
const KEPT   = process.argv[5];   // parked file whose mod is still installed
let pass = 0, fail = 0;
const ok  = (m) => { console.log('  PASS  ' + m); pass++; };
const bad = (m, d) => { console.log('  FAIL  ' + m); console.log('        ' + d); fail++; };

const rowOf = (page, file) => page.evaluate((f) => {
	const box = document.querySelector(`#cfgMigBody input[data-mig-file="${f}"]`);
	if (!box) { return null; }
	const label = box.closest('label');
	const why = label.querySelector('.cfg-mig-why');
	return {
		checked: box.checked,
		why: (why ? why.textContent : '').trim(),
		flaggedRisky: !!(why && why.classList.contains('risk')),
	};
}, file);

(async () => {
	if (!WORLD || !ORPHAN || !KEPT) {
		console.error('usage: ... <world> <orphan.cfg> <still-installed.cfg>');
		process.exit(2);
	}
	const browser = await chromium.launch();
	const page = await browser.newPage();
	const errors = [];
	page.on('pageerror', e => errors.push(e.message));

	await page.setViewportSize({ width: 1557, height: 1000 });
	await page.goto(BASE + '/index.php', { waitUntil: 'networkidle' });

	// Open the review from the sidebar and scope it to nothing in particular, then confirm the
	// fixture is actually on the page -- otherwise every assertion below passes vacuously.
	await page.evaluate((w) => showConfigMigration(w), WORLD);
	await page.waitForFunction(
		() => document.querySelectorAll('#cfgMigBody input[data-mig-file]').length > 0,
		{ timeout: 8000 }).catch(() => {});

	const orphan = await rowOf(page, ORPHAN);
	const kept   = await rowOf(page, KEPT);
	if (!orphan || !kept) {
		bad('both fixture files are listed in the review',
			`orphan=${!!orphan} kept=${!!kept} — the fixture is not on the page, so this suite `
			+ 'would report success without testing anything');
		await browser.close();
		console.log(`\n${pass} passed, ${fail} failed`);
		process.exit(1);
	}
	ok('both fixture files are listed in the review');

	// ---- the orphan is safe, ticked, and SAYS it ----
	if (orphan.checked) {
		ok(`${ORPHAN} (mod gone) is ticked for deletion`);
	} else {
		bad(`${ORPHAN} (mod gone) is ticked for deletion`,
			'left unticked — this is the exact complaint: a file nothing can read, presented as a risk');
	}
	// BOTH facts, not just the reassuring one. An orphan is "the database does not have these"
	// AND "nothing here can read them". Saying only the second made the banner's own advice --
	// you would set the mod up from its defaults again -- unanswerable: if it was all migrated,
	// why would anything need re-entering? That question is what sent me back to this wording.
	if (/NOT in the database/i.test(orphan.why)
	    && /no mod in this world uses this config/i.test(orphan.why)
	    && /safe to delete/i.test(orphan.why)) {
		ok('the orphan row states the database lacks the settings AND why it is still safe');
	} else {
		bad('the orphan row states the database lacks the settings AND why it is still safe',
			`reason was: "${orphan.why}"`);
	}
	if (!orphan.flaggedRisky) {
		ok('the orphan row is not styled as a risk');
	} else {
		bad('the orphan row is not styled as a risk',
			'it carries .risk, so it reads as dangerous while being described as safe');
	}

	// ---- the still-installed one stays unticked and NAMES the mod ----
	if (!kept.checked) {
		ok(`${KEPT} (mod still installed) is left unticked`);
	} else {
		bad(`${KEPT} (mod still installed) is left unticked`,
			'pre-ticked — one click would destroy the only copy of a live setting');
	}
	if (/still installed/i.test(kept.why) && /only copy/i.test(kept.why)) {
		ok('the at-risk row names the installed mod and says it is the only copy');
	} else {
		bad('the at-risk row names the installed mod and says it is the only copy',
			`reason was: "${kept.why}"`);
	}
	if (kept.flaggedRisky) {
		ok('the at-risk row is styled as a risk');
	} else {
		bad('the at-risk row is styled as a risk', 'no .risk class — it looks as safe as the orphan');
	}

	// ---- two separate banners, not one verdict for both ----
	const banners = await page.evaluate(() =>
		Array.from(document.querySelectorAll('#cfgMigBody .cfg-coverage'))
			.map(b => ({ warn: b.classList.contains('warn'), text: b.textContent.trim() })));
	const warnBanner = banners.find(b => b.warn);
	const okBanner   = banners.find(b => !b.warn);
	if (warnBanner && okBanner) {
		ok('the review shows both a warning banner and a safe banner');
	} else {
		bad('the review shows both a warning banner and a safe banner',
			`banners: ${JSON.stringify(banners.map(b => b.warn))} — one verdict cannot describe both cases`);
	}
	if (okBanner && /does not have/i.test(okBanner.text) && /no longer has/i.test(okBanner.text)
	    && /safe to delete/i.test(okBanner.text) && /only copy/i.test(okBanner.text)) {
		ok('the safe banner names both facts and warns the delete is final');
	} else {
		bad('the safe banner names both facts and warns the delete is final',
			`text was: "${okBanner ? okBanner.text.slice(0, 220) : '(none)'}"`);
	}
	// The intro must not claim EVERY setting was migrated -- it is the sentence that makes the
	// list underneath look like a contradiction.
	const intro = await page.evaluate(() =>
		document.getElementById('cfgMigIntro').textContent);
	if (!/every setting/i.test(intro) && /as it stands now/i.test(intro)) {
		ok('the intro says the comparison is against the database as it stands now');
	} else {
		bad('the intro says the comparison is against the database as it stands now',
			'it still claims every setting was migrated, which the list below contradicts');
	}
	// The warning must be scoped to the still-installed case now, or it still over-claims.
	if (warnBanner && /still has/i.test(warnBanner.text)) {
		ok('the warning banner is scoped to mods the world still has');
	} else {
		bad('the warning banner is scoped to mods the world still has',
			`text was: "${warnBanner ? warnBanner.text.slice(0, 160) : '(none)'}"`);
	}

	// ---- and the delete button starts with exactly the safe ones selected ----
	const ticked = await page.evaluate(() =>
		Array.from(document.querySelectorAll('#cfgMigBody input[data-mig-file]:checked'))
			.map(b => b.dataset.migFile));
	if (ticked.includes(ORPHAN) && !ticked.includes(KEPT)) {
		ok(`a Delete now would take ${ticked.length} file(s), including the orphan and not the risky one`);
	} else {
		bad('a Delete now would take the orphan and not the risky one', `ticked: ${ticked.join(', ')}`);
	}

	if (errors.length === 0) {
		ok('no page errors while rendering the review');
	} else {
		bad('no page errors while rendering the review', errors.slice(0, 4).join(' | '));
	}

	await browser.close();
	console.log(`\n${pass} passed, ${fail} failed`);
	process.exit(fail === 0 ? 0 : 1);
})().catch((e) => { console.error('HARNESS ERROR: ' + e.message); process.exit(2); });
