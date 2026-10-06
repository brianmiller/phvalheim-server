#!/usr/bin/env python3
"""A world's mod configs: parse, discover, materialise, and the one-time legacy import.

  modConfigs.py --world NAME --parse-dir         JSON of every editable config   (NO DATABASE)
  modConfigs.py --parse-file PATH                JSON of one cfg file            (NO DATABASE)
  modConfigs.py --world NAME --discover          the above plus override state         (root)
  modConfigs.py --world NAME --materialise       apply DB overrides onto the trees     (root)
  modConfigs.py --import-legacy --all            one-time lift of custom_configs*/     (root)
  modConfigs.py --import-legacy --world NAME     the same, one world                   (root)

The two NO DATABASE modes exist for the admin UI. php-fpm runs as `phvalheim` and reaches the
database as `phvalheim_user` via PDO, while the modes marked (root) talk to mysql as -uroot --
so the UI parses files through --parse-dir and merges its own rows itself, rather than asking
the web user to authenticate as root.

THE PROBLEM THIS SOLVES
purgeWorldModsConfigsPatchers() deletes BepInEx/config/* on every world update, so a mod
config is DERIVED, never state. Before 2.55 the only way to keep an edit was to copy the
whole file into custom_configs/, which pins the config at the shape the mod had when it was
copied: a setting the new version adds is lost, one it removes lingers forever, and nothing
can say which values the operator actually chose. 2.55 stores SPARSE PER-KEY overrides in
mod_config_overrides and re-applies them onto whatever the new version generates.

WHY THERE IS EXACTLY ONE PARSER, HERE
The admin UI needs to parse cfg files too -- to render the editor and to diff a pasted file.
It calls --discover / --parse-file rather than carrying its own PHP parser, because two
parsers for one hand-written format drift apart and then disagree about what the operator
set. Everything that reads a .cfg reads it through parse_cfg() below.

WHAT IS DELIBERATELY NOT TOUCHED
  BepInEx.cfg                     the LOADER's own config, engine state, not a mod config.
                                  2.49 shipped a release that swept it and silenced both the
                                  world log and the client's console window. See
                                  ensureBepInExLoaderConfig().
  ZeroBandwidth.CustomSeed.cfg    the engine computes it from worlds.seed in
                                  createCustomSeedConfig(). An operator-editable row here
                                  would let them fight the engine and lose on every update --
                                  and the loss is the world's MAP.
"""

import argparse
import json
import os
import re
import shutil
import subprocess
import sys

DB = "phvalheim"
MYSQL = "/usr/bin/mysql"
WORLDS_ROOT = "/opt/stateful/games/valheim/worlds"

# Never editable, never imported. See the module docstring for why each one is here.
EXCLUDED_FILES = {"BepInEx.cfg", "ZeroBandwidth.CustomSeed.cfg", "quick_connect_servers.cfg"}

# Where --import-legacy parks a file it has consumed.
#
# Moving it is not tidiness, it is correctness. installCustomModsConfigsPatchers() copies
# custom_configs/* into both trees on EVERY update, and that copy runs BEFORE materialise.
# Leave an imported file in place and its stale non-overridden keys land underneath the
# operator's overrides forever -- which is the same frozen-at-import-time config the per-key
# store exists to prevent. The bytes are kept rather than deleted so a bad import is
# recoverable by hand.
IMPORTED_DIR = ".imported-pre-2.55"


def sql(stmt, fetch=False):
    cmd = [MYSQL, "-uroot", "--default-character-set=utf8mb4", "--database", DB]
    if fetch:
        cmd.append("--skip-column-names")
    r = subprocess.run(cmd, input=stmt, capture_output=True, text=True)
    if r.returncode != 0:
        raise RuntimeError(f"mysql failed: {r.stderr.strip()[:800]}")
    return r.stdout


def rows(query):
    return [ln.split("\t") for ln in sql(query, fetch=True).splitlines() if ln]


def q(v):
    """Quote for MySQL. Mirrors worldMods.py.q() deliberately -- same escaping, same traps."""
    if v is None:
        return "NULL"
    s = (str(v).replace("\\", "\\\\").replace("'", "\\'")
         .replace("\n", "\\n").replace("\r", "").replace("\x00", ""))
    return "'" + s + "'"


def world_id(name):
    r = rows(f"SELECT id FROM worlds WHERE name={q(name)};")
    if not r:
        raise SystemExit(f"ERROR: no world named {name!r}")
    return int(r[0][0])


