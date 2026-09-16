#!/usr/bin/env python3
"""A world's mod set: dependency closure, install plan, and the admin viewer payload.

  worldMods.py --world NAME --resolve              expand dependencies into world_mods
  worldMods.py --world NAME --plan                 TSV install plan for the engine
  worldMods.py --world NAME --viewer-json          refresh worlds.modsViewer (DISPLAY ONLY)
  worldMods.py --world NAME --record-installed IDS record what is on disk (INSTALLER ONLY)

Replaces tsModDepGetter.sh and generateModViewerJson().

The old dependency walker ran a fresh `mysql` process per dependency per level, against a
`tsmods` table with no index but its primary key, and parsed `owner-name-version` by
cutting on hyphens -- which misresolves any owner or version that contains one
(LVH-IT, sinai-dev, 2.0.6-beta.1). The graph is now precomputed into `mod_deps` by
modSync.py using longest-prefix matching, so the closure here is a plain indexed walk.
"""

import argparse
import json
import subprocess
import sys

DB = "phvalheim"
MYSQL = "/usr/bin/mysql"


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
    if v is None or v == "":
        return "NULL"
    s = (str(v).replace("\\", "\\\\").replace("'", "\\'")
         .replace("\n", "\\n").replace("\r", "").replace("\x00", ""))
    return "'" + s + "'"


def world_id(name):
    r = rows(f"SELECT id FROM worlds WHERE name={q(name)} LIMIT 1;")
    if not r:
        raise SystemExit(f"world '{name}' not found")
    return r[0][0]


def chosen(wid):
    """The operator's own picks -- is_dep=0."""
    return [r[0] for r in rows(
        f"SELECT mod_id FROM world_mods WHERE world_id={wid} AND is_dep=0;")]


# Preference when two catalogues offer the same plugin at the same version. Thunderstore
# is the canonical upstream -- Hexium mirrors it, carrying the original uuid4 -- so it
# wins a tie. Only used as a tiebreak; the newer version wins first.
SOURCE_ORDER = {"thunderstore": 0, "hexium": 1}


def is_loader(name):
    """The BepInEx mod loader, which a world never installs as a mod.

    InstallAndUpdateBepInEx() puts the loader in place at engine start, unconditionally and
    always latest, before downloadAndInstallTsModsForWorld runs. So a loader row in a world's
    closure downloads and unzips a second copy of something already installed -- and every mod
    in the catalogue depends on one, which is how it got there.

    Kept out of the closure rather than filtered later, so world_mods, the install plan and
    the mod viewer all agree that it is not part of the selection. Mirrors
    loaderExclusionSql() in includes/modcatalog.php -- change both together.
    """
    return str(name or "").lower().startswith("bepinexpack")


def version_key(version):
    """Sortable key for a version string; a prerelease ranks below its own release.

    Deliberately not full semver -- it only has to answer "which of these two copies of
    the same plugin is newer". A non-numeric part counts as 0 rather than raising, because
    the catalogues really do carry '1.0' and '2.0.6-beta.1'.
    """
    head, _, pre = str(version or "").partition("-")
    nums = []
    for part in head.split("."):
        digits = "".join(c for c in part if c.isdigit())
        nums.append(int(digits) if digits else 0)
    return (tuple(nums), 0 if pre else 1)


def mod_meta(mod_ids):
    """{mod_id: (source, owner, name)}."""
    if not mod_ids:
        return {}
    inlist = ",".join(str(m) for m in mod_ids)
    return {r[0]: (r[1], r[2], r[3]) for r in rows(
        f"SELECT id, source, owner, name FROM mods WHERE id IN ({inlist});")}


def by_plugin(wid, mod_ids, meta):
    """Group mod ids by INSTALLABLE identity: {(owner, name): [best, ...worse]}.

    A mod's files land in the same game/BepInEx tree whichever catalogue supplied the zip,
    so (owner, name) -- not the mod id, and not the source -- is what a world can only
    have one of. Both catalogues carry denikson/BepInExPack_Valheim, and modSync
    deliberately resolves each mod's dependency to its OWN catalogue's copy, so any world
    drawing from both ends up with two of them.

    Newest version first, since that is the copy satisfying the highest requirement.
    """
    groups = {}
    for mid in mod_ids:
        if mid not in meta:
            continue
        source, owner, name = meta[mid]
        _, version, _, _ = effective_version(wid, mid)
        groups.setdefault((owner, name), []).append(
            ((version_key(version), -SOURCE_ORDER.get(source, 99)), mid, source, version))
    for g in groups.values():
        g.sort(key=lambda t: t[0], reverse=True)
    return groups


