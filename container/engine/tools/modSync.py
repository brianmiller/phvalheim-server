#!/usr/bin/env python3
"""PhValheim catalogue sync -- Thunderstore and Hexium, one code path.

  modSync.py [--source all|thunderstore|hexium] [--force] [--trigger cron|manual|boot]
             [--dry-run] [--json]

Replaces tsSyncLocalParseMultithreaded.sh / tsSyncRemoteParse.sh / tsPrune.sh.

WHY THIS IS NOT THE OLD SCRIPT
------------------------------
The old sync was slow for one reason: it ran `jq` per field and then TWO separate
`mysql` processes per mod (a SELECT to test existence, then an INSERT or UPDATE) against
a `tsmods` table that had no index except its primary key -- so every one of those
SELECTs was a full table scan. Parsing was never the cost: transforming Hexium's entire
catalogue in Python takes 0.017s.

This does three things differently.

1. ONE process, batched multi-row upserts through a single `mysql` pipe. Measured cold
   build of both catalogues, from empty, including all 91,701 historical versions: ~26s.
   (A server-side LOAD DATA INFILE variant benchmarked SLOWER at 32.9s and needed a
   `secure_file_priv` directory the cron user cannot write to, so it was dropped -- this
   path has no filesystem handoff at all and behaves the same on Unraid, K8s and Docker.)

2. CHANGE DETECTION, so the routine case does almost nothing. Thunderstore sends
   Last-Modified, so it gets a conditional If-Modified-Since and normally answers 304
   with no body. Hexium sends no validator of any kind, so its body is hashed and
   compared against the last successful run.

3. A CONTENT DIFF rather than a blind re-upsert. Re-upserting all 89,405 Thunderstore
   versions costs ~55s even when nothing changed, because every row becomes an UPDATE.
   Each row instead carries a content_hash; the sync reads the existing hashes in one
   query and writes only rows that are new or actually different. A real incremental
   sync touches a handful of rows and finishes in well under a second.

Deliberately dependency-free: the container has no pip and no Python MySQL driver, so
this uses urllib plus the `mysql` client over stdin.
"""

import argparse
import gzip
import hashlib
import json
import os
import subprocess
import sys
import time
import urllib.error
import urllib.request

DB = "phvalheim"
MYSQL = "/usr/bin/mysql"
LOGPREFIX = "[modsync]"

# Sources are identical in shape -- Hexium's /api/v1/package/ returns the same keys,
# the same nesting and the same newest-first versions[] ordering as Thunderstore's.
# It is one adapter with two URLs, which is why `source` is data and not code.
SOURCES = {
    "thunderstore": {
        "label": "Thunderstore",
        "url": "https://thunderstore.io/c/valheim/api/v1/package/",
        "key_setting": "thunderstoreApiKey",
        "enabled_setting": "thunderstoreEnabled",
        # Thunderstore sends Last-Modified; a conditional request usually gets a
        # bodiless 304, which is the cheapest possible sync.
        "conditional": True,
    },
    "hexium": {
        "label": "Hexium",
        "url": "https://valheim.hexium.gg/api/v1/package/",
        "key_setting": "hexiumApiKey",
        "enabled_setting": "hexiumEnabled",
        # Hexium sends neither ETag nor Last-Modified (verified 2026-09-12), so the only
        # way to know its catalogue is unchanged is to hash the body.
        "conditional": False,
    },
}

# Significant columns for the content hash, in a fixed order. Download counts and
# rating are deliberately EXCLUDED: they tick constantly and would make every mod look
# changed on every sync, defeating the whole diff. They are still written whenever the
# row is written for another reason.
MOD_HASH_FIELDS = ("full_name", "source_uuid", "package_url", "donation_link",
                   "date_created", "date_updated", "is_deprecated", "is_nsfw",
                   "is_pinned", "categories", "version_count", "latest_version")
VER_HASH_FIELDS = ("full_name", "source_uuid", "download_url", "icon_url", "website_url",
                   "description", "file_size", "is_active", "source_rank",
                   "date_created", "deps")


# The run currently being logged. A module global rather than a parameter threaded through
# every function, so that all the existing log() call sites keep working unchanged and
# helpers like resolve_deps() do not each need to know how to persist a line.
_CURRENT_RUN = None

# Detail lines naming individual mods are capped. A first sync adds 89,405 versions; writing
# a row per mod would produce a log nobody can read and a table larger than the catalogue.
DETAIL_CAP = 40

# How many recent runs keep their log, per source. The panel shows the current or last run,
# so a handful of history is enough to compare "this run vs the previous one".
LOG_KEEP_RUNS = 10


def log(msg, level="info", detail=False):
    """Print to the engine log, and record against the current run for the admin UI.

    `detail` marks the per-mod lines the UI hides behind a toggle -- they are the useful
    part when you want to know WHAT changed, and noise when you only want to know whether
    the sync worked.
    """
    print(f"{time.strftime('%a %b %d %H:%M:%S %Z %Y')} {LOGPREFIX} {msg}", flush=True)
    if _CURRENT_RUN is not None:
        _CURRENT_RUN.record(msg, level, detail)


# ---------------------------------------------------------------- database plumbing

def sql(statements, fetch=False):
    """Run SQL through the mysql client. `statements` may be one string or a list."""
    if isinstance(statements, (list, tuple)):
        statements = "\n".join(statements)
    cmd = [MYSQL, "-uroot", "--default-character-set=utf8mb4", "--database", DB]
    if fetch:
        cmd.append("--skip-column-names")
    r = subprocess.run(cmd, input=statements, capture_output=True, text=True)
    if r.returncode != 0:
        raise RuntimeError(f"mysql failed: {r.stderr.strip()[:1200]}")
    return r.stdout