# ---------------------------------------------------------------------------------------
# The parser
# ---------------------------------------------------------------------------------------
#
# A BepInEx config file is INI-shaped and self-describing. The loader writes the type, the
# default and the permitted values as comments above each entry, which is what makes a typed
# form UI possible without a per-mod schema registry:
#
#     ## Whether the patch should run on game launch.
#     # Setting type: Boolean
#     # Default value: true
#     Enabled = true
#
#     ## A value other than Automatic is always applied.
#     # Setting type: EntryPointOverrideMode
#     # Default value: Automatic
#     # Acceptable values: Automatic, Default, Disabled
#     Mode = Automatic
#
# `##` is the human description, `#` carries the metadata. `# Default value:` is the baseline
# every modified-from-default badge and the whole legacy import diff are computed against --
# it travels INSIDE the file, so it needs no network and no catalogue lookup.

RE_SECTION = re.compile(r"^\[(?P<name>.+)\]\s*$")
# The key may contain spaces (BepInEx permits "Setting name = value"), so this anchors on the
# first `=` rather than on a word boundary. A leading # or [ is excluded so comments and
# section headers cannot be mistaken for entries.
#
# The whitespace on BOTH sides of the `=` is captured, not just skipped. A rewrite rebuilds
# the line from these groups, and an uncaptured `\s*` before the `=` silently reformatted
# every overridden line -- `Weird   =    7` came back as `Weird=    99`. BepInEx reads either
# form, so nothing would have broken; the file would simply have stopped matching the mod's
# own output on exactly the lines the operator touched, which is the one property a surgical
# write exists to keep. Caught by the byte-identity assertion in test-mod-config-editor.sh,
# and NOT by counting how many overrides applied -- that count was correct the whole time.
RE_ENTRY = re.compile(r"^(?P<key>[^#\[=][^=]*?)(?P<presep>\s*)=(?P<sep>\s*)(?P<value>.*)$")
RE_META_TYPE = re.compile(r"^#\s*Setting type:\s*(?P<v>.*)$")
RE_META_DEFAULT = re.compile(r"^#\s*Default value:\s*(?P<v>.*)$")
RE_META_VALUES = re.compile(r"^#\s*Acceptable values:\s*(?P<v>.*)$")
RE_META_RANGE = re.compile(r"^#\s*Acceptable value range:\s*From\s+(?P<lo>\S+)\s+to\s+(?P<hi>\S+)\s*$")
RE_PLUGIN_NAME = re.compile(r"^##\s*Settings file was created by(?: plugin)?\s+(?P<v>.+?)\s+v[\d.]+\s*$")
RE_PLUGIN_GUID = re.compile(r"^##\s*Plugin GUID:\s*(?P<v>.*)$")


def parse_cfg(text):
    """Parse BepInEx cfg text.

    Returns {"plugin": name|None, "guid": str|None, "entries": [entry, ...]} where each entry
    carries its own line index so a later write can be surgical. `has_default` is tracked
    separately from `default` because "the file says the default is empty string" and "the
    file does not say" are different facts, and the legacy import needs to tell them apart:
    with no baseline it cannot decide whether a value is an operator edit or an untouched
    default, and guessing either way is how a whole world gets frozen at its import-time
    config.
    """
    plugin = guid = None
    section = ""
    desc = []
    meta = {}
    entries = []

    for idx, raw in enumerate(text.splitlines()):
        line = raw.rstrip("\n")
        stripped = line.strip()

        if not stripped:
            # A blank line ends an entry's comment block. Without this, a description four
            # entries up would still be attached to the next key encountered.
            desc, meta = [], {}
            continue

        m = RE_PLUGIN_NAME.match(stripped)
        if m:
            plugin = m.group("v").strip()
            continue
        m = RE_PLUGIN_GUID.match(stripped)
        if m:
            guid = m.group("v").strip()
            continue

        m = RE_SECTION.match(stripped)
        if m:
            section = m.group("name").strip()
            desc, meta = [], {}
            continue

        if stripped.startswith("##"):
            desc.append(stripped[2:].strip())
            continue

        if stripped.startswith("#"):
            for rx, key in ((RE_META_TYPE, "type"),
                            (RE_META_DEFAULT, "default"),
                            (RE_META_VALUES, "acceptable")):
                m = rx.match(stripped)
                if m:
                    meta[key] = m.group("v").strip()
                    break
            else:
                m = RE_META_RANGE.match(stripped)
                if m:
                    meta["range"] = [m.group("lo"), m.group("hi")]
            continue

        m = RE_ENTRY.match(line)
        if m:
            acceptable = meta.get("acceptable")
            entries.append({
                "section": section,
                "key": m.group("key").strip(),
                "value": m.group("value").strip(),
                "type": meta.get("type"),
                "default": meta.get("default"),
                "has_default": "default" in meta,
                "acceptable": [a.strip() for a in acceptable.split(",")] if acceptable else None,
                "range": meta.get("range"),
                "description": " ".join(d for d in desc if d) or None,
                "line": idx,
            })
            desc, meta = [], {}

    return {"plugin": plugin, "guid": guid, "entries": entries}


