#!/usr/bin/env python3
# requires: container
"""Run recovery against real MariaDB in an OWNED disposable presentation fixture.

This clears catalogue sync history and stops fixture engine/cron. Never target an
operator container. PHV_PRESENTATION_FIXTURE must name a fresh fixture JSON.
"""
import json
import os
from pathlib import Path
import subprocess
import tempfile
from fixture_analytics import assert_isolated
f = json.loads(Path(os.environ['PHV_PRESENTATION_FIXTURE']).read_text())
info = json.loads(subprocess.check_output(['docker','inspect',f['name']]))[0]
assert_isolated(info)
assert f['name'].startswith('phv-presentation-') and f['volume']==f['name']+'-data'
for service in ['phvalheim','cron']:
    state = subprocess.run(['docker','exec',f['name'],'supervisorctl','status',service],capture_output=True,text=True)
    if 'RUNNING' in state.stdout:
        subprocess.run(['docker','exec',f['name'],'supervisorctl','stop',service],check=True)
# Boot catalogue sync is launched with setsid and survives stopping the engine.
# Terminate its entire process group in this disposable fixture before seeding IDs.
quiesce = r"""
import os, signal, time, fcntl, sys
from pathlib import Path
for proc in Path('/proc').glob('[0-9]*'):
    try:
        args=(proc/'cmdline').read_bytes().split(b'\0')
        if b'/opt/stateless/engine/tools/modSync.py' in args and b'boot' in args:
            group=os.getpgid(int(proc.name))
            assert group != os.getpgrp()
            os.killpg(group, signal.SIGTERM)
    except (FileNotFoundError, ProcessLookupError): pass
sys.path.insert(0, '/opt/stateless/engine/tools')
import modSync
with modSync.open_lock(modSync.RUN_LOCK) as lock:
    for attempt in range(100):
        try:
            fcntl.flock(lock,fcntl.LOCK_EX|fcntl.LOCK_NB)
            break
        except BlockingIOError: time.sleep(.1)
    else: raise AssertionError('fixture catalogue writer did not stop')
"""
subprocess.run(['docker','exec',f['name'],'python3','-c',quiesce],check=True)
with tempfile.TemporaryDirectory() as tmp:
    test = Path(tmp)/'test-modsync-stale-pid.py'
    test.write_bytes(Path(__file__).with_name(test.name).read_bytes())
    test.chmod(0o644)
    subprocess.run(['docker','cp',str(test),f['name']+':/tmp/test-modsync-stale-pid.py'],check=True)
    subprocess.run(['docker','exec','-e','PHV_MODSYNC_TEST_MYSQL=1',
        '-e','PHV_MODSYNC_TEST_MODULE=/opt/stateless/engine/tools/modSync.py',
        f['name'],'python3','/tmp/test-modsync-stale-pid.py'],check=True)
    subprocess.run(['docker','exec',f['name'],'rm','/tmp/test-modsync-stale-pid.py'],check=True)
