#!/usr/bin/env python3
# requires: manual
"""Review a kept Portainer integration fixture through its visible browser UI.

Usage: python3 dev_tools/portainer-browser.py /tmp/phv-portainer-<run>/evidence.json
Writes browser-evidence.json and screenshots beside the integration evidence.
Does not deploy, restart, or modify stacks, or reuse the fixture's API JWT.
"""

import argparse
from html.parser import HTMLParser
import json
from pathlib import Path
import re
import sys
from urllib.parse import parse_qs, urlsplit, urlunsplit


ROOT = Path(__file__).resolve().parents[1]
INSTALL_URL = 'https://brianmiller.github.io/phvalheim-server/start/install/'


class NoteText(HTMLParser):
    def __init__(self, note):
        super().__init__()
        self.parts = []
        self.feed(note)

    def handle_data(self, data):
        self.parts.append(data)


class Review:
    def __init__(self, path, evidence, credentials):
        self.path = path.parent / 'browser-evidence.json'
        self.secrets = [str(credentials.get(key, ''))
                        for key in ('username', 'password', 'jwt')]
        self.result = {
            'run': evidence['run'], 'integration_evidence': str(path),
            'status': 'running', 'checks': [], 'screenshots': {},
            'page_errors': [], 'console_errors': [], 'network_errors': [],
            'optional_network_notes': [], 'public_portal': {'status': 'not_requested'},
            'authentication': {'authenticated': False, 'phase': None},
            'locale_fallback_responses': [],
        }
        self.phase = 'browser startup'

    def safe(self, message):
        text = str(message)
        for secret in self.secrets:
            if secret:
                text = text.replace(secret, '[redacted]')
        text = re.sub(r'eyJ[A-Za-z0-9_-]+\.[A-Za-z0-9_-]+\.[A-Za-z0-9_-]+',
                      '[redacted JWT]', text)
        return text[:2000]

    def check(self, name, assertion):
        self.phase = name
        try:
            assertion()
        except Exception:
            self.result['checks'].append({'name': name, 'passed': False})
            raise
        self.result['checks'].append({'name': name, 'passed': True})

    def screenshot(self, page, name):
        path = self.path.parent / (name + '.png')
        page.screenshot(path=str(path), full_page=True, timeout=15000,
                        mask=[page.get_by_placeholder('Enter your username', exact=True),
                              page.locator('input[type="password"]')])
        self.result['screenshots'][name] = str(path)

    def watch(self, page, base_url, optional=False):
        origin = urlsplit(base_url).netloc
        parsed_base = urlsplit(base_url)
        users_me_url = urlunsplit((parsed_base.scheme, origin, '/api/users/me', '', ''))
        locale_url = urlunsplit((parsed_base.scheme, origin, '/locales/en-US/translation.json', '', ''))
        fallback_url = urlunsplit((parsed_base.scheme, origin, '/locales/en/translation.json', '', ''))
        fallback_loaded = False
        locale_errors = []

        def baseline_note(entry, url):
            if optional:
                return None
            if (url == users_me_url and entry.get('status') == 401
                    and not entry['authenticated'] and entry['phase'] == 'visible form login'):
                return 'Portainer startup user probe before visible form login succeeds'
            if url == locale_url and entry.get('status') == 404 and fallback_loaded:
                return 'en-US locale missing; English fallback returned HTTP 200 at ' + fallback_url
            return None

        def network(url, detail, status=None):
            parsed = urlsplit(url)
            clean_url = urlunsplit((parsed.scheme, parsed.netloc, parsed.path, '', ''))
            key = ('optional_network_notes' if optional or parsed.netloc != origin
                   or 'favicon' in parsed.path.lower() or detail == 'net::ERR_ABORTED'
                   else 'network_errors')
            entry = {'url': self.safe(clean_url), 'detail': self.safe(detail),
                     'phase': self.phase,
                     'authenticated': self.result['authentication']['authenticated']}
            if status is not None:
                entry['status'] = status
            reason = baseline_note(entry, url)
            if reason:
                key = 'optional_network_notes'
                entry['reason'] = self.safe(reason)
            self.result[key].append(entry)
            if url == locale_url and status == 404 and key == 'network_errors':
                locale_errors.append(entry)

        def response(response):
            nonlocal fallback_loaded
            if not optional and response.url == fallback_url and response.status == 200:
                fallback_loaded = True
                self.result['locale_fallback_responses'].append(
                    {'url': self.safe(fallback_url), 'status': 200, 'phase': self.phase,
                     'authenticated': self.result['authentication']['authenticated']})
                for entry in locale_errors:
                    entry['reason'] = self.safe(baseline_note(entry, locale_url))
                    self.result['network_errors'].remove(entry)
                    self.result['optional_network_notes'].append(entry)
                locale_errors.clear()
            if response.status >= 400:
                network(response.url, 'HTTP ' + str(response.status), response.status)

        page.on('pageerror', lambda error: self.result['page_errors'].append(
            {'page': 'public_portal' if optional else 'portainer',
             'message': self.safe(error)}))
        page.on('requestfailed', lambda request: network(request.url, request.failure))
        page.on('response', response)

        def console(message):
            if message.type != 'error':
                return
            text = message.text
            if text.startswith('Failed to load resource:'):
                status = {'Failed to load resource: the server responded with a status of 401 (Unauthorized)': 401,
                          'Failed to load resource: the server responded with a status of 404 (Not Found)': 404}.get(text)
                network(message.location.get('url', base_url), text, status)
            else:
                self.result['console_errors'].append(
                    {'page': 'public_portal' if optional else 'portainer',
                     'message': self.safe(text)})

        page.on('console', console)

    def save(self):
        checks = self.result['checks']
        self.result['totals'] = {'passed': sum(c['passed'] for c in checks),
                                 'failed': sum(not c['passed'] for c in checks)}
        with self.path.open('w') as output:
            json.dump(self.result, output, indent=2)
            output.write('\n')


