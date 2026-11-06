# v2.62 — Remote Access, player themes and redesigned settings

Stable release. `theoriginalbrian/phvalheim-server:latest` and `:2.62` carry it. [Client 2.0.18](https://github.com/brianmiller/phvalheim-client/releases/tag/2.0.18) is released alongside; Client 2.0.17 remains the minimum.

**Security scan.** Docker Scout lists one HIGH finding in the bundled Tailscale 1.104.5 binaries: CVE-2026-116591, a crash in the zstd decoder of `github.com/klauspost/compress` 1.20.0. No Tailscale release carries the fixed library yet. It is assessed as not exploitable in this image: Tailscale decodes zstd only from its own coordination server, over its authenticated control channel, and from asset files built into the binary. Website visitors and tailnet peers cannot supply that input. The worst case, a hostile coordination server, is a restart of the optional Tailscale connector. The finding stays in the raw scan; the [assessment](security/REMOTE-ACCESS-SCANNER.md) is a reachability judgment, not a patched library.

## Changes

- The Gate world chooser wraps large world lists into readable rows instead of squeezing all names into one desktop row. For this layout fix alone, pull/recreate the container and reload the player website; no world rebuild or client update is needed.

- Built-in public website access through Tailscale Funnel or Cloudflare, with setup, connection status and diagnostics. Players do not need Tailscale. Existing private configurations require an explicit Publish action before becoming public.
- Independent login and player website themes: Classic, Classic Reforged, The Gate and Ember. Classic retains the original login screen. Classic Reforged clearly distinguishes offline worlds, shows active players, and places world selectors beneath selected-world details.
- A dedicated Server Settings page with categories, search, theme previews, clearer units, persistent Save/Discard controls and unsaved-change protection.
- A player mod library and personal configuration choices. Client 2.0.18 applies allowed web preferences at launch; forced operator values remain enforced.
- Steam overlay preferences for modded Linux launches, globally and per world, with Client 2.0.18. The overlay is enabled by default; the player's local disable still wins. Windows, macOS and vanilla launches retain Steam's own settings.
- Companion's connection dialog opens only after clicking **CONNECT: <worldname>**, including after menu navigation and failed or cancelled joins.
- Simpler Flatpak installation guidance and release-note links that follow the latest release.
- Correct installation of modded worlds with no selected catalogue mods, accurate failed-update reporting, and automatic recovery of interrupted catalogue sync records without PID-reuse false positives.
- Quieter analytics status and clearer local library/tool health in Hugin's Vibes.
- Hugin Setup fetches the current model list from your endpoint, with a Refresh action and
  the time of the last successful fetch; a failed refresh keeps the saved selection. Each
  provider has Limits for context and response size, compaction, timeouts, concurrent
  requests and queueing, applied to both Hugin and Hugin's Vibes.
- Longer Hugin's Vibes turns: 300 tool rounds and 60 minutes by default, configurable up to
  10,000 rounds and 24 hours. Saved limits equal to an earlier default or preset (40, 100 or
  200 rounds; 15 or 30 minutes) move to the new defaults; other saved limits are kept.
- Long Vibes conversations open quickly and no longer go blank: a 3,845-event session took
  5.7 seconds to open and now takes under 0.1; the chat is not reloaded after each turn,
  build, package or publish.
- A Vibe's library card no longer keeps its spinning ring and "turn…" after the turn ends.
- After a turn stops at a limit, Send works without reloading the page. The same fix covers
  any turn longer than about 20 seconds, and a turn whose live connection dropped.

## Update

1. Pull `:latest` (or `:2.62`) and recreate the container with its existing data volume and settings.
2. Update each modded world to install the new Companion and rebuild its client download.
3. Quit Valheim and launch through the player website again. The main-menu CONNECT entry now opens the dialog.
4. Install [Client 2.0.18](https://github.com/brianmiller/phvalheim-client/releases/tag/2.0.18) for overlay controls and personal web settings. If you installed a 2.0.18 Linux candidate before November 6, download and install it again.

Updating a world in an old container continues to install the old Companion. No manual database cleanup is needed for interrupted catalogue syncs. Remote Access is configured separately; it is not silently enabled by upgrading.

## Validation

Server standalone suites, published-image world-update/HTTP-download checks and UI browser tests passed during development. Brian confirmed the corrected Flatpak overlay toggle on Bazzite Desktop Mode and the click-only Companion dialog in Valheim. These reports do not establish every OS, graphics-driver, Steam packaging or multiplayer scenario. Companion's original unpublicized client-assembly access check remains unavailable; the change introduces no new game API calls.

The dependency finding above is assessed separately from functional validation.
