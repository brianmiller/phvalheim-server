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

- World server password is hardcoded as "hammertime"
- Admin interface (8081) should never be exposed publicly
- Thunder Store mod metadata syncs every 12 hours via cron
- World backups run every 30 minutes
