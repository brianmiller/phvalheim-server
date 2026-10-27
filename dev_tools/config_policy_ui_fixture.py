import json
from pathlib import Path
import shutil
import sys
import uuid
sys.path.insert(0, '/opt/stateless/engine/tools')
import modConfigs as mc

record = Path('/tmp/phv260-ui-fixture.json')
if sys.argv[1] == 'create':
    name = 'zpolicy_ui_' + uuid.uuid4().hex[:8]
    mc.sql(f"INSERT INTO worlds (name,mode,vanilla,autostart,port) VALUES ({mc.q(name)},'stopped',0,0,25096);")
    wid = mc.world_id(name)
    mc.sql(f"INSERT INTO mods (source,owner,name,full_name,latest_version) VALUES ('thunderstore',{mc.q(name)},'PolicyUI',{mc.q(name+'-PolicyUI')},'1.0.0');")
    mid = int(mc.rows(f'SELECT id FROM mods WHERE owner={mc.q(name)}')[0][0])
    data = dict(world=name, wid=wid, mid=mid)
    record.write_text(json.dumps(data))
    mc.sql(f"INSERT INTO mod_versions (mod_id,version,full_name,download_url,description) VALUES ({mid},'1.0.0',{mc.q(name+'-PolicyUI-1.0.0')},'https://example.invalid/fixture.zip','Config policy UI fixture');")
    mc.sql(f'INSERT INTO world_mods (world_id,mod_id,is_dep) VALUES ({wid},{mid},0);')
    mc.sql(f"INSERT INTO mod_plugin_guids (mod_id,guid,plugin_name,learned,manual) VALUES ({mid},{mc.q(name)},'PolicyUI',1,0);")
    folder = Path(mc.server_config_dir(name))
    folder.mkdir(parents=True)
    (folder/(name+'.cfg')).write_text(f'''## Settings file was created by plugin PolicyUI v1.0.0
## Plugin GUID: {name}
[UI]
# Setting type: Boolean
# Default value: false
Weight = false
# Setting type: Boolean
# Default value: true
Popup = true
''')
    print(json.dumps(data))
else:
    if record.exists():
        d=json.loads(record.read_text());wid=d['wid'];mid=d['mid']
        for table in ['world_mods','world_mod_config_policy','mod_config_overrides','mod_config_shapes']:
            mc.sql(f'DELETE FROM {table} WHERE world_id={wid};')
        mc.sql(f'DELETE FROM worlds WHERE id={wid}; DELETE FROM mod_plugin_guids WHERE mod_id={mid}; DELETE FROM mod_versions WHERE mod_id={mid}; DELETE FROM mods WHERE id={mid};')
        shutil.rmtree(Path(mc.WORLDS_ROOT)/d['world'],ignore_errors=True)
        record.unlink()
