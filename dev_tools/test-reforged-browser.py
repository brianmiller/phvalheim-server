#!/usr/bin/env python3
# requires: container
"""Real world selection, compact geometry and controls on an isolated fixture."""
import json, os, subprocess
from pathlib import Path
from playwright.sync_api import sync_playwright, expect
from fixture_analytics import assert_isolated
f=json.loads(Path(os.environ['PHV_PRESENTATION_FIXTURE']).read_text())
assert_isolated(json.loads(subprocess.check_output(['docker','inspect',f['name']]))[0])
with sync_playwright() as p:
 b=p.chromium.launch(); page=b.new_page(viewport={'width':1728,'height':822}); errors=[]
 page.on('pageerror',lambda e:errors.append(str(e)))
 assert page.request.post(f['admin']+'/adminAPI.php?action=saveServerSettings',data={'publicTheme':'reforged'}).json()['success']
 page.goto(f['public']+'/authenticated.php',wait_until='networkidle'); page.evaluate('acceptCookies()')
 nav=page.get_by_role('navigation',name='Choose a world')
 def choose(world,keyboard=False):
  button=nav.get_by_role('button',name=world,exact=False)
  if keyboard:
   button.focus(); page.keyboard.press('Enter')
  else: button.click()
  expect(button).to_have_attribute('aria-pressed','true')
  expect(page.locator('.catbox:visible')).to_have_count(1)
  card=page.locator('[data-world="'+world+'"]');expect(card).to_be_visible()
  return card
 # This fails on the previous build: world cards had no selection action.
 raven=choose('Ravenholm')
 expect(page.locator('[data-world="Midgard"]')).to_be_hidden()
 raven.get_by_role('button',name='View mods').click()
 expect(page.locator('#playerMods')).to_be_visible();page.keyboard.press('Escape')
 raven.locator('summary').click();expect(raven.locator('.world-seed')).to_be_visible()
 page.evaluate("fetch('api.php?mode=getMyWorldsStatus').then(r=>r.json()).then(d=>updateWorldCards(d.worlds))")
 expect(raven).to_be_visible();expect(raven.locator('.world-seed')).to_be_visible()
 meadows=choose('Meadows',keyboard=True)
 meadows.get_by_text('show',exact=False).first.click()
 expect(meadows.locator('.vanilla-password')).to_contain_text('testpass')
 search=page.get_by_role('searchbox',name='Search worlds')
 search.fill('  raven  ');expect(raven).to_be_visible();expect(nav.locator('button:visible')).to_have_count(1)
 search.fill('');expect(raven).to_be_visible() # Clearing search must not pin the first world again.
 search.fill('no-world-matches-this');expect(page.locator('.catbox:visible')).to_have_count(0)
 expect(page.get_by_text('No worlds match your search.')).to_be_visible()
 page.get_by_role('button',name='Clear search').click();expect(nav.locator('button:visible')).to_have_count(3)
 for width in [390,768,1024,1440,1728,2135]:
  page.set_viewport_size({'width':width,'height':822})
  card=choose('Midgard')
  title=card.locator('h2');title.evaluate("e=>e.textContent='A very long Viking world name'")
  a=title.bounding_box();z=card.locator('.launch-link').bounding_box()
  assert a['x']+a['width']<=z['x']+1 or a['y']+a['height']<=z['y']+1 or z['y']+z['height']<=a['y']+1,f'{width}: title/action overlap'
  assert page.evaluate('document.documentElement.scrollWidth<=innerWidth'),f'{width}: overflow'
  assert card.locator('.theme-boss').evaluate_all("es=>es.every(e=>e.getBoundingClientRect().width<=73)"),'boss heads too large'
  if width>=1024:
   assert card.bounding_box()['height']<330, 'selected panel is oversized'
   assert card.bounding_box()['y']+card.bounding_box()['height']<700, 'world controls should fit a desktop viewport'
  page.screenshot(path=f'/tmp/phv-reforged-select-{width}.png',full_page=True)
 assert page.locator('.trophy_icon[data-defeated="false"] .theme-boss').evaluate_all("es=>es.length>0 && es.every(e=>getComputedStyle(e).filter.includes('grayscale(1)'))")
 def sql(statement):
  subprocess.run(['docker','exec',f['name'],'mysql','-uphvalheim_user','-pphvalheim_secretpassword','phvalheim','-e',statement],check=True,capture_output=True)
 try:
  sql("INSERT INTO worlds (name,port,public,mode,status,seed,password,password_public,vanilla) VALUES ('ReforgedExtraA',2462,1,'stopped','ready','TestSeed','',1,0),('ReforgedExtraB',2464,1,'stopped','ready','TestSeed','',1,0)")
  for width in [390,1024,1728]:
   page.set_viewport_size({'width':width,'height':822});page.reload(wait_until='networkidle')
   expect(nav.locator('button')).to_have_count(5)
   for world in ['Midgard','Meadows','Ravenholm','ReforgedExtraA','ReforgedExtraB']:
    card=choose(world)
    if 'catbox-vanilla' not in card.get_attribute('class'):
     expect(card.get_by_role('button',name='View mods')).to_be_visible()
   assert page.evaluate('document.documentElement.scrollWidth<=innerWidth')
   page.screenshot(path=f'/tmp/phv-reforged-select-five-{width}.png',full_page=True)
  sql('UPDATE worlds SET public=0');page.reload(wait_until='networkidle')
  expect(page.get_by_text('No worlds are available yet.')).to_be_visible()
  expect(page.locator('.theme-total')).to_have_text('0 online · 0 worlds')
 finally:
  sql("DELETE FROM worlds WHERE name IN ('ReforgedExtraA','ReforgedExtraB'); UPDATE worlds SET public=1")
 assert not errors,errors
 print('PASS: click/keyboard world selection, no pinned first world, polling, mod viewer, offline controls, search/reset, six viewport sizes, compact geometry, five/zero worlds')
 b.close()
