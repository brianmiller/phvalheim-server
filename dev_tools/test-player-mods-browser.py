#!/usr/bin/env python3
# requires: container (isolated PHV_PRESENTATION_FIXTURE)
"""Real HTTP/DB and browser contract for the player library and personal editor.
Use only a disposable presentation fixture; patch-source is for prebuild testing.
"""
import json,os,subprocess,tempfile,base64,sys
from pathlib import Path
from playwright.sync_api import sync_playwright,expect
from fixture_analytics import assert_isolated
root=Path(__file__).resolve().parent.parent
f=json.loads(Path(os.environ['PHV_PRESENTATION_FIXTURE']).read_text());name=f['name']
assert_isolated(json.loads(subprocess.check_output(['docker','inspect',name]))[0])
def run(*args):return subprocess.check_output(args,text=True)
def sql(text):return run('docker','exec',name,'mysql','-N','-uphvalheim_user','-pphvalheim_secretpassword','phvalheim','-e',text)
def put(path,text):
 with tempfile.NamedTemporaryFile(mode='w') as t:
  t.write(text);t.flush();os.chmod(t.name,0o644);run('docker','cp',t.name,name+':'+path)
if '--patch-source' in sys.argv:
 for rel in ['includes/modcatalog.php','includes/player_mods.php','includes/player_config.php','includes/db_gets.php','public/api.php','css/player-mods.css','js/player-mods.js']:
  run('docker','cp',str(root/'container/nginx/www'/rel),name+':/opt/stateless/nginx/www/'+rel)
  run('docker','exec',name,'chmod','644','/opt/stateless/nginx/www/'+rel)
 auth=(root/'container/nginx/www/public/authenticated.php').read_text();a=auth.index('$steamJSON = file_get_contents(');b=auth.index(';',a)
 auth=auth[:a]+"$steamJSON = '{\"response\":{\"players\":[{\"personaname\":\"Test Viking\",\"avatarmedium\":\"\"}]}}'"+auth[b:]
 put('/opt/stateless/nginx/www/public/authenticated.php',auth)
 run('docker','cp',str(root/'container/engine/dbUpdates/dbUpdate_2.62.sh'),name+':/opt/stateless/engine/dbUpdates/dbUpdate_2.62.sh')
 run('docker','exec',name,'bash','/opt/stateless/engine/dbUpdates/dbUpdate_2.62.sh')
