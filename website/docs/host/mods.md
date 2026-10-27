# Mods and configurations

A world's mod selection is the shared setup its players receive. Change the selection, update the world, then have players sync with the launcher.

## Find and select mods

The picker offers **Thunderstore**, **Hexium** and **Hugin's Vibes**. The last contains mods you publish locally from the workbench.

Use the catalogue buttons to narrow the list. Search and sorting stay in place when you toggle them. At least one catalogue stays enabled. **Selected mods remain visible**, even when you turn off their source, so you can still review or remove them.

## Keep a version, or follow the latest

Choose a specific version in a selected mod's row to pin it. **Latest (auto)** follows new versions when the world is updated. Saved pins remain in the database even if a catalogue later delists that version.

Save your selection and **Update** the world to build its payload. The UI shows the work in progress. After it finishes, players should sync again before joining.

!!! tip "Try changes on a test world first"
    Dependency resolution cannot guarantee that two mods behave well together. For a large change or a new Valheim version, test a small collection first.

## Change configurations

Config delivery is separate from **Installed on**. For each selected mod, **Config Server**
starts on and **Config Client** starts off. Save the mod selection to update the world.
The bulk controls apply to all selected mods, including other pages and search results.

Run a mod once before editing its settings. Mods installed on the server generate their
config there. For a client-only mod, run the game once and import its `.cfg` through that
mod's config editor. Importing makes settings editable without publishing the player's values.

Untouched settings start **Overridable**. Editing a value clears that flag, and saving a
shared setting enables **Config Client** for its mod. Recheck Overridable after editing to
offer a suggested default instead. With Overridable enabled, this supplies a value
only when the player does not already have one. Players can use their preferred config tool;
their choices survive both mod updates and config-only updates. Clear Overridable to reapply
the operator's value before every launch. **Server only** excludes that setting from the
client policy regardless of the mod's delivery switches.

Use **Save & apply to players** to send client settings at the next launch. Server changes
require a restart. A mod's own runtime synchronization can still control settings in game.
These per-setting controls apply to BepInEx `.cfg` files.

**Client 2.0.17 or newer is required.** Upgrade players before updating a world with 2.60.
Older launchers are blocked before they can overwrite personal configs; some show this as
“world not found”. Existing worlds also start with Client delivery off after upgrading;
previously saved operator settings remain available. Saving a shared setting enables delivery
for its mod; Server-only edits and resets leave the Client switch unchanged.

## Catalogue sync

The public catalogues sync at startup and on the configured interval. The **Mod Catalogues** panel shows progress and a live log for each source. Expand the log when a source is slow or a package is missing.

[Catalogue settings and sync details →](../reference/catalogues.md)

## Use a mod you made

Publish a packaged version in Hugin's Vibes, then select it from the **Hugin's Vibes** catalogue here. Building or publishing a mod does not automatically change a world.

[Make, package and test a Vibe →](../vibes/first-mod.md)