def rows(query):
    """Run a SELECT and return a list of tab-split field lists."""
    out = sql(query, fetch=True)
    return [ln.split("\t") for ln in out.splitlines() if ln]


def scalar(query, default=None):
    r = rows(query)
    if not r or not r[0]:
        return default
    v = r[0][0]
    return default if v == "NULL" else v


def q(v):
    """Quote a Python value as a MySQL literal."""
    if v is None or v == "":
        return "NULL"
    s = str(v)
    s = (s.replace("\\", "\\\\").replace("'", "\\'")
          .replace("\n", "\\n").replace("\r", "").replace("\x00", ""))
    return "'" + s + "'"


def dt(v):
    """ISO-8601 from either feed -> MySQL DATETIME literal."""
    if not v:
        return "NULL"
    return "'" + str(v)[:19].replace("T", " ") + "'"


def chunks(seq, n):
    for i in range(0, len(seq), n):
        yield seq[i:i + n]


# ---------------------------------------------------------------- settings

def get_settings():
    cols = ["thunderstoreApiKey", "hexiumApiKey",
            "thunderstoreEnabled", "hexiumEnabled", "modSyncIntervalHours"]
    have = {r[0] for r in rows("DESCRIBE settings;")}
    use = [c for c in cols if c in have]
    if not use:
        return {}
    r = rows(f"SELECT {','.join(use)} FROM settings LIMIT 1;")
    if not r:
        return {}
    out = {}
    for k, v in zip(use, r[0]):
        out[k] = "" if v == "NULL" else v
    return out


# ---------------------------------------------------------------- run bookkeeping

class Run:
    """One row in mod_sync_runs, updated in place so the admin UI can watch it."""

    def __init__(self, source, trigger, dry=False):
        self.source = source
        self.dry = dry
        self.t0 = time.time()
        self.id = None
        self._buf = []              # pending mod_sync_log rows
        self._flushed = time.time()
        self._phase = "starting"
        self._phase_t0 = time.time()
        self.timings = {}           # phase -> seconds
        self._detail_count = {}     # per-category cap tracking
        if dry:
            return
        # The INSERT and the LAST_INSERT_ID() must be in ONE mysql invocation. Every
        # sql() call here spawns a separate client, and LAST_INSERT_ID() is
        # per-connection -- asking for it in a second process returns 0. That silently
        # left self.id unset, so every phase() and finish() update targeted nothing:
        # runs sat at status='running' with all counts zero, the next run reaped them as
        # stale, and because no run ever reached 'ok' the change detection had no
        # previous hash to compare against and re-read both catalogues every time.
        out = sql(f"INSERT INTO mod_sync_runs (source,status,phase,trigger_kind,pid) "
                  f"VALUES ({q(source)},'running','starting',{q(trigger)},{os.getpid()});"
                  f"SELECT LAST_INSERT_ID();", fetch=True)
        got = [ln for ln in out.splitlines() if ln.strip()]
        self.id = got[-1].strip() if got else None
        if not self.id or self.id == "0":
            raise RuntimeError("could not obtain a mod_sync_runs id")

    # ---- live log ------------------------------------------------------------------
    #
    # Buffered, but flushed on a TIME threshold as well as a size one. Size alone would
    # hold the last few lines of a phase until the next phase happened to fill the
    # buffer, so a slow step would look frozen -- which is exactly what a live log is
    # supposed to rule out.
    FLUSH_LINES = 12
    FLUSH_SECONDS = 0.7

    def record(self, msg, level="info", detail=False):
        if self.dry or not self.id:
            return
        self._buf.append((level, self._phase, str(msg)[:500], 1 if detail else 0))
        if (len(self._buf) >= self.FLUSH_LINES
                or (time.time() - self._flushed) >= self.FLUSH_SECONDS):
            self.flush()

    def flush(self):
        if self.dry or not self.id or not self._buf:
            return
        rows = ",".join(
            f"({self.id},{q(self.source)},{q(lvl)},{q(ph)},{q(msg)},{det})"
            for lvl, ph, msg, det in self._buf)
        self._buf = []
        self._flushed = time.time()
        try:
            sql(f"INSERT INTO mod_sync_log (run_id,source,level,phase,message,is_detail) "
                f"VALUES {rows};")
        except RuntimeError as e:
            # A log write must never take the sync down with it -- the catalogue data is
            # the point and this is commentary about it.
            print(f"{LOGPREFIX} WARNING: could not persist log lines: {e}", flush=True)

    def detail(self, category, msg):
        """A per-mod line, capped per category so a first sync cannot emit 89,405 rows."""
        n = self._detail_count.get(category, 0)
        self._detail_count[category] = n + 1
        if n < DETAIL_CAP:
            log(f"  {msg}", detail=True)
        elif n == DETAIL_CAP:
            log(f"  ... further {category} lines suppressed "
                f"(cap {DETAIL_CAP}; see the counts above for the total)", detail=True)

    def phase(self, name, pct=None, **counts):
        if self.dry or not self.id:
            return
        # Close out the previous phase's timing before switching.
        if name != self._phase:
            self.timings[self._phase] = round(
                self.timings.get(self._phase, 0) + (time.time() - self._phase_t0), 3)
            self._phase = name
            self._phase_t0 = time.time()
            self.flush()
        sets = [f"phase={q(name)}"]
        if pct is not None:
            sets.append(f"phase_pct={int(pct)}")
        for k, v in counts.items():
            sets.append(f"{k}={int(v)}")
        sql(f"UPDATE mod_sync_runs SET {','.join(sets)} WHERE id={self.id};")

    def finish(self, status, error=None, **counts):
        ms = int((time.time() - self.t0) * 1000)
        if self.dry or not self.id:
            return

        # Close the final phase so its time is not silently dropped.
        self.timings[self._phase] = round(
            self.timings.get(self._phase, 0) + (time.time() - self._phase_t0), 3)

        # Where the time actually went. Printed as one line so a slow sync can be
        # diagnosed from the log without subtracting timestamps by hand.
        breakdown = ", ".join(f"{k} {v:.2f}s" for k, v in self.timings.items() if v >= 0.01)
        if breakdown:
            # The display label, not the source key -- every other line in this log uses the
            # label, and mixing the two reads like a different subsystem talking.
            label = SOURCES.get(self.source, {}).get("label", self.source)
            log(f"{label}: phase timings -- {breakdown}")
        self.flush()

        sets = [f"status={q(status)}", "phase='done'", "phase_pct=100",
                "finished=NOW()", f"duration_ms={ms}",
                f"phase_timings={q(json.dumps(self.timings))}"]
        if error:
            sets.append(f"error={q(str(error)[:2000])}")
        for k, v in counts.items():
            sets.append(f"{k}={int(v)}")
        sql(f"UPDATE mod_sync_runs SET {','.join(sets)} WHERE id={self.id};")
        self.prune_logs()

    def prune_logs(self):
        """Keep the log for the most recent runs of THIS source only.

        Pruned by run rather than by row count or age: the useful unit is "the last few
        syncs", and a row cap would truncate one big cold-build run into something
        misleading while leaving dozens of trivial 2-second runs intact.
        """
        try:
            keep = rows(
                f"SELECT id FROM mod_sync_runs WHERE source={q(self.source)} "
                f"ORDER BY id DESC LIMIT {LOG_KEEP_RUNS};")
            ids = [r[0] for r in keep if r and r[0]]
            if not ids:
                return
            sql(f"DELETE FROM mod_sync_log WHERE source={q(self.source)} "
                f"AND run_id NOT IN ({','.join(ids)});")
        except RuntimeError as e:
            print(f"{LOGPREFIX} WARNING: could not prune old log rows: {e}", flush=True)


