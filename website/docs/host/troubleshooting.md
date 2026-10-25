# Find the next useful clue

Start with the symptom. The dashboard's **Server Health** checks and a world's **Logs** usually point to the next action.

| What you see | What to check first |
| --- | --- |
| Admin UI unavailable just after an upgrade | Allow migrations to finish; inspect container logs if it persists |
| No worlds after an Unraid move or restart | Confirm the original data path is mounted; [check the appdata pool](../start/other-platforms.md#unraid) |
| World will not start | Open that world's Logs and inspect its latest start or update operation |
| Player cannot see the world | Steam sign-in and the world's Access list |
| Player sees a mod mismatch | World update completed, then client sync completed |
| Mod is missing from the picker | Catalogue filter, sync progress and per-catalogue sync log |
| Scheduled backups do not run | Separate backup volume, free space, schedule and activity requirement |
| Hugin cannot inspect anything | Provider connection and model tool support |
| Vibes cannot build | Build Pak, harvested game libraries and the Build tab diagnostics |
| Git fetch or push fails | Remote URL, token permissions and the branch state in History |

## A modded world crashes

Try a vanilla test world to check that the host and game work. Then narrow the mod collection. A successful download or compile does not prove that a mod is compatible with the current game or every other mod.

## Read the right log

World **Activity** groups PhValheim operations and their outcomes. **Valheim** contains the game's log. **Both** puts them together. The server-wide **Logs** page can filter by world, source and level.

[How logs work →](logs.md)

## Ask for help

[Discord](https://discord.gg/8RMMrJVQgy) is a good place for setup help. For a repeatable problem, [open an issue](https://github.com/brianmiller/phvalheim-server/issues) with:

- Your PhValheim version and deployment type.
- What you expected and what happened instead.
- The operation's error and the steps that reproduce it.

Remove passwords, API keys, access tokens and private player information before sharing logs or screenshots.
