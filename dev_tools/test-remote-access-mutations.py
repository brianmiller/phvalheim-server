#!/usr/bin/env python3
"""Prove the remote-access guards fail for security regressions, in disposable copies."""
from pathlib import Path
import shutil
import subprocess
import tempfile

ROOT = Path(__file__).resolve().parents[1]
with tempfile.TemporaryDirectory() as directory:
    root=Path(directory)
    files=['container/remote-access/service.py','dev_tools/test-remote-access.py',
           'container/nginx/www/includes/remote_access.php','dev_tools/test-remote-access-guards.php']
    for name in files:
        target=root/name; target.parent.mkdir(parents=True,exist_ok=True)
        shutil.copyfile(ROOT/name,target)
    source=root/files[0]; original=source.read_text()
    mutations=[
        ('return ip.version == 4 and ip.is_global and not ip.is_multicast', 'return True', 'test_private_destination_rejected_with_public_control'),
        ("c['tokenSaved'] = (STATE / 'cloudflare.token').exists()", "c['tokenSaved'] = (STATE / 'cloudflare.token').exists()\n            if c['tokenSaved']: c['leaked'] = (STATE / 'cloudflare.token').read_text()", 'test_secret_is_not_echoed'),
    ]
    for before,after,case in mutations:
        assert before in original
        source.write_text(original.replace(before,after,1))
        result=subprocess.run(['python3',str(root/files[1])],capture_output=True,text=True)
        assert result.returncode!=0 and 'FAIL: '+case in result.stderr,(case,result.stderr[-1500:])
        print('PASS: mutation rejected by',case)
    php=root/files[2]
    text=php.read_text(); marker="    $origin = $server['HTTP_ORIGIN'] ?? '';"
    assert marker in text
    php.write_text(text.replace(marker,'    return true;\n'+marker,1))
    result=subprocess.run(['php',str(root/files[3])],capture_output=True,text=True)
    assert result.returncode!=0 and 'foreign origin refused' in result.stderr,result.stderr
    print('PASS: origin-bypass mutation rejected')
