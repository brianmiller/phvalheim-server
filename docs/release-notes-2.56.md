`theoriginalbrian/phvalheim-server:2.56` — also `:latest` and `:rc`, same image.

```
docker pull theoriginalbrian/phvalheim-server:latest
```

The headline is a **log system that knows which world it is talking about**, and a
**Server Health** strip on the dashboard that says, in plain words, what needs attention.
Around them: a crossplay join code that stops disappearing, a catalogue sync that had quietly
stopped running on every fresh install, and a tidier admin dashboard.

**No new client is needed.** Upgrade, and the schema migration runs on first boot.

## Logs that belong to a world

Everything PhValheim does — starting, stopping, updating and repackaging a world, backups,
automatic updates, your own clicks and Hugin's approved actions — is now recorded as an
**event** carrying the world it concerns and the **operation** it belongs to, instead of a line
in one shared engine log.

A world's **Logs** button opens a view with three tabs:

- **Activity** — what PhValheim did to this world, grouped by operation, each with an outcome
  in words: *Update done: update applied; world left stopped*. A marker shows where each
  operation began and where it ended.
- **Valheim** — the game's own log, this boot only by default.
- **Both** — merged by time, so an update reads as one story: the command, the mod install,
  the package, then Valheim loading the world.

The sidebar's four log links and the dashboard's "Additional Logs" card are replaced by one
**Logs** page: a server-wide activity stream with world, source and level filters, a recent
operations column, and every log file on disk. **Logs** pulses in the sidebar while there are
warnings or errors you have not looked at, and a world's Logs button pulses while PhValheim is
working on that world.

Hugin reads the same record. "Why won't my world start" now begins from what PhValheim
actually did, not from a guess. Events are kept for 30 days.

## Server Health

PhValheim now checks the things that used to fail silently: whether GitHub is answering
client download lookups, whether Steam still accepts your API key, whether the data volume can
be written, whether each mod catalogue's last sync and the last backup run succeeded, whether
the required settings are set, and whether recent errors need a look. Anything that needs
attention appears at the top of the dashboard with a button for what to do; **Server Health**
in the sidebar lists every check. The backup-retention warning that used to appear on the
players' page now appears here, where the person who can fix it will see it.

## Fixes

**A crossplay world could show "waiting for its join code" indefinitely** while it was up and
players could join on the code it was not showing. The code was only ever read from the world's
log, and once the log was rotated — within minutes on a busy modded world — it was gone until
someone joined. PhValheim now remembers each world's join code, and forgets it the moment the
world restarts, so a code from an earlier session is never shown.

**A log rotation could make an occupied world look empty to automatic updates.** The player
count was lost with the rotated log and recorded as a recent zero — which is what automatic
updates wait for before restarting a world. A count that can no longer be read is now treated
as unknown, which never allows a restart. Only mattered with automatic updates turned on.

**The hourly mod catalogue sync, and the dashboard's per-catalogue sync link, could silently
never run.** On a fresh install the engine's first sync created the sync log as root, and every
later sync — which runs as the service user — failed to open that log before it started, with
nothing to say so. Catalogues then only refreshed when PhValheim restarted. The log belongs to
the service user now, existing installs are repaired at the next start, and the sync link no
longer depends on it. The link also no longer reloads the page.

**A mod's config file could show under "Unmatched config files"** — with the mod listed as
having no config — on worlds set up before 2.55, for forks such as
`ValheimPlus_Grantapher_Temporary` whose package name looks nothing like its config file. The
settings always applied; only the label was wrong. PhValheim now reads every world's mods at
startup to learn which config each one writes. And when it still cannot tell, the Mod Configs
page now asks: a **Match to mod** list on the file, with **Undo**. Suggested on Discord.

**Players could be told "No published release is available right now"** when GitHub would not
answer PhValheim's release lookup, which it limits per internet address. PhValheim falls back to
GitHub's "latest release" page, which is not limited the same way.

**The log viewer would serve any file the web server could read** if asked for it by path. It
now serves only the log files in PhValheim's own log directory. The admin port should never be
exposed to the internet regardless.

## Dashboard

- The four header cards are one compact strip.
- The catalogue card is **Mod Catalogues**: one full-width row per catalogue. Its backup and
  log-rotation lines are gone, since Server Health reports a late run.
- Each world row has one row of buttons in a fixed order — Launch, Start, Stop, Mods, Update,
  Settings, Logs, Delete — instead of two columns that hid Settings and Delete behind a "…"
  menu.
- The sidebar is grouped into **Main**, **Server** (Logs, Server Health, Server Settings) and
  **Tools** (File Browser, Database Browser, Supervisor).
- The **Client Download URL** setting is gone. Nothing has used it since the download menu
  started offering the right installer per system, but leaving it blank showed players a false
  warning.

## Upgrading

Pull and restart. The migration adds the event tables and the join-code column on first boot.
No world needs recreating and no player needs anything.