def effective_version(wid, mod_id):
    """(version_id, version, download_url) honouring a pin, else the newest.

    A pin is only honoured if the row still exists; modSync keeps pinned versions alive
    even when a source delists them, so this normally succeeds. Falling back to newest
    rather than failing means an un-pinnable world still starts, and the caller logs it.
    """
    r = rows(f"SELECT v.id, v.version, v.download_url FROM world_mods wm "
             f"JOIN mod_versions v ON v.id = wm.pin_version_id "
             f"WHERE wm.world_id={wid} AND wm.mod_id={mod_id} "
             f"  AND wm.pin_version_id IS NOT NULL LIMIT 1;")
    if r:
        return r[0][0], r[0][1], r[0][2], True
    r = rows(f"SELECT id, version, download_url FROM mod_versions "
             f"WHERE mod_id={mod_id} ORDER BY source_rank ASC LIMIT 1;")
    if r:
        return r[0][0], r[0][1], r[0][2], False
    return None, None, None, False


def resolve(name, quiet=False):
    """Walk mod_deps transitively and record the closure as is_dep=1 rows.

    Only the version a world will ACTUALLY install contributes its dependencies -- the
    pinned one if pinned, otherwise the newest. Walking the newest version's deps for a
    world pinned to an older release is how you end up installing a dependency set the
    pinned mod never asked for.
    """
    wid = world_id(name)
    picks = chosen(wid)
    if not picks:
        # No explicit picks means nothing to expand. Clearing stale dependency rows still
        # matters: a world whose last mod was just removed must not keep that mod's
        # dependencies installed.
        sql(f"DELETE FROM world_mods WHERE world_id={wid} AND is_dep=1;")
        if not quiet:
            print(f"[worldmods] '{name}': no mods selected")
        return []

    seen = set(picks)
    frontier = list(picks)
    deps = set()
    missing = []
    depth = 0

    while frontier and depth < 32:
        depth += 1
        vids = []
        for mod_id in frontier:
            vid, _, _, _ = effective_version(wid, mod_id)
            if vid:
                vids.append(vid)
        if not vids:
            break
        nxt = []
        for r in rows("SELECT DISTINCT dep_mod_id, dep_string FROM mod_deps "
                      "WHERE version_id IN (" + ",".join(vids) + ");"):
            dep_mod, dep_string = (r + ["", ""])[:2]
            if dep_mod in ("NULL", "", None):
                # An unresolvable dependency is a mod we simply do not have. Silence here
                # is how a world reaches its first start missing a plugin with nothing
                # explaining why, so it is collected and reported.
                missing.append(dep_string)
                continue
            if dep_mod in seen:
                continue
            seen.add(dep_mod)
            deps.add(dep_mod)
            nxt.append(dep_mod)
        frontier = nxt

    # Collapse per-catalogue copies of the same plugin before recording the closure.
    # Without this, one Hexium pick in an otherwise Thunderstore world yields TWO
    # denikson/BepInExPack_Valheim rows -- the Hexium mod's dependency resolves to
    # Hexium's copy while the three mods every world gets resolve to Thunderstore's. Both
    # unzip into game/BepInEx, so installing both is wasted work whose surviving version
    # depends on unzip order, and the mod viewer lists the plugin twice.
    meta = mod_meta(set(picks) | deps)

    # The loader is provided by the engine, not by the selection. Dropped here, before the
    # duplicate-plugin collapse, so it never becomes an is_dep row -- otherwise every world
    # carries one and the picker hangs a "dependency (deselected)" badge on a row the
    # operator cannot act on.
    loader_deps = {d for d in deps if d in meta and is_loader(meta[d][2])}
    if loader_deps and not quiet:
        for d in sorted(loader_deps):
            print(f"[worldmods] '{name}': {meta[d][0]}/{meta[d][1]}/{meta[d][2]} is the mod "
                  f"loader; installed by the engine, not added as a dependency")
    deps -= loader_deps

    picked_plugins = {meta[m][1:] for m in picks if m in meta}
    collapsed = set()
    for key, group in by_plugin(wid, deps, meta).items():
        # An explicit pick already provides the plugin; a dependency copy is redundant.
        redundant = group if key in picked_plugins else group[1:]
        if key not in picked_plugins:
            collapsed.add(group[0][1])
        for _, mid, source, version in redundant:
            if not quiet:
                why = ("already selected from another catalogue"
                       if key in picked_plugins else
                       f"superseded by {group[0][2]}'s {group[0][3]}")
                print(f"[worldmods] '{name}': skipping {source}/{key[0]}/{key[1]} "
                      f"{version or '?'} -- {why}")
    deps = collapsed

    # Rebuilt wholesale rather than diffed: a dependency that is no longer required must
    # disappear, and is_dep rows carry no operator intent worth preserving.
    sql(f"DELETE FROM world_mods WHERE world_id={wid} AND is_dep=1;")
    if deps:
        vals = ",".join(f"({wid},{d},1)" for d in sorted(deps))
        # IGNORE so a mod that is BOTH an explicit pick and someone's dependency keeps
        # its is_dep=0 row. Demoting it would let a later cascade remove a mod the
        # operator chose by hand.
        sql(f"INSERT IGNORE INTO world_mods (world_id, mod_id, is_dep) VALUES {vals};")

    if not quiet:
        print(f"[worldmods] '{name}': {len(picks)} selected, {len(deps)} dependencies")
        for m in sorted(set(missing)):
            print(f"[worldmods] WARN '{name}': dependency '{m}' is not in the catalogue; "
                  f"the mod that needs it will likely not work")
    return sorted(deps)


