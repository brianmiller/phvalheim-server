#!/usr/bin/env python3
"""Standalone security and state-transition oracles for Remote Access."""
import importlib.util
import json
from pathlib import Path
import tempfile
import unittest
from unittest.mock import patch

ROOT = Path(__file__).resolve().parents[1]
spec = importlib.util.spec_from_file_location('remote', ROOT / 'container/remote-access/service.py')
remote = importlib.util.module_from_spec(spec)
spec.loader.exec_module(remote)


class Validation(unittest.TestCase):
    def test_normalized_hostname(self):
        self.assertEqual(remote.website(' https://Play.Example.net:443/ '), 'https://play.example.net')

    def test_reject_url_injection(self):
        for value in ['http://play.example.net', 'https://127.0.0.1', 'https://[::1]',
                      'https://u:p@play.example.net', 'https://play.example.net:8081',
                      'https://play.example.net/a', 'https://play.example.net?q=1',
                      'https://play.example.net#x', 'https://-a.example.net',
                      'https://a..example.net', 'https://a.example.net\n;id',
                      'https://a.' + 'b'*64 + '.net', {}, None]:
            with self.subTest(value=value), self.assertRaises(remote.UserError):
                remote.website(value)

    def test_private_destination_rejected_with_public_control(self):
        self.assertTrue(remote.public_ip('1.1.1.1'))
        for ip in ['127.0.0.1','10.0.0.1','172.17.0.1','192.168.1.1','169.254.169.254',
                   '100.64.0.1','224.0.0.1','0.0.0.0','::ffff:127.0.0.1','::1','2001:db8::1']:
            with self.subTest(ip=ip): self.assertFalse(remote.public_ip(ip))

    def test_login_links_are_provider_only(self):
        self.assertEqual(remote.provider_link('https://login.tailscale.com/a/abc'), 'https://login.tailscale.com/a/abc')
        for value in ['javascript:alert(1)', 'https://login.tailscale.com.evil.example/a',
                      'https://login.tailscale.com@evil.example/a', 'http://login.tailscale.com/a',
                      'https://u:p@login.tailscale.com/a', 'https://login.tailscale.com:8081/a']:
            self.assertEqual(remote.provider_link(value), '')


