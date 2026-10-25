// Real browser + admin API + detached jobs + Build Pak Git + HTTPS smart Git.
const assert = require('node:assert/strict');
const {execFileSync} = require('node:child_process');
const {chromium} = require('playwright');
const C=process.env.SCM_CONTAINER, base='http://127.0.0.1:'+process.env.SCM_PORT;
let passed=0;
function check(name,fn){fn();passed++;console.log('PASS '+name);}
function dex(args,input){return execFileSync('docker',['exec',...(input?['-i']:[]),C,...args],{input,encoding:'utf8'}).trim();}
function control(d){return JSON.parse(dex(['python3','/phv-fixtures/vibes-scm-server.py',JSON.stringify(d)]));}
async function api(action,data={},method='POST',status=200){
 const r=await fetch(base+'/vibesAPI.php?action='+action+(method==='GET'?'&'+new URLSearchParams(data):''),{method,headers:{'Content-Type':'application/json'},...(method==='POST'?{body:JSON.stringify(data)}:{})});
 const j=await r.json();assert.equal(r.status,status,action+': '+JSON.stringify(j));return j;
}
async function waitJob(id,ok=true){
 for(let i=0;i<360;i++){
  const j=(await api('job',{id},'GET')).job;
  if(!['queued','running'].includes(j.status)){assert.equal(j.status,ok?'done':'failed',JSON.stringify(j));return j;}
  await new Promise(r=>setTimeout(r,250));
 }throw Error('Job timed out '+id);
}
async function job(action,data,ok=true){return waitJob((await api(action,data)).jobId,ok);}
async function project(p){return (await api('project',{id:p.id},'GET')).project;}
function localGit(p,args){
 const code='require "/opt/stateless/nginx/www/includes/vibes.php"; require "/opt/stateless/engine/tools/vibes/lib/git.php"; echo json_encode(vibesGit($argv[1],json_decode($argv[2],true)));';
 const r=JSON.parse(execFileSync('docker',['exec','-u','phvalheim',C,'php','-r',code,p.slug,JSON.stringify(args)],{encoding:'utf8'}));assert.ok(r.ok,JSON.stringify(r));return r.out.trim();
}
function write(p,file,text){dex(['php','-r','file_put_contents($argv[1],$argv[2]);','/opt/stateful/vibes/projects/'+p.slug+'/'+file,text]);dex(['chown','-R','phvalheim:phvalheim','/opt/stateful/vibes/projects/'+p.slug]);}
function commit(p,text){write(p,'local.txt',text);localGit(p,['add','-A']);localGit(p,['commit','-m',text]);return localGit(p,['rev-parse','HEAD']);}
async function counts(p,remote,ahead,behind){const s=(await project(p)).scm;const r=s.remotes.find(x=>x.name===remote);assert.ok(r,JSON.stringify(s));assert.equal(r.ahead,ahead);assert.equal(r.behind,behind);return s;}

