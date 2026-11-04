#!/usr/bin/env python3
# requires: manual
"""End to end: the REAL PhValheim Client, against a REAL PhValheim Server, through the public
surface 2.61 hardened -- the two payload URLs under /stateful/ and api.php.

Why it exists: 2.61 replaced the whole-tree /stateful/ alias with a regex that serves exactly
<world>/<world>.zip and <world>/<world>-config.zip, and put a world-name allowlist in front of
api.php. The tree-level and container-level tests assert those by hand-made requests; this
runs the shipped Linux client binary and lets IT make the requests, then checks it got the
payload (md5 equal to the server's), unpacked it, and started the (stub) game with the launch
payload intact. The stub Steam/Valheim host is the client repo's own, from
builders/test_flatpak_runtime.py, so the observable outcomes are the ones client CI asserts.

It runs the client inside a disposable Ubuntu container on the HOST network (the stubs want
root and a throwaway /usr/local/bin), pointed at a server that has the dev Steam bypass on, so
the launch string comes from the real api.php?mode=getMyWorldsStatus with no browser login.

usage:
  python3 dev_tools/test-client-e2e-public.py --server http://127.0.0.1:8080 \
      --client ../phvalheim-client/builds/phvalheim-client-2.0.17-universal-x86_64.tar.gz \
      [--container phvalheim-dev] [--world midgard]

  --client may be the extracted linux-x64 binary or the published .tar.gz (extracted here).
  --container names the server container so its nginx log can be read back afterwards.
"""
import argparse
import hashlib
import json
import os
import shutil
import subprocess
import sys
import tarfile
import tempfile
import time
from pathlib import Path
from urllib.request import urlopen

PASS = 0
FAIL = 0

def ck(name, cond, detail=''):
    global PASS, FAIL
    if cond:
        PASS += 1; print('  PASS ', name, flush=True)
    else:
        FAIL += 1; print('  FAIL ', name, ('-- ' + str(detail)[:400]) if detail else '', flush=True)

# ------------------------------------------------------------------ inner: runs in the container
def inner(server, world_pick):
    sys.path.insert(0, '/opt/builders')
    import test_flatpak_runtime as T  # the client's own stub host

    print('== stub host')
    valheim_dir = T.build_fake_host()
    T.clear_logs()
    ck('stub Steam on PATH', shutil.which('steam') == '/usr/local/bin/steam')

    print('== launch string from the real server')
    worlds = json.load(urlopen(server + '/api.php?mode=getMyWorldsStatus', timeout=30))
    ck('api.php answered the bypassed session with success', worlds.get('success') is True, str(worlds)[:200])
    cands = [w for w in worlds.get('worlds', []) if not w.get('vanilla') and (w.get('md5') or '')]
    if world_pick:
        cands = [w for w in cands if w.get('name') == world_pick]
    ck('a modded world with a published payload is available', bool(cands),
       [(w.get('name'), w.get('vanilla'), w.get('md5')) for w in worlds.get('worlds', [])])
    if not cands:
        return
    w = cands[0]
    world = w['name']
    launch = next((v for k, v in w.items() if 'launch' in k.lower() and isinstance(v, str) and v), None)
    ck(f'world {world} carries a launch string', bool(launch), list(w.keys()))
    if not launch:
        return
    server_md5 = urlopen(server + '/api.php?mode=getMD5&configPolicy=1&world=' + world, timeout=30).read().decode().strip()
    ck('server publishes a 32-hex md5 for it', len(server_md5) == 32, server_md5)
    sync = urlopen(server + '/api.php?mode=getSyncState&configPolicy=1&world=' + world, timeout=30).read().decode()
    ck('getSyncState names the same md5', ('world=' + server_md5) in sync, sync[:200])

    print(f'== run the client: phvalheim://?{launch[:24]}...')
    env = dict(os.environ, HOME='/root', PHVALHEIM_VERBOSE='1', DOTNET_SYSTEM_GLOBALIZATION_INVARIANT='1')
    t0 = time.time()
    p = subprocess.run(['/opt/client/phvalheim-client', 'phvalheim://?' + launch], env=env,
                       capture_output=True, timeout=900, stdin=subprocess.DEVNULL)
    out = p.stdout.decode(errors='replace') + p.stderr.decode(errors='replace')
    print('  client exit', p.returncode, 'in %.0fs' % (time.time() - t0))
    print('  --- client output (last 40 lines) ---')
    print('\n'.join('  | ' + ln for ln in out.strip().splitlines()[-40:]))
    ck('client exited 0', p.returncode == 0, out[-500:])

    print('== what the client did')
    rec = T.wait_for_json(T.VALHEIM_LOG, timeout=30)
    ck('the stub Valheim was started', rec is not None)
    if rec:
        argv = rec['argv']
        ck('--phvalheim-launch reached Valheim argv with the launch string intact',
           '--phvalheim-launch' in argv and argv[argv.index('--phvalheim-launch') + 1] == launch, argv[1:][:12])
        ck('Valheim was started in its own directory', rec['cwd'] == str(valheim_dir), rec['cwd'])
        ck('BepInEx doorstop is in its environment', 'doorstop' in json.dumps(rec['env']).lower())
    zips = list(Path('/root/.config/PhValheim').rglob(world + '.zip'))
    ck('the world payload was downloaded', len(zips) >= 1, [str(z) for z in Path('/root/.config/PhValheim').rglob('*.zip')])
    if zips:
        got = hashlib.md5(zips[0].read_bytes()).hexdigest()
        ck('...and its md5 equals what the server published', got == server_md5, (got, server_md5))
    cfgs = list(Path('/root/.config/PhValheim').rglob(world + '-config.zip'))
    ck('the config payload was downloaded', len(cfgs) >= 1)
    bep = list(Path('/root/.config/PhValheim').rglob('BepInEx'))
    ck('the payload was unpacked (a BepInEx tree exists)', any(b.is_dir() for b in bep))
    steam_log = T.STEAM_LOG.read_text() if T.STEAM_LOG.exists() else ''
    ck('stub Steam was asked to launch Valheim (-applaunch 892970)', '-applaunch' in steam_log and '892970' in steam_log, steam_log[-300:])

