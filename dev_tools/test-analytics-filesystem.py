#!/usr/bin/env python3
# requires: container
"""Run as root inside a disposable container with /opt/stateful on the target filesystem.

Usage: python3 test-analytics-filesystem.py /opt/stateless/engine/tools/analyticsTransport.py
Only temporary fixtures are written; provider requests are stubbed, no reports sent.
Exercises root startup, service-account retry, credential persistence and denied reads.
"""
import os,sys,tempfile,shutil,importlib.util,json
from pathlib import Path
source=sys.argv[1]
spec=importlib.util.spec_from_file_location('transport',source)
t=importlib.util.module_from_spec(spec);spec.loader.exec_module(t)
root=Path(tempfile.mkdtemp(prefix='.phv-enrollment-test-',dir='/opt/stateful'))
os.chown(root,1000,1000);os.chmod(root,0o755)
state=root/'analytics'
# Reproduce the failed existing installation, including inherited ZFS ACLs.
state.mkdir(mode=0o770);os.chown(state,1000,1000)
payload=root/'payload.json';payload.write_text(json.dumps({'version':'2.62'}));os.chmod(payload,0o644)
def worker(uid,fn):
 pid=os.fork()
 if pid==0:
  try:
   if uid:
    os.setgroups([]);os.setgid(uid);os.setuid(uid)
   fn();sys.stdout.flush();os._exit(0)
  except BaseException as e:
   print('FAIL:',type(e).__name__,str(e),flush=True);os._exit(1)
 _,status=os.waitpid(pid,0)
 assert status==0, 'worker failed'
def run(first):
 t.STATE=state
 original=t.submit
 t.submit=lambda p: original(p,state)
 calls=[]
 def request(path,p,token):
  calls.append(path)
  assert state.stat().st_mode&0o777==0o700
  assert (state/'credential.json').stat().st_mode&0o777==0o600
  return {'success':True,'uuid':p['uuid']}
 t.request=request;sys.argv=['transport',str(payload)]
 assert t.main()==0
 assert calls==(['/api/enroll','/api/ingest'] if first else ['/api/ingest'])
try:
 worker(0,lambda:run(True))
 before=(state/'credential.json').read_bytes()
 worker(1000,lambda:run(False))
 assert before==(state/'credential.json').read_bytes()
 print('PASS: root startup and service-account retry preserve private identity',flush=True)
 for uid in (568,1001,65534):
  def denied():
   try:(state/'credential.json').read_bytes()
   except PermissionError:return
   raise AssertionError('unrelated account could read credential')
  worker(uid,denied)
 print('PASS: inherited named account and unrelated accounts cannot read credential',flush=True)
 print('PASS: directory 0700; lock and credential 0600',flush=True)
finally:shutil.rmtree(root)
