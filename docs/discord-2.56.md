# PhValheim Server 2.56 is out 🪵

`docker pull theoriginalbrian/phvalheim-server:latest` — no new client needed, no world needs touching. Pull, restart, done.

**Logs that know which world they're about.** Every world's **Logs** button now opens a real view: **Activity** (what PhValheim did to that world, grouped by operation, each with an outcome in words — *Update done: update applied; world left stopped*), **Valheim** (the game's own log, this boot only), and **Both** merged by time, so an update reads as one story. One **Logs** page replaces the old four links and the "Additional Logs" card, and it pulses while there are warnings or errors you haven't looked at. Hugin reads the same record, so "why won't my world start" begins from what actually happened.

**Server Health on the dashboard.** The things that used to fail silently — Steam rejecting your API key, GitHub not answering client download lookups, a data volume that can't be written, a catalogue sync or backup that didn't succeed — now show at the top of the dashboard in plain words, with a button for what to do.

**Fixes worth knowing about:**
- 🔑 A **crossplay world could show "waiting for its join code" forever** while players were happily joining on it. The code only lived in the world's log, and a log rotation took it away. It's remembered now.
- 🔄 **The hourly mod catalogue sync wasn't running at all on fresh installs** — a permissions slip meant the catalogues only refreshed when PhValheim restarted. Fixed, and existing installs repair themselves at the next start.
- ⚙️ **Config files showing as "unmatched"** for forks like `ValheimPlus_Grantapher_Temporary` are filed under their mod now, and when PhValheim still can't tell, the Mod Configs page asks you — **Match to mod**, with Undo. Thanks to the person who suggested this here.
- 🛡️ A log rotation could make an occupied world look empty to automatic updates (only mattered with auto-updates on). Unknown is unknown now, and unknown never restarts a world.
- 🔒 The log viewer could be asked for files outside the log directory. It can't any more. (The admin port should never be exposed to the internet regardless.)
- ⬇️ "No published release is available right now" in the client download menu when GitHub rate-limited the lookup. There's a fallback now.

**And a tidier dashboard:** compact header strip, one row of buttons per world in a fixed order (no more "…" menu hiding Settings and Delete), the catalogue card is one full-width row per catalogue, and the sidebar is grouped into Main / Server / Tools.

Full notes: https://github.com/brianmiller/phvalheim-server/releases/tag/v2.56
