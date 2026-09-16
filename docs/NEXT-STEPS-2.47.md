# 2.47 — where it stands, and what is next

Written 2026-09-16 as a handoff. Read this first after a context compaction.

## Status

**`:rc` only. NOT released.** No version tag, no `:latest`, no GitHub release.

- Branch `master`, commit `4602580a`, pushed.
- Image `theoriginalbrian/phvalheim-server:rc`
- Digest `sha256:e4d6ee2104315d8258284c4ffafc4a9a83bfc855b34194473068a4bf203156e5`
- Version in Dockerfile: `2.47`
- Tests: 62 passing — `test-playerMonitor.sh` (9), `test-updateApplier.sh` (16),
  `test-updateChecker.py` (19), `test-record-installed.py` (18)

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

## Production state to be aware of

`513-phvalheim1` has the 2.47 schema and a newer `updateChecker.py` **staged by hand** from
verification runs during development. Pulling `:rc` there is a no-op for the DB (the
migration is idempotent). `phvalheim-dev` on this host likewise has the 2.47 schema and a
synced web tree.

Unrelated, noticed in passing: `VOXYLADY.log` on production is 1.4 MB, of which 12,277 of
36,830 lines are one repeated `MissingMethodException: Method not found: void
.Character.Message(…)` — a broken mod spamming a live world. Never investigated.
