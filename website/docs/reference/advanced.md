# Advanced world options

## Custom launch parameters

Each world's **Settings** modal has a **Custom Launch Parameters** field. These arguments
are appended after PhValheim's generated arguments. Valheim decides how to handle duplicate
or unsupported options; do not assume a later argument overrides an earlier one.

```
-saveinterval 900 -instanceid myserver
```

Shell metacharacters are rejected. PhValheim does not validate whether each option is
supported by Valheim. Change these one at a time and check the world log and actual behavior.

## How the pieces fit

PhValheim runs its web server, PHP application, database, process supervisor and world-management engine in one container. Persistent state is mounted at `/opt/stateful`.

The public website handles Steam player sign-in and launch links. The private admin website controls worlds and configuration. The PhValheim client synchronizes a modded world's payload before launching Valheim. The in-game Companion provides the connection dialog and server telemetry.

The database is the source of truth for PhValheim-managed settings and records. Use the admin UI to manage them; generated files can be replaced during a world update or start.

## Project links

- [Server source](https://github.com/brianmiller/phvalheim-server)
- [Client source](https://github.com/brianmiller/phvalheim-client)
- [Companion source](https://github.com/brianmiller/phvalheim-companion)
- [Vibes Build Pak](https://github.com/brianmiller/phvalheim-vibes-buildpak)
- [Changelog](https://github.com/brianmiller/phvalheim-server/blob/master/CHANGELOG.md)
