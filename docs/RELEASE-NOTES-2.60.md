# PhValheim Server 2.60 — player preferences and Vibes limits

Prerelease preparation for testing. The tested image is
`theoriginalbrian/phvalheim-server:dev`, digest
`sha256:c2857341b9feabdbb5904fa3cf1f0f418ae44541aad78356c2d7ac57774e772e`.
The `:rc`, `:latest`, and stable version tags have not been promoted.

**Install PhValheim Client 2.0.17 or newer before updating a test world.** Older clients are
blocked once a world contains the new config policy; some report this as “world not found.”
The [client prerelease](https://github.com/brianmiller/phvalheim-client/releases/tag/2.0.17)
contains tested Windows MSI; Linux tarball, Debian, RPM and Flatpak; and universal
macOS tarball and PKG packages. Redownload to get the complete 2026-10-31 revision.

## Linux launcher follow-up

The revised Client **2.0.17** is the recommended Linux test client after a reported overlay startup
hang. Redownload its Flatpak or Linux tarball even if 2.0.17 is already installed. Direct modded
Linux launches leave the overlay off unless explicitly enabled with
`PHVALHEIM_ENABLE_OVERLAY=1`; `PHVALHEIM_NO_OVERLAY=1` still takes precedence.
The launcher now observes the host game process from Flatpak, rejects concurrent launches
before syncing, and closes its window after the game exits. Failed observation is reported.
The original Steam deadlock remains unconfirmed; live retesting on the affected laptop is
required. The revised Windows MSI was tested upgrading the original 2.0.17 MSI with
exact payload verification, repair, uninstall, and the full wizard.

## Config delivery and player preferences

- Config **Server** and **Client** are independent of where a mod is installed.
  Server delivery defaults on; Client delivery defaults off, including upgraded worlds.
- Untouched settings are **Overridable** by default. Players can use their preferred
  configuration tools, and their personal values survive full mod and config-only updates.
- Editing a value clears Overridable. Saving a shared setting enables Client delivery
  for its mod. Recheck Overridable after editing to supply a suggested default instead.
- Enforced values are reapplied before every launch, even without a download.
  Server-only values never enter the client policy. Server-only edits, resets, and
  rejected edits do not enable Client delivery.
- Bulk controls, tooltips, and save summaries explain the resulting behavior.
  Client-only mods can generate their config on a player's first launch; importing that
  `.cfg` discovers editable settings without automatically publishing personal values.
- Client updates stage extraction and merging before replacing the working install.
  Failed updates retain that install. Removed enforcement rules release their unchanged
  forced values so the mod can regenerate defaults.

A mod's own runtime synchronization may still control values during gameplay.
Existing saved operator choices are not retroactively rewritten by the automatic-delivery
refinement. See [the behavior guide](CONFIG-POLICY-2.60.md) for details.

## Vibes time and tool limits

Settings → Turn limits now separates **Tool rounds per turn**, **Turn time limit**, and
**Model response limit**. The default is now 100 rounds, with 15-minute turn and response
limits. Saved limits are preserved; choose Standard to adopt the new defaults. Increase
rounds for longer tasks; increase response and turn time for slower models.

Use the Turn limits shortcut beside Send, minute-based fields, or Standard / Long task /
Slow model presets. Review the fields and click Save limits. These settings apply to the
next turn, across Gemini, OpenAI-compatible/local endpoints, and Anthropic.

A round limit is not a timeout. Stopped turns explain which limit was reached and invite you
to send “Continue” using the saved project files. Stop interrupts a waiting model request,
including a silent stream. Compaction and negotiation retries share the remaining time budget;
partial interrupted tool calls are discarded. No automatic build or commit starts after a limit
or Stop. Running tools keep their own bounded timeouts; turn time is checked between tools.

## Validation and remaining tests

Vibes limits were checked with deterministic HTTP provider fixtures for Gemini,
OpenAI-compatible endpoints, and Anthropic, including real elapsed-time cutoffs and Stop.
Browser checks cover saved settings, limit validation, and reopening stopped sessions.
The existing Forge integration also completed nine tool reads and automatic compaction.
All 74 standalone server suites passed. Image verification passed and the build's Scout
scan reported zero critical/high findings.

Earlier config-delivery validation remains: 49 server and 26 browser checks on the prior
dev candidate, 26 actual client synchronization checks, and 6 archive-to-client checks.
Signed Windows installer and Linux executable checks passed. Those unchanged paths were
not rerun for the Vibes update. This does not reproduce the Discord user's exact Gemini
session or establish why that task consumed its round budget.
See [the validation record](WORKBENCH-2.60-VALIDATION.md) for scope and evidence.

Before publication, test a real player session:

1. Upgrade to Client 2.0.17, then update a test world on Server 2.60.
2. Change a personal setting. Confirm it survives a mod update and a config-only update.
3. Edit that setting in the admin UI. Confirm Client is enabled and Overridable cleared,
   then apply to players and confirm the value is reapplied on the next launch.
4. Recheck Overridable and confirm player changes persist. Test a fresh player receiving
   the supplied default and an existing player retaining their personal value.
5. Confirm Server-only settings stay private and client-only config import works after
   the mod's first launch. Check any mod-specific runtime synchronization separately.

All client package formats now have installer validation, including native Windows and
macOS checks. Live Unity/Steam gameplay is not yet validated. This draft does not announce
a stable release or a production deployment.
