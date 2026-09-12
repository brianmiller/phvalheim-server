# CLAUDE.md

This file provides guidance to Claude Code (claude.ai/code) when working with code in this repository.

## Project Overview

PhValheim Server is a Docker-based Valheim game server manager that synchronizes server and client files to ensure all players have identical mod configurations. It runs multiple services (NGINX, PHP-FPM, MariaDB, Supervisor) in a single container.

**Tech Stack:** Bash (engine), PHP 8.1 (web interfaces), MariaDB (database), Docker (Ubuntu Jammy)

## Architecture

```
┌─────────────────────────────────────────────────────────────┐
│  NGINX (8080: public, 8081: admin)                          │
│      ↓                                                      │
│  PHP-FPM → /container/nginx/www/{public,admin}/             │
│      ↓                                                      │
│  PhValheim Engine (/container/engine/phvalheim)             │
│  - Main loop checks world states every 2 seconds            │
│  - Orchestrates: create, start, stop, update, delete        │
│      ↓                                                      │
│  Supervisor → manages MariaDB, NGINX, PHP-FPM, world procs  │
│      ↓                                                      │
│  MariaDB (worlds, mods, mod_versions, world_mods, settings)  │
└─────────────────────────────────────────────────────────────┘
```

## Key Directories

- `/container/engine/` - Bash orchestration engine
  - `phvalheim` - Main event loop (entry point)
  - `includes/0-functions.sh` - Core functions (1000+ lines)
  - `includes/phvalheim-static.conf` - Configuration constants
  - `tools/` - Utility scripts (db updates, mod sync, backups)
  - `dbUpdates/` - Database schema migrations (versioned)
- `/container/nginx/www/` - PHP web application
  - `public/` - Player-facing UI (Steam login, world list)
  - `admin/` - Management UI (world CRUD, player access, logs)
  - `includes/` - Shared PHP (db_gets.php, db_sets.php, config)
- `/container/cron.d/` - Scheduled tasks (mod sync, backups, log rotation)

## Build & Run

```bash
# Build Docker image
docker build -t phvalheim-server:latest .

# Run container (see README.md for full deployment)
docker create --name phvalheim \
  -p 8080:8080/tcp -p 8081:8081/tcp \
  -p 25000-26000:25000-26000/udp \
  -e basePort=25000 -e steamAPIKey=YOUR_KEY \
  -v /path/to/data:/opt/stateful \
  phvalheim-server:latest
```

## Required Environment Variables

| Variable | Purpose |
|----------|---------|
| `basePort` | First world UDP port (range: 25000-26000) |
| `steamAPIKey` | Steam Web API key for authentication |
| `phvalheimHost` | Public FQDN for web UI |
| `gameDNS` | DNS clients use to connect to game servers |
| `defaultSeed` | Default world seed |
| `backupsToKeep` | Number of backups to retain |
| `phvalheimClientURL` | URL for client installer download |

## Development Scripts

```bash
# In dev_tools/
./buildImage.sh          # Build and push Docker image
./deployLocal.sh         # Local deployment
./promoteRCtoLatest.sh   # Promote release candidate
./saveGit.sh             # Git operations
```

## Releasing

Every release must add an entry to `container/nginx/www/includes/whatsnew.php` describing
its new features and bug fixes — that file feeds the admin UI's one-shot "What's New"
modal, which is how operators find out anything changed. Run `dev_tools/check-whatsnew.sh`
before building; it fails when the Dockerfile version has no entry. A missing entry is
invisible at runtime (the modal shows nothing), so the gate is the only thing that catches it.

## Database Schema Updates

To add a database field, create `/container/engine/dbUpdates/dbUpdate_X.X.sh` and update the version in the Dockerfile. The engine runs `dbUpdater.sh` on startup which applies pending migrations.

## Key Code Paths

