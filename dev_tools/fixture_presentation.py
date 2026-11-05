#!/usr/bin/env python3
"""Create an isolated exact-image fixture for test-presentation-browser.py.
PHV_PRESENTATION_IMAGE selects a local image; PHV_PRESENTATION_FIXTURE selects output JSON.
Remove only the printed container and volume after testing.
"""
import sys,subprocess,time,json,urllib.request,os,uuid
from pathlib import Path
sys.path.insert(0,str(Path(__file__).resolve().parent))
os.chdir(Path(__file__).resolve().parent.parent)
from fixture_analytics import docker_args,verify_no_enrollment
name='phv-presentation-'+uuid.uuid4().hex[:8];vol=name+'-data'
def run(*a):return subprocess.check_output(a,text=True).strip()
run('docker','volume','create',vol)
run('docker','run',*docker_args(),'-e','phvalheimDevSteamID=76561198000000001','-d','--name',name,'-p','127.0.0.1::8081','-p','127.0.0.1::8080','-v',vol+':/opt/stateful',os.environ.get('PHV_PRESENTATION_IMAGE','phvalheim-themes:validation'))
admin='http://'+run('docker','port',name,'8081/tcp');public='http://'+run('docker','port',name,'8080/tcp')
for i in range(90):
 try:
  data=json.load(urllib.request.urlopen(admin+'/adminAPI.php?action=getServerSettings',timeout=4))
  if data.get('success'):break
 except Exception:pass
 time.sleep(1)
else:raise AssertionError('startup')
run('docker','exec',name,'bash','/opt/stateless/engine/dbUpdates/dbUpdate_2.62.sh')
run('docker','exec',name,'mysql','-uphvalheim_user','-pphvalheim_secretpassword','phvalheim','-e',"UPDATE settings SET setupComplete=1,migrationNoticeShown=1,gameDNS='fixture.invalid',steamApiKey='fixture';")
verify_no_enrollment(name)
Path(os.environ.get('PHV_PRESENTATION_FIXTURE','/tmp/phv-presentation-fixture.json')).write_text(json.dumps({'name':name,'volume':vol,'admin':admin,'public':public}))
print(admin,public)

sql="INSERT INTO worlds (name,port,public,mode,status,seed,world_md5,date_deployed,date_updated,trophyeikthyr,trophytheelder,trophybonemass,password,password_public,vanilla) VALUES ('Midgard',2456,1,'running','ready','VikingSeed','abcdef123456','2026-11-01','2026-11-05',1,1,1,'testpass',1,0),('Ravenholm',2458,1,'stopped','ready','HiddenGrove','abcdef123456','2026-11-01','2026-11-05',1,0,0,'',1,0),('Meadows',2460,1,'stopped','ready','SunnyValley','abcdef123456','2026-11-01','2026-11-05',0,0,0,'testpass',1,1);"
run('docker','exec',name,'mysql','-uphvalheim_user','-pphvalheim_secretpassword','phvalheim','-e',sql)
# Record a process with the world's name; no actual Valheim installation needed.
run('docker','exec','-d',name,'bash','-c','exec -a "valheim_server -name Midgard " sleep 1800')
# Replace only the external Steam profile response and enable viewing login with the
# test bypass on other pages. All application markup/CSS/JS comes from the image.
import tempfile
with tempfile.TemporaryDirectory() as temp:
 auth=run('docker','exec',name,'cat','/opt/stateless/nginx/www/public/authenticated.php')
 a=auth.index('$steamJSON = file_get_contents(');b=auth.index(';',a)
 auth=auth[:a]+"$steamJSON = '{\"response\":{\"players\":[{\"personaname\":\"Test Viking\",\"realname\":\"Test Viking\",\"avatarmedium\":\"\"}]}}'"+auth[b:]
 session=run('docker','exec',name,'cat','/opt/stateless/nginx/www/includes/session_auth.php')
 session=session.replace('function phvDevSteamID() {', "function phvDevSteamID() {\n    if (basename($_SERVER['SCRIPT_NAME'] ?? '') === 'index.php') return NULL;")
 for rel,contents in [('public/authenticated.php',auth),('includes/session_auth.php',session)]:
  file=Path(temp)/Path(rel).name;file.write_text(contents);file.chmod(0o644)
  run('docker','cp',str(file),name+':/opt/stateless/nginx/www/'+rel)
print('Cleanup: docker rm -f '+name+' && docker volume rm '+vol)