def last_good(source):
    """The previous successful run, for change detection."""
    r = rows(f"SELECT body_sha256, http_last_mod FROM mod_sync_runs "
             f"WHERE source={q(source)} AND status IN ('ok','unchanged') "
             f"ORDER BY id DESC LIMIT 1;")
    if not r:
        return None, None
    sha = r[0][0] if r[0][0] != "NULL" else None
    lm = r[0][1] if len(r[0]) > 1 and r[0][1] != "NULL" else None
    return sha, lm


def already_running(source):
    """True if another sync of this source is genuinely alive.

    A row left at status='running' by a killed process (container restart mid-sync,
    the admin UI's stop button, an OOM) would otherwise block every future sync
    forever, so the recorded pid is checked and stale rows are reaped.
    """
    for rid, pid in rows(f"SELECT id, COALESCE(pid,0) FROM mod_sync_runs "
                         f"WHERE source={q(source)} AND status='running';"):
        alive = False
        try:
            p = int(pid)
            if p > 0:
                os.kill(p, 0)
                alive = p != os.getpid()
        except (ValueError, ProcessLookupError):
            alive = False
        except PermissionError:
            alive = True
        if alive:
            return True
        sql(f"UPDATE mod_sync_runs SET status='stale', phase='done', "
            f"error='process disappeared; reaped by a later run' WHERE id={rid};")
        log(f"reaped stale running row #{rid} for {source}")
    return False


# ---------------------------------------------------------------- fetch

def fetch(source, cfg, api_key, prev_sha, prev_lm, force):
    """Return (payload_bytes|None, status, last_modified, unchanged)."""
    req = urllib.request.Request(cfg["url"])
    req.add_header("Accept", "application/json")
    req.add_header("Accept-Encoding", "gzip")
    req.add_header("User-Agent", "PhValheim/2.43 catalogue-sync")
    if api_key:
        req.add_header("Authorization", f"Bearer {api_key}")
    if cfg["conditional"] and prev_lm and not force:
        req.add_header("If-Modified-Since", prev_lm)

    try:
        with urllib.request.urlopen(req, timeout=300) as resp:
            status = resp.status
            lm = resp.headers.get("Last-Modified")
            raw = resp.read()
            if resp.headers.get("Content-Encoding") == "gzip":
                raw = gzip.decompress(raw)
    except urllib.error.HTTPError as e:
        if e.code == 304:
            return None, 304, prev_lm, True
        raise

    sha = hashlib.sha256(raw).hexdigest()
    # The body hash is the only validator Hexium gives us, and it also catches the case
    # where Thunderstore's Last-Modified moves but the content is identical.
    if prev_sha and sha == prev_sha and not force:
        return None, status, lm, True
    return raw, status, lm, False


# ---------------------------------------------------------------- transform

def api_key_present(settings, cfg):
    return bool((settings or {}).get(cfg["key_setting"], ""))


def hash_row(values):
    h = hashlib.md5()
    for v in values:
        h.update((b"\x1f" if v is None else str(v).encode("utf-8", "replace")))
        h.update(b"\x1e")
    return h.hexdigest()