**World lifecycle** (in `0-functions.sh`):
- `InstallAndUpdateValheim()` - Downloads/updates game via SteamCMD
- `InstallAndUpdateBepInEx()` - Updates mod loader
- `downloadAndInstallTsModsForWorld()` - Installs a world's mods from any catalogue
- `packageClient()` - Creates client payload ZIP
- `createSupervisorWorldConfig()` - Generates supervisor config

## Mods: the multi-source catalogue (2.43+)

Mods come from **more than one catalogue** — Thunderstore and Hexium — and a world may use
both. Read `dev_tools/DESIGN-2.43-multisource-mods.md` before changing anything here; it
records the four traps that were found by measuring the real feeds.

**Identity is `(source, owner, name)` — never the source's uuid.** Hexium mirrors
Thunderstore packages *carrying their original `uuid4`*: 600 package UUIDs exist in both
catalogues. `mods.source_uuid` is metadata, never a key. Anything that looks a mod up by a
bare UUID is wrong.

`mods.owner`, `mods.name` and `mod_versions.version` are `COLLATE utf8mb4_0900_as_cs` —
**case sensitive, and load-bearing.** Under MySQL's default `ai_ci`,
`IronTeam/Iron_ModPack` and `IronTeam/Iron_Modpack` are the same row and overwrite each
other on every sync; 22 such pairs exist on Thunderstore. Any new table joining these
columns needs the same collation or the join fails on a mixed-collation error.

