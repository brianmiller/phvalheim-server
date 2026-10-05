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
		hasImport: !!label.querySelector('.cfg-mig-import'),
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
	if (/not in the database/i.test(orphan.why) && /no mod here reads/i.test(orphan.why)) {
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
	// The MOD NAME has to be in there, not just the word "installed" -- naming it is what tells
	// the operator which live mod would lose settings.
	if (/not in the database/i.test(kept.why) && /is installed/i.test(kept.why)
	    && /only copy/i.test(kept.why) && /SkyheimExtended/.test(kept.why)) {
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

	// ---- ONE banner, naming the repair and both flavours ----
	// It used to print two paragraphs, one per state. The states differ in urgency, not in what
	// to do about them, and two explanations of the same repair is what made this unreadable.
	const banners = await page.evaluate(() =>
		Array.from(document.querySelectorAll('#cfgMigBody .cfg-coverage'))
			.map(b => ({ warn: b.classList.contains('warn'), text: b.textContent.trim() })));
	if (banners.length === 1) {
		ok('the review shows a single banner, not one per state');
	} else {
		bad('the review shows a single banner, not one per state',
			`${banners.length} banners: ${banners.map(b => b.text.slice(0, 50)).join(' || ')}`);
	}
	const banner = banners[0];
	if (banner && /does not have/i.test(banner.text) && /Import/.test(banner.text)
	    && /still installed/i.test(banner.text) && /no longer has/i.test(banner.text)) {
		ok('the banner names the gap, the Import fix, and both flavours of file');
	} else {
		bad('the banner names the gap, the Import fix, and both flavours of file',
			`text was: "${banner ? banner.text.slice(0, 260) : '(none)'}"`);
	}
	// It must be a WARNING while a still-installed mod's only copy is on the line.
	if (banner && banner.warn) {
		ok('the banner is a warning while a still-installed mod has settings only in a file');
	} else {
		bad('the banner is a warning while a still-installed mod has settings only in a file',
			'it rendered as the safe variant');
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
	// ---- and the delete button starts with exactly the safe ones selected ----
	const ticked = await page.evaluate(() =>
		Array.from(document.querySelectorAll('#cfgMigBody input[data-mig-file]:checked'))
			.map(b => b.dataset.migFile));
	if (ticked.includes(ORPHAN) && !ticked.includes(KEPT)) {
		ok(`a Delete now would take ${ticked.length} file(s), including the orphan and not the risky one`);
	} else {
		bad('a Delete now would take the orphan and not the risky one', `ticked: ${ticked.join(', ')}`);
	}

	// ---- Import: the repair the review used to withhold ----
	// "N settings not in the database" is a fixable state, not a verdict. The files parse and
	// carry their own documented defaults -- the same rule the upgrade imported them by. A
	// review that reports the gap and offers only delete-or-keep is what made the operator ask
	// why they "can't" be migrated.
	if (orphan.hasImport && kept.hasImport) {
		ok('both files the database is missing offer Import');
	} else {
		bad('both files the database is missing offer Import',
			`orphan=${orphan.hasImport} stillInstalled=${kept.hasImport}`);
	}

	await page.evaluate((f) => document.querySelector(
		`#cfgMigBody input[data-mig-file="${f}"]`).closest('label')
		.querySelector('.cfg-mig-import').click(), KEPT);
	// The handler re-reads the report, so wait for the row to change state rather than a timer.
	await page.waitForFunction((f) => {
		const b = document.querySelector(`#cfgMigBody input[data-mig-file="${f}"]`);
		return b && !b.closest('label').querySelector('.cfg-mig-import');
	}, KEPT, { timeout: 10000 }).catch(() => {});

	const afterImport = await rowOf(page, KEPT);
	if (afterImport && /in the database/i.test(afterImport.why)
	    && !/not in the database/i.test(afterImport.why)) {
		ok(`importing ${KEPT} moves it to "settings in the database" ("${afterImport.why}")`);
	} else {
		bad(`importing ${KEPT} moves it to "settings in the database"`,
			`row now reads: "${afterImport ? afterImport.why : '(gone)'}" — the import did not take`);
	}
	// And now that the values are saved, the file is a duplicate: it must become safe AND
	// ticked. An import that leaves the file still flagged has not finished the job.
	if (afterImport && afterImport.checked && !afterImport.flaggedRisky) {
		ok('once imported the file is ticked and no longer flagged as a risk');
	} else {
		bad('once imported the file is ticked and no longer flagged as a risk',
			`checked=${afterImport && afterImport.checked} risky=${afterImport && afterImport.flaggedRisky}`);
	}
	const msg = await page.evaluate(() =>
		document.getElementById('cfgMigMsg').textContent.trim());
	if (/\d+ setting\(s\) imported/.test(msg)) {
		ok(`the import reports what it saved ("${msg}")`);
	} else {
		bad('the import reports what it saved', `status said: "${msg}"`);
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
