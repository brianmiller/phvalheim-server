#!/usr/bin/env python3
# requires: container
"""Disposable real HTTP/DB tests; never uses the operator's data volume.

PHV_PORT_TEST_IMAGE selects the exact built candidate. PHV_PORT_TEST_COPY_SOURCE=1
overlays changed files into a stopped old-image container for pre-build testing.
"""
import concurrent.futures
import json
import os
from pathlib import Path
import subprocess
import time
import uuid
from fixture_analytics import docker_args as analytics_isolation, verify_no_enrollment

ROOT = Path(__file__).resolve().parents[1]
IMAGE = os.environ.get('PHV_PORT_TEST_IMAGE', 'theoriginalbrian/phvalheim-server:dev')
PREFIX = 'phv-port-oracle-' + uuid.uuid4().hex[:8]
VOLUME = PREFIX + '-data'
NAME = PREFIX
CHECKS = 0

def run(args, **kwargs):
    return subprocess.check_output(args, text=True, **kwargs).strip()

def ck(name, value):
    global CHECKS
    assert value, name
    CHECKS += 1
    print('PASS:', name, flush=True)

def sql(query):
    return run(['docker', 'exec', NAME, 'mysql', '-N', '-uroot', 'phvalheim', '-e', query])

def request(action, payload=None):
    cmd = ['docker', 'exec', NAME, 'curl', '--max-time', '20', '-fsS',
           'http://127.0.0.1:8081/adminAPI.php?action=' + action]
    if payload is not None:
        cmd += ['-H', 'Content-Type: application/json', '--data-binary', json.dumps(payload)]
    return json.loads(run(cmd))

def boot(managed=False, legacy=False):
    subprocess.run(['docker', 'rm', '-f', NAME], stdout=subprocess.DEVNULL, stderr=subprocess.DEVNULL)
    args = ['docker', 'create', *analytics_isolation(), '--name', NAME, '-v', VOLUME + ':/opt/stateful']
    if managed:
        args += ['-e', 'PHVALHEIM_GAME_PORT_START=31000', '-e', 'PHVALHEIM_GAME_PORT_END=31003']
    if legacy:
        args += ['-e', 'basePort=26000']
    run(args + [IMAGE])
    if os.environ.get('PHV_PORT_TEST_COPY_SOURCE') == '1':
        files = ['engine/phvalheim', 'engine/tools/gamePorts.php']
        files += ['nginx/www/' + p for p in [
            'includes/gameports.php', 'includes/config_env_puller.php', 'includes/db_sets.php',
            'includes/health.php', 'admin/adminAPI.php', 'admin/setup.php', 'admin/index.php', 'admin/new_world.php']]
        for path in files:
            run(['docker', 'cp', str(ROOT / 'container' / path), NAME + ':/opt/stateless/' + path])
    run(['docker', 'start', NAME])
    if os.environ.get('PHV_PORT_TEST_COPY_SOURCE') == '1':
        run(['docker', 'exec', NAME, 'chown', 'phvalheim:phvalheim', *['/opt/stateless/' + path for path in files]])
    deadline = time.monotonic() + 240
    while time.monotonic() < deadline:
        try:
            settings = request('getServerSettings')
            ready = subprocess.run(['docker', 'exec', NAME, 'grep', '-q', '^phvalheimVersion=', '/etc/environment'], capture_output=True).returncode == 0
            if settings.get('success') and ready:
                # Stop only this fixture's engine before inserting worlds, so the test
                # cannot download Steam or start game processes. nginx/PHP/DB stay live.
                run(['docker', 'exec', NAME, 'supervisorctl', 'stop', 'phvalheim'])
                return settings['settings']
        except (subprocess.CalledProcessError, json.JSONDecodeError):
            pass
        time.sleep(2)
    raise RuntimeError('Fixture API never became ready')

def create(name):
    return request('createWorld', {'world': name, 'vanilla': 1, 'accessOpen': 1})

def browser(managed):
    if os.environ.get('PHV_PORT_TEST_BROWSER') != '1':
        return
    subprocess.run(['docker', 'run', '--rm', '--network', 'container:' + NAME,
                    '-e', 'PHV_PORT_UI_MANAGED=' + ('1' if managed else '0'),
                    '-v', str(ROOT) + ':/repo:ro', 'mcr.microsoft.com/playwright:v1.47.0-jammy',
                    'bash', '-c', 'npm install --silent --prefix /tmp/pw playwright@1.47.0 && NODE_PATH=/tmp/pw/node_modules node /repo/dev_tools/game-ports-browser.cjs'], check=True)

