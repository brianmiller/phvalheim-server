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

    def test_fresh_enrollment_does_not_require_a_network_map(self):
        from types import SimpleNamespace
        self.c.enrolling = True
        with patch.object(remote, 'run', return_value=SimpleNamespace(stdout='{"BackendState":"NeedsLogin"}')) as run, patch.object(self.c, 'spawn') as spawn:
            self.c.enroll('')
        self.assertEqual(run.call_count, 1)
        self.assertIn('up', spawn.call_args.args[0])
        self.assertTrue(self.c.enrolling)

    def test_test_cooldown_reuses_job_and_exposes_deadline(self):
        self.c.config['enabled'] = True
        with patch.object(remote.time, 'time', return_value=100), patch.object(remote.threading.Thread, 'start') as start:
            first = self.c.action({'action':'test'})
            self.c.test['state'] = 'URL verified from this server'
            again = self.c.action({'action':'test'})
            self.assertEqual(again['test']['id'], first['test']['id'])
            self.assertEqual(again['testAvailableAt'], 110)
            self.assertTrue(again['success'])
            self.assertEqual(start.call_count, 1)
        with patch.object(remote.time, 'time', return_value=110), patch.object(remote.threading.Thread, 'start') as start:
            next_test = self.c.action({'action':'test'})
            self.assertNotEqual(next_test['test']['id'], first['test']['id'])
            self.assertEqual(start.call_count, 1)

    def test_both_providers_schedule_without_browser_and_never_overlap(self):
        for provider in ['tailscale', 'cloudflare']:
            with self.subTest(provider=provider):
                self.c.config.update(provider=provider, enabled=True)
                self.c.observed['state'] = 'Connected'
                self.c.test = None; self.c.last_test = 0; self.c.next_check = 0
                with patch.object(remote.time, 'time', return_value=1000), patch.object(remote.threading.Thread, 'start') as start:
                    self.c.schedule_check()
                    self.assertEqual(start.call_count, 1)
                    self.assertEqual(self.c.snapshot()['nextCheckAt'], 1240)
                    self.c.schedule_check()
                    self.assertEqual(start.call_count, 1)
                self.c.test['state'] = 'URL verified from this server'
                with patch.object(remote.time, 'time', return_value=1239), patch.object(remote.threading.Thread, 'start') as start:
                    self.c.schedule_check(); start.assert_not_called()
                with patch.object(remote.time, 'time', return_value=1240), patch.object(remote.threading.Thread, 'start') as start:
                    self.c.schedule_check(); self.assertEqual(start.call_count, 1)
                self.c.test['state'] = 'Check failed'; self.c.next_check = 1300
                with patch.object(remote.time, 'time', return_value=1300), patch.object(remote.threading.Thread, 'start') as start:
                    self.c.schedule_check(); self.assertEqual(start.call_count, 1)
                self.c.test = None; self.c.config['enabled'] = False
                with patch.object(remote.time, 'time', return_value=2000), patch.object(remote.threading.Thread, 'start') as start:
                    self.c.schedule_check(); start.assert_not_called()
                self.c.config['enabled'] = True; self.c.observed['state'] = 'Connecting'
                with patch.object(remote.time, 'time', return_value=2000), patch.object(remote.threading.Thread, 'start') as start:
                    self.c.schedule_check(); start.assert_not_called()

    def test_cancel_defers_automatic_check_without_disconnect(self):
        self.c.config.update(provider='cloudflare', enabled=True)
        self.c.observed['state']='Connected'
        with patch.object(remote.time,'time',return_value=1000), patch.object(remote.threading.Thread,'start'):
            self.c.begin_test(); self.c.action({'action':'cancelTest'})
        self.assertEqual(self.c.next_check,1060)
        self.assertTrue(self.c.config['enabled'])
        with patch.object(remote.time,'time',return_value=1059), patch.object(remote.threading.Thread,'start') as start:
            self.c.schedule_check(); start.assert_not_called()
        with patch.object(remote.time,'time',return_value=1060), patch.object(remote.threading.Thread,'start') as start:
            self.c.schedule_check(); self.assertEqual(start.call_count,1)

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

    def test_tailscale_funnel_approval_timeout_and_recovery(self):
        self.c.config.update(provider='tailscale', enabled=True, publicAccess=True)
        status = {'BackendState':'Running', 'Self':{'DNSName':'fixture.example.ts.net.'}}
        approval = 'https://login.tailscale.com/f/funnel?node=fixture'
        calls = []
        approved = False
        def command(args, timeout):
            calls.append(args)
            if args[len(remote.TS):] == ['status', '--json']:
                return remote.subprocess.CompletedProcess(args, 0, json.dumps(status), '')
            if '--bg' in args:
                self.assertEqual(args[len(remote.TS)], 'funnel')
            if '--bg' in args and not approved:
                raise remote.subprocess.TimeoutExpired(args, timeout,
                    output=('Funnel is not enabled on your tailnet.\nTo enable, visit:\n' + approval).encode())
            return remote.subprocess.CompletedProcess(args, 0, json.dumps({'TCP':{'443':{'HTTPS':True}}, 'Web':{'fixture.example.ts.net:443':{'Handlers':{'/':{'Proxy':remote.ORIGIN}}}}, 'AllowFunnel':{'fixture.example.ts.net:443':True}}), '')
        with patch.object(remote, 'run', side_effect=command), patch.object(self.c, 'gateway_start'), patch.object(remote.time, 'time', return_value=100):
            self.c.observe()
            self.assertEqual(self.c.observed['state'], 'Public access approval required')
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
        self.c.config.update(provider='tailscale', enabled=True, publicAccess=True)
        def command(args, timeout):
            if '--bg' in args:
                raise remote.subprocess.TimeoutExpired(args, timeout, output=b'https://login.tailscale.com.evil.example/approve')
            return remote.subprocess.CompletedProcess(args, 0,
                '{"BackendState":"Running","Self":{"DNSName":"fixture.example.ts.net"}}', '')
        with patch.object(remote, 'run', side_effect=command), patch.object(self.c, 'gateway_start'):
            self.c.observe()
        self.assertEqual(self.c.auth_url, '')
        self.assertIn('Public HTTPS could not start', self.c.error)
        self.assertEqual(self.c.observed['details']['backend'], 'Running')

    def test_legacy_private_config_does_not_publish_on_upgrade(self):
        legacy = {'provider':'tailscale', 'enabled':True, 'url':'https://fixture.example.ts.net', 'revision':3}
        remote.atomic(self.base/'config.json', legacy)
        identity = self.c.instance
        upgraded = remote.Controller()
        self.assertFalse(upgraded.config['enabled'])
        self.assertFalse(json.loads((self.base/'config.json').read_text())['enabled'])
        self.assertEqual(upgraded.instance, identity)
        self.assertIn('Publish website', upgraded.error)

    def test_public_connect_requires_explicit_intent_and_persists(self):
        self.c.action({'action':'save','provider':'tailscale'})
        self.c.ts_state = 'Running'
        with self.assertRaises(remote.UserError): self.c.action({'action':'connect'})
        self.assertFalse(self.c.config['enabled'])
        with patch.object(self.c, 'start'), patch.object(self.c, 'begin_test'), patch.object(remote.threading.Thread, 'start'):
            self.c.action({'action':'connect','publicAccess':True})
        restarted = remote.Controller()
        self.assertTrue(restarted.config['enabled'])
        self.assertTrue(restarted.config['publicAccess'])

    def test_reconnect_restores_identity_without_an_extra_enroll_action(self):
        self.c.config.update(provider='tailscale', enabled=False, enrolled=True, hostname='my-world', publicAccess=True)
        self.c.ts_state = ''
        identity = self.c.instance
        with patch.object(self.c, 'start') as start, patch.object(remote.threading.Thread, 'start') as worker:
            result = self.c.action({'action':'connect','publicAccess':True})
        start.assert_called_once(); worker.assert_called_once()
        self.assertTrue(result['config']['enabled'])
        self.assertTrue(self.c.pending_test)
        self.assertIsNone(result['test'])
        self.assertEqual(self.c.instance, identity)
        self.c.action({'action':'disconnect'})
        self.assertTrue(self.c.config['enrolled'])
        self.assertEqual(self.c.config['hostname'], 'my-world')
        self.assertFalse(self.c.pending_test)

    def test_rename_offline_preserves_identity_and_invalidates_old_url(self):
        self.c.config.update(provider='tailscale', enabled=False, url='https://old.example.ts.net')
        identity = self.c.instance
        result = self.c.action({'action':'hostname','hostname':'New-World'})
        self.assertEqual(result['config']['hostname'], 'new-world')
        self.assertEqual(result['config']['url'], '')
        self.assertTrue(result['config']['enrolled'])
        self.assertFalse(result['config']['enabled'])
        self.assertEqual(remote.Controller().config['hostname'], 'new-world')
        self.assertEqual(self.c.instance, identity)
        for name in ['', '-bad', 'bad-', 'https://example.net', 'two.words', 'a'*64, 'x;id', None]:
            with self.subTest(name=name), self.assertRaises(remote.UserError):
                self.c.action({'action':'hostname','hostname':name})
        self.c.config['enabled'] = True
        with self.assertRaises(remote.UserError): self.c.action({'action':'hostname','hostname':'other'})

    def test_rename_waits_for_provider_assigned_url_before_publishing(self):
        self.c.config.update(provider='tailscale', enabled=True, publicAccess=True, hostname='new-world', url='')
        status = {'BackendState':'Running', 'Self':{'HostName':'old', 'DNSName':'old.example.ts.net'}}
        calls=[]
        def command(args, timeout):
            calls.append(args)
            result = status if args[len(remote.TS)] == 'status' else {'TCP':{'443':{'HTTPS':True}}, 'Web':{'new-world-1.example.ts.net:443':{'Handlers':{'/':{'Proxy':remote.ORIGIN}}}}, 'AllowFunnel':{'new-world-1.example.ts.net:443':True}}
            return remote.subprocess.CompletedProcess(args,0,json.dumps(result),'')
        with patch.object(remote,'run',side_effect=command), patch.object(self.c,'gateway_start'):
            self.c.observe()
            self.assertEqual(self.c.observed['state'],'Updating hostname')
            self.assertEqual(self.c.config['url'],'')
            self.assertIn(remote.TS+['set','--hostname=new-world'],calls)
            self.assertFalse(any('funnel' in c for c in calls))
            status['Self'].update(HostName='new-world',DNSName='new-world-1.example.ts.net')
            self.c.pending_test=True
            with patch.object(self.c,'begin_test') as test:
                self.c.observe(); test.assert_called_once()
            self.assertEqual(self.c.observed['state'],'Connected')
            self.assertEqual(self.c.config['url'],'https://new-world-1.example.ts.net')

    def test_cancelled_enrollment_cannot_mutate_new_connection(self):
        self.c.enrolling=True
        self.c.config['revision']=5
        with patch.object(remote,'run') as run, patch.object(self.c,'spawn') as spawn:
            self.c.enroll(False,4)
        run.assert_not_called(); spawn.assert_not_called()

    def test_private_serve_config_cannot_report_public_connection(self):
        self.c.config.update(provider='tailscale', enabled=True, publicAccess=True)
        self.c.serving = True
        def command(args, timeout):
            value = {'BackendState':'Running', 'Self':{'DNSName':'fixture.example.ts.net'}} if args[len(remote.TS)] == 'status' else {'TCP':{'443':{'HTTPS':True}},'Web':{'fixture.example.ts.net:443':{'Handlers':{'/':{'Proxy':remote.ORIGIN}}}}}
            return remote.subprocess.CompletedProcess(args, 0, json.dumps(value), '')
        with patch.object(remote,'run',side_effect=command): self.c.observe()
        self.assertFalse(self.c.serving)
        self.assertNotEqual(self.c.observed['state'], 'Connected')

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

    def diagnostic(self, mode='good', provider='cloudflare'):
        import time, threading
        from unittest.mock import MagicMock
        self.c.config['enabled'] = False
        self.save_cf()
        self.c.config.update(enabled=True, provider=provider, publicAccess=True)
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
        if mode == 'timeout': public.connect.side_effect = TimeoutError('starting')
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
        self.assertEqual(self.c.last_verified['revision'], test['revision'])
        self.assertAlmostEqual(self.c.next_check - self.c.last_verified['at'], 240, delta=1)

    def test_tailscale_checks_public_dns_tls_and_correct_instance(self):
        test = self.diagnostic(provider='tailscale')
        self.assertEqual(test['state'], 'URL verified from this server')
        self.assertEqual(set(s['state'] for s in test['steps'].values()), {'Passed'})
        for mode in ['private_dns','wrong_instance','tls']:
            with self.subTest(mode=mode):
                self.assertEqual(self.diagnostic(mode, provider='tailscale')['state'], 'Check failed')

    def test_public_startup_retry_is_bounded_and_never_masks_invalid_routes(self):
        import time
        self.c.verify_until=time.time()+120
        test=self.diagnostic('timeout',provider='tailscale')
        self.assertEqual(test['state'],'Retrying public website')
        self.assertTrue(self.c.pending_test)
        self.c.verify_until=time.time()-1
        self.assertEqual(self.diagnostic('timeout',provider='tailscale')['state'],'Check failed')
        self.c.verify_until=time.time()+120
        for mode in ['tls','private_dns','wrong_instance']:
            self.assertEqual(self.diagnostic(mode,provider='tailscale')['state'],'Check failed')
        self.c.action({'action':'cancelTest'})
        self.assertFalse(self.c.pending_test)
        self.assertEqual(self.c.verify_until,0)

    def test_failed_refresh_clears_previous_success_for_both_providers(self):
        for provider in ['tailscale','cloudflare']:
            self.diagnostic(provider=provider)
            self.assertIsNotNone(self.c.last_verified)
            self.diagnostic('tls',provider=provider)
            self.assertIsNone(self.c.last_verified)

    def test_diagnostic_refuses_false_success(self):
        for mode, step in [('wrong_instance','route'),('cached','route'),('access_page','route'),('redirect','route'),('truncated','transfer'),('tls','tls'),('private_dns','dns')]:
            with self.subTest(mode=mode):
                test = self.diagnostic(mode)
                self.assertEqual(test['state'],'Check failed')
                self.assertEqual(test['steps'][step]['state'],'Failed')
                self.assertIsNone(self.c.last_verified)
                import time
                self.assertAlmostEqual(self.c.next_check - time.time(), 60, delta=1)


if __name__ == '__main__': unittest.main()
