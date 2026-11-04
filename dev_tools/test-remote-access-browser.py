#!/usr/bin/env python3
# requires: manual
"""Real browser flow against a retained disposable Remote Access integration fixture.

Run test-remote-access-integration.py with PHV_REMOTE_TEST_KEEP=1 first.
Requires Python Playwright and Chromium; never targets an operator's container.
"""
import json
from pathlib import Path
import subprocess
import time
from urllib.parse import urlsplit
from playwright.sync_api import sync_playwright, expect

fixture=json.loads(Path('/tmp/phv-remote-browser-fixture.json').read_text())
assert fixture['app'].startswith('phv-remote-oracle-')
assert fixture['connector'].startswith('phv-remote-oracle-')
deadline = time.monotonic() + 240
while True:
    ready = subprocess.run(['docker','exec',fixture['app'],'mysql','-uroot','phvalheim','-e',
                "UPDATE settings SET setupComplete=2, migrationNoticeShown=1, huginNoticeShown=1, connectNoticeShown=1, steamAPIKey='00000000000000000000000000000000', gameDNS='fixture.invalid';"],capture_output=True)
    engine = subprocess.run(['docker','exec',fixture['app'],'grep','-q','PhValheim engine started','/opt/stateful/logs/phvalheim.log'],capture_output=True)
    if ready.returncode == 0 and engine.returncode == 0: break
    if time.monotonic() > deadline: raise AssertionError('Fixture database did not become ready')
    time.sleep(.5)
# Restore only this disposable fixture so failed browser runs can be repeated.
subprocess.run(['docker','exec',fixture['connector'],'python3','-c',
    "import socket,json; s=socket.socket(socket.AF_UNIX); s.connect('/run/phvalheim-remote/control.sock'); s.sendall(b'{\"action\":\"forget\"}\\n'); r=json.loads(s.makefile().readline()); assert r['success'],r"], check=True,capture_output=True)
# Only this disposable database is seeded; never modify an operator's worlds.
def sql(query):
    subprocess.run(['docker','exec',fixture['app'],'mysql','-uroot','phvalheim','-e',query],check=True,capture_output=True)