**Tables:** `mods` (one row per source+owner+name, newest version denormalised onto it),
`mod_versions` (every published version, each with the source's own `download_url` —
Hexium's CDN path cannot be templated), `mod_deps` (resolved dependency edges, cross-source),
`world_mods` (a world's picks, `pin_version_id` NULL = follow latest), `mod_sync_runs`
(per-run progress and counts).

`tsmods` is **dead**, and so are `worlds.thunderstore_mods` / `thunderstore_mods_deps` —
kept only as a rollback record of what 2.43 migrated from. Nothing reads or writes them, and
a fresh install does not even create the table. If you find code touching either, it is a
bug: it will silently report zero mods, which is exactly how the world-card mod counts broke.

The whole pre-2.43 sync is gone: `tsSync*.sh`, `tsPrune.sh`, `tsModDepGetter.sh`,
`modLookup.sh`, `exportTsModsSeed.sh`, the `ts_wip` scratch dir, the 14 MB `tsmods_seed.sql`
GitHub seed and `tsSeeder()`. The catalogue is filled from the live APIs instead of a dump.

`syncModCatalogue` runs at **every** engine start, for both catalogues, backgrounded — not
just when the catalogue is empty. A fresh install builds in ~30s; a restart with nothing new
costs ~1s because Thunderstore answers 304 and Hexium's body hash matches. It passes
`--trigger boot` deliberately: `modSyncIntervalHours` is enforced for `trigger=cron` only, so
a boot sync runs even if cron ran minutes ago. It must never pass `--force` — that would turn
every container restart into a full refetch plus dependency rebuild.

There is no manual sync button and no stop endpoint. Syncing needs no supervision; the
Sync & Maintenance panel's per-catalogue link forces one.

**Sync:** `engine/tools/modSync.py`, hourly via `cron.d/modSync`, interval enforced from
`settings.modSyncIntervalHours` inside the script (cron cannot read the database). Change
detection short-circuits the common case: Thunderstore answers a conditional
`If-Modified-Since` with a bodiless 304, Hexium sends no validator so its body is hashed.
Each row carries a `content_hash` so only genuinely changed rows are written. A full cold
build of both catalogues is ~30s; a routine no-change tick is ~2s. Neither catalogue needs
an API key — both are public.

**A world's mods:** `engine/tools/worldMods.py --resolve | --plan | --viewer-json`.
`--plan` emits the install plan the engine loops over. Dependency resolution follows the
version that will *actually* be installed (the pin, if pinned), and dependency strings are
matched by **longest known `owner-name` prefix** — not by splitting on `-`, since owners
(`LVH-IT`, `sinai-dev`) and versions (`2.0.6-beta.1`) both contain hyphens.

**A world installs one copy per `(owner, name)`, not per mod id.** Both catalogues carry
`denikson/BepInExPack_Valheim` as separate `mods` rows, and modSync resolves each mod's
dependency to its *own* catalogue's copy — so a single Hexium pick in a world that also has
the three standard Thunderstore mods pulls in two BepInEx. They unzip into the same
`game/BepInEx` tree, so the surviving version depended on unzip order. `by_plugin()` collapses
them: an explicit pick beats a dependency, else newest version, else Thunderstore (canonical
upstream). `resolve()` applies it to the closure and `install_rows()` is the single source for
both `--plan` and `--viewer-json`, so the plan and the viewer can never disagree. Guarded by
`dev_tools/test-duplicate-plugin.sh`.

`world_mods` is keyed on `worlds.id`, so it **must** be cleared before a world's row is
deleted (`deleteWorldModRows`). InnoDB recomputes `AUTO_INCREMENT` as `MAX(id)+1` on
restart, so a leftover row can be inherited by a new world. `pruneOrphanedWorldMods` sweeps
at engine start.

PHP reads the catalogue through `includes/modcatalog.php`, never with ad-hoc SQL.

**Database queries**:
- `db_gets.php` - SELECT queries (getAllMods, getMyWorlds, etc.)
- `db_sets.php` - INSERT/UPDATE queries (deleteWorld, updateWorld, etc.)

**SQL wrapper**: `SQL()` function in `phvalheim-static.conf`

## Logs

- Engine: `/opt/stateful/logs/phvalheim.log`
- Worlds: `/opt/stateful/logs/valheimworld_<name>.log`
- Mod catalogue sync: `/opt/stateful/logs/modSync.log` (pre-2.43: `tsSync.log`)
- Backups: `/opt/stateful/logs/backups.log`

## Supervisor Commands

```bash
supervisorctl status                     # View all processes
supervisorctl stop valheimworld_myworld  # Stop world
supervisorctl start valheimworld_myworld # Start world
```

## Notes

- Modded worlds: access is gated by the CITIZENS list (`permittedlist.txt`); the server is
  started with `-public 0` and no `-password`. The "hammertime" literal in the launch string
  is historical and inert.
- Vanilla worlds (2.40+): real per-world `password`, `crossplay` and `listed` columns, applied
  by `startWorld.sh`. See `docs/RELEASE-2.40-DESIGN.md`.
- `worlds.public` is the CITIZENS access-control flag, NOT Valheim's `-public` server browser
  argument — that is the separate `listed` column. Do not conflate them.
- Access lists (`permittedlist.txt` / `adminlist.txt` / `bannedlist.txt`) live in the `-savedir`
  ROOT. The **database is the source of truth**: `syncAccessLists.sh` renders all three at every
  world start. Write them only via `writeAccessList()` (PHP) or that script — never
  `file_put_contents()` directly, and never ignore the return value. A silently-failed write is
  what made the CITIZENS editor look like it had stopped working while the UI said "saved".
- Valheim enforces `permittedlist.txt` **only when it has entries** — an empty file is *no
  restriction*, not "nobody may join". So an enforced-but-empty list is a **wide open server**
  whose Access tab claims otherwise. Nothing closes it at render time: a fail-closed placeholder
  entry was tried and deliberately removed. The protection is entirely up front —
  `createWorld` demands a first player ID, `saveCitizens` refuses an empty enforced list, and
  `syncAccessLists.sh` logs a WARNING at every world start for any world already in that state.
  **Never write an entry the operator did not supply**; if you are tempted to, read
  `dev_tools/test-create-access-guards.sh` first.
- Admin interface (8081) should never be exposed publicly
- Mod catalogues (Thunderstore + Hexium) sync hourly via cron; the effective interval is
  `settings.modSyncIntervalHours` (default 6h), enforced inside `modSync.py`
- World backups run every 30 minutes

# context-mode — MANDATORY routing rules

You have context-mode MCP tools available. These rules are NOT optional — they protect your context window from flooding. A single unrouted command can dump 56 KB into context and waste the entire session.

## BLOCKED commands — do NOT attempt these

### curl / wget — BLOCKED
Any Bash command containing `curl` or `wget` is intercepted and replaced with an error message. Do NOT retry.
Instead use:
- `ctx_fetch_and_index(url, source)` to fetch and index web pages
- `ctx_execute(language: "javascript", code: "const r = await fetch(...)")` to run HTTP calls in sandbox

### Inline HTTP — BLOCKED
Any Bash command containing `fetch('http`, `requests.get(`, `requests.post(`, `http.get(`, or `http.request(` is intercepted and replaced with an error message. Do NOT retry with Bash.
Instead use:
- `ctx_execute(language, code)` to run HTTP calls in sandbox — only stdout enters context

### WebFetch — BLOCKED
WebFetch calls are denied entirely. The URL is extracted and you are told to use `ctx_fetch_and_index` instead.
Instead use:
- `ctx_fetch_and_index(url, source)` then `ctx_search(queries)` to query the indexed content

## REDIRECTED tools — use sandbox equivalents

### Bash (>20 lines output)
Bash is ONLY for: `git`, `mkdir`, `rm`, `mv`, `cd`, `ls`, `npm install`, `pip install`, and other short-output commands.
For everything else, use:
- `ctx_batch_execute(commands, queries)` — run multiple commands + search in ONE call
- `ctx_execute(language: "shell", code: "...")` — run in sandbox, only stdout enters context

### Read (for analysis)
If you are reading a file to **Edit** it → Read is correct (Edit needs content in context).
If you are reading to **analyze, explore, or summarize** → use `ctx_execute_file(path, language, code)` instead. Only your printed summary enters context. The raw file content stays in the sandbox.

### Grep (large results)
Grep results can flood context. Use `ctx_execute(language: "shell", code: "grep ...")` to run searches in sandbox. Only your printed summary enters context.

## Tool selection hierarchy

1. **GATHER**: `ctx_batch_execute(commands, queries)` — Primary tool. Runs all commands, auto-indexes output, returns search results. ONE call replaces 30+ individual calls.
2. **FOLLOW-UP**: `ctx_search(queries: ["q1", "q2", ...])` — Query indexed content. Pass ALL questions as array in ONE call.
3. **PROCESSING**: `ctx_execute(language, code)` | `ctx_execute_file(path, language, code)` — Sandbox execution. Only stdout enters context.
4. **WEB**: `ctx_fetch_and_index(url, source)` then `ctx_search(queries)` — Fetch, chunk, index, query. Raw HTML never enters context.
5. **INDEX**: `ctx_index(content, source)` — Store content in FTS5 knowledge base for later search.

## Subagent routing

When spawning subagents (Agent/Task tool), the routing block is automatically injected into their prompt. Bash-type subagents are upgraded to general-purpose so they have access to MCP tools. You do NOT need to manually instruct subagents about context-mode.

## Output constraints

- Keep responses under 500 words.
- Write artifacts (code, configs, PRDs) to FILES — never return them as inline text. Return only: file path + 1-line description.
- When indexing content, use descriptive source labels so others can `ctx_search(source: "label")` later.

## ctx commands

| Command | Action |
|---------|--------|
| `ctx stats` | Call the `ctx_stats` MCP tool and display the full output verbatim |
| `ctx doctor` | Call the `ctx_doctor` MCP tool, run the returned shell command, display as checklist |
| `ctx upgrade` | Call the `ctx_upgrade` MCP tool, run the returned shell command, display as checklist |
