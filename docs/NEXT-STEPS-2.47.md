# 2.47 — where it stands, and what is next

Written 2026-09-16 as a handoff. Read this first after a context compaction.

`docs/RELEASING.md` points here for "what is still untested", so **keep the next two sections
true** — a stale entry here becomes a release note that lies.

## Status

**PRE-RELEASE. `:rc` moved on 2026-09-19 and is now AHEAD of the `:2.47` tag.**
`:latest` still points at 2.46, deliberately.

- Branch `master` @ `90712938`, pushed. Tag `v2.47` is at the older `31fdb460`.
- `:rc` = `sha256:beb91bf88540132cd3ed84ea8bab4a9184bc90b85d1049f23967c24129a79f58`
  — this is the one to test. Contains five commits the `:2.47` tag does not.
- `:2.47` = `sha256:c0a005ef…` (2026-09-16, **superseded — has the four auto-update bugs**)
- `:latest` = `sha256:c16d8e7a…` (2.46)
- Tests: **118 passing** — `test-updateApplier.sh` (37), `test-updateChecker.py` (19),
  `test-record-installed.py` (18), `test-whatsnew.sh` (18), `test-status-badge-css.py` (17),
  `test-playerMonitor.sh` (9)
- GitHub release <https://github.com/brianmiller/phvalheim-server/releases/tag/v2.47>,
  marked pre-release. **Its notes predate the 2026-09-19 fixes** — re-cut the tag and the
  release from `master` when promoting.

Issue #87 answered on GitHub (comment 5689792285), left **open** deliberately.

## What has actually been tested

- Check Now — confirmed fast and correct
- **A full Update Now run, on a live world, 2026-09-19.** First one ever. It found four
  bugs; all four are fixed and in `:rc`. See `phvalheim_autoupdate_lifecycle` in memory.
- **The mod rebuild path is now exercised for real** — 6 mods downloaded and installed,
  36 plugins verified, `--record-installed` recorded all 36 against a live catalogue.
- The three scroll fixes — still not confirmed in a browser
- The What's New button and the status pills — code and markers verified, **not looked at**
- **The scheduled path has still never fired on its own.** Everything so far has been
  `updateApplier <world> now`, which skips the idle and window gates.

## Do this next, in order

1. **Let the SCHEDULED path fire.** Turn auto-update on for one world, leave it, and watch
   cron pick it up. Every fix so far was verified through the `now` path, which bypasses
   `isIdle` and `inWindow` — those two gates have never run in anger.
2. **Watch the world come back up by itself.** The 2026-09-19 run left it down for 7
   minutes; that is fixed, but the fix landed *after* the run, so the restart has been
   tested only by unit tests and a marker.
3. **Look at the What's New button and the status pills** in a browser. Both were changed
   on 2026-09-19 and neither has been seen rendered.
4. **Confirm the scroll fixes** in a browser — reasoned from the code, never exercised.
   The mod picker one is the least certain of the three.
5. Then promote: re-tag `v2.47` at `master`, rebuild with
   `EXTRA_TAGS="2.47 latest"`, update the release notes, drop pre-release, close #87.
5. Only then, promote: `EXTRA_TAGS="latest" setsid nohup dev_tools/buildRcDetached.sh &`,
   `gh release edit v2.47 --prerelease=false --latest`, then close #87. **Never `docker tag` a
   tested `:rc`** — see `docs/RELEASING.md`.

## The 2026-09-19 auto-update fixes, in one place

All four found by actually running it. Full detail in the `phvalheim_autoupdate_lifecycle`
memory entry; the short version, because it is the part that will be needed again:

1. **`updateApplier` runs as `phvalheim` and CANNOT use `supervisorctl`** — supervisord's
   config is `0660 root:root` and its socket `0700 root:root`. The bare call discarded the
   error, so worlds were never stopped and steamcmd rewrote game files under live servers.
   Stop/start now go through `worlds.mode`, which the root engine owns, and the applier
   polls the process table before touching anything.
2. **`mode='update'` ALWAYS ends with the world stopped** — that path finishes with
   "finally, set the world to stopped state", on purpose, because a hand-edited mod list
   uses it too. The applier's start was in an `else` that only ran for `scope='game'`, and
   the default scope is `both`. Every auto-update touching mods took a world offline and
   left it there while reporting success.
3. **A function's last command is its return value.** `InstallAndUpdateValheim` ended with
   `chown -R`, and the client payload zip is root-owned while the applier is not, so EPERM
   turned a successful install into "update failed".
4. **`worldBackup` exited 0 when it SKIPPED** (global lock held by another world), so a
   skipped backup read as a taken one. Now exit 75, and the update defers.

**Still open, deliberately not fixed:** `update_state='failed'` excludes a world from the
sweep (`WHERE update_state IN ('idle','pending')`) with no retry and no expiry. A failed
auto-update is a dead end until someone clears it by hand. Worth a decision before 2.48.

## Watch for

