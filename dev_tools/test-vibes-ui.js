#!/usr/bin/env node
// Oracle test: admin/hugins_vibes.php renders states 1, 2, 5 and 6 of docs/HUGINS-VIBES-DESIGN.md 10.3.
// requires: container
//
// HOW. Drives the page on phvalheim-dev with Playwright. Each state is produced by a real
// STIMULUS (rows and files the page reads) and checked by its RESPONSE (what the operator sees):
//
//   state 2  not installed + no enabled AI provider: the DB is read FIRST (SELECT on ai_providers)
//            and the test refuses to run if a provider exists -- the "Install disabled" assertion
//            would otherwise be blind to the bug. Then: modal shown, red provider line, Install
//            disabled, the additional-download sentence and the data-volume path in the copy.
//   state 1  the same modal with the provider gate passing. The dev box has no provider (and the
//            brief forbids adding one), so the router and the gate are exercised in-page with the
//            provider status stubbed to ok: pickState() answers 'intro' and Install enables.
//            That is a stub, and the summary says so.
//   state 5  installed + libraries + zero projects: a fixture libs/manifest.json and
//            vibesInstalled=1 -> empty-state library with three starter cards.
//   state 6  a fixture hv_projects row + hv_sessions row + an empty transcript -> the workspace
//            opens the Vibe by name, its session is listed, the composer is present.
//
// Everything it creates is removed in `finally`, and settings go back to what they were.
// Screenshots land in /tmp/phv-vibes-shots/ named by state (for Brian's eyes, not asserted).
//
//   argv: admin base URL, container

const { chromium } = require('playwright');
const { execFileSync } = require('child_process');
const fs = require('fs');

const BASE = process.argv[2] || 'http://127.0.0.1:8081';
const CONTAINER = process.argv[3] || 'phvalheim-dev';
const SHOTS = '/tmp/phv-vibes-shots';
const SLUG = 'zz-ui-fixture', NAME = 'ZzUiFixture', NS = 'OracleTest';

let pass = 0, fail = 0;
const ok  = (m) => { console.log('  PASS  ' + m); pass++; };
const bad = (m, d) => { console.log('  FAIL  ' + m); if (d) console.log('        ' + String(d).slice(0, 300)); fail++; };
const sleep = (ms) => new Promise(r => setTimeout(r, ms));
function sql(q) { return execFileSync('docker', ['exec', CONTAINER, 'mysql', '-N', '-e', q, 'phvalheim'], { encoding: 'utf8' }).trim(); }
function sh(cmd) { return execFileSync('docker', ['exec', CONTAINER, 'bash', '-c', cmd], { encoding: 'utf8' }).trim(); }

