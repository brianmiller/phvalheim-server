# Catalogue settings and syncing

PhValheim syncs public mods from **Thunderstore** and **Hexium**. **Hugin’s Vibes** is a third, local catalogue populated by the workbench. Both are enabled by default and a
world can use either or both; search results show a coloured pill for each mod's origin
(Thunderstore blue, Hexium purple).

| Setting | Default | Description |
|---|---|---|
| **Thunderstore** / **Hexium** | Enabled | Whether that catalogue is synced and offered in the mod picker. |
| **Thunderstore API Key** / **Hexium API Key** | *(empty)* | **Not required.** Both catalogues are public and unauthenticated. Supply a key only if a source starts demanding one; it is sent as a bearer token. |
| **Catalogue Sync Interval** | `6` hours | How often the catalogues are checked for changes. Does not apply to the start-up sync, which always runs. |

Disabling a catalogue hides its mods from the picker but does **not** remove mods a world
has already selected.

**Version pinning.** Every published version of every mod is stored, not just the newest.
Select a mod and pick a version from the dropdown in its row to freeze it there; `Latest
(auto)` keeps following new releases. A pinned version is kept in the database even if the
source later delists it, so a pinned world does not silently move.

## When the catalogues are synced

Both catalogues are checked **every time the server starts**, and on the configured interval
after that. So a container you have just restarted comes back with a current mod list rather
than whatever the last scheduled check left behind — the start-up sync ignores the interval
deliberately, and costs about a second when nothing has changed.

It runs in the background, so a slow or unreachable catalogue never delays your worlds
starting.

## How the sync performs

There is nothing to tune. The sync is designed so that the common case does almost no work:

- **Thunderstore** sends a `Last-Modified` header, so PhValheim makes a conditional request
  and normally gets a bodiless `304 Not Modified` back in about a tenth of a second.
- **Hexium** sends no validator, so its (much smaller) response body is hashed and compared
  against the previous run.
- Every stored row carries a content hash, so even when a catalogue *has* changed only the
  rows that genuinely differ are rewritten.

The **Mod Catalogues** card shows a live panel per catalogue: current phase, packages and
versions seen, how many mods and versions were added, changed or removed, how long it took,
and how that compares with the previous run. Progress is in
`/opt/stateful/logs/modSync.log`.

Each catalogue's panel has its own **live sync log** — expand it to watch a sync happen: the
endpoint used, how change detection decided to fetch or skip, what was added, updated or
delisted **by name**, which dependencies could not be resolved, and a per-phase timing
breakdown. A **per-mod detail** toggle hides the individual mod lines when you only want the
summary.

To force a sync immediately, use the per-catalogue `sync` link in the Mod Catalogues
panel. A forced sync ignores change detection and rebuilds the dependency graph, so it takes
considerably longer than a routine check.

> **Upgrading from 2.42 or earlier:** existing mod selections are migrated automatically on
> first start and the old Thunderstore tables are left untouched, so nothing is discarded.
> The `Thunderstore Local Sync` and `Thunderstore Chunk Size` settings have been removed —
> they configured the old parallel-worker sync, which no longer exists. The sidebar's
> **Thunderstore Sync** button is gone too; syncing is automatic, and the Sync &
> Maintenance panel has a per-catalogue `sync` link if you want to force one.
