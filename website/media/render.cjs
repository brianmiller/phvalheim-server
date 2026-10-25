// Reproducible video composition; see README.md and audio/ATTRIBUTION.md.
const {chromium}=require('playwright');
const {execFileSync}=require('child_process');
const path=require('path');
const fs=require('fs');
const scenes=require('./scenes.json');
const seconds=scenes.length*5;
(async()=>{
 const dir=path.resolve(__dirname,'../docs/assets');
 const tmp=fs.mkdtempSync('/tmp/phv-tour-');
 const browser=await chromium.launch({headless:true});
 try {
 const context=await browser.newContext({viewport:{width:720,height:1280},recordVideo:{dir:tmp,size:{width:720,height:1280}}});
 const page=await context.newPage();await page.goto('file://'+path.join(__dirname,'tour.html'));
 await page.evaluate(data=>initTour(data),scenes);
 await page.evaluate(async()=>{await Promise.all([...document.images].map(i=>i.decode()));await document.fonts.ready});
 await page.waitForTimeout(700);
 // Check every chapter's readable content stays above the footer.
 for(let i=0;i<scenes.length;i++){
  await page.evaluate(i=>setTime(i*5+2),i);await page.waitForTimeout(600);
  const overflow=await page.locator('.scene.active').evaluate(e=>[...e.querySelectorAll('.note,.grid,.stack,.url,.credit,.shot,.tagrow')].some(c=>c.getBoundingClientRect().bottom>1160));
  if(overflow)throw Error('Chapter overflows safe area: '+scenes[i].kind);
  await page.screenshot({path:path.join(tmp,`scene-${i}.png`)});
 }
 await page.evaluate(()=>setTime(0));await page.waitForTimeout(700);
 await page.screenshot({path:path.join(dir,'tour-poster.png')});
 await page.evaluate(()=>startTour());await page.waitForTimeout(seconds*1000);
 const video=page.video();await context.close();const input=await video.path();
 const duration=Number(execFileSync('ffprobe',['-v','error','-show_entries','format=duration','-of','default=nw=1:nk=1',input],{encoding:'utf8'}).trim());
 const offset=Math.max(0,duration-seconds);
 const credit='Music: Viking Intro (Loop) by Alexander Nakarada (https://creatorchords.com). CC BY 4.0 https://creativecommons.org/licenses/by/4.0/. Excerpt, normalized and faded.';
 execFileSync('ffmpeg',['-y','-ss',String(offset),'-i',input,'-i',path.join(__dirname,'audio/viking-intro-loop.mp3'),'-t',String(seconds),'-vf','fps=30','-c:v','libx264','-preset','medium','-crf','21','-pix_fmt','yuv420p','-c:a','aac','-b:a','192k','-ar','48000','-ac','2','-af',`afade=t=in:d=0.15,afade=t=out:st=${seconds-2}:d=2,loudnorm=I=-16:TP=-1.5:LRA=9`,'-metadata','title=PhValheim — Host. Mod. Sync. Play.','-metadata','comment='+credit,'-movflags','+faststart',path.join(dir,'phvalheim-tour.mp4')],{stdio:'ignore'});
 const stamp=s=>`00:${String(Math.floor(s/60)).padStart(2,'0')}:${String(s%60).padStart(2,'0')}.000`;
 fs.writeFileSync(path.join(dir,'phvalheim-tour.vtt'),'WEBVTT\n\n'+scenes.map((s,i)=>`${stamp(i*5)} --> ${stamp((i+1)*5)}\n${s.caption}\n`).join('\n'));
 fs.writeFileSync(path.join(dir,'phvalheim-tour-credits.txt'),'PhValheim — Host. Mod. Sync. Play.\nhttps://brianmiller.github.io/phvalheim-server/start/tour/\n\n'+credit+'\nSource: https://creatorchords.com/music/viking-intro-loop/\n\nInclude the music credit in the description wherever you repost this video.\n');
 console.log(`Rendered ${seconds}-second tour. Preview frames and raw recording: ${tmp}`);
 } finally {await browser.close()}
})().catch(e=>{console.error(e);process.exitCode=1});