#Header for a config file this tool creates from scratch. Deliberately uses `##` so BepInEx
#keeps it as a comment and so a human opening the file knows where the values came from.
CREATED_HEADER = (
    "## Settings file written by PhValheim from your saved mod settings.\n"
    "##\n"
    "## BepInEx adopts these values when the mod binds them, then rewrites this file with its\n"
    "## own documentation. Edit these in the admin UI under Mod Configs, not here -- an edit\n"
    "## made here is replaced the next time this world starts or updates.\n"
)


def render_new_cfg(overrides):
    """A minimal cfg carrying just the overridden keys, grouped by section.

    WHY THIS HAS TO EXIST -- this is the 2.55 bug.
    purgeWorldModsConfigsPatchers() empties BepInEx/config on every world update, and almost
    no mod ships a config/ inside its zip (AzuClock does not). So at materialise time, during
    an update, the file an override targets DOES NOT EXIST YET: the mod writes it later, on
    first load, from its own defaults. A materialiser that only rewrites existing files
    therefore applies nothing at all on the path that matters, and the operator's settings are
    replaced by defaults every single update. The first cut of 2.55 did exactly that and
    logged `0 applied, 78 not applicable`.

    A partial file is enough. BepInEx's ConfigFile parses the whole file into its entries and
    an orphaned-entries table, and Bind() adopts a stored value when the key is present --
    which is precisely how the pre-2.55 custom_configs/ mechanism worked, since those files
    were copied in BEFORE the world had ever booted.

    The first cut of 2.55 asserted the reverse in a comment -- that a pre-placed key would be
    disregarded by the loader and dropped again on save -- and refused to write on the strength
    of it. That assertion was false, and the refusal it justified is the bug. It is paraphrased
    rather than quoted here on purpose: a verify marker asserts the original sentence is gone
    from this file, and a verbatim copy inside the explanation would keep that marker red
    forever while proving nothing.
    """
    by_section = {}
    for ov in overrides:
        by_section.setdefault(ov["section"], []).append(ov)
    out = [CREATED_HEADER]
    for section in sorted(by_section):
        out.append(f"\n[{section}]\n\n")
        for ov in by_section[section]:
            out.append(f"{ov['ckey']} = {ov['cvalue']}\n")
    return "".join(out)


def apply_to_text(text, overrides, inject=True):
    """Write the overrides into cfg text. Returns (new_text, applied, injected).

    SURGICAL for keys that are already there. Gale parses a cfg into its own model and writes
    the whole file back, which reorders sections and adds newlines. Here only the value span of
    a matched line changes, so the file stays byte-identical everywhere else, a diff against
    the mod's own output stays readable, and any exotica the parser did not understand survives
    instead of being dropped on the floor.

    A key that is NOT present is INJECTED -- appended inside its section if the section exists,
    otherwise as a new section at the end. See render_new_cfg() for why: on an update the file
    is regenerated by the mod AFTER this runs, so "not present yet" is the normal case, not an
    error, and refusing to write it is what broke the feature.

    Injection is harmless when the mod genuinely renamed or dropped the setting: BepInEx keeps
    an unbound key as an orphaned entry and nothing reads it. discover() is what tells the
    operator about those, and it looks at the LIVE post-boot file where the distinction is
    real -- a key the mod bound carries `# Setting type:` metadata, an orphan does not.
    """
    lines = text.splitlines(keepends=True)
    parsed = parse_cfg(text)
    index = {(e["section"], e["key"]): e for e in parsed["entries"]}

    applied, missing = [], []
    for ov in overrides:
        entry = index.get((ov["section"], ov["ckey"]))
        if entry is None:
            missing.append(ov)
            continue
        i = entry["line"]
        raw = lines[i]
        newline = "\n" if raw.endswith("\n") else ""
        m = RE_ENTRY.match(raw.rstrip("\n"))
        # Preserve the key text and the spacing on BOTH sides of the `=` exactly as the mod
        # wrote them, so the only bytes that change on this line are the value itself.
        lines[i] = (f"{m.group('key')}{m.group('presep')}={m.group('sep')}"
                    f"{ov['cvalue']}{newline}")
        applied.append(ov)

    if not missing:
        return "".join(lines), applied, []
    if not inject:
        # Still REPORT them. Returning an empty list here made inject=False silently claim
        # there was nothing to do, which is the same shape of lie as the bug this whole change
        # is fixing. The third value is always "the keys that were not already in the file";
        # whether they got written is decided by the mode the caller asked for.
        return "".join(lines), applied, missing

    # Where each existing section ends, from the ORIGINAL parse. The replacements above are
    # one-for-one line swaps, so these indexes are still valid.
    section_last = {}
    for e in parsed["entries"]:
        section_last[e["section"]] = max(section_last.get(e["section"], -1), e["line"])

    inserts = []
    new_sections = {}
    for ov in missing:
        line = f"{ov['ckey']} = {ov['cvalue']}\n"
        if ov["section"] in section_last:
            inserts.append((section_last[ov["section"]] + 1, line))
        else:
            new_sections.setdefault(ov["section"], []).append(line)

    # Bottom-up, so an earlier insertion does not shift a later index.
    for at, line in sorted(inserts, key=lambda t: -t[0]):
        lines.insert(at, line)

    tail = []
    for section in sorted(new_sections):
        tail.append(f"\n[{section}]\n\n")
        tail.extend(new_sections[section])

    body = "".join(lines)
    if tail and not body.endswith("\n"):
        body += "\n"
    return body + "".join(tail), applied, missing


