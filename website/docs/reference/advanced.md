# Advanced world options

## Custom launch parameters

Each world's **Settings** modal has a **Custom Launch Parameters** field, appended to the Valheim server command line after everything PhValheim generates, so it can override the defaults.

```
-saveinterval 900 -instanceid myserver
```

Shell metacharacters are rejected. Invalid Valheim arguments will stop the world from booting, with the reason only visible in the world log — change these one at a time.

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