(async()=>{
 let browser;
 try{
  const gh=await api('testGitHub',{settings:{}}),gl=await api('testGitLab',{settings:{}});
  check('GitHub and GitLab Settings Test connection authenticates',()=>{assert.equal(gh.login,'fixture');assert.equal(gl.login,'fixture');});
  for(const [which,action] of [['github','testGitHub'],['gitlab','testGitLab']]){
   const rejected=await api(action,{settings:{[which]:{token:'invalid'}}},'POST',502);
   check(which+' bad credential rejected without echoing credential',()=>{assert.match(rejected.error,/rejected/);assert.ok(!JSON.stringify(rejected).includes('invalid'));});
   await api(action,{settings:{[which]:{token:''}}},'POST',400);
   check(which+' empty credential prompts operator to enter token',()=>assert.ok(true));
  }
  browser=await chromium.launch({headless:true});
  const page=await browser.newPage({viewport:{width:1440,height:1000}}), errors=[];
  page.on('pageerror',e=>errors.push(String(e)));
  await page.goto(base+'/hugins_vibes.php');await page.waitForFunction(()=>document.body.dataset.vbReady==='1');
  for(const which of ['github','gitlab']){
   const host=which==='github'?'github.com':'gitlab.fixture.test',path=(which==='github'?'fixture':'team/sub')+'/Imported'+which;
   control({op:'create',which,path});control({op:'seed',which,path});
   await page.locator('#vbBtnImport').click();await page.locator('#vbImportUrl').fill('https://'+host+'/'+path+'.git');
   const queued=page.waitForResponse(r=>r.url().includes('action=importProject')&&r.request().method()==='POST');
   await page.locator('#vbImportSubmit').click();const started=await (await queued).json();assert.ok(started.jobId,JSON.stringify(started));
   const done=await waitJob(started.jobId);const p=(await api('projects',{},'GET')).projects.find(p=>p.name==='Imported'+which);assert.ok(p,JSON.stringify(done));
   await page.waitForFunction(()=>!document.querySelector('#vbImport').classList.contains('show'));
   await page.evaluate(id=>Vibes.workspace.select(id),p.id);await page.locator('[data-tab="history"]').click();
   await page.waitForFunction(()=>document.querySelector('#vbScm').textContent.includes('0 ahead · 0 behind'));
   check(which+' private import completes via modal, jobs and real authenticated clone',()=>assert.ok(p.slug));
   check(which+' original remote/history preserved and metadata excluded',()=>{assert.equal(localGit(p,['remote','get-url','origin']),'https://'+host+'/'+path+'.git');assert.equal(localGit(p,['status','--porcelain']),'');assert.ok(localGit(p,['log','--oneline']).includes('seed'));});
   await counts(p,'origin',0,0);
   control({op:'advance',which,path,text:'Remote revision 1'});
   await page.locator('#vbScm button').filter({hasText:'Check remote'}).click();
   await page.waitForFunction(()=>document.querySelector('#vbScm').textContent.includes('0 ahead · 1 behind'));
   check(which+' browser Fetch reports behind without changing worktree',()=>assert.ok(localGit(p,['log','-1','--pretty=%s']).includes('seed')));
   await page.locator('#vbScm button').filter({hasText:'Pull (fast-forward)'}).click();
   await page.waitForFunction(()=>document.querySelector('#vbScm').textContent.includes('0 ahead · 0 behind'));
   check(which+' browser fast-forward Pull updates local contents',()=>assert.equal(localGit(p,['show','HEAD:remote.txt']),'Remote revision 1'));
   const newHead=commit(p,'Local revision 1');await counts(p,'origin',1,0);
   await page.evaluate(()=>Vibes.workspace.reload());await page.locator('#vbScm button').filter({hasText:'Push branch'}).click();
   await page.waitForFunction(()=>document.querySelector('#vbScm').textContent.includes('0 ahead · 0 behind'));
   check(which+' browser Push transfers new commit upstream',()=>assert.equal(control({op:'inspect',which,path}).head,newHead));
   write(p,'local.txt','Dirty file');const dirty=await job('scmSync',{id:p.id,remote:'origin',operation:'pull'},false);
   check(which+' dirty pull rejected without losing changes',()=>{assert.match(dirty.error,/Commit your local changes/);assert.equal(localGit(p,['diff','--name-only']),'local.txt');});
   localGit(p,['checkout','--','local.txt']);
   const localHead=commit(p,'Diverged local');control({op:'advance',which,path,text:'Diverged remote'});
   const remoteHead=control({op:'inspect',which,path}).head;
   const pull=await job('scmSync',{id:p.id,remote:'origin',operation:'pull'},false);
   check(which+' divergent pull rejected and both histories retained',()=>{assert.match(pull.error,/fast-forward/);assert.equal(localGit(p,['rev-parse','HEAD']),localHead);assert.equal(control({op:'inspect',which,path}).head,remoteHead);});
   const push=await job('scmSync',{id:p.id,remote:'origin',operation:'push'},false);
   check(which+' divergent push refused with no force or remote overwrite',()=>{assert.match(push.error,/Push rejected/);assert.equal(control({op:'inspect',which,path}).head,remoteHead);});
   await counts(p,'origin',1,1);
   localGit(p,['checkout','--detach']);const detached=await job('scmSync',{id:p.id,remote:'origin',operation:'fetch'},false);
   check(which+' detached HEAD gets actionable error',()=>assert.match(detached.error,/Check out a branch/));
   localGit(p,['checkout','main']);
  }
  for(const which of ['github','gitlab']){
   const p=(await api('createProject',{name:'Created'+which,namespace:'Fixture',description:'SCM E2E',template:'basic-plugin'})).project;
   localGit(p,['tag','-a','v0.1.0','-m','Fixture release']);
   const j=await job('push',{id:p.id,remote:which});
   check(which+' initial Push creates repository and pushes template',()=>{assert.equal(j.status,'done');assert.equal(control({op:'inspect',which,path:(which==='github'?'fixture':'team/sub')+'/'+p.name}).head,localGit(p,['rev-parse','HEAD']));});
   await job('push',{id:p.id,remote:which});check(which+' repeated initial Push reuses repository',()=>assert.ok(true));
   check(which+' annotated tags pushed with initial publish',()=>assert.match(control({op:'inspect',which,path:(which==='github'?'fixture':'team/sub')+'/'+p.name}).refs,/refs\/tags\/v0.1.0/));
  }
  await api('saveSettings',{settings:{author:{namespace:'ImportedFixture'}}});
  await job('importProject',{url:'https://github.com/fixture/Createdgithub'});
  const imported=(await api('projects',{},'GET')).projects.find(p=>p.name==='Createdgithub'&&p.namespace==='ImportedFixture');
  const originalTemplate=(await api('projects',{},'GET')).projects.find(p=>p.name==='Createdgithub'&&p.namespace==='Fixture');
  const source=localGit(originalTemplate,['show','HEAD:src/Plugin.cs']);
  check('imported mod is not built automatically and retains original plugin source/GUID',()=>{assert.equal(imported.last_build_ok,null);assert.equal(localGit(imported,['show','HEAD:src/Plugin.cs']),source);});
  await job('build',{id:imported.id});
  const built=await project(imported);
  check('imported real template compiles with the Build Pak and harvested libraries',()=>assert.equal(built.lastBuild.ok,true));
  await job('package',{id:imported.id});
  const packaged=await project(imported),archive=packaged.packages[0];assert.ok(archive);
  const download=await fetch(base+'/vibesAPI.php?action=downloadPackage&id='+imported.id+'&file='+encodeURIComponent(archive.name));
  assert.equal(download.status,200);const zip=Buffer.from(await download.arrayBuffer());
  check('imported compiled mod packages and downloads as a ZIP',()=>{assert.equal(zip.subarray(0,2).toString(),'PK');assert.ok(zip.length>100);});
  await api('saveSettings',{settings:{author:{namespace:'Fixture'}}});
  await api('saveSettings',{settings:{github:{owner:'FixtureOrg'}}});
  const org=(await api('createProject',{name:'OrganizationMod',namespace:'Fixture',template:'basic-plugin'})).project;
  await job('push',{id:org.id,remote:'github'});
  check('GitHub organization repository creation uses configured owner',()=>assert.equal(control({op:'inspect',which:'github',path:'FixtureOrg/'+org.name}).head,localGit(org,['rev-parse','HEAD'])));
  await api('saveSettings',{settings:{github:{owner:'fixture'},gitlab:{group:''}}});
  const personal=(await api('createProject',{name:'PersonalMod',namespace:'Fixture',template:'basic-plugin'})).project;
  await job('push',{id:personal.id,remote:'gitlab'});
  check('GitLab personal repository creation works without group',()=>assert.equal(control({op:'inspect',which:'gitlab',path:'fixture/'+personal.name}).head,localGit(personal,['rev-parse','HEAD'])));
  await api('saveSettings',{settings:{gitlab:{group:'team/sub'}}});
  for(const [name,opts] of [['Symlink',{symlink:true}],['Metadata',{metadata:true}],['NotMain',{branch:'master'}]]){
   const path='fixture/'+name;control({op:'create',path});control({op:'seed',path,...opts});
   const j=await job('importProject',{url:'https://github.com/'+path},name!=='Symlink');
   if(name==='Symlink'){const rows=(await api('projects',{},'GET')).projects;check('symlink import fails without registering project',()=>{assert.match(j.error,/symlinks/);assert.ok(!rows.some(p=>p.name===name));});continue;}
   const p=(await api('projects',{},'GET')).projects.find(p=>p.name===name);assert.ok(p);
   check(name+' imported metadata/history handled',()=>{assert.equal(localGit(p,['branch','--show-current']),opts.branch||'main');if(name==='Metadata'){const notes=dex(['cat','/opt/stateful/vibes/projects/'+p.slug+'/.vibes/notes.md']);assert.ok(notes.includes('Imported from'));assert.ok(!notes.includes('Untrusted upstream metadata'));assert.equal(localGit(p,['show','HEAD:.vibes/notes.md']),'Untrusted upstream metadata');}});
   if(name==='NotMain'){
    commit(p,'Update master branch');
    await job('scmSync',{id:p.id,remote:'origin',operation:'push'});
    check('non-main imported current branch push succeeds',()=>assert.equal(control({op:'inspect',path,ref:'master'}).head,localGit(p,['rev-parse','HEAD'])));
    await job('push',{id:p.id,remote:'gitlab'});
    check('initial publish of imported non-main branch preserves branch name',()=>assert.equal(control({op:'inspect',which:'gitlab',path:'team/sub/'+name,ref:'master'}).head,localGit(p,['rev-parse','HEAD'])));
   }
  }
  for(const url of ['file:///etc/passwd','http://github.com/a/b','https://user:password@github.com/a/b','https://github.com/a/b/tree/main','https://github.com/a/b?x=1','https://evil.invalid/a/b']){
   await api('importProject',{url},'POST',400);check('invalid import rejected before job: '+url.replace('user:password@','[userinfo]@'),()=>assert.ok(true));
  }
  const missing=await job('importProject',{url:'https://github.com/fixture/DoesNotExist'},false);
  check('missing repository gives clear failed job',()=>assert.match(missing.error,/Clone failed/));
  const credentials=JSON.parse(dex(['cat','/tmp/vibes-scm-fixture/tokens.json']));
  control({op:'create',path:'fixture/PublicSeed'});control({op:'seed',path:'fixture/PublicSeed'});
  await api('saveSettings',{settings:{github:{token:''}}});
  await job('importProject',{url:'https://github.com/fixture/PublicSeed'});
  check('public repository imports without a token',()=>assert.ok(true));
  const privateWithoutToken=await job('importProject',{url:'https://github.com/fixture/Importedgithub'},false);
  check('private repository without token fails with authentication guidance',()=>assert.match(privateWithoutToken.error,/token|private/i));
  await api('saveSettings',{settings:{github:{token:credentials.github}}});
  const beforeDuplicate=(await api('projects',{},'GET')).projects;
  const original=beforeDuplicate.find(p=>p.name==='Importedgithub'),originalHead=localGit(original,['rev-parse','HEAD']);
  const duplicate=await job('importProject',{url:'https://github.com/fixture/Importedgithub'},false);
  check('duplicate import preserves existing project and leaves no extra row',()=>{assert.match(duplicate.error,/duplicate|already exists/i);assert.equal(localGit(original,['rev-parse','HEAD']),originalHead);});
  const afterDuplicate=(await api('projects',{},'GET')).projects;
  check('duplicate import cleans partial workspace',()=>assert.equal(afterDuplicate.length,beforeDuplicate.length));
  const locked=afterDuplicate.find(p=>p.name==='Createdgithub'&&p.namespace==='Fixture');
  await page.evaluate(id=>Vibes.workspace.select(id),locked.id);
  control({op:'delay',path:'fixture/'+locked.name,seconds:2});
  const pending=await api('scmSync',{id:locked.id,remote:'github',operation:'fetch'});
  const busy=await api('scmSync',{id:locked.id,remote:'github',operation:'push'},'POST',409);
  check('overlapping SCM jobs for a Vibe are refused',()=>assert.match(busy.error,/already|busy/));
  await page.reload();await page.waitForFunction(()=>document.body.dataset.vbReady==='1');
  await waitJob(pending.jobId);
  await page.evaluate(id=>Vibes.workspace.select(id),locked.id);
  await page.locator('[data-tab="history"]').click();
  await page.waitForFunction(()=>document.querySelector('#vbScm').textContent.includes('0 ahead · 0 behind'));
  check('SCM job survives browser reload and restores synchronized status',()=>assert.ok(true));
  control({op:'delay',path:'fixture/'+locked.name,seconds:0});
  const journal=control({op:'journal'});
  check('real smart HTTP upload-pack and receive-pack used for both providers',()=>{for(const which of ['github','gitlab'])for(const op of ['git-upload-pack','git-receive-pack'])assert.ok(journal.some(r=>r.which===which&&r.path.endsWith(op)&&r.authorized));});
  const masked=await api('settings',{},'GET');
  const tokens=JSON.parse(dex(['cat','/tmp/vibes-scm-fixture/tokens.json']));
  const projects=(await api('projects',{},'GET')).projects;
  for(const p of projects){const config=localGit(p,['config','--local','--list']);for(const token of Object.values(tokens))assert.ok(!config.includes(token));}
  check('tokens masked in settings and absent from every git config',()=>{for(const token of Object.values(tokens))assert.ok(!JSON.stringify(masked).includes(token));});
  const logs=dex(['sh','-c','cat /opt/stateful/vibes/logs/jobs/*.jsonl /opt/stateful/vibes/logs/vibes.log']);
  check('credentials absent from job events and runner logs',()=>{for(const [which,token] of Object.entries(tokens)){assert.ok(!logs.includes(token));assert.ok(!logs.includes(Buffer.from((which==='github'?'x-access-token':'oauth2')+':'+token).toString('base64')));}});
  assert.equal(dex(['sh','-c','find /opt/stateful/vibes/downloads -maxdepth 1 -name "import-*" | wc -l']),'0');
  check('successful and failed imports leave no temporary checkout',()=>assert.ok(true));
  await page.setViewportSize({width:390,height:844});
  await page.waitForTimeout(300);
  assert.equal(await page.evaluate(()=>document.documentElement.scrollWidth>innerWidth+1),false);
  check('mobile History SCM controls fit the viewport',()=>assert.ok(true));
  check('browser has no JavaScript exceptions',()=>assert.deepEqual(errors,[]));
  console.log('SCM E2E: '+passed+' assertions passed');
 } finally {if(browser)await browser.close();}
})().catch(e=>{console.error(e);process.exitCode=1;});