# Fixture-only identities to exercise real session isolation without Steam.
session=run('docker','exec',name,'cat','/opt/stateless/nginx/www/includes/session_auth.php')
if 'HTTP_X_FIXTURE_PLAYER' not in session:session=session.replace('function phvDevSteamID() {',"function phvDevSteamID() {\n if(isset($_SERVER['HTTP_X_FIXTURE_PLAYER']))return preg_match('/^[0-9]{17}$/',$_SERVER['HTTP_X_FIXTURE_PLAYER']) ? $_SERVER['HTTP_X_FIXTURE_PLAYER'] : null;")
put('/opt/stateless/nginx/www/includes/session_auth.php',session)
sql("""INSERT IGNORE INTO mods (id,source,owner,name,full_name,package_url,latest_version) VALUES
(990001,'thunderstore','FixtureAuthor','SmoothCamera','FixtureAuthor-SmoothCamera','https://example.invalid/camera','2.0.0'),
(990002,'thunderstore','FixtureAuthor','ServerHelper','FixtureAuthor-ServerHelper','javascript:alert(1)','1.0.0');
INSERT IGNORE INTO mod_versions (id,mod_id,version,full_name,download_url,description,source_rank) VALUES
(990001,990001,'1.0.0','FixtureAuthor-SmoothCamera-1.0.0','https://example.invalid/a.zip','Customize camera distance and motion. <img src=x onerror=alert(1)>',1),
(990002,990001,'2.0.0','FixtureAuthor-SmoothCamera-2.0.0','https://example.invalid/b.zip','New camera release',0),
(990003,990002,'1.0.0','FixtureAuthor-ServerHelper-1.0.0','https://example.invalid/c.zip','Server helper',0);
INSERT IGNORE INTO world_mods (world_id,mod_id,deploy_server,deploy_client,installed_version_id,installed_at) SELECT id,990001,1,1,990001,NOW() FROM worlds WHERE name='Midgard';
INSERT IGNORE INTO world_mods (world_id,mod_id,deploy_server,deploy_client,is_dep) SELECT id,990002,1,0,1 FROM worlds WHERE name='Midgard';
INSERT IGNORE INTO world_mod_config_policy (world_id,mod_id,push_client) SELECT id,990001,1 FROM worlds WHERE name='Midgard';
INSERT IGNORE INTO mod_plugin_guids (mod_id,guid,learned,manual) VALUES (990001,'fixture.camera',1,0);
INSERT IGNORE INTO worlds (name,port,public,mode,status) VALUES ('PrivateLibrary',2470,0,'stopped','ready');
DELETE FROM player_mod_configs;
DELETE o FROM mod_config_overrides o JOIN worlds w ON w.id=o.world_id WHERE w.name='Midgard' AND o.mod_id=990001 AND o.ckey='Distance';
""")
base='/opt/stateful/games/valheim/worlds/Midgard'
run('docker','exec',name,'mkdir','-p',base+'/game/BepInEx/config',base+'/client/BepInEx/config')
cfg='''## Settings file was created by plugin SmoothCamera v1.0.0
## Plugin GUID: fixture.camera
[Camera]
## Distance of the camera from your character.
# Setting type: Single
# Default value: 4
# Acceptable value range: From 1 to 12
Distance = 8
## Smooth motion.
# Setting type: Boolean
# Default value: true
Smooth = true
## Enforced setting.
# Setting type: Int32
# Default value: 2
Locked = 2
## Private key.
# Setting type: String
# Default value: default-private
Private = RAW-SERVER-SECRET
RawUndocumented = RAW-UNDOCUMENTED-SECRET
'''
put(base+'/game/BepInEx/config/fixture.camera.cfg',cfg)
sql("""INSERT INTO mod_config_overrides (world_id,mod_id,cfg_file,section,ckey,cvalue,server_only,overridable,locked,origin)
SELECT id,990001,'fixture.camera.cfg','Camera','Private','PRIVATE-OVERRIDE',1,1,0,'ui' FROM worlds WHERE name='Midgard'
ON DUPLICATE KEY UPDATE server_only=1;
INSERT INTO mod_config_overrides (world_id,mod_id,cfg_file,section,ckey,cvalue,server_only,overridable,locked,origin)
SELECT id,990001,'fixture.camera.cfg','Camera','Locked','2',0,0,0,'ui' FROM worlds WHERE name='Midgard'
ON DUPLICATE KEY UPDATE overridable=0;
""")
policy={'version':1,'minClientVersion':'2.0.17','files':[{'file':'fixture.camera.cfg','entries':[{'section':'Camera','key':k,'value':v,'overridable':o} for k,v,o in [('Distance','4',True),('Smooth','true',True),('Locked','2',False),('Private','STALE-PRIVATE',True)]]}],'managedFiles':[]}
put(base+'/client/BepInEx/config/.phvalheim-policy.json',json.dumps(policy))
with sync_playwright() as p:
 browser=p.chromium.launch();page=browser.new_page(viewport={'width':1440,'height':1050});errors=[]
 page.on('pageerror',lambda e:errors.append(str(e)))
 url=f['public']+'/api.php?mode=getPlayerMods&world=Midgard'
 r=page.request.get(url);assert r.status==200,r.text();d=r.json();camera=next(m for m in d['mods'] if m['id']==990001)
 assert camera['installed_version']=='1.0.0' and camera['selected_version']=='2.0.0'
 assert next(m for m in d['mods'] if m['id']==990002)['url']==''
 assert {s['key'] for s in camera['settings']}=={'Distance','Smooth','Locked'},camera
 assert next(s for s in camera['settings'] if s['key']=='Distance')['value']=='4'
 assert not any(x in r.text() for x in ['RAW-SERVER-SECRET','RAW-UNDOCUMENTED-SECRET','PRIVATE-OVERRIDE','STALE-PRIVATE'])
 assert page.request.get(url,headers={'X-Fixture-Player':'none'}).status==401
 assert page.request.get(f['public']+'/api.php?mode=getPlayerMods&world=PrivateLibrary').status==404
 save=f['public']+'/api.php?mode=savePlayerConfig&world=Midgard'
 entry={'file':'fixture.camera.cfg','section':'Camera','key':'Distance','value':'7'}
 assert page.request.post(save,data={'revision':0,'entries':[entry]}).status==403
 def post(entries,rev=0):return page.request.post(save,headers={'X-CSRF-Token':d['csrf']},data={'revision':rev,'entries':entries})
 for key,value in [('Locked','3'),('Private','leak'),('Distance','13'),('Distance','NaN'),('Distance','4\nPrivate=bad'),('Smooth','maybe')]:
  assert post([{**entry,'key':key,'value':value}]).status==400,(key,value)
 assert post([entry]).status==200
 assert post([entry]).status==409
 other=page.request.get(url,headers={'X-Fixture-Player':'76561198000000002'}).json();assert other['personal']['entries']==[]
 for theme in ['classic','reforged','gate','ember']:
  page.request.post(f['admin']+'/adminAPI.php?action=saveServerSettings',data={'publicTheme':theme})
  page.goto(f['public']+'/authenticated.php',wait_until='networkidle');page.evaluate('acceptCookies()')
  page.locator('[data-world="Midgard"] .mod-view-link').click();dialog=page.locator('#playerMods');expect(dialog).to_be_visible()
  page.get_by_role('button',name='SmoothCamera',exact=False).click()
  expect(dialog.get_by_role('heading',name='SmoothCamera',exact=True)).to_be_visible()
  assert dialog.locator('img').count()==0,'Untrusted description rendered markup'
  inp=dialog.get_by_role('textbox',name='Camera · Distance');expect(inp).to_have_value('7')
  inp.fill('6');section=inp.locator('..');section.get_by_role('button',name='Save my choice',exact=True).click();expect(section.get_by_role('status')).to_have_text('Saved. Applies on your next launch.')
  section.get_by_role('button',name='Use published default',exact=True).click();expect(inp).to_have_value('4')
  section.get_by_role('button',name='Use local config',exact=True).click();expect(section.get_by_role('status')).to_contain_text('Using local config')
  inp.fill('7');section.get_by_role('button',name='Save my choice',exact=True).click();expect(section.get_by_role('status')).to_contain_text('Saved.')
  page.screenshot(path='/tmp/phv-player-mods-'+theme+'-desktop.png')
  search=dialog.get_by_role('searchbox');search.fill('impossible');expect(dialog.get_by_role('heading',name='No mods found')).to_be_visible();search.fill('')
  dialog.get_by_label('Filter mods').select_option('config');expect(dialog.locator('.pm-mod')).to_have_count(1)
  page.set_viewport_size({'width':390,'height':844});page.screenshot(path='/tmp/phv-player-mods-'+theme+'-mobile.png')
  assert dialog.evaluate('e=>e.scrollWidth<=e.clientWidth'),theme+' overflow'
  page.keyboard.press('Escape');expect(dialog).not_to_be_visible();expect(page.locator('[data-world="Midgard"] .mod-view-link')).to_be_focused()
  page.set_viewport_size({'width':1440,'height':1050})
  if theme in ['gate','ember']:page.locator('.theme-world-choice').filter(has_text='Ravenholm').click()
  if theme=='reforged':page.locator('[data-world="Ravenholm"] summary').click()
  page.locator('[data-world="Ravenholm"] .mod-view-link').click()
  expect(dialog.get_by_role('heading',name='No mods found')).to_be_visible()
  page.keyboard.press('Escape')
 # Token from the genuine launch builder; neither browser cookies nor a player-ID query authorize the client API.
 encoded=page.locator('[data-world="Midgard"] .launch-link').get_attribute('data-launch')
 fields=base64.b64decode(encoded).decode().split('?');token=fields[12].removeprefix('playerConfig=')
 client=f['public']+'/api.php?mode=getPlayerConfig&world=Midgard'
 assert page.request.get(client).status==401
 response=page.request.get(client,headers={'X-PhValheim-Player-Config':token});assert response.status==200,response.text();assert response.json()['entries'][0]['value']=='7'
 assert page.request.get(client,headers={'X-PhValheim-Player-Config':token[:-1]+('a' if token[-1]!='a' else 'b')}).status==401
 assert page.request.get(client.replace('Midgard','Ravenholm'),headers={'X-PhValheim-Player-Config':token}).status==401
 # Revoking overridability also revokes previously saved choices at read time.
 sql("INSERT INTO mod_config_overrides (world_id,mod_id,cfg_file,section,ckey,cvalue,server_only,overridable,locked,origin) SELECT id,990001,'fixture.camera.cfg','Camera','Distance','4',0,0,0,'ui' FROM worlds WHERE name='Midgard' ON DUPLICATE KEY UPDATE overridable=0")
 assert page.request.get(client,headers={'X-PhValheim-Player-Config':token}).json()['entries']==[]
 assert not errors,errors
 browser.close()
print('PASS: player isolation, CSRF, stale saves, private/locked filtering, validation, scoped token, revocation and 4-theme desktop/mobile editor')
