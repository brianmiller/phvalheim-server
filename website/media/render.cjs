// Run with Playwright available and ffmpeg on PATH. See README.md.
const {chromium}=require('playwright');
const {execFileSync}=require('child_process');
const path=require('path');
const fs=require('fs');
(async()=>{
 const dir=path.resolve(__dirname,'../docs/assets');
 const tmp=fs.mkdtempSync('/tmp/phv-tour-');
 const browser=await chromium.launch({headless:true});
 const context=await browser.newContext({viewport:{width:720,height:1280},recordVideo:{dir:tmp,size:{width:720,height:1280}}});
 const page=await context.newPage();
 await page.goto('file://'+path.join(__dirname,'tour.html'));
 await page.evaluate(async()=>{await Promise.all([...document.images].map(i=>i.decode()));await document.fonts.ready});
 await page.waitForTimeout(500);
 await page.screenshot({path:path.join(dir,'tour-poster.png')});
 const start=Date.now();await page.evaluate(()=>startTour());
 await page.waitForTimeout(32000);
 const video=page.video();await context.close();await browser.close();
 const input=await video.path();
 // Trim recorder setup from the beginning; preserve exactly 32 seconds of the tour.
 const duration=Number(execFileSync('ffprobe',['-v','error','-show_entries','format=duration','-of','default=nw=1:nk=1',input],{encoding:'utf8'}).trim());
 const offset=Math.max(0,duration-32);
 const wav=path.join(tmp,'music.wav');
 execFileSync('python3',[path.join(__dirname,'music.py'),wav]);
 execFileSync('ffmpeg',['-y','-ss',String(offset),'-i',input,'-i',wav,'-t','32','-vf','fps=30','-c:v','libx264','-preset','medium','-crf','22','-pix_fmt','yuv420p','-c:a','aac','-b:a','128k','-af','loudnorm=I=-18:TP=-2:LRA=7','-movflags','+faststart',path.join(dir,'hugins-vibes-tour.mp4')],{stdio:'ignore'});
 console.log('Rendered 32-second tour:',path.join(dir,'hugins-vibes-tour.mp4'));
})().catch(e=>{console.error(e);process.exitCode=1});
