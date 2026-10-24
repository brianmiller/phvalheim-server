# PhValheim Server 2.58 is out 👤

`docker pull theoriginalbrian/phvalheim-server:latest` — no new client needed. Pull, restart, and the migration runs on first boot. Worlds pick up the new Companion at their next update or Rebuild Mods.

**The player count is real.** The PhValheim Companion on each world's server now reports the server's own roster. The status pill reads `Running 👤 3`, the tooltip says exactly what it is and where it came from, and the same number drives Settings → Updates, the public page (per world, if you switch it on) and the automatic-update idle check. Nothing is scraped out of a log any more.

**Update a running world.** The Update button works while a world is up. A dialog tells you what will happen — stop, update, start again — warns you if players are on it, and then shows the engine's log line by line until the world is back. Close it whenever you like; the update carries on and the world's Logs button shows the same stream.

**Delete asks properly.** What goes, that backups are kept, and the world's name typed back before the button lights up.

**Automatic updates lose their beta badge.** The idle check rests on the real count now. A vanilla world has no Companion and can never look quiet, so its Updates tab tells you to give it a maintenance window with "update anyway".

**Docker Hub's vulnerability list: 2,408 → 48**, none critical or high. Almost all of it was kernel headers and tools the image never ran. The image is 958 MB instead of 1.25 GB, and the build now refuses to publish if a critical or high finding appears.

**Fixes:** Hugin crashed on anything that read a log in 2.56/2.57 (#93); the Logs badge never cleared; the update badge outlived the update and never said which mod; a phantom "3.5.8 → "3.5.8"" update; a no-password world could fail to start after its start program was regenerated; the catalogue sync bar stuck at 80%; a running world's row was taller than a stopped one; the mod-sync log pane vanished during syncs.

Release notes: https://github.com/brianmiller/phvalheim-server/releases/tag/v2.58
