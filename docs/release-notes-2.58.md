`theoriginalbrian/phvalheim-server:2.58` — also `:latest` and `:rc`, same image.

```
docker pull theoriginalbrian/phvalheim-server:latest
```

The headline is a **real player count**: the PhValheim Companion that ships with the server
now reports the dedicated server's own player roster, and that is what the dashboard, the
public page and the automatic-update idle check use — nothing is scraped out of a log any
more. Around it: a **running world can be updated** from its row through a dialog that shows
the engine's log as it works, a proper **Delete** dialog, a Docker Hub vulnerability list cut
from 2,408 findings to 48 (none critical or high), and a run of fixes operators have reported.

**No new client is needed.** Upgrade, and the schema migration runs on first boot. Existing
worlds pick up the new Companion at their next update or Rebuild Mods — until then their
count shows nothing rather than a guess.

## What changed

**The player count is real.** Companion 1.0.5 writes the server's roster every five seconds;
PhValheim reads it every two minutes. The status pill reads `Running 👤 3`, its tooltip says
"3 players online" and where the figure came from, Settings → Updates shows the same, and the
per-world "Show players on the public page" switch puts it on the world's public card. A count
is shown only while a world is running. A vanilla world runs no Companion and shows none.

**A running world can be updated.** The Update button is no longer greyed out while a world is
up. It opens a dialog that says what will happen — stop the world, update Valheim and the
mods (pinned mods untouched), start it again — warns you with the live count if players are on
it, and then streams the engine's own log until the world is back. Closing the dialog does not
stop the update; the same stream is under the world's Logs button. A stopped world gets the
same dialog and stays stopped afterwards.

**Delete has a proper dialog.** It lists what goes, notes that backups are kept, and asks for
the world's name before the button works.

**Automatic updates have lost their beta warning.** The idle check rests on the Companion's
real count. A vanilla world can never be seen as quiet; its Updates tab says so and tells you
to give it a maintenance window with "If players never leave: update anyway".

**The Docker Hub vulnerability list.** 2.57 listed 2,408 findings. 2,290 of them were Linux
kernel headers pulled in by a `-dev` package the crossplay runtime never needed, and most of
the rest came from tools the image never ran and a 132 MB PHP vendor tree nothing referenced.
2.58 installs the three runtime libraries the crossplay library actually links, drops the
rest, and the build refuses to publish if a critical or high finding appears. The image is
958 MB instead of 1.25 GB.

## Fixes

- **Hugin crashed on anything that read a log** (issue #93) — "any world broken?", "full health
  check" and the like returned nothing in 2.56 and 2.57. Three tools handed Hugin structured
  data and the conversation loop expected text. Every tool result is text now.
- **The Logs badge would not clear.** The sidebar count stayed up however often Logs was
  opened: the acknowledgement compared event ids as text, so once ids passed three digits it
  never moved. It is per event now; opening Logs clears what you see, and opening a world's
  Logs clears that world's.
- **The update badge outlived the update, and never said which mod.** A world updated by hand
  is re-checked straight away, and the badge's tooltip and the Updates tab name the mods.
- **"Update available: 3.5.8 → "3.5.8"".** A catalogue entry whose version carried literal
  quotes could never match the installed one. Version strings are cleaned on read, and stored
  ones repaired on upgrade.
- **A world with no password could fail to start with "Missing arguments"** once its start
  program was regenerated. The program carries only the name and port now, and a world whose
  program is missing gets it regenerated at start instead of being marked broken.
- **The catalogue sync bar sat at 80% for the whole of the real work.** It moves through the
  dependency graph now, which is what takes the time.
- **A running world's row was taller than a stopped one.** The count lives in the status pill
  and the update badge on the Settings button; the Resources column holds two items.
- **The mod-sync log pane vanished during every sync** (a 2.56 regression).

## Operators

- The migration drops the short-lived `playerCountsEnabled` setting from the mid-2.58 `:dev`
  builds; the count is always on.
- `playerMonitor` no longer reads the world log for the count; the join code is still read
  from it, which is the only place Valheim prints it.
- The container test bucket is a release gate now: 77 tests run against the pushed image.

Full details per change, with the file names and verify markers, are in `CHANGELOG.md`.