(async () => {
	fs.mkdirSync(SHOTS, { recursive: true });
	const prior = sql('SELECT vibesInstalled, vibesNoticeShown FROM settings LIMIT 1').split('\t');
	const providers = sql('SELECT COUNT(*) FROM ai_providers WHERE enabled=1');
	// The stimulus for state 2, checked before anything is asserted about the response.
	if (providers !== '0') { console.log('HARNESS: ' + providers + ' enabled AI provider(s) exist; state 2 cannot be produced here'); process.exit(2); }
	const libsFixture = '/opt/stateful/vibes/libs/manifest.json';
	if (sh(`test -f ${libsFixture} && echo yes || echo no`) === 'yes') { console.log('HARNESS: real libraries are present at ' + libsFixture + '; refusing to overwrite them'); process.exit(2); }

	const browser = await chromium.launch();
	const page = await browser.newPage({ viewport: { width: 1920, height: 1080 } });
	const errors = [];
	page.on('pageerror', e => errors.push(String(e).slice(0, 200)));
	const open = async (w) => { if (w) await page.setViewportSize({ width: w, height: 1080 }); await page.goto(BASE + '/hugins_vibes.php', { waitUntil: 'networkidle' }); await page.waitForFunction(() => document.body.dataset.vbReady === '1'); await sleep(400); };
	const ev = (fn) => page.evaluate(fn);

	try {
		/* ---- state 2 ------------------------------------------------------------------- */
		sql('UPDATE settings SET vibesInstalled=0, vibesNoticeShown=0');
		await open(1920);
		const s2 = await ev(() => ({
			state: document.body.dataset.vbState,
			modal: document.getElementById('vbIntro').classList.contains('show'),
			title: document.getElementById('vbIntroTitle').textContent.trim(),
			aiBad: document.getElementById('vbCheckAi').classList.contains('bad'),
			aiText: document.getElementById('vbCheckAi').textContent,
			installDisabled: document.getElementById('vbIntroInstall').disabled,
			copy: document.querySelector('.vb-intro-text').textContent,
			disk: document.getElementById('vbCheckDisk').textContent,
			workspaceHidden: document.getElementById('vbWsProject').hidden,
		}));
		// Kept projects from an earlier install (or another test's rows) make this "Welcome back"
		// (state 8) instead of state 2; both are the same modal with the same gates. Say which.
		const existing = Number(sql('SELECT COUNT(*) FROM hv_projects'));
		const want = existing > 0 ? { state: 'deleted', title: 'Welcome back.' } : { state: 'intro-no-ai', title: 'I can build mods now.' };
		s2.state === want.state ? ok('state 2: router picked ' + want.state + ' (0 enabled providers, ' + existing + ' existing project rows)') : bad('state 2 router', s2.state + ' wanted ' + want.state);
		s2.modal && s2.title === want.title ? ok('state 2: the introduction modal is shown ("' + want.title + '")') : bad('state 2 modal', JSON.stringify(s2));
		s2.aiBad && /needs an AI provider/.test(s2.aiText) && /AI Helper/.test(s2.aiText) ? ok('state 2: red provider line with the Server Settings > AI Helper link') : bad('state 2 provider line', s2.aiText);
		s2.installDisabled ? ok('state 2: Download & Install is disabled') : bad('state 2 Install should be disabled');
		/additional download, separate from the PhValheim image and from Valheim/.test(s2.copy) ? ok('modal copy: says it is an additional download, separate from the image and Valheim') : bad('modal copy: additional download sentence', s2.copy);
		/\/opt\/stateful\/vibes/.test(s2.copy) ? ok('modal copy: names the destination on the data volume') : bad('modal copy: destination');
		/free on the data volume .* required/.test(s2.disk) ? ok('modal: free space vs required is shown') : bad('modal disk line', s2.disk);
		s2.workspaceHidden ? ok('state 2: no workspace behind the modal') : bad('state 2 workspace visible');
		await page.screenshot({ path: SHOTS + '/state2-no-provider-1920.png' });
		await page.setViewportSize({ width: 1280, height: 900 }); await sleep(300);
		await page.screenshot({ path: SHOTS + '/state2-no-provider-1280.png' });

		/* ---- state 1 (provider gate stubbed in-page; see header) ----------------------- */
		const s1 = await ev(() => {
			// projectCount pinned to 0: this asks about the provider branch, not the kept-projects one.
			const st = Object.assign(JSON.parse(JSON.stringify(Vibes.S.state)), { installed: false, projectCount: 0, jobs: [] });
			const picked = { noAi: Vibes.router.pickState(st), withAi: Vibes.router.pickState(Object.assign({}, st, { ai: { ok: true, provider: 'Stub', model: 'stub-model' } })) };
			Vibes.S.state.ai = { ok: true, provider: 'Stub provider', model: 'stub-model', reason: '' };
			Vibes.intro.renderChecks();
			return { picked, installEnabled: !document.getElementById('vbIntroInstall').disabled, aiOk: document.getElementById('vbCheckAi').classList.contains('ok') };
		});
		s1.picked.withAi === 'intro' && s1.picked.noAi === 'intro-no-ai' ? ok('state 1: pickState answers intro once the provider is ok (stubbed) and intro-no-ai without') : bad('state 1 router', JSON.stringify(s1.picked));
		s1.installEnabled && s1.aiOk ? ok('state 1: with provider + disk green the Install button enables (provider stubbed in-page)') : bad('state 1 install gate', JSON.stringify(s1));
		await page.screenshot({ path: SHOTS + '/state1-first-visit-stubbed-provider.png' });

		/* ---- Not now: dismissNotice is called and the flag lands in the DB -------------- */
		await page.click('#vbIntroLater'); await sleep(600);
		const notice = sql('SELECT vibesNoticeShown FROM settings LIMIT 1');
		notice === '1' ? ok('Not now: vibesNoticeShown=1 in the DB') : bad('Not now flag', notice);
		(await ev(() => !document.getElementById('vbIntro').classList.contains('show'))) ? ok('Not now: the modal closed') : bad('Not now modal still open');

		/* ---- state 5 ------------------------------------------------------------------- */
		sh(`mkdir -p /opt/stateful/vibes/libs && printf '{"gameVersion":"0.0.0-fixture","sourceWorld":"fixture","harvestedAt":"2026-01-01T00:00:00Z","files":{}}' > ${libsFixture} && chown phvalheim: ${libsFixture}`);
		sql('UPDATE settings SET vibesInstalled=1, vibesNoticeShown=1');
		await open(1920);
		const s5 = await ev(() => ({ state: document.body.dataset.vbState, starters: document.querySelectorAll('.vb-starter').length, modal: document.getElementById('vbIntro').classList.contains('show'), lib: document.getElementById('vbLibList').textContent, newBtn: document.getElementById('vbBtnNewVibe').disabled }));
		s5.state === 'empty' ? ok('state 5: router picked empty (installed, libs present, 0 projects)') : bad('state 5 router', s5.state);
		s5.starters === 3 && !s5.modal ? ok('state 5: three starter ideas, no modal') : bad('state 5 starters', JSON.stringify(s5));
		/No Vibes yet/.test(s5.lib) && !s5.newBtn ? ok('state 5: empty-state library with + New Vibe enabled') : bad('state 5 library', s5.lib);
		await page.screenshot({ path: SHOTS + '/state5-empty-library.png' });

		/* ---- state 6 ------------------------------------------------------------------- */
		sql(`INSERT INTO hv_projects (slug, name, namespace, description, version, template, status) VALUES ('${SLUG}', '${NAME}', '${NS}', 'UI oracle fixture', '0.1.0', 'basic-plugin', 'draft')`);
		const pid = sql(`SELECT id FROM hv_projects WHERE slug='${SLUG}'`);
		sql(`INSERT INTO hv_sessions (project_id, title) VALUES (${pid}, 'fixture session')`);
		const sid = sql(`SELECT id FROM hv_sessions WHERE project_id=${pid}`);
		sh(`mkdir -p /opt/stateful/vibes/projects/${SLUG}/.vibes/sessions && : > /opt/stateful/vibes/projects/${SLUG}/.vibes/sessions/${sid}.jsonl && chown -R phvalheim: /opt/stateful/vibes/projects/${SLUG}`);
		await open(1920);
		const s6a = await ev(() => ({ state: document.body.dataset.vbState, cards: document.querySelectorAll('.vb-lib-card').length, card: (document.querySelector('.vb-lib-card') || {}).textContent }));
		s6a.state === 'working' ? ok('state 6: router picked working') : bad('state 6 router', s6a.state);
		s6a.cards === 1 && new RegExp(NAME).test(s6a.card) ? ok('state 6: the fixture Vibe is in the library') : bad('state 6 library card', JSON.stringify(s6a));
		await page.click('.vb-lib-card'); await page.waitForFunction(() => !document.getElementById('vbWsProject').hidden); await sleep(800);
		const s6 = await ev(() => ({
			name: document.getElementById('vbPName').textContent, ns: document.getElementById('vbPNs').textContent,
			composer: !!document.getElementById('vbComposerText'), sessions: document.getElementById('vbSessions').textContent,
			tabs: Array.from(document.querySelectorAll('#vbTabs .vb-tab')).map(t => t.dataset.tab).join(','), active: (document.querySelector('.vb-lib-card.active') || {}).textContent,
			hint: document.getElementById('vbComposerHint').textContent, title: document.title,
		}));
		s6.name === NAME && s6.ns === NS ? ok('state 6: the workspace shows the Vibe\'s name and namespace') : bad('state 6 head', JSON.stringify(s6));
		/fixture session/.test(s6.sessions) ? ok('state 6: its session is listed in the context rail') : bad('state 6 sessions', s6.sessions);
		s6.composer && /^vibe,code,build,package,history/.test(s6.tabs) ? ok('state 6: composer and the five tabs are present') : bad('state 6 tabs', s6.tabs);
		/No AI provider/.test(s6.hint) ? ok('state 6: the composer says why it is locked (no AI provider)') : bad('state 6 composer reason', s6.hint);
		await page.screenshot({ path: SHOTS + '/state6-working-1920.png' });
		await page.click('[data-tab="code"]'); await sleep(300); await page.screenshot({ path: SHOTS + '/state6-code-tab.png' });
		await page.click('[data-tab="vibe"]');
		await page.setViewportSize({ width: 1280, height: 900 }); await sleep(400);
		const layout = await ev(() => ({ cols: getComputedStyle(document.getElementById('vbMain')).gridTemplateColumns.split(' ').length, ctx: getComputedStyle(document.getElementById('vbContext')).display }));
		layout.cols === 3 && layout.ctx !== 'none' ? ok('1280 wide: three columns, context rail visible') : bad('1280 layout', JSON.stringify(layout));
		await page.screenshot({ path: SHOTS + '/state6-working-1280.png' });
		await page.setViewportSize({ width: 1000, height: 900 }); await sleep(400);
		const narrow = await ev(() => ({ ctx: getComputedStyle(document.getElementById('vbContext')).display, libBtn: getComputedStyle(document.getElementById('vbBtnLibrary')).display, ctxTab: getComputedStyle(document.querySelector('[data-tab="context"]')).display }));
		narrow.ctx === 'none' && narrow.libBtn !== 'none' && narrow.ctxTab !== 'none' ? ok('below 1200: library becomes a drawer button, context becomes a tab') : bad('narrow layout', JSON.stringify(narrow));
		await page.click('#vbBtnLibrary'); await sleep(350);
		await page.screenshot({ path: SHOTS + '/state6-narrow-1000-drawer.png' });

		errors.length === 0 ? ok('no page errors across every state') : bad('page errors', errors.join(' | '));
	} catch (e) {
		bad('harness exception', e && e.stack ? e.stack.split('\n').slice(0, 3).join(' ') : e);
	} finally {
		try { sql(`DELETE FROM hv_sessions WHERE project_id IN (SELECT id FROM hv_projects WHERE slug='${SLUG}')`); sql(`DELETE FROM hv_projects WHERE slug='${SLUG}'`); } catch (e) { bad('cleanup rows', e.message); }
		try { sh(`rm -rf /opt/stateful/vibes/projects/${SLUG}; rm -f ${libsFixture}`); } catch (e) { bad('cleanup files', e.message); }
		try { sql(`UPDATE settings SET vibesInstalled=${prior[0] || 0}, vibesNoticeShown=${prior[1] || 0}`); } catch (e) { bad('restore settings', e.message); }
		const left = sql(`SELECT COUNT(*) FROM hv_projects WHERE slug='${SLUG}'`) + sh(`test -e ${libsFixture} && echo 1 || echo 0`) + sh(`test -e /opt/stateful/vibes/projects/${SLUG} && echo 1 || echo 0`);
		left === '000' ? ok('cleanup: fixture rows, transcript and libs manifest removed; settings restored') : bad('cleanup left something behind', left);
		await browser.close();
	}
	console.log('\nvibes UI: ' + pass + ' passed, ' + fail + ' failed');
	process.exit(fail ? 1 : 0);
})();
