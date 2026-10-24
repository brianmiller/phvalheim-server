# PhValheim worlds
> How a PhValheim world installs a mod, the per-mod Server/Client switches, why BepInEx/config is regenerated, custom configs, where saves live.

PhValheim is the host this mod runs under. A world is a directory `/opt/stateful/games/valheim/worlds/<name>/` with `game/` (the dedicated server files: `valheim_server_Data/Managed/*.dll` is where your reference assemblies were harvested from) and the save directory INSIDE `game/`.

How a mod reaches players:
1. The operator adds the mod to the world's mod list in the admin (the picker lists Thunderstore, Hexium and -- once published -- Hugin's Vibes packages as ordinary mods).
2. On Update, the engine resolves the dependency closure (`worldMods.py --plan`), downloads each package zip (for a `file://` URL it copies the catalogue file), and unzips into the world's `BepInEx/` tree. **One copy per (owner, name)**: duplicates across catalogues are collapsed.
3. Each mod has **Server** and **Client** destination switches. Server-only mods never ship to players; client-only mods are not installed on the dedicated server. Default is both. A mod that must run on both sides (RPCs, synced rules) needs both; a pure UI mod should be client-only.
4. The engine then packages the client payload (the whole client `BepInEx/` tree plus a manifest) and players' PhValheim client downloads it. The payload IS the server's live tree for client-destined mods -- there is no second copy to keep in sync.

Things the engine does that affect mod design:
- **`BepInEx/plugins`, `BepInEx/config` and `BepInEx/patchers` are deleted and regenerated on every world update.** Defaults in code are what runs. The operator can set a *custom config* per mod in the admin, which the engine writes after regeneration. Never persist mod data under `BepInEx/`.
- Saves live under the world's `game/` save directory and survive updates; mod data belongs there (next to the `.db`) or in `Utils.GetSaveDataPath(...)`.
- The world starts under supervisor with `-public <0|1>`, a password (Valheim enforces it only with `-public 1`), crossplay and the three access lists (`permittedlist.txt`, `adminlist.txt`, `bannedlist.txt`) rendered from the database at every start. Your mod must not rewrite those files.
- `adminlist.txt` is what `ZNet.instance.m_adminList` reads -- use it for admin-only features.
- A world's Valheim log is `/opt/stateful/logs/valheimworld_<name>.log` (admin → World logs). BepInEx's `LogOutput.log` is in the world's `BepInEx/`.
- Nothing in Hugin's Vibes starts, stops or edits a world. To test, the operator adds the published mod to a world and clicks Update, then starts it from the dashboard (see `testing-a-mod`).
- Vanilla worlds carry no mods at all; a published Vibe can only be added to a modded world.
