#!/usr/bin/env python3
"""Decide, per world, whether a Valheim server update or a mod update is available.

Issue #87. This only ever RECORDS what it finds -- applying is updateApplier's job, and
keeping the two apart means a broken check can never restart anybody's server.

Two questions, answered very differently.

GAME: every world has its own steamcmd tree, so "is this world current" is a per-world
question. But the ANSWER is server-wide: there is one published buildid for app 896660.
So the buildid is fetched ONCE per run with `+app_info_print` and compared against each
world's own appmanifest_896660.acf. N worlds cost one steamcmd call, not N downloads.
The alternative -- running `app_update` and seeing whether anything moved -- would
download the whole game per world on every check.

MODS: world_mods.installed_version_id is the record of what is on disk, written by the
installer once the files are down. Comparing the version behind that id against the
catalogue's current version tells us what has moved. A pinned mod is skipped outright: a
pin means the operator chose that version, and auto-update must never quietly walk away
from it.

This deliberately does NOT read worlds.modsViewer, which is where it started. modsViewer is
the admin UI's display cache and its versions come from the live catalogue, so comparing it
against the catalogue compares a number with itself -- always equal, always "up to date".

Usage:
    updateChecker.py              check every running world, honouring the interval
    updateChecker.py --force      ignore the interval
    updateChecker.py --world NAME check one world, ignoring the interval
"""

import argparse
import os
import re
import subprocess
import sys

MYSQL = "/usr/bin/mysql"
DB = "phvalheim"
STEAMCMD = "/usr/games/steamcmd"
STEAM_HOME = "/opt/stateful/games/steam_home"
APPID = "896660"
WORLDS_ROOT = "/opt/stateful/games/valheim/worlds"


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


def one(query):
    r = rows(query)
    return r[0][0] if r and r[0] else ""


def q(v):
    if v is None or v == "":
        return "NULL"
    s = (str(v).replace("\\", "\\\\").replace("'", "\\'")
         .replace("\n", "\\n").replace("\r", "").replace("\x00", ""))
    return "'" + s + "'"


def log(msg):
    print(f"[updateChecker] {msg}", flush=True)


# --------------------------------------------------------------------------- game

BUILD_CACHE_MINUTES = 15


def cached_buildid(max_age_minutes=BUILD_CACHE_MINUTES):
    """The published buildid from cache, or "" if there is none recent enough.

    The published build is server-wide, not per-world, and fetching it costs about 31
    seconds -- almost entirely steamcmd starting up and logging into Steam, measured; the
    app_info refresh itself is free by comparison. Without this cache, checking five worlds
    meant five logins and clicking Check Now twice meant two.
    """
    if max_age_minutes <= 0:
        return ""
    row = one(f"SELECT IFNULL(publishedBuildid,'') FROM settings "
              f"WHERE publishedBuildidAt IS NOT NULL "
              f"AND publishedBuildidAt > DATE_SUB(NOW(), INTERVAL {int(max_age_minutes)} MINUTE) "
              f"LIMIT 1;")
    return row or ""


def store_buildid(buildid):
    if buildid:
        sql(f"UPDATE settings SET publishedBuildid={q(buildid)}, publishedBuildidAt=NOW();")


def available_buildid(max_age_minutes=BUILD_CACHE_MINUTES):
    cached = cached_buildid(max_age_minutes)
    if cached:
        log(f"published buildid {cached} (cached, under {max_age_minutes}m old)")
        return cached
    return fetch_buildid()


def fetch_buildid():
    """The published buildid for the public branch, fetched once for the whole server.

    app_info_print emits a nested VDF blob, and getting the right number out of it needs
    TWO anchors, not one.

    "buildid" appears once per branch -- public, default_old, default_pre1_0 and half a
    dozen frozen historical branches -- so the first match is not necessarily the live one.

    Worse, "public" appears SEVEN times in the real output: once per depot under that
    depot's "manifests" section (those blocks hold gid/size/download and no buildid at
    all), and once under "branches". Anchoring on "public" alone finds a depot manifest
    and reports no buildid, which is exactly what the first version of this did.

    So: locate "branches" first, then "public" inside it, then the buildid inside that.
    """
    # HOME must be set explicitly.
    #
    # This runs as the phvalheim user from cron and from the admin API, and that user's HOME
    # is not a directory it can write. steamcmd bootstraps itself into $HOME/.local and
    # $HOME/.steam, so without this it fails with a pile of "cannot create directory
    # '/opt/.local'" and never prints any app info at all. 0-functions.sh has always passed
    # HOME for exactly this reason; this call has to do the same.
    #
    # It cost a silently wrong answer in production: every world reported "up to date"
    # forever, including one running a build over five thousand revisions behind.
    env = dict(os.environ)
    env["HOME"] = STEAM_HOME

    try:
        r = subprocess.run(
            [STEAMCMD, "+login", "anonymous", "+app_info_update", "1",
             "+app_info_print", APPID, "+quit"],
            capture_output=True, text=True, timeout=300, env=env)
    except (subprocess.TimeoutExpired, FileNotFoundError) as e:
        log(f"steamcmd unavailable: {e}")
        return ""

    buildid = parse_public_buildid(r.stdout)
    store_buildid(buildid)
    return buildid