# ---------------------------------------------------------------------------------------
# Attribution
# ---------------------------------------------------------------------------------------
#
# A cfg file is named for the plugin GUID (org.bepinex.plugins.something.cfg), NOT for the
# catalogue's owner-name. There is no reliable mapping, so this matches on the three handles
# the file itself offers -- the GUID, the `created by` plugin name, and the filename stem --
# against the names of the mods THIS WORLD has, normalised.
#
# It requires a UNIQUE winner and returns None otherwise. A wrong attribution is worse than
# none: it would hide a mod's real config behind another mod's Config icon, and the operator
# would have no way to tell. An unattributed file is not lost -- it lists under Unattributed
# on the all-configs page, which is exactly the fallback the non-catalogue plugins need
# (PhValheim-TickMonitor and ZeroBandwidth-CustomSeed are not in the catalogue at all).

def _norm(s):
    return re.sub(r"[^a-z0-9]", "", (s or "").lower())


def guid_owners(wid):
    """BepInEx plugin GUID -> mod_id, for the mods THIS world installs.

    The exact half of attribution, learned at install time by pluginGuids.py from the
    BepInPlugin attribute inside each package's assemblies. BepInEx names a config file after
    the plugin's GUID, so this answers "which package wrote this file" outright, where the
    name matching below can only guess.

    Scoped to the world, not global: the same GUID learned from a package this world does not
    have must not attribute its file here. And a GUID declared by TWO of this world's packages
    is dropped rather than guessed between -- same rule the name match uses, for the same
    reason (a wrong owner is worse than none: it files the setting under a mod that never
    reads it).
    """
    seen = {}
    for guid, mod_id in rows(
            "SELECT g.guid, g.mod_id FROM mod_plugin_guids g "
            f"JOIN world_mods wm ON wm.mod_id = g.mod_id AND wm.world_id = {wid};"):
        if guid in seen and seen[guid] != int(mod_id):
            seen[guid] = None
        elif guid not in seen:
            seen[guid] = int(mod_id)
    return {g: m for g, m in seen.items() if m is not None}


def attribute(cfg_name, parsed, catalogue, guids=None):
    # The GUID first, because it is evidence rather than resemblance. The file's own header
    # states the GUID that wrote it, and guid_owners() knows which package declares that GUID
    # -- so this is exact where everything below is a string guess. Only its ABSENCE falls
    # through to the guessing: a world that has not been packaged since this existed, or a
    # file whose header carries no GUID at all.
    if guids:
        g = (parsed.get("guid") or "").strip()
        if g and g in guids:
            return guids[g]
        # The filename IS the GUID whenever BepInEx created the file, so a config whose header
        # we could not parse is still answerable.
        stem_guid = cfg_name[:-4] if cfg_name.endswith(".cfg") else cfg_name
        if stem_guid in guids:
            return guids[stem_guid]

    stem = cfg_name[:-4] if cfg_name.endswith(".cfg") else cfg_name
    handles = {_norm(stem), _norm(parsed.get("plugin")), _norm(parsed.get("guid"))}
    # The GUID is usually dotted and prefixed; its last segment is the part that resembles a
    # mod name (org.bepinex.plugins.valheim_plus -> valheim_plus).
    for raw in (stem, parsed.get("guid")):
        if raw and "." in raw:
            handles.add(_norm(raw.rsplit(".", 1)[-1]))
    handles.discard("")

    hits = {mid for key, mid in catalogue.items() if key in handles}
    return hits.pop() if len(hits) == 1 else None


