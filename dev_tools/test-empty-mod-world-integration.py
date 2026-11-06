#!/usr/bin/env python3
# requires: container
"""Run only against a disposable fixture_presentation.py container passed by name.
Uses the image's actual engine world loop, SQL, resolver, installers and packaging.
Only Steam/loader downloads, process detection, supervisor config and background
update checks are stubbed. This is not a Valheim gameplay test.
"""
import hashlib
import json
from pathlib import Path
import subprocess
import sys
import tempfile
import zipfile
from fixture_analytics import verify_no_enrollment

EXPECTED_COMPANION = '8705a9b960a48956e1756e116c8feccb4c8891dce92a71bcc42e54332e6dfa54'
c = sys.argv[1]
assert c.startswith('phv-presentation-'), 'use a disposable presentation fixture'
verify_no_enrollment(c)
def run(*args):
    return subprocess.check_output(args, text=True).strip()
def ex(*args): return run('docker', 'exec', c, *args)
def sql(q): return ex('mysql', '-N', 'phvalheim', '-e', q)
ex('supervisorctl', 'stop', 'phvalheim')
status = subprocess.run(['docker','exec',c,'supervisorctl','status','phvalheim'],capture_output=True,text=True)
assert 'STOPPED' in status.stdout, status.stdout
# Select only our new rows, preserving any unrelated fixture worlds.
assert sql("SELECT COUNT(*) FROM worlds WHERE name LIKE 'installprobe%'") == '0'
for i, name in enumerate(['installprobeempty', 'installprobeclient', 'installprobebad', 'installprobehealthy']):
    sql(f"INSERT INTO worlds (name,port,mode,status,seed,vanilla) VALUES ('{name}',{26000+i*2},'create','ready','fixtureseed',0)")
sql("INSERT INTO mods(source,owner,name,full_name,latest_version) VALUES ('thunderstore','Fixture','ProbeClient','Fixture-ProbeClient','1.0'),('thunderstore','Fixture','ProbeBad','Fixture-ProbeBad','1.0')")
for name in ['ProbeClient','ProbeBad']:
    sql(f"INSERT INTO mod_versions(mod_id,version,full_name,download_url) SELECT id,'1.0',CONCAT(full_name,'-1.0'),'file:///tmp/installprobe/{name}.zip' FROM mods WHERE owner='Fixture' AND name='{name}'")
for world, mod, server in [('installprobeclient','ProbeClient',0),('installprobebad','ProbeBad',1)]:
    sql(f"INSERT INTO world_mods(world_id,mod_id,deploy_server,deploy_client) SELECT w.id,m.id,{server},1 FROM worlds w JOIN mods m ON m.owner='Fixture' AND m.name='{mod}' WHERE w.name='{world}'")
