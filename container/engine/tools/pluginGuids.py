#!/usr/bin/env python3
"""Which mod package owns which BepInEx plugin GUID.

  pluginGuids.py --world NAME [--learn]   read the world's mod zips, record every GUID
  pluginGuids.py --dll PATH               print the GUIDs found in ONE assembly (diagnostic)
  pluginGuids.py --zip PATH               print the GUIDs found in one mod zip (diagnostic)

THE PROBLEM THIS SOLVES
-----------------------
A mod's config file is NOT shipped in its zip -- BepInEx writes `config/<Plugin GUID>.cfg`
the first time the plugin runs. That GUID is a string the mod AUTHOR typed into
`[BepInPlugin("com.pipakin.SkillInjectorMod", "SkillInjectorMod", "1.1.1")]`. The catalogue
knows a different name: the PACKAGE, `pipakin/SkillInjector`. Nothing in the .cfg, and
nothing in the catalogue, connects the two -- so modConfigs.attribute() could only compare
strings and hope, and it misses every mod whose author named the plugin differently from the
package. Measured on one real world: 7 of 58 files unmatched, four of them from this cause
(`SkillInjector` vs `...SkillInjectorMod`, `InstantMonsterLootDrop` vs `InstantMonsterDrop`,
`SmarterContainers` vs `SmartContainers`, and one package shipping several plugins).

The link does exist, in the one place both sides meet: the DLL. The zip is downloaded for a
known mod_id, and the assembly inside it carries its own `BepInPlugin` attribute. Read it
once at install time and attribution becomes an exact lookup.

HOW THE GUID IS READ
--------------------
From the custom-attribute blob, not by guessing at strings. A `[BepInPlugin(...)]` blob is:

    01 00  <SerString guid>  <SerString name>  <SerString version>  00 00

where SerString is a compressed-uint length followed by UTF-8 bytes. Scanning for that exact
shape -- prolog, three strings, no named arguments -- and then requiring the third to look
like a version is what separates it from every other three-string attribute in the file.
A plain text search for "com.something" would also hit a soft dependency check on ANOTHER
mod's GUID (`Chainloader.PluginInfos.ContainsKey("com.jotunn.jotunn")`), which is exactly how
a config would get attributed to the wrong package.

No external dependency: this is a byte scan over the assembly, not a CLI metadata parse.
A full parse would be more precise and is not needed -- the blob shape plus the version check
is unambiguous enough that the live corpus produces no spurious triples (see
dev_tools/test-plugin-guids.sh, which asserts exactly that against crafted near-misses).
"""

import argparse
import os
import re
import subprocess
import sys
import zipfile

DB = "phvalheim"
MYSQL = "/usr/bin/mysql"
TS_MODS_DIR = "/opt/stateful/games/valheim/mods/ts"

# A version as BepInEx accepts it: 1, 1.1, 1.1.1, 1.1.1.1. Deliberately strict -- this is the
# field that tells a BepInPlugin blob apart from any other attribute taking three strings.
RE_VERSION = re.compile(r"^\d+(\.\d+){0,3}$")

# GUIDs and plugin names are human-authored identifiers. Anything with a control character or
# a wild length is a false positive from the byte scan, not an identifier.
MAX_FIELD = 128


def sql(stmt, fetch=False):
    cmd = [MYSQL, "-uroot", "--default-character-set=utf8mb4", "--database", DB]
    if fetch:
        cmd.append("--skip-column-names")
    cmd += ["-e", stmt]
    p = subprocess.run(cmd, capture_output=True, text=True)
    if p.returncode != 0:
        raise RuntimeError(p.stderr.strip() or "mysql failed")
    return p.stdout


def rows(query):
    out = sql(query, fetch=True).strip()
    return [] if not out else [line.split("\t") for line in out.split("\n")]


def q(v):
    return "'" + str(v).replace("\\", "\\\\").replace("'", "\\'") + "'"


def _ser_string(buf, i):
    """One SerString at buf[i]: (value, next_index), or (None, None).

    Length is a compressed unsigned int: 1, 2 or 4 bytes depending on the top bits. 0xFF is
    the NULL string, which a BepInPlugin argument never is.
    """
    if i >= len(buf):
        return None, None
    b0 = buf[i]
    if b0 == 0xFF:
        return None, None
    if b0 < 0x80:
        ln, j = b0, i + 1
    elif b0 & 0xC0 == 0x80:
        if i + 1 >= len(buf):
            return None, None
        ln, j = ((b0 & 0x3F) << 8) | buf[i + 1], i + 2
    elif b0 & 0xE0 == 0xC0:
        if i + 3 >= len(buf):
            return None, None
        ln = ((b0 & 0x1F) << 24) | (buf[i + 1] << 16) | (buf[i + 2] << 8) | buf[i + 3]
        j = i + 4
    else:
        return None, None

    if ln == 0 or ln > MAX_FIELD or j + ln > len(buf):
        return None, None
    try:
        s = buf[j:j + ln].decode("utf-8")
    except UnicodeDecodeError:
        return None, None
    # Printable only. A length byte that happens to precede binary data decodes as "text"
    # often enough that this is the check doing most of the filtering.
    if any(ord(c) < 0x20 or ord(c) == 0x7F for c in s):
        return None, None
    return s, j + ln