def build(source, pkgs):
    """Flatten a feed into (mods, versions) dicts keyed for diffing."""
    mods, versions = {}, {}
    for p in pkgs:
        owner, name = p.get("owner"), p.get("name")
        if not owner or not name:
            continue
        vers = p.get("versions") or []
        # Feeds are not guaranteed unique: Hexium currently ships Smoothbrain/Cooking
        # twice. Last one wins rather than exploding on the unique key.
        m = {
            "owner": owner,
            "name": name,
            "full_name": p.get("full_name") or f"{owner}-{name}",
            "source_uuid": p.get("uuid4"),
            "package_url": p.get("package_url"),
            "donation_link": p.get("donation_link"),
            "date_created": (str(p.get("date_created") or "")[:19].replace("T", " ")) or None,
            "date_updated": (str(p.get("date_updated") or "")[:19].replace("T", " ")) or None,
            "rating_score": int(p.get("rating_score") or 0),
            "downloads": sum(int(v.get("downloads") or 0) for v in vers),
            "is_deprecated": 1 if p.get("is_deprecated") else 0,
            "is_nsfw": 1 if p.get("has_nsfw_content") else 0,
            "is_pinned": 1 if p.get("is_pinned") else 0,
            "categories": json.dumps(p.get("categories") or [], sort_keys=True),
            "version_count": len(vers),
            "latest_version": vers[0].get("version_number") if vers else None,
        }
        m["content_hash"] = hash_row([m[f] for f in MOD_HASH_FIELDS])
        mods[(owner, name)] = m

        for rank, v in enumerate(vers):
            ver = v.get("version_number")
            if not ver:
                continue
            d = {
                "owner": owner,
                "name": name,
                "version": ver,
                "full_name": v.get("full_name") or f"{owner}-{name}-{ver}",
                "source_uuid": v.get("uuid4"),
                "download_url": v.get("download_url") or "",
                "icon_url": v.get("icon"),
                "website_url": (v.get("website_url") or None),
                "description": (v.get("description") or "")[:400] or None,
                "file_size": int(v.get("file_size") or 0),
                "downloads": int(v.get("downloads") or 0),
                "is_active": 1 if v.get("is_active", True) else 0,
                # The source's own ordering (0 = newest). Trusted over parsing the
                # version string, which is not reliably semver: real published
                # versions include 2.0.6-beta.1.
                "source_rank": rank,
                "date_created": (str(v.get("date_created") or "")[:19].replace("T", " ")) or None,
                "deps": json.dumps(v.get("dependencies") or []),
            }
            d["content_hash"] = hash_row([d[f] for f in VER_HASH_FIELDS])
            versions[(owner, name, ver)] = d

    # A version row with no download_url cannot ever be installed. Drop it here rather
    # than let a world fail at start time with a blank URL.
    dropped = [k for k, v in versions.items() if not v["download_url"]]
    for k in dropped:
        del versions[k]
    if dropped:
        log(f"{source}: skipped {len(dropped)} version(s) with no download_url")
    return mods, versions


# ---------------------------------------------------------------- upserts

MOD_COLS = ("source,owner,name,full_name,source_uuid,package_url,donation_link,"
            "date_created,date_updated,rating_score,downloads,is_deprecated,is_nsfw,"
            "is_pinned,categories,version_count,latest_version,content_hash,last_seen")
MOD_UPD = ",".join(f"{c}=VALUES({c})" for c in (
    "full_name", "source_uuid", "package_url", "donation_link", "date_created",
    "date_updated", "rating_score", "downloads", "is_deprecated", "is_nsfw",
    "is_pinned", "categories", "version_count", "latest_version", "content_hash",
    "last_seen"))

VER_COLS = ("mod_id,version,full_name,source_uuid,download_url,icon_url,website_url,"
            "description,file_size,downloads,is_active,source_rank,date_created,deps,"
            "content_hash,last_seen")
VER_UPD = ",".join(f"{c}=VALUES({c})" for c in (
    "full_name", "source_uuid", "download_url", "icon_url", "website_url",
    "description", "file_size", "downloads", "is_active", "source_rank",
    "date_created", "deps", "content_hash", "last_seen"))

BATCH = 2000


def upsert_mods(source, mods):
    tup = []
    for m in mods:
        tup.append("(" + ",".join([
            q(source), q(m["owner"]), q(m["name"]), q(m["full_name"]),
            q(m["source_uuid"]), q(m["package_url"]), q(m["donation_link"]),
            dt(m["date_created"]), dt(m["date_updated"]),
            str(m["rating_score"]), str(m["downloads"]),
            str(m["is_deprecated"]), str(m["is_nsfw"]), str(m["is_pinned"]),
            q(m["categories"]), str(m["version_count"]), q(m["latest_version"]),
            q(m["content_hash"]), "NOW()",
        ]) + ")")
    for c in chunks(tup, BATCH):
        sql(f"INSERT INTO mods ({MOD_COLS}) VALUES\n" + ",\n".join(c)
            + f"\nON DUPLICATE KEY UPDATE {MOD_UPD};")


def upsert_versions(versions, idmap):
    tup = []
    for v in versions:
        mid = idmap.get((v["owner"], v["name"]))
        if not mid:
            continue
        tup.append("(" + ",".join([
            mid, q(v["version"]), q(v["full_name"]), q(v["source_uuid"]),
            q(v["download_url"]), q(v["icon_url"]), q(v["website_url"]),
            q(v["description"]), str(v["file_size"]), str(v["downloads"]),
            str(v["is_active"]), str(v["source_rank"]), dt(v["date_created"]),
            q(v["deps"]), q(v["content_hash"]), "NOW()",
        ]) + ")")
    for c in chunks(tup, BATCH):
        sql(f"INSERT INTO mod_versions ({VER_COLS}) VALUES\n" + ",\n".join(c)
            + f"\nON DUPLICATE KEY UPDATE {VER_UPD};")


# ---------------------------------------------------------------- dependency resolution

