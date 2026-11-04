#!/usr/bin/env python3
# requires: container
"""2.61 public-UI hardening, against a REAL container: disposable, never the operator's volume.

What this proves that the tree-level test (test-public-ui-hardening.sh) cannot: nginx actually
routes the way the config says, PHP actually refuses the forged callback, the API actually
answers 401, the bound queries actually return the right rows. Run it against the PREVIOUS
image and most of it goes red -- that is the control that shows these checks can see the bugs.

  PHV_SEC_TEST_IMAGE        image under test (default theoriginalbrian/phvalheim-server:dev)
  PHV_SEC_TEST_COPY_SOURCE=1  overlay the working tree's changed files into the stopped
                            container before start (pre-build testing; NOT exact-image proof)
  PHV_SEC_TEST_KEEP=1       leave the fixture container/volume behind for inspection

The forged-full-assertion case posts to steamcommunity.com for real (the container's own
outbound HTTPS), so Steam is the one saying is_valid:false. A precondition check reports
whether Steam was reachable; without it that case would be rejected for the wrong reason.
"""
import hashlib
import json
import os
import subprocess
import sys
import time
import uuid
from pathlib import Path

ROOT = Path(__file__).resolve().parents[1]
IMAGE = os.environ.get('PHV_SEC_TEST_IMAGE', 'theoriginalbrian/phvalheim-server:dev')
NAME = 'phv-sec-oracle-' + uuid.uuid4().hex[:8]
VOLUME = NAME + '-data'
SID = '76561198000000001'          # the fixture player (also the dev-bypass id)
OTHER = '76561198000000002'
PASS = 0
FAIL = 0

def run(args, check=True, **kw):
    r = subprocess.run(args, text=True, capture_output=True, **kw)
    if check and r.returncode != 0:
        raise RuntimeError(f'{args}: rc={r.returncode}\n{r.stdout}\n{r.stderr}')
    return r.stdout.strip()

def ck(name, cond, detail=''):
    global PASS, FAIL
    if cond:
        PASS += 1; print('PASS:', name, flush=True)
    else:
        FAIL += 1; print('FAIL:', name, ('-- ' + str(detail)[:300]) if detail else '', flush=True)

def dexec(*args, check=True):
    return run(['docker', 'exec', NAME, *args], check=check)

def sql(q):
    return dexec('mysql', '-N', '-uroot', 'phvalheim', '-e', q)

def admin(action, payload=None):
    cmd = ['docker', 'exec', NAME, 'curl', '--max-time', '20', '-fsS',
           'http://127.0.0.1:8081/adminAPI.php?action=' + action]
    if payload is not None:
        cmd += ['-H', 'Content-Type: application/json', '--data-binary', json.dumps(payload)]
    return json.loads(run(cmd))

def http(path, *extra, method='GET', body=None, port=8080):
    """status, headers(dict, lower-cased, last wins; set-cookie joined), body"""
    cmd = ['docker', 'exec', NAME, 'curl', '--max-time', '30', '-s', '-i', '-X', method,
           '-H', 'Expect:', *extra, 'http://127.0.0.1:%d%s' % (port, path)]
    if body is not None:
        cmd += ['--data-binary', body]
    raw = run(cmd, check=False).replace('\r\n', '\n')   # text mode already normalised most of them
    head, _, bod = raw.partition('\n\n')
    lines = head.split('\n')
    status = int(lines[0].split()[1]) if lines and len(lines[0].split()) > 1 else 0
    hdr = {}
    for ln in lines[1:]:
        k, _, v = ln.partition(':')
        k = k.strip().lower(); v = v.strip()
        hdr[k] = (hdr[k] + '\n' + v) if k in hdr else v
    return status, hdr, bod

def boot(bypass=None):
    subprocess.run(['docker', 'rm', '-f', NAME], capture_output=True)
    args = ['docker', 'create', '--name', NAME, '-v', VOLUME + ':/opt/stateful']
    if bypass:
        args += ['-e', 'phvalheimDevSteamID=' + bypass]
    run(args + [IMAGE])
    overlay = []
    if os.environ.get('PHV_SEC_TEST_COPY_SOURCE') == '1':
        for rel in ['includes/session_auth.php', 'includes/steam_openid.php', 'includes/db_gets.php',
                    'public/authenticated.php', 'public/api.php', 'public/index.php']:
            run(['docker', 'cp', str(ROOT / 'container/nginx/www' / rel), NAME + ':/opt/stateless/nginx/www/' + rel])
            overlay.append('/opt/stateless/nginx/www/' + rel)
        run(['docker', 'cp', str(ROOT / 'container/nginx/phvalheim.conf'), NAME + ':/etc/nginx/sites-available/phvalheim.conf'])
        run(['docker', 'cp', str(ROOT / 'container/nginx/nginx.conf'), NAME + ':/etc/nginx/nginx.conf'])
    run(['docker', 'start', NAME])
    if overlay:
        dexec('chown', 'phvalheim:phvalheim', *overlay)
    deadline = time.monotonic() + 240
    while time.monotonic() < deadline:
        try:
            s = admin('getServerSettings')
            ready = subprocess.run(['docker', 'exec', NAME, 'grep', '-q', '^phvalheimVersion=', '/etc/environment'],
                                   capture_output=True).returncode == 0
            if s.get('success') and ready:
                # Only this fixture's engine: nothing may download Steam or start a game.
                dexec('supervisorctl', 'stop', 'phvalheim')
                return s['settings']
        except (RuntimeError, json.JSONDecodeError):
            pass
        time.sleep(2)
    raise RuntimeError('fixture API never became ready')

