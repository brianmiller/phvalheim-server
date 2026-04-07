# Changelog

## v2.38 — Backup System Modernization (Pre-release)

### Backup Engine
- **Activity-aware scheduling**: Backups only trigger when players have been online since the last backup (configurable)
- **Configurable intervals**: Set backup frequency per-server or per-world (default: 30 minutes)
- **Full world backups**: Archives the entire world directory (game data, mods, configs) instead of just Unity save files
- **Compression support**: Optional gzip or zstd compression with deferred scheduling (compress during off-peak hours)
- **Tiered retention**: Keep all backups for N hours, then daily, weekly, and monthly tiers — with per-world overrides
- **Performance tuning**: CPU priority (nice), I/O priority (ionice), and compression level controls to minimize impact on active players
- **Disk space preflight checks**: Backup and compression operations check available space before starting; graceful fallback to uncompressed if space is insufficient
- **Manual backups**: On-demand backup creation from the admin UI with real-time progress streaming
- **Transitional state protection**: Backups and restores are blocked while a world is starting, stopping, updating, or being deleted

### Restore
- **One-click restore**: Restore any backup from the admin UI with live progress
- **Pre-restore safety backup**: Automatically creates a safety snapshot before overwriting world data
- **Legacy format support**: Detects and correctly restores backups from pre-2.38 (Unity save path format)
- **Disk space checks**: Warns if insufficient space for the safety backup; skips safety backup rather than failing the restore

### Backup Management UI
- **Backup history table**: View all backups per world with date, type (scheduled/manual), size, compression status
- **Bulk operations**: Select and delete multiple backups at once
- **Download**: Download any backup directly from the browser
- **View details**: Inspect backup metadata including mod list and world settings at time of backup
- **Per-world settings**: Override global backup settings (interval, retention, compression, performance) per world
- **Orphan detection**: Automatically detects and flags backup records whose files are missing from disk

### Dashboard
- **Storage card**: Consolidated volume status showing all mounted volumes (data, backups) with usage bars, mount paths, and capacity warnings
- **Dedicated volume detection**: Uses `mountpoint` detection for accurate bind-mount identification
- **Orphan warnings**: Dashboard shows count of orphaned backup records with a one-click cleanup button
- **Dynamic updates**: Storage card refreshes after backup create, delete, and restore operations

### Backup Reconciliation
- **Startup reconciliation**: On container start, scans for orphaned DB records (files missing) and untracked backup files (files on disk with no DB record)
- **Auto-import**: Discovers backup files on disk that aren't tracked in the database and imports them
- **Orphan recovery**: If a previously orphaned file reappears (e.g., volume remounted), the orphan flag is cleared automatically
- **API endpoints**: Trigger reconciliation or purge orphaned records from the admin UI

### Infrastructure
- **New scripts**: `worldBackupCompress`, `worldBackupRetention`, `worldBackupReconcile`, `worldActivityMonitor`, `worldRestore`
- **New cron jobs**: Activity monitor (every 5 min), backup (every 10 min, self-gating), compression (hourly), retention (hourly)
- **Database migration**: New `backups` table, backup settings columns in `settings` and `worlds` tables
- **CSS tooltips**: Replaced native `title` tooltips with JS-powered tooltips that work inside modals

---

## v2.37 — macOS Apple Silicon BepInEx Fixes

- Ship patched MonoMod.RuntimeDetour.dll and BepInEx.Preloader.dll for macOS arm64
- Fixes MonoMod Harmony exceptions caused by MAP_JIT W^X enforcement on M-series chips
- All 8 BepInEx plugins now load with zero errors on macOS arm64

## v2.36 — macOS Client Support

- Cross-platform PhValheim Client (Windows, Linux, macOS)
- Universal `.pkg` installer for macOS (Intel + Apple Silicon)

## v2.35 — Setup Wizard Fix

- Fixed fresh install incorrectly showing settings modal instead of setup wizard
- Removed `TZ` from env var upgrade detection loop

## v2.34 — Anonymous Usage Analytics

- Optional anonymous usage analytics with opt-out
- Analytics dashboard at analytics.phvalheim.com

## v2.33 — Analytics Infrastructure

- Added analytics collection framework
- UUID-based installation tracking

## v2.31 — Setup Wizard & DB-Backed Settings

- All environment variables migrated to database settings
- Setup wizard for fresh installations
- Server Settings modal in admin UI
- Migration notice for upgraders

## v2.27 — Mod Pack Management

- Dependency resolution in PHP
- Two-table mod selection layout
- State-driven mod management architecture
