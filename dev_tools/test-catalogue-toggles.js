// requires: container
// Browser oracle for saved source filters and responsive toggles on both world pickers.
const { chromium } = require('playwright');
const { execFileSync } = require('child_process');
const BASE = process.argv[2] || 'http://127.0.0.1:8081';
const CONTAINER = process.env.ORACLE_CONTAINER || 'phvalheim-dev';
const WORLD = 'ztest_source_toggle_' + process.pid;
const sql = query => execFileSync('docker', ['exec', CONTAINER, 'mysql', '-N', 'phvalheim', '-e', query], {encoding:'utf8'}).trim();
let passed = 0;
function check(name, ok) { if (!ok) throw new Error(name); console.log('PASS ' + name); passed++; }
(async () => {
 let browser, worldId;
 try {
  sql(`INSERT INTO worlds(name,seed,mode,status,mod_sources) VALUES ('${WORLD}','oracle','stopped','Down','thunderstore')`);
  worldId = Number(sql(`SELECT id FROM worlds WHERE name='${WORLD}'`));
  const selectedId = Number(sql("SELECT id FROM mods WHERE source='thunderstore' AND name='SkyheimExtended' LIMIT 1"));
  check('selected-mod fixture exists', selectedId > 0);
  sql(`INSERT INTO world_mods(world_id,mod_id,is_dep) VALUES (${worldId},${selectedId},0)`);
  browser = await chromium.launch();
  const page = await browser.newPage();
  const errors = [];
  page.on('pageerror', e => errors.push(String(e)));
  for (const kind of ['edit','new']) {
   await page.goto(BASE + (kind === 'edit' ? '/edit_world.php?world=' + WORLD : '/new_world.php'), {waitUntil:'networkidle'});
   await page.waitForFunction(() => jQuery.fn.dataTable.isDataTable('#modtable-all'));
   const mods = await page.evaluate(async () => (await (await fetch('adminAPI.php?action=getAllModsWithDeps')).json()).mods.map(m => [String(m.id), m.source]));
   const sources = ['thunderstore','hexium','vibes'];
   const sourceById = new Map(mods);
   const pressed = source => page.locator(`#modSourceFilter [data-source="${source}"]`).getAttribute('aria-pressed');
   const rows = () => page.evaluate(() => jQuery('#modtable-all').DataTable().rows({search:'applied'}).data().toArray().map(r => ({id:r[0].match(/data-uuid="(\d+)"/)[1], checked:r[0].includes(' checked')})));
   const selectSources = async wanted => {
    // Enable first so the last-source guard never interferes with the requested transition.
    for (const source of wanted) if (await pressed(source) !== 'true') await page.locator(`#modSourceFilter [data-source="${source}"]`).click();
    for (const source of sources.filter(s => !wanted.includes(s))) if (await pressed(source) === 'true') await page.locator(`#modSourceFilter [data-source="${source}"]`).click();
   };
   check(kind + ': saved filter matches button appearance', await pressed('thunderstore') === 'true' && await pressed('hexium') === (kind === 'edit' ? 'false':'true') && await pressed('vibes') === (kind === 'edit' ? 'false':'true'));
   if (kind === 'edit') check('edit: saved filter matches initial rows', (await rows()).every(r => r.checked || sourceById.get(r.id) === 'thunderstore'));
   for (const source of sources) {
    const start = Date.now();
    await selectSources([source]);
    const filtered = await rows();
    check(kind + ': ' + source + ' rows are available', filtered.some(r => sourceById.get(r.id) === source));
    check(kind + ': ' + source + ' hides other unselected sources', filtered.every(r => r.checked || sourceById.get(r.id) === source));
    check(kind + ': ' + source + ' button agrees with filter', await pressed(source) === 'true' && (await page.locator(`#modSourceFilter [data-source="${source}"]`).getAttribute('class')).split(/\s+/).includes('active'));
    if (kind === 'edit') check('edit: selected mod survives ' + source + ' filter', filtered.some(r => r.id === String(selectedId) && r.checked));
    console.log('  filter response: ' + (Date.now()-start) + ' ms');
   }
   await selectSources(sources);
   await page.evaluate(() => jQuery('#modtable-all').DataTable().search('a').order([[1,'desc']]).draw());
   const before = await page.evaluate(() => { const t=jQuery('#modtable-all').DataTable(); return {search:t.search(),order:t.order(),count:t.rows({search:'applied'}).count()}; });
   // A double click must apply twice, without discarding search or ordering.
   await page.locator('#modSourceFilter [data-source="hexium"]').dblclick({delay:30});
   const after = await page.evaluate(() => { const t=jQuery('#modtable-all').DataTable(); return {search:t.search(),order:t.order(),count:t.rows({search:'applied'}).count()}; });
   check(kind + ': rapid toggles apply reliably', await pressed('hexium') === 'true' && after.count === before.count);
   check(kind + ': search and sort survive toggles', after.search === before.search && JSON.stringify(after.order) === JSON.stringify(before.order));
   check(kind + ': filter feedback is visible', (await page.locator('#modSourceFilter .msf-feedback').innerText()).includes('Showing'));
  }
  check('no browser exceptions', errors.length === 0);
  console.log(passed + ' assertions passed');
 } finally {
  if (browser) await browser.close();
  if (worldId) {sql(`DELETE FROM world_mods WHERE world_id=${worldId}`);sql(`DELETE FROM worlds WHERE id=${worldId}`);}
 }
})().catch(e => {console.error(e);process.exitCode=1;});