def world_catalogue(wid):
    """normalised mod name -> mod_id, for the mods this world has selected."""
    out = {}
    for mod_id, name, full_name in rows(
            "SELECT m.id, m.name, m.full_name FROM world_mods wm "
            f"JOIN mods m ON m.id = wm.mod_id WHERE wm.world_id = {wid};"):
        for handle in (name, full_name):
            k = _norm(handle)
            if k:
                out[k] = int(mod_id)
    return out


# ---------------------------------------------------------------------------------------
# Paths
# ---------------------------------------------------------------------------------------
#
# Both trees, named rather than inferred. The server tree is game/, the client staging tree is
# client/ -- matching clientStagingRoot() in 0-functions.sh. 2.53 made the server-only
# guarantee structural by naming the server's tree instead of relying on running after
# packageClient(); keeping these two as explicit named paths is the same decision.

def server_config_dir(world):
    return os.path.join(WORLDS_ROOT, world, "game", "BepInEx", "config")


def client_config_dir(world):
    return os.path.join(WORLDS_ROOT, world, "client", "BepInEx", "config")


def editable_cfgs(directory):
    if not os.path.isdir(directory):
        return []
    return sorted(f for f in os.listdir(directory)
                  if f.endswith(".cfg")
                  and f not in EXCLUDED_FILES
                  and os.path.isfile(os.path.join(directory, f)))


def read_text(path):
    # errors="replace" rather than strict: a mod may write a stray byte, and one bad byte in
    # one file must not take down the whole editor page or the whole materialise pass.
    with open(path, "r", encoding="utf-8", errors="replace") as fh:
        return fh.read()


def load_overrides(wid):
    out = {}
    for cfg_file, section, ckey, cvalue, server_only, locked, origin in rows(
            "SELECT cfg_file, section, ckey, cvalue, server_only, locked, origin "
            f"FROM mod_config_overrides WHERE world_id = {wid};"):
        out.setdefault(cfg_file, []).append({
            "cfg_file": cfg_file, "section": section, "ckey": ckey, "cvalue": cvalue,
            "server_only": int(server_only), "locked": int(locked), "origin": origin,
        })
    return out


# ---------------------------------------------------------------------------------------
# --discover
# ---------------------------------------------------------------------------------------

def discover(world):
    """Everything the editor needs to render, including the three states it must distinguish.

    `generated` is the one that matters. Most BepInEx mods do not ship a cfg -- the file is
    written on first Config.Bind(), so a mod that has just been added to a world has NOTHING
    to edit until the world has booted once with it. "No file" and "a file that happens to be
    all defaults" are different answers, and a 0/false default that doubles as a real answer
    has shipped here three separate times. So the unknown state is reported explicitly rather
    than reconstructed in the UI from an empty entry list.
    """
    wid = world_id(world)
    directory = server_config_dir(world)
    catalogue = world_catalogue(wid)
    guids = guid_owners(wid)
    overrides = load_overrides(wid)

    files, seen = [], set()
    for name in editable_cfgs(directory):
        text = read_text(os.path.join(directory, name))
        parsed = parse_cfg(text)
        mod_id = attribute(name, parsed, catalogue, guids)
        ov_index = {(o["section"], o["ckey"]): o for o in overrides.get(name, [])}
        seen.add(name)

        entries = []
        for e in parsed["entries"]:
            ov = ov_index.get((e["section"], e["key"]))
            effective = ov["cvalue"] if ov else e["value"]
            entries.append({
                "section": e["section"], "key": e["key"],
                "type": e["type"], "description": e["description"],
                "default": e["default"], "has_default": e["has_default"],
                "acceptable": e["acceptable"], "range": e["range"],
                "file_value": e["value"],
                "value": effective,
                "overridden": ov is not None,
                "modified": e["has_default"] and effective != e["default"],
                "server_only": ov["server_only"] if ov else 0,
                "locked": ov["locked"] if ov else 0,
                "origin": ov["origin"] if ov else None,
            })

        files.append({
            "file": name, "plugin": parsed["plugin"], "guid": parsed["guid"],
            "mod_id": mod_id, "generated": True,
            "entry_count": len(entries),
            "override_count": sum(1 for e in entries if e["overridden"]),
            "entries": entries,
        })

    # Override rows whose file is gone, and rows whose key is gone from a file that is still
    # here. Both are shown, never silently dropped: a mod update that renames a setting leaves
    # a row that will never apply again, and the operator is the only one who can decide
    # whether to delete it or re-set the new key. Hiding it would make the editor claim an
    # override is in force when it is not.
    # Detected by METADATA, not by the key's absence.
    #
    # materialise() now injects a missing key rather than refusing to write it, so "the key is
    # not in the file" stopped being a usable signal -- after any start or update, every
    # override's key is present and a presence test would report nothing, forever.
    #
    # What still distinguishes them is what BepInEx writes. A key the plugin actually bound
    # gets `# Setting type:` and `# Default value:` above it when BepInEx rewrites the file; an
    # orphaned key -- one we injected that nothing binds, because the mod renamed or dropped it
    # -- keeps its bare `key = value` line and gains no metadata.
    #
    # Guarded by the file-has-metadata test: a file this tool has just CREATED has no metadata
    # on anything, which means the mod has not booted with it yet. Calling those orphans would
    # flag every freshly-applied setting as broken.
    stale = []
    for cfg_file, ovs in overrides.items():
        if cfg_file not in seen:
            stale += [dict(o, reason="the mod has not written this config yet") for o in ovs]
            continue
        entries = parse_cfg(read_text(os.path.join(directory, cfg_file)))["entries"]
        by_key = {(e["section"], e["key"]): e for e in entries}
        documented = any(e["type"] or e["has_default"] for e in entries)
        for o in ovs:
            e = by_key.get((o["section"], o["ckey"]))
            if e is None:
                stale += [dict(o, reason="not present in the installed version")]
            elif documented and not e["type"] and not e["has_default"]:
                stale += [dict(o, reason="this version of the mod does not use this setting")]

    return {
        "world": world, "world_id": wid,
        "config_dir_exists": os.path.isdir(directory),
        "files": files, "stale": stale,
    }


