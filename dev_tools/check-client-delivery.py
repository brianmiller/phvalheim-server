#!/usr/bin/env python3
"""Live release gate: download every rendered Windows/Linux link and verify its SHA256.

Requires PHP CLI, Python 3, GitHub CLI and outbound HTTPS. No files are installed.
Checks the latest stable client by default, or a supplied bare numeric release tag.
macOS uses its own installer and is outside this server-menu check.
"""
import hashlib
import json
from pathlib import Path
import re
import subprocess
import sys
import urllib.request

root = Path(__file__).resolve().parent
tag = sys.argv[1] if len(sys.argv) == 2 else None
if len(sys.argv) > 2 or (tag and not re.fullmatch(r'\d+\.\d+\.\d+', tag)):
    sys.exit('Usage: python3 dev_tools/check-client-delivery.py [x.y.z]')
endpoint = 'latest' if tag is None else 'tags/' + tag
release = json.loads(subprocess.check_output(['gh', 'api', 'repos/brianmiller/phvalheim-client/releases/' + endpoint]))
assert not release['draft'] and not release['prerelease'], 'Expected a published stable release'
tag = release['tag_name']
assert re.fullmatch(r'\d+\.\d+\.\d+', tag), 'Client release tag must be bare numeric'
assets = {a['browser_download_url']: a for a in release['assets']}
manifest = next(a for a in release['assets'] if a['name'] == 'SHA256SUMS')

def open_url(url):
    return urllib.request.urlopen(urllib.request.Request(url, headers={'User-Agent': 'phvalheim-delivery-check'}), timeout=90)

with open_url(manifest['browser_download_url']) as response:
    sums = {}
    for line in response.read().decode().splitlines():
        digest, filename = line.split(maxsplit=1)
        sums[filename.lstrip('*')] = digest.lower()
urls = []
for os_name in ['Windows', 'Linux']:
    html = subprocess.check_output(['php', str(root / 'render-client-downloads.php'), os_name, tag], text=True)
    urls += re.findall(r"href='(https://github.com/brianmiller/phvalheim-client/[^']+)'", html)
urls = list(dict.fromkeys(u for u in urls if '/phvalheim-client-' in u))
assert len(urls) == 5, f'Expected five package links, got {len(urls)}'
for url in urls:
    assert url in assets, f'Rendered URL is not an asset of this release: {url}'
    asset = assets[url]
    h = hashlib.sha256()
    size = 0
    with open_url(url) as response:
        while chunk := response.read(1024 * 1024):
            h.update(chunk)
            size += len(chunk)
    assert size == asset['size'], f'Wrong size: {asset["name"]}'
    assert h.hexdigest() == sums[asset['name']], f'Checksum mismatch: {asset["name"]}'
    if asset.get('digest'):
        assert 'sha256:' + h.hexdigest() == asset['digest'], 'GitHub digest differs'
    print(f'PASS: {asset["name"]} {size} bytes SHA256 {h.hexdigest()}', flush=True)
print(f'{tag}: all five rendered package links delivered verified release assets')
