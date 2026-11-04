import importlib.util
import io
import json
import os
import struct
import tempfile
import unittest
import urllib.error
from pathlib import Path
from unittest.mock import patch

ROOT = Path(__file__).resolve().parents[1]
spec = importlib.util.spec_from_file_location('transport', ROOT/'container/engine/tools/analyticsTransport.py')
t = importlib.util.module_from_spec(spec); spec.loader.exec_module(t)

class Enrollment(unittest.TestCase):
    def setUp(self):
        self.tmp=tempfile.TemporaryDirectory(); self.state=Path(self.tmp.name)/'analytics'
        self.payload={'uuid':'old-public-uuid','version':'2.61','hostname':'test.invalid'}
        self.calls=[]
    def tearDown(self): self.tmp.cleanup()
    def remote(self,path,payload,token):
        self.calls.append((path,payload,token))
        return {'success':True,'uuid':payload['uuid']}
    def test_persistence_permissions_restart(self):
        with patch.object(t,'request',self.remote):
            t.submit(self.payload,self.state); t.submit(self.payload,self.state)
        self.assertEqual([c[0] for c in self.calls],['/api/enroll','/api/ingest','/api/ingest'])
        credential=json.loads((self.state/'credential.json').read_text())
        self.assertNotEqual(credential['uuid'],self.payload['uuid'])
        self.assertEqual(len(credential['token']),43)
        self.assertEqual((self.state/'credential.json').stat().st_mode & 0o777,0o600)
        self.assertEqual(len({c[2] for c in self.calls}),1)
    def test_lost_enrollment_response_reuses_identity(self):
        def fail(*args):
            self.calls.append(args);raise OSError('lost response')
        with patch.object(t,'request',fail), self.assertRaises(OSError): t.submit(self.payload,self.state)
        with patch.object(t,'request',self.remote): t.submit(self.payload,self.state)
        self.assertEqual(self.calls[0][1:],self.calls[1][1:])
    def test_unauthorized_never_resets_identity(self):
        with patch.object(t,'request',self.remote): t.submit(self.payload,self.state)
        before=(self.state/'credential.json').read_bytes()
        with patch.object(t,'request',side_effect=urllib.error.HTTPError('url',401,'denied',{},None)), self.assertRaises(urllib.error.HTTPError):
            t.submit(self.payload,self.state)
        self.assertEqual(before,(self.state/'credential.json').read_bytes())
    def test_opt_out_does_not_enroll(self):
        with patch.object(t,'request',self.remote): t.submit(dict(self.payload,analytics_disabled=True),self.state)
        self.assertEqual(self.calls,[])
        self.assertFalse((self.state/'credential.json').exists())
    def test_old_version_rejected(self):
        with patch.object(t,'request',self.remote), self.assertRaises(ValueError): t.submit(dict(self.payload,version='2.60'),self.state)
        self.assertEqual(self.calls,[])
    def test_symlink_and_public_file_rejected(self):
        self.state.mkdir(mode=0o700);target=Path(self.tmp.name)/'target';target.write_text('{}')
        (self.state/'credential.json').symlink_to(target)
        with self.assertRaises(OSError): t.submit(self.payload,self.state)
        (self.state/'credential.json').unlink();(self.state/'credential.json').write_text('{}')
        with self.assertRaises(ValueError): t.submit(self.payload,self.state)
    def test_redirect_refused(self):
        self.assertIsNone(t.NoRedirect().redirect_request(None,None,None,None,None,None))

    def test_inherited_acl_creation_modes_and_restart(self):
        # The affected ZFS mount creates both directories AND files as 0770,
        # even when mkdir/open/mkstemp request 0700/0600. chmod is supported.
        mkdir, op, mkstemp = Path.mkdir, os.open, tempfile.mkstemp
        def inherited_dir(path, *args, **kwargs):
            mkdir(path, *args, **kwargs)
            os.chmod(path, 0o770)
        def inherited_file(path, flags, *args, **kwargs):
            fd = op(path, flags, *args, **kwargs)
            if flags & os.O_CREAT: os.fchmod(fd, 0o770)
            return fd
        def inherited_temp(*args, **kwargs):
            fd, name = mkstemp(*args, **kwargs)
            os.fchmod(fd, 0o770)
            return fd, name
        def receiver(path, payload, token):
            self.assertEqual(self.state.stat().st_mode & 0o777, 0o700)
            for p in self.state.iterdir():
                self.assertEqual(p.stat().st_mode & 0o777, 0o600)
            return self.remote(path, payload, token)
        with patch.object(Path, 'mkdir', inherited_dir), patch.object(t.os, 'open', inherited_file), \
             patch.object(t.tempfile, 'mkstemp', inherited_temp), patch.object(t, 'request', receiver):
            t.submit(self.payload, self.state)
            before = (self.state/'credential.json').read_bytes()
            t.submit(self.payload, self.state)
        self.assertEqual(before, (self.state/'credential.json').read_bytes())
        self.assertEqual([c[0] for c in self.calls], ['/api/enroll','/api/ingest','/api/ingest'])

    def test_filesystem_refuses_private_modes(self):
        self.state.mkdir(mode=0o770)
        os.chmod(self.state, 0o770)
        with patch.object(t.os, 'fchmod'), patch.object(t, 'request', self.remote), \
             self.assertRaises(t.PrivateStorageError):
            t.submit(self.payload, self.state)
        self.assertEqual(self.calls, [])
        self.assertFalse((self.state/'credential.json').exists())

    def test_symlink_directory_never_modified(self):
        target = Path(self.tmp.name)/'target'
        target.mkdir(mode=0o755)
        before = target.stat().st_mode
        self.state.symlink_to(target, target_is_directory=True)
        with patch.object(t, 'request', self.remote), self.assertRaises(OSError):
            t.submit(self.payload, self.state)
        self.assertEqual(before, target.stat().st_mode)
        self.assertEqual(self.calls, [])

    def test_private_mode_does_not_hide_named_acl_grants(self):
        # A named user may retain data access even when stat reports mode 0600.
        public_acl = struct.pack('>7I', 0, 1, 0, 0, 0, 0x1f01ff, 568)
        with patch.object(t.os, 'getxattr', return_value=public_acl), \
             patch.object(t.os, 'setxattr') as setter, self.assertRaises(t.PrivateStorageError):
            t.private_acl(123, 0o600)
        setter.assert_not_called()

    def test_acl_write_must_be_retained(self):
        public_acl = struct.pack('>7I', 0, 1, 0, 0, 0, 0x1f01ff, 568)
        with patch.object(t.os, 'getxattr', return_value=public_acl), \
             patch.object(t.os, 'setxattr'), self.assertRaises(t.PrivateStorageError):
            t.private_acl(123, 0o700, tighten=True)

    def test_requests_identify_phvalheim_to_the_edge(self):
        seen = []
        class Edge:
            def open(self, req, timeout):
                # Model the deployed edge's generic-client rejection.
                if req.get_header('User-agent') != 'PhValheim-Server/2.62':
                    raise urllib.error.HTTPError(req.full_url, 403, 'client rejected', {}, None)
                seen.append(req.full_url)
                return io.BytesIO(b'{"success":true}')
        with patch.object(t.urllib.request, 'build_opener', return_value=Edge()):
            for path in ('/api/enroll', '/api/ingest'):
                self.assertTrue(t.request(path, {'version':'2.62'}, 'test-credential')['success'])
        self.assertEqual(len(seen), 2)

if __name__=='__main__':unittest.main()