# ------------------------------------------------------------------ outer: drives docker
def outer(a):
    here = Path(__file__).resolve().parent
    builders = Path(a.builders).resolve()
    ck('client stub host module present', (builders / 'test_flatpak_runtime.py').is_file(), str(builders))
    work = Path(tempfile.mkdtemp(prefix='phv-e2e-'))
    try:
        src = Path(a.client).resolve()
        if src.suffix == '.gz' or src.name.endswith('.tar.gz'):
            with tarfile.open(src) as t:
                t.extractall(work / 'client')
            bins = [p for p in (work / 'client').rglob('phvalheim-client') if p.is_file()]
            ck('tarball contains the linux client binary', len(bins) == 1, [str(b) for b in bins])
            if len(bins) != 1:
                return
            exe = bins[0]
        else:
            exe = src
        exe.chmod(0o755)
        print('client binary:', exe, hashlib.sha256(exe.read_bytes()).hexdigest()[:16], exe.stat().st_size, 'bytes')

        if a.container:
            subprocess.run(['docker', 'exec', a.container, 'sh', '-c', 'echo "--- e2e start $(date -u +%FT%TZ) ---" >> /opt/stateful/logs/nginx.log'], check=False)

        name = 'phv-e2e-' + os.urandom(4).hex()
        cmd = ['docker', 'run', '--rm', '--name', name, '--network', 'host',
               '-v', f'{exe}:/opt/client/phvalheim-client:ro',
               '-v', f'{builders}:/opt/builders:ro',
               '-v', f'{here}:/opt/dev_tools:ro',
               'ubuntu:jammy', 'bash', '-c',
               'export DEBIAN_FRONTEND=noninteractive; apt-get -qq update >/dev/null && '
               'apt-get -qq install -y --no-install-recommends python3 ca-certificates libicu70 >/dev/null && '
               f'python3 -I /opt/dev_tools/test-client-e2e-public.py --inner {a.server} --world "{a.world or ""}"']
        print('== container', name)
        r = subprocess.run(cmd, text=True, capture_output=True, timeout=1500)
        sys.stdout.write(r.stdout)
        if r.returncode != 0:
            sys.stdout.write(r.stderr[-2000:])
        # The inner run already printed its own PASS/FAIL lines; fold its verdict in.
        inner_fail = sum(1 for ln in r.stdout.splitlines() if ln.startswith('  FAIL'))
        inner_pass = sum(1 for ln in r.stdout.splitlines() if ln.startswith('  PASS'))
        ck('inner run completed', r.returncode == 0 and inner_pass > 0, (r.returncode, r.stderr[-300:]))
        global PASS, FAIL
        PASS += inner_pass; FAIL += inner_fail

        if a.container:
            print('== server side: what nginx saw from the client (since e2e start)')
            log = subprocess.run(['docker', 'exec', a.container, 'sh', '-c',
                                  'awk "/--- e2e start/{buf=\\"\\"} {buf=buf $0 \\"\\n\\"} END{printf \\"%s\\", buf}" /opt/stateful/logs/nginx.log'],
                                 text=True, capture_output=True).stdout
            hits = [ln for ln in log.splitlines() if '/stateful/valheim/worlds/' in ln or 'api.php' in ln]
            for ln in hits[-12:]:
                print('  |', ln[:200])
            ck('nginx served the world zip to the client with 200',
               any('.zip HTTP' in ln and ' 200 ' in ln and '-config.zip' not in ln for ln in hits), hits[-5:])
            ck('nginx served the config zip with 200', any('-config.zip HTTP' in ln and ' 200 ' in ln for ln in hits))
            ck('the client asked getSyncState', any('mode=getSyncState' in ln and ' 200 ' in ln for ln in hits))
            ck('nothing else under /stateful/ was requested', not any('/stateful/' in ln and '.zip' not in ln for ln in hits), hits)
    finally:
        shutil.rmtree(work, ignore_errors=True)

if __name__ == '__main__':
    ap = argparse.ArgumentParser()
    ap.add_argument('--inner', metavar='SERVER_URL')
    ap.add_argument('--server')
    ap.add_argument('--client')
    ap.add_argument('--container')
    ap.add_argument('--world', default='')
    ap.add_argument('--builders', default=str(Path(__file__).resolve().parents[2] / 'phvalheim-client' / 'builders'))
    a = ap.parse_args()
    if a.inner:
        inner(a.inner, a.world or None)
        print(f'inner: {PASS} passed, {FAIL} failed')
        sys.exit(0 if FAIL == 0 else 1)
    if not (a.server and a.client):
        ap.error('--server and --client are required')
    outer(a)
    print(f'\n{PASS} passed, {FAIL} failed')
    sys.exit(0 if FAIL == 0 else 1)
