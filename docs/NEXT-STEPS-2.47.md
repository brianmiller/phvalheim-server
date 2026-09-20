# 2.47 — where it stands, and what is next

Written 2026-09-16 as a handoff. Read this first after a context compaction.

`docs/RELEASING.md` points here for "what is still untested", so **keep the next two sections
true** — a stale entry here becomes a release note that lies.

## Status

**PRE-RELEASE. `:rc` moved FOUR times on 2026-09-19 and is far ahead of the `:2.47` tag.**
`:latest` still points at 2.46, deliberately.

- Branch `master` @ `52c0ed69`, pushed. Tag `v2.47` is at the much older `31fdb460`.
- `:rc` = `sha256:ab4a74a3d89f3c74dce8222b541d8a5bf3a4e105b5f18a12fa74ff815c24a3e5`
  — **this is the one to test, by digest.** The `:2.47` tag has none of the day's fixes.
- Tests: **183 passing** — `test-updateApplier.sh` (37), `test-engine-reaper.sh` (27),
  `test-record-installed.py` (24), `test-updateChecker.py` (22), `test-whatsnew.sh` (18),
  `test-status-badge-css.py` (17), `test-modsync-lock.py` (20), `test-update-banner.js` (9),
  `test-playerMonitor.sh` (9).
- `dev_tools/test-duplicate-plugin.sh` fails 3 and **was already failing** (verified on a
  clean tree). It asserts BepInEx appears in the install plan and the mod viewer, which 2.44
  deliberately removed. The test encodes a reversed expectation; retire or rewrite it.
- GitHub release <https://github.com/brianmiller/phvalheim-server/releases/tag/v2.47>,
  marked pre-release. **Its notes predate everything below.**

Issue #87 answered on GitHub (comment 5689792285), left **open** deliberately.

## What has actually been tested

- **An outside operator ran a full auto-update on his own server and it worked** — backup,
  stop, update, start, all four phases. First confirmation off our box.
  **Ask him whether that run was SCHEDULED or a manual Update Now** before promoting: only
  the scheduled path exercises `isIdle` and `inWindow`.
- A full Update Now on a live world, and the mod rebuild path, both exercised for real.
- **The vanilla fix was reproduced, fixed and re-verified on a live container** in both
  halves: the checker reports an honest zero with no rebuild, and a rebuild records the rows
  as known-not-installed.
- The three scroll fixes, the What's New button and the status pills — **still not looked at
  in a browser.**
- **The scheduled path has still never been watched from this side.**

## Two things that affect EVERY `:rc` operator

**`:rc` is a live channel** — other people run it, so each push is a release. Batch fixes
rather than pushing per-fix, and ask for a **digest** in bug reports: the tag moved six times
on 2026-09-19 and "I'm on rc" identifies nothing.

**Pulling stops every world, and `autostart=0` worlds do not come back.** Nothing warns the
operator — not the What's New modal, not the docs. Production had three running worlds in
exactly that state. Better fixed than documented: remember which worlds were running at
shutdown and restore those, instead of leaning on a flag that means something subtly
different.

## Do this next, in order

1. **Confirm with the reporting operator whether that successful run was scheduled or manual.** If scheduled,
   the `:latest` gate is met. If manual, that gate is still open.
2. **Look at the What's New button and the status pills** in a browser. Both were changed on
   2026-09-19 and neither has been seen rendered.
3. **Confirm the scroll fixes** in a browser — reasoned from the code, never exercised.
4. Then promote: re-tag `v2.47` at `master`, rebuild with `EXTRA_TAGS="2.47 latest"`, update
   the release notes, drop pre-release, close #87. **Never `docker tag` a tested `:rc`** —
   see `docs/RELEASING.md`.

## The 2026-09-19 fixes, in one place

Eight in one day. Four came from the first real Update Now; **four more came from shipping
those fixes to a real operator**, each uncovered by the one before it.

5. **The reaper fought supervisor and could not win.** SIGKILL on an `autorestart=true`
   program is respawned instantly, and the next 2s tick kills the new pid — one kill per
   tick, pid climbing, forever. Ask supervisor to stop it first (that marks the exit
   expected); only SIGKILL what supervisor does not own.
6. **`mode='update'` then SPUN on a running world.** Fixing the dead liveness guard made a
   refusal reachable that nothing ever cleared, so the loop reprinted it every 2s and the
   update never ran. **Nothing that sets `mode='update'` stops the world first** — not the
   mod-list save, not Rebuild Mods, not Hugin — so the engine stops it itself now, and
   restarts it only if it was running.
7. **A vanilla world waited forever for mod data.** Converting to vanilla purges the mod
   files and skips the install path but keeps the `world_mods` rows, so they stayed
   `installed_at IS NULL` — "waiting for data" that no rebuild could ever clear.
8. **The "Update started…" banner never came down**, so a finished job looked like a running
   one. Age-gated at 10s: the engine only picks a world up on its next tick, so clearing on
   the first not-busy read would wipe the banner a second after the click.

**`worlds.pid` is never written by anything.** Both guards that read it always answered "not
running". Ask the process table via `worldProcessRunning`.


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
