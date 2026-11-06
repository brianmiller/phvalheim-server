#!/usr/bin/env python3
# requires: manual — isolated presentation fixture, real settings API and Chromium
"""Settings route: real persistence, drafts/errors, responsive navigation and retained controls.
Run with PHV_PRESENTATION_FIXTURE pointing to fixture_presentation.py output.
"""
import json, os, subprocess
from pathlib import Path
from playwright.sync_api import sync_playwright, expect
from fixture_analytics import assert_isolated
f = json.loads(Path(os.environ['PHV_PRESENTATION_FIXTURE']).read_text())
assert_isolated(json.loads(subprocess.check_output(['docker','inspect',f['name']]))[0])
with sync_playwright() as p:
    browser = p.chromium.launch()
    page = browser.new_page(viewport={'width':1440,'height':1000})
    errors = []
    page.on('pageerror',lambda e:errors.append(str(e)))
    def api(action, data=None):
        url = f['admin']+'/adminAPI.php?action='+action
        return (page.request.get(url) if data is None else page.request.post(url,data=data)).json()
    original = api('getServerSettings')['settings']
    def section(name):
        page.get_by_role('button',name=name,exact=True).click()
    def ready():
        expect(page.locator('.settings-layout')).to_be_visible()
    def save():
        page.locator('#ssSubmitBtn').click()
        expect(page.locator('#ssStatus')).to_have_text('Settings saved.')
        expect(page.locator('#ssSubmitBtn')).to_be_disabled()
    try:
        # Odd durations and byte sizes must survive unrelated saves exactly.
        assert api('saveServerSettings',{'sessionTimeout':90123,'maxLogSize':1234567,'autoUpdateMode':0,'timezone':'America/Detroit','backupRetainAllHours':0})['success']
        page.goto(f['admin'],wait_until='domcontentloaded')
        page.get_by_role('link',name='Server Settings',exact=True).click()
        ready(); assert page.url.endswith('/settings.php')
        expect(page.locator('#settings-general')).to_be_visible()
        assert page.locator('.settings-panel:visible').count() == 1
        expect(page.locator('#ssSubmitBtn')).to_be_disabled()
        section('Players & Steam')
        expect(page.locator('#ss-sessionTimeout-display')).to_have_value('90123')
        expect(page.get_by_role('combobox',name='Session duration unit')).to_have_value('1')
        section('Appearance')
        expect(page.locator('.settings-theme-card')).to_have_count(8)
        page.locator('[data-field="loginTheme"] [data-theme="ember"]').focus();page.keyboard.press('Enter')
        page.locator('[data-field="publicTheme"] [data-theme="gate"]').click()
        section('General');section('Appearance')
        expect(page.locator('[data-field="loginTheme"] [data-theme="ember"]')).to_have_attribute('aria-pressed','true')
        save()
        stored = api('getServerSettings')['settings']
        assert stored['loginTheme']=='ember' and stored['publicTheme']=='gate'
        assert int(stored['sessionTimeout'])==90123 and int(stored['maxLogSize'])==1234567
        assert stored['timezone']=='America/Detroit' and int(stored['backupRetainAllHours'])==0
        # Provider actions are still present and use the shared wizard.
        section('Hugin Setup');page.get_by_role('button',name='+ Add AI provider',exact=True).click()
        expect(page.locator('#aiWizardOverlay')).to_have_class('mods-modal-overlay show')
        page.evaluate('aiWizClose()')
        # Keyboard selection, exact unit conversion, dirty undo and Discard across categories.
        section('Players & Steam')
        page.locator('#ss-sessionTimeout-display').fill('30')
        page.get_by_role('combobox',name='Session duration unit').select_option('86400')
        section('Advanced');page.locator('#ss-maxLogSize-display').fill('2')
        page.get_by_role('combobox',name='Log size unit').select_option('1000000')
        save(); stored=api('getServerSettings')['settings']
        assert int(stored['sessionTimeout'])==2592000 and int(stored['maxLogSize'])==2000000
        section('Players & Steam');page.locator('#ss-sessionTimeout-display').fill('')
        page.locator('#ssSubmitBtn').click()
        assert not page.locator('#ss-sessionTimeout-display').evaluate('(e)=>e.checkValidity()')
        expect(page.locator('#ss-sessionTimeout-display')).to_be_focused()
        page.locator('#settingsDiscard').click()
        expect(page.locator('#ss-sessionTimeout-display')).to_have_value('30')
        page.locator('#ss-disableSteamOverlay').check()
        section('Appearance');page.locator('[data-field="loginTheme"] [data-theme="classic"]').click()
        page.locator('#settingsDiscard').click()
        expect(page.locator('[data-field="loginTheme"] [data-theme="ember"]')).to_have_attribute('aria-pressed','true')
        expect(page.locator('#ss-disableSteamOverlay')).not_to_be_checked()
        # Search expands advanced options and finds fields in otherwise hidden update controls.
        page.locator('#settingsSearch').fill('compression level')
        expect(page.locator('#ss-backupCompressionLevel')).to_be_visible()
        page.locator('#settingsSearch').fill('quiet')
        expect(page.locator('#ss-autoUpdateIdleMinutes')).to_be_visible()
        page.locator('#settingsSearch').fill('no-such-setting')
        expect(page.locator('.settings-empty')).to_be_visible()
        section('Updates & Mods')
        expect(page.locator('#ss-autoUpdateIdleMinutes')).not_to_be_visible()
        page.locator('#ss-autoUpdateMode').select_option('1')
        expect(page.locator('#ss-autoUpdateIdleMinutes')).to_be_visible()
        page.locator('#ss-autoUpdateWindowStart').fill('0');page.locator('#ss-autoUpdateWindowHours').fill('2')
        # Rejected and failed requests retain all unsaved values and re-enable Save.
        page.route('**/adminAPI.php?action=saveServerSettings',lambda route:route.fulfill(json={'success':False,'error':'Fixture rejected save'}))
        page.locator('#ssSubmitBtn').click();expect(page.locator('#ssStatus')).to_have_text('Fixture rejected save')
        expect(page.locator('#ss-autoUpdateWindowStart')).to_have_value('0')
        expect(page.locator('#ssSubmitBtn')).to_be_enabled()
        page.unroute('**/adminAPI.php?action=saveServerSettings')
        page.route('**/adminAPI.php?action=saveServerSettings',lambda route:route.abort())
        page.locator('#ssSubmitBtn').click();expect(page.locator('#ssStatus')).to_have_text('Network error.')
        expect(page.locator('#ssSubmitBtn')).to_be_enabled()
        page.unroute('**/adminAPI.php?action=saveServerSettings')
        save();stored=api('getServerSettings')['settings'];assert int(stored['autoUpdateWindowStart'])==0
        # Pending changes protect navigation; cancelled navigation preserves the draft.
        section('General');page.locator('#ss-gameDNS').fill('new-fixture.invalid')
        dialogs=[]
        def dismiss(dialog): dialogs.append(dialog.message);dialog.dismiss()
        page.on('dialog',dismiss)
        page.get_by_role('link',name='Dashboard',exact=True).click()
        assert dialogs and '/settings.php' in page.url
        expect(page.locator('#ss-gameDNS')).to_have_value('new-fixture.invalid')
        page.remove_listener('dialog',dismiss)
        page.locator('#ssSubmitBtn').click()
        expect(page.locator('#gameDnsNoticeOverlay')).to_have_class('mods-modal-overlay show')
        assert api('getServerSettings')['settings']['gameDNS']=='new-fixture.invalid'
        page.evaluate("document.getElementById('gameDnsNoticeOverlay').classList.remove('show')")
        assert not page.evaluate('serverSettingsPage.isDirty()')
        # Verify route refresh and deep link; no duplicate IDs or dashboard polling errors.
        page.goto(f['admin']+'/settings.php#appearance',wait_until='domcontentloaded');ready()
        expect(page.locator('#settings-appearance')).to_be_visible()
        ids=page.locator('[id]').evaluate_all('(es)=>es.map(e=>e.id)');assert len(ids)==len(set(ids)), 'duplicate DOM IDs'
        for width in [1440,1024,768,390,320]:
            page.set_viewport_size({'width':width,'height':900})
            if width<=760: page.locator('#settingsCategory').select_option('appearance')
            expect(page.locator('#settings-appearance')).to_be_visible()
            assert page.evaluate('document.documentElement.scrollWidth<=innerWidth'), f'overflow at {width}'
            page.screenshot(path=f'/tmp/phv-settings-{width}.png',full_page=True)
        page.locator('#settingsCategory').select_option('backups')
        if not original['backupPathMounted']:
            expect(page.locator('#ss-backupIntervalMinutes')).to_be_disabled()
        # Managed range response must remain read-only and display conflicts.
        page.route('**/adminAPI.php?action=getServerSettings',lambda route:route.fulfill(json={'success':True,'settings':dict(stored,backupPathMounted=True,gamePortRange={'enabled':True,'start':25000,'end':25009,'conflicts':[{'name':'OldWorld','port':24000}]})}))
        page.goto(f['admin']+'/settings.php?managed-fixture=1#general',wait_until='domcontentloaded');ready()
        expect(page.locator('#ss-basePort')).to_have_attribute('readonly','')
        expect(page.locator('[data-field="basePort"]')).to_contain_text('OldWorld')
        page.locator('#settingsCategory').select_option('backups')
        expect(page.locator('#ss-backupIntervalMinutes')).to_be_enabled()
        assert not errors,errors
        print('PASS: route, categories, theme persistence, exact units, drafts/undo/discard, invalid input, server/network errors, midnight, DNS notice, leave guard, search/disclosures, provider wizard, managed ports, backup restriction, five widths, no JS errors')
    finally:
        page.unroute('**/adminAPI.php?action=getServerSettings')
        api('saveServerSettings',original)
        browser.close()
