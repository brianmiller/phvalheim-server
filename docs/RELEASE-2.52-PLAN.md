# Release 2.52 — plan

**Theme: cross-user `/tmp` state. One confirmed always-on breakage, one latent, three defensive.**

Status: plan only. Nothing written, nothing built. Version number is the maintainer's (2.52, given).

Reported on Discord 2026-09-27: manual catalogue sync throws, and automatic sync never runs.
Reproduced in a real `:2.51` container. It is a regression in **2.47 → 2.51 inclusive** — i.e.
every release currently published — and it is **silent** on the cron path.

---

## 1. The bug, precisely

`modSync.py` is invoked from three places, as **two different users**:

| Trigger | Site | Runs as |
|---|---|---|
| boot | `container/engine/includes/0-functions.sh:567` (`syncModCatalogue`) → `engine/phvalheim:35`, `supervisor.d/phvalheim.conf` `user=root` | **root** |
| cron, hourly at :17 | `container/cron.d/modSync:12` | `phvalheim` |
| forced (Sync & Maintenance per-catalogue link) | `container/nginx/www/admin/index.php:88` `exec()` → `php-fpm/www.conf` `user = phvalheim` | `phvalheim` |

`take_global_lock()` (`container/engine/tools/modSync.py:361`) does:

```python
fh = open(RUN_LOCK, "w")        # RUN_LOCK = /tmp/phvalheim-modsync.run.lock
```

The boot sync runs **first and always**, as root, so the lock file is created `root:root 0644`
(umask 0022). `open(..., "w")` needs write to truncate. Every later cron or forced run is
`phvalheim` → `PermissionError: [Errno 13]`. `/tmp` is sticky (`drwxrwxrwt`), so `phvalheim`
cannot delete it either. `WAIT_LOCK` (line 342) has the identical `open(..., "w")`.

Reproduced in `:2.51`, with the clean path as a control:

```
CONTROL A  phvalheim creates it first  -> PHVALHEIM-CREATE-OK / PHVALHEIM-REOPEN-OK      ok
REPRO B    root creates it first       -> -rw-r--r-- 1 root root
           then as phvalheim           -> PermissionError: [Errno 13] ... run.lock       fail
           then rm as phvalheim        -> "Operation not permitted"                      fail
```

Introduced by `09b2aff3` (2026-09-20), the fix for the 668-lost-dependency-edges incident.
`git tag --contains` → v2.47, v2.48, v2.49, v2.50, v2.51.

**Observed impact.** The boot sync itself succeeds (root creates and takes its own lock), so the
catalogue does refresh on every container restart. After that, nothing: cron fails hourly with
the same traceback into `/opt/stateful/logs/modSync.log`, which nobody reads. That is exactly
the reporter's pair of symptoms — the forced link throws visibly, the hourly sync is silently
dead — and it explains why a catalogue can look *almost* current.

### 1b. A second, independent cross-user bug in the same function

`already_running()` (`modSync.py`, per-source guard) reaps stale `mod_sync_runs` rows with
`os.kill(pid, 0)` and maps `PermissionError` → `alive = True`. That is the right conservative
default in general, but it only became reachable because two users run this tool: a row left
`status='running'` by a **root** boot sync whose pid has since been recycled gives `phvalheim`
EPERM, which is read as "a sync is alive" and skips the run. Fixing the user split (§2.2) closes
this too; no separate code change is proposed.

---

## 2. The fix

### 2.1 Required — `take_global_lock()` needs no write permission at all

`flock(2)` on Linux honours `LOCK_EX` on a **read-only** descriptor. Verified in a `:2.51`
container against a root-owned `0644` file, with a control proving the lock is still genuinely
exclusive:

```
RDONLY-FLOCK-OK      lock taken as phvalheim on a root-owned 0644 file
CONTROL-OK           a second holder was correctly BLOCKED
```

So both lock paths become:

```python
fd = os.open(RUN_LOCK, os.O_RDONLY | os.O_CREAT, 0o666)   # umask trims to 0644 on create
fh = os.fdopen(fd, "r")
```