def parse_public_buildid(out):
    """Split out from the steamcmd call so it can be tested against captured output."""
    branches = re.search(r'"branches"\s*\{', out)
    if not branches:
        log("no branches section in app_info_print output")
        return ""

    # From the start of "branches", the first "public" block is the live branch.
    m = re.search(r'"public"\s*\{(.*?)\}', out[branches.end():], re.S)
    if m:
        b = re.search(r'"buildid"\s*"(\d+)"', m.group(1))
        if b:
            return b.group(1)

    log("could not parse a public buildid from app_info_print")
    return ""


def installed_buildid(world):
    """Read the world's own steamcmd manifest. Absent manifest = unknown, not 'outdated'."""
    path = os.path.join(WORLDS_ROOT, world, "game", "steamapps",
                        f"appmanifest_{APPID}.acf")
    try:
        with open(path, "r", errors="replace") as fh:
            m = re.search(r'"buildid"\s*"(\d+)"', fh.read())
            return m.group(1) if m else ""
    except OSError:
        return ""


# --------------------------------------------------------------------------- mods

def mod_updates(world):
    """How many UNPINNED mods have a newer version than the one this world has on disk.

    Returns (count, names, error). An `error` means the question could not be answered,
    which is NOT the same as zero and must never be rendered as "up to date".

    Reads world_mods.installed_version_id, which the installer writes after a mod's files
    are actually on disk (worldMods.py record_installed). It does NOT read
    worlds.modsViewer: that is a display cache whose versions come from the live catalogue,
    so comparing it against the catalogue compares a number with itself and answers "up to
    date" unconditionally. It only looked right because its two call sites happen to sit
    immediately after an install.

    mod_versions.id is immutable, so a catalogue resync can move latest_version without
    rewriting what we recorded -- which is the whole reason for storing the id rather than
    a version string.

    Three states per row, and all three are needed:
      installed_at NULL                     -- never recorded. UNKNOWN.
      installed_at set, version_id NULL     -- known not installed (a duplicate plugin that
                                               by_plugin() collapsed away). Not a gap.
      installed_at set, version_id set      -- comparable.
    """
    wid = one(f"SELECT id FROM worlds WHERE name={q(world)} LIMIT 1;")
    if not wid:
        return 0, [], ""

    # LEFT JOIN on mod_versions: the row survives even if the recorded version has since
    # been pruned from the catalogue. An INNER JOIN would drop it, and a dropped row is
    # indistinguishable from a world with fewer mods -- silently back to a false zero.
    picks = rows(
        f"SELECT m.name, "
        f"       IFNULL(wm.pin_version_id,''), "
        f"       IFNULL(wm.installed_version_id,''), "
        f"       IFNULL(iv.version,''), "
        f"       IFNULL(m.latest_version,''), "
        f"       IF(wm.installed_at IS NULL,'0','1') "
        f"FROM world_mods wm "
        f"JOIN mods m ON m.id = wm.mod_id "
        f"LEFT JOIN mod_versions iv ON iv.id = wm.installed_version_id "
        f"WHERE wm.world_id = {int(wid)};")

    if not picks:
        # A vanilla world legitimately has no mods. Honestly zero.
        return 0, [], ""

    stale = []
    unknown = 0
    comparable = 0

    for name, pin, inst_id, have, want, recorded in picks:
        if pin:
            # Pinned: the operator chose that version on purpose, and auto-update must
            # never quietly walk away from it. Not a gap in our knowledge either.
            continue
        if recorded != "1":
            unknown += 1
            continue
        if not inst_id:
            # Recorded, and recorded as not installed. Nothing to compare, nothing missing.
            continue
        comparable += 1
        if want and have and want != have:
            stale.append(f"{name} {have} -> {want}")

    if unknown:
        # Report the definite part of the answer even while some of it is missing: a mod we
        # CAN see is out of date is still out of date. The error text exists so the UI can
        # say "and N we cannot see" instead of implying the count is the whole story.
        # NOT named `one` -- that is this module's single-value SQL helper, and shadowing it
        # here made the very next call to it raise UnboundLocalError.
        singular = unknown == 1
        subject = "1 mod has" if singular else f"{unknown} mods have"
        they, were, them = ("it", "was", "it") if singular else ("they", "were", "them")
        return len(stale), stale, (
            f"{subject} no recorded installed version on this world, so {they} cannot be "
            f"compared against the catalogue. PhValheim records a version when it installs "
            f"a mod; {they} {were} installed before it started doing that. Rebuilding the "
            f"world's mods records {them} -- use Rebuild Mods, edit its mod list, or let "
            f"an update run.")

    return len(stale), stale, ""


