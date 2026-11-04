"""Disable scheduled telemetry before boot, including with older server images."""
from pathlib import Path


def docker_args():
    source = Path(__file__).resolve().parent / 'fixtures/analytics-disabled.sh'
    if not source.is_file():
        raise RuntimeError('Missing analytics isolation fixture')
    # Network-filesystem checkouts can inherit 0770 despite Git's executable bit.
    # This public, non-secret no-op must also be readable by the container UID.
    source.chmod(0o755)
    # --mount fails closed if the source disappears; -v would create a directory.
    return ['--mount', f'type=bind,source={source},target=/opt/stateless/engine/tools/pushAnalytics.sh,readonly']


def assert_isolated(inspection):
    target = '/opt/stateless/engine/tools/pushAnalytics.sh'
    mounts = [m for m in inspection['Mounts'] if m['Destination'] == target]
    source = Path(__file__).resolve().parent / 'fixtures/analytics-disabled.sh'
    if len(mounts) != 1 or mounts[0]['RW'] or Path(mounts[0]['Source']).resolve() != source:
        raise RuntimeError('Fixture lacks the read-only analytics block; recreate it before testing')


def verify_no_enrollment(container):
    """Prove both scheduled and opt-out calls leave the fixture without identity."""
    import json
    import subprocess
    inspection = json.loads(subprocess.check_output(['docker', 'inspect', container], text=True))[0]
    assert_isolated(inspection)
    subprocess.run(['docker', 'exec', container, 'sh', '-ec',
        '/opt/stateless/engine/tools/pushAnalytics.sh; '
        '/opt/stateless/engine/tools/pushAnalytics.sh --disabled; '
        'test ! -e /opt/stateful/analytics/credential.json'], check=True)
