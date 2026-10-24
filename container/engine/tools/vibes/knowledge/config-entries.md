# Config entries
> ConfigEntry<T>, ConfigDescription, AcceptableValueRange/List, SettingChanged, where the .cfg lives and why PhValheim regenerates it.

```csharp
using BepInEx.Configuration;

internal static ConfigEntry<bool>  Enabled;
internal static ConfigEntry<float> StaminaCost;
internal static ConfigEntry<string> Mode;
internal static ConfigEntry<KeyboardShortcut> OpenKey;

void Awake() {
    Enabled     = Config.Bind("General", "Enabled", true, "Master switch.");
    StaminaCost = Config.Bind("Balance", "StaminaCost", 10f,
        new ConfigDescription("Stamina per use.", new AcceptableValueRange<float>(0f, 100f)));
    Mode        = Config.Bind("Balance", "Mode", "normal",
        new ConfigDescription("Which rule set.", new AcceptableValueList<string>("normal", "hard")));
    OpenKey     = Config.Bind("Keys", "OpenKey", new KeyboardShortcut(UnityEngine.KeyCode.K), "Client only.");
    StaminaCost.SettingChanged += (s, e) => Plugin.Log.LogInfo($"StaminaCost now {StaminaCost.Value}");
}
```

- Read with `.Value`; set with `.Value = x` (writes the file). `Config.Save()` forces a write; `Config.Reload()` re-reads.
- `KeyboardShortcut.IsDown()` for a one-frame press, `.IsPressed()` while held; check `Player.m_localPlayer != null && !Console.IsVisible() && !Chat.instance.HasFocus()` before acting.
- Supported T: bool, int, float, double, string, enums, KeyboardShortcut, Color, Vector2/3/4, Quaternion. Anything else needs a `TypeConverter` -- usually just store a string.
- Section/key must not contain `=`, `[`, `]`, newlines, or `\"`.
- The file is `BepInEx/config/<GUID>.cfg`, created on first run with your descriptions as comments.

PhValheim specifics (important):
- `BepInEx/config/` on a world is DERIVED: the engine wipes and regenerates it on every world update. Defaults in code are what a world actually runs unless the operator sets a *custom config* for the mod in the admin UI -- so choose defaults as if nobody will ever edit the file.
- Never persist mod DATA (save files, inventories) under `BepInEx/config` or `plugins`. Use the save directory (`Utils.GetSaveDataPath(FileHelpers.FileSource.Local)` or a path next to the world's `.db`) which the host preserves.
- A client-side option is only applied on the client's own copy; to enforce a value server-wide you need to sync it (see `server-vs-client`).
