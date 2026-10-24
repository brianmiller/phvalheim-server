# BepInEx plugin anatomy
> What a Valheim BepInEx 5 plugin is: the attribute, the base class, lifecycle, logging, where files go.

Valheim runs BepInEx 5.4.x (Mono, .NET Framework 4.7.2). A mod is one DLL in `BepInEx/plugins/`.

```csharp
using BepInEx; using BepInEx.Logging; using HarmonyLib;

[BepInPlugin(PluginGuid, PluginName, PluginVersion)]       // GUID is the identity; never change it
[BepInDependency("com.jotunn.jotunn", BepInDependency.DependencyFlags.SoftDependency)] // optional
public class Plugin : BaseUnityPlugin                       // a MonoBehaviour: Awake/Start/Update exist
{
    public const string PluginGuid = "myserver.mymod";       // lowercase namespace.name
    public const string PluginName = "MyMod";
    public const string PluginVersion = "0.1.0";             // must be x.y.z (BepInEx parses it)
    internal static ManualLogSource Log;
    private Harmony _harmony;

    void Awake() {                                           // runs once when BepInEx loads the DLL
        Log = Logger;                                        // Logger is the plugin's own ManualLogSource
        _harmony = new Harmony(PluginGuid);
        _harmony.PatchAll();                                 // every [HarmonyPatch] class in THIS assembly
        Log.LogInfo($"{PluginName} {PluginVersion} loaded");
    }
    void OnDestroy() { _harmony?.UnpatchSelf(); }
}
```

Facts that matter:
- `Awake` runs before any game scene; `ZNet.instance`, `Player.m_localPlayer`, `ObjectDB.instance` are all null there. Hook game state with Harmony patches (e.g. `ZNetScene.Awake`, `ObjectDB.CopyOtherDB`, `Player.OnSpawned`), not from `Awake`.
- `Logger.LogInfo/LogWarning/LogError` go to `BepInEx/LogOutput.log` and the console. On a PhValheim world that is the world's Valheim log in the admin Logs page.
- Config: `Config.Bind(section, key, default, description)` returns `ConfigEntry<T>`; the file is `BepInEx/config/<GUID>.cfg`. See `config-entries`.
- The DLL's assembly name must equal the Thunderstore package `name`; the GUID is read from the attribute, not the file name.
- Only reference what the game ships: `assembly_valheim`, `assembly_utils`, `assembly_guiutils`, `UnityEngine.*`, `BepInEx`, `0Harmony`. Any other DLL must be copied into `plugins/` by the package (the Vibes packager includes non-framework DLLs found in `bin/`).
- `Private=false` on every reference in the csproj; the package must contain only your DLL(s).
- Class names in `global::` namespace: Valheim's own types (`Player`, `ZNet`) live in the global namespace, so `using` nothing is needed; avoid naming your own class `Game` or `Player`.