def seed():
    """A fresh install, set up, with two worlds: one the fixture player may see, one not."""
    s = boot()
    if s['setupComplete'] != 2:
        ck('fixture setup completes', admin('completeSetup', {'basePort': 25000, 'gameDNS': 'fixture.invalid', 'steamAPIKey': '0' * 32})['success'])
    ck('fixture world (visible) created', admin('createWorld', {'world': 'secw', 'vanilla': 1, 'accessFirstId': 'V_' + SID})['success'])
    ck('fixture world (private) created', admin('createWorld', {'world': 'secpriv', 'vanilla': 1, 'accessFirstId': 'V_' + OTHER})['success'])
    sql("UPDATE worlds SET public=0, mode='stopped', world_md5='%s' WHERE name IN ('secw','secpriv')" % ('a' * 32))
    base = '/opt/stateful/games/valheim/worlds'
    dexec('sh', '-c', f'''
        set -e
        for w in secw secpriv; do
          mkdir -p {base}/$w/game/.config/unity3d/IronGate/Valheim {base}/$w/game/worlds_local
          echo "V_{OTHER}" > {base}/$w/game/.config/unity3d/IronGate/Valheim/adminlist.txt
          echo "V_{SID}"   > {base}/$w/game/.config/unity3d/IronGate/Valheim/permittedlist.txt
          printf 'SAVE' > {base}/$w/game/worlds_local/$w.db
          printf 'PAYLOAD-%s' $w > {base}/$w/$w.zip
          printf 'CONFIG-%s' $w  > {base}/$w/$w-config.zip
          printf 'OTHER' > {base}/$w/other.zip
          printf 'opts' > {base}/$w/.running-options
        done
        mkdir -p /opt/stateful/games/steam_home && echo secret > /opt/stateful/games/steam_home/token
        chown -R phvalheim:phvalheim {base} /opt/stateful/games/steam_home
    ''')

def steam_reachable():
    r = subprocess.run(['docker', 'exec', NAME, 'curl', '-s', '-o', '/dev/null', '-w', '%{http_code}', '--max-time', '15',
                        'https://steamcommunity.com/openid/login'], capture_output=True, text=True)
    return r.stdout.strip() not in ('', '000')

def forged_full_assertion(claimed):
    """A complete id_res shaped exactly like Steam's, with a signature Steam never made."""
    from urllib.parse import urlencode
    return '/authenticated.php?' + urlencode({
        'openid.ns': 'http://specs.openid.net/auth/2.0',
        'openid.mode': 'id_res',
        'openid.op_endpoint': 'https://steamcommunity.com/openid/login',
        'openid.claimed_id': claimed,
        'openid.identity': claimed,
        'openid.return_to': 'http://127.0.0.1:8080/authenticated.php',
        'openid.response_nonce': '2026-11-03T00:00:00ZforgedNONCE',
        'openid.assoc_handle': '1234567890',
        'openid.signed': 'signed,op_endpoint,claimed_id,identity,return_to,response_nonce,assoc_handle',
        'openid.sig': 'Zm9yZ2VkZm9yZ2VkZm9yZ2VkZm9yZ2VkZm9yZ2Vk',
    })

def cookie_of(hdr):
    sc = hdr.get('set-cookie', '')
    for part in sc.split('\n'):
        if part.startswith('PHPSESSID='):
            return part.split(';')[0]
    return ''