Same for `WAIT_LOCK`. Why this is the right shape:

- **It self-heals every already-affected server with no cleanup step and no restart.** The
  existing root-owned `0644` file is world-readable, so `phvalheim` can lock it as-is. No
  `rm -f`, no migration, no "restart your container" instruction in the notes.
- No `O_TRUNC`. A lock file's contents were never used; truncating it was the only reason write
  was ever needed.
- `0644` is sufficient *because only read is required* — so the earlier idea of forcing `0666`
  via `umask(0)` is unnecessary and is dropped. Fewer moving parts.
- The kernel still releases the lock when the process dies, which is the property the original
  commit chose `flock` for. That is preserved exactly.

### 2.2 Required — one user for all three triggers

`0-functions.sh:567`, so the boot sync stops being the odd one out:

```bash
setsid su phvalheim -s /bin/sh -c '/opt/stateless/engine/tools/modSync.py --source all --trigger boot' \
        >> /opt/stateful/logs/modSync.log 2>&1 &
```

Checked, not assumed:

- `getent passwd phvalheim` → `phvalheim:x:1000:1000::/opt:/bin/bash`. **`HOME=/opt` is NOT
  writable by `phvalheim`** (verified). `modSync.py` needs no `HOME`, and cron already runs it
  as this user with this same `HOME`, so parity is proven rather than hoped for. Do **not** use
  `su -` here on the theory that it gives a better environment; it resolves to the same
  unwritable `/opt`.
- `-s /bin/sh` is belt-and-braces against the login shell changing; the account currently has
  `/bin/bash`.
- Keep it backgrounded and keep `--trigger boot` — `modSyncIntervalHours` is enforced for
  `trigger=cron` only, and a boot sync must run regardless. Still no `--force`.
- Privileges needed by `modSync.py`: MariaDB, outbound HTTPS, append to
  `/opt/stateful/logs/modSync.log`. Cron exercises all three as `phvalheim` today.

§2.1 alone fixes the reported bug. §2.2 is what stops the *class* recurring here, and is the
fix for §1b.

### 2.3 Sweep — the same shape in four other tools

This is a class, not an instance. Every fixed-path `/tmp` state file written by a tool that can
run as either user:

| File | Tool | Mechanism | Status |
|---|---|---|---|
| `phvalheim-modsync.run.lock` / `.wait.lock` | `modSync.py:341-342` | `open(...,"w")` | **confirmed broken** — §2.1 |
| `phvalheim_analytics_payload.json`, `phvalheim_analytics.tmp` | `pushAnalytics.sh:274,283` | `>` redirect | **latent** — root at `engine/phvalheim:134`, `phvalheim` via `cron.d/analyticsPush` and adminAPI `--disabled`. Line 309 `rm -f`s both, so it only wedges when a root run dies before that (hung/killed curl). Fix: `mktemp` like lines 143-145 already do. |
| `.updateApplier.lock` | `updateApplier:19-20` | `exec 9>` | defensive — only `phvalheim` invokes it today (cron + adminAPI). Would wedge auto-updates permanently if root ever ran it. |
| `worldBackup.lock` | `worldBackup:33` | `echo $$ >` | defensive — cron only. `worldBackupReconcile` (root, `phvalheim:24`) does not touch it; confirmed. |
| `worldRestore.lock` | `worldRestore:40` | `echo $$ >` | defensive — adminAPI only. |

Recommend: fix `pushAnalytics.sh` (real, if narrow) and convert the three `.lock` files to the
read-only-`flock` idiom in the same pass, so the pattern is uniform and the next tool to be
called from the engine cannot reintroduce it. If scope needs cutting, cut the three defensive
ones — not `pushAnalytics.sh`.

---

## 3. Tests

**`dev_tools/test-modsync-lock.py` passes today and cannot see this bug.** It imports
`modSync` and runs every case as a single user (`ms.RUN_LOCK`, `hold()`, all in one process), so
a cross-user permission failure is invisible to it. It is a textbook non-oracle for 2.52: it
answers the same whether the bug is present or not.

