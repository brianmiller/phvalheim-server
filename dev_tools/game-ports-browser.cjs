const {chromium} = require('playwright');
(async () => {
  const browser = await chromium.launch({headless: true});
  const page = await browser.newPage();
  const errors = [];
  page.on('pageerror', e => errors.push(e.message));
  try {
    await page.goto('http://127.0.0.1:8081/');
    await page.evaluate(() => showServerSettingsModal());
    const base = page.locator('#ss-basePort');
    await base.waitFor();
    const managed = process.env.PHV_PORT_UI_MANAGED === '1';
    if (await base.evaluate(e => e.readOnly) !== managed) throw Error('Base Port editability does not match ownership');
    if (await base.inputValue() !== (managed ? '31000' : '26000')) throw Error('Incorrect effective Base Port');
    if (managed) {
      const text = await page.locator('#serverSettingsOverlay').innerText();
      if (!text.includes('Managed by container: UDP 31000–31003') || !text.includes('rangeone (26000)')) throw Error('Managed range or conflict is missing');
      await page.goto('http://127.0.0.1:8081/new_world.php');
      const banner = await page.locator('#managed-game-port-range').innerText();
      if (!banner.includes('rangeone (26000)')) throw Error('Create-world conflict missing');
    }
    if (errors.length) throw Error(errors.join('\n'));
    console.log('PASS: real browser ' + (managed ? 'managed read-only range, conflicts and create page' : 'ordinary editable saved Base Port') + '; no JavaScript errors');
  } finally { await browser.close(); }
})().catch(e => { console.error(e); process.exit(1); });
