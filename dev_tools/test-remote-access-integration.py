#!/usr/bin/env python3
# requires: container
"""Disposable exact-image Remote Access tests. No account credentials required.

PHV_REMOTE_TEST_IMAGE defaults to phvalheim-remote:candidate. Set KEEP=1 to retain
the fixture for browser checks. Provider enrollment is started but never approved.
"""
import http.client
import http.cookiejar
import json
import os
from pathlib import Path
import subprocess
import time
import urllib.error
import urllib.request
import uuid
from fixture_analytics import docker_args as analytics_isolation, verify_no_enrollment

IMAGE = os.environ.get('PHV_REMOTE_TEST_IMAGE', 'phvalheim-remote:candidate')
HOST = os.environ.get('PHV_REMOTE_TEST_HOST', '127.0.0.1')
NAME = 'phv-remote-oracle-' + uuid.uuid4().hex[:8]
APP = NAME + '-app'; CONNECTOR = APP
VOLUMES = [NAME+'-data']
NET = NAME+'-net'
PASS = 0


def command(*args, check=True, **kw):
    r = subprocess.run(list(args), text=True, capture_output=True, **kw)
    if check and r.returncode:
        raise RuntimeError('Command failed: ' + ' '.join(args[:4]) + '\n' + r.stderr[-1500:] + r.stdout[-1500:])
    return r.stdout.strip()


def ck(ok, label):
    global PASS
    if not ok: raise AssertionError(label)
    PASS += 1; print('PASS:', label, flush=True)


def wait(fn, seconds=30):
    end = time.monotonic()+seconds
    while time.monotonic()<end:
        try:
            value=fn()
            if value: return value
        except (OSError, ValueError, urllib.error.URLError, RuntimeError): pass
        time.sleep(.5)
    raise AssertionError('Timed out waiting for fixture condition')


def python_in(name, source, user=None):
    args=['docker','exec','-i']
    if user: args+=['--user',str(user)]
    return command(*args,name,'python3','-',input=source)


