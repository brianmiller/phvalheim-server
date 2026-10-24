# Server vs client
> What runs where on a PhValheim world, how to tell at runtime, and how to sync a config value from the server.

A PhValheim modded world installs the SAME mod list on the dedicated server and in every player's client package (each mod has Server/Client destination switches in the admin; by default both). Your DLL therefore usually runs in two very different processes:

| | dedicated server | client |
|---|---|---|
| `ZNet.IsDedicated()` | true | false |
| `ZNet.instance.IsServer()` | true | true only when hosting locally |
| `Player.m_localPlayer` | **null, always** | the player after character load |
| Unity UI, `Hud`, `InventoryGui`, `Camera.main`, `FejdStartup` | **absent / null** | present |
| `ObjectDB`, `ZNetScene`, `ZDOMan`, `ZRoutedRpc` | present | present |
| `MessageHud`, `Chat` UI | null | present |

Rules:
1. Any code touching UI, input (`Input.GetKey`, `KeyboardShortcut`), `Camera`, `Hud`, `Player.m_localPlayer` must be guarded: `if (ZNet.IsDedicated()) return;` or `if (Player.m_localPlayer == null) return;`. A NullReferenceException inside a Harmony patch on the server is logged every frame and can stall the world.
2. Authority: the owner of a `ZNetView` runs its logic (`m_nview.IsOwner()`); players own their own character and nearby objects. World rules you want enforced for everyone belong on the server (`ZNet.instance.IsServer()`) with an RPC to clients, or in ZDO data clients read.
3. Spawning/prefab changes (`ObjectDB` recipes, new items) must happen identically on both sides or clients desync ("failed to find prefab" / item vanishes).
4. Syncing a config value from server to clients, without a library: on the server, in a `ZNet.OnNewConnection`/`ZNet.RPC_PeerInfo` Postfix, `peer.m_rpc.Invoke("MyMod_Config", pkg)`; on the client `peer.m_rpc.Register<ZPackage>("MyMod_Config", ...)` applies it and marks local config read-only. The ServerSync source file (blaxxun) does this generically and is embedded (not referenced) by many mods; Jotunn's `ConfigurationManagerAttributes { IsAdminOnly = true }` does it declaratively (see `jotunn-basics`).
5. Admin checks: `ZNet.instance.ListContainsId(ZNet.instance.m_adminList, peer.m_socket.GetHostName())` -- the server's `adminlist.txt`, which PhValheim renders from the database at every world start.
6. The dedicated server has no `Update` of your `BaseUnityPlugin` disabled; it runs headless at the server tick. Keep per-frame work tiny.
7. Logging on the server lands in the world's Valheim log (admin → World logs → Valheim). Prefix lines with your mod name so the operator can grep them.