engine = ex('cat', '/opt/stateless/engine/phvalheim')
loop = engine[engine.index('\tfor WORLD in $WORLDS; do'):engine.rindex('\n\tdone') + len('\n\tdone')]
harness = '''#!/bin/bash
source /opt/stateless/engine/includes/phvalheim-static.conf
source /opt/stateless/engine/includes/0-functions.sh
PHV_LOG_SOURCE=installprobe
export PHV_LOG_SOURCE
InstallAndUpdateValheim(){ return 0; }
InstallAndUpdateBepInEx(){
 local g="$worldsDirectoryRoot/$1/game"
 mkdir -p "$g/BepInEx/core" "$g/BepInEx/config" "$g/doorstop_libs"
 echo fixture-loader > "$g/BepInEx/core/loader.dll"
 touch "$g/doorstop_config.ini" "$g/start_game_bepinex.sh" "$g/winhttp.dll"
}
worldProcessRunning(){ return 1; }
createSupervisorWorldConfig(){ :; }
setsid(){ :; }
gameDNS=fixture.invalid
defaultSeed=fixtureseed
WORLDS=$(SQL "SELECT id FROM worlds WHERE name LIKE 'installprobe%' ORDER BY id")
''' + loop
with tempfile.TemporaryDirectory() as td:
    td = Path(td)
    with zipfile.ZipFile(td / 'ProbeClient.zip', 'w') as z: z.writestr('ProbeClient.dll','fixture-plugin')
    (td / 'ProbeBad.zip').write_text('broken archive')
    (td / 'tick.sh').write_text(harness)
    for f in td.iterdir(): f.chmod(0o644)
    ex('mkdir', '-p', '/tmp/installprobe')
    run('docker', 'cp', str(td) + '/.', c + ':/tmp/installprobe/')
    # First pass creates directories and custom seed; second performs installation.
    for _ in range(2): ex('bash', '/tmp/installprobe/tick.sh')
    for world in ['installprobeempty','installprobeclient','installprobehealthy']:
        state = sql(f"SELECT mode,LENGTH(world_md5),config_policy_version FROM worlds WHERE name='{world}'").split('\t')
        assert state == ['stopped','32','1'], (world,state)
        path = '/opt/stateful/games/valheim/worlds/' + world
        ex('test', '-s', path + '/game/BepInEx/plugins/PhValheimCompanion/PhValheimCompanion.dll')
        dest = td / (world + '.zip')
        run('docker','cp',c+':'+path+'/'+world+'.zip',str(dest))
        with zipfile.ZipFile(dest) as z:
            assert hashlib.sha256(z.read('BepInEx/plugins/PhValheimCompanion/PhValheimCompanion.dll')).hexdigest() == EXPECTED_COMPANION
            assert any(n.endswith('/phvalheim-world.cfg') for n in z.namelist())
            assert any(n.endswith('/ProbeClient.dll') for n in z.namelist()) == (world == 'installprobeclient')
        assert sql(f"SELECT outcome FROM phv_operations WHERE world='{world}' AND kind='update' ORDER BY started DESC LIMIT 1") == 'ok'
        print('PASS create/update and Companion payload:',world)
    assert ex('bash','-c',"find /opt/stateful/games/valheim/worlds/installprobeclient/game -name ProbeClient.dll | wc -l") == '0'
    assert sql("SELECT COUNT(*) FROM world_mods wm JOIN worlds w ON w.id=wm.world_id WHERE w.name='installprobeclient' AND installed_version_id IS NOT NULL") == '1'
    bad = 'installprobebad'
    assert sql(f"SELECT mode,status FROM worlds WHERE name='{bad}'") == 'stopped\tfailed'
    assert sql(f"SELECT outcome FROM phv_operations WHERE world='{bad}' AND kind='update'") == 'failed'
    ex('test','!','-e',f'/opt/stateful/games/valheim/worlds/{bad}/{bad}.zip')
    # Retry an already-failed world alongside a succeeding rebuild. Retain old bytes/md5.
    ex('bash','-c',f"echo previous-payload > /opt/stateful/games/valheim/worlds/{bad}/{bad}.zip")
    sql(f"UPDATE worlds SET mode='update',world_md5='previous-md5' WHERE name='{bad}'")
    # Seed an old installed plugin in ALL trees and an old downloadable archive.
    # A successful Update must replace each copy and advertise the new archive checksum.
    healthy_path = '/opt/stateful/games/valheim/worlds/installprobehealthy'
    stale = td / 'PhValheimCompanion.dll'
    stale.write_bytes(b'old Companion from the previous container')
    stale_archive = td / 'stale.zip'
    with zipfile.ZipFile(stale_archive, 'w') as z:
        z.write(stale, 'BepInEx/plugins/PhValheimCompanion/PhValheimCompanion.dll')
    for rel in ['custom_plugins', 'game/BepInEx/plugins', 'client/BepInEx/plugins']:
        run('docker','cp',str(stale),c+':'+healthy_path+'/'+rel+'/PhValheimCompanion/PhValheimCompanion.dll')
    run('docker','cp',str(stale_archive),c+':'+healthy_path+'/installprobehealthy.zip')
    old_md5 = hashlib.md5(stale_archive.read_bytes()).hexdigest()
    sql(f"UPDATE worlds SET world_md5='{old_md5}' WHERE name='installprobehealthy'")
    sql("UPDATE worlds SET mode='update' WHERE name='installprobehealthy'")
    ex('bash','/tmp/installprobe/tick.sh')
    assert ex('cat',f'/opt/stateful/games/valheim/worlds/{bad}/{bad}.zip') == 'previous-payload'
    assert sql(f"SELECT world_md5 FROM worlds WHERE name='{bad}'") == 'previous-md5'
    assert sql(f"SELECT outcome FROM phv_operations WHERE world='{bad}' AND kind='update' ORDER BY started") == 'failed\nfailed'
    assert sql("SELECT COUNT(*) FROM phv_operations WHERE world LIKE 'installprobe%' AND finished IS NULL") == '0'
    assert sql("SELECT COUNT(*) FROM phv_operations WHERE world='installprobehealthy' AND kind='update' AND outcome='ok'") == '2'
    print('PASS failed operation isolation, failed retry, prior payload preservation and later healthy rebuild')

    for rel in ['custom_plugins', 'game/BepInEx/plugins', 'client/BepInEx/plugins']:
        digest = ex('sha256sum',healthy_path+'/'+rel+'/PhValheimCompanion/PhValheimCompanion.dll').split()[0]
        assert digest == EXPECTED_COMPANION, (rel,digest)
    delivered = td / 'updated.zip'
    run('docker','cp',c+':'+healthy_path+'/installprobehealthy.zip',str(delivered))
    with zipfile.ZipFile(delivered) as z:
        assert hashlib.sha256(z.read('BepInEx/plugins/PhValheimCompanion/PhValheimCompanion.dll')).hexdigest() == EXPECTED_COMPANION
    new_md5 = hashlib.md5(delivered.read_bytes()).hexdigest()
    assert new_md5 != old_md5
    assert sql("SELECT world_md5 FROM worlds WHERE name='installprobehealthy'") == new_md5
    print('PASS existing world Update replaces old Companion in all trees and client ZIP, with new download checksum')
print('All disposable install pipeline checks passed; remove this fixture and its volume.')
