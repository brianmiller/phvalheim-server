# 2.43 — Multi-source mod database

Research notes and the decisions they forced. Measured 2026-09-12 against live
Thunderstore and Hexium, and benchmarked on real MySQL 8.0.46 in `phvalheim-dev`.

## Hexium needs no credential

`https://valheim.hexium.gg/api/v1/package/` is public, unauthenticated, and returns a
payload **schema-identical to Thunderstore's** `/c/valheim/api/v1/package/`. Same key
names, same nesting, same `versions[]` ordering (newest first).

| | Thunderstore | Hexium |
|---|---|---|
| packages | 10,839 | 834 |
| versions | 89,405 | 2,297 |
| body | 164 MB raw / 12.5 MB gz | 2.3 MB raw / 250 KB gz |
| `Last-Modified` | **yes** | no |
| extras | — | `donation_link`, version `suggestions` |
| also has | — | `/api/experimental/package-index/` (NDJSON, 247 KB) |

So the two sources are one adapter with two URLs, not two subsystems. The API-key
settings are still added (below) but are documented as optional — neither source
requires one to read its catalogue.

## Four traps found by measurement

These are the reasons the schema looks the way it does. Each was observed, not guessed.

**1. `uuid4` is not a global identity — 600 collide.**
Hexium mirrors Thunderstore packages *carrying their original `uuid4`*. 600 package
UUIDs appear in both feeds, all 600 with identical owner/name. Today's code keys a
world's mod selection on a bare `moduuid`, so adding a second source would silently
conflate 600 mods. **Identity is `(source, owner, name)`.** `source_uuid` is retained
as source metadata only, never as a key. (Version UUIDs do not collide — Hexium
generates synthetic ones.)

**2. A case-insensitive UNIQUE key destroys 23 real mods.**
MySQL's default `utf8mb4_0900_ai_ci` treats `IronTeam/Iron_ModPack` and
`IronTeam/Iron_Modpack` as the same row — they are different published mods. 22 such
groups exist on Thunderstore (`Janoobalance`/`JanooBalance`,
`AlbusWorld_ModPack`/`AlbusWorld_Modpack`, …). Under `ai_ci` they collapse *and
overwrite each other on every sync*. The identity columns `owner`, `name`, `version`
are therefore `COLLATE utf8mb4_0900_as_cs`, and the staging tables must match or the
merge JOIN fails on mixed collations.

Oracle for this: a full load must produce **exactly 11,672 mods and 91,701
mod_versions**. Under `ai_ci` it produces 11,649 / 91,668 — and says nothing.

**3. Dependency strings cannot be split on `-`.**
The format is `owner-name-version`, and all three parts may contain hyphens:
owners `LVH-IT`, `sinai-dev`; versions `2.0.6-beta.1`. Naive `rpartition('-')`
misresolves; **longest-prefix match against known `owner-name` keys** resolves
1,371 of 1,372. The resolution is done once at sync time, not per-lookup.

**4. Dependencies cross sources — resolution cannot be per-source.**
Of 1,372 distinct Hexium dependency strings: 752 name a package present in both
feeds, **576 name a package that exists only on Thunderstore**, 8 exist in neither
(withdrawn/renamed). A Hexium-only world therefore still needs Thunderstore rows to
resolve its own dependency graph. This is the strongest argument for one unified
table rather than two parallel ones.

Also: the Hexium feed itself ships one exact duplicate (`Smoothbrain/Cooking` twice),
so the loader must be duplicate-tolerant rather than assume feed uniqueness.

## Why the old sync is slow

It is not the network. `tsSyncLocalParseMultithreaded.sh` shells out to `jq` per field
and runs **one `SELECT` plus one `INSERT`/`UPDATE` as a separate `mysql` process per
mod** — and `tsmods` has *no index except the primary key*, so each of those SELECTs is
a full table scan of ~11k rows. That is ~2 process spawns × 11k mods against an
unindexed table, which is where the hours go. It also keeps only the newest version
(`head -1`), which is why pinning was impossible.

Transforming Hexium's entire catalogue in Python takes **0.017 s**. The work was never
the parsing.

## Measured replacement

Fetch → transform to TSV → server-side `LOAD DATA INFILE` into unkeyed staging →
one `INSERT … SELECT … ON DUPLICATE KEY UPDATE` merge per table.

`local_infile` is `OFF` and `secure_file_priv=/var/lib/mysql-files/`; MySQL runs in the
same container, so the TSV is written there and loaded **server-side** — no need to
weaken `local_infile`.

Cold build, empty database, both sources, all history:

| | Thunderstore | Hexium |
|---|---|---|
| json parse | 0.92 s | 0.12 s |
| transform + TSV | 1.19 s | 0.03 s |
| `LOAD DATA INFILE` | 19.65 s | 0.39 s |
| merge mods | 1.29 s | 0.29 s |
| merge versions | 8.26 s | 0.71 s |
| **total** | **31.31 s** | **1.54 s** |

**32.9 s for both, from nothing, including all 91,701 historical versions** — against
hours today for 11,222 latest-only rows.

Steady state is cheaper still, and mostly skipped outright: Thunderstore gets a
conditional `If-Modified-Since` from its `Last-Modified`, and Hexium (which sends no
validator) is short-circuited on a **sha256 of the response body** recorded in
`mod_sync_runs`. An unchanged feed costs one HTTP round trip and no database work.

`LOAD DATA` timing is the noisiest phase under disk contention (19.6 s–55 s observed
for Thunderstore). The change-detection short-circuit is what keeps the routine case
flat, so it is a correctness requirement, not an optimisation.

## Schema

`mods` — one row per `(source, owner, name)`; `UNIQUE KEY (source, owner, name)` with
case-sensitive owner/name. Carries denormalised `version_count` and `latest_version`
so the picker never needs the self-join `getAllModsLatestVersion()` does today.

`mod_versions` — one row per version, `UNIQUE KEY (mod_id, version)`. Holds the
source's own `download_url` (mandatory: Hexium serves from `cdn.hexium.gg`, so the
URL cannot be reconstructed from a template the way the Thunderstore path does today),
plus `file_size`, `date_created`, `deps` JSON, and `source_rank` (0 = newest, the
source's own ordering, which is more trustworthy than parsing semver).

`world_mods` — `(world_id, mod_id)` with `pin_version_id` NULL meaning "follow latest",
and an `is_dep` flag. This replaces the space-separated-UUID columns, which cannot
represent either a source or a pin.

`mod_sync_runs` — per-run row: phase, counts, added/updated/removed, bytes, body
sha256, duration, trigger, error. Feeds the reactive progress panel and the
previous-vs-current comparison.

`last_seen` on `mods`/`mod_versions` drives pruning: rows absent from a successful full
sync are removed, so a delisted mod cannot linger as an unresolvable selection.

## Migration

`tsmods` is left in place untouched for rollback. `dbUpdate_2.43.sh` copies it into
`mods`/`mod_versions` as `source='thunderstore'` and expands each world's
space-separated `thunderstore_mods` / `thunderstore_mods_deps` into `world_mods` rows,
resolving each legacy UUID through `tsmods` — unambiguous because before 2.43 every
selection was Thunderstore.
