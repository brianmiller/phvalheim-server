#!/usr/bin/env python3
# requires: container
"""Measure real PHP-rendered selectors with many worlds, not a synthetic card.

Use fixture_presentation.py and set PHV_PRESENTATION_FIXTURE to its JSON file.
This replaces worlds only in that isolated disposable fixture.
"""
import json
import os
import subprocess
from pathlib import Path

from playwright.sync_api import sync_playwright, expect
from fixture_analytics import assert_isolated

fixture = json.loads(Path(os.environ['PHV_PRESENTATION_FIXTURE']).read_text())
assert_isolated(json.loads(subprocess.check_output(['docker', 'inspect', fixture['name']]))[0])


def sql(statement):
    subprocess.run(['docker', 'exec', fixture['name'], 'mysql', '-N', '-uroot',
                    'phvalheim', '-e', statement], check=True, capture_output=True)


def seed(count):
    sql('DELETE FROM world_mods; DELETE FROM worlds;')
    if count:
        names = ['Midgard'] + [f'WorldWithAnExtraLongName{i:02}' for i in range(1, count)]
        rows = [f"('{name}',{2456 + i * 2},1,'stopped','ready','TestSeed',1)"
                for i, name in enumerate(names)]
        sql('INSERT INTO worlds (name,port,public,mode,status,seed,vanilla) VALUES ' + ','.join(rows))


with sync_playwright() as playwright:
    browser = playwright.chromium.launch()
    page = browser.new_page()
    errors = []
    page.on('pageerror', lambda error: errors.append(str(error)))

    def load(theme, width):
        response = page.request.post(fixture['admin'] + '/adminAPI.php?action=saveServerSettings',
                                     data={'publicTheme': theme})
        assert response.json()['success']
        page.set_viewport_size({'width': width, 'height': 900})
        page.goto(fixture['public'] + '/authenticated.php', wait_until='networkidle')
        page.evaluate('acceptCookies()')
        expect(page.locator('body')).to_have_attribute('data-public-theme', theme)

    cases = 0
    # Start with the reported desktop failure so the old stylesheet fails immediately.
    for count in [30, 0, 1, 3, 8, 60]:
        seed(count)
        for width in [2135, 1440, 1024, 701, 700, 390, 320]:
            load('gate', width)
            buttons = page.locator('.theme-world-choice')
            expect(buttons).to_have_count(count)
            assert page.evaluate('document.documentElement.scrollWidth <= innerWidth'), (count, width, 'page overflow')
            if count:
                metrics = buttons.evaluate_all('''elements => elements.map(e => {
                    const r = e.getBoundingClientRect(), label = e.querySelector('strong').getBoundingClientRect();
                    return {x:r.x, y:r.y, w:r.width, h:r.height, labelWidth:label.width,
                            textFits:label.left>=r.left && label.right<=r.right && label.bottom<=r.bottom};
                })''')
                assert all(m['labelWidth'] >= (60 if width <= 700 else 140) for m in metrics), (count, width, 'crushed labels', metrics[0])
                assert all(m['h'] < 150 and m['textFits'] for m in metrics), (count, width, 'overflowing/tall choices')
                if count >= 30:
                    assert len({round(m['y']) for m in metrics}) > 1, (count, width, 'choices must wrap')
                nav = page.locator('.theme-world-nav').bounding_box()
                panel = page.locator('.catbox:visible').bounding_box()
                assert panel['y'] + panel['height'] <= nav['y'] + 1, 'Gate chooser must remain below selected world'
                buttons.last.click()
                expect(buttons.last).to_have_attribute('aria-current', 'true')
                expect(page.locator('.catbox:visible')).to_have_count(1)
                expected_name = buttons.last.locator('strong').inner_text()
                expect(page.locator('.catbox:visible .card_worldName')).to_have_text(expected_name)
                buttons.first.focus()
                page.keyboard.press('Enter')
                expect(buttons.first).to_have_attribute('aria-current', 'true')
                expect(page.locator('.catbox:visible .card_worldName')).to_have_text('Midgard')
                # A normal status poll must preserve selection and all choices.
                page.evaluate("fetch('api.php?mode=getMyWorldsStatus').then(r=>r.json()).then(d=>updateWorldCards(d.worlds))")
                expect(buttons).to_have_count(count)
                expect(buttons.first).to_have_attribute('aria-current', 'true')
                if count == 30 and width in [2135, 390]:
                    page.screenshot(path=f'/tmp/phv-gate-world-count-{width}.png', full_page=True)
            cases += 1
    # The shared stylesheet must retain the other themes' navigation behavior.
    seed(30)
    for theme in ['classic', 'reforged', 'ember']:
        for width in [1440, 390]:
            load(theme, width)
            expect(page.locator('.catbox')).to_have_count(30)
            if theme == 'classic':
                expect(page.locator('#publicPortal')).to_have_count(0)
            else:
                buttons = page.locator('.theme-world-choice')
                expect(buttons).to_have_count(30)
                buttons.last.click()
                expect(page.locator('.catbox:visible')).to_have_count(1)
                expect(page.locator('.catbox:visible .card_worldName')).to_have_text(buttons.last.locator('strong').inner_text())
                assert page.evaluate('document.documentElement.scrollWidth <= innerWidth'), theme
            cases += 1
    assert not errors, errors
    browser.close()
print(f'PASS: {cases} real HTTP/browser cases; Gate counts 0/1/3/8/30/60 at seven widths, long names, selection, keyboard, polling; other-theme smoke checks')
