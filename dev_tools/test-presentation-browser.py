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
  page.evaluate('acceptCookies()')
  assert page.locator('.catbox').count()==3
  if theme=='classic':
   assert page.locator('script[src*="public-themes"]').count()==0
   assert page.locator('[data-world="Midgard"] .trophy_dimmed').evaluate_all("es=>es.length===4 && es.every(e=>getComputedStyle(e).filter.includes('grayscale(1)'))"), 'Classic undefeated heads must stay grey'
  else:
   expect(page.locator('.theme-brand')).to_be_visible()
   assert page.locator('.theme-boss').count()==14
   # Geometry checks target the shipped visual defects, not just DOM presence.
   launch=page.locator('[data-world="Midgard"] .launch-link')
   assert 80 <= launch.bounding_box()['width'] <= 260, theme+' oversized Launch'
   assert page.locator('.theme-header').bounding_box()['height'] < 125, theme+' oversized header'
   assert page.locator('[data-world="Midgard"] .card_worldInfo').first.evaluate("e=>parseFloat(getComputedStyle(e).fontSize)") >= 14
   cells=page.locator('[data-world="Midgard"] .trophy_icon')
   assert cells.evaluate_all("es=>es.every(e=>getComputedStyle(e).opacity==='1' && getComputedStyle(e).filter==='none')"),theme+' double-dimmed trophies'
   assert page.locator('[data-world="Midgard"] .theme-boss').evaluate_all("es=>es.every(e=>e.getBoundingClientRect().width>=70)"),theme+' collapsed boss artwork'
   def boss_colors():
    assert page.locator('.trophy_icon[data-defeated="false"] .theme-boss').evaluate_all("es=>es.length>0 && es.every(e=>getComputedStyle(e).filter.includes('grayscale(1)'))"),theme+' undefeated heads must be grey'
    assert page.locator('.trophy_icon[data-defeated="true"] .theme-boss').evaluate_all("es=>es.length>0 && es.every(e=>getComputedStyle(e).filter==='none')"),theme+' defeated heads must retain color'
   boss_colors()
   progress=page.locator('[data-world="Midgard"] .theme-progress')
   expect(progress).to_have_text('3 / 7')
   boss=page.locator('[data-world="Midgard"] .trophy-dragonQueen img')
   original=boss.get_attribute('title')
   boss.evaluate("e=>e.title='Moder has been defeated'")
   expect(progress).to_have_text('4 / 7')
   boss_colors()
   boss.evaluate('(e,title)=>e.title=title',original)
   expect(progress).to_have_text('3 / 7')
   boss_colors()
   if theme=='reforged':
    search=page.get_by_role('searchbox',name='Search worlds')
    search.fill('Raven');assert page.locator('.catbox:visible').count()==1
    expect(page.locator('[data-world="Ravenholm"] .world-seed')).to_be_visible()
    search.fill('')
    page.locator('[data-world="Ravenholm"] summary').click()
    expect(page.locator('[data-world="Ravenholm"] .world-seed')).to_be_visible()
    page.locator('[data-world="Ravenholm"] summary').click()
   elif theme=='gate':
    assert page.locator('[data-world="Midgard"]').bounding_box()['width'] <= 950
   else:
    rail=page.locator('.theme-world-nav').bounding_box();card=page.locator('[data-world="Midgard"]').bounding_box()
    assert rail['x']+rail['width'] < card['x'], 'Ember world chooser must sit beside the world'

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
  if theme != 'classic':
   assert page.evaluate('document.documentElement.scrollWidth <= innerWidth'),theme+' mobile overflow'
   assert page.locator('[data-world="Midgard"] .theme-boss').evaluate_all("es=>es.every(e=>e.getBoundingClientRect().width>=60)"),theme+' mobile boss heads too small'
   assert page.locator('[data-world="Midgard"] .theme-boss-name').evaluate_all("es=>es.every(e=>parseFloat(getComputedStyle(e).fontSize)>=12)"),theme+' mobile labels too small'
   # Capture the lower viewport as well: full-page screenshots alone can hide fixed-background bugs.
   page.evaluate("document.documentElement.style.scrollBehavior='auto';document.body.style.scrollBehavior='auto';scrollTo(0,document.body.scrollHeight)");page.wait_for_timeout(250);page.screenshot(path='/tmp/phv-presentation-'+theme+'-mobile-bottom.png')
   page.evaluate('scrollTo(0,0)')
  page.screenshot(path='/tmp/phv-presentation-'+theme+'-mobile.png',full_page=True)
  if theme=='reforged':
   for width in [768,1024,2135]:
    page.set_viewport_size({'width':width,'height':1000})
    title=page.locator('[data-world="Midgard"] h2');title.evaluate("e=>e.textContent='VikingOutlaws'")
    a=title.bounding_box();b=page.locator('[data-world="Midgard"] .launch-link').bounding_box()
    assert a['x']+a['width']<=b['x'] or a['y']+a['height']<=b['y'],str(width)+' title/action overlap'
    assert page.evaluate('document.documentElement.scrollWidth<=innerWidth')
  page.set_viewport_size({'width':1440,'height':1050})
 page.goto(f['admin'],wait_until='domcontentloaded');page.evaluate('showServerSettingsModal()')
 expect(page.locator('#ss-publicTheme')).to_be_visible();assert page.locator('#ss-publicTheme option').count()==4
 expect(page.locator('#ss-disableSteamOverlay')).not_to_be_checked()
 assert not errors,errors
 print('PASS: theme persistence/allowlist, all four desktop/mobile layouts, preserved actions/polling, world selection/keyboard, global/world overlay inheritance and public launch payload, admin controls')
 browser.close()