def require(condition, message):
    if not condition:
        raise AssertionError(message)


def login(page, evidence, credentials, review, expect):
    review.phase = 'visible form login'
    page.goto(evidence['portainer_url'], wait_until='domcontentloaded')
    username = page.get_by_placeholder('Enter your username', exact=True)
    password = page.get_by_placeholder('Enter your password', exact=True)
    expect(username).to_be_visible()
    expect(password).to_be_visible()
    username.fill(credentials['username'])
    password.fill(credentials['password'])
    page.get_by_role('button', name=re.compile(r'^(log in|login|sign in)$', re.I)).click()
    review.check('visible form login succeeds', lambda: expect(password).to_be_hidden())
    review.result['authentication'] = {'authenticated': True, 'phase': review.phase}


def select_environment(page, evidence, review, expect):
    review.phase = 'select isolated Docker environment'
    endpoint = page.get_by_text(evidence['run'], exact=True)
    expect(endpoint).to_have_count(1)
    endpoint.click()
    endpoint_id = str(evidence['endpoint_id'])
    review.check('selected environment matches evidence endpoint', lambda: expect(page).to_have_url(
        re.compile(r'/'+re.escape(endpoint_id)+r'/(?:docker|dashboard)(?:[/?#]|$)')))


def review_template(page, template, review, expect):
    review.phase = 'open App Templates'
    page.get_by_role('link', name='Templates', exact=True).click()
    page.get_by_role('link', name='Application', exact=True).click()
    item = page.get_by_role('listitem', name=template['title'], exact=True)
    expect(item).to_be_visible()
    review.check('template description is visible', lambda: expect(
        item.get_by_text(template['description'], exact=True)).to_be_visible())
    logo = item.locator('img[src=' + json.dumps(template['logo']) + ']')
    review.check('template logo is visible', lambda: expect(logo).to_be_visible())
    review.phase = 'template logo finishes loading'
    page.wait_for_function('src => Array.from(document.images).some(img => '
                           'img.src === src && img.complete && img.naturalWidth > 0)',
                           arg=template['logo'])
    review.check('template logo loaded successfully', lambda: require(
        logo.evaluate('(img) => img.complete && img.naturalWidth > 0'), 'Logo did not load'))
    review.screenshot(page, 'template-overview')
    item.click()
    review.phase = 'template configuration form'
    for field in template['env']:
        control = page.get_by_label(field['label'], exact=True)
        review.check(field['label'] + ' is visible', lambda control=control: expect(control).to_be_visible())
        review.check(field['label'] + ' default is ' + field['default'],
                     lambda control=control, field=field: expect(control).to_have_value(field['default']))
    review.check('exactly four deployment environment fields', lambda: expect(
        page.locator('input[id^="env_var_"]')).to_have_count(4))
    review.screenshot(page, 'template-form')
    note = page.locator('div.text-xs').filter(has=page.get_by_role(
        'link', name='installation guide', exact=True))
    review.check('HTML note installation link is visible', lambda: expect(
        note.get_by_role('link', name='installation guide', exact=True)).to_be_visible())
    review.check('HTML note installation link has the expected href', lambda: expect(
        note.get_by_role('link', name='installation guide', exact=True)).to_have_attribute('href', INSTALL_URL))
    for text in NoteText(template['note']).parts:
        if text.strip():
            review.check('HTML note text: ' + text.strip()[:70],
                         lambda text=text: expect(note).to_contain_text(text.strip()))
    for text in ('x86-64', 'Docker Standalone', 'loopback', 'SSH tunnel', '127.0.0.1'):
        review.check('template guidance: ' + text, lambda text=text: expect(note).to_contain_text(text))


def review_stack(page, evidence, review, expect):
    review.phase = 'open Stacks'
    page.get_by_role('link', name='Stacks', exact=True).click()
    name = evidence.get('stack_name') or evidence['run'] + '-stack'
    stack = page.get_by_role('link', name=name, exact=True)
    review.check('existing fixture stack is visible', lambda: expect(stack).to_be_visible())
    review.check('existing stack link matches evidence stack ID', lambda: require(
        parse_qs(urlsplit(stack.get_attribute('href')).fragment.partition('?')[2]).get('id')
        == [str(evidence['stack_id'])], 'Stack link ID differs from integration evidence'))
    review.screenshot(page, 'stacks-overview')
    stack.click()
    review.phase = 'existing stack details'
    container_name = re.compile(re.escape(name) + r'[-_]phvalheim[-_]1')
    row = page.get_by_role('row').filter(has=page.get_by_title(container_name))
    review.check('deployed stack contains its PhValheim container', lambda: expect(row).to_be_visible())
    review.check('deployed stack container is running', lambda: expect(
        row.get_by_text('running', exact=True)).to_be_visible())
    review.screenshot(page, 'deployed-stack')


