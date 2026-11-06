#!/usr/bin/env python3
# requires: container
"""Independent login/public themes, migration defaults and visible crossplay join codes."""
import json,os,subprocess
from pathlib import Path
from playwright.sync_api import sync_playwright,expect
from fixture_analytics import assert_isolated
f=json.loads(Path(os.environ['PHV_PRESENTATION_FIXTURE']).read_text())
assert_isolated(json.loads(subprocess.check_output(['docker','inspect',f['name']]))[0])
def sql(s):return subprocess.check_output(['docker','exec',f['name'],'mysql','-N','-uroot','phvalheim','-e',s],text=True).strip()
def migrate():subprocess.run(['docker','exec',f['name'],'bash','/opt/stateless/engine/dbUpdates/dbUpdate_2.62.sh'],check=True,capture_output=True)
def file(path,text):subprocess.run(['docker','exec','-i',f['name'],'sh','-c','cat > '+path+' && chmod 644 '+path],input=text.encode(),check=True)
with sync_playwright() as p:
 b=p.chromium.launch();ctx=b.new_context(viewport={'width':1440,'height':900},permissions=['clipboard-read','clipboard-write']);page=ctx.new_page();errors=[]
 page.on('pageerror',lambda e:errors.append(str(e)))
 def settings():return page.request.get(f['admin']+'/adminAPI.php?action=getServerSettings').json()['settings']
 def save(data):return page.request.post(f['admin']+'/adminAPI.php?action=saveServerSettings',data=data).json()
 assert 'loginTheme' in settings(),'independent login setting missing'
 # Owned disposable fixture: exercise first migration, upgrade and repeat boot.
 sql('ALTER TABLE settings DROP COLUMN publicTheme, DROP COLUMN loginTheme');migrate()
 assert settings()['publicTheme']==settings()['loginTheme']=='reforged'
 for field in ['publicTheme','loginTheme']:
  assert sql("SELECT COLUMN_DEFAULT FROM information_schema.COLUMNS WHERE TABLE_SCHEMA='phvalheim' AND TABLE_NAME='settings' AND COLUMN_NAME='"+field+"'").strip("'")=='reforged'
 assert save({'publicTheme':'classic','loginTheme':'ember'})['success']
 sql("ALTER TABLE settings ALTER COLUMN publicTheme SET DEFAULT 'classic'")
 migrate();migrate()
 assert settings()['publicTheme']=='classic' and settings()['loginTheme']=='ember','migration overwrote saved choices'
 assert sql("SELECT COLUMN_DEFAULT FROM information_schema.COLUMNS WHERE TABLE_SCHEMA='phvalheim' AND TABLE_NAME='settings' AND COLUMN_NAME='publicTheme'").strip("'")=='reforged'
 for field in ['publicTheme','loginTheme']:
  for bad in [None,True,1,{},[], '../gate','GATE','']:
   before=settings();assert not save({field:bad})['success'];after=settings()
   assert (before['publicTheme'],before['loginTheme'])==(after['publicTheme'],after['loginTheme'])
 themes=['classic','reforged','gate','ember']
 for public in themes:
  for login in themes:
   assert save({'publicTheme':public,'loginTheme':login})['success']
   assert f'data-public-theme="{login}"' in page.request.get(f['public']+'/index.php').text()
   assert f'data-public-theme="{public}"' in page.request.get(f['public']+'/authenticated.php').text()
 for theme in themes:
  assert save({'loginTheme':theme})['success'];assert settings()['publicTheme']=='ember','login save changed public theme'
  for width in [1440,390]:
   page.set_viewport_size({'width':width,'height':900});page.goto(f['public']+'/index.php',wait_until='networkidle')
   expect(page.locator('body')).to_have_attribute('data-public-theme',theme)
   expect(page.get_by_role('button',name='Sign in through Steam')).to_be_visible()
   assert page.evaluate('document.documentElement.scrollWidth<=innerWidth')
   page.screenshot(path=f'/tmp/phv-login-choice-{theme}-{width}.png',full_page=True)
 # Save both choices through the real Server Settings form, then reopen it.
 page.set_viewport_size({'width':1440,'height':900});page.goto(f['admin'],wait_until='domcontentloaded');page.evaluate('showServerSettingsModal()')
 expect(page.locator('#ss-loginTheme')).to_be_visible();expect(page.locator('#ss-loginTheme option')).to_have_count(4)
 page.locator('#ss-publicTheme').select_option('reforged');page.locator('#ss-loginTheme').select_option('gate')
 with page.expect_response('**/adminAPI.php?action=saveServerSettings') as response:page.locator('#ssSubmitBtn').click()
 assert response.value.json()['success'];page.reload(wait_until='domcontentloaded');page.evaluate('showServerSettingsModal()')
 expect(page.locator('#ss-publicTheme')).to_have_value('reforged');expect(page.locator('#ss-loginTheme')).to_have_value('gate')
 page.screenshot(path='/tmp/phv-login-choice-settings.png',full_page=True)
 # Crossplay code is real log-derived data, for both modded and vanilla worlds.
 sql("UPDATE worlds SET crossplay=1 WHERE name IN ('Midgard','Meadows')")
 for world in ['Midgard','Meadows']:
  file('/opt/stateful/logs/valheimworld_'+world+'.log',f'Opened PlayFab server\nSession "{world}" registered with join code 123456\n')
 subprocess.run(['docker','exec','-d',f['name'],'bash','-c','exec -a "valheim_server -name Meadows " sleep 600'],check=True)
 for theme in themes:
  assert save({'publicTheme':theme})['success'];assert settings()['loginTheme']=='gate','public save changed login theme'
  for width in [1440,390]:
   page.set_viewport_size({'width':width,'height':900});page.goto(f['public']+'/authenticated.php',wait_until='networkidle');page.evaluate('acceptCookies()')
   for world in ['Midgard','Meadows']:
    if theme!='classic':page.get_by_role('button',name=world,exact=False).click()
    card=page.locator('[data-world="'+world+'"]');code=card.locator('.world-joincode');expect(code).to_be_visible();expect(code).to_contain_text('123456')
    assert code.locator('xpath=ancestor::details').count()==0,'Join code hidden under World details'
    code.get_by_role('link',name='copy',exact=True).click();assert page.evaluate('navigator.clipboard.readText()')=='123456'
    card.locator('.vanilla-password').get_by_role('link',name='show',exact=True).click();expect(card.locator('.vanilla-password')).to_contain_text('testpass')
   if theme=='reforged':page.screenshot(path=f'/tmp/phv-join-code-overview-{width}.png',full_page=True)
 # Keep the same DOM and exercise the actual HTTP polling path with a changed code.
 file('/opt/stateful/logs/valheimworld_Meadows.log','Opened PlayFab server\nSession "Meadows" registered with join code 654321\n')
 page.evaluate("fetch('api.php?mode=getMyWorldsStatus').then(r=>r.json()).then(d=>updateWorldCards(d.worlds))")
 expect(page.locator('[data-world="Meadows"] .world-joincode')).to_contain_text('654321')
 assert not errors,errors
 b.close()
print('PASS: Reforged defaults, upgrade/idempotence preserving choices, validation, 16 independent combinations, real admin save/reopen, four login themes, visible/copyable modded+vanilla join codes across themes/mobile, polling')
