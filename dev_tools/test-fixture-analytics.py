#!/usr/bin/env python3
"""Evaluate every boot command: no container is launched by this regression."""
import ast
import json
from pathlib import Path
import subprocess
import unittest
from fixture_analytics import docker_args, assert_isolated

ROOT = Path(__file__).resolve().parents[1]
TARGET = '/opt/stateless/engine/tools/pushAnalytics.sh'

class Isolation(unittest.TestCase):
    def check_args(self, args):
        index = args.index('--mount')
        fields = dict(x.split('=', 1) if '=' in x else (x, True) for x in args[index+1].split(','))
        self.assertEqual(fields['target'], TARGET)
        self.assertTrue(fields['readonly'])
        self.assertEqual(Path(fields['source']), ROOT/'dev_tools/fixtures/analytics-disabled.sh')
        self.assertTrue(Path(fields['source']).is_file())

    def test_private_checkout_mode_is_prepared_for_service_uid(self):
        source=ROOT/'dev_tools/fixtures/analytics-disabled.sh'
        source.chmod(0o770)
        docker_args()
        self.assertEqual(source.stat().st_mode & 0o777, 0o755)

    def test_python_launches_including_recreation(self):
        count = 0
        for name in ['test-game-port-integration.py', 'test-public-security-integration.py', 'test-remote-access-integration.py']:
            tree = ast.parse((ROOT/'dev_tools'/name).read_text())
            env = dict(analytics_isolation=docker_args, NAME='fixture', APP='fixture', HOST='127.0.0.1', VOLUME='fixture-data', VOLUMES=['fixture-data'], IMAGE='fixture-image')
            for node in ast.walk(tree):
                # The two create argument lists and both Remote Access run calls.
                items = node.elts if isinstance(node, ast.List) else node.args if isinstance(node, ast.Call) else []
                if len(items) < 2 or not all(isinstance(x, ast.Constant) for x in items[:2]):
                    continue
                if [x.value for x in items[:2]] not in [['docker','create'], ['docker','run']]:
                    continue
                # Browser companion image never starts the server engine.
                if any(isinstance(x,ast.Constant) and x.value=='--network' for x in items):
                    continue
                expr = ast.Expression(ast.List(elts=items,ctx=ast.Load()))
                args = eval(compile(ast.fix_missing_locations(expr), name, 'eval'), env)
                self.check_args(args); count += 1
        self.assertEqual(count, 4)

    def test_shell_launches(self):
        for name in ['test-ai-actions.sh','test-ai-actions-mutations.sh','test-ai-e2e.sh','test-proposal-ui.sh','test-vibes-scm-e2e.sh','devUp.sh','oracle-container-env.sh']:
            source = (ROOT/'dev_tools'/name).read_text().replace('\\\n',' ')
            self.assertIn('fixture-analytics.sh', source)
            lines = [line.strip().split('>/dev/null')[0] for line in source.splitlines() if 'docker' + ' run -d ' in line]
            self.assertEqual(len(lines),1,name)
            script = '''source "$REPO/dev_tools/fixture-analytics.sh" || exit 1
NAME=fixture SCM_CONTAINER=fixture ORACLE_PROBE_NAME=fixture
DATA=/tmp/fixture VOLUME=fixture-data SCM_VOLUME=fixture-data SOURCE_VOLUME=/tmp/fixture
IMAGE=fixture ORACLE_IMAGE=fixture PORT=1 SCM_PORT=1 ORACLE_PROBE_PORT=1 ORACLE_PROBE_ADMIN_PORT=2
env_args=''
docker() { printf '%s\\0' "$@"; }
''' + lines[0]
            import os
            result=subprocess.check_output(['bash','-eu','-c',script],env=dict(os.environ,REPO=str(ROOT)))
            self.check_args(result.decode().rstrip('\0').split('\0'))

    def test_retained_fixture_rejects_unprotected_or_writable_mount(self):
        good={'Destination':TARGET, 'Source':str(ROOT/'dev_tools/fixtures/analytics-disabled.sh'), 'RW':False}
        assert_isolated({'Mounts':[good]})
        for mounts in [[],[dict(good,RW=True)],[dict(good,Source='/tmp/other')]]:
            with self.assertRaises(RuntimeError): assert_isolated({'Mounts':mounts})
        source=(ROOT/'dev_tools/test-remote-access-browser.py').read_text()
        self.assertLess(source.index('assert_isolated(json'),source.index('UPDATE settings'))

if __name__=='__main__': unittest.main()
