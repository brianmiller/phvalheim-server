# PhValheim Server 2.60 — player config preferences

Prerelease preparation for testing. The tested image is
`theoriginalbrian/phvalheim-server:dev`, digest
`sha256:7c7c21eeb279b3091d5f760aea31e076647070e38318d419a17f9793040c4a9f`.
The `:rc`, `:latest`, and stable version tags have not been promoted.

**Install PhValheim Client 2.0.17 before updating a test world.** Older clients are
blocked once a world contains the new config policy; some report this as “world not found.”
The matching client prerelease is being prepared with the existing tested Windows MSI
and Linux tarball. macOS and additional Linux package formats remain pending.

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

## Validation and remaining tests

Final-image server checks: 49 passed; browser checks: 26 passed. All 74 standalone server
suites passed. Actual client synchronization checks: 26 passed; archive-to-client checks:
6 passed. Image verification passed and the build's Scout scan reported zero critical/high
findings. Signed Windows installer checks and Linux executable checks passed.
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

Live Unity/Steam gameplay and the new macOS package are not yet validated. This draft
does not announce a stable release or a production deployment.
