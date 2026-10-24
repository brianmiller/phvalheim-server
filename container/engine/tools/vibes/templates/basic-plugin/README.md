# {{Name}}

{{Description}}

Made with Hugin's Vibes on a PhValheim server. BepInEx GUID: `{{Guid}}`.

## What it does

- Logs a line when the local player spawns (replace this with the real feature).
- Configurable in `BepInEx/config/{{Guid}}.cfg`: `Enabled`, `ExampleMultiplier`.

## Installation

Published to this server's local catalogue: add **{{Namespace}}-{{Name}}** to a world's mod list
in the PhValheim admin and click Update; players receive it with the world's client package.

Manual: drop `{{Name}}.dll` into `BepInEx/plugins/`.

## Building

```
dotnet build -c Release -p:VibesLibs=/path/to/valheim/libs -p:VibesPak=/path/to/buildpak
```

`VibesLibs` must contain the game's `Managed/` assemblies with `assembly_valheim_publicized.dll`;
`VibesPak/bepinex/` must contain `BepInEx.dll` and `0Harmony.dll`.
