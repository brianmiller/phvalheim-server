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
whether the required settings are set, and whether the configured Cloudflare or Tailscale
public website is connected and verified. Remote Access warnings link directly to its setup
and diagnostics. An intentionally disconnected or unconfigured provider does not warn.
Verification older than five minutes is marked unknown until you run Test connection.
Analytics is excluded from Server Health and warning badges; diagnostic records remain in Logs. **Server Health** in the sidebar lists every check, including the ones that are fine.
Nothing here is a heartbeat: the checks run when the dashboard asks, and the two that touch the
network (GitHub, Steam) are cached so a dead network costs one short wait every five minutes,
never one per page load.

## Analytics enrollment in 2.62 development

The 2.62 development branch includes authenticated analytics enrollment. When
analytics is enabled, the server enrolls automatically and keeps a private
credential at `/opt/stateful/analytics/credential.json`. Preserve that file with
your private server backups; do not copy it into a different installation.

The development image identifies itself as **2.62**. Its startup also handles
inherited TrueNAS filesystem permissions automatically for the private analytics
directory. An earlier development image could show `HTTP 000` before making a
request because that directory inherited shared permissions. Update the container
while keeping its existing data volume; no world rebuild is needed.
The sender also identifies itself as PhValheim to avoid an HTTP 403 rejection of
Python's generic HTTP client by the analytics service's edge filter.

The existing 2.61 release does not include this transport. The receiver acknowledges
and discards unauthenticated reports from versions through 2.61 so their daily
check completes without an analytics health warning. A warning left by the security
cutover clears after the next daily push. This acknowledgment does not mean the
report was recorded; collection resumes with authenticated enrollment in 2.62.
Worlds and player connections are unaffected, and analytics can still be disabled
in settings. A version string by itself is not a credential.
