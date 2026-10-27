#!/usr/bin/env python3
# requires: container
"""Real DB, PHP save paths, Python materialiser and both package archives; disposable world."""
import json
import os
from pathlib import Path
import shutil
import subprocess
import sys
import uuid
import zipfile

sys.path.insert(0, '/opt/stateless/engine/tools')
import modConfigs as mc
import clientConfigPolicy as cp

name = 'zpolicy_' + uuid.uuid4().hex[:10]
base = Path(mc.WORLDS_ROOT) / name
wid = mid = None
passed = 0
notices = mc.rows("SELECT clientUpdateNoticeState, configPolicyNoticeState FROM settings LIMIT 1")[0]
shape = '''## Settings file was created by plugin PolicyFixture v1.0
## Plugin GUID: org.phv.policyfixture
[UI]
# Setting type: Boolean
# Default value: false
Weight = false
# Setting type: Int32
# Default value: 5
Limit = 5
# Setting type: String
# Default value: none
Secret = none
'''

def check(condition, label):
    global passed
    assert condition, label
    passed += 1
    print('PASS: ' + label, flush=True)


def php(fn, args):
    code = '''include '/opt/stateless/nginx/www/includes/config_env_puller.php';
include '/opt/stateless/nginx/www/includes/phvalheim-frontend-config.php';
require_once '/opt/stateless/nginx/www/includes/modconfigs.php';
$v=json_decode(stream_get_contents(STDIN),true);
echo json_encode(call_user_func_array($v['fn'],array_merge([$pdo],$v['args'])));'''
    r = subprocess.run(['php', '-r', code], input=json.dumps(dict(fn=fn, args=args)), text=True, capture_output=True)
    assert r.returncode == 0, r.stderr
    assert not r.stderr.strip(), r.stderr
    return json.loads(r.stdout)


def selection(server=True, client=False):
    return php('saveWorldModSelection', [name, [dict(id=mid, server=True, client=True,
                config_server=server, config_client=client)]])


def save(key, value, **flags):
    return php('modConfigSaveOverrides', [name, [dict(file='org.phv.policyfixture.cfg',
        section='UI', key=key, value=value, mod_id=mid, **flags)]])


