# Versioning and the GUID
> The GUID never changes; three places must agree on the version; when to bump what; tags.

**GUID** = `<namespace>.<Name>` lower-cased, e.g. `myserver.nomovementpenalty`. It is:
- the BepInEx plugin id (`[BepInPlugin(GUID, ...)]`), used for `BepInDependency` by other mods,
- the Harmony instance id (patch ownership),
- the config file name `BepInEx/config/<GUID>.cfg` on every player's machine,
- what `ZNet`-level compatibility checks and many tools key on.
Changing it after publishing orphans every config file and breaks every mod that depends on yours. **Never change it.** Renaming the mod means a new mod.

**Version** lives in three places that must agree -- the `manifest` tool keeps them in step when you set `version_number`; if you edit by hand, edit all three:
1. `manifest.json` → `version_number` (what the catalogue and worlds see),
2. `<Name>.csproj` → `<Version>` (what the DLL's assembly version says),
3. `src/Plugin.cs` → `public const string PluginVersion` (what BepInEx logs and compatibility checks use; must parse as `x.y.z`).

When to bump:
- **patch** (0.1.0 → 0.1.1): bug fix, no behaviour change players would notice as a feature.
- **minor** (0.1.1 → 0.2.0): new feature, new config key, changed defaults.
- **major** (0.2.0 → 1.0.0): config keys removed/renamed, save-data format changed, dependency added that players must install.
Bump BEFORE publish, not after: the catalogue's `(mod, version)` is unique, and re-publishing the same number replaces the zip under players who already have it -- fine while iterating privately, wrong once a world has shipped it.

Publish tags the git repository `v<version>`; `push` sends tags with the branch.

Dependencies in `manifest.json` pin a version string, but a world installs whatever its own catalogue resolves (latest of that dependency unless pinned by the operator). State the minimum you tested in README.

Compatibility over game updates: Valheim's assembly changes often; a method you patch by string name may vanish or gain an overload. After a game update re-harvest (Settings → Libraries) and build again; `nameof` makes a renamed public member a compile error instead of a silent no-patch.