def guids_in_assembly(data):
    """Every (guid, name, version) that looks like a BepInPlugin attribute blob."""
    found = []
    seen = set()
    i = 0
    while True:
        i = data.find(b"\x01\x00", i)
        if i < 0:
            break
        j = i + 2
        guid, j = _ser_string(data, j)
        if guid:
            name, j2 = _ser_string(data, j)
            if name:
                version, j3 = _ser_string(data, j2)
                # The trailing 00 00 is the named-argument count: a BepInPlugin attribute has
                # none. Requiring it is what keeps a longer attribute's first three strings
                # from being read as a plugin declaration.
                if (version and RE_VERSION.match(version)
                        and data[j3:j3 + 2] == b"\x00\x00"
                        and (guid, name, version) not in seen):
                    seen.add((guid, name, version))
                    found.append((guid, name, version))
        i += 2
    return found


def guids_in_zip(path):
    """GUIDs declared by the assemblies in one mod zip."""
    out = []
    try:
        with zipfile.ZipFile(path) as z:
            for info in z.infolist():
                n = info.filename.replace("\\", "/")
                if not n.lower().endswith(".dll") or n.endswith("/"):
                    continue
                # The loader's own pack rides inside some zips; its assemblies are not this
                # package's plugins and claiming their GUIDs would attribute BepInEx.cfg and
                # friends to whatever mod happened to bundle the pack.
                if "bepinexpack" in n.lower() or "/core/" in n.lower():
                    continue
                # A single plugin DLL is a megabyte at most; anything larger is an asset
                # bundle renamed, and reading it costs more than it can ever return.
                if info.file_size > 32 * 1024 * 1024:
                    continue
                try:
                    data = z.read(info)
                except Exception:
                    continue
                out.extend(guids_in_assembly(data))
    except Exception as e:
        print(f"[pluginguids] cannot read {path}: {e}", file=sys.stderr)
        return []
    return out


def world_zips(world):
    """[(mod_id, cached zip path)] for the mods this world installs.

    Straight from worldMods.plan(), so the filename convention lives in exactly one place --
    it encodes the SOURCE as well as owner/name/version, because the cache is shared and
    Hexium's and Thunderstore's copies of one release would otherwise collide.
    """
    here = os.path.dirname(os.path.abspath(__file__))
    p = subprocess.run([sys.executable, os.path.join(here, "worldMods.py"),
                        "--world", world, "--plan"],
                       capture_output=True, text=True)
    if p.returncode != 0:
        print(f"[pluginguids] plan failed for '{world}': {p.stderr.strip()}", file=sys.stderr)
        return []

    out = []
    for line in p.stdout.strip().split("\n"):
        if not line.strip():
            continue
        f = line.split("\t")
        if len(f) < 9:
            continue
        fname, mod_id = f[5], f[8]
        path = os.path.join(TS_MODS_DIR, fname)
        if os.path.isfile(path):
            out.append((int(mod_id), path))
    return out


def learn(world, write=True):
    """Record every GUID the world's packages declare. Returns the rows it found."""
    known = {(int(m), g) for m, g in rows("SELECT mod_id, guid FROM mod_plugin_guids;")}
    learned = []

    for mod_id, path in world_zips(world):
        for guid, name, version in guids_in_zip(path):
            learned.append((mod_id, guid, name))
            if write and (mod_id, guid) not in known:
                # INSERT IGNORE, not a check-then-insert: two worlds installing the same mod
                # can learn it at the same moment, and a duplicate key here is a race, not an
                # error worth failing a world's startup over.
                sql("INSERT IGNORE INTO mod_plugin_guids (mod_id, guid, plugin_name) "
                    f"VALUES ({mod_id}, {q(guid)}, {q(name)});")
                known.add((mod_id, guid))

    return learned


def main():
    ap = argparse.ArgumentParser()
    ap.add_argument("--world")
    ap.add_argument("--learn", action="store_true")
    ap.add_argument("--dll")
    ap.add_argument("--zip")
    args = ap.parse_args()

    if args.dll:
        with open(args.dll, "rb") as fh:
            for guid, name, version in guids_in_assembly(fh.read()):
                print(f"{guid}\t{name}\t{version}")
        return 0

    if args.zip:
        for guid, name, version in guids_in_zip(args.zip):
            print(f"{guid}\t{name}\t{version}")
        return 0

    if not args.world:
        ap.error("--world, --dll or --zip is required")

    found = learn(args.world, write=args.learn)
    if args.learn:
        print(f"[pluginguids] '{args.world}': {len(found)} plugin declaration(s) across "
              f"{len({m for m, _, _ in found})} package(s)")
    else:
        for mod_id, guid, name in found:
            print(f"{mod_id}\t{guid}\t{name}")
    return 0


if __name__ == "__main__":
    sys.exit(main())
