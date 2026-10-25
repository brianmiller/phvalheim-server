// Smoke-check the generated site. Run with Playwright available through NODE_PATH.
const {chromium}=require('playwright');
const assert=require('node:assert/strict');
const base=(process.env.DOCS_URL||'http://127.0.0.1:18759/phvalheim-server/').replace(/\/?$/,'/');
(async()=>{
 const b=await chromium.launch({headless:true});
 const context=await b.newContext({viewport:{width:1440,height:1000},permissions:['clipboard-read','clipboard-write']});
 const p=await context.newPage();const errors=[];const bad=[];
 p.on('pageerror',e=>errors.push(String(e)));
 p.on('response',r=>{if(r.url().startsWith(base)&&r.status()>=400)bad.push(r.status()+' '+r.url())});
 try {
 await p.goto(base);await p.getByRole('heading',{name:'More adventures. Less admin.'}).waitFor();
 await p.locator('.md-search__button').click();
 await p.getByRole('combobox',{name:'Search'}).fill('compaction');
 await p.getByText('Long conversations',{exact:true}).click({timeout:15000});
 await p.waitForURL(u=>u.pathname.endsWith('/vibes/setup/')&&u.hash==='#long-conversations');
 console.log('PASS search returns relevant indexed guide and section');
 await p.keyboard.press('Escape');
 await p.goto(base+'start/install/');
 await p.getByRole('button',{name:'Copy to clipboard'}).first().click();
 const clip=await p.evaluate(()=>navigator.clipboard.readText());assert.match(clip,/127\.0\.0\.1:8081:8081/);
 console.log('PASS Compose copy includes private admin binding');
 await p.locator('label[for="__palette_1"]').click();
 await p.waitForFunction(()=>document.body.dataset.mdColorScheme==='default');
 const contrast=await p.locator('.md-header__title').evaluate(e=>{
   const rgb=s=>s.match(/[\d.]+/g).slice(0,3).map(Number).map(v=>{v/=255;return v<=.04045?v/12.92:((v+.055)/1.055)**2.4});
   const lum=a=>a[0]*.2126+a[1]*.7152+a[2]*.0722;
   const a=lum(rgb(getComputedStyle(e).color)),b=lum(rgb(getComputedStyle(document.querySelector('.md-header')).backgroundColor));
   return (Math.max(a,b)+.05)/(Math.min(a,b)+.05);
 });
 assert.ok(contrast>=4.5,'header contrast '+contrast);
 console.log('PASS theme switch and readable light-mode navigation');
 await p.goto(base+'start/tour/');
 await p.locator('video').evaluate(async v=>{v.muted=true;await v.play()});
 await p.waitForFunction(()=>document.querySelector('video').currentTime>0);
 const media=await p.locator('video').evaluate(v=>({width:v.videoWidth,height:v.videoHeight,duration:v.duration,tracks:v.textTracks.length}));
 assert.equal(media.width,720);assert.equal(media.height,1280);assert.ok(media.duration>=59.9&&media.duration<=60.1);assert.equal(media.tracks,1);
 await p.waitForFunction(()=>document.querySelector('video').textTracks[0].cues?.length===12);
 const credits=await context.request.get(base+'assets/phvalheim-tour-credits.txt');
 assert.equal(credits.status(),200);assert.match(await credits.text(),/Alexander Nakarada.*CC BY 4\.0/);
 await p.locator('video').evaluate(v=>{v.pause();v.currentTime=18});await p.waitForTimeout(350);
 await p.screenshot({path:'/tmp/phv-docs-tour-preview.png'});
 console.log('PASS video playback, portrait format, duration and captions');
 await p.goto(base+'vibes/tour/');
 await p.getByRole('link',{name:'Watch the one-minute PhValheim tour →'}).click();
 await p.waitForURL(base+'start/tour/');
 console.log('PASS old tour link leads to the full product tour; music credit is downloadable');
 await p.setViewportSize({width:390,height:844});
 for(const route of ['', 'start/install/','host/backups/','vibes/first-mod/','vibes/source-control/','start/tour/']){
   await p.goto(base+route);assert.equal(await p.evaluate(()=>document.documentElement.scrollWidth>innerWidth+1),false,'mobile overflow '+route);
 }
 await p.locator('.md-header label[for="__drawer"]').click();assert.equal(await p.locator('#__drawer').isChecked(),true);
 console.log('PASS mobile navigation and six mobile layouts');
 await p.goto(base);await p.screenshot({path:'/tmp/phv-docs-mobile-final.png',fullPage:true});
 await p.setViewportSize({width:1440,height:1000});await p.screenshot({path:'/tmp/phv-docs-desktop-final.png',fullPage:true});
 assert.deepEqual(errors,[]);assert.deepEqual(bad,[]);
 console.log('PASS no browser exceptions or failed local resources');
 }finally{await b.close()}
})().catch(e=>{console.error(e);process.exitCode=1});