def install_rows(wid, warn=None):
    """What will ACTUALLY be installed: one row per plugin, an explicit pick beating a dep.

    resolve() already keeps the dependency closure free of duplicate plugins, but an
    operator can pick the same plugin from both catalogues by hand -- the picker offers
    both, each with its own source pill. Collapsing here as well means the install plan and
    the mod viewer can never disagree with each other, and neither can ever show a world
    two copies of one plugin.
    """
    all_rows = {}
    for mod_id, is_dep, source, owner, mname, page in rows(
            f"SELECT wm.mod_id, wm.is_dep, m.source, m.owner, m.name, "
            f"       COALESCE(m.package_url,'') "
            f"FROM world_mods wm JOIN mods m ON m.id = wm.mod_id "
            f"WHERE wm.world_id={wid};"):
        # Loader rows are skipped rather than installed. resolve() already keeps them out of
        # the closure, but a world migrated from 2.43 or earlier may still carry one, and
        # installing it would unzip a second loader over the one the engine just placed.
        if is_loader(mname):
            continue
        all_rows[mod_id] = (is_dep, source, owner, mname, page)

    meta = {mid: (r[1], r[2], r[3]) for mid, r in all_rows.items()}
    out = []
    for key, group in by_plugin(wid, all_rows.keys(), meta).items():
        # An operator's own pick outranks a dependency regardless of version: they asked
        # for that catalogue's copy by hand, and a dependency is satisfied either way.
        group.sort(key=lambda t: (all_rows[t[1]][0] == "0", t[0]), reverse=True)
        win = group[0][1]
        for _, mid, source, version in group[1:]:
            if warn:
                warn(f"{source}/{key[0]}/{key[1]} {version or '?'} not installed -- "
                     f"{all_rows[win][1]}'s copy of the same plugin is used instead")
        is_dep, source, owner, mname, page = all_rows[win]
        vid, version, url, pinned = effective_version(wid, win)
        out.append({"mod_id": win, "is_dep": is_dep, "source": source, "owner": owner,
                    "name": mname, "page": page, "version_id": vid,
                    "version": version, "url": url, "pinned": pinned})
    out.sort(key=lambda r: (r["is_dep"], r["name"]))
    return out


def plan(name):
    """TSV the installer loops over: source, owner, name, version, url, filename, pinned,
    is_dep, mod_id.

    Emitting a filename here keeps the engine from reconstructing one: the local cache is
    shared across worlds and sources, so the name has to include the source to stop
    Hexium's and Thunderstore's copies of the same owner/name/version colliding on a
    single cached zip.

    mod_id is APPENDED rather than inserted, so the four scripts that read fields 1-5 with
    awk keep working untouched. The installer needs it to report back which mods actually
    landed -- see record_installed().
    """
    wid = world_id(name)
    warn = lambda m: print(f"[worldmods] '{name}': {m}", file=sys.stderr)
    out = []
    for r in install_rows(wid, warn=warn):
        if not r["version_id"] or not r["url"]:
            print(f"[worldmods] ERROR '{name}': {r['source']}/{r['owner']}/{r['name']} "
                  f"has no installable version; it will be MISSING", file=sys.stderr)
            continue
        fname = f"{r['source']}-{r['owner']}-{r['name']}-{r['version']}.zip"
        out.append("\t".join([r["source"], r["owner"], r["name"], r["version"], r["url"],
                              fname, "pinned" if r["pinned"] else "latest", r["is_dep"],
                              str(r["mod_id"])]))
    print("\n".join(out))