class Lifecycle(unittest.TestCase):
    def setUp(self):
        self.temp = tempfile.TemporaryDirectory()
        self.base = Path(self.temp.name)
        self.paths = patch.multiple(remote, STATE=self.base, LINK=self.base, RUN=self.base)
        self.paths.start()
        self.ownership = patch.object(remote.os, 'chown')
        self.ownership.start()
        self.c = remote.Controller()

    def tearDown(self):
        self.ownership.stop(); self.paths.stop(); self.temp.cleanup()

    def save_cf(self):
        return self.c.action({'action':'save','provider':'cloudflare','url':'https://play.example.net','token':'A'*120})

    def test_defaults_off(self):
        self.assertFalse(self.c.snapshot()['config']['enabled'])
        self.assertEqual(self.c.snapshot()['observed']['state'], 'Off')

    def test_secret_is_not_echoed(self):
        result = self.save_cf()
        self.assertTrue(result['config']['tokenSaved'])
        self.assertNotIn('A'*120, json.dumps(result))
        self.assertEqual((self.base/'cloudflare.token').read_text(), 'A'*120)
        self.assertEqual((self.base/'cloudflare.token').stat().st_mode & 0o777, 0o600)
        self.assertFalse(result['config']['enabled'])

    def test_nonsecret_challenge_permissions_survive_private_umask(self):
        import os
        old = os.umask(0o077)
        try:
            remote.atomic(self.base/'challenge.json', {'nonce':'test'}, 0o644)
            self.assertEqual((self.base/'challenge.json').stat().st_mode & 0o777, 0o644)
        finally:
            os.umask(old)

    def test_reject_command_and_preserve_saved_token(self):
        self.save_cf()
        with self.assertRaises(remote.UserError):
            self.c.action({'action':'save','provider':'cloudflare','url':'https://play.example.net','token':'cloudflared tunnel run --token ' + 'B'*100})
        self.assertEqual((self.base/'cloudflare.token').read_text(), 'A'*120)

    def test_blank_token_preserves_secret(self):
        self.save_cf()
        self.c.action({'action':'save','provider':'cloudflare','url':'https://new.example.net','token':''})
        self.assertEqual((self.base/'cloudflare.token').read_text(), 'A'*120)

    def test_connect_sets_desired_state_not_success(self):
        self.save_cf()
        with patch.object(self.c,'start'), patch.object(self.c,'begin_test'):
            result = self.c.action({'action':'connect'})
        self.assertTrue(result['config']['enabled'])
        self.assertNotEqual(result['observed']['state'], 'Connected')

    def test_failed_save_does_not_enable_connection(self):
        self.save_cf()
        with patch.object(remote,'atomic',side_effect=OSError('full')), patch.object(self.c,'start') as start:
            with self.assertRaises(remote.UserError): self.c.action({'action':'connect'})
            start.assert_not_called()
        self.assertFalse(self.c.config['enabled'])

    def test_active_edit_is_refused(self):
        self.save_cf(); self.c.config['enabled'] = True
        with self.assertRaises(remote.UserError):
            self.c.action({'action':'save','provider':'tailscale'})
        self.assertEqual(self.c.config['provider'], 'cloudflare')

    def test_disconnect_persists_off_and_removes_challenge(self):
        self.save_cf(); self.c.config['enabled'] = True
        (self.base/'challenge.json').write_text('{}')
        result = self.c.action({'action':'disconnect'})
        self.assertFalse(result['config']['enabled'])
        self.assertFalse((self.base/'challenge.json').exists())
        self.assertTrue((self.base/'cloudflare.token').exists())
        self.assertFalse(remote.Controller().config['enabled'])

    def test_forget_removes_local_credentials(self):
        self.save_cf()
        with patch.object(remote,'run') as command:
            command.return_value.returncode = 0
            self.c.action({'action':'forget'})
        self.assertFalse((self.base/'cloudflare.token').exists())
        self.assertEqual(self.c.config['provider'], '')

    def test_tailscale_requires_observed_login(self):
        self.c.action({'action':'save','provider':'tailscale'})
        with self.assertRaises(remote.UserError): self.c.action({'action':'connect'})
        self.assertFalse(self.c.config['enabled'])

    def test_tailscale_serve_approval_timeout_and_recovery(self):
        self.c.config.update(provider='tailscale', enabled=True)
        status = {'BackendState':'Running', 'Self':{'DNSName':'fixture.example.ts.net.'}}
        approval = 'https://login.tailscale.com/f/serve?node=fixture'
        calls = []
        approved = False
        def command(args, timeout):
            calls.append(args)
            if args[len(remote.TS):] == ['status', '--json']:
                return remote.subprocess.CompletedProcess(args, 0, json.dumps(status), '')
            if '--bg' in args and not approved:
                raise remote.subprocess.TimeoutExpired(args, timeout,
                    output=('Serve is not enabled on your tailnet.\nTo enable, visit:\n' + approval).encode())
            return remote.subprocess.CompletedProcess(args, 0, '{"Web":{"fixture":{}}}', '')
        with patch.object(remote, 'run', side_effect=command), patch.object(self.c, 'gateway_start'), patch.object(remote.time, 'time', return_value=100):
            self.c.observe()
            self.assertEqual(self.c.observed['state'], 'HTTPS approval required')
            self.assertEqual(self.c.observed['details']['backend'], 'Running')
            self.assertEqual(self.c.snapshot()['authUrl'], approval)
            self.assertEqual(self.c.config['url'], 'https://fixture.example.ts.net')
            self.c.observe()
            self.assertEqual(self.c.auth_url, approval)
            self.assertEqual(sum('--bg' in x for x in calls), 1, 'Approval retries must back off')
        approved = True
        with patch.object(remote, 'run', side_effect=command), patch.object(self.c, 'gateway_start'), patch.object(remote.time, 'time', return_value=111):
            self.c.observe()
        self.assertEqual(self.c.observed['state'], 'Connected')
        self.assertEqual(self.c.auth_url, '')
        self.assertEqual(self.c.error, '')

    def test_tailscale_timeout_rejects_untrusted_approval_url(self):
        self.c.config.update(provider='tailscale', enabled=True)
        def command(args, timeout):
            if '--bg' in args:
                raise remote.subprocess.TimeoutExpired(args, timeout, output=b'https://login.tailscale.com.evil.example/approve')
            return remote.subprocess.CompletedProcess(args, 0,
                '{"BackendState":"Running","Self":{"DNSName":"fixture.example.ts.net"}}', '')
        with patch.object(remote, 'run', side_effect=command), patch.object(self.c, 'gateway_start'):
            self.c.observe()
        self.assertEqual(self.c.auth_url, '')
        self.assertIn('Private HTTPS could not start', self.c.error)
        self.assertEqual(self.c.observed['details']['backend'], 'Running')

    def test_no_test_when_off(self):
        self.save_cf()
        with self.assertRaises(remote.UserError): self.c.action({'action':'test'})

    def test_cancel_test_does_not_disconnect(self):
        self.save_cf(); self.c.config['enabled'] = True
        self.c.test = {'state':'Running'}
        self.c.action({'action':'cancelTest'})
        self.assertTrue(self.c.config['enabled'])
        self.assertEqual(self.c.test['state'],'Cancelled')

    def test_old_job_cannot_change_new_test(self):
        self.save_cf()
        old = {'revision':self.c.config['revision'], 'steps':{}}
        self.c.test = {'revision':self.c.config['revision']+1, 'steps':{}}
        self.c.step(old,'route','Passed','stale result')
        self.assertEqual(self.c.test['steps'],{})

    def test_device_proof_bound_to_nonce(self):
        import time
        self.c.test = {'nonce':'good', 'device':'Not tested', 'state':'Needs device test'}
        (self.base/'device-test.json').write_text(json.dumps({'nonce':'wrong','time':time.time()}))
        self.assertEqual(self.c.snapshot()['test']['device'],'Not tested')
        (self.base/'device-test.json').write_text(json.dumps({'nonce':'good','time':time.time()}))
        self.assertIn('browser reached',self.c.snapshot()['test']['device'])

    def diagnostic(self, mode='good'):
        import time, threading
        from unittest.mock import MagicMock
        self.c.config['enabled'] = False
        self.save_cf()
        self.c.config['enabled'] = True
        self.c.observed = {'state':'Connected', 'time':time.time(), 'details':{}}
        test = {'revision':self.c.config['revision'], 'state':'Running', 'steps':{}}
        self.c.test = test
        def response():
            challenge = json.loads((self.base/'challenge.json').read_text())
            challenge['payload'] = 'phvalheim'*128
            return challenge
        local = MagicMock()
        local.getresponse.return_value.status = 200
        local.getresponse.return_value.read.side_effect = lambda n: json.dumps(response()).encode()
        public = MagicMock()
        public.sock.getpeercert.return_value = {'notAfter':'test expiry'}
        public.getresponse.return_value.status = 302 if mode == 'redirect' else 200
        def body(n):
            value = response()
            if mode == 'wrong_instance': value['instance'] = 'another instance'
            if mode == 'cached': value['nonce'] = 'old nonce'
            if mode == 'truncated': value['payload'] = 'short'
            return b'<html>Access login</html>' if mode == 'access_page' else json.dumps(value).encode()
        public.getresponse.return_value.read.side_effect = body
        if mode == 'tls': public.connect.side_effect = remote.ssl.SSLCertVerificationError('mismatch')
        ip = '127.0.0.1' if mode == 'private_dns' else '1.1.1.1'
        with patch.object(remote,'UnixHTTP',return_value=local), patch.object(remote,'PinnedHTTPS',return_value=public) as https, patch.object(remote.socket,'getaddrinfo',return_value=[(2,1,6,'',(ip,443))]):
            self.c.diagnostics(test,threading.Event())
            if mode == 'private_dns': https.assert_not_called()
        return test

    def test_diagnostic_positive_control(self):
        test = self.diagnostic()
        self.assertEqual(test['state'],'URL verified from this server')
        self.assertEqual(set(s['state'] for s in test['steps'].values()), {'Passed'})

    def test_diagnostic_refuses_false_success(self):
        for mode, step in [('wrong_instance','route'),('cached','route'),('access_page','route'),('redirect','route'),('truncated','transfer'),('tls','tls'),('private_dns','dns')]:
            with self.subTest(mode=mode):
                test = self.diagnostic(mode)
                self.assertEqual(test['state'],'Check failed')
                self.assertEqual(test['steps'][step]['state'],'Failed')


if __name__ == '__main__': unittest.main()
