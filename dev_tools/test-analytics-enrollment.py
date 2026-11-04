import importlib.util
import json
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

if __name__=='__main__':unittest.main()