def review_public(context, evidence, review, expect):
    url = evidence.get('review', {}).get('public_url')
    if not url:
        review.result['public_portal'] = {'status': 'unavailable', 'reason': 'No review.public_url'}
        return
    page = context.new_page()
    review.watch(page, url, optional=True)
    try:
        response = page.goto(url, wait_until='domcontentloaded')
        require(response is not None and response.ok, 'Public portal HTTP response failed')
        expect(page.get_by_role('button', name='Sign in through Steam')).to_be_visible()
        expect(page.locator('body')).to_contain_text(re.compile(r'PhValheim|Welcome, Viking', re.I))
        review.screenshot(page, 'public-portal')
        review.result['public_portal'] = {'status': 'passed', 'steam_sign_in': False}
    except Exception as error:
        review.result['public_portal'] = {'status': 'unavailable', 'reason': review.safe(error)}
    finally:
        page.close()


def main():
    parser = argparse.ArgumentParser(description=__doc__,
                                     formatter_class=argparse.RawDescriptionHelpFormatter)
    parser.add_argument('evidence', type=Path, help='evidence.json from integration --keep')
    parser.add_argument('--timeout', type=int, default=30,
                        help='UI readiness deadline per assertion, seconds (default: 30)')
    parser.add_argument('--public', action='store_true', help='also review the optional public player portal')
    args = parser.parse_args()
    if args.timeout <= 0:
        parser.error('--timeout must be positive')
    review = None
    try:
        from playwright.sync_api import expect, sync_playwright

        path = args.evidence.resolve()
        evidence = json.loads(path.read_text())
        credentials_path = Path(evidence['credentials_path'])
        if not credentials_path.is_absolute():
            credentials_path = path.parent / credentials_path
        credentials = json.loads(credentials_path.read_text())
        review = Review(path, evidence, credentials)
        require(evidence['run'].startswith('phv-portainer-'), 'Expected an isolated integration run')
        require(evidence.get('endpoint_id') and evidence.get('stack_id'), 'Fixture has no deployed stack')
        templates = json.loads((ROOT / 'portainer/templates.json').read_text())['templates']
        template = next(t for t in templates if t['title'] == 'PhValheim Server')
        review.check('catalog has the four expected port defaults', lambda: require(
            {field['name']: field['default'] for field in template['env']} == {
                'PHV_PUBLIC_PORT': '8080', 'PHV_ADMIN_PORT': '8081',
                'PHV_GAME_PORT_START': '25000', 'PHV_GAME_PORT_END': '25019'},
            'Unexpected template port defaults'))
        expect.set_options(timeout=args.timeout * 1000)
        with sync_playwright() as playwright:
            browser = playwright.chromium.launch(timeout=30000)
            context = browser.new_context(viewport={'width': 1440, 'height': 1080})
            context.set_default_timeout(args.timeout * 1000)
            context.set_default_navigation_timeout(args.timeout * 1000)
            page = context.new_page()
            review.watch(page, evidence['portainer_url'])
            try:
                login(page, evidence, credentials, review, expect)
                select_environment(page, evidence, review, expect)
                review_template(page, template, review, expect)
                review_stack(page, evidence, review, expect)
                if args.public:
                    review_public(context, evidence, review, expect)
                review.check('no JavaScript application errors', lambda: require(
                    not review.result['page_errors'] and not review.result['console_errors'],
                    'JavaScript application errors recorded'))
                review.check('no unexpected failed Portainer requests', lambda: require(
                    not review.result['network_errors'], 'Unexpected failed Portainer requests recorded'))
                review.result['status'] = 'passed'
            except Exception:
                try:
                    review.screenshot(page, 'failure-visible-page')
                except Exception as error:
                    review.result['failure_screenshot_error'] = review.safe(error)
                raise
            finally:
                context.close()
                browser.close()
    except Exception as error:
        if review is None:
            print('FAIL: browser input/setup (' + type(error).__name__ + ')', file=sys.stderr)
            return 1
        review.result['status'] = 'failed'
        if not any(not c['passed'] for c in review.result['checks']):
            review.result['checks'].append({'name': review.phase, 'passed': False})
        review.result['failure'] = {'phase': review.phase, 'type': type(error).__name__,
                                    'message': review.safe(error)}
    review.save()
    print(json.dumps({'status': review.result['status'], 'totals': review.result['totals'],
                      'evidence': str(review.path),
                      'optional_network_notes': len(review.result['optional_network_notes']),
                      'public_portal': review.result['public_portal']['status']}))
    return 0 if review.result['status'] == 'passed' else 1


if __name__ == '__main__':
    sys.exit(main())
