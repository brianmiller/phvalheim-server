# Config delivery in 2.60

Installation and configuration are independent. Installed on controls where the mod
runs. Config controls where PhValheim supplies operator settings. Server starts enabled;
Client starts disabled, including worlds upgraded from 2.59. Existing saved values remain
in the database. Saving a shared setting enables Client for its matched mod, without changing
Server delivery or installation. Server-only edits, resets, and rejected edits do not enable
Client. Settings whose owner is unknown remain stored until matched and saved.

Untouched settings start Overridable. Editing a value clears Overridable; an operator can
recheck it after editing to offer a suggested default. Pasted values also default to enforced.
A save without an explicit flag uses enforcement for a new or changed value, while a re-save
of the same value preserves its flag. Existing stored choices are not retroactively changed. A supplied overridable value fills a missing
client setting; an existing player value wins. A non-overridable value is reapplied by
Client 2.0.17 before every launch. This is configuration management, not an anti-cheat
boundary. A mod's own runtime configuration synchronization can also control its values.
Server only always excludes that entry from the client policy, whatever the mod switches.

Players edit their ordinary BepInEx config using their preferred tool. The client preserves
these files across full mod updates and small config updates. Loader/connection/world-seed
files remain managed by PhValheim. When an enforced entry is removed, its last enforced
value is removed if it is still unchanged, allowing the mod to generate its default.
Turning off Client delivery releases enforcement and keeps personal settings. Removed mods
leave inactive config files behind so reinstalling them can recover personal preferences.

Run a server-installed mod once to discover its settings. Client-only mods must first run
on a player machine; import their generated .cfg through the config editor. Import supplies
the editable shape, not an automatic decision to publish the player's values. Save settings
and apply to players. The save summary reports any automatically enabled Client delivery.
Recheck Overridable after editing when existing personal values should win.

The policy is packaged inside both archives under BepInEx/config, so it shares the config
checksum and cannot change between downloading the settings and reading their rules.
Client installation uses a staging directory and rolls back on failure. Old servers retain
their legacy sync contract. The new model requires Client 2.0.17; older clients cannot
preserve personal files during their destructive extraction.

The setting editor and per-key policy apply to BepInEx .cfg files. Other package assets in
BepInEx/config keep their existing distribution path as initial files; existing personal
files also win for these assets. They do not acquire per-key enforcement or editor widgets.
Old launchers receive HTTP 426 once a world publishes the new policy. Some old versions
translate that into “world not found”; the operator notice explains the required upgrade.
