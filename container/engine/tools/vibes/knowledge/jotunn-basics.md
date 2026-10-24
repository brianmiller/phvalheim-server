# Jotunn basics
> When to use Jotunn (JotunnLib), what it gives you, how to depend on it, and the cost.

Jotunn is the common Valheim modding library (Thunderstore `ValheimModding-Jotunn`, GUID `com.jotunn.jotunn`). It wraps the fiddly, side-sensitive parts: adding items/pieces/recipes on both sides, custom localisation, server-synced config, input, GUI helpers, asset bundles.

Use it when you add CONTENT (items, pieces, recipes, status effects, custom prefabs). Do not use it for a small behaviour patch -- a plain Harmony plugin has no dependency and loads faster.

Declaring it:
```csharp
[BepInPlugin(PluginGuid, PluginName, PluginVersion)]
[BepInDependency(Jotunn.Main.ModGuid)]                 // hard dependency
[NetworkCompatibility(CompatibilityLevel.EveryoneMustHaveMod, VersionStrictness.Minor)]
public class Plugin : BaseUnityPlugin { ... }
```
manifest.json `dependencies` must then list `ValheimModding-Jotunn-X.Y.Z` (look the current version up in the admin's mod picker: it is in the Thunderstore catalogue). The csproj needs a reference to `Jotunn.dll`; the Build Pak's NuGet cache carries the `JotunnLib` package so `<PackageReference Include="JotunnLib" Version="2.*" />` restores offline -- confirm with a build.

Common calls (verify against the Jotunn version you reference):
- Server-synced config: `Config.Bind("Section", "Key", 1f, new ConfigDescription("...", null, new ConfigurationManagerAttributes { IsAdminOnly = true }))` -- Jotunn pushes admin-only entries from server to every client.
- Items: `ItemManager.Instance.AddItem(new CustomItem(prefab, fixReference: true, new ItemConfig { Name = "$my_item", Requirements = new[] { new RequirementConfig("Wood", 4) }, CraftingStation = CraftingStations.Workbench }))` inside a `PrefabManager.OnVanillaPrefabsAvailable` handler.
- Pieces: `PieceManager.Instance.AddPiece(new CustomPiece(prefab, fixReference: true, new PieceConfig { PieceTable = PieceTables.Hammer, ... }))`.
- Localisation: `Localization = LocalizationManager.Instance.GetLocalization(); Localization.AddTranslation("English", new Dictionary<string,string>{{"my_item","My Item"}});`
- Assets: `AssetUtils.LoadAssetBundleFromResources("mybundle")` (bundle as an EmbeddedResource) then `bundle.LoadAsset<GameObject>("MyPrefab")`.
- Commands: subclass `ConsoleCommand`, register with `CommandManager.Instance.AddConsoleCommand(new MyCommand())`.
- Input: `InputManager.Instance.AddButton(PluginGuid, new ButtonConfig { Name = "MyKey", Key = KeyCode.K })`, then `ZInput.GetButtonDown("MyKey")`.

Costs: every player needs the same Jotunn version (PhValheim installs it with the world, so that is handled); Jotunn's own patches touch many game methods -- read its changelog when the game updates. `NetworkCompatibility` refuses mismatched clients with a clear message instead of a desync, which is usually what you want.

Unverified here: the exact `ItemConfig`/`PieceConfig` property names for the Jotunn version in the pak; read the decompiled `Jotunn.dll` or its docs (valheim-modding.github.io/Jotunn) before relying on them.