def resolve_deps(run, scope_version_ids=None, catalogue_moved=False):
    """Resolve mod_deps for the versions a world could install.

    `scope_version_ids` limits the work to versions that actually changed this run;
    None means rebuild everything (first run, or --force).

    Resolving all of it costs ~35s because it reads every mod and every version to build
    the lookup maps, and a full rebuild ran on EVERY source sync -- twice per cron tick,
    even when nothing changed. That was the single largest remaining cost in the routine
    case, so the normal path now re-resolves only what moved.

    `catalogue_moved` additionally re-tries edges that are currently unresolved: a
    dependency that named a package we did not have becomes resolvable the moment that
    package appears, and nothing about the DEPENDING version changes when that happens.
    Without this, a newly-added mod would never retro-fix the graph that wanted it.

    A dependency string is "owner-name-version" and ALL THREE parts may contain
    hyphens -- owners LVH-IT and sinai-dev, versions like 2.0.6-beta.1. Splitting on
    the last hyphen misresolves; matching the LONGEST known "owner-name" prefix
    resolves 1371 of Hexium's 1372 distinct strings.

    Resolution is deliberately CROSS-SOURCE: 576 of those 1372 Hexium dependencies name
    a package that exists only on Thunderstore, so a Hexium mod's graph reaches into the
    Thunderstore rows. Where a package exists in both, the depending mod's own source
    wins, so a Hexium world prefers Hexium's copy.
    """
    run.phase("resolving deps", 80)

    # full_name -> {source: mod_id}. 11k rows, always cheap.
    by_name = {}
    for mid, source, full_name in rows("SELECT id, source, full_name FROM mods;"):
        by_name.setdefault(full_name, {})[source] = mid

    # Only versions that are actually reachable: the newest of each mod, plus anything a
    # world has pinned. Resolving all 91,701 versions would mean ~3.2 MILLION edges for
    # rows nothing can select.
    reachable = ("(v.source_rank = 0 OR v.id IN "
                 "(SELECT pin_version_id FROM world_mods WHERE pin_version_id IS NOT NULL))")

    if scope_version_ids is None:
        targets = rows(
            "SELECT v.id, v.mod_id, m.source, v.deps FROM mod_versions v "
            "JOIN mods m ON m.id = v.mod_id "
            f"WHERE {reachable};")
        full_rebuild = True
    else:
        ids = sorted({str(i) for i in scope_version_ids})
        extra = []
        if catalogue_moved:
            extra = [r[0] for r in rows(
                "SELECT DISTINCT version_id FROM mod_deps WHERE dep_mod_id IS NULL;")]
        want = sorted(set(ids) | set(extra))
        if not want:
            log("dependency graph unchanged, nothing to resolve")
            return 0, int(scalar("SELECT COUNT(*) FROM mod_deps WHERE dep_mod_id IS NULL;", 0))
        targets = []
        for c in chunks(want, 1000):
            targets += rows(
                "SELECT v.id, v.mod_id, m.source, v.deps FROM mod_versions v "
                "JOIN mods m ON m.id = v.mod_id "
                f"WHERE v.id IN ({','.join(c)}) AND {reachable};")
        full_rebuild = False

    # (mod_id, version) -> version_id, to pin a dependency to the exact version it names.
    # Loading all 91,701 is only worth it for a full rebuild; an incremental run asks for
    # just the pairs its own dep strings mention.
    ver_id = {}
    if full_rebuild:
        for vid, mid, ver in rows("SELECT id, mod_id, version FROM mod_versions;"):
            ver_id[(mid, ver)] = vid

    resolved = unresolved = 0
    edges = []          # (version_id, dep_string, dep_mod_id|None, dep_version|None)
    touched = set()
    for vid, mid, source, deps_json in targets:
        touched.add(vid)
        if not deps_json or deps_json == "NULL":
            continue
        try:
            deps = json.loads(deps_json)
        except (ValueError, TypeError):
            continue
        for dep in deps:
            # LONGEST-prefix match, not a split. A dep string is
            # "owner-name-version" and all three parts may contain hyphens: owners
            # LVH-IT and sinai-dev, versions like 2.0.6-beta.1. Splitting on the last
            # hyphen misresolves; this resolves 1371 of Hexium's 1372.
            base = None
            for i, ch in enumerate(dep):
                if ch == "-" and dep[:i] in by_name:
                    if base is None or i > len(base):
                        base = dep[:i]
            if base is None:
                unresolved += 1
                edges.append((vid, dep[:220], None, None))
                continue
            dep_version = dep[len(base) + 1:]
            owners = by_name[base]
            # Where a package exists on both, the depending mod's own source wins, so a
            # Hexium mod prefers Hexium's copy of its dependency. Falling back to any
            # source is what makes the 576 Hexium deps that exist ONLY on Thunderstore
            # resolve at all.
            dep_mod = owners.get(source) or next(iter(owners.values()))
            resolved += 1
            edges.append((vid, dep[:220], dep_mod, dep_version))

    # Fill in dep_version_id. A full rebuild has the whole map in memory already; an
    # incremental run asks only for the pairs its own dep strings actually named.
    if not full_rebuild:
        need = {(dm, dv) for _, _, dm, dv in edges if dm and dv}
        by_mod = {}
        for dm, dv in need:
            by_mod.setdefault(dm, set()).add(dv)
        mids = sorted(by_mod)
        for c in chunks(mids, 500):
            vers = set()
            for m in c:
                vers |= by_mod[m]
            for vc in chunks(sorted(vers), 500):
                for vid2, mid2, ver2 in rows(
                        "SELECT id, mod_id, version FROM mod_versions WHERE mod_id IN ("
                        + ",".join(c) + ") AND version IN ("
                        + ",".join(q(x) for x in vc) + ");"):
                    ver_id[(mid2, ver2)] = vid2

    tup = []
    for vid, dep, dm, dv in edges:
        dvid = ver_id.get((dm, dv)) if dm and dv else None
        tup.append(f"({vid},{q(dep)},{dm if dm else 'NULL'},{q(dv)},"
                   f"{dvid if dvid else 'NULL'})")

    # Scoped delete, so an incremental run does not wipe the edges of versions it never
    # looked at. A version whose deps became empty must still lose its old rows, which is
    # why the delete is driven by `touched` rather than by what we are about to insert.
    if full_rebuild:
        sql("DELETE FROM mod_deps;")
    elif touched:
        for c in chunks(sorted(str(t) for t in touched), 1000):
            sql("DELETE FROM mod_deps WHERE version_id IN (" + ",".join(c) + ");")

    for c in chunks(tup, BATCH):
        sql("INSERT INTO mod_deps (version_id,dep_string,dep_mod_id,dep_version,dep_version_id) "
            "VALUES " + ",".join(c) +
            " ON DUPLICATE KEY UPDATE dep_mod_id=VALUES(dep_mod_id),"
            "dep_version=VALUES(dep_version),dep_version_id=VALUES(dep_version_id);")

    # Worded as CATALOGUE-WIDE on purpose. The dependency graph is shared and resolved across
    # sources, so these totals are not "this source's" numbers -- and because this runs inside
    # each source's sync, the same figure appears in both providers' logs. Saying "all
    # catalogues" stops that reading as Hexium alone having 268,885 edges.
    scope = "full rebuild" if full_rebuild else f"{len(touched)} changed version(s)"
    log(f"dependency graph ({scope}, all catalogues): "
        f"{resolved} edge(s) resolved, {unresolved} unresolved")

    # An unresolved dependency means a mod will install but not work, so name them. They are
    # the single most actionable thing in the whole log.
    if unresolved and run is not None:
        seen = set()
        for _, dep, dm, _dv in edges:
            if dm is None and dep not in seen:
                seen.add(dep)
                run.detail("unresolved dependency",
                           f"! {dep} is named as a dependency but is in no enabled catalogue")
        log(f"dependency graph: {len(seen)} distinct missing package(s) across all catalogues; "
            f"mods needing them will install but may not work",
            level="warn")
    return resolved, unresolved


