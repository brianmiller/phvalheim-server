// Browser checks for the built join page. Requires Playwright and a served site.
const {chromium}=require('playwright');
const assert=require('node:assert/strict');
const base=(process.env.DOCS_URL||'http://127.0.0.1:18759/phvalheim-server/').replace(/\/?$/,'/');
const api='https://api.github.com/repos/brianmiller/phvalheim-client/releases/latest';
const repo='https://github.com/brianmiller/phvalheim-client';
const suffixes={windows:'x86_64.msi',macos:'macos-universal.pkg',deb:'x86_64.deb',rpm:'x86_64.rpm',flatpak:'x86_64.flatpak',linux:'universal-x86_64.tar.gz'};
function release(version){return {tag_name:version,draft:false,prerelease:false,assets:Object.values(suffixes).map(s=>{
 const name=`phvalheim-client-${version}-${s}`;return {name,browser_download_url:`${repo}/releases/download/${version}/${name}`};
})}}
(async()=>{
 const browser=await chromium.launch({headless:true});
 const context=await browser.newContext({viewport:{width:1440,height:1000},permissions:['clipboard-read','clipboard-write']});
 const page=await context.newPage();page.setDefaultTimeout(6000);
 const errors=[],bad=[];
 page.on('pageerror',e=>errors.push(String(e)));
 page.on('response',r=>{if(r.url().startsWith(base)&&r.status()>=400)bad.push(r.status()+' '+r.url())});
 let mode='offline';
 await context.route(api,route=>{
   if(mode==='offline')return route.fulfill({status:503,body:'Unavailable'});
   const data=release('2.0.99');
   if(mode==='incomplete')data.assets.pop();
   if(mode==='prerelease')data.prerelease=true;
   return route.fulfill({json:data});
 });
 try {
  await page.goto(base+'play/join/');
  await page.locator('.phv-download').last().waitFor();
  assert.equal(await page.locator('.phv-download').count(),6);
  assert.equal(await page.locator('dialog[open]').count(),0);
  assert.equal(await page.locator('nav[aria-label="On this page"]').getByText('Install on macOS').count(),0);
  assert.ok(await page.locator('.phv-download img').evaluateAll(imgs=>imgs.every(i=>i.complete&&i.naturalWidth>0)));
  for(const [platform,suffix]of Object.entries(suffixes)){
   assert.equal(await page.locator(`.phv-download[data-client-package="${platform}"]`).getAttribute('href'),`${repo}/releases/download/2.0.17/phvalheim-client-2.0.17-${suffix}`);
  }
  console.log('PASS six icon buttons and direct fallback downloads with API unavailable');
  const mac=page.locator('[data-install-dialog="mac-install"]');
  assert.equal(await mac.evaluate(e=>e.dispatchEvent(new MouseEvent('click',{bubbles:true,cancelable:true,ctrlKey:true}))),true,'modified click keeps default link behavior');
  assert.equal(await page.locator('dialog[open]').count(),0);
  await mac.focus();await page.keyboard.press('Enter');
  const dialog=page.getByRole('dialog',{name:'Install on macOS'});
  await dialog.waitFor({state:'visible'});
  await dialog.getByRole('button',{name:'Copy command',exact:true}).click();
  assert.equal(await page.evaluate(()=>navigator.clipboard.readText()),'curl -fsSL https://raw.githubusercontent.com/brianmiller/phvalheim-client/master/macinstall.sh | bash');
  await dialog.getByRole('status').getByText('Command copied. Paste it into Terminal.').waitFor();
  for(let i=0;i<8;i++){
   await page.keyboard.press('Tab');
   // Chromium may focus browser chrome (reported as body) at the tab boundary;
   // links and controls elsewhere in the document must remain inert.
   assert.equal(await page.evaluate(()=>document.activeElement===document.body||!!document.activeElement.closest('#mac-install')),true,'page controls outside modal stay inert');
  }
  await page.screenshot({path:'/tmp/phv-join-macos.png'});
  await page.keyboard.press('Escape');
  assert.equal(await mac.evaluate(e=>document.activeElement===e),true,'focus returns to trigger');
  await mac.click();await dialog.getByRole('button',{name:'Close macOS instructions'}).click();
  assert.equal(await page.locator('dialog[open]').count(),0);
  console.log('PASS macOS keyboard opening, clipboard, focus trap, Escape and close button');
  await page.locator('[data-install-dialog="flatpak-install"]').click();
  const flatpak=page.getByRole('dialog',{name:'Install with Flatpak'});
  await flatpak.getByRole('button',{name:'Copy command',exact:true}).first().click();
  assert.equal(await page.evaluate(()=>navigator.clipboard.readText()),'flatpak install --user ./phvalheim-client-2.0.17-x86_64.flatpak');
  await flatpak.getByText('Using Hyprland, Sway or i3?').click();
  await flatpak.locator('[data-copy-command="flatpak-path-command"]').click();
  assert.match(await page.evaluate(()=>navigator.clipboard.readText()),/exports\/share\\n' "\$HOME" > ~\/\.config\/environment.d\/flatpak.conf$/);
  await page.mouse.click(2,2);
  assert.equal(await page.locator('dialog[open]').count(),0);
  console.log('PASS Flatpak download, exact install and desktop commands, backdrop dismissal');
  await page.evaluate(()=>{navigator.clipboard.writeText=async()=>{throw new Error('Clipboard denied')}});
  await mac.click();await dialog.getByRole('button',{name:'Copy command',exact:true}).click();
  await dialog.getByRole('status').getByText('Select and copy the highlighted command.').waitFor();
  assert.match(await page.evaluate(()=>getSelection().toString()),/^curl -fsSL/);
  await page.keyboard.press('Escape');
  console.log('PASS clipboard denial offers manual copying');
  await page.evaluate(()=>getSelection().removeAllRanges());
  for(const scheme of ['slate','default']){
   await page.evaluate(s=>{document.body.dataset.mdColorScheme=s},scheme);
   for(const width of [1440,768,390,320]){
    await page.setViewportSize({width,height:844});
    assert.equal(await page.evaluate(()=>document.documentElement.scrollWidth>innerWidth+1),false,`page overflow ${width}/${scheme}`);
    await mac.click();
    assert.equal(await dialog.evaluate(e=>e.scrollWidth>e.clientWidth+1),false,`modal overflow ${width}/${scheme}`);
    if(width===390){await page.screenshot({path:`/tmp/phv-join-macos-${scheme}-mobile.png`,animations:'disabled'})}
    await page.keyboard.press('Escape');
    await page.locator('[data-install-dialog="flatpak-install"]').click();
    assert.equal(await flatpak.evaluate(e=>e.scrollWidth>e.clientWidth+1),false,`Flatpak overflow ${width}/${scheme}`);
    await page.keyboard.press('Escape');
   }
  }
  await page.setViewportSize({width:1440,height:1000});
  await page.evaluate(()=>{document.body.dataset.mdColorScheme='slate';document.activeElement.blur();scrollTo(0,0)});
  await page.mouse.move(0,0);
  await page.screenshot({path:'/tmp/phv-join-desktop.png',fullPage:true,animations:'disabled'});
  await page.setViewportSize({width:390,height:844});
  await page.screenshot({path:'/tmp/phv-join-mobile.png',fullPage:true,animations:'disabled'});
  console.log('PASS desktop, tablet and small mobile layouts in both themes');
  mode='latest';await page.reload();
  await page.waitForFunction(()=>document.querySelector('[data-client-version]').textContent==='2.0.99');
  for(const [platform,suffix]of Object.entries(suffixes)){
   for(const href of await page.locator(`[data-client-package="${platform}"]`).evaluateAll(es=>es.map(e=>e.href))){
    assert.equal(href,`${repo}/releases/download/2.0.99/phvalheim-client-2.0.99-${suffix}`);
   }
  }
  assert.equal(await page.locator('#flatpak-command').textContent(),'flatpak install --user ./phvalheim-client-2.0.99-x86_64.flatpak');
  console.log('PASS latest stable assets and install command update together');
  for(mode of ['incomplete','prerelease']){
   await page.reload();await page.waitForLoadState('networkidle');
   assert.equal(await page.locator('[data-client-version]').textContent(),'2.0.17');
   assert.match(await mac.getAttribute('href'),/download\/2\.0\.17\//);
  }
  console.log('PASS incomplete releases and prereleases keep known working downloads');
  const nojs=await browser.newContext({javaScriptEnabled:false});
  const plain=await nojs.newPage();await plain.goto(base+'play/join/');
  assert.equal(await plain.locator('.phv-download:visible').count(),6);
  assert.equal(await plain.locator('noscript').isVisible(),true);
  assert.equal(await plain.locator('dialog:visible').count(),0);
  await nojs.close();
  console.log('PASS downloads and installation guide remain available without JavaScript');
  assert.deepEqual(errors,[]);assert.deepEqual(bad,[]);
  console.log('PASS no page exceptions or failed site resources');
 }finally{await browser.close()}
})().catch(e=>{console.error(e);process.exitCode=1});
