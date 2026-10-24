# Testing a mod
> Why a green build proves nothing, what you CAN verify from here, and how the operator tests on a real world.

A green `dotnet build` proves the code compiles against the publicized assembly. It does not prove: the patched method exists at runtime with that signature (string-named private methods), the member you call is actually public (publicizer trap), the patch applies (`AmbiguousMatchException`, `___field` type mismatch), or that the behaviour is right in game. Say so in your answer; do not report a build as "working".

What you can verify from the workbench, and should:
- Every game type/member you reference: `game_types` (exists, public?) and `game_source kind=member` (signature, overloads, whether it is private -- the decompiled source shows the real access modifier; the publicized DLL does not).
- Overloads: if `game_source` shows two methods with the same name, your `[HarmonyPatch]` MUST carry the parameter types.
- Side safety: grep your own code for `Player.m_localPlayer`, `Hud.`, `InventoryGui.`, `Camera.`, `Input.`, `KeyboardShortcut` and confirm each is behind a client guard (`server-vs-client`).
- Package contents: after `package`, `shell: unzip -l dist/<zip>` -- exactly manifest.json, README.md, CHANGELOG.md, icon.png, plugins/<Name>.dll.
- Logging: every code path you cannot exercise should log once at Info with the mod name, so the operator's world log answers "did it run?".

How the operator tests (you cannot start a world; say these steps plainly):
1. `publish` the Vibe (bumping the version first).
2. Admin → Worlds → a modded test world → Mods → add `<Namespace>-<Name>` → Save → Update. Watch the world's Activity log for the install lines (the `file://` copy and "Installing").
3. Start the world. Read Admin → World logs → Valheim for `[Info   : <Name>]` lines from your `Awake`, and for `[Error  : HarmonyX]` lines -- those are patch failures.
4. Join with the PhValheim client (it fetches the client package with your DLL) and exercise the feature. Ask the operator for the exact in-game observation that would confirm it ("the tooltip still shows the movement line", "stamina does not drop when...").
5. For a server-side-only change, the client needs nothing new; still re-join so the client receives the current config.

Iterating: fix → build → bump patch version → publish → the operator clicks Update (worlds following "latest" pick the new zip up) → restart the world. Record what was tested and what was not in `.vibes/notes.md` with the `note` tool.

Unit tests: a net472 class library cannot host the game. If logic is pure (math, parsing), put it in a static class with no game types and test it by reasoning or a tiny `dotnet test` project the operator approves; do not add test frameworks to the mod csproj (they would ship).