# ---------------------------------------------------------------- one source

def sync_source(source, settings, trigger, force, dry):
    cfg = SOURCES[source]

    if settings.get(cfg["enabled_setting"], "1") in ("0", 0, "", None):
        log(f"{cfg['label']}: disabled in settings, skipping")
        return "disabled"

    if not dry and already_running(source):
        log(f"{cfg['label']}: a sync is already running, skipping")
        return "busy"

    # The operator's configured interval is enforced HERE, not in cron. cron fires hourly
    # and this decides whether enough time has passed, because the interval lives in the
    # settings table and a crontab cannot read it -- offering the setting while cron
    # ignored it would make it a lie.
    #
    # A manual or boot run always proceeds: pressing Sync must do something visible.
    if trigger == "cron" and not force:
        try:
            interval = int(settings.get("modSyncIntervalHours") or 6)
        except (TypeError, ValueError):
            interval = 6
        if interval > 0:
            due = scalar(
                f"SELECT IF(MAX(started) IS NULL OR "
                f"          MAX(started) < NOW() - INTERVAL {interval} HOUR, 1, 0) "
                f"FROM mod_sync_runs WHERE source={q(source)} "
                f"  AND status IN ('ok','unchanged')", "1")
            if str(due) != "1":
                log(f"{cfg['label']}: synced less than {interval}h ago, skipping")
                return "not-due"

    prev_sha, prev_lm = last_good(source)
    run = Run(source, trigger, dry)

    # From here on, every log() line is also recorded against this run so the admin UI can
    # stream this catalogue's own detail. Reset in the finally block below -- leaving it set
    # would attribute the NEXT source's lines to this run.
    global _CURRENT_RUN
    _CURRENT_RUN = run

    log(f"{cfg['label']}: sync starting (trigger={trigger}, force={force})")
    log(f"{cfg['label']}: endpoint {cfg['url']}")
    log(f"{cfg['label']}: change detection -- "
        + ("conditional request (Last-Modified)" if cfg["conditional"]
           else "response body hash (this source sends no validator)")
        + (f"; previous hash {prev_sha[:12]}..." if prev_sha else "; no previous run to compare")
        + (f"; last modified {prev_lm}" if prev_lm else ""))
    if api_key_present(settings, cfg):
        log(f"{cfg['label']}: sending the configured API key as a bearer token")

    try:
        run.phase("fetching", 5)
        t = time.time()
        raw, status, lm, unchanged = fetch(
            source, cfg, settings.get(cfg["key_setting"], ""), prev_sha, prev_lm, force)
        fetch_s = time.time() - t

        if unchanged:
            log(f"{cfg['label']}: catalogue unchanged (HTTP {status}, {fetch_s:.2f}s) -- nothing to do")
            run.finish("unchanged", http_status=status, bytes_fetched=0)
            if not dry and lm:
                sql(f"UPDATE mod_sync_runs SET http_last_mod={q(lm)}, "
                    f"body_sha256={q(prev_sha)} WHERE id={run.id};")
            return "unchanged"

        sha = hashlib.sha256(raw).hexdigest()
        log(f"{cfg['label']}: fetched {len(raw)/1e6:.1f} MB in {fetch_s:.2f}s (HTTP {status})")

        run.phase("parsing", 20)
        pkgs = json.loads(raw)
        feed_mods, feed_vers = build(source, pkgs)
        log(f"{cfg['label']}: {len(feed_mods)} packages, {len(feed_vers)} versions in feed")
        run.phase("parsing", 30, pkgs_seen=len(feed_mods), vers_seen=len(feed_vers))

        if dry:
            log(f"{cfg['label']}: --dry-run, stopping before any write")
            return "dry"

        # ---- diff mods -------------------------------------------------------------
        run.phase("comparing", 35)
        have_mods = {}
        for mid, owner, name, chash in rows(
                f"SELECT id, owner, name, COALESCE(content_hash,'') FROM mods "
                f"WHERE source={q(source)};"):
            have_mods[(owner, name)] = (mid, chash)

        new_m = [m for k, m in feed_mods.items() if k not in have_mods]
        chg_m = [m for k, m in feed_mods.items()
                 if k in have_mods and have_mods[k][1] != m["content_hash"]]
        gone_m = [v[0] for k, v in have_mods.items() if k not in feed_mods]

        if new_m or chg_m:
            run.phase("writing mods", 45)
            log(f"{cfg['label']}: writing {len(new_m) + len(chg_m)} mod row(s) "
                f"in batches of {BATCH}")
            upsert_mods(source, new_m + chg_m)
        # Counted here, but NOT reported as removed: the keep/drop decision happens in the
        # prune below, and a delisted mod a world still selects is kept. Saying "-1" here
        # and "keeping 1" there made the log contradict itself.
        log(f"{cfg['label']}: mods +{len(new_m)} ~{len(chg_m)}"
            + (f", {len(gone_m)} delisted by the source" if gone_m else ""))

        # WHICH mods moved. This is the part an operator actually wants from a sync log --
        # "something changed" is what the counts already say.
        for m in new_m:
            run.detail("new mod", f"+ {m['owner']}/{m['name']} "
                                  f"({m['latest_version'] or 'no version'}, "
                                  f"{m['version_count']} version(s))")
        for m in chg_m:
            run.detail("updated mod", f"~ {m['owner']}/{m['name']} "
                                      f"-> {m['latest_version'] or 'no version'}")
        if gone_m:
            for mid in gone_m[:DETAIL_CAP]:
                nm = rows(f"SELECT owner, name FROM mods WHERE id={mid};")
                if nm:
                    run.detail("delisted mod",
                               f"- {nm[0][0]}/{nm[0][1]} is no longer offered by "
                               f"{cfg['label']} (kept if a world still selects it)")

        # id map after the insert, so new mods have ids
        idmap = {}
        for mid, owner, name in rows(
                f"SELECT id, owner, name FROM mods WHERE source={q(source)};"):
            idmap[(owner, name)] = mid

        # ---- diff versions ---------------------------------------------------------
        run.phase("comparing versions", 55,
                  mods_added=len(new_m), mods_updated=len(chg_m))
        have_vers = {}
        for vid, owner, name, ver, chash in rows(
                f"SELECT v.id, m.owner, m.name, v.version, COALESCE(v.content_hash,'') "
                f"FROM mod_versions v JOIN mods m ON m.id = v.mod_id "
                f"WHERE m.source={q(source)};"):
            have_vers[(owner, name, ver)] = (vid, chash)

        new_v = [v for k, v in feed_vers.items() if k not in have_vers]
        chg_v = [v for k, v in feed_vers.items()
                 if k in have_vers and have_vers[k][1] != v["content_hash"]]
        gone_v = [val[0] for k, val in have_vers.items() if k not in feed_vers]

        if new_v or chg_v:
            run.phase("writing versions", 65)
            log(f"{cfg['label']}: writing {len(new_v) + len(chg_v)} version row(s) "
                f"in batches of {BATCH}")
            upsert_versions(new_v + chg_v, idmap)
        # Same as the mods line above: delisted, not yet removed. The prune decides.
        log(f"{cfg['label']}: versions +{len(new_v)} ~{len(chg_v)}"
            + (f", {len(gone_v)} delisted by the source" if gone_v else ""))

        # New RELEASES are the interesting ones: a new version of a mod a world tracks is
        # what it will pick up at its next update.
        for v in sorted(new_v, key=lambda x: x["source_rank"]):
            if v["source_rank"] == 0:
                run.detail("new release",
                           f"+ {v['owner']}/{v['name']} {v['version']} "
                           f"({v['file_size'] / 1048576:.1f} MB, released {v['date_created'] or '?'})")
        backfill = len([v for v in new_v if v["source_rank"] != 0])
        if backfill:
            log(f"{cfg['label']}: {backfill} of those are older versions being backfilled "
                f"into the history, not new releases")

        # ---- prune -----------------------------------------------------------------
        #
        # Pruned by KEY DIFF against the feed, not by a last_seen sweep: stamping
        # last_seen on all 89,405 rows just to prove they still exist is the ~55s
        # write the content diff exists to avoid.
        #
        # A version is kept when a world still depends on it, for two separate reasons:
        #
        #   1. it is PINNED -- deleting it would silently un-pin that world and move it
        #      onto a different version at its next start; and
        #   2. its MOD is still selected -- a mod row with no versions left is
        #      uninstallable, so pruning the last version of a mod we are deliberately
        #      keeping produces exactly the broken state the keep was meant to prevent.
        #
        # (2) is not hypothetical: with only the pin check, a delisted mod a world had
        # selected kept its `mods` row and lost its only `mod_versions` row, and the world
        # then reported "no installable version" for a mod the sync had just promised to
        # preserve.
        if gone_v:
            run.phase("pruning", 72)
            keep = set()
            for c in chunks(gone_v, 1000):
                inlist = ",".join(c)
                for r in rows("SELECT DISTINCT pin_version_id FROM world_mods "
                              "WHERE pin_version_id IN (" + inlist + ");"):
                    if r and r[0] not in ("NULL", ""):
                        keep.add(r[0])
                for r in rows("SELECT DISTINCT v.id FROM mod_versions v "
                              "JOIN world_mods wm ON wm.mod_id = v.mod_id "
                              "WHERE v.id IN (" + inlist + ");"):
                    if r and r[0]:
                        keep.add(r[0])
            drop = [v for v in gone_v if v not in keep]
            if keep:
                log(f"{cfg['label']}: keeping {len(keep)} delisted version(s) a world still needs")
                for vid in list(keep)[:DETAIL_CAP]:
                    d = rows(f"SELECT m.owner, m.name, v.version FROM mod_versions v "
                             f"JOIN mods m ON m.id = v.mod_id WHERE v.id={vid};")
                    if d:
                        run.detail("kept version",
                                   f"= {d[0][0]}/{d[0][1]} {d[0][2]} kept: a world pins it "
                                   f"or still selects the mod")
            for c in chunks(drop, 1000):
                sql("DELETE FROM mod_deps WHERE version_id IN (" + ",".join(c) + ");")
                sql("DELETE FROM mod_versions WHERE id IN (" + ",".join(c) + ");")
            gone_v = drop

        if gone_m:
            # A mod a world still selects is kept, for the same reason: dropping it
            # would make the selection unresolvable and the world would start with a
            # mod missing and nothing explaining why.
            keep_m = set()
            for c in chunks(gone_m, 1000):
                for r in rows("SELECT DISTINCT mod_id FROM world_mods "
                              "WHERE mod_id IN (" + ",".join(c) + ");"):
                    keep_m.add(r[0])
            drop_m = [m for m in gone_m if m not in keep_m]
            if keep_m:
                log(f"{cfg['label']}: keeping {len(keep_m)} delisted mod(s) still selected by a world")
            for c in chunks(drop_m, 1000):
                sql("DELETE FROM mod_versions WHERE mod_id IN (" + ",".join(c) + ");")
                sql("DELETE FROM mods WHERE id IN (" + ",".join(c) + ");")
            gone_m = drop_m

        # The log's last word on removals, so it agrees with the mods_removed /
        # versions_removed the run records and the panel shows.
        log(f"{cfg['label']}: removed {len(gone_m)} mod(s) and {len(gone_v)} version(s)")

        # ---- deps ------------------------------------------------------------------
        #
        # Only the newest version of a mod (and anything a world pinned) can be
        # installed, so only those need edges. Scope the rebuild to the versions that
        # actually moved; a full rebuild is reserved for the first run, --force, and an
        # empty mod_deps table.
        have_edges = int(scalar("SELECT COUNT(*) FROM mod_deps;", 0) or 0)
        catalogue_moved = bool(new_m or gone_m)
        if force or not have_edges:
            dres, dunres = resolve_deps(run, None, catalogue_moved)
        else:
            changed_latest = [v for v in (new_v + chg_v) if v["source_rank"] == 0]
            scope = set()
            for c in chunks(changed_latest, 500):
                pairs = []
                for v in c:
                    mid = idmap.get((v["owner"], v["name"]))
                    if mid:
                        pairs.append(f"(mod_id={mid} AND version={q(v['version'])})")
                if not pairs:
                    continue
                for r in rows("SELECT id FROM mod_versions WHERE " + " OR ".join(pairs) + ";"):
                    scope.add(r[0])
            # A world's pin can point at a version whose row was rewritten this run, and
            # removing a mod changes what its dependents resolve to, so both feed in.
            dres, dunres = resolve_deps(run, scope, catalogue_moved)

        run.finish("ok", http_status=status, bytes_fetched=len(raw),
                   pkgs_seen=len(feed_mods), vers_seen=len(feed_vers),
                   mods_added=len(new_m), mods_updated=len(chg_m), mods_removed=len(gone_m),
                   vers_added=len(new_v), vers_updated=len(chg_v), vers_removed=len(gone_v),
                   deps_resolved=dres, deps_unresolved=dunres)
        sql(f"UPDATE mod_sync_runs SET body_sha256={q(sha)}, http_last_mod={q(lm)} "
            f"WHERE id={run.id};")
        log(f"{cfg['label']}: sync complete in {time.time()-run.t0:.2f}s")
        return "ok"

    except Exception as e:                                   # noqa: BLE001
        log(f"ERROR {cfg['label']}: {e}", level="error")
        run.finish("error", error=e)
        return "error"

    finally:
        # Always detach, even on the early returns above (unchanged / dry-run) and on the
        # error path. Leaving _CURRENT_RUN set would attribute the NEXT catalogue's log
        # lines to this run, so Hexium's detail would appear under Thunderstore.
        run.flush()
        _CURRENT_RUN = None


def main():
    ap = argparse.ArgumentParser()
    ap.add_argument("--source", default="all", choices=["all"] + list(SOURCES))
    ap.add_argument("--force", action="store_true",
                    help="ignore change detection and re-read the whole catalogue")
    ap.add_argument("--trigger", default="cron",
                    choices=["cron", "manual", "boot"])
    ap.add_argument("--dry-run", action="store_true")
    ap.add_argument("--json", action="store_true", help="emit a machine-readable summary")
    args = ap.parse_args()

    settings = get_settings()
    todo = list(SOURCES) if args.source == "all" else [args.source]

    results = {}
    for s in todo:
        results[s] = sync_source(s, settings, args.trigger, args.force, args.dry_run)

    if args.json:
        print(json.dumps(results))
    return 0 if all(v != "error" for v in results.values()) else 1


if __name__ == "__main__":
    sys.exit(main())
