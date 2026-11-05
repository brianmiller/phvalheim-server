#!/usr/bin/env python3
# requires: manual
"""Live isolated HTTP/DB page plus controlled provider responses for header state transitions."""
import json,os,subprocess,time
from pathlib import Path
from playwright.sync_api import sync_playwright,expect
from fixture_analytics import assert_isolated
fixture=json.loads(Path(os.environ['PHV_STATUS_FIXTURE']).read_text())
assert_isolated(json.loads(subprocess.check_output(['docker','inspect',fixture['name']]))[0])
with sync_playwright() as p:
    browser=p.chromium.launch();page=browser.new_page(viewport={'width':1440,'height':1000})
    errors=[];page.on('pageerror',lambda e:errors.append(str(e)))
    current={'provider':'','enabled':False,'state':'Off','health':'ok','available':True,'age':0}; requests=[]
    def response(route):
        requests.append(time.monotonic());now=time.time();c=current
        data={'success':True,'available':c['available'],'now':now,'csrf':'fixture','config':{'provider':c['provider'],'enabled':c['enabled'],'revision':1,'url':'https://play.example.net','hostname':'fixture'},'observed':{'state':c['state'],'time':now-c['age'],'details':{}},'health':{'state':c['health'],'detail':'Public website status from the fixture.'},'test':None,'events':[],'authUrl':c.get('auth','')}
        if not c['available']: data.pop('config')
        route.fulfill(json=data)
    page.route('**/remoteAccessAPI.php',response)
    def refresh(): page.evaluate("document.dispatchEvent(new Event('visibilitychange'))")
    page.goto(fixture['url'],wait_until='domcontentloaded')
    for provider in ['tailscale','cloudflare']:expect(page.locator('#raPill-'+provider)).to_be_hidden()
    expect(page.get_by_role('button',name='Ask Hugin',exact=False)).to_be_visible()
    start=len(requests);page.wait_for_timeout(5500);assert len(requests)>start,'header must update with modal closed'
    page.evaluate('refreshModSyncPanel()')
    card=page.locator('.vibes-library');expect(card).to_contain_text('LOCAL LIBRARY');expect(card).to_contain_text('published mods')
    assert 'NEVER RUN' not in card.inner_text().upper() and 'sync' not in card.locator('a').all_text_contents()
    expect(card.locator('[data-sync]')).to_have_count(0)
    page.evaluate('showServerHealthModal()');expect(page.locator('#serverHealthBody')).to_contain_text('Hugin’s Vibes library');expect(page.locator('#serverHealthBody')).not_to_contain_text('Vibes catalogue');page.evaluate('closeServerHealthModal()')
    for provider in ['tailscale','cloudflare']:
        current.update(provider=provider,enabled=True,state='Connecting',health='unknown');refresh()
        pill=page.locator('#raPill-'+provider);expect(pill).to_be_visible();expect(pill).to_contain_text('Connecting')
        expect(page.locator('#raPill-'+('cloudflare' if provider=='tailscale' else 'tailscale'))).to_be_hidden()
        current.update(state='Connected');refresh();expect(pill).to_contain_text('Checking')
        current.update(health='ok');refresh();expect(pill).to_contain_text('Live');expect(pill).to_have_attribute('data-state','live')
        pill.click();expect(page.locator('#raMethodTitle')).to_contain_text('Tailscale' if provider=='tailscale' else 'Cloudflare');page.locator('#raClose').click()
        current.update(available=False);refresh();expect(pill).to_be_visible();expect(pill).to_contain_text('Unknown')
        current.update(available=True,health='warn');refresh();expect(pill).to_contain_text('Attention')
        current.update(health='ok',age=30);refresh();expect(pill).to_contain_text('Unknown')
        current.update(age=0);refresh();expect(pill).to_contain_text('Live')
        current.update(enabled=False,state='Off');refresh();expect(pill).to_be_visible();expect(pill).to_contain_text('Off')
        current.update(provider='');refresh();expect(pill).to_be_hidden()
    current.update(provider='tailscale',enabled=True,state='Connected');refresh();expect(page.locator('#raPill-tailscale')).to_contain_text('Live')
    page.locator('.admin-header').screenshot(path='/tmp/phv262-status-header.png')
    card.screenshot(path='/tmp/phv262-status-library.png')
    page.set_viewport_size({'width':390,'height':844});page.wait_for_timeout(500);page.evaluate("scrollTo({top:0,behavior:'instant'})");page.wait_for_timeout(200);page.screenshot(path='/tmp/phv262-status-mobile.png')
    assert page.evaluate('document.documentElement.scrollWidth <= innerWidth'),'mobile page overflows'
    for provider in ['tailscale']:
        box=page.locator('#raPill-'+provider).bounding_box();assert box['x']>=0 and box['x']+box['width']<=390
    page.locator('#raPill-tailscale').focus();page.keyboard.press('Enter');expect(page.locator('#remoteAccessOverlay')).to_be_visible()
    page.locator('#raClose').click();expect(page.locator('#raPill-tailscale')).to_be_focused()
    assert not errors,errors
    print('PASS: live library/health, background polling, unconfigured/forgotten providers hidden, saved disconnected visible, both providers connecting/checking/live/failure/stale/recovery, provider click, mobile fit, keyboard/focus, no JS errors')
    browser.close()