# ---------------------------------------------------------------------------------------
# --materialise
# ---------------------------------------------------------------------------------------

def materialise(world):
    """Write the DB's overrides onto the world's trees.

    ORDERING: this must run AFTER installCustomModsConfigsPatchers(), which copies
    custom_configs/* into both trees. That copy is still the engine's own distribution
    mechanism -- createCustomSeedConfig() writes the world's SEED there at creation and
    relies on every later update redistributing it -- so it cannot simply be removed. Running
    last is what makes the database win over the directory.

    server_only rows are applied to the server tree ONLY. That is enforced by naming the two
    trees, not by running at a particular point relative to packageClient(); 2.53 learned that
    an ordering nothing declares is not a guarantee.
    """
    wid = world_id(world)
    overrides = load_overrides(wid)
    if not overrides:
        print(f"[modConfigs] {world}: no overrides to apply")
        return 0

    total_applied = total_created = total_injected = 0
    for tree, directory in (("server", server_config_dir(world)),
                            ("client", client_config_dir(world))):
        if not os.path.isdir(directory):
            # A vanilla world has no BepInEx tree. The client staging tree does not exist
            # until a payload has been built. Both are normal; neither is an error. Note this
            # checks the DIRECTORY, not the file -- a missing file is handled below by
            # creating it, which is the whole point of the 2.55 fix.
            continue
        for cfg_file, ovs in overrides.items():
            if cfg_file in EXCLUDED_FILES:
                continue
            if tree == "client":
                ovs = [o for o in ovs if not o["server_only"]]
                if not ovs:
                    continue
            path = os.path.join(directory, cfg_file)

            if not os.path.isfile(path):
                # CREATE it. During an update the purge has just emptied this directory and
                # the mod has not run yet, so this is the normal case rather than an edge
                # case -- see render_new_cfg(). Skipping it here is what made every override
                # silently inert on the one path that matters.
                with open(path, "w", encoding="utf-8") as fh:
                    fh.write(render_new_cfg(ovs))
                total_created += len(ovs)
                print(f"[modConfigs] {world}: {tree}: {cfg_file}: created with "
                      f"{len(ovs)} saved setting(s) (the mod had not written it yet)")
                continue

            text = read_text(path)
            new_text, applied, injected = apply_to_text(text, ovs)
            if new_text != text:
                with open(path, "w", encoding="utf-8") as fh:
                    fh.write(new_text)
            total_applied += len(applied)
            total_injected += len(injected)
            for o in injected:
                print(f"[modConfigs] {world}: {tree}: {cfg_file}: "
                      f"added [{o['section']}] {o['ckey']} (not in the file yet)")
            if applied:
                print(f"[modConfigs] {world}: {tree}: {cfg_file}: "
                      f"applied {len(applied)} override(s)")

    print(f"[modConfigs] {world}: {total_applied} applied, {total_injected} added, "
          f"{total_created} written into new files")
    return 0