try:
    mc.sql(f"INSERT INTO worlds (name,mode,vanilla,autostart,port) VALUES ({mc.q(name)},'stopped',0,0,25098);")
    wid = mc.world_id(name)
    mc.sql(f"INSERT INTO mods (source,owner,name,full_name) VALUES ('vibes',{mc.q(name)},'PolicyFixture',{mc.q(name+'-PolicyFixture')});")
    mid = int(mc.rows(f"SELECT id FROM mods WHERE owner={mc.q(name)};")[0][0])
    check(selection()['ok'], 'selection saves default delivery choices')
    selected = php('worldModSelection', [name])['selected'][0]
    check(selected['config_server'] and not selected['config_client'], 'server on and client off persist through reload')
    for tree in ['game', 'client']:
        directory = base / tree / 'BepInEx/config'
        directory.mkdir(parents=True)
        (directory / 'org.phv.policyfixture.cfg').write_text(shape)
        (directory / 'BepInEx.cfg').write_text('[Logging.Console]\nEnabled = true\n')
    mc.sql(f"INSERT INTO mod_plugin_guids (mod_id,guid,plugin_name,learned,manual) VALUES ({mid},'org.phv.policyfixture','PolicyFixture',1,0);")
    check(cp.build_policy(name)['files'] == [], 'untouched mods default to no client config rules')
    check(save('Secret', 'DO_NOT_DISTRIBUTE', server_only=True)['ok'], 'save a private server setting')
    check(not php('worldModSelection',[name])['selected'][0]['config_client'], 'private server edit leaves Client off')
    check(selection(server=False)['ok'], 'disable Server before testing independent automatic Client delivery')
    bad=save('Bad', 'line1\nInjected = true')
    check(bad['saved']==0 and not php('worldModSelection',[name])['selected'][0]['config_client'], 'rejected edit does not enable Client')
    first=save('Weight', 'true')
    check(first['ok'] and first['needsPush'] and len(first['clientConfigEnabled'])==1, 'saving a value enables Client and offers a push immediately')
    selected=php('worldModSelection',[name])['selected'][0]
    check(selected['config_client'] and not selected['config_server'] and selected['server'] and selected['client'], 'automatic Client preserves Server delivery and both installation choices')
    ov = mc.load_overrides(wid)
    check(next(r for r in ov['org.phv.policyfixture.cfg'] if r['ckey']=='Weight')['overridable'] == 0, 'operator value without an explicit flag defaults to enforced')
    check(save('Weight', 'true', overridable=True)['ok'], 'operator can deliberately make the value overridable')
    check(save('Weight', 'true')['ok'], 'unchanged re-save accepted without an explicit flag')
    ov=mc.load_overrides(wid)
    check(next(r for r in ov['org.phv.policyfixture.cfg'] if r['ckey']=='Weight')['overridable']==1, 'unchanged re-save preserves deliberate Overridable')
    check(save('Weight', 'false')['ok'], 'change an already overridable value without supplying a flag')
    ov=mc.load_overrides(wid)
    check(next(r for r in ov['org.phv.policyfixture.cfg'] if r['ckey']=='Weight')['overridable']==0, 'a changed operator value becomes enforced again')
    save('Weight', 'true', overridable=True)
    check(selection()['ok'], 'operator can still turn Client off explicitly')
    reset=php('modConfigSaveOverrides',[name,[dict(file='org.phv.policyfixture.cfg',section='UI',key='Absent',reset=True)]])
    check(reset['removed']==0 and not php('worldModSelection',[name])['selected'][0]['config_client'], 'reset does not enable Client')
    mc.materialise(name)
    check('Weight = true' in (base/'game/BepInEx/config/org.phv.policyfixture.cfg').read_text(), 'server receives saved value by default')
    check(cp.build_policy(name)['files'] == [], 'client off emits no ordinary config rules')
    check(selection(client=True)['ok'], 'enable client config delivery')
    check(save('Limit', '20', overridable=False)['needsPush'], 'enforced client setting save needs a push')
    check(save('Limit', '20', server_only=True)['needsPush'], 'changing a client rule to server-only still requires removing it from clients')
    check(save('Limit', '20', server_only=False, overridable=False)['needsPush'], 'restoring a client rule requires sync')
    bad=save('Bad', 'line1\nInjected = true')
    check(bad['saved']==0 and bad['tally']['server']==0 and not bad['needsPush'], 'refused values cannot inflate save summary or inject config keys')
    check(save('Secret', 'DO_NOT_DISTRIBUTE', server_only=True, overridable=False)['ok'], 'save server-only secret')
    check(save('Path', r'C:\mods\custom = yes')['ok'], 'save literal backslashes and equals sign')
    mc.materialise(name)
    policy = cp.build_policy(name)
    rules = {r['key']: r for r in policy['files'][0]['entries']}
    check(rules['Path']['value'] == r'C:\mods\custom = yes', 'policy preserves exact string values through SQL')
    check(rules['Weight']['overridable'] and not rules['Limit']['overridable'], 'saved flags reach the client policy')
    check('Secret' not in rules and 'DO_NOT_DISTRIBUTE' not in json.dumps(policy), 'server-only entry omitted entirely, not replaced with its secret')
    check('DO_NOT_DISTRIBUTE' in (base/'game/BepInEx/config/org.phv.policyfixture.cfg').read_text(), 'control: server-only value reaches server')
    # Contaminate staging to prove filtering does not trust prior materialisation.
    (base/'client/BepInEx/config/org.phv.policyfixture.cfg').write_text(shape.replace('none','DO_NOT_DISTRIBUTE'))
    (base/'client/BepInEx/config/Unmatched.CFG').write_text('Secret = DO_NOT_DISTRIBUTE')
    cp.package_policy(name)
    check(not (base/'client/BepInEx/config/org.phv.policyfixture.cfg').exists(), 'raw staging cfg never leaks alongside rules')
    check(not (base/'client/BepInEx/config/Unmatched.CFG').exists(), 'uppercase cfg cannot bypass config filtering')
    check((base/'client/BepInEx/config/BepInEx.cfg').exists(), 'engine-owned loader config preserved')
    # PHP import makes the first client-only config editable without publishing user values.
    imported = php('modConfigImportClientShape', [name, mid, 'org.phv.importfixture.cfg', shape.replace('org.phv.policyfixture','org.phv.importfixture').replace('Weight = false','Weight = player')])
    check(imported.get('ok'), 'client-generated config imports as editable shape')
    payload = php('modConfigEditorPayload', [name])
    check(any(f['file']=='org.phv.importfixture.cfg' for f in payload['files']), 'imported client-only shape appears in editor')
    check(not php('modConfigImportClientShape', [name, mid, '../bad.cfg', shape])['ok'], 'import rejects path traversal')
    check(not php('modConfigImportClientShape', [name, mid, 'BepInEx.cfg', shape])['ok'], 'import rejects engine-owned cfg')
    check(not php('modConfigImportClientShape', [name, mid, 'empty.cfg', 'hello'])['ok'], 'import rejects files with no settings')
    # Package through the production shell, not a reimplementation of its zip list.
    stage = base/'client'
    (stage/'BepInEx/plugins').mkdir(parents=True, exist_ok=True)
    (stage/'BepInEx/plugins/fixture.dll').write_bytes(b'fixture')
    (stage/'doorstop_libs').mkdir(exist_ok=True)
    for file in ['doorstop_config.ini','start_game_bepinex.sh','winhttp.dll']:
        (stage/file).write_text('fixture')
    script = 'source /opt/stateless/engine/includes/0-functions.sh\npackageClient "$1"\n'
    run = subprocess.run(['bash','-c',script,'policy-test',name],text=True,capture_output=True)
    check(run.returncode == 0, 'production packageClient succeeds: ' + run.stderr[-150:])
    with zipfile.ZipFile(base/(name+'.zip')) as full, zipfile.ZipFile(base/(name+'-config.zip')) as small:
        key='BepInEx/config/'+cp.POLICY_FILE
        check(full.read(key)==small.read(key), 'full and config-only archives carry identical policy bytes')
        check(all(b'DO_NOT_DISTRIBUTE' not in z.read(n) for z in (full,small) for n in z.namelist() if not n.endswith('/')), 'neither archive contains server-only secret')
    check(mc.rows(f'SELECT config_policy_version FROM worlds WHERE id={wid}')[0][0]=='1', 'published world opts into capability gate')
    # Optional artifacts for exercising the real server archives with the launcher merger.
    if os.environ.get('PHV_POLICY_EXPORT'):
        export = Path(os.environ['PHV_POLICY_EXPORT'])
        export.mkdir(parents=True, exist_ok=True)
        shutil.copy2(base/(name+'.zip'), export/'full.zip')
        shutil.copy2(base/(name+'-config.zip'), export/'config.zip')
    check(selection(server=False, client=False)['ok'], 'disable both config destinations independently of installation')
    check(php('worldModSelection',[name])['selected'][0]['server'], 'mod installation remains enabled')
    check(cp.build_policy(name)['files']==[], 'turning delivery off clears client rules')
    # Omitted policy fields in an old UI/AI action must retain operator intent.
    check(php('saveWorldModSelection',[name,[mid]])['ok'], 'legacy selection writer still works')
    selected=php('worldModSelection',[name])['selected'][0]
    check(not selected['config_server'] and not selected['config_client'], 'legacy writer preserves stored delivery flags')
    # Real HTTP gate prevents an old client from entering its destructive extraction.
    import urllib.request, urllib.error
    try:
        urllib.request.urlopen('http://127.0.0.1:8080/api.php?mode=getSyncState&world='+name)
        blocked=False
    except urllib.error.HTTPError as e:
        blocked=e.code==426 and b'2.0.17' in e.read()
    check(blocked, 'old client is stopped before downloading a new-model world')
    with urllib.request.urlopen('http://127.0.0.1:8080/api.php?mode=getSyncState&configPolicy=1&world='+name) as response:
        check(response.status==200, 'capable client receives sync state')
    print(f'SERVER CONFIG POLICY: {passed} passed')
finally:
    mc.sql(f"UPDATE settings SET clientUpdateNoticeState={int(notices[0])}, configPolicyNoticeState={int(notices[1])};")
    if wid:
        for table in ['world_mod_config_policy','world_mods','mod_config_overrides','mod_config_shapes']:
            mc.sql(f'DELETE FROM {table} WHERE world_id={wid};')
        mc.sql(f'DELETE FROM worlds WHERE id={wid};')
    if mid:
        mc.sql(f'DELETE FROM mod_plugin_guids WHERE mod_id={mid}; DELETE FROM mods WHERE id={mid};')
    shutil.rmtree(base, ignore_errors=True)
