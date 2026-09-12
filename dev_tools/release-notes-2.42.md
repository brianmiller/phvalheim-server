PhValheim 2.42 fixes one bug that silently disabled automatic backups on Unraid, and adds a short "What's New" notice so an upgrade no longer arrives without explanation.

```
docker pull theoriginalbrian/phvalheim-server:2.42
```

## Automatic backups stayed off on Unraid, while the UI said they were fine

If you run PhValheim on Unraid with a dedicated backup share, your automatic backups have not been running. The Server Settings panel showed `✓ dedicated volume` and the storage panel showed two volumes of different sizes, but `backups.log` repeated this at every scheduled run:

```
[WARN : phvalheim] Backup path (/opt/stateful/backups) is on the same volume as
/opt/stateful — no dedicated backup volume mounted. Automatic backups disabled.
```

Manual backups worked throughout, which is what made it confusing: the button was fine, the schedule was not.

The admin UI and the backup scheduler were asking two different questions. The UI asked the **mount table** — is `/opt/stateful/backups` a mount point? The scheduler compared **device identity**, taking the source column of `df` for the backup path and for `/opt/stateful` and calling them the same volume if the strings matched.

Device identity cannot see a bind mount. On Unraid, every `/mnt/user/<share>` is served by a single FUSE mount, so `df` reports the source as the literal string `shfs` for all of them — appdata and an 11T backup share alike, even though each reports its own size. `shfs` equalled `shfs`, the scheduler concluded the backup volume was not dedicated, and disabled itself. Every 30 minutes, for as long as the server had been running.

Both now use one shared check that reads the mount table, so the panel and the scheduler cannot reach opposite verdicts again.

**If you are on Unraid, check `backups.log` after upgrading** and confirm scheduled backups actually start. Any backups you already have are likely the ones you took by hand.

Thanks to **M-ike** for the report, the logs, and for confirming the fix on his own server.

## A short "What's New" notice after an upgrade

The admin UI now shows a one-time summary of what changed, the first time you open it after upgrading. Dismiss it and it stays gone until the next upgrade.

Skipping a release does not skip its notes — upgrading straight from 2.42 to 2.44 shows both. Fresh installs never see it, since there is nothing to describe as new, and it waits its turn behind the setup wizard rather than stacking on top of it.

This exists because 2.42's backup bug was invisible by design: the failure was a log line nobody had reason to read, on a schedule nobody was watching. A release that fixes something quiet should say so somewhere you will actually look.

Every future release is required to carry these notes — the build fails without them.