try:
    for volume in VOLUMES: command('docker','volume','create',volume)
    command('docker','run',*analytics_isolation(),'-d','--name',APP,'-p',HOST+'::8080','-p',HOST+'::8081',
            '-v',VOLUMES[0]+':/opt/stateful',IMAGE)
    inspection=json.loads(command('docker','inspect',APP))[0]
    ck(not inspection['HostConfig'].get('Privileged') and not inspection['HostConfig'].get('CapAdd')
       and len([m for m in inspection['Mounts'] if m['Destination']=='/opt/stateful'])==1, 'single standard container, existing data mount, no added capabilities (telemetry blocked)')
    port=int(command('docker','port',APP,'8081/tcp').rsplit(':',1)[1]); base='http://'+HOST+':'+str(port)
    pubport=int(command('docker','port',APP,'8080/tcp').rsplit(':',1)[1])
    jar=http.cookiejar.CookieJar(); opener=urllib.request.build_opener(urllib.request.HTTPCookieProcessor(jar))
    csrf=''
    def http(path='/remoteAccessAPI.php', payload=None, headers=None, public=False):
        hdr={'Content-Type':'application/json','Origin':base,'Sec-Fetch-Site':'same-origin','X-PHV-CSRF':csrf}
        hdr.update(headers or {})
        request=urllib.request.Request(('http://'+HOST+':'+str(pubport) if public else base)+path,
                                       data=json.dumps(payload).encode() if payload is not None else None,headers=hdr)
        try:
            with opener.open(request,timeout=20) as r: return r.status,r.read()
        except urllib.error.HTTPError as e: return e.code,e.read()
    def status():
        return json.loads(http()[1])
    def action(name, **fields):
        code,body=http(payload={'action':name,**fields}); result=json.loads(body)
        if code!=200 or not result.get('success'): raise AssertionError(result.get('error','Action failed'))
        return result
    s=wait(lambda: (d if (d:=status()).get('available') else None),60); csrf=s['csrf']
    ck(s['observed']['state']=='Off' and not s['config']['enabled'],'default off with genuine connector status')
    ck(http(public=True)[0]==404,'admin Remote Access API absent from player port')
    ck(http(headers={'Host':'evil.example'})[0]==403,'Host/Origin mismatch rejected before CSRF disclosure')
    ck(http(headers={'Sec-Fetch-Site':'cross-site'})[0]==403,'cross-site status read rejected')
    ck(http(headers={'Sec-Fetch-Site':'same-site'})[0]==403,'sibling-site status read rejected')
    ck(http(payload={'action':'disconnect'},headers={'X-PHV-CSRF':''})[0]==403,'mutation without CSRF rejected')
    ck(http(payload={'action':'disconnect'},headers={'Origin':'https://evil.example'})[0]==403,'cross-origin mutation rejected')
    ck(http(payload={'action':'save','provider':'cloudflare','url':'http://127.0.0.1','token':'A'*120})[0]==400,'unsafe URL rejected')
    action('save',provider='cloudflare',url='https://play.example.net',token='A'*120)
    ck('A'*120 not in json.dumps(status()),'saved credential never returned in status')
    ck(python_in(CONNECTOR,"from pathlib import Path\np=Path('/opt/stateful/remote-access/cloudflare.token'); print(p.stat().st_mode & 511, p.stat().st_uid)")=='384 1001','token private to provider UID')
    denial="""import socket
s=socket.socket(socket.AF_UNIX)
try: s.connect('/run/phvalheim-remote/control.sock'); print('UNSAFE')
except PermissionError: print('DENIED')
"""
    ck(python_in(CONNECTOR,denial,'1001:1001')=='DENIED','provider UID cannot open admin control socket')
    private_read="""from pathlib import Path
try: Path('/opt/stateful/remote-access/cloudflare.token').read_text(); print('UNSAFE')
except PermissionError: print('DENIED')
"""
    ck(python_in(APP,private_read,'1000:1000')=='DENIED','web/application user cannot read saved token')
    ck(s['packaging']=='built-in','status identifies built-in service')
    action('connect')
    s=wait(lambda: (d if (d:=status()).get('test',{}).get('steps',{}).get('local',{}).get('state')=='Passed' else None))
    ck(s['test']['steps']['local']['state']=='Passed','real Nginx/PHP instance challenge passes')
    nonce=s['test']['nonce']
    code,body=http('/remote-test.php?nonce='+nonce,public=True)
    ck(code==200 and json.loads(body)['nonce']==nonce,'public challenge matches current job')
    ck(http('/remote-test.php?nonce='+'0'*48,public=True)[0]==404,'wrong challenge rejected with positive control')
    body=python_in(CONNECTOR,"""import http.client,json
c=http.client.HTTPConnection('127.0.0.1',18080,timeout=3)
c.request('GET','/remote-test.php?nonce=NONCE',headers={'Host':'play.example.net'})
r=c.getresponse(); print(r.status, json.loads(r.read()).get('nonce'))
""".replace('NONCE',nonce), '1001:1001')
    ck(body=='200 '+nonce,'fixed gateway forwards to correct player UI over Unix socket')
    code=python_in(CONNECTOR,"""import http.client
c=http.client.HTTPConnection('127.0.0.1',18080,timeout=3);c.request('GET','/',headers={'Host':'evil.example'});print(c.getresponse().status)
""",'1001:1001')
    ck(code=='421','gateway rejects unexpected Host')
    code=python_in(APP,"""import http.client
for path in ['/remoteAccessAPI.php','/supervisor/','/stateful/remote-access/config.json','/stateful/remote-access/cloudflare.token']:
 c=http.client.HTTPConnection('127.0.0.1',18080,timeout=3);c.request('GET',path,headers={'Host':'play.example.net'});print(c.getresponse().status);c.close()
""",'1001:1001')
    ck(code.splitlines()==['404']*4,'configured public gateway exposes neither admin nor credentials')

    http('/remote-test.php?nonce='+nonce+'&browser=1',public=True)
    ck('browser reached' in status()['test']['device'],'device test records actual browser-route evidence')
    s=wait(lambda: (d if (d:=status())['test']['state']=='Check failed' else None),35)
    ck(s['test']['steps']['provider']['state']=='Failed' and s['test']['steps']['route']['state']=='Blocked','invalid real provider token never produces a successful website check')
    ck('A'*120 not in command('docker','logs',CONNECTOR,check=False),'raw provider credentials absent from container logs')
    s=action('disconnect')
    ck(not s['config']['enabled'] and s['observed']['state']=='Off','disconnect confirms stopped state')
    ck(http('/remote-test.php?nonce='+nonce,public=True)[0]==404,'disconnect invalidates public challenge')
    command('docker','exec',APP,'supervisorctl','restart','remote-access')
    s=wait(lambda: (d if (d:=status()).get('available') else None))
    ck(s['config']['tokenSaved'] and not s['config']['enabled'],'restart retains configuration and off state')
    # Real Tailscale daemon and sign-in handshake, without authorizing an account.
    action('save',provider='tailscale')
    action('enroll')
    s=wait(lambda: (d if (d:=status()).get('authUrl') else None),45)
    ck(s['authUrl'].startswith('https://login.tailscale.') and not s['config']['enabled'],'real Tailscale sign-in link appears while website stays off')
    ck(s['observed']['details']['backend']=='NeedsLogin','Tailscale status reflects actual unauthenticated daemon')
    providers=python_in(CONNECTOR,"""from pathlib import Path
for p in Path('/proc').glob('[0-9]*'):
 try:
  if p.joinpath('comm').read_text().strip()=='tailscaled':
   s=p.joinpath('status').read_text(); print([x for x in s.splitlines() if x.startswith('CapEff:')][0].split()[1])
 except OSError: pass
""")
    ck(providers and set(providers.splitlines())=={'0000000000000000'},'provider process has zero effective capabilities')
    # If the controller crashes, old provider processes must not survive its restart.
    old_pids=python_in(APP,"from pathlib import Path; print(' '.join(p.name for p in Path('/proc').glob('[0-9]*') if (p/'comm').exists() and (p/'comm').read_text().strip()=='tailscaled'))").split()
    controller=int(command('docker','exec',APP,'supervisorctl','pid','remote-access'))
    command('docker','exec',APP,'kill','-KILL',str(controller))
    wait(lambda: python_in(APP,"from pathlib import Path;print(all(not Path('/proc/'+p).exists() for p in "+repr(old_pids)+"))")=='True',15)
    s=wait(lambda: (d if (d:=status()).get('available') and not d.get('enrolling') else None),30)
    ck(old_pids and not s['config']['enabled'],'controller crash stops old provider processes and restarts safely Off')
    action('disconnect'); action('forget')
    ck(not status()['config']['tokenSaved'] and status()['config']['provider']=='','forget removes local credentials')
    # Recreate a main container with its original data volume and the telemetry block. This catches
    # accidental reliance on the old sidecar, shared socket mount, or writable layer.
    action('save',provider='cloudflare',url='https://persist.example.net',token='B'*120)
    identity=python_in(APP,"from pathlib import Path;print(Path('/opt/stateful/remote-access/identity.json').read_text())")
    command('docker','rm','-f',APP)
    command('docker','run',*analytics_isolation(),'-d','--name',APP,'-p',HOST+'::8080','-p',HOST+'::8081',
            '-v',VOLUMES[0]+':/opt/stateful',IMAGE)
    port=int(command('docker','port',APP,'8081/tcp').rsplit(':',1)[1]); base='http://'+HOST+':'+str(port)
    pubport=int(command('docker','port',APP,'8080/tcp').rsplit(':',1)[1])
    wait(lambda: http('/remote-test.php?nonce='+'0'*48,public=True)[0]==404,45)
    s=wait(lambda: (d if (d:=status()).get('available') else None),45); csrf=s['csrf']
    ck(s['config']['tokenSaved'] and s['config']['url']=='https://persist.example.net' and not s['config']['enabled'],
       'container replacement retains configuration and Off using only the existing data volume')
    ck(python_in(APP,"from pathlib import Path;print(Path('/opt/stateful/remote-access/identity.json').read_text())")==identity,
       'container replacement preserves instance identity')
    action('forget')
    print(f'PASS: {PASS} exact-image Remote Access integration checks. No account enrollment approved or live player login/download claimed.',flush=True)
    if os.environ.get('PHV_REMOTE_TEST_KEEP')=='1':
        Path('/tmp/phv-remote-browser-fixture.json').write_text(json.dumps({'app':APP,'connector':CONNECTOR,'network':NET,'volumes':VOLUMES,'admin':base,'public':'http://'+HOST+':'+str(pubport)}))
    verify_no_enrollment(APP)
    print("PASS: startup and explicit analytics calls created no enrollment", flush=True)
finally:
    if os.environ.get('PHV_REMOTE_TEST_KEEP')!='1':
        for name in (APP,): command('docker','rm','-f',name,check=False)
        for volume in VOLUMES: command('docker','volume','rm',volume,check=False)
