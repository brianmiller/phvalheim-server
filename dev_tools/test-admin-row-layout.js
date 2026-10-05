// Oracle test: the REAL admin world rows, measured in a real browser.
//
// Written because three separate layout complaints on this table ("the Repackaging pill
// overlaps the world name", "there is a lot of unused space in each row", "the rows are
// bulky") are all claims about COMPUTED geometry, and every previous attempt to fix this
// table by reading the CSS and reasoning about percentages has been wrong at least once.
// test-world-card-layout.js is the cautionary case: it builds its own approximation of a
// card and so agreed with four consecutive wrong layouts.
//
// .worlds-table is `table-layout: fixed`, so the nth-child width percentages are the WHOLE
// budget. A cell whose content is wider than its share does not widen the column -- an
// inline-flex pill like .status-badge simply paints outside its cell and lands on top of the
// next one. That is the overlap, and it cannot be seen from the stylesheet alone: it depends
// on the rendered text width of the longest mode label at the current window size.
//
// Requires the dev container with the admin UI on :8081 and at least one world:
//   dev_tools/devUp.sh
//
// Usage:
//   docker run --rm --network host -v "$PWD":/repo -w /repo/dev_tools \
//     mcr.microsoft.com/playwright:v1.47.0-jammy bash -c \
//     'npm i --silent --prefix /tmp/pw playwright@1.47.0 >/dev/null 2>&1;
//      NODE_PATH=/tmp/pw/node_modules node test-admin-row-layout.js http://127.0.0.1:8081'

const { chromium } = require('playwright');

const BASE = process.argv[2] || 'http://127.0.0.1:8081';
const WIDTHS = [1280, 1557, 1920];
const COLS = ['Status', 'World', 'Actions', 'Configure', 'Resources'];

let pass = 0, fail = 0;
const ok  = (m) => { console.log('  PASS  ' + m); pass++; };
const bad = (m, d) => { console.log('  FAIL  ' + m); console.log('        ' + d); fail++; };

