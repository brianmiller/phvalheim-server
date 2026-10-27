#!/usr/bin/env python3
"""Build the 2.60 client configuration contract from explicit delivery choices.

Ordinary cfg files are represented as per-key defaults/rules, never copied from the
server verbatim. In particular a server_only value cannot leak through a file that
was already present in the staging tree before materialisation.
"""
import json
import os
from pathlib import Path
import shutil
import sys
import tempfile

import modConfigs as mc

POLICY_FILE = '.phvalheim-policy.json'


def delivery(wid, query=None):
    return {int(mid): (int(srv) == 1, int(cli) == 1) for mid, srv, cli in (query or mc.rows)(
        'SELECT wm.mod_id, (COALESCE(cp.push_server,1) AND COALESCE(wm.deploy_server,1)), '
        '(COALESCE(cp.push_client,0) AND COALESCE(wm.deploy_client,1)) '
        'FROM world_mods wm LEFT JOIN world_mod_config_policy cp '
        'ON cp.world_id=wm.world_id AND cp.mod_id=wm.mod_id '
        f'WHERE wm.world_id={wid};')}


def build_policy(world):
    wid = mc.world_id(world)
    choices = delivery(wid)
    catalogue, guids = mc.world_catalogue(wid), mc.guid_owners(wid)
    overrides = mc.load_overrides(wid)
    # HEX avoids mysql batch escaping changing config text or swallowing newlines.
    texts = {name: bytes.fromhex(value).decode('utf-8') for name, value in mc.rows(
        f'SELECT cfg_file, HEX(cfg_text) FROM mod_config_shapes WHERE world_id={wid};')}
    for directory in (mc.client_config_dir(world), mc.server_config_dir(world)):
        for path in Path(directory).glob('*'):
            if not path.is_file() or path.suffix.lower() != '.cfg':
                continue
            text = mc.read_text(str(path))
            if path.name not in texts or mc.cfg_is_documented(mc.parse_cfg(text)):
                texts[path.name] = text
    files = []
    names = set()
    for name in sorted(set(texts) | set(overrides)):
        if name in mc.EXCLUDED_FILES or Path(name).name != name:
            continue
        parsed = mc.parse_cfg(texts.get(name, ''))
        mid = mc.attribute(name, parsed, catalogue, guids)
        if mid is None or not choices.get(mid, (True, False))[1]:
            continue
        ovs = {(o['section'], o['ckey']): o for o in overrides.get(name, [])}
        entries = {(e['section'], e['key']): e for e in parsed['entries']}
        rules = []
        for key in sorted(set(entries) | set(ovs)):
            e, ov = entries.get(key), ovs.get(key)
            if ov and ov['server_only']:
                continue
            # Only documented defaults or deliberately saved values may leave the server.
            if not ov and (not e or not e['has_default']):
                continue
            value = ov['cvalue'] if ov else e['default']
            rules.append(dict(section=key[0], key=key[1], value=value,
                              overridable=bool(ov.get('overridable', 1)) if ov else True))
        if rules:
            if name.casefold() in names or name.startswith('.') or any(c in name for c in '/\\:\x00'):
                raise ValueError('Config filenames must be portable and unique: ' + name)
            names.add(name.casefold())
            for rule in rules:
                if (any(c in rule['section'] for c in '\r\n\x00[]')
                        or any(c in rule['key'] for c in '\r\n\x00=')
                        or any(c in rule['value'] for c in '\r\n\x00')):
                    raise ValueError('Config policy contains an invalid entry in ' + name)
            files.append(dict(file=name, entries=rules))
    return dict(version=1, minClientVersion='2.0.17', files=files,
                managedFiles=sorted(mc.EXCLUDED_FILES))


def package_policy(world):
    policy = build_policy(world)  # Fail before touching the current tree.
    target = Path(mc.client_config_dir(world))
    target.mkdir(parents=True, exist_ok=True)
    stage = Path(tempfile.mkdtemp(prefix='.config-policy-', dir=target.parent))
    try:
        for name in mc.EXCLUDED_FILES:
            source = target / name
            if source.is_file():
                shutil.copy2(source, stage / name)
        # Non-cfg mod assets (YAML, JSON, subdirectories) keep their existing distribution
        # contract; only BepInEx cfg entries support the per-setting editor.
        for source in target.iterdir():
            if source.name.startswith('.phvalheim-') or source.suffix.lower() == '.cfg':
                continue
            if source.is_dir():
                shutil.copytree(source, stage / source.name)
            else:
                shutil.copy2(source, stage / source.name)
        (stage / POLICY_FILE).write_text(json.dumps(policy, ensure_ascii=False, sort_keys=True), encoding='utf-8')
        old = target.with_name('.config-policy-old')
        if old.exists():
            shutil.rmtree(old)
        target.rename(old)
        try:
            stage.rename(target)
        except Exception:
            old.rename(target)
            raise
        shutil.rmtree(old)
    finally:
        if stage.exists():
            shutil.rmtree(stage)


if __name__ == '__main__':
    package_policy(sys.argv[1])