try:
    settings = boot(managed=True)
    ck('managed fresh install still needs setup', settings['setupComplete'] == 0)
    ck('API reports effective managed range', settings['basePort'] == 31000 and settings['gamePortRange']['end'] == 31003)
    ck('saved base is not overwritten', sql('SELECT basePort FROM settings') == '25000')
    html = run(['docker', 'exec', NAME, 'curl', '-fsS', 'http://127.0.0.1:8081/setup.php'])
    ck('wizard shows managed read-only base', 'id="setup-basePort" value="31000" placeholder="25000" readonly' in html)
    ck('wizard explains ownership', 'Managed by container: UDP 31000' in html)
    ck('settings API rejects duplicate override', request('saveServerSettings', {'basePort': 25000})['success'] is False)
    ck('setup rejects duplicate override', request('completeSetup', {'basePort': 25000})['success'] is False)
    ck('failed setup keeps wizard pending', sql('SELECT setupComplete FROM settings') == '0')
    ck('setup completes with container base', request('completeSetup', {'basePort': 31000, 'gameDNS': 'fixture.invalid', 'steamAPIKey': '0' * 32})['success'])
    ck('setup preserves saved base', sql('SELECT basePort FROM settings') == '25000')
    ck('create first world', create('rangeone')['success'])
    ck('first world reserves first pair', sql("SELECT port FROM worlds WHERE name='rangeone'") == '31000')
    ck('create second world', create('rangetwo')['success'])
    ck('second world reserves next pair', sql("SELECT port FROM worlds WHERE name='rangetwo'") == '31002')
    result = create('rangefull')
    ck('full range explains exhaustion', result['success'] is False and 'is full' in result['error'])
    ck('failed create leaves no world', sql("SELECT COUNT(*) FROM worlds WHERE name='rangefull'") == '0')
    sql("DELETE FROM worlds WHERE name='rangetwo'")
    with concurrent.futures.ThreadPoolExecutor(2) as executor:
        attempts = list(executor.map(create, ['racerone', 'racertwo']))
    ck('concurrent requests cannot share last pair', sum(bool(r.get('success')) for r in attempts) == 1)
    ck('all reserved ports distinct', sql('SELECT COUNT(DISTINCT port)=COUNT(*) FROM worlds') == '1')
    sql("UPDATE worlds SET mode='stopped', autostart=0")
    settings = boot(managed=True)
    ck('managed range survives restart', settings['basePort'] == 31000 and settings['setupComplete'] == 2)
    ck('reserved world ports survive restart', sql('SELECT GROUP_CONCAT(port ORDER BY port) FROM worlds') == '31000,31002')
    sql("UPDATE worlds SET port=26000 WHERE name='rangeone'")
    settings = request('getServerSettings')['settings']
    ck('existing world outside range flagged', settings['gamePortRange']['conflicts'] == [{'name': 'rangeone', 'port': 26000}])
    ck('conflicting world never renumbered', sql("SELECT port FROM worlds WHERE name='rangeone'") == '26000')
    browser(True)
    html = run(['docker', 'exec', NAME, 'curl', '-fsS', 'http://127.0.0.1:8081/new_world.php'])
    ck('create page displays conflict', 'rangeone (26000)' in html and 'their ports have not been changed' in html)
    ident = sql("SELECT id FROM worlds WHERE name='rangeone'")
    cmd = subprocess.run(['docker', 'exec', NAME, 'php', '/opt/stateless/engine/tools/gamePorts.php', 'assign', ident], capture_output=True, text=True)
    ck('engine refuses out-of-range reassignment', cmd.returncode == 1 and 'has not been renumbered' in cmd.stderr)
    sql("UPDATE worlds SET port=NULL WHERE name='rangeone'")
    ck('engine assigns legacy unallocated world within range', run(['docker', 'exec', NAME, 'php', '/opt/stateless/engine/tools/gamePorts.php', 'assign', ident]) == '31000')
    sql('UPDATE settings SET basePort=26000')
    settings = boot()
    ck('removing managed range restores saved base', settings['basePort'] == 26000 and not settings['gamePortRange']['enabled'])
    browser(False)
    ck('removing managed range preserves worlds', sql('SELECT GROUP_CONCAT(port ORDER BY port) FROM worlds') == '31000,31002')
    ck('unmanaged settings remain editable', request('saveServerSettings', {'basePort': 27000})['success'])
    ck('unmanaged setting persists', sql('SELECT basePort FROM settings') == '27000')
    ck('ordinary Docker/Unraid world creation still works', create('ordinaryworld')['success'])
    ck('unmanaged create retains engine-assigned port behavior', sql("SELECT port IS NULL FROM worlds WHERE name='ordinaryworld'") == '1')
    allocated = run(['docker', 'exec', '-e', 'basePort=27000', NAME, 'bash', '-c', 'source /opt/stateless/engine/includes/0-functions.sh; getNextPort'])
    ck('legacy allocator uses saved base outside managed limits', allocated == '27000')
    sql('DELETE FROM worlds')
    sql('UPDATE settings SET setupComplete=0, basePort=25000')
    settings = boot()
    ck('new Unraid-style install keeps normal wizard', settings['setupComplete'] == 0 and settings['basePort'] == 25000)
    settings = boot(legacy=True)
    ck('pre-2.31 basePort migration still works', settings['basePort'] == 26000 and settings['setupComplete'] == 1)
    print(f'{CHECKS} integration checks passed', flush=True)
    verify_no_enrollment(NAME)
    print("PASS: startup and explicit analytics calls created no enrollment", flush=True)
finally:
    subprocess.run(['docker', 'rm', '-f', NAME], stdout=subprocess.DEVNULL, stderr=subprocess.DEVNULL)
    subprocess.run(['docker', 'volume', 'rm', VOLUME], stdout=subprocess.DEVNULL, stderr=subprocess.DEVNULL)
