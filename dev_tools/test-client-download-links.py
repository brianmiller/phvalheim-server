#!/usr/bin/env python3
"""Assert the URLs a player actually receives, including the historical asset boundary."""
from pathlib import Path
import re
import subprocess

ROOT = Path(__file__).resolve().parent

def links(os_name, version):
    html = subprocess.check_output(['php', str(ROOT / 'render-client-downloads.php'), os_name, version], text=True)
    return re.findall(r"href='(https://github.com/brianmiller/phvalheim-client/[^']+)'", html)

repo = 'https://github.com/brianmiller/phvalheim-client'
for version, base in [('2.0.17', f'{repo}/releases/download/2.0.17'),
                      ('2.0.12', f'{repo}/releases/download/2.0.12'),
                      ('2.0.11', f'{repo}/raw/master/builds')]:
    windows = links('Windows', version)
    assert windows == [f'{base}/phvalheim-client-{version}-x86_64.msi', f'{repo}/releases'], windows
    linux = links('Linux', version)
    suffixes = ['universal-x86_64.tar.gz', 'x86_64.deb', 'x86_64.rpm']
    if version == '2.0.17':
        suffixes.insert(0, 'x86_64.flatpak')
    assert linux == [f'{base}/phvalheim-client-{version}-{s}' for s in suffixes] + [f'{repo}/releases'], linux
    print(f'PASS: {version} renders correct Windows/Linux artifacts and release browsing link')
