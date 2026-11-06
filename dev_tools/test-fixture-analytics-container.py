#!/usr/bin/env python3
# requires: container
"""Positive canary + read-only suppression; never permits external networking."""
import json,os,subprocess,tempfile,uuid
from pathlib import Path
from fixture_analytics import docker_args,assert_isolated
IMAGE=os.environ.get('PHV_FIXTURE_TEST_IMAGE','theoriginalbrian/phvalheim-server:dev')
NAME='phv-analytics-isolation-'+uuid.uuid4().hex[:8]
TARGET='/opt/stateless/engine/tools/pushAnalytics.sh'
def run(*args,check=True):
    result = subprocess.run(args,capture_output=True,text=True)
    if check and result.returncode:
        raise RuntimeError(str(args[:3]) + ': ' + result.stderr + result.stdout)
    return result
with tempfile.TemporaryDirectory(prefix='phv-analytics-canary-') as d:
    directory=Path(d);directory.chmod(0o777)
    canary=directory/'canary.sh';canary.write_text('#!/bin/sh\nprintf attempted > /out/attempted\n');canary.chmod(0o755)
    def create(mounts):
        run('docker','create','--name',NAME,'--network','none','--entrypoint','sh','--user','1000:1000',
            '--mount',f'type=bind,source={d},target=/out',*mounts,IMAGE,'-c',f'{TARGET}; {TARGET} --disabled')
    try:
        create(['--mount',f'type=bind,source={canary},target={TARGET},readonly'])
        run('docker','start','-a',NAME)
        assert (directory/'attempted').read_text()=='attempted'
        (directory/'attempted').unlink();run('docker','rm',NAME)
        print('PASS: unblocked startup canary executes (positive control)',flush=True)
        for cycle in range(2):
            create(docker_args())
            assert_isolated(json.loads(run('docker','inspect',NAME).stdout)[0])
            for restart in range(2):
                run('docker','start','-a',NAME)
                assert not (directory/'attempted').exists()
                assert run('docker','inspect','--format','{{.State.ExitCode}}',NAME).stdout.strip()=='0'
            run('docker','rm',NAME)
        # A root writer cannot replace the mounted block.
        result=run('docker','run','--rm','--network','none','--entrypoint','sh',*docker_args(),IMAGE,'-c',f'echo unsafe > {TARGET}',check=False)
        assert result.returncode!=0 and 'Read-only' in result.stderr
        print('PASS: suppression survives startup, disabled notice, restart and recreation; mount rejects writes',flush=True)
    finally: run('docker','rm','-f',NAME,check=False)