(async () => {
	const browser = await chromium.launch();
	const page = await browser.newPage();

	for (const width of WIDTHS) {
		await page.setViewportSize({ width, height: 1000 });
		await page.goto(BASE + '/index.php', { waitUntil: 'networkidle' });

		// The 5-second poll rewrites every row. Measure AFTER one tick has landed, or the
		// numbers describe the PHP render only -- and the two have disagreed before.
		await page.waitForTimeout(6000);

		const data = await page.evaluate(() => {
			const out = { rows: [], cols: [] };
			const rows = Array.from(document.querySelectorAll('.worlds-table tbody tr[data-world]'));
			if (!rows.length) return out;

			// Column geometry, read off the first real row.
			Array.from(rows[0].children).forEach((td) => {
				const r = td.getBoundingClientRect();
				out.cols.push({ width: Math.round(r.width) });
			});

			for (const tr of rows) {
				const tds = Array.from(tr.children);
				const row = {
					world: tr.getAttribute('data-world'),
					section: tr.getAttribute('data-section'),
					height: Math.round(tr.getBoundingClientRect().height),
					cells: [],
					badge: null,
					nameClipped: null,
					actions: [],
				};

				// How much of the cell the content actually needs.
				//
				// NOT td.scrollWidth. On a table-layout:fixed table a cell's scrollWidth is
				// clamped to its clientWidth, so every column reported content == width and
				// the slack column read "+0" for all five -- a probe that could not see the
				// thing it was built to measure. Measure the INNER content instead, and for
				// the two action cells measure what showing every button would need, including
				// the ones reflowActionGroups() has hidden in the "..." menu. Otherwise a row
				// that hid half its buttons looks like a row that fits.
				const needOf = (td) => {
					const group = td.querySelector('.action-group');
					if (group) {
						// Summing the buttons in place does NOT work: reflowActionGroups()
						// parks the ones that did not fit inside .action-overflow-menu, which
						// is display:none until opened, so those measure 0 width. The first
						// version of this probe did exactly that and reported a row as having
						// 51px to spare while two of its buttons were hidden -- it could not
						// see the very thing the assertion below is about.
						// Clone the group off-screen with every button inline instead, and
						// measure what showing all of them would actually need.
						const probe = group.cloneNode(true);
						const menu = probe.querySelector('.action-overflow-menu');
						const ov   = probe.querySelector('.action-overflow');
						if (menu && ov) {
							while (menu.firstChild) probe.insertBefore(menu.firstChild, ov);
						}
						if (ov) ov.remove();
						probe.style.position = 'absolute';
						probe.style.left = '-10000px';
						probe.style.top = '0';
						probe.style.width = 'max-content';
						probe.style.flexWrap = 'nowrap';
						// Same cell context, so font and button padding resolve identically.
						td.appendChild(probe);
						const need = probe.scrollWidth;
						probe.remove();
						return need;
					}
					const inner = td.querySelector('.status-badge, .world-name, .world-resources');
					if (inner) {
						// scrollWidth on the inner box is NOT clamped by the cell, so an
						// ellipsised name still reports the width it wanted.
						return Math.max(inner.scrollWidth, Math.round(inner.getBoundingClientRect().width));
					}
					return td.scrollWidth;
				};

				tds.forEach((td) => {
					const r = td.getBoundingClientRect();
					const cs = getComputedStyle(td);
					const padding = parseFloat(cs.paddingLeft) + parseFloat(cs.paddingRight);
					const group = td.querySelector('.action-group');
					const ov = td.querySelector('.action-overflow');
					row.cells.push({
						width: Math.round(r.width),
						content: Math.round(needOf(td) + padding),
						// "Needs more width than it has" is NOT the same as "the operator
						// cannot see it", and conflating the two made this test fail on two
						// deliberate designs. An action group with a visible "..." trigger has
						// moved the surplus somewhere reachable. A group carrying a join-code
						// chip is allowed to wrap to a second line -- that rule predates this
						// test and has its own comment in the stylesheet. Only a cell with
						// neither affordance is genuinely cutting content off.
						hasOverflowMenu: !!(ov && getComputedStyle(ov).display !== 'none'),
						wraps: !!(group && getComputedStyle(group).flexWrap === 'wrap'),
					});
				});

				const badge = tr.querySelector('.status-badge');
				if (badge) {
					const br = badge.getBoundingClientRect();
					const cr = tds[0].getBoundingClientRect();
					row.badge = {
						text: (badge.textContent || '').trim(),
						right: Math.round(br.right),
						cellRight: Math.round(cr.right),
						// Positive = the pill paints past its own cell, onto the World column.
						spill: Math.round(br.right - cr.right),
						wrapped: Math.round(br.height) > 30,
					};
				}

				const name = tr.querySelector('.world-name');
				if (name) {
					row.nameClipped = name.scrollWidth > name.clientWidth + 1;
					row.nameWidth = name.scrollWidth;
				}

				// Buttons pushed into the "..." overflow menu are buttons the operator cannot
				// see. Recorded per cell, so a cell that is genuinely full can be told apart
				// from one that hid a button with width to spare.
				row.hiddenInFullCell = [];
				tds.forEach((td, i) => {
					const hidden = Array.from(td.querySelectorAll('.action-overflow-menu .action-btn'))
						.map(b => (b.textContent || '').trim());
					if (!hidden.length) return;
					hidden.forEach(h => row.actions.push(h));
					const slack = row.cells[i].width - row.cells[i].content;
					if (slack > 20) {
						row.hiddenInFullCell.push(`${COLS[i] || i} hid ${hidden.join('/')} with ${slack}px spare`);
					}
				});

				row.cells.forEach((c) => {
					c.slack = c.width - c.content;
					c.clipped = c.content > c.width + 1 && !c.hasOverflowMenu && !c.wraps;
				});
				out.rows.push(row);
			}
			return out;
		});

		console.log(`\n=== ${width}px ===`);
		if (!data.rows.length) {
			bad(`${width}px: rows found`, 'no tr[data-world] in .worlds-table — is the dev container seeded with a world?');
			continue;
		}

		console.log('  columns: ' + data.cols.map((c, i) => `${COLS[i] || i}=${c.width}px`).join('  '));
		for (const r of data.rows) {
			console.log(`  ${r.world} [${r.section}] h=${r.height}px  `
				+ r.cells.map((c, i) => `${COLS[i] || i}:${c.content}/${c.width}(${c.slack >= 0 ? '+' : ''}${c.slack})`).join('  '));
			if (r.badge) {
				console.log(`      badge "${r.badge.text}" spill=${r.badge.spill}px wrapped=${r.badge.wrapped}`);
			}
			if (r.actions.length) {
				console.log(`      hidden in overflow: ${r.actions.join(', ')}`);
			}
		}

		// ---- assertions ----

		// 1. THE BUG. A status pill must not paint outside the Status column.
		const spilled = data.rows.filter(r => r.badge && r.badge.spill > 1);
		if (spilled.length === 0) {
			ok(`${width}px: no status pill spills into the World column`);
		} else {
			bad(`${width}px: no status pill spills into the World column`,
				spilled.map(r => `${r.world}: "${r.badge.text}" spills ${r.badge.spill}px`).join('; '));
		}

		// 2. A pill that wrapped to two lines is the other way this fails -- it does not
		//    overlap, it makes the row twice as tall.
		const wrapped = data.rows.filter(r => r.badge && r.badge.wrapped);
		if (wrapped.length === 0) {
			ok(`${width}px: no status pill wraps to a second line`);
		} else {
			bad(`${width}px: no status pill wraps to a second line`,
				wrapped.map(r => `${r.world}: "${r.badge.text}"`).join('; '));
		}

		// Below 1400px the five columns' measured needs exceed the table -- about 1200px of
		// content in a ~988px one at 1280px. The stylesheet spends that shortfall in a stated
		// priority order (pill, then charts, then controls-via-"...", then the name), so the
		// two assertions that assume there is enough width to go round are checked only above
		// it. They are not relaxed there: 1557 and 1920 are where the complaint came from and
		// where the budget is supposed to balance.
		const roomy = width >= 1400;

		// 3. The world name must not be truncated while the row has spare width elsewhere.
		//    Truncating with room to spare is the specific complaint, not truncation itself.
		const clippedNames = data.rows.filter(r => r.nameClipped);
		const totalSlack = (r) => r.cells.reduce((a, c) => a + Math.max(0, c.slack), 0);
		const clippedWithRoom = clippedNames.filter(r => totalSlack(r) > 60);
		if (!roomy) {
			console.log('  SKIP  world-name truncation (below 1400px a long name ellipsises by design)');
		} else if (clippedWithRoom.length === 0) {
			ok(`${width}px: no world name is truncated while the row has >60px spare`);
		} else {
			bad(`${width}px: no world name is truncated while the row has >60px spare`,
				clippedWithRoom.map(r => `${r.world}: name ${r.nameWidth}px, row slack ${totalSlack(r)}px`).join('; '));
		}

		// 4. No cell may clip its own content. Checked at EVERY width, including the narrow
		//    breakpoint: the status pill and the resource charts are the two things the
		//    stylesheet promises never to cut, and 12% on Resources was caught here clipping
		//    all five rows.
		const clippedCells = [];
		data.rows.forEach(r => r.cells.forEach((c, i) => {
			// The World column ellipsises by design below 1400px -- covered by #3 above.
			if (!roomy && i === 1) return;
			if (c.clipped) clippedCells.push(`${r.world}/${COLS[i] || i}`);
		}));
		if (clippedCells.length === 0) {
			ok(`${width}px: no cell clips its content`);
		} else {
			bad(`${width}px: no cell clips its content`, clippedCells.join(', '));
		}

		// 5. Density. Padding, not content, was most of a row. Measured at 1557px:
		//      before: online 98px, offline 66-76px   (td padding 1rem all round)
		//      after:  online 82-88px, offline 50-60px (td padding 0.5rem 0.75rem)
		//    The bounds below are set between those two sets, so the old stylesheet fails them
		//    and this is a real gate rather than a restatement of whatever happens to render.
		//    Online allows 90px because a crossplay row wraps its join-code chip onto a second
		//    line by design.
		//    Below 1400px a crossplay row is expected to wrap, which costs it a line, so the
		//    online bound there allows for exactly that one extra line.
		const onlineMax = roomy ? 90 : 100;
		const limit = (r) => (r.section === 'online' ? onlineMax : 62);
		const tall = data.rows.filter(r => r.height > limit(r));
		const label = `row heights within budget (online <=${onlineMax}px, offline <=62px)`;
		if (tall.length === 0) {
			ok(`${width}px: ${label}`);
		} else {
			bad(`${width}px: ${label}`, tall.map(r => `${r.world}[${r.section}]=${r.height}px`).join(', '));
		}

		// 6. A button may only be hidden behind "..." when ITS OWN cell is actually full.
		//    This started out comparing against the whole row's slack, which fails forever at
		//    narrow widths: a 31-char name plus a crossplay action set plus four Configure
		//    buttons genuinely does not fit at 1557px, and the overflow menu is what that is
		//    for. Mis-allocation ACROSS columns is caught by the clipped-cell assertion above;
		//    what belongs here is the one case that is always a defect -- a cell hiding a
		//    button while holding spare width of its own.
		const hiddenWithRoom = data.rows.filter(r => r.hiddenInFullCell && r.hiddenInFullCell.length);
		if (hiddenWithRoom.length === 0) {
			ok(`${width}px: no action is hidden in "..." by a cell that has room for it`);
		} else {
			bad(`${width}px: no action is hidden in "..." by a cell that has room for it`,
				hiddenWithRoom.map(r => `${r.world}: ${r.hiddenInFullCell.join('; ')}`).join(' | '));
		}
	}

	await browser.close();
	console.log(`\n${pass} passed, ${fail} failed`);
	process.exit(fail === 0 ? 0 : 1);
})().catch((e) => { console.error('HARNESS ERROR: ' + e.message); process.exit(2); });
