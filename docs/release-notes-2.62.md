# v2.62 — Remote Access, player themes and redesigned settings

**Release candidate.** Use `theoriginalbrian/phvalheim-server:rc` for testing. The stable `latest` and `2.61` images remain unchanged. Client 2.0.18 is also a prerelease; ordinary client download buttons continue to select stable 2.0.17.

The image security gate still reports one existing HIGH finding in Tailscale's `github.com/klauspost/compress` dependency (CVE-2026-116591). Image content checks pass; this is not a clean security-gate result.

## Changes

- Built-in public website access through Tailscale Funnel or Cloudflare, with setup, connection status and diagnostics. Players do not need Tailscale. Existing private configurations require an explicit Publish action before becoming public.
- Independent login and player website themes: Classic, Classic Reforged, The Gate and Ember. Classic retains the original login screen. Classic Reforged clearly distinguishes offline worlds, shows active players, and places world selectors beneath selected-world details.
- A dedicated Server Settings page with categories, search, theme previews, clearer units, persistent Save/Discard controls and unsaved-change protection.
- A player mod library and personal configuration choices. Client 2.0.18 applies allowed web preferences at launch; forced operator values remain enforced.
- Steam overlay preferences for modded Linux launches, globally and per world, with Client 2.0.18. The overlay is enabled by default; the player's local disable still wins. Windows, macOS and vanilla launches retain Steam's own settings.
- Companion's connection dialog opens only after clicking **CONNECT: <worldname>**, including after menu navigation and failed or cancelled joins.
- Simpler Flatpak installation guidance and release-note links that follow the latest release.
- Correct installation of modded worlds with no selected catalogue mods, accurate failed-update reporting, and automatic recovery of interrupted catalogue sync records without PID-reuse false positives.
- Quieter analytics status and clearer local library/tool health in Hugin's Vibes.
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

1. Pull the `:rc` image and recreate the container with its existing data volume and settings.
2. Update each modded world to install the new Companion and rebuild its client download.
3. Quit Valheim and launch through the player website again. The main-menu CONNECT entry now opens the dialog.
4. Install the [Client 2.0.18 prerelease](https://github.com/brianmiller/phvalheim-client/releases/tag/2.0.18) to test overlay controls and personal web settings. Redownload/reinstall earlier 2.0.18 Linux candidates.

Updating a world in an old container continues to install the old Companion. No manual database cleanup is needed for interrupted catalogue syncs. Remote Access is configured separately; it is not silently enabled by upgrading.

## Validation

Server standalone suites, published-image world-update/HTTP-download checks and UI browser tests passed during development. Brian confirmed the corrected Flatpak overlay toggle on Bazzite Desktop Mode and the click-only Companion dialog in Valheim. These reports do not establish every OS, graphics-driver, Steam packaging or multiplayer scenario. Companion's original unpublicized client-assembly access check remains unavailable; the change introduces no new game API calls.

The remaining dependency finding above is tracked separately from functional validation. This prerelease does not promote the stable image or client release.