sql("DELETE FROM worlds; INSERT INTO worlds(name,mode,port,crossplay) VALUES ('Direct_world','stopped',25000,0),('Relay_world','stopped',25002,1),('Waiting_world','stopped',NULL,0);")
with sync_playwright() as p:
    address=urlsplit(fixture['admin'])
    browser=p.chromium.launch(headless=True,args=[f'--host-resolver-rules=MAP phvalheim-lan.test {address.hostname}'])
    page=browser.new_page(viewport={'width':1440,'height':1000})
    errors=[]; exposed=[]
    page.on('pageerror',lambda e:errors.append(str(e)))
    def response(r):
        if 'remoteAccessAPI.php' in r.url and r.request.method=='GET':
            try:
                if 'A'*120 in r.text(): exposed.append(r.url)
            except Exception: pass
    page.on('response',response)
    page.on('dialog',lambda d:d.accept())
    def open_modal(url=None):
        if url: page.goto(url,wait_until='domcontentloaded')
        page.locator('.nav-item',has_text='Remote Access').click()
        expect(page.locator('#raChoices')).to_be_visible()
    def choose(name): page.locator('[data-choice="'+name+'"]').click()
    def shot(name): page.screenshot(path='/tmp/phv-remote-ux-'+name+'.png')
    open_modal(f'http://phvalheim-lan.test:{address.port}')
    expect(page.locator('[data-nav="remote-access"] svg')).to_be_visible()
    expect(page.locator('[data-choice]')).to_have_count(3)
    expect(page.locator('#raTest')).not_to_be_visible()
    expect(page.locator('#raDisconnect')).not_to_be_visible()
    assert page.locator('.ra-modal').bounding_box()['height'] < 500
    assert 'SSH' not in page.locator('#remoteAccessOverlay').inner_text()
    shot('choices')
    choose('direct')
    expect(page.locator('#raLocalCheck')).to_contain_text('Internet access is not verified',timeout=15000)
    expect(page.locator('#raDirect')).to_contain_text('8081')
    assert not page.evaluate("fetch('remoteAccessAPI.php').then(r=>r.json()).then(d=>d.config.enabled)")
    shot('direct')
    page.locator('#raGamesTab').click()
    expect(page.locator('#raWorldInfo')).to_contain_text('25000–25001',timeout=15000)
    expect(page.locator('#raWorldInfo')).to_contain_text('Forward both UDP ports')
    assert page.locator('.ra-modal').bounding_box()['height'] < 800
    page.locator('summary',has_text='Set up UDP forwarding').click()
    expect(page.locator('#raCopyPorts')).to_be_visible()
    page.locator('#raRefreshGames').click()
    expect(page.locator('#raRefreshGames')).to_be_enabled(timeout=15000)
    expect(page.locator('#raCopyPorts')).to_be_visible()
    page.locator('summary',has_text='Set up UDP forwarding').click()
    expect(page.locator('#raNetworkTime')).to_contain_text('not tested')
    shot('games-direct')
    page.locator('#raWorld').select_option('Relay_world')
    expect(page.locator('#raWorldInfo')).to_contain_text('No inbound game port forwarding')
    expect(page.locator('#raWorldInfo')).to_contain_text('Saved settings')
    page.locator('#raRefreshGames').click()
    expect(page.locator('#raRefreshGames')).to_be_enabled(timeout=15000)
    expect(page.locator('#raWorld')).to_have_value('Relay_world')
    shot('games-relay')
    page.locator('#raWorld').select_option('Waiting_world')
    expect(page.locator('#raWorldInfo')).to_contain_text('no valid port pair')
    # A blocked HTTP refresh must never keep presenting old data as fresh.
    page.route('**/adminAPI.php?action=getRemoteNetwork',lambda r:r.abort())
    page.locator('#raRefreshGames').click()
    expect(page.locator('#raNetworkMessage')).to_contain_text('last successful check')
    page.unroute('**/adminAPI.php?action=getRemoteNetwork')
    page.locator('#raRefreshGames').click()
    expect(page.locator('#raNetworkMessage')).to_be_empty(timeout=15000)
    # Read-only endpoint excludes all settings and world secrets.
    snapshot=page.evaluate("fetch('adminAPI.php?action=getRemoteNetwork').then(r=>r.json())")
    assert snapshot['internetVerified'] is False and len(snapshot['worlds'])==3
    assert set(snapshot['worlds'][0])=={'name','mode','crossplay','savedCrossplay','basis','restartPending','port','queryPort'}
    assert 'steamApiKey' not in snapshot and 'password' not in str(snapshot)
    page.locator('#raClose').click()
    open_modal(fixture['admin'])
    choose('cloudflare')
    expect(page.locator('#raInstructions ol li')).to_have_count(2)
    expect(page.locator('#raInstructions')).to_contain_text('127.0.0.1:18080')
    expect(page.locator('#raUrlInput')).not_to_be_visible()
    shot('cloudflare-prepare')
    page.locator('#raNext').click()
    expect(page.locator('#raInstructions')).not_to_be_visible()
    expect(page.locator('#raConnect')).to_be_enabled(timeout=15000)
    shot('cloudflare-connect')
    assert page.locator('.ra-modal').bounding_box()['height'] < 650
    page.locator('#raUrlInput').fill('http://play.example.net')
    page.locator('#raToken').fill('A'*120)
    page.locator('#raConnect').click()
    expect(page.locator('#raMessage')).to_contain_text('HTTPS',timeout=10000)
    page.locator('#raUrlInput').fill('https://play.example.net')
    page.locator('#raSaveOptions summary').click()
    page.locator('#raSave').click()
    expect(page.locator('#raMessage')).to_contain_text('Settings saved',timeout=10000)
    expect(page.locator('#raToken')).to_have_value('')
    expect(page.locator('#raTest')).not_to_be_visible()
    page.locator('#raEdit').click()
    expect(page.locator('#raTokenSaved')).to_contain_text('saved')
    page.locator('#raConnect').click()
    expect(page.locator('#raChecks .ra-check').first).to_contain_text('Passed',timeout=15000)
    job=page.evaluate("fetch('remoteAccessAPI.php').then(r=>r.json()).then(d=>d.test.id)")
    page.locator('#raClose').click()
    page.locator('.nav-item',has_text='Remote Access').click()
    expect(page.locator('#raCancel')).to_be_visible(timeout=10000)
    assert page.evaluate("fetch('remoteAccessAPI.php').then(r=>r.json()).then(d=>d.test.id)")==job
    page.locator('#raCancel').click()
    expect(page.locator('#raCancel')).not_to_be_visible(timeout=10000)
    # Direct guidance can coexist with a provider without changing its state.
    page.locator('#raBack').click(); choose('direct')
    assert page.evaluate("fetch('remoteAccessAPI.php').then(r=>r.json()).then(d=>d.config.enabled)")
    page.locator('#raBack').click(); choose('cloudflare')
    page.locator('#raDisconnect').click()
    expect(page.locator('#raSummary')).to_contain_text('Off',timeout=10000)
    page.locator('#raBack').click(); choose('tailscale')
    expect(page.locator('#raAudience')).to_contain_text('Players need Tailscale')
    expect(page.locator('#raConnect')).not_to_be_visible()
    page.locator('#raSignIn').click()
    expect(page.locator('#raSignInLink a')).to_be_visible(timeout=60000)
    assert page.locator('#raSignInLink a').get_attribute('href').startswith('https://login.tailscale.')
    shot('tailscale-signin')
    page.locator('#raClose').click()
    page.locator('.nav-item',has_text='Remote Access').click()
    expect(page.locator('#raSignInLink a')).to_be_visible(timeout=10000)
    page.locator('#raDisconnect').click()
    expect(page.locator('#raBack')).to_be_enabled(timeout=15000)
    page.locator('#raBack').click(); choose('tailscale')
    page.locator('summary',has_text='Remove saved connection').click()
    page.locator('#raForget').click()
    expect(page.locator('#raChoices')).to_be_visible(timeout=15000)
    # Outages do not disable independent direct/game guidance.
    choose('cloudflare'); page.locator('#raNext').click()
    subprocess.run(['docker','exec',fixture['app'],'supervisorctl','stop','remote-access'],check=True,capture_output=True)
    expect(page.locator('#raMessage')).to_contain_text('service',timeout=20000)
    expect(page.locator('#raConnect')).to_be_disabled()
    page.locator('#raGamesTab').click(); page.locator('#raRefreshGames').click()
    expect(page.locator('#raRefreshGames')).to_be_enabled(timeout=15000)
    expect(page.locator('#raWorldInfo')).to_contain_text('Direct UDP')
    subprocess.run(['docker','exec',fixture['app'],'supervisorctl','start','remote-access'],check=True,capture_output=True)
    page.locator('#raWebsiteTab').click()
    expect(page.locator('#raConnect')).to_be_enabled(timeout=20000)
    page.route('**/remoteAccessAPI.php',lambda route:route.abort())
    expect(page.locator('#raConnect')).to_be_disabled(timeout=20000)
    expect(page.locator('#raMessage')).not_to_be_empty()
    page.unroute('**/remoteAccessAPI.php')
    expect(page.locator('#raConnect')).to_be_enabled(timeout=20000)
    # Initial choice and each setup panel fit narrow screens without horizontal scroll.
    for width in [768,390]:
        page.set_viewport_size({'width':width,'height':844})
        for method in ['direct','cloudflare','tailscale']:
            page.locator('#raBack').click(); choose(method)
            assert page.locator('.ra-modal').evaluate('(e)=>e.scrollWidth<=e.clientWidth+1')
        shot('narrow-'+str(width))
    page.locator('#raClose').focus(); page.keyboard.press('Shift+Tab')
    assert page.evaluate("document.getElementById('remoteAccessOverlay').contains(document.activeElement)")
    page.locator('#raClose').click()
    expect(page.locator('.nav-item',has_text='Remote Access')).to_be_focused()
    assert not errors,errors
    assert not exposed,'Saved token appeared in GET response'
    print('PASS: actual HTTP hostname/IP wizard, icon, compact choices/steps, direct website, real world ports/crossplay, read-only schema, stale network recovery, validation/masking, real provider tests/enrollment, reopen/cancel/disconnect/forget, independent guidance during connector outages, 3 viewport widths, keyboard focus, zero page errors.')
    browser.close()