- `player_count_source` should read `playfab` on a crossplay world. If it ever says
  `heartbeat` there, the flag routing is wrong and that world's count will sit at zero.
- A world stuck on `update_check_state='checking'`. It is cleared in a `finally`, but if one
  ever sticks the UI spins forever with no retry.
- The `update_phase` bars are striped/animated rather than percentage-based on purpose:
  neither steamcmd nor the mod install reports progress, so a number would be invented.

## Installed mod versions — DONE in 2.47 (was the 2.48 plan)

Folded in rather than deferred. `updateChecker` no longer reads `worlds.modsViewer` at all.

```sql
world_mods.installed_version_id  INT UNSIGNED NULL   -- mod_versions.id
world_mods.installed_at          DATETIME NULL
```

Written by exactly one thing: `worldMods.py --record-installed`, called from
`downloadAndInstallTsModsForWorld()` with the ids of the mods that actually landed. `--plan`
gained a ninth column (`mod_id`, **appended** so the four scripts that awk fields 1-5 are
untouched) to carry those ids out to the bash loop.

`mod_versions.id` rather than a version string, because the id is immutable — a catalogue
resync cannot rewrite history underneath us.

**The two columns carry three states, and all three are load-bearing:**

| `installed_at` | `installed_version_id` | meaning |
| --- | --- | --- |
| NULL | NULL | never recorded → **unknown** |
| set | NULL | known **not** installed (a duplicate plugin `by_plugin()` collapsed away) |
| set | set | comparable |

Collapsing the middle case into the first is the trap on the other side: after a clean
rebuild, a world with one collapsed duplicate would sit on "waiting for data" forever. Every
modded world has at least one such row. Both directions are pinned by tests.

A mod whose install **failed** is left completely untouched — its previous copy is still in
`BepInEx/plugins`, so its previous recorded version is still true. Clearing it would report
"unknown" for a mod we can see.

The guard that was offered for the latent `--viewer-json` bug is no longer needed: nothing
that makes a decision reads `modsViewer` any more. Its docstring now says so.

Also built: the per-world **Rebuild Mods** button, in the Updates tab beside the "waiting for
data" message. It posts `worldAction&cmd=update`, the same path as saving a mod-list edit.
Deliberately per-world and manual — a rebuild stops the world, and a world whose mod list no
longer resolves is left stopped by design, so doing this to 28 worlds unattended could take a
server down overnight.

Also fixed while in there: the Updates tab was drawing **two Mods rows** — a leftover
unconditional block after the never-checked gate — so a never-checked world showed a muted
"waiting for data" and a green "up to date" one line apart.

### Verified against a real database

Not just the unit tests. On `phvalheim-dev`, with a synthetic 36-mod dependency closure built
from the live catalogue (since removed):

- migration adds both columns and is a no-op on re-run
- `--plan` emits 9 fields with `mod_id` last, across all 36 rows
- a simulated failed install records 35 of 36 and leaves the failed row NULL/NULL
- rewinding Jotunn to 2.0.1 → `1 mod(s): Jotunn 2.0.1 -> 2.30.0`
- pinning that same mod → back to 0, no error
- one unrecorded mod → "waiting for data" text, and a confirmed update alongside it still
  reports **both**
- `installed_at` set with a NULL version → correctly **not** treated as a gap

## Backfill options for the 28 legacy worlds

**The migration writes nothing, deliberately.** Nothing on the box knows which version of a
plugin is sitting in a world's `BepInEx/plugins` — the extracted folders carry no
`manifest.json` — so any backfilled value would be a guess wearing the costume of a fact.
NULL is the true answer and the UI says "waiting for data".

1. **Do nothing** (recommended). Self-heals as worlds are rebuilt.
2. **Rebuild Mods, per world, when convenient** — now a button in the Updates tab.
3. **Rebuild them all deliberately** — works, but each stops/reinstalls/restarts, and a world
   with a mod that no longer resolves is left stopped by design. Maintenance-window job.
4. **Infer versions from the zip cache** — rejected. The cache is shared and holds multiple
   versions of the same mod, and issue #50 says it has no integrity check, so it would mean
   building authoritative-looking numbers on a store we do not trust.

## Test-server state to be aware of

The test server has the 2.47 schema and, from verification runs during development, some
engine tools **staged by hand**. Pulling `:rc` there is a no-op for the DB — the migration is
object-by-object idempotent — but it will overwrite those hand-staged files, which is what
you want. The local dev container likewise has the 2.47 schema and a synced web tree.

Noticed in passing on two different worlds, never investigated: a mod compiled against an
older Valheim API throws on every tick and fills the world log — 12,277 of 36,830 lines in
one case, 2,340 in another. The second one **resolved itself** once the mods were brought up
to date, which is the likely answer for the first too. It is a symptom of game-and-mods
drifting apart, not a PhValheim fault, but a world log that is 25% one exception is worth
surfacing to the operator somewhere.
