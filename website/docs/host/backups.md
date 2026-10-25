# Back up and restore

PhValheim includes a full backup system with activity-aware scheduling, compression, tiered retention, and one-click restore.

## Backup Storage

Mount a **separate volume** for backups to keep them isolated from game data:

```yaml
volumes:
  - /path/to/data:/opt/stateful:Z
  - /path/to/backups:/opt/stateful/backups:Z   # separate disk recommended
```

If no dedicated backup volume is detected, the admin UI will display a warning and automatic backups are disabled. Manual backups can still be created.

## Scheduling

| Setting | Default | Description |
|---|---|---|
| **Backup Interval** | `30 min` | How often scheduled backups run. The cron runs every 10 minutes but self-gates based on this interval. |
| **Require Player Activity** | `Yes` | Only create backups when players have connected since the last backup. Prevents redundant backups of idle worlds. |

## Compression

| Setting | Default | Description |
|---|---|---|
| **Compression** | `None` | Algorithm: `none` (uncompressed tar), `gzip`, or `zstd`. Zstd is faster with better compression ratios. |
| **Compression Schedule** | `3:00 AM` | Hour to run deferred compression. Set to `Immediate` to compress at backup time. Deferred mode reduces CPU impact during active hours. |
| **Compression Level** | `0` (default) | Higher levels = smaller files but more CPU. `0` uses each algorithm's default level. |

> **Disk space note:** Compression requires temporary space for both the uncompressed tar and the compressed output (~2x world size). If insufficient space is available, the backup is saved uncompressed with a warning.

## Retention Policy

Backups are pruned automatically using a tiered retention policy. Manual backups are never auto-pruned.

| Tier | Default | Description |
|---|---|---|
| **Keep All** | `24 hours` | Every backup within this window is kept. |
| **Daily** | `7 days` | After the keep-all window, one backup per day is retained. |
| **Weekly** | `30 days` | After the daily tier, one backup per week is retained. |
| **Monthly** | `6 months` | After the weekly tier, one backup per month is retained. |

## Performance Tuning (Backups)

These settings control how aggressively backup operations use system resources. Lower priority = less impact on active players.

| Setting | Default | Description |
|---|---|---|
| **CPU Priority** | `Low (10)` | `nice` value for tar and compression. `Normal (0)` = full speed, `Low (10)` = reduced, `Lowest (19)` = minimal. |
| **I/O Priority** | `Low` | `ionice` class. `Idle` = backups only use disk when the game server isn't reading/writing. `Normal` = no throttling. |

## Per-World Overrides

Each world can override the global backup settings. In the world settings modal, switch to the **Backups** tab and uncheck **Use Global Defaults** to configure per-world intervals, retention, compression, and performance settings.

## Restore

Choose a backup in the world's Backups tab and follow the restore confirmation. Restoring replaces the world's files; review the selected world and timestamp carefully.

PhValheim stops the world before restoring, attempts a safety backup when space permits, extracts the archive and fixes file ownership. It queues a rebuild and leaves the restored world stopped; wait for that rebuild to finish, then start it from the dashboard. Transitional states can block a restore until the current operation finishes.

## Reconciliation

On startup, PhValheim reconciles backup records with files on disk:

- **Orphaned records**: DB entries pointing to missing files are flagged as orphaned.
- **Untracked files**: Backup files on disk with no DB record are discovered and imported.
- **Recovery**: If an orphaned file reappears (e.g., backup volume remounted), the orphan flag is cleared.

Orphaned records are shown in the dashboard Storage card and in the per-world backup table. Use the **Clean up** button to purge orphaned records.