New oracle — `dev_tools/test-modsync-lock-crossuser.sh`, docker-based because the whole bug is
about two uids:

1. root creates the lock → `phvalheim` takes it → **must succeed** (this is the assertion that
   fails on 2.51 and passes after §2.1).
2. `phvalheim` creates it → root takes it → must succeed (reverse order).
3. Exclusion control: with the lock held, a second `phvalheim` holder must be **refused**. This
   is the guard against "fixed it by making the lock not a lock".
4. Boot-path parity: after `syncModCatalogue` runs, `stat -c %U /tmp/phvalheim-modsync.*.lock`
   must be `phvalheim` (asserts §2.2).

**Mutation-check all four** — revert §2.1, confirm 1 goes red; revert §2.2, confirm 4 goes red.
Report honestly if a mutation does not fail.

Also run, and confirm their pre-existing state on a clean tree first (`git stash`):
`test-modsync-lock.py`, `test-duplicate-plugin.sh`, `check-whatsnew.sh`.

---

## 4. Verify markers for 2.52

`dev_tools/buildRcDetached.sh`, new block after the 2.51 blocks (~line 1245), styled like
`# ---- 2.52: ... ----`. Prefer negatives, per `RELEASING.md`:

- `grep -c 'open(RUN_LOCK, "w")' .../modSync.py` → **want 0** (the negative that matters).
- `grep -c 'open(WAIT_LOCK, "w")' .../modSync.py` → **want 0**.
- `grep -cE 'os\.open\((RUN_LOCK|WAIT_LOCK), os\.O_RDONLY' .../modSync.py` → want 2.
- `grep -cE 'su phvalheim .*modSync\.py .*--trigger boot' .../engine/includes/0-functions.sh` → want 1.
- `grep -c 'setsid /opt/stateless/engine/tools/modSync.py' .../0-functions.sh` → **want 0** (the
  un-su'd form is gone). Note this moves 2.43's existing count — per `RELEASING.md`, split it:
  leave 2.43's block asserting its own thing and assert the new total here.
- `grep -c '"/tmp/phvalheim_analytics_payload.json"' .../pushAnalytics.sh` → **want 0** if §2.3 ships.
- whatsnew: `grep -c "2.52. => ." .../includes/whatsnew.php` → want 1.

Dry-run all of these against the repo tree first (`/opt/stateless/engine` → `container/engine`).
Cron entries live at `/etc/cron.d/` in the image, `container/cron.d/` in the repo — no marker
here points at cron, keep it that way.

---

## 5. `whatsnew.php` entry

`container/nginx/www/includes/whatsnew.php`, key `'2.52'`, before `'2.51'`. Operator-facing,
no internals — matching the house voice. Draft:

> Fixed: **your mod catalogues stopped updating after the first start.** PhValheim refreshes
> Thunderstore and Hexium when it starts and then every few hours after that. The hourly refresh
> — and the per-catalogue refresh links on the Sync & Maintenance panel — have been failing since
> 2.47, because the file PhValheim uses to stop two refreshes running at once was being created
> by the wrong account at startup and could not then be opened by the account that does the
> later refreshes. The refresh links reported an error; the automatic ones failed **silently**,
> which is why this went unnoticed.
>
> Your catalogue was still refreshed every time the container restarted, so nothing was lost or
> corrupted — it was simply as current as your last restart. **This repairs itself as soon as you
> upgrade**; there is nothing to clean up and no restart beyond the upgrade itself.
>
> Mods you already have installed in a world, and their versions, were never affected.

Then run `dev_tools/check-whatsnew.sh` — it gates on the Dockerfile version having an entry.

---

## 6. Order of work

1. `Dockerfile` → `ENV phvalheimVersion=2.52`.
2. §2.1 `modSync.py` (both lock paths).
3. §2.2 `0-functions.sh:567`.
4. §2.3 `pushAnalytics.sh` → `mktemp`; three `.lock` tools → read-only `flock`.
5. New cross-user test + mutation-check it. Fix `test-modsync-lock.py`'s docstring so the next
   person knows it is single-user by construction.
6. Verify markers, dry-run against the tree.
7. `whatsnew.php` + `check-whatsnew.sh`.
8. `CHANGELOG` — describe what shipped, re-read against the final diff.
9. Build: `rm -f /tmp/phvalheim-rc-build.log && setsid nohup dev_tools/buildRcDetached.sh > /dev/null 2>&1 &`
   — **always detached.** Poll for `=== done`, read `IMAGE VERIFY OK` and every `(want N)`, note
   the digest. `:rc` is pushed **before** the verify; a failed verify does not unpush it.
10. Live test on the real container per §7 — not just the in-image greps.
11. Publish: `EXTRA_TAGS="2.52 latest" setsid nohup dev_tools/buildRcDetached.sh ... &`. Never
    `docker tag` the tested `:rc`. Confirm digests match.
12. `git tag -a v2.52` + `gh release create v2.52 --notes-file docs/release-notes-2.52.md`.

No DB migration. No schema change. No `dbUpdate_2.52.sh`.

---

## 7. Live test before `:latest`

In-image greps cannot show the lock actually being taken across users. On a container built from
the RC:

1. Restart it. `stat -c '%U %a' /tmp/phvalheim-modsync.*.lock` → expect `phvalheim 644`.
2. Click a per-catalogue refresh link in Sync & Maintenance → completes, no traceback in
   `/opt/stateful/logs/modSync.log`.
3. Force the cron path as the real user:
   `su phvalheim -s /bin/sh -c '/opt/stateless/engine/tools/modSync.py --source all --trigger cron'`
   → runs, no `Errno 13`.
4. **The upgrade path is the one that matters.** Start a `:2.51` container, let the boot sync
   leave a root-owned lock, then upgrade in place to the RC *without* clearing `/tmp`, and
   confirm the next `phvalheim` sync takes the lock. This is the §2.1 self-heal claim; it is the
   single assertion most worth watching run.
5. Two concurrent forced syncs of *different* sources still serialise — the property `09b2aff3`
   existed to protect. Do not ship without this; the original incident cost 668 versions'
   dependency edges permanently.

Ship `--prerelease` if 4 or 5 has not been watched. Otherwise a full release is justified: the
bug is live on every published version and the fix is small and self-healing.

---

## 8. Explicitly out of scope

- **#90** (OneMapToRuleThemAll / Jötunn "Incompatible version") — `dev_tools/ISSUE-90-analysis.md`
  concludes **not a PhValheim bug**: the mod ships its own `RPC_PeerInfo` gate that rejects peers
  with `ErrorVersion`, and Jötunn repaints any `ErrorVersion` as its mod-comparison popup. The
  47-mod set did not reproduce it. Needs a **reply**, not code. That analysis doc and
  `dev_tools/issue90-probe/` are currently untracked — decide whether they get committed.
- **#84** (mod-list edits wipe `BepInEx/config`) — a feature request for admin UI + docs around
  `custom_configs/`, not a bug. Do not "fix" it; that would damage the design.
- **#79** (Firefox download button), #67, #66, #63, #50, #48, #47, #27, #9 — untouched. #50 (no
  integrity check on the mod zip cache) is a real bug with a known fix shape in
  `mod_versions.file_size`, but it is its own release.

## 9. Risks

- **`:rc` is a live channel** — real operators run it. Batch the pushes; do not iterate on `:rc`
  six times in a day.
- §2.2 changes a boot path. If `su` misbehaves under `setsid` in some operator's environment the
  boot sync silently stops running, and the symptom looks like the bug being fixed. §7 step 1 is
  the guard. If it is at all shaky, ship §2.1 alone — it fixes the report by itself.
- Repo is **public**. Grep the diff for hostnames/IPs/credentials before committing, per
  `RELEASING.md`. No log excerpts from the reporter's server in the notes.