# ---------------------------------------------------------------------------------------
# --import-legacy
# ---------------------------------------------------------------------------------------

def import_legacy_world(world):
    """Lift one world's custom_configs*/ content into mod_config_overrides, once.

    THE TRAP THIS IS BUILT AROUND
    importWorld.sh copies an imported world's ENTIRE BepInEx/config tree into
    custom_configs/. So an imported world is indistinguishable, by directory listing alone,
    from an operator who hand-copied hundreds of files -- and almost every one of those files
    is an untouched default. Import them as overrides and the world is frozen at its
    import-time defaults forever: every later mod update would have its new defaults
    overwritten by rows nobody set.

    So a key is imported only when it DIFFERS from a knowable baseline, and the baseline is
    the file's own `# Default value:` comment -- which rides inside the file, needs no network
    and no catalogue, and is the same baseline the editor's modified badge uses.

    A key with NO `# Default value:` has no baseline at all. Those are imported as
    origin='legacy-review' rather than silently kept or silently dropped: either choice would
    be a guess wearing the costume of a fact, and the operator is the only one who can say
    whether that value was theirs.
    """
    wid = world_id(world)
    base = os.path.join(WORLDS_ROOT, world)
    imported = reviewed = skipped = 0

    for subdir, server_only in (("custom_configs", 0), ("custom_configs_secure", 1)):
        source = os.path.join(base, subdir)
        if not os.path.isdir(source):
            continue
        parked = os.path.join(source, IMPORTED_DIR)
        catalogue = world_catalogue(wid)

        for name in sorted(os.listdir(source)):
            path = os.path.join(source, name)
            if not os.path.isfile(path) or not name.endswith(".cfg"):
                continue
            if name in EXCLUDED_FILES:
                # The loader's config and the engine's seed file stay exactly where they are
                # and keep being distributed by installCustomModsConfigsPatchers(). Importing
                # the seed would hand the operator a knob that fights the engine; importing
                # BepInEx.cfg is what 2.49 did, and it cost the world log and the client
                # console in one stroke.
                print(f"[modConfigs] {world}: {subdir}/{name}: left in place (not a mod config)")
                continue

            parsed = parse_cfg(read_text(path))
            if not parsed["entries"]:
                print(f"[modConfigs] {world}: {subdir}/{name}: no parsable entries, left in place")
                continue

            mod_id = attribute(name, parsed, catalogue, guid_owners(wid))
            took = 0
            for e in parsed["entries"]:
                if e["has_default"]:
                    if e["value"] == e["default"]:
                        skipped += 1
                        continue
                    origin = "legacy"
                else:
                    origin = "legacy-review"
                    reviewed += 1

                # INSERT IGNORE, not REPLACE: a row the operator has already set in the editor
                # outranks anything found on disk. The import is a one-shot lift of history,
                # not an authority.
                sql("INSERT IGNORE INTO mod_config_overrides "
                    "(world_id, cfg_file, section, ckey, cvalue, mod_id, server_only, locked, origin) "
                    f"VALUES ({wid}, {q(name)}, {q(e['section'])}, {q(e['key'])}, "
                    f"{q(e['value'])}, {mod_id if mod_id else 'NULL'}, {server_only}, 0, {q(origin)});")
                took += 1
                imported += 1

            if took:
                os.makedirs(parked, exist_ok=True)
                shutil.move(path, os.path.join(parked, name))
                print(f"[modConfigs] {world}: {subdir}/{name}: imported {took} setting(s), "
                      f"file moved to {subdir}/{IMPORTED_DIR}/")
            else:
                print(f"[modConfigs] {world}: {subdir}/{name}: every value matches its "
                      f"documented default, nothing imported, file left in place")

    print(f"[modConfigs] {world}: imported {imported} override(s) "
          f"({reviewed} need review, {skipped} were untouched defaults)")
    return 0


