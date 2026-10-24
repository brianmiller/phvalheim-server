# Harmony patterns
> Prefix, Postfix, Transpiler; __instance, __result, ref, ___field injection; targeting overloads; what to avoid.

HarmonyX (BepInEx's fork, `0Harmony.dll`). Patches are static methods in a class carrying `[HarmonyPatch]`.

```csharp
[HarmonyPatch(typeof(Player), nameof(Player.OnSpawned))]          // verified: exists in current assembly
static class Player_OnSpawned_Patch {
    static void Postfix(Player __instance) { /* after the original */ }
}

[HarmonyPatch(typeof(Character), nameof(Character.Damage))]
static class Character_Damage_Patch {
    static bool Prefix(Character __instance, ref HitData hit) {     // return false = skip original
        if (!Plugin.Enabled.Value) return true;
        hit.ApplyModifier(0.5f);                                    // change an input in place
        return true;
    }
}

[HarmonyPatch(typeof(Inventory), nameof(Inventory.CanAddItem), new[] { typeof(ItemDrop.ItemData), typeof(int) })]
static class Inventory_CanAddItem_Patch {                           // OVERLOAD: name the parameter types
    static void Postfix(ref bool __result) { __result = true; }    // change the return value
}

[HarmonyPatch(typeof(Player), "UpdateFood")]                       // private method: string name
static class Player_UpdateFood_Patch {
    static void Postfix(Player __instance, ref float ___m_foodUpdateTimer) { }  // ___name = private FIELD (three underscores)
}
```

Injection names: `__instance`, `__result` (ref to change), `__state` (Prefix -> Postfix hand-off), `___fieldName`, `__originalMethod`, and any parameter by its exact name (`ref` to modify).

Rules of thumb:
- Postfix first, Prefix when you must change inputs or skip, Transpiler last (fragile across game patches).
- Overloads: an ambiguous name throws `AmbiguousMatchException` at `PatchAll` and the plugin loads with NO patches applied. Always pass the parameter type array when a method is overloaded (`Character.Message` grew a 5-argument overload; `Player.HaveRequirements` has two).
- `___field` type must match EXACTLY: a `float[]` field declared as `ref float` compiles and emits invalid IL; newer HarmonyX validates it and the patch silently never applies.
- Patching a method that does not exist is a runtime error at `PatchAll`, not a compile error, unless you use `nameof(Type.Member)` -- so always use `nameof` for public members and `game_source` to confirm private names.
- Guard the whole patch body with `if (!Plugin.Enabled.Value) return;` so the config switch means something.
- `[HarmonyPriority(Priority.Last)]`, `[HarmonyBefore("other.guid")]` order patches among mods.
- `Harmony.UnpatchSelf()` in `OnDestroy` keeps hot-reload tooling happy.
- Never patch Unity messages you can subscribe to instead (`ZNetScene.Awake` is fine; `MonoBehaviour.Update` on hot types is a frame-time cost on every player).
