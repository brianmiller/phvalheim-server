using BepInEx;
using BepInEx.Configuration;
using BepInEx.Logging;
using HarmonyLib;

namespace {{Name}}
{
    /// <summary>
    /// {{Description}}
    ///
    /// Made with Hugin's Vibes (PhValheim). The GUID is "{{Guid}}" -- it names this mod to
    /// BepInEx and keys every player's config file, so it must never change once published.
    /// </summary>
    [BepInPlugin(PluginGuid, PluginName, PluginVersion)]
    public class Plugin : BaseUnityPlugin
    {
        public const string PluginGuid = "{{Guid}}";
        public const string PluginName = "{{Name}}";
        public const string PluginVersion = "{{Version}}";

        // Static so patches (which are static) can log and read config without an instance.
        internal static ManualLogSource Log;
        internal static ConfigEntry<bool> Enabled;
        internal static ConfigEntry<float> ExampleMultiplier;

        private Harmony _harmony;

        private void Awake()
        {
            Log = Logger;

            // Config entries appear in BepInEx/config/{{Guid}}.cfg on first run. PhValheim
            // regenerates that directory on every world update, so defaults here are what a
            // world actually gets unless the operator adds a custom config.
            Enabled = Config.Bind("General", "Enabled", true,
                "Turn the whole mod off without uninstalling it.");
            ExampleMultiplier = Config.Bind("General", "ExampleMultiplier", 1.0f,
                new ConfigDescription("An example number the patch below logs. Replace it with something real.",
                    new AcceptableValueRange<float>(0f, 10f)));

            if (!Enabled.Value)
            {
                Log.LogInfo($"{PluginName} {PluginVersion} is disabled by configuration.");
                return;
            }

            // One Harmony instance per plugin, keyed on the GUID so patches can be found and
            // removed by id. PatchAll picks up every [HarmonyPatch] class in this assembly.
            _harmony = new Harmony(PluginGuid);
            _harmony.PatchAll();
            Log.LogInfo($"{PluginName} {PluginVersion} loaded.");
        }

        private void OnDestroy()
        {
            _harmony?.UnpatchSelf();
        }
    }

    /// <summary>
    /// A harmless example: runs after the local player has spawned into the world.
    /// Player.OnSpawned(bool spawnValkyrie) exists in the current game assembly (the compiler
    /// verifies the name through nameof). On a dedicated server there is no local Player, so
    /// this never fires there -- which is what you want for a client-side message.
    /// </summary>
    [HarmonyPatch(typeof(Player), nameof(Player.OnSpawned))]
    internal static class Player_OnSpawned_Patch
    {
        // Postfix: the original ran, we observe. Prefer this over Prefix or Transpiler unless
        // you must change inputs or skip the original (see knowledge: harmony-patterns).
        private static void Postfix(Player __instance)
        {
            if (!Plugin.Enabled.Value || __instance == null) return;
            if (__instance != Player.m_localPlayer) return;
            Plugin.Log.LogInfo($"{Plugin.PluginName}: {__instance.GetPlayerName()} spawned (ExampleMultiplier = {Plugin.ExampleMultiplier.Value}).");
        }
    }
}