def catalogue_from_json(path):
    """Build the attribution lookup from a caller-supplied list, instead of from the DB.

    The admin UI has the world's mods already (it reads them through PDO) but cannot run the
    DB modes here, so it passes them in and attribution still happens in attribute() -- one
    implementation, one set of normalisation rules. Reimplementing _norm() in PHP would create
    a second attributor that drifts from this one, and the symptom of a drift is a mod's config
    appearing under the wrong mod's Config icon, which an operator has no way to detect.

    Accepts [{"id": 1, "name": "...", "full_name": "...", "guids": ["com.x.y", ...]}, ...].
    Returns (name_handles, guid_owners) -- the same two lookups the DB modes build, so
    attribute() behaves identically whichever side called it. `guids` is optional; an older
    caller that omits it gets name matching alone, which is what 2.55 shipped with.
    """
    out = {}
    guids = {}
    with open(path, "r", encoding="utf-8") as fh:
        for row in json.load(fh):
            mod_id = int(row["id"])
            for handle in (row.get("name"), row.get("full_name")):
                k = _norm(handle)
                if k:
                    out[k] = mod_id
            for g in (row.get("guids") or []):
                g = (g or "").strip()
                if not g:
                    continue
                # Ambiguity is dropped, not resolved -- see guid_owners().
                guids[g] = None if (g in guids and guids[g] != mod_id) else mod_id
    return out, {g: m for g, m in guids.items() if m is not None}


def parse_dir(world, catalogue=None, directory=None, guids=None):
    """Every editable cfg in a directory, parsed. NO DATABASE.

    `directory` defaults to the world's SERVER tree. The migration review passes the parked
    .imported-pre-2.55/ directory instead, because it needs the SAME attribution the editor
    uses -- "does a mod this world still has claim this config file?" -- and reimplementing
    _norm()/attribute() in PHP would create a second attributor that drifts from this one.
    See catalogue_from_json().

    This is the mode the admin UI calls, and the absence of a database connection is the
    whole point. php-fpm runs as the `phvalheim` user and reaches the database as
    `phvalheim_user` through PDO, while every DB-using mode in this file speaks to mysql as
    `-uroot`. A page that shelled out to --discover would be asking the web user to
    authenticate as root, which is both wrong and fragile.

    So the split is by privilege, not by convenience: the UI parses files here and merges its
    own override rows through PDO, and the root-only modes (--materialise, --import-legacy)
    are called by the engine, which is already root.

    `generated` is reported per world, not inferred from an empty list. Most BepInEx mods do
    not ship a cfg -- the file appears on the first Config.Bind() -- so a world whose mods
    have never loaded has nothing to edit, and that is a different answer from "a config with
    all defaults". A 0/false default doubling as a real answer has shipped here three times.
    """
    directory = directory or server_config_dir(world)
    catalogue = catalogue or {}
    files = []
    for name in editable_cfgs(directory):
        parsed = parse_cfg(read_text(os.path.join(directory, name)))
        files.append({
            "file": name,
            "plugin": parsed["plugin"],
            "guid": parsed["guid"],
            "mod_id": attribute(name, parsed, catalogue, guids),
            "entries": parsed["entries"],
        })
    return {
        "world": world,
        "config_dir_exists": os.path.isdir(directory),
        "generated": bool(files),
        "files": files,
    }


def main():
    ap = argparse.ArgumentParser()
    ap.add_argument("--world")
    ap.add_argument("--all", action="store_true")
    ap.add_argument("--discover", action="store_true")
    ap.add_argument("--materialise", action="store_true")
    ap.add_argument("--parse-file")
    ap.add_argument("--parse-dir", action="store_true")
    ap.add_argument("--dir",
                    help="with --parse-dir: parse THIS directory instead of the world's "
                         "server config tree (the migration review points it at the parked "
                         "pre-2.55 originals)")
    ap.add_argument("--catalogue-file",
                    help="JSON [{id,name,full_name}] for attribution, for callers that "
                         "cannot use the DB modes (the admin UI)")
    ap.add_argument("--import-legacy", action="store_true")
    args = ap.parse_args()

    if args.parse_file:
        print(json.dumps(parse_cfg(read_text(args.parse_file)), indent=1))
        return 0

    if args.parse_dir:
        if not args.world:
            raise SystemExit("ERROR: --parse-dir needs --world NAME")
        cat, cat_guids = (catalogue_from_json(args.catalogue_file)
                          if args.catalogue_file else (None, None))
        print(json.dumps(parse_dir(args.world, cat, args.dir, cat_guids), indent=1))
        return 0

    if args.import_legacy:
        if args.all:
            names = [r[0] for r in rows("SELECT name FROM worlds ORDER BY id;")]
        elif args.world:
            names = [args.world]
        else:
            raise SystemExit("ERROR: --import-legacy needs --world NAME or --all")
        for name in names:
            import_legacy_world(name)
        return 0

    if not args.world:
        raise SystemExit("ERROR: --world NAME is required")
    if args.discover:
        print(json.dumps(discover(args.world), indent=1))
        return 0
    if args.materialise:
        return materialise(args.world)

    raise SystemExit("ERROR: pick one of --parse-dir, --parse-file, --discover, "
                     "--materialise, --import-legacy")


if __name__ == "__main__":
    sys.exit(main())
