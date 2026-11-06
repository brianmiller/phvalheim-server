#!/usr/bin/env python3
"""Execute the real installer with synthetic plans/archives and fail-closed controls.
No Steam download or live world is required. PHV_TEST_SOURCE supports negative controls.
"""
import contextlib
import importlib.util
import io
import os
from pathlib import Path
import re
import subprocess
import tempfile
import zipfile

repo = Path(os.environ.get('PHV_TEST_SOURCE', Path(__file__).resolve().parent.parent))
source = (repo / 'container/engine/includes/0-functions.sh').read_text()
engine = (repo / 'container/engine/phvalheim').read_text()
static = (repo / 'container/engine/includes/phvalheim-static.conf').read_text()

def function(text, name):
    return re.search(r'^function ' + name + r'\(\).*?^}', text, re.M | re.S)[0]

with tempfile.TemporaryDirectory(prefix='phv-empty-mod-') as td:
    root = Path(td)
    tool = root / 'worldmods'
    tool.write_text('''#!/bin/bash
case "$3" in
 --resolve) exit "${RESOLVE_RC:-0}";;
 --plan) cat "$PLAN"; exit "${PLAN_RC:-0}";;
 --record-installed) printf '%s' "$4" > "$RECORD";;
esac
''')
    tool.chmod(0o755)
    installer = function(source, 'downloadAndInstallTsModsForWorld')
    installer = installer.replace('/opt/stateless/engine/tools/worldMods.py', str(tool))
    installer = installer.replace('/opt/stateless/engine/tools/pluginGuids.py', 'true')
    installer = installer.replace('/tmp/BepInEx_tmp', td + '/extract')
    funcs = '\n'.join(function(source, n) for n in ('clientStagingRoot', 'modTargetTrees', 'prepareClientStaging'))
    script = root / 'run.sh'
    script.write_text('''#!/bin/bash
phvlog(){ :; }
chown(){ :; }
unzip(){
 if [ -n "$FAIL_ENTRY" ] && [ "${3}" = "$FAIL_ENTRY" ]; then return 2; fi
 command unzip "$@"
}
worldsDirectoryRoot="$CASE/worlds"
tsModsDir="$CACHE"
''' + funcs + '\n' + installer + '\ndownloadAndInstallTsModsForWorld sample\n')
    cache = root / 'cache'; cache.mkdir()
    for filename, entry in [('plugin.zip', 'Sample.dll'), ('patcher.zip', 'patchers/Patch.dll'), ('config.zip', 'config/Sample.cfg')]:
        with zipfile.ZipFile(cache / filename, 'w') as z:
            z.writestr(entry, 'fixture payload')
    (cache / 'corrupt.zip').write_text('not a zip')

    def run(name, filename=None, server=1, client=1, expected=0, **extra):
        case = root / name.replace(' ', '-')
        game = case / 'worlds/sample/game/BepInEx'
        (game / 'core').mkdir(parents=True)
        (game / 'core/loader.dll').write_text('loader')
        plan = case / 'plan'
        plan.write_text('' if filename is None else '\t'.join(['local','Fixture','Sample','1.0','file://' + td + '/absent.zip',filename,'latest','0','42',str(server),str(client)]) + '\n')
        env = dict(os.environ, CASE=str(case), CACHE=str(cache), PLAN=str(plan), RECORD=str(case / 'record'), **extra)
        result = subprocess.run(['bash', str(script)], env=env, capture_output=True, text=True)
        assert (result.returncode == 0) == (expected == 0), (name, result.returncode, result.stderr)
        if expected == 0:
            assert (case / 'record').read_text() == ('' if filename is None else '42'), name
        print('PASS', name)
        return case

    run('empty valid selection')
    c = run('client only', 'plugin.zip', server=0)
    assert not list((c / 'worlds/sample/game').rglob('Sample.dll'))
    assert list((c / 'worlds/sample/client').rglob('Sample.dll'))
    c = run('both destinations', 'plugin.zip')
    assert len(list((c / 'worlds/sample').rglob('Sample.dll'))) == 2
    run('patcher only', 'patcher.zip')
    run('config only', 'config.zip')
    run('corrupt archive fails', 'corrupt.zip', expected=1)
    run('missing download fails', 'missing.zip', expected=1)
    run('plan read fails', expected=1, PLAN_RC='1')
    run('resolution fails', expected=1, RESOLVE_RC='1')
    run('core extraction fails', 'plugin.zip', expected=1, FAIL_ENTRY='core/*')
    run('config extraction fails', 'config.zip', expected=1, FAIL_ENTRY='config/*')

    # Execute each actual early-exit branch followed by a healthy world's normal close.
    # The first failure must already be closed, never relabelled by that healthy world.
    close = engine[engine.index('\t\tif [ -n "$PHV_OP" ]; then', engine.index('# Close this world')):engine.index('\n\tdone', engine.index('# Close this world'))]
    branches = [
        engine[engine.index('\t\t\t\t\tphvlog ERROR "$worldPort"'):engine.index('\n\t\t\t\tfi', engine.index('\t\t\t\t\tphvlog ERROR "$worldPort"'))],
        engine[engine.index('\t\t\t\t\tphvlog ERROR "Valheim installation failed'):engine.index('\n\t\t\t\tfi', engine.index('\t\t\t\t\tphvlog ERROR "Valheim installation failed'))],
        engine[engine.index('\t\t\t\t\t\tphvlog ERROR "Mod installation failed'):engine.index('\n\t\t\t\t\tfi', engine.index('\t\t\t\t\t\tphvlog ERROR "Mod installation failed'))],
    ]
    for i, branch in enumerate(branches):
        logfile = root / ('ops' + str(i))
        script.write_text('''phvlog(){ :; }
phvSqlEscape(){ printf '%s' "$1"; }
timeout(){ printf '%s\\n' "${@: -1}" >> "$OPS"; }
SQL(){ case "$1" in SELECT*mode*) echo running;; SELECT*status*) echo ready;; esac; }
''' + function(static, 'phvOpEnd') + '\n' + function(static, 'phvOpSummary') + '''
for worldName in failed_world healthy_world; do
 worldID=1; worldMode=update; opStartStatus=failed
 if [ "$worldName" = failed_world ]; then
  PHV_WORLD=failed_world; PHV_OP=failed_operation
''' + branch + '''
 else
  test -z "$PHV_OP" && test -z "$PHV_WORLD" || exit 22
  PHV_WORLD=healthy_world; PHV_OP=healthy_operation
 fi
''' + close + '\ndone\n')
        r = subprocess.run(['bash', str(script)], env=dict(os.environ, OPS=str(logfile)), capture_output=True, text=True)
        assert r.returncode == 0, (i, r.returncode, r.stderr)
        lines = logfile.read_text().splitlines()
        assert len(lines) == 2 and "outcome='failed'" in lines[0] and "op_id='failed_operation'" in lines[0], lines
        assert "outcome='ok'" in lines[1] and "op_id='healthy_operation'" in lines[1], lines
        print('PASS operation isolation for failure branch', i)

# A missing catalogue version must not masquerade as a legitimate empty selection.
spec = importlib.util.spec_from_file_location('worldMods', repo / 'container/engine/tools/worldMods.py')
wm = importlib.util.module_from_spec(spec); spec.loader.exec_module(wm)
wm.world_id = lambda name: 1
wm.install_rows = lambda *a, **kw: []
with contextlib.redirect_stdout(io.StringIO()) as out:
    wm.plan('sample')
assert not out.getvalue().strip()
wm.install_rows = lambda *a, **kw: [dict(version_id=None, url='', source='local', owner='Fixture', name='Missing')]
try:
    with contextlib.redirect_stdout(io.StringIO()), contextlib.redirect_stderr(io.StringIO()):
        wm.plan('sample')
except SystemExit as e:
    assert e.code != 0
else:
    raise AssertionError('unavailable selected version accepted as an empty plan')
print('PASS empty plan allowed, unavailable selected version refused')
