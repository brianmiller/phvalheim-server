# Bundled Companion

Source: [phvalheim-companion fd02cf42bf3f323dba2cb8ff4d7f4325d153c95f](https://github.com/brianmiller/phvalheim-companion/commit/fd02cf42bf3f323dba2cb8ff4d7f4325d153c95f), [PR #7](https://github.com/brianmiller/phvalheim-companion/pull/7).

Built with `dotnet build -c Release`, assembly version 1.0.6 (unchanged).
SHA256: `8705a9b960a48956e1756e116c8feccb4c8891dce92a71bcc42e54332e6dfa54`.

The connection dialog stays closed until the player clicks **CONNECT: <worldname>**.
Returning to the main menu or failing/cancelling a join leaves the menu entry available
without reopening the dialog. Launch-payload and manifest-only paths both use this gate.

Operators must pull/recreate the server container, update each modded world, then have
players launch through the website again. Updating a world in an older container keeps
installing that container's older Companion. Already-running games must be restarted.

Validation: Release build, click-transition tests and built-assembly reachability checks.
Original unpublicized client-assembly access checks and real Valheim UI behavior remain
unverified; this revision introduces no new game API calls. PR #7 remains unmerged.
