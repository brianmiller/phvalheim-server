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
with sync_playwright() as p:
    browser=p.chromium.launch(headless=True)
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
    page.goto(fixture['admin'],wait_until='domcontentloaded')
    page.locator('.nav-item',has_text='Remote Access').click()
    expect(page.locator('#raSummary')).to_contain_text('No provider configured',timeout=15000)
    expect(page.locator('#raInstructions ol li')).to_have_count(3)
    expect(page.locator('#raTest')).to_be_disabled()
    page.locator('#raUrlInput').fill('http://play.example.net')
    page.locator('#raToken').fill('A'*120)
    page.locator('#raSave').click()
    expect(page.locator('#raMessage')).to_contain_text('HTTPS',timeout=10000)
    page.locator('#raUrlInput').fill('https://play.example.net')
    page.locator('#raSave').click()
    expect(page.locator('#raMessage')).to_contain_text('Settings saved',timeout=10000)
    expect(page.locator('#raToken')).to_have_value('')
    expect(page.locator('#raTokenSaved')).to_contain_text('token saved')
    page.locator('#raConnect').click()
    expect(page.locator('#raChecks .ra-check').first).to_contain_text('Passed',timeout=15000)
    job=page.evaluate("fetch('remoteAccessAPI.php').then(r=>r.json()).then(d=>d.test.id)")
    page.locator('#raClose').click()
    expect(page.locator('#remoteAccessOverlay')).not_to_have_class(__import__('re').compile('show'))
    page.locator('.nav-item',has_text='Remote Access').click()
    expect(page.locator('#raCancel')).to_be_enabled(timeout=10000)
    assert page.evaluate("fetch('remoteAccessAPI.php').then(r=>r.json()).then(d=>d.test.id)")==job
    page.locator('#raCancel').click()
    expect(page.locator('#raTestSource')).to_contain_text('Cancelled',timeout=10000)
    expect(page.locator('#raDisconnect')).to_be_enabled()
    page.locator('#raDisconnect').click()
    expect(page.locator('#raSummary')).to_contain_text('Off',timeout=10000)
    page.set_viewport_size({'width':390,'height':844})
    assert page.locator('.ra-modal').evaluate('(e)=>e.scrollWidth<=e.clientWidth+1')
    page.locator('#raClose').focus()
    page.keyboard.press('Shift+Tab')
    assert page.evaluate("document.getElementById('remoteAccessOverlay').contains(document.activeElement)")
    page.locator('#raProvider').select_option('tailscale')
    expect(page.locator('#raAudience')).to_contain_text('permitted tailnet')
    page.locator('#raSave').click()
    expect(page.locator('#raMessage')).to_contain_text('Settings saved',timeout=10000)
    expect(page.locator('#raConnect')).to_be_disabled()
    page.locator('#raSignIn').click()
    expect(page.locator('#raSignInLink a')).to_be_visible(timeout=60000)
    assert page.locator('#raSignInLink a').get_attribute('href').startswith('https://login.tailscale.')
    expect(page.locator('#raConnect')).to_be_disabled()
    page.locator('#raDisconnect').click()
    expect(page.locator('#raSummary')).to_contain_text('Off',timeout=15000)
    page.locator('summary',has_text='Remove this connection').click()
    page.locator('#raForget').click()
    expect(page.locator('#raSummary')).to_contain_text('No provider configured',timeout=15000)
    subprocess.run(['docker','stop',fixture['connector']],check=True,capture_output=True)
    expect(page.locator('#raSummary')).to_contain_text('unavailable',timeout=20000)
    expect(page.locator('#raConnect')).to_be_disabled()
    subprocess.run(['docker','start',fixture['connector']],check=True,capture_output=True)
    expect(page.locator('#raSummary')).to_contain_text('No provider configured',timeout=20000)
    expect(page.locator('#raMessage')).not_to_contain_text('not installed or is stopped')
    page.set_viewport_size({'width':1440,'height':1000})
    page.locator('.ra-modal .mods-modal-body').evaluate('(e)=>e.scrollTop=0')
    page.screenshot(path='/tmp/phv-remote-access-modal.png',full_page=False)
    page.locator('#raClose').click()
    expect(page.locator('.nav-item',has_text='Remote Access')).to_be_focused()
    assert not errors,errors
    assert not exposed,'Saved token appeared in GET response'
    print('PASS: real modal setup, validation, secret masking, live checks, reopen/cancel/disconnect, Tailscale sign-in, scope labels, narrow layout, keyboard focus, connector outage/recovery, zero page errors.')
    browser.close()