try:
    seed()
    profile = f'https://steamcommunity.com/openid/id/{SID}'

    print('\n== the reported bug: a sign-in callback that Steam never issued')
    st, h, _ = http(f'/authenticated.php?openid_claimed_id={profile}')
    ck('bare claimed_id: refused (302 to the sign-in page with auth=failed)',
       st == 302 and h.get('location', '').endswith('index.php?auth=failed'), (st, h.get('location')))
    ck('bare claimed_id: no redirect into the portal', not h.get('location', '').endswith('/authenticated.php'), h.get('location'))
    c = cookie_of(h)
    st2, h2, _ = http('/authenticated.php', '-H', 'Cookie: ' + c) if c else (None, {}, '')
    ck('bare claimed_id: the session it left behind (if any) is NOT signed in',
       c == '' or (st2 == 302 and 'index.php' in h2.get('location', '')), (c, st2, h2.get('location')))
    ck('rejection is logged for the operator',
       'rejected Steam sign-in callback' in dexec('sh', '-c', 'tail -c 20000 /opt/stateful/logs/php.log 2>/dev/null; tail -c 20000 /opt/stateful/logs/nginx.log 2>/dev/null; true'))

    reach = steam_reachable()
    ck('precondition: the container can reach steamcommunity.com (so the next check is Steam\'s verdict, not a timeout)', reach)
    st, h, _ = http(forged_full_assertion(profile))
    ck('full-shaped assertion with a forged signature: refused by Steam\'s check_authentication',
       st == 302 and h.get('location', '').endswith('index.php?auth=failed'), (st, h.get('location')))
    st, h, _ = http(forged_full_assertion(f'https://evil.example/openid/id/{SID}'))
    ck('assertion naming a non-Steam profile: refused', st == 302 and h.get('location', '').endswith('index.php?auth=failed'))
    st, h, body = http('/index.php?auth=failed')
    ck('the sign-in page explains a failed verification', st == 200 and 'Steam did not confirm that sign-in' in body)
    st, h, _ = http('/authenticated.php')
    ck('control: the portal with no session still bounces to sign-in (302)', st == 302 and 'index.php' in h.get('location', ''), (st, h.get('location')))

    print('\n== api.php: identity comes from the session or nowhere')
    st, h, body = http(f'/api.php?mode=getMyWorldsStatus&steamID={SID}')
    ck('steamID query parameter no longer signs anyone in (401)', st == 401 and '"success":false' in body, (st, body[:120]))
    ck('...and leaks no world', 'secw' not in body and 'launch' not in body.lower(), body[:200])
    st, h, body = http('/api.php?mode=getMyWorldsStatus')
    ck('no session at all: 401', st == 401, st)

    print('\n== SQL: bound parameters, allowlisted names')
    st, h, body = http('/api.php?mode=getMD5&world=secw')
    ck('control: getMD5 for a real world answers its md5', st == 200 and body.strip() == 'a' * 32, (st, body[:80]))
    st, h, body = http("/api.php?mode=getMD5&world=secw%27%20OR%20%271%27%3D%271")
    ck("getMD5 with a quote-breaking world name: 400, no row", st == 400 and 'a' * 32 not in body, (st, body[:80]))
    st, h, body = http('/api.php?mode=getSyncState&world=..%2Fsecw')
    ck('getSyncState with a path-shaped world name: 400', st == 400, st)
    st, h, body = http('/api.php', method='POST', body=json.dumps({'action': 'nothing', 'world': "secw' OR 1=1 -- "}),
                       port=8080, )
    ck('Companion POST with an injected world name: 400', st == 400, (st, body[:80]))
    st, h, body = http('/api.php', method='POST', body=json.dumps({'action': 'TrophyEikthyr', 'world': 'secw'}))
    ck('control: Companion POST with a real world name still records a hung head (true)', st == 200 and body.strip() == 'true', (st, body[:80]))
    ck('...and the bound UPDATE really landed', sql("SELECT trophyeikthyr FROM worlds WHERE name='secw'") == '1')
    php = ('require "/opt/stateless/nginx/www/includes/config_env_puller.php";'
           'require "/opt/stateless/nginx/www/includes/phvalheim-frontend-config.php";'
           'require "/opt/stateless/nginx/www/includes/db_gets.php";')
    out = dexec('php', '-r', php + f'echo implode(",", getMyWorlds($pdo, "{SID}"));')
    ck('control: getMyWorlds for the fixture player lists secw and not secpriv', out == 'secw', out)
    out = dexec('php', '-r', php + 'echo implode(",", getMyWorlds($pdo, "\' OR 1=1 -- "));')
    ck("getMyWorlds with an injected id does not widen to every world", 'secpriv' not in out and 'secw' not in out, out)
    out = dexec('php', '-r', php + 'echo implode(",", getMyWorlds($pdo, "%"));')
    ck('getMyWorlds with a bare LIKE wildcard matches nothing', out == '', out)
    out = dexec('php', '-r', php + 'var_export(getLaunchString($pdo, "secw\' OR 1=1 -- ", "g", "h", "http"));')
    ck('getLaunchString with an injected name returns nothing', out.strip() == 'NULL', out)

    print('\n== /stateful/: the two payload URLs and nothing else')
    cases = [
        ('/stateful/valheim/worlds/secw/secw.zip', 200, 'PAYLOAD-secw'),
        ('/stateful/valheim/worlds/secw/secw-config.zip', 200, 'CONFIG-secw'),
        ('/stateful/valheim/worlds/secw/other.zip', 404, None),
        ('/stateful/valheim/worlds/secw/secpriv.zip', 404, None),
        ('/stateful/valheim/worlds/secw/game/.config/unity3d/IronGate/Valheim/adminlist.txt', 404, None),
        ('/stateful/valheim/worlds/secw/game/.config/unity3d/IronGate/Valheim/permittedlist.txt', 404, None),
        ('/stateful/valheim/worlds/secw/game/worlds_local/secw.db', 404, None),
        ('/stateful/valheim/worlds/secw/.running-options', 404, None),
        ('/stateful/steam_home/token', 404, None),
        ('/stateful/', 404, None),
        ('/stateful/valheim/worlds/secw/', 404, None),
    ]
    for path, want, content in cases:
        st, h, body = http(path)
        ok = st == want and (content is None or body == content)
        ck(f'{path} -> {want}' + (' with the payload bytes' if content else ''), ok, (st, body[:60]))
    for p in ('adminlist.txt', 'permittedlist.txt'):
        st, _, body = http(f'/stateful/valheim/worlds/secw/game/.config/unity3d/IronGate/Valheim/{p}')
        ck(f'{p} content never leaves the server', 'V_7656' not in body)
    st, h, body = http('/stateful/valheim/worlds/secw/secw.zip', port=8081)
    ck('admin block (8081, private) still serves the payload', st == 200 and body == 'PAYLOAD-secw', st)

    print('\n== headers and cookies')
    st, h, _ = http('/index.php')
    ck('X-Content-Type-Options: nosniff on the sign-in page', h.get('x-content-type-options') == 'nosniff', h.get('x-content-type-options'))
    ck('X-Frame-Options: SAMEORIGIN', h.get('x-frame-options') == 'SAMEORIGIN')
    ck('Referrer-Policy set', h.get('referrer-policy') == 'strict-origin-when-cross-origin')
    ck('Server header carries no version', h.get('server', '') == 'nginx', h.get('server'))
    sc = h.get('set-cookie', '')
    ck('session cookie is HttpOnly + SameSite=Lax', 'HttpOnly' in sc and 'SameSite=Lax' in sc, sc)
    ck('...and NOT Secure over plain HTTP (operators without TLS keep sign-in)', 'secure' not in sc.lower(), sc)
    st, h, _ = http('/index.php', '-H', 'X-Forwarded-Proto: https')
    ck('...but Secure when the request arrived over TLS', 'secure' in h.get('set-cookie', '').lower(), h.get('set-cookie'))
    st, h, _ = http('/css/phvalheimStyles.css')
    ck('static assets carry nosniff too', st == 200 and h.get('x-content-type-options') == 'nosniff', (st, h.get('x-content-type-options')))
    st, h, body = http('/index.php')
    ck('sign-in page loads jQuery locally', '/js/jquery-3.6.0.js' in body and 'cdnjs.cloudflare.com/ajax/libs/jquery' not in body)
    st, h, _ = http('/js/jquery-3.6.0.js')
    ck('...and that file is served', st == 200, st)
    st, h, body = http('/companion.php?world=secw')
    ck('companion.php without a token is still 403 (unchanged)', st == 403, st)

    print('\n== control: the dev bypass still renders the portal, and does not weaken the callback')
    boot(bypass=SID)
    st, h, body = http('/authenticated.php')
    ck('bypassed portal renders (200) with its red banner', st == 200 and 'STEAM AUTH BYPASSED' in body, (st, body[:100]))
    ck('...showing the fixture player\'s world card, escaped name in place', 'data-world="secw"' in body and 'secpriv' not in body)
    st, h, body = http('/api.php?mode=getMyWorldsStatus')
    ck('bypassed session: getMyWorldsStatus answers success with the world', st == 200 and '"success":true' in body and '"secw"' in body, (st, body[:120]))
    st, h, _ = http(f'/authenticated.php?openid_claimed_id={profile}')
    ck('bypassed: a forged callback is STILL refused', st == 302 and h.get('location', '').endswith('index.php?auth=failed'), (st, h.get('location')))
finally:
    if os.environ.get('PHV_SEC_TEST_KEEP') == '1':
        print('kept', NAME, VOLUME)
    else:
        subprocess.run(['docker', 'rm', '-f', NAME], capture_output=True)
        subprocess.run(['docker', 'volume', 'rm', VOLUME], capture_output=True)

print(f'\n{PASS} passed, {FAIL} failed  (image {IMAGE}{", source overlay" if os.environ.get("PHV_SEC_TEST_COPY_SOURCE") == "1" else ""})')
sys.exit(0 if FAIL == 0 else 1)
