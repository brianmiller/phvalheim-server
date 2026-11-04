#!/usr/bin/env python3
# requires: manual
"""Real Chromium UI with controlled provider snapshots; no provider accounts needed."""
import json
from pathlib import Path
import time
from playwright.sync_api import sync_playwright, expect

ROOT = Path(__file__).resolve().parents[1]
source = (ROOT/'container/nginx/www/js/remoteAccess.js').read_text()
with sync_playwright() as p:
    browser = p.chromium.launch(headless=True)
    page = browser.new_page(viewport={'width':1100,'height':850})
    errors = []
    page.on('pageerror', lambda e: errors.append(str(e)))
    state = {
        'success':True, 'available':True, 'csrf':'fixture', 'enrolling':False,
        'config':{'provider':'tailscale', 'enabled':True, 'url':'https://fixture.example.ts.net', 'revision':1},
        'observed':{'state':'HTTPS approval required', 'details':{'backend':'Running'}},
        'authUrl':'https://login.tailscale.com/f/serve?node=fixture',
        'error':'Enable HTTPS in Tailscale using the approval link below.', 'events':[], 'test':None,
    }
    pending = []
    hold = False
    def reply(route):
        state['now'] = time.time(); state['observed']['time'] = time.time()
        route.fulfill(json=state)
    def api(route):
        assert route.request.method == 'GET', 'Provider navigation must not mutate configuration'
        if hold: pending.append(route)
        else: reply(route)
    page.route('http://fixture.test/', lambda r:r.fulfill(body='<style>[hidden]{display:none!important}.modal-overlay:not(.show){display:none}</style><button onclick="showRemoteAccessModal()">Remote Access</button>'))
    page.route('**/remoteAccessAPI.php', api)
    def load():
        page.goto('http://fixture.test/'); page.add_script_tag(content=source)
        page.get_by_role('button',name='Remote Access',exact=True).click()
    def cf_blocked():
        expect(page.locator('#raMethodTitle')).to_contain_text('Cloudflare')
        expect(page.locator('#raSwitchNotice')).to_be_visible()
        expect(page.locator('#raSwitchText')).to_contain_text('Tailscale is active')
        expect(page.locator('#raConnection')).not_to_be_visible()
        expect(page.locator('#raUrl')).to_be_empty()
        expect(page.locator('#raUrlInput')).to_have_value('')
        expect(page.locator('#raApproval')).to_be_empty()
        expect(page.locator('#raMessage')).to_be_empty()
        expect(page.locator('#raConnect')).not_to_be_visible()
    load()
    expect(page.locator('#raMethodTitle')).to_contain_text('Tailscale')
    expect(page.locator('#raApproval a')).to_have_text('Enable HTTPS in Tailscale')
    expect(page.locator('#raApproval a')).to_be_visible()
    expect(page.locator('#raUrl a')).to_have_text(state['config']['url'])
    page.locator('#raBack').click(); page.locator('[data-choice="cloudflare"]').click()
    cf_blocked()
    page.locator('#raViewActive').click()
    expect(page.locator('#raApproval a')).to_be_visible()
    page.locator('#raClose').click(); page.get_by_role('button',name='Remote Access',exact=True).click()
    expect(page.locator('#raApproval a')).to_be_visible()
    # Account approval is simulated in the response, never performed by this test.
    state['authUrl']=''; state['error']=''; state['observed']['state']='Connected'
    expect(page.locator('#raSummary')).to_contain_text('Connected',timeout=10000)
    expect(page.locator('#raApproval')).to_be_empty()
    expect(page.locator('#raMessage')).to_be_empty()
    # A user's choice made before the initial response must not be overwritten.
    hold=True; load()
    page.locator('[data-choice="cloudflare"]').click()
    assert pending
    reply(pending.pop()); hold=False
    cf_blocked()
    # The same-provider early click must still open its configured connection.
    hold=True; load()
    page.locator('[data-choice="tailscale"]').click()
    assert pending
    reply(pending.pop()); hold=False
    expect(page.locator('#raSummary')).to_be_visible()
    expect(page.locator('#raMethodTitle')).to_contain_text('Tailscale')
    # Reverse direction: Cloudflare state must not appear in Tailscale.
    state['config'].update(provider='cloudflare',url='https://play.example.net')
    state['observed']['details']={}; load()
    expect(page.locator('#raUrl a')).to_have_text('https://play.example.net')
    page.locator('#raBack').click(); page.locator('[data-choice="tailscale"]').click()
    expect(page.locator('#raSwitchText')).to_contain_text('Cloudflare is active')
    expect(page.locator('#raConnection')).not_to_be_visible()
    expect(page.locator('#raUrl')).to_be_empty()
    page.locator('#raViewActive').click()
    expect(page.locator('#raUrl a')).to_be_visible()
    assert not errors, errors
    browser.close()
print('PASS: provider isolation both directions, actionable HTTPS approval, reopen, automatic recovery, initial-status races, no navigation writes, zero JavaScript errors.')
