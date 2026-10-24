# Thunderstore manifest and package
> The exact zip layout the engine installs, manifest.json rules, dependency strings, how publish works here.

A Vibes package is a Thunderstore-layout zip, because that is what PhValheim's engine already installs for every world (it unzips into `BepInEx/`). `package` produces `dist/<Namespace>-<Name>-<version>.zip` containing exactly:

```
manifest.json
README.md
CHANGELOG.md        (optional)
icon.png            (256x256 PNG, required)
plugins/<Name>.dll  (+ any extra non-framework DLL the build produced)
```
Nothing else: no `bin/`, no `.pdb`, no game or BepInEx assemblies (the csproj's `Private=false` keeps them out of `bin/`).

manifest.json:
```json
{ "name": "MyMod", "version_number": "0.1.0", "website_url": "",
  "description": "One line, 250 chars max.", "dependencies": ["denikson-BepInExPack_Valheim-5.4.2202"] }
```
- `name`: `^[A-Za-z][A-Za-z0-9_]+$`, fixed at creation (it is the assembly name and part of the GUID). Use the `manifest` tool to change the other fields; never edit `name`.
- `version_number`: strictly `x.y.z` digits. Bump it whenever behaviour changes; the `manifest` tool also updates the csproj `<Version>` and `PluginVersion`.
- `description`: single line, ≤ 250 characters (the catalogue shows it on the card).
- `dependencies`: `Owner-Name-X.Y.Z` strings. Owners and versions may contain hyphens, so the catalogue resolves them by the LONGEST known `Owner-Name` prefix. Keep BepInExPack as the first entry; add Jotunn or others only if the DLL actually references them. A dependency the catalogue cannot resolve is stored but shows as unresolved; the world still installs your mod.

Publish (`publish` job) copies the zip to `/opt/stateful/vibes/catalog/`, upserts the catalogue rows (`mods.source='vibes'`, owner = namespace, categories `["Hugin's Vibes"]`), inserts the version with `download_url = file:///opt/stateful/vibes/catalog/<zip>` and resolves dependency edges. The engine's install loop copies a `file://` URL instead of fetching it -- the only engine change Vibes made. Re-publishing the same version replaces the file (the fix-and-ship loop); worlds following "latest" pick it up on their next Update. Unpublish refuses while a world pins that version.

The package is also valid for a real Thunderstore upload (the operator does that by hand; not in 2.59). Thunderstore additionally requires the icon to be exactly 256x256 and the README to be Markdown.
