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
│  MariaDB (worlds, tsmods, settings tables)                  │
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

## Database Schema Updates

To add a database field, create `/container/engine/dbUpdates/dbUpdate_X.X.sh` and update the version in the Dockerfile. The engine runs `dbUpdater.sh` on startup which applies pending migrations.

## Key Code Paths

**World lifecycle** (in `0-functions.sh`):
- `InstallAndUpdateValheim()` - Downloads/updates game via SteamCMD
- `InstallAndUpdateBepInEx()` - Updates mod loader
- `downloadAndInstallTsModsForWorld()` - Fetches mods from Thunder Store
- `packageClient()` - Creates client payload ZIP
- `createSupervisorWorldConfig()` - Generates supervisor config

**Database queries**:
- `db_gets.php` - SELECT queries (getAllMods, getMyWorlds, etc.)
- `db_sets.php` - INSERT/UPDATE queries (deleteWorld, updateWorld, etc.)

**SQL wrapper**: `SQL()` function in `phvalheim-static.conf`

## Logs

- Engine: `/opt/stateful/logs/phvalheim.log`
- Worlds: `/opt/stateful/logs/valheimworld_<name>.log`
- Thunder Store sync: `/opt/stateful/logs/tsSync.log`
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
- Admin interface (8081) should never be exposed publicly
- Thunder Store mod metadata syncs every 12 hours via cron
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
