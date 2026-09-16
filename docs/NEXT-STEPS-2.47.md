# 2.47 — where it stands, and what is next

Written 2026-09-16 as a handoff. Read this first after a context compaction.

## Status

**`:rc` only. NOT released.** No version tag, no `:latest`, no GitHub release.

- Branch `master`, commit `4602580a`, pushed.
- Image `theoriginalbrian/phvalheim-server:rc`
- Digest `sha256:e4d6ee2104315d8258284c4ffafc4a9a83bfc855b34194473068a4bf203156e5`
- Version in Dockerfile: `2.47`
- Tests: 37 passing — `test-playerMonitor.sh` (9), `test-updateApplier.sh` (16),
  `test-updateChecker.py` (12)

Issue #87 has been answered on GitHub (comment 5689792285) and left **open** deliberately,
pending real-world testing.

## What Brian has actually tested

- Check Now — confirmed much faster and better
- The three scroll fixes — not yet confirmed either way at time of writing
- **Update Now has NEVER been watched end to end on a live world.** This is the single
  biggest untested path and it is the one that stops a server.

## Do this next, in order

1. **Watch one full Update Now run on a live world.** Verify each phase is written and
   rendered: backup → stopping → game → mods → starting, the Active Worlds pill follows it,
   and the world comes back up. Pick a world that is not precious.
2. **Turn auto-update on for exactly one world** and let the scheduled path fire on its own.
   `updateApplier` is the only thing in PhValheim that stops a server nobody asked it to
   stop; watch it do that once before trusting it broadly.
3. **Confirm the scroll fixes** in a browser — they were reasoned from the code, not
   exercised. The mod picker one is the least certain of the three.
4. Only then: version tags. **Rebuild for `2.47` and `latest` — never `docker tag` a tested
   `:rc`** (2.45 lesson; finishing the docs changes files inside the image). Then the GitHub
   release, then close #87.

## Watch for

- `player_count_source` should read `playfab` on a crossplay world. If it ever says
  `heartbeat` there, the flag routing is wrong and that world's count will sit at zero.
- A world stuck on `update_check_state='checking'`. It is cleared in a `finally`, but if one
  ever sticks the UI spins forever with no retry.
- The `update_phase` bars are striped/animated rather than percentage-based on purpose:
  neither steamcmd nor the mod install reports progress, so a number would be invented.

## Known-imperfect, deliberately left

**`worlds.modsViewer` is a UI display cache, and update detection currently reads it.** Its
`version` comes from `effective_version()` — the live catalogue — so it is only correct
because both `generateModViewerJson` call sites sit immediately after a mod install. That is
a coincidence of call sites, not a property of the data: anything that calls
`worldMods.py --viewer-json` later silently rewrites every "installed" version to "latest",
and every world then reports up to date forever.

Today's behaviour is *honest* (it says "waiting for data") but imprecise, and it resolves as
worlds get rebuilt. 28 of 35 production worlds currently have a pre-2.43 snapshot with no
versions at all; 26 of 35 have never been checked.

### Planned for 2.48

```sql
world_mods.installed_version_id  INT UNSIGNED NULL   -- FK mod_versions.id
world_mods.installed_at          DATETIME NULL
```

Written by exactly one thing — the installer, after a mod is on disk. Read by
`updateChecker`. `modsViewer` goes back to being a cache nobody's correctness depends on.
Better because it records a fact rather than a derivation (`mod_versions.id` is immutable, so
a catalogue resync cannot rewrite history), it is per-mod rather than one blob that blinds a
whole world, and NULL genuinely means "never recorded".

**Deliberately NOT done in 2.47**: it touches the mod install path, where a mistake leaves
worlds unbootable, and 2.47 already carries ~24 new columns and four rounds of fixes on an
RC that has not been through a full update cycle.

### Offered but not built (Brian has not said yes)

- A **guard** so the latent `--viewer-json` bug cannot bite before 2.48 — either make
  `--viewer-json` refuse to run outside an install, or have the checker ignore a `modsViewer`
  written after `date_updated`.
- A per-world **"Rebuild mods"** button next to the "waiting for data" message, so the 28
  legacy worlds can be fixed one at a time when convenient rather than as a batch job.

## Backfill options for the 28 legacy worlds

1. **Do nothing** (recommended). Self-heals as worlds are rebuilt.
2. **Rebuild them deliberately** — works, but each stops/reinstalls/restarts, and a world
   with a mod that no longer resolves is left stopped by design. Maintenance-window job.
3. **Infer versions from the zip cache** — rejected. The cache is shared and holds multiple
   versions of the same mod, and issue #50 says it has no integrity check, so it would mean
   building authoritative-looking numbers on a store we do not trust.

## Production state to be aware of

`513-phvalheim1` has the 2.47 schema and a newer `updateChecker.py` **staged by hand** from
verification runs during development. Pulling `:rc` there is a no-op for the DB (the
migration is idempotent). `phvalheim-dev` on this host likewise has the 2.47 schema and a
synced web tree.

Unrelated, noticed in passing: `VOXYLADY.log` on production is 1.4 MB, of which 12,277 of
36,830 lines are one repeated `MissingMethodException: Method not found: void
.Character.Message(…)` — a broken mod spamming a live world. Never investigated.
