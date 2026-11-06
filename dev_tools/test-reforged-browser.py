#!/usr/bin/env python3
# requires: container
"""Classic Reforged interaction and geometry checks on an isolated presentation fixture."""
import json, os, subprocess
from pathlib import Path
from playwright.sync_api import sync_playwright, expect
from fixture_analytics import assert_isolated
f=json.loads(Path(os.environ['PHV_PRESENTATION_FIXTURE']).read_text())
assert_isolated(json.loads(subprocess.check_output(['docker','inspect',f['name']]))[0])
with sync_playwright() as p:
 b=p.chromium.launch(); page=b.new_page(viewport={'width':1440,'height':1050}); errors=[]
 page.on('pageerror',lambda e:errors.append(str(e)))
 assert page.request.post(f['admin']+'/adminAPI.php?action=saveServerSettings',data={'publicTheme':'reforged'}).json()['success']
 page.goto(f['public']+'/authenticated.php',wait_until='networkidle'); page.evaluate('acceptCookies()')
 raven=page.locator('[data-world="Ravenholm"]')
 expect(raven.get_by_role('button',name='View mods')).to_be_visible()
 raven.get_by_role('button',name='View mods').click()
 expect(page.locator('#playerMods')).to_be_visible()
 page.keyboard.press('Escape')
 search=page.get_by_role('searchbox',name='Search worlds')
 search.fill('  raven  ')
 expect(page.locator('.catbox:visible')).to_have_count(1)
 assert raven.bounding_box()['width'] > page.locator('.theme-worlds').bounding_box()['width']*.95, 'matching world should use featured width'
 expect(raven.locator('.world-seed')).to_be_visible()
 search.fill('no-world-matches-this')
 expect(page.locator('.catbox:visible')).to_have_count(0)
 expect(page.get_by_text('No worlds match your search.')).to_be_visible()
 page.get_by_role('button',name='Clear search').click()
 expect(page.locator('.catbox:visible')).to_have_count(3)
 # Offline information controls must still be clickable, including inherited dimmed cells.
 meadows=page.locator('[data-world="Meadows"]')
 meadows.locator('summary').click()
 expect(meadows.locator('.world-seed')).to_be_visible()
 meadows.get_by_text('show',exact=False).first.click()
 expect(meadows.locator('.vanilla-password')).to_contain_text('testpass')
 meadows.locator('summary').click()
 # Secondary cards use the row rather than leaving two empty four-column slots.
 assert raven.bounding_box()['width'] > page.locator('.theme-worlds').bounding_box()['width']*.45
 for width in [390,768,1024,1440,2135]:
  page.set_viewport_size({'width':width,'height':1000})
  title=page.locator('[data-world="Midgard"] h2')
  title.evaluate("e=>e.textContent='A very long Viking world name'")
  launch=page.locator('[data-world="Midgard"] .launch-link')
  a=title.bounding_box();z=launch.bounding_box()
  assert a['x']+a['width']<=z['x']+1 or a['y']+a['height']<=z['y']+1 or z['y']+z['height']<=a['y']+1, f'{width}: title/action overlap'
  assert page.evaluate('document.documentElement.scrollWidth<=innerWidth'),f'{width}: page overflow'
  assert page.locator('.trophy_icon[data-defeated="false"] .theme-boss').evaluate_all("es=>es.length>0 && es.every(e=>getComputedStyle(e).filter.includes('grayscale(1)'))")
  page.screenshot(path=f'/tmp/phv-reforged-{width}.png',full_page=True)
 # Search and open disclosures survive the actual polling renderer.
 search.fill('raven')
 page.evaluate("fetch('api.php?mode=getMyWorldsStatus').then(r=>r.json()).then(d=>updateWorldCards(d.worlds))")
 expect(page.locator('.catbox:visible')).to_have_count(1)
 expect(raven.locator('.world-seed')).to_be_visible()
 # A single vanilla result uses its connection details without an empty trophy column.
 search.fill('Meadows')
 expect(meadows.locator('.world-seed')).to_be_visible()
 assert meadows.bounding_box()['width'] > page.locator('.theme-worlds').bounding_box()['width']*.95
 page.screenshot(path='/tmp/phv-reforged-vanilla.png',full_page=True)
 def sql(statement):
  subprocess.run(['docker','exec',f['name'],'mysql','-uphvalheim_user','-pphvalheim_secretpassword','phvalheim','-e',statement],check=True,capture_output=True)
 try:
  sql("INSERT INTO worlds (name,port,public,mode,status,seed,password,password_public,vanilla) VALUES ('ReforgedExtraA',2462,1,'stopped','ready','TestSeed','',1,0),('ReforgedExtraB',2464,1,'stopped','ready','TestSeed','',1,0)")
  for width in [390,1024,1440,2135]:
   page.set_viewport_size({'width':width,'height':1100})
   page.reload(wait_until='networkidle')
   expect(page.locator('.catbox:visible')).to_have_count(5)
   assert page.evaluate('document.documentElement.scrollWidth<=innerWidth'),f'{width}: five-world overflow'
   for card in page.locator('.catbox').all():
    if 'catbox-vanilla' not in card.get_attribute('class'):
     expect(card.get_by_role('button',name='View mods')).to_be_visible()
    if not card.locator('.launch-link').is_visible():
     assert 'card_dimmed' in card.get_attribute('class'), 'online launch must be visible'
     continue
    a=card.locator('h2').bounding_box(); z=card.locator('.launch-link').bounding_box()
    assert a['x']+a['width']<=z['x']+1 or a['y']+a['height']<=z['y']+1 or z['y']+z['height']<=a['y']+1,f'{width}: compact title/action overlap'
   page.screenshot(path=f'/tmp/phv-reforged-five-{width}.png',full_page=True)
  sql('UPDATE worlds SET public=0')
  page.reload(wait_until='networkidle')
  expect(page.get_by_text('No worlds are available yet.')).to_be_visible()
  expect(page.locator('.theme-total')).to_have_text('0 online · 0 worlds')
 finally:
  sql("DELETE FROM worlds WHERE name IN ('ReforgedExtraA','ReforgedExtraB'); UPDATE worlds SET public=1")
 assert not errors,errors
 print('PASS: Reforged mod viewer, offline actions, search/empty/reset, adaptive cards, long titles, five viewport widths, polling, five-world and empty-world layouts')
 b.close()
