// requires: container
// Needs an installed Vibes Build Pak and harvested libraries; uses a disposable mod.
const {chromium}=require('playwright');
const fs=require('fs');
const base=process.env.ORACLE_ADMIN || 'http://127.0.0.1:8081';
async function api(action,data={},method='POST'){const r=await fetch(base+'/vibesAPI.php?action='+action+(method==='GET'?'&'+new URLSearchParams(data):''),{method,headers:{'Content-Type':'application/json'},...(method==='POST'?{body:JSON.stringify(data)}:{})});const j=await r.json();if(!r.ok||j.success===false)throw Error(action+': '+JSON.stringify(j));return j;}
async function job(action,data){let j=await api(action,data);for(let i=0;i<180;i++){await new Promise(r=>setTimeout(r,1000));let r=(await api('job',{id:j.jobId},'GET')).job;if(r.status==='done')return r;if(['failed','error','cancelled'].includes(r.status))throw Error(JSON.stringify(r));}throw Error('timeout '+action);}
(async()=>{let p,browser;try{
 p=(await api('createProject',{name:'OracleVibeUx',namespace:'Oracle',description:'Temporary workbench verification',template:'basic-plugin'})).project;
 console.log('created',p.id);
 await job('build',{id:p.id});console.log('PASS real template build');
 browser=await chromium.launch({headless:true});const page=await browser.newPage({viewport:{width:1600,height:1000}});let errors=[];page.on('pageerror',e=>errors.push(String(e)));
 await page.goto(base+'/hugins_vibes.php');await page.waitForFunction(()=>document.body.dataset.vbReady==='1');await page.evaluate(id=>Vibes.workspace.select(id),p.id);
 await page.locator('[data-tab="package"]').click();await page.locator('#vbBtnPackage').click();await page.waitForFunction(()=>document.querySelector('#vbDownloads a'),{timeout:90000});
 console.log('PASS package button creates download links',await page.locator('#vbPackageFeedback').innerText());
 let href=await page.locator('#vbDownloads a').first().getAttribute('href');let zip=await fetch(base+'/'+href);if(zip.status!==200||!(await zip.arrayBuffer()).byteLength)throw Error('download failed');console.log('PASS ZIP download');
 await page.locator('#vbBtnPublish').click();await page.waitForFunction(()=>document.querySelector('#vbBtnPublish').textContent==='Unpublish from Catalogue');console.log('PASS published toggle');
 await page.locator('#vbBtnPublish').click();await page.locator('#vbConfirmOk').click();await page.waitForFunction(()=>document.querySelector('#vbBtnPublish').textContent==='Publish to Catalogue');console.log('PASS unpublish toggle');
 await page.locator('[data-tab="vibe"]').click();await page.locator('#vbModelInput').fill('oracle-model');await page.locator('#vbModelInput').dispatchEvent('change');await page.waitForFunction(()=>document.querySelector('#vbChips').textContent.includes('oracle-model'));console.log('PASS model pill');
 for(const id of ['vbToggleLibrary','vbToggleForge','vbToggleNotes']){await page.locator('#'+id).click();if(await page.locator('#'+id).getAttribute('aria-expanded')!=='false')throw Error('collapse '+id);await page.locator('#'+id).click();}console.log('PASS collapsible panels');
 await page.evaluate(()=>{Vibes.vibeTab.handle({type:'context',tokens:49000,budget:60000},true);Vibes.vibeTab.handle({type:'usage',usage:{in:1234,out:456}},true);for(let i=0;i<25;i++)Vibes.vibeTab.handle({type:'user',text:'Previous message '+i+'\nMore content for scrolling.'},true);Vibes.vibeTab.handle({type:'tool_call',id:'check',name:'read_file',args:{path:'Plugin.cs'}},true);Vibes.vibeTab.handle({type:'tool_result',id:'check',ok:true,preview:'Source read',ms:25},true);});
 await page.waitForTimeout(500);await page.locator('#vbTranscript').hover();await page.mouse.wheel(0,-1500);await page.waitForTimeout(500);let before=await page.locator('#vbTranscript').evaluate(e=>e.scrollTop);await page.evaluate(()=>Vibes.vibeTab.handle({type:'token',text:'New output while reading history'},true));await page.waitForTimeout(200);let after=await page.locator('#vbTranscript').evaluate(e=>e.scrollTop);if(Math.abs(after-before)>20)throw Error('scroll-back jumped');console.log('PASS scroll-back preserved');
 await page.screenshot({path:'/tmp/phv-vibes-desktop.png'});
 await page.setViewportSize({width:390,height:844});await page.screenshot({path:'/tmp/phv-vibes-mobile.png'});if(await page.evaluate(()=>document.documentElement.scrollWidth>innerWidth+1))throw Error('mobile horizontal overflow');console.log('PASS mobile width');
 if(errors.length)throw Error(errors.join('\n'));console.log('PASS no browser errors');
 }finally{if(browser)await browser.close();if(p){try{const state=(await api('project',{id:p.id},'GET')).project;for(const v of state.catalog||[])await job('unpublish',{id:p.id,version:v.version});}catch(e){}await api('deleteProject',{id:p.id,confirm:p.name});}}
})().catch(e=>{console.error(e);process.exitCode=1});
