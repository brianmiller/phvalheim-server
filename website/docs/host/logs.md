# Logs and server health

PhValheim records management actions as **events** with the world they concern and the
**operation** they belong to (a start, stop, update, create, delete or repackage, each with an
outcome). A world's **Logs** button opens three tabs: **Activity** (PhValheim's actions on that
world, grouped by operation), **Valheim** (the game's own log, this boot only by default) and
**Both**, merged by time. The sidebar's **Logs** page is the server-wide stream, with filters
for world, source and level, and a **Files** tab listing every raw log on disk. **Logs** pulses
while there are warnings or errors you have not looked at; a world's **Logs** button pulses while
PhValheim is working on it. Hugin reads the same record. Event retention is 30 days with a
cap of roughly 100,000 rows; raw files rotate at the configured log size (1 MB by default).

## Server Health

The dashboard checks the things that used to fail silently and shows anything that needs
attention at the top of the page, in plain words, with a button for what to do: whether GitHub
is answering client download lookups, whether Steam still accepts your API key, whether the data
volume can be written, whether each mod catalogue's last sync and the last backup run succeeded,
whether the required settings are set, and whether the daily analytics push went through (if
enabled). **Server Health** in the sidebar lists every check, including the ones that are fine.
Nothing here is a heartbeat: the checks run when the dashboard asks, and the two that touch the
network (GitHub, Steam) are cached so a dead network costs one short wait every five minutes,
never one per page load.

## Analytics enrollment in 2.62 development

The 2.62 development branch includes authenticated analytics enrollment. When
analytics is enabled, the server enrolls automatically and keeps a private
credential at `/opt/stateful/analytics/credential.json`. Preserve that file with
your private server backups; do not copy it into a different installation.

The existing 2.61 release does not include this transport. Older senders can report
an Analytics failure because the receiver now requires enrollment credentials.
This does not affect worlds or player connections. A version string by itself is
not a credential. Server Health reports whether an upload was accepted; disabling
analytics remains available in settings. Enrollment support will ship with 2.62.
