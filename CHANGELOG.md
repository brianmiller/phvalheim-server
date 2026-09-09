# Changelog

## v2.40-rc — Vanilla Servers, Admins, Custom Launch Parameters

Release candidate. Held at `:rc` pending the Valheim 1.0 (Deep North) boss trophy prefab name — see "Known gaps" below.

### Features
- **Vanilla (zero-mod) worlds** ([#81](https://github.com/brianmiller/phvalheim-server/issues/81)): A world can now be created as vanilla — stock Valheim, no BepInEx, no mods, no client payload. Players join with the ordinary Valheim client, so no PhValheim client install is needed. New per-world settings: **server password** and **list in the public server browser** — these are vanilla-only, since modded worlds are gated by the CITIZENS list exactly as before.
- **Crossplay**, on every world: lets Xbox / Microsoft Store players join. This is *not* a vanilla-only setting — it is orthogonal to whether a world runs mods, and lives in every world's **Settings** modal. (It was briefly scoped vanilla-only during development; that was a mistake.)
- **Bespoke public UI card for vanilla worlds**: shows the endpoint, a click-to-reveal password, access badges, and a Join button (`steam://run/892970//+connect`). Vanilla Valheim has no launch argument to pre-fill a server password, so showing it on the card is what makes the world joinable.
- **Custom launch parameters** per world, appended after everything PhValheim generates so they can override it. Validated in the admin UI and never `eval`'d by `startWorld.sh`.
- **ADMINS editor** under CITIZENS in the world Settings modal, writing `adminlist.txt`. Entries are validated as SteamID64 — Valheim silently ignores malformed ones, which previously looked like "I added an admin and nothing happened."
- **Boss registry** (`includes/bosses.php`): the boss list is now defined once and consumed by the API, the public card and the AJAX refresh. Adding a boss is one array entry, one DB column and one PNG.
- **Password visibility control**: a per-world toggle (`password_public`) removes the password row from the public card entirely. It is also stripped from the AJAX payload, not just hidden in the markup.
- **Copy button** next to Show on the vanilla card's password, with a non-secure-context fallback — `navigator.clipboard` is undefined over plain HTTP, which is how most self-hosted installs are reached on a LAN.

### Vanilla world refinements
- Card now reads `Type: unmodded`.
- The **HEALTH** bar is hidden for vanilla worlds in the admin UI. Tick health comes from the TickMonitor BepInEx plugin, which a vanilla world does not run, so the bar could only ever sit empty.
- **Edit Mods is disabled** for vanilla worlds, and `edit_world.php` refuses them server-side — the page is reachable by URL regardless of the button. Switching a world to vanilla now clears its mod selection, so flipping back later does not resurrect a list you thought you had removed.
- Offline vanilla cards grey out fully. The access badges set their own background, so they needed their colour **replaced** rather than faded — opacity alone left a tinted pill on an otherwise grey card.
- The seed control is hidden when creating a vanilla world, replaced with an explanation. Custom seeds require the CustomSeed BepInEx mod and Valheim itself has no seed argument, so the field could only ever be ignored.
- **Vanilla worlds no longer display a fabricated seed.** Creating one stored a seed that could never reach Valheim — the game invents its own when it first generates the `.fwl` — and the public card rendered that stored value as though it were the world's real seed. Nothing failed; the number was simply wrong. Vanilla worlds now store no seed at creation, the card shows *generated on first start*, and the engine reads the real seed back out of the `.fwl` once the world has started (same extraction `importWorld.sh` has always used on uploaded saves, verified against real Valheim saves).

### Access lists (CITIZENS / ADMINS / BANNED)
- **The CITIZENS and ADMINS editors could report a save that never reached the disk.** Both wrote their file with `file_put_contents()` and ignored the return value, so a write that failed — most commonly a list file left owned by `root` by an older engine or by a restore — returned `{"success":true,"message":"Saved successfully"}` while the file kept its previous contents. The database and the file then disagreed permanently: `permittedlist.txt` went on gating the world with a stale list, so players the operator had just added were refused, and `adminlist.txt` looked like it was never written at all. Writes are now atomic (temp file + `rename`, which needs permission on the *directory* rather than the target file, so it also repairs a root-owned file on the next save) and **every failure is reported instead of swallowed**.
- **Nothing ever rewrote these files from the database.** The admin UI was the only writer, at the moment Save was pressed. A world that was restored from a backup, or rebuilt, kept whatever list files came with it. `syncAccessLists.sh` now regenerates all three from the database at every world start, making the database the single source of truth and letting drift heal itself on the next restart.
- **New BANNED list**, editing `bannedlist.txt`, alongside CITIZENS and ADMINS in the world Settings modal. A ban applies even when the world is public.
- **`worldDirPrep` only ever created `permittedlist.txt`.** `adminlist.txt` and `bannedlist.txt` were left for Valheim to create on first boot, so the admin UI was editing files that did not exist yet. All three are now created with the world.
- **An imported world's admins existed only on disk.** `importWorld.sh` wrote `adminlist.txt` directly but never set the `admins` column, so the Settings modal showed no admins for an imported world — and under the new sync those admins would have been dropped at the next start. It now writes the database and renders the files from it.
- Citizens are validated as SteamID64 the same way admins already were. Valheim silently ignores anything else in these files, which is indistinguishable from "I added them and nothing happened".
- File headers now match what Valheim itself writes byte-for-byte, including the double space in the admin and banned headers.

Verified against the real Valheim dedicated server: it reads and writes all three lists in the `-savedir` root, creates any that are missing at startup, and does not overwrite entries written from outside — neither before it starts nor while it is running. `dev_tools/test-accesslists.sh` covers the above end to end against a live container.

### Fixes
- **`-public` was hardcoded to `0`** in `startWorld.sh`, and `-password` was never passed at all despite being accepted as an argument. No world has ever been listed or password protected.
- **`worlds.public` is not a "public server" flag.** It is the CITIZENS access-control flag — when set it blanks `permittedlist.txt`. Valheim's `-public` argument is now driven by a separate `listed` column, so worlds that were opened to all citizens are not silently published to the global server browser on upgrade.
- **Trophy tooltip drift**: the AJAX refresh said "The Seeker Queen" where the server-rendered card said "The Queen", so the tooltip changed on first refresh. Both now come from the registry.
- **SQL injection in `setHungHeads()`**: the world name and trophy column were interpolated into SQL from an unauthenticated POST body. Both are now bound/validated against the registry.

### Client (2.0.13)
- Understands vanilla worlds via a new optional 8th launch-string field and launches them with Valheim's `+connect`, skipping the world sync and BepInEx entirely. Older servers that send 7 fields still work.
- Removed the dead `ProgressBar/` directory — 6 files, 524 of 1591 lines, all declaring `namespace UpdateHOB` and referenced by nothing.

### Known gaps
- The Deep North boss is **not yet registered**: its trophy prefab name is unknown until the 1.0 release. `public/api.php` now logs any unrecognised `Trophy*` POST to `phvalheim.log`, so the first hung head anywhere reveals the name. Landing it is one entry in `includes/bosses.php`, one uncommented line in `dbUpdate_2.40.sh`, and one PNG.
- Custom seeds need a BepInEx mod, so vanilla worlds always generate a random seed.

## v2.39 — Modpack Rebuild Boot Fix

First stable release of the 2.38 backup system work, plus a fix for worlds failing to boot after a modpack rebuild.

### Fixes
- **World boot failure after modpack rebuild** ([#80](https://github.com/brianmiller/phvalheim-server/issues/80)): Mod zips packaged on Windows can store directories without the execute bit. `unzip` preserves that, leaving BepInEx unable to traverse the extracted plugin directories and aborting startup with a fatal `UnauthorizedAccessException`. `u+rwX` is now restored after Thunderstore extraction and after custom mods/configs/patchers installs (`cp -p` preserves the bad source permissions the same way).
- **Backup/restore progress behind Cloudflare Tunnel**: Replaced streaming progress with background jobs plus polling, which Cloudflare Tunnels do not buffer.
- **NGINX FastCGI buffering**: Disabled for streaming progress endpoints.
- **Backups table schema**: The `orphaned` column is now present in the initial table creation, not only in the migration path.

### Included from v2.38 (previously pre-release)
The full backup system modernization — activity-aware scheduling, tiered retention, compression, one-click restore, backup management UI, and startup reconciliation. See the v2.38 notes below for details.

---

## v2.38 — Backup System Modernization (Pre-release)

### Backup Engine
- **Activity-aware scheduling**: Backups only trigger when players have been online since the last backup (configurable)
- **Configurable intervals**: Set backup frequency per-server or per-world (default: 30 minutes)
- **Full world backups**: Archives the entire world directory (game data, mods, configs) instead of just Unity save files
- **Compression support**: Optional gzip or zstd compression with deferred scheduling (compress during off-peak hours)
- **Tiered retention**: Keep all backups for N hours, then daily, weekly, and monthly tiers — with per-world overrides
- **Performance tuning**: CPU priority (nice), I/O priority (ionice), and compression level controls to minimize impact on active players
- **Disk space preflight checks**: Backup and compression operations check available space before starting; graceful fallback to uncompressed if space is insufficient
- **Manual backups**: On-demand backup creation from the admin UI with real-time progress polling
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
- **Cloudflare Tunnel compatibility**: Backup/restore progress uses background jobs with polling instead of streaming, which Cloudflare Tunnels buffer

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
