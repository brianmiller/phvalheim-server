#!/usr/bin/env python3
# requires: container
"""Exercise native login and published client downloads on an isolated real PHP fixture."""
import json, os, subprocess
from pathlib import Path
from urllib.parse import parse_qs
from playwright.sync_api import sync_playwright, expect
from fixture_analytics import assert_isolated
f=json.loads(Path(os.environ['PHV_PRESENTATION_FIXTURE']).read_text())
assert_isolated(json.loads(subprocess.check_output(['docker','inspect',f['name']]))[0])
with sync_playwright() as p:
 b=p.chromium.launch(); errors=[]
 for theme in ['classic','reforged','gate','ember']:
  ctx=b.new_context(viewport={'width':1440,'height':900},user_agent='Mozilla/5.0 (X11; Linux x86_64) Chrome/130.0')
  page=ctx.new_page();page.on('pageerror',lambda e:errors.append(str(e)))
  assert page.request.post(f['admin']+'/adminAPI.php?action=saveServerSettings',data={'publicTheme':theme}).json()['success']
  page.goto(f['public']+'/index.php',wait_until='networkidle')
  expect(page.locator('.entry-card')).to_be_visible()
  expect(page.get_by_role('heading',name='Welcome, Viking.')).to_be_visible()
  expect(page.locator('#authenticated_form')).to_have_attribute('method','post')
  page.locator('.entry-about summary').click();expect(page.get_by_role('link',name='Read the field guide')).to_be_visible()
  for width in [1440,390,320]:
   page.set_viewport_size({'width':width,'height':900})
   assert page.evaluate('document.documentElement.scrollWidth<=innerWidth')
   assert page.locator('.entry-card').bounding_box()['width']<=461
   page.screenshot(path=f'/tmp/phv-entry-{theme}-login-{width}.png',full_page=True)
  # Intercept the actual form POST; never sign in to Steam during a fixture test.
  sent=[]
  page.route('https://steamcommunity.com/openid/login',lambda route:(sent.append(route.request.post_data),route.fulfill(status=200,body='Steam handoff intercepted')))
  page.get_by_role('button',name='Sign in through Steam').click();page.wait_for_url('https://steamcommunity.com/openid/login*')
  fields=parse_qs(sent[0]);assert fields['openid.mode']==['checkid_setup']
  assert fields['openid.return_to']==[f['public']+'/authenticated.php']
  page.goto(f['public']+'/index.php?auth=failed');expect(page.get_by_role('alert')).to_contain_text('Please try again')
  page.set_viewport_size({'width':1440,'height':900})
  page.goto(f['public']+'/authenticated.php',wait_until='networkidle');page.evaluate('acceptCookies()')
  button=page.locator('.client-download-trigger');panel=page.locator('#clientDownloadPanel')
  button.click();expect(panel).to_be_visible();expect(panel.get_by_role('link',name='DEB package')).to_be_visible()
  assert panel.get_by_role('link',name='DEB package').get_attribute('href').endswith('.deb')
  assert panel.locator('.client-package').count()>=4
  page.screenshot(path=f'/tmp/phv-entry-{theme}-downloads.png',full_page=True)
  page.keyboard.press('Escape');expect(panel).to_be_hidden();expect(button).to_be_focused()
  button.press('ArrowDown');expect(panel).to_be_visible()
  panel.get_by_role('link',name='Flatpak').click();expect(page.locator('#flatpakInstallModal')).to_be_visible();expect(panel).to_be_hidden()
  expect(page.locator('#flatpakDownloadBtn')).to_have_attribute('href',__import__('re').compile(r'\.flatpak$'))
  page.screenshot(path=f'/tmp/phv-entry-{theme}-flatpak.png',full_page=True)
  expect(page.locator('#flatpakInstallModal')).to_be_focused();page.keyboard.press('Escape');expect(page.locator('#flatpakInstallModal')).to_be_hidden()
  for width in [390,320]:
   page.set_viewport_size({'width':width,'height':700});button.click();expect(panel).to_be_visible()
   box=panel.bounding_box();assert box['x']>=0 and box['x']+box['width']<=width+1 and box['y']>=0 and box['y']+box['height']<=701,box
   page.screenshot(path=f'/tmp/phv-entry-{theme}-downloads-{width}.png',full_page=True)
   panel.get_by_role('button',name='Close downloads').click();expect(button).to_be_focused()
  button.click();page.locator('body').click(position={'x':1,'y':1});expect(panel).to_be_hidden()
  ctx.close()
 for platform,ua in [('Windows','Mozilla/5.0 (Windows NT 10.0; Win64; x64)'),('macOS','Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7)')]:
  page=b.new_page(user_agent=ua);page.goto(f['public']+'/authenticated.php',wait_until='networkidle');page.evaluate('acceptCookies()')
  page.locator('.client-download-trigger').click();panel=page.locator('#clientDownloadPanel');expect(panel).to_contain_text('Downloads for '+platform)
  if platform=='Windows': assert panel.get_by_role('link',name='Windows installer').get_attribute('href').endswith('.msi')
  else:
   panel.get_by_role('button',name='Install for macOS').click();expect(page.locator('#macInstallModal')).to_be_visible();expect(panel).to_be_hidden()
  page.close()
 # Exercise the same renderer's historical gates and missing-release fallback.
 page=b.new_page(user_agent='Mozilla/5.0 (X11; Linux x86_64)');
 cache='/opt/stateful/cache/clientReleases.json'
 original=subprocess.check_output(['docker','exec',f['name'],'cat',cache])
 def cache_versions(versions):
  subprocess.run(['docker','exec','-i',f['name'],'sh','-c','cat > '+cache],input=json.dumps({'versions':versions}).encode(),check=True)
 try:
  for version in ['2.0.12','2.0.11']:
   cache_versions([version]);page.goto(f['public']+'/authenticated.php',wait_until='networkidle');page.evaluate('acceptCookies()');page.locator('.client-download-trigger').click()
   panel=page.locator('#clientDownloadPanel');expect(panel.locator('.client-package')).to_have_count(3)
   expected='/releases/download/'+version+'/' if version=='2.0.12' else '/raw/master/builds/'
   assert all(expected in href for href in panel.locator('.client-package').evaluate_all('(es)=>es.map(e=>e.href)'))
  cache_versions([]);page.reload(wait_until='networkidle');page.locator('.client-download-trigger').click()
  expect(page.locator('#clientDownloadPanel')).to_contain_text('No published release is available')
  expect(page.locator('#clientDownloadPanel .client-package')).to_have_count(0)
  expect(page.get_by_role('link',name='All platforms & releases')).to_have_attribute('href','https://github.com/brianmiller/phvalheim-client/releases')
 finally:
  subprocess.run(['docker','exec','-i',f['name'],'sh','-c','cat > '+cache],input=original,check=True)
 page.close()
 # Login is usable without JavaScript, including About and Steam's native form.
 page=b.new_page(java_script_enabled=False);page.goto(f['public']+'/index.php');expect(page.get_by_role('button',name='Sign in through Steam')).to_be_visible();page.locator('summary').click();expect(page.get_by_role('link',name='Read the field guide')).to_be_visible()
 assert not errors,errors
 b.close()
 print('PASS: four themed native logins, actual intercepted Steam POST, error/About/no-JS, package links, Flatpak/macOS guides, keyboard/outside dismissal, mobile bounds and screenshots')