def record_installed(name, installed_ids):
    """Record which version of each mod is now ON DISK for this world.

    The ONLY writer of world_mods.installed_version_id, and it is called from exactly one
    place: downloadAndInstallTsModsForWorld(), after the unzips, with the ids of the mods
    that actually landed. That restriction is the entire point. The previous design read
    worlds.modsViewer, whose versions come from the live catalogue, so it silently answered
    "whatever is newest today" instead of "whatever we installed" the moment anything
    refreshed it outside an install.

    Three outcomes, and the difference between them matters:

      installed  -- the mod's files were just written. Record the version we installed.
      failed     -- the download or the unzip failed. LEAVE THE ROW ALONE: the previous
                    copy is still sitting in BepInEx/plugins, so the old value is still
                    the truth. Clearing it would report "unknown" for a mod we can see.
      not in plan -- a duplicate plugin that by_plugin() collapsed away, or a loader row.
                    Nothing of it is installed under its own id, so it must claim nothing.

    The two columns carry three states between them, and the checker needs all three:

      installed_at NULL                        never recorded -- we do NOT know
      installed_at set, installed_version_id NULL   looked at, deliberately not installed
      installed_at set, installed_version_id set    this exact version is on disk

    Collapsing the middle case into the first is the trap: after a clean rebuild a world
    with one collapsed duplicate would sit at "unknown" forever, which is the same
    can't-tell-the-difference failure as the one this replaced, just pointing the other way.
    """
    wid = world_id(name)
    rows_now = install_rows(wid)
    wanted = {r["mod_id"] for r in rows_now}
    landed = {i for i in installed_ids if i in wanted}

    for r in rows_now:
        if r["mod_id"] not in landed:
            continue
        sql(f"UPDATE world_mods SET installed_version_id={r['version_id'] or 'NULL'}, "
            f"installed_at=NOW() "
            f"WHERE world_id={wid} AND mod_id={int(r['mod_id'])};")

    # Not in the plan: record that we KNOW it is not installed, rather than leaving it
    # indistinguishable from a mod nobody has ever looked at.
    not_planned = f"AND mod_id NOT IN ({','.join(str(int(m)) for m in wanted)})" if wanted else ""
    sql(f"UPDATE world_mods SET installed_version_id=NULL, installed_at=NOW() "
        f"WHERE world_id={wid} {not_planned};")

    print(f"[worldmods] '{name}': recorded installed versions for {len(landed)} of "
          f"{len(wanted)} planned mod(s)")


def viewer_json(name):
    """worlds.modsViewer -- what the admin UI's mod dropdown reads, and NOTHING else.

    This is a DISPLAY CACHE. Its versions come from effective_version(), i.e. the live
    catalogue, so an entry says what the world WOULD get, not what it has. Nothing that
    decides whether an update is available may read it -- that is what
    world_mods.installed_version_id is for. See record_installed().
    """
    wid = world_id(name)
    items = []
    # install_rows(), not world_mods directly: the viewer must show the mods a world
    # actually gets. Listing one plugin twice because two catalogues supplied it is the
    # bug this shares its fix with.
    for r in sorted(install_rows(wid), key=lambda r: r["name"]):
        items.append({
            "name": r["name"],
            "owner": r["owner"],
            "source": r["source"],
            "uuid": r["mod_id"],
            "url": r["page"],
            "version": r["version"] or "",
            "pinned": bool(r["pinned"]),
            "dependency": r["is_dep"] == "1",
        })
    payload = json.dumps(items)
    sql(f"UPDATE worlds SET modsViewer={q(payload)} WHERE id={wid};")
    print(f"[worldmods] '{name}': viewer payload refreshed ({len(items)} mods)")


def main():
    ap = argparse.ArgumentParser()
    ap.add_argument("--world", required=True)
    ap.add_argument("--resolve", action="store_true")
    ap.add_argument("--plan", action="store_true")
    ap.add_argument("--viewer-json", action="store_true")
    ap.add_argument("--record-installed", metavar="MOD_IDS",
                    help="comma-separated mod ids that were just installed on disk; "
                         "call this ONLY from the installer")
    ap.add_argument("--quiet", action="store_true")
    a = ap.parse_args()
    if a.resolve:
        resolve(a.world, a.quiet)
    if a.plan:
        plan(a.world)
    if a.viewer_json:
        viewer_json(a.world)
    if a.record_installed is not None:
        # An EMPTY string is meaningful and must not be confused with the flag being
        # absent: "the installer ran and nothing landed" is a real outcome, and it still
        # has to clear the rows for mods that are no longer installed.
        ids = []
        for tok in a.record_installed.split(","):
            tok = tok.strip()
            if tok.isdigit():
                ids.append(tok)
        record_installed(a.world, ids)
    if not (a.resolve or a.plan or a.viewer_json or a.record_installed is not None):
        ap.error("pick one of --resolve / --plan / --viewer-json / --record-installed")
    return 0


if __name__ == "__main__":
    sys.exit(main())
