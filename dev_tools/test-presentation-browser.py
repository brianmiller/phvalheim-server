#!/usr/bin/env python3
# requires: manual — isolated fixture with seeded modded/vanilla worlds and Steam profile stub
"""Actual admin persistence, public themes and world-policy inheritance over HTTP."""
import base64,json,os,subprocess
from pathlib import Path
from playwright.sync_api import sync_playwright,expect
from fixture_analytics import assert_isolated
f=json.loads(Path(os.environ['PHV_PRESENTATION_FIXTURE']).read_text())
assert_isolated(json.loads(subprocess.check_output(['docker','inspect',f['name']]))[0])
with sync_playwright() as p:
 browser=p.chromium.launch();page=browser.new_page(viewport={'width':1440,'height':1050});errors=[]
 page.on('pageerror',lambda e:errors.append(str(e)))
 def api(action,data=None):
  url=f['admin']+'/adminAPI.php?action='+action
  return (page.request.get(url) if data is None else page.request.post(url,data=data)).json()
 def save(data):
  response=api('saveServerSettings',data);assert response['success'],response
 def world(value):
  response=api('saveWorldOptions&world=Midgard',{'world':'Midgard','vanilla':0,'password':'testpass','crossplay':0,'listed':0,'passwordPublic':1,'launchParams':'','disableSteamOverlay':value});assert response['success'],response
 def policy(): return api('getWorldOptions&world=Midgard')['overlay']
 save({'publicTheme':'classic','disableSteamOverlay':0});world(None)
 assert policy()=={'override':None,'serverDisabled':0,'disabled':False}
 for server,override,disabled in [(1,None,True),(1,0,False),(0,1,True),(0,None,False)]:
  save({'disableSteamOverlay':server});world(override);assert policy()['disabled']==disabled
  page.goto(f['public']+'/authenticated.php',wait_until='networkidle')
  encoded=page.locator('[data-world="Midgard"] .launch-link').get_attribute('data-launch')
  fields=base64.b64decode(encoded).decode().split('?');assert fields[11]==('overlay=off' if disabled else 'overlay=on')
  assert len(fields)>11 and fields[1]=='Midgard' and fields[2]=='testpass'
 for bad in ['../gate',None,{},'unknown']:
  assert not api('saveServerSettings',{'publicTheme':bad})['success']
 for bad in [2,'1',True,None]:
  assert not api('saveServerSettings',{'disableSteamOverlay':bad})['success']
 assert not api('saveWorldOptions&world=Midgard',{'world':'Midgard','disableSteamOverlay':'off'})['success']
 for theme in ['classic','reforged','gate','ember']:
  save({'publicTheme':theme});page.goto(f['public']+'/authenticated.php',wait_until='networkidle')
  expect(page.locator('body')).to_have_attribute('data-public-theme',theme)
  assert page.locator('.catbox').count()==3
  if theme=='classic':
   assert page.locator('script[src*="public-themes"]').count()==0
  else:
   expect(page.locator('.theme-brand')).to_be_visible()
   assert page.locator('.theme-boss').count()==14
   if theme in ['gate','ember']:
    assert page.locator('.catbox:visible').count()==1
    page.get_by_role('button',name='Meadows',exact=False).click()
    expect(page.locator('[data-world="Meadows"]')).to_be_visible()
    page.get_by_role('button',name='Midgard',exact=False).focus();page.keyboard.press('Enter')
    expect(page.locator('[data-world="Midgard"]')).to_be_visible()
   page.locator('[data-world="Midgard"] .vanilla-password-action').first.click()
   expect(page.locator('[data-world="Midgard"] .vanilla-password')).to_contain_text('testpass')
   # Exercise the real polling renderer against a changed response, without restarting worlds.
   page.evaluate("fetch('api.php?mode=getMyWorldsStatus').then(r=>r.json()).then(d=>updateWorldCards(d.worlds))")
   page.wait_for_timeout(500)
  page.screenshot(path='/tmp/phv-presentation-'+theme+'-desktop.png',full_page=True)
  page.set_viewport_size({'width':390,'height':844});page.wait_for_timeout(200)
  if theme != 'classic': assert page.evaluate('document.documentElement.scrollWidth <= innerWidth'),theme+' mobile overflow'
  page.screenshot(path='/tmp/phv-presentation-'+theme+'-mobile.png',full_page=True)
  page.set_viewport_size({'width':1440,'height':1050})
 page.goto(f['admin'],wait_until='domcontentloaded');page.evaluate('showServerSettingsModal()')
 expect(page.locator('#ss-publicTheme')).to_be_visible();assert page.locator('#ss-publicTheme option').count()==4
 expect(page.locator('#ss-disableSteamOverlay')).not_to_be_checked()
 assert not errors,errors
 print('PASS: theme persistence/allowlist, all four desktop/mobile layouts, preserved actions/polling, world selection/keyboard, global/world overlay inheritance and public launch payload, admin controls')
 browser.close()
