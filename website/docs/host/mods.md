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

Use the world's configuration controls for editable mod settings. Shared configuration goes into the client payload; server-only configuration stays on the server. Apply the changes and follow any update or restart notice in the UI.

| Folder | Purpose |
| --- | --- |
| `custom_configs/` | Shared files included in the client payload on world update |
| `custom_configs_secure/` | Server-only files preserved through updates |

## Catalogue sync

The public catalogues sync at startup and on the configured interval. The **Mod Catalogues** panel shows progress and a live log for each source. Expand the log when a source is slow or a package is missing.

[Catalogue settings and sync details →](../reference/catalogues.md)

## Use a mod you made

Publish a packaged version in Hugin's Vibes, then select it from the **Hugin's Vibes** catalogue here. Building or publishing a mod does not automatically change a world.

[Make, package and test a Vibe →](../vibes/first-mod.md)
