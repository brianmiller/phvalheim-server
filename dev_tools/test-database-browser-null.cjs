// Issue #92: drive the real grid and SQL, using a disposable copy of the world schema.
// requires: container
// Requires a running test container/admin endpoint and Playwright in NODE_PATH.
// PHV_CONTAINER=... PHV_ADMIN_URL=http://127.0.0.1:8081 node dev_tools/test-database-browser-null.cjs
const assert = require('node:assert/strict');
const {execFileSync} = require('node:child_process');
const {chromium} = require('playwright');
const container = process.env.PHV_CONTAINER || 'phvalheim-dev';
const base = process.env.PHV_ADMIN_URL || 'http://127.0.0.1:8081';
const table = `phv_grid_null_${process.pid}`;
function sql(query) {
    return execFileSync('docker', ['exec', container, 'mysql', '-uroot', '-N', 'phvalheim', '-e', query], {encoding:'utf8'}).trim();
}
let checks = 0;
function check(label, actual, expected) {
    assert.deepEqual(actual, expected, label);
    console.log(`PASS ${label}`); checks++;
}
(async () => {
    let browser;
    try {
        check('strict SQL mode is enabled', sql('SELECT @@sql_mode').includes('STRICT_TRANS_TABLES'), true);
        sql(`CREATE TABLE ${table} LIKE worlds`);
        sql(`ALTER TABLE ${table} ADD required_date DATETIME NOT NULL DEFAULT '2026-10-08 12:00:00', ADD optional_date DATE NULL, ADD optional_time TIME NULL, ADD optional_stamp TIMESTAMP NULL, ADD optional_number DECIMAL(8,2) NULL`);
        sql(`INSERT INTO ${table} (name,mode,trophytheelder,date_updated,port) VALUES ('GridFixture','stopped',0,'2026-10-08 12:00:00',25000),('GridFixture2','stopped',0,'2026-10-08 12:00:00',25002)`);
        const fields = sql(`SHOW COLUMNS FROM ${table}`).split('\n').map(line => line.split('\t')[0]);
        browser = await chromium.launch({headless:true});
        const page = await browser.newPage();
        await page.goto(`${base}/gridphp/`, {waitUntil:'domcontentloaded'});
        await page.evaluate(({table, fields}) => {
            const form = document.forms[0];
            form.querySelector('[name=tables]').value = table;
            const select = form.querySelector('[name="fields[]"]');
            select.innerHTML = '';
            fields.forEach(name => select.add(new Option(name,name,true,true)));
            form.submit();
        }, {table, fields});
        const grid = `#list1_${table}`;
        await page.waitForSelector(`${grid} tr.jqgrow`);
        async function gridCall(method, ...args) {
            return page.evaluate(({grid,method,args}) => jQuery(grid).jqGrid(method,...args) && true, {grid,method,args});
        }
        async function inlineSave(values) {
            await gridCall('editRow','1',true);
            for (const [field,value] of Object.entries(values)) await page.locator(`input.editable[name="${field}"]`).fill(value);
            const response = page.waitForResponse(r => r.request().method()==='POST' && r.url().includes('grid_id='));
            await gridCall('saveRow','1');
            const r = await response;
            assert.equal(r.status(),200,await r.text());
            await page.waitForFunction(grid => !document.querySelector(`${grid} input.editable`),grid);
        }
        await inlineSave({trophytheelder:'1'});
        check('full-row trophy edit succeeds with blank timestamps/numbers/JSON', sql(`SELECT trophytheelder,update_phase_at IS NULL,player_count_at IS NULL,modsViewer IS NULL FROM ${table} WHERE id=1`), '1\t1\t1\t1');
        check('existing timestamp is retained',sql(`SELECT date_updated FROM ${table} WHERE id=1`),'2026-10-08 12:00:00');
        await inlineSave({trophytheelder:'0',port:'0',optional_number:'0',update_phase_at:'2026-10-09 13:14:15',ip:''});
        check('numeric zero is retained, not converted to NULL',sql(`SELECT trophytheelder,port,optional_number FROM ${table} WHERE id=1`),'0\t0\t0.00');
        check('valid timestamp is stored',sql(`SELECT update_phase_at FROM ${table} WHERE id=1`),'2026-10-09 13:14:15');
        check('empty text remains an empty string',sql(`SELECT ip IS NOT NULL, LENGTH(ip) FROM ${table} WHERE id=1`),'1\t0');
        await inlineSave({update_phase_at:'',optional_number:'',optional_date:'',optional_time:'',optional_stamp:''});
        check('nullable typed fields can be cleared',sql(`SELECT update_phase_at IS NULL,optional_number IS NULL,optional_date IS NULL,optional_time IS NULL,optional_stamp IS NULL FROM ${table} WHERE id=1`),'1\t1\t1\t1\t1');
        // The dialog uses a different jqGrid submission path from inline editing.
        await gridCall('editGridRow','1',{closeAfterEdit:true});
        await page.locator('#FrmGrid_list1_'+table+' [name=trophytheelder]').fill('1');
        const dialogResponse = page.waitForResponse(r => r.request().method()==='POST' && r.url().includes('grid_id='));
        await page.locator('#sData').click();
        const dialogResult = await dialogResponse;
        check('dialog save succeeds',dialogResult.status(),200);
        check('dialog trophy edit persisted',sql(`SELECT trophytheelder,update_phase_at IS NULL FROM ${table} WHERE id=1`),'1\t1');
        // Bulk and invalid submissions through the same session/real CRUD endpoint.
        const url = await page.evaluate(grid => jQuery(grid).jqGrid('getGridParam','editurl'),grid);
        const post = form => page.request.post(new URL(url,base+'/gridphp/').href,{form});
        let result = await post({oper:'edit',id:'1,2',trophytheelder:'0',update_phase_at:''});
        check('bulk save succeeds',result.status(),200);
        check('bulk saves NULL and zero for both rows',sql(`SELECT COUNT(*) FROM ${table} WHERE trophytheelder=0 AND update_phase_at IS NULL`),'2');
        result = await post({oper:'edit',id:'1',update_phase_at:'not-a-date'});
        check('invalid nonempty datetime still rejected',result.status(),500);
        check('invalid datetime reports the field',(await result.text()).includes('update_phase_at'),true);
        result = await post({oper:'edit',id:'1',required_date:''});
        check('NOT NULL datetime is not silently cleared',result.status(),500);
        check('required timestamp remains unchanged',sql(`SELECT required_date FROM ${table} WHERE id=1`),'2026-10-08 12:00:00');
        console.log(`${checks} checks passed`);
    } finally {
        if (browser) await browser.close();
        sql(`DROP TABLE IF EXISTS ${table}`);
    }
})().catch(error => {console.error(error); process.exitCode=1;});
