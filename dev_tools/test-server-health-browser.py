#!/usr/bin/env python3
# requires: manual
"""Health banner/action test against an analytics-isolated disposable container."""
import json
import os
import subprocess
from playwright.sync_api import sync_playwright, expect
from fixture_analytics import assert_isolated

name = os.environ['PHV_HEALTH_TEST_CONTAINER']
base = os.environ['PHV_HEALTH_TEST_URL']
inspection = json.loads(subprocess.check_output(['docker', 'inspect', name]))[0]
assert_isolated(inspection)
assert name.startswith('phv'), 'Use a disposable PhValheim fixture'
def service(action):
    subprocess.run(['docker','exec',name,'supervisorctl',action,'remote-access'],check=True,capture_output=True)
with sync_playwright() as p:
    browser = p.chromium.launch()
    page = browser.new_page(viewport={'width':1100,'height':850})
    errors=[];page.on('pageerror',lambda e:errors.append(str(e)))
    try:
        page.goto(base,wait_until='domcontentloaded')
        page.evaluate('showServerHealthModal()')
        expect(page.locator('#serverHealthBody')).to_contain_text('Remote Access')
        expect(page.locator('#serverHealthBody')).not_to_contain_text('Usage analytics')
        page.evaluate('closeServerHealthModal()')
        service('stop')
        page.evaluate('fetchServerHealth(true)')
        row=page.locator('#serverHealthStrip .health-row').filter(has_text='Remote Access')
        expect(row).to_contain_text('service is unavailable')
        row.get_by_role('button',name='Open Remote Access').click()
        expect(page.locator('#remoteAccessOverlay')).to_be_visible()
        expect(page.locator('#raMessage')).to_contain_text('unavailable',timeout=15000)
        page.locator('#raClose').click()
        service('start')
        page.wait_for_timeout(2500)
        page.evaluate('fetchServerHealth(true)')
        expect(row).to_have_count(0)
        page.evaluate('showServerHealthModal()')
        expect(page.locator('#serverHealthBody')).to_contain_text('No website provider configured')
        expect(page.locator('#serverHealthBody')).not_to_contain_text('Usage analytics')
        page.screenshot(path='/tmp/phv262-health-browser.png')
        assert not errors, errors
        print('PASS: health entry, stopped-service banner, action opens Remote Access, recovery clears alert, analytics absent, no JS errors')
    finally:
        service('start')
        browser.close()
