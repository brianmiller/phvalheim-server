# PhValheim in one minute

**Host. Mod. Sync. Play.** A quick tour of the whole workflow: multiple worlds, per-world options and schedules, mod selection, shared configuration and client syncing—with backups and Hugin along for the adventure.

<video class="phv-tour" controls playsinline preload="metadata" poster="../../assets/tour-poster.png" aria-label="PhValheim full product tour">
  <source src="../assets/phvalheim-tour.mp4" type="video/mp4">
  <track kind="captions" src="../assets/phvalheim-tour.vtt" srclang="en" label="English">
  Your browser does not support embedded video. Download the tour below.
</video>

[Download the vertical MP4](../assets/phvalheim-tour.mp4){ .md-button }
[Set up your server](install.md){ .md-button .md-button--primary }

Real interface captures and animated workflow diagrams, condensed into 60 seconds. This is a product overview, not a real-time installation, sync or gameplay recording. Captions and the guide below work with sound off.

## Follow the adventure at your own pace

| Time | What PhValheim does | Next step |
| --- | --- | --- |
| 0:00 | Runs multiple worlds, each with its own mods and settings. | [Explore the guides](../index.md) |
| 0:05 | Hosts vanilla and modded worlds in one Docker container. | [Install](install.md) |
| 0:10 | Starts, stops and updates worlds separately from one dashboard. | [Create your first world](first-world.md) |
| 0:15 | Configures server type, crossplay, passwords, visibility and launch parameters per world. Console players cannot load mods. | [World options](../host/vanilla.md) |
| 0:20 | Sets per-world update windows, quiet periods, timeout behaviour and backup-first options. | [Update policies](../host/updating.md) |
| 0:25 | Selects mods from three catalogues and pins versions. | [Choose your mods](../host/mods.md) |
| 0:30 | Edits each world's mod configurations and preserves saved overrides through updates. | [Tune the settings](../host/mods.md#change-configurations) |
| 0:35 | Resolves dependencies and packages mods and shared configurations. Server-only configs stay private. | [Build the shared setup](../host/mods.md) |
| 0:40 | Syncs changed files through the PhValheim client before launching a modded game. | [Join a world](../play/join.md) |
| 0:45 | Sets backup schedules and retention, manages access lists, admins and bans, and provides logs. | [Protect your worlds](../host/backups.md) |
| 0:50 | Helps with Hugin; writes, builds and packages mods in Vibes for your local catalogue and test worlds. | [Make your first Vibe](../vibes/first-mod.md) |
| 0:55 | More worlds. Less admin. Host, configure, mod, sync and play. | [Start hosting](install.md) |

For a mod-list change, save the selection and update the world, then have players sync before joining. For shared config changes, **Save & apply to players** rebuilds the config payload; players receive it on their next launch. Vanilla worlds use ordinary Valheim without the PhValheim client. Player-aware update policies require Companion telemetry; vanilla worlds need a scheduled window and an appropriate timeout policy. AI features are optional.

## Soundtrack and sharing

**Music: Viking Intro (Loop) by [Alexander Nakarada](https://creatorchords.com)**. Licensed under [Creative Commons Attribution 4.0 International (CC BY 4.0)](https://creativecommons.org/licenses/by/4.0/). [Original track and licensing](https://creatorchords.com/music/viking-intro-loop/). Edited to length, normalized and faded for this tour.

This is a modern Viking-inspired cinematic track. If you share the video on Discord, YouTube or another service, include the music credit above in its description. [Download the ready-to-paste credit](../assets/phvalheim-tour-credits.txt).