# --------------------------------------------------------------------------- main

def due(interval_hours, world):
    """Has enough time passed since this world was last checked?"""
    if interval_hours <= 0:
        return True
    n = one(f"SELECT COUNT(*) FROM worlds WHERE name={q(world)} "
            f"AND update_checked_at IS NOT NULL "
            f"AND update_checked_at > DATE_SUB(NOW(), INTERVAL {interval_hours} HOUR);")
    return n != "1"


def main():
    ap = argparse.ArgumentParser()
    ap.add_argument("--world")
    ap.add_argument("--force", action="store_true")
    ap.add_argument("--refresh-build", action="store_true",
                    help="ignore the cached published build and ask Steam")
    args = ap.parse_args()

    interval = one("SELECT IFNULL(autoUpdateCheckIntervalHours,6) FROM settings LIMIT 1;")
    try:
        interval = int(interval)
    except (TypeError, ValueError):
        interval = 6

    if args.world:
        worlds = [args.world]
    else:
        # Running worlds only. A stopped world updates when it next starts, which is what
        # the engine already does -- reaching into one here would fight that path.
        worlds = [r[0] for r in rows("SELECT name FROM worlds WHERE mode='running';")]

    if not worlds:
        return 0

    todo = [w for w in worlds if args.force or args.world or due(interval, w)]
    if not todo:
        return 0

    # Mark every world in this run as checking BEFORE the slow part, so the UI can show a
    # spinner rather than an unexplained half-minute pause. Set first, cleared per world as
    # each finishes.
    names = ",".join(q(w) for w in todo)
    sql(f"UPDATE worlds SET update_check_state='checking' WHERE name IN ({names});")

    try:
        # One steamcmd call for the whole run, not one per world -- and reused from cache
        # when it is recent, which makes a repeat check effectively instant.
        avail = available_buildid(0 if args.refresh_build else BUILD_CACHE_MINUTES)
        if avail:
            log(f"published buildid for app {APPID}: {avail}")

        for world in todo:
            have = installed_buildid(world)

            # Unknown on either side means we cannot claim an update exists -- reporting one
            # would hand updateApplier a reason to stop a server on no evidence.
            #
            # But it equally means we cannot claim the world is CURRENT, and that half was
            # missing: update_available_game stayed 0 and the UI rendered a green "up to date"
            # over a world five thousand builds behind. The reason is now recorded so the UI can
            # say "could not check" and show why.
            error = ""
            if not avail:
                error = ("Could not read the published Valheim build from Steam. "
                         "The installed build is unknown to be current or not.")
                game = 0
            elif not have:
                error = (f"No Steam manifest found for this world "
                         f"(game/steamapps/appmanifest_{APPID}.acf), so its installed build "
                         f"could not be read.")
                game = 0
            else:
                game = 1 if have != avail else 0

            count, stale, mods_error = mod_updates(world)

            sql(f"UPDATE worlds SET "
                f"update_available_game={game}, "
                f"update_available_mods={count}, "
                f"installed_buildid={q(have)}, "
                f"update_check_error={q(error)}, "
            f"update_mods_error={q(mods_error)}, "
                f"update_checked_at=NOW() "
                f"WHERE name={q(world)};")

            if error:
                log(f"'{world}': {error}")

            # Clear a pending clock that no longer has anything to wait for -- the operator
            # may have updated by hand, or a mod may have been re-pinned.
            #
            # Guarded on `not error`: a failed check also produces game=0 and count=0, and
            # treating that as "nothing to do" would cancel a legitimate pending update every
            # time Steam was briefly unreachable.
            if not error and not mods_error and game == 0 and count == 0:
                sql(f"UPDATE worlds SET update_pending_since=NULL, "
                    f"update_state='idle' "
                    f"WHERE name={q(world)} AND update_state='pending';")
            else:
                # Start the max-wait clock on the first sighting, and only then.
                sql(f"UPDATE worlds SET update_pending_since=NOW() "
                    f"WHERE name={q(world)} AND update_pending_since IS NULL;")

            if game or count:
                bits = []
                if game:
                    bits.append(f"game {have} -> {avail}")
                if count:
                    bits.append(f"{count} mod(s): " + ", ".join(stale[:5]))
                log(f"'{world}': update available -- " + "; ".join(bits))

    finally:
        # Always clear, including on an exception. A world stuck on 'checking'
        # would spin in the UI forever with no way to retry.
        sql(f"UPDATE worlds SET update_check_state=NULL WHERE name IN ({names});")

    return 0


if __name__ == "__main__":
    sys.exit(main())
