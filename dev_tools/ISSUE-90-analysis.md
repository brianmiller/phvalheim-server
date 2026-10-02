# Issue #90 — OneMapToRuleThemAll "Incompatible version" — analysis

**Verdict: not a PhValheim bug.** The mod ships its own connection gate that rejects peers with
Valheim's `ErrorVersion`, and Jötunn repaints any `ErrorVersion` as its mod-comparison popup.
Every detail in the report follows from that, including the table where all rows match.

## Evidence chain (all reproducible from artifacts, no live client needed)

1. **Mod is real.** `thunderstore.io/api/experimental/package/DrummerCraig/OneMapToRuleThemAll/`
   → 200, latest `2.8.1`. Two fabricated control names → 404, so the 200 is not a proxy artifact.

2. **OneMap has zero Jötunn integration.** `manifest.json` declares `"dependencies": []`; the DLL
   contains no `jotunn` / `NetworkCompatibility` / `CompatibilityLevel` string at all.
   → It can never be a row in Jötunn's table. Matches "OneMap does not appear in the table".

3. **It patches the handshake with a displacing prefix**
   (`ilspycmd`, class `VersionGate`, `all.cs:18564`):
   ```csharp
   [HarmonyPatch(typeof(ZNet), "RPC_PeerInfo")]
   private static bool Prefix(ZRpc rpc, ZNet __instance) {
       if (__instance.IsServer()) {
           if (!_validatedPeers.Contains(rpc)) {
               rpc.Invoke("Error", new object[1] { 3 });   // <-- rejects the peer
               return false;                                // <-- vanilla PeerInfo never runs
           }
           return true;
       }
       ...
   }
   ```
   `_validatedPeers` is populated **only** by `OnServerReceiveClientVersion`, which requires the
   client to send RPC `OM_Version` whose payload is *exactly* `"2.8.1"` (ordinal compare).

4. **`Error 3` = `ZNet.ConnectionStatus.ErrorVersion`** — confirmed against the real
   `assembly_valheim.dll` (enum ordinals: None, Connecting, Connected, **ErrorVersion**, ...).

5. **Jötunn turns that into the misleading popup.** Jotunn 2.30.2, `ModCompatibility`:
   ```csharp
   [HarmonyPatch(typeof(FejdStartup), "ShowConnectError")] [HarmonyPostfix]
   if (LastServerVersionData.IsValid() && (int)ZNet.m_connectionStatus == 3) {
       StartCoroutine(ShowModCompatibilityErrorMessage(text));
   }
   ```
   It never re-checks that a mod actually mismatches — it only checks the status code is 3.
   `LastServerVersionData` is already valid (sent at `RPC_ServerHandshake`, before `PeerInfo`).
   → **Table renders with every row matching.** Exactly the reporter's screenshot.

6. **Silent server side.** OneMap logs nothing on the reject path, so `LogOutput.log` shows only
   Jötunn's `Peer has disconnected. Skipping initial data send.` Matches the report.

7. **Harmony ordering:** Jötunn's `RPC_PeerInfo` prefix is `HarmonyPriority(800)`; OneMap's is
   default (400). Jötunn runs first and returns true, then OneMap's veto lands.

## What PhValheim does correctly (reproduced)

Ran the engine's own two commands in isolation — the `unzip` at
`container/engine/includes/0-functions.sh:732` and the `zip -r ./BepInEx` in `packageClient()`
(`:964`):

```
server:  game/BepInEx/plugins/OneMapToRuleThemAll/plugins/OneMapToRuleThemAll.dll
client:  BepInEx/plugins/OneMapToRuleThemAll/plugins/OneMapToRuleThemAll.dll   (in <world>.zip)
```

Both sides get the DLL, at a depth BepInEx's chainloader scans (`SearchOption.AllDirectories`,
already verified end-to-end in #85). So the sync is delivering the mod to the client.

## The reporter's stated root-cause suspicion is disproven

The prefab/DLL-fingerprint scan (`ModPrefabIndex`, `ObjectCatalog`) does **not** feed any
compatibility hash — there is no Jötunn code path in the assembly to feed. The gate is a plain
string compare of `"2.8.1"`.

## What is NOT yet established

*Why* his client fails validation. The gate only fires when the client does not answer
`OM_Version` with exactly `2.8.1`. Candidates, in order:

- the client is not loading OneMap at all (note he manually `mv`'d the DLL in #85 and called
  that workaround non-durable — a hand-edited tree is not what the payload ships);
- the client is on a different OneMap version (compare is exact and ordinal);
- an RPC ordering race: the client sends `OM_Version` from an `OnNewConnection` postfix, i.e.
  after vanilla queues `ServerHandshake`, and `PeerInfo` only goes out a round trip later — so
  ordering normally favours validation, which is why the mod works for other people.

**The one artifact that settles it is the client-side `LogOutput.log`**, which #90 explicitly
does not have. Ask for it: it will show whether `One Map To Rule Them All 2.8.1` loaded.

## Live reproduction on 37648-phvalheim1 (wopr), 2026-09-23

Instance `37648-phvalheim1` on wopr, image `theoriginalbrian/phvalheim-server:rc`, engine **2.51**.
World **`modtest67`** (id 20), previously stopped.

Added Jötunn `ValheimModding/Jotunn` **2.30.2** (mod id 10352) and
`DrummerCraig/OneMapToRuleThemAll` **2.8.1** (mod id 10596), then `mode='update'` → `mode='start'`.
Deploy clean; `--record-installed` wrote 7 of 7. This is the reporter's step 2, reproduced.

**Both mods install nested, and both reach the client** — the nesting in #85 is normal:
```
BepInEx/plugins/Jotunn/plugins/Jotunn.dll                              (in modtest67.zip)
BepInEx/plugins/OneMapToRuleThemAll/plugins/OneMapToRuleThemAll.dll    (in modtest67.zip)
```
Server log: `10 plugins to load` … `Loading [Jotunn 2.30.2]`, `Loading [One Map To Rule Them All 2.8.1]`.
So PhValheim installs it, loads it, and ships it to the client. Nothing here is broken.

### The gate, observed at runtime

Probe plugin (`dev_tools/issue90-probe/`) dumping `Harmony.GetPatchInfo(ZNet.RPC_PeerInfo)` on the
live world:

```
PREFIX  owner=com.jotunn.jotunn                     priority=800  ModCompatibility.ZNet_RPC_PeerInfo          returns=Boolean
PREFIX  owner=com.jotunn.jotunn                     priority=400  SynchronizationManager...ZNet_RPC_Pre_PeerInfo returns=Void
PREFIX  owner=drummercraig.one_map_to_rule_them_all priority=400  VersionGate+RpcPeerInfoPatch.Prefix         returns=Boolean
POSTFIX owner=com.jotunn.jotunn                     priority=400  SynchronizationManager...ZNet_RPC_Post_PeerInfo
POSTFIX owner=drummercraig.one_map_to_rule_them_all priority=400  VersionGate+RpcPeerInfoPatch.Postfix
```

**Two displacing `Boolean` prefixes on one method.** Jötunn's runs first (800) and returns true;
OneMap's runs second (400) and is the one that can veto the handshake. Confirms the static read.

### Still armed — what a real client will print

The probe is installed on `modtest67` and logs, on the next real connection attempt:

- `[PROBE] >>> RPC_PeerInfo arriving, isServer=True`
- `[PROBE] OneMap _validatedPeers count=N containsThisPeer=<bool> -> WILL PASS | WILL BE REJECTED WITH ErrorVersion`
- `[PROBE] >>> ZRpc.Invoke("Error", 3) -> ErrorVersion` + stack, if it rejects

That is the one-line oracle for the half I cannot drive headlessly (a Valheim client needs Steam
auth + a GPU session). World is up on `valheim.phospher.com:25020`.

### Teardown

```sh
docker exec 37648-phvalheim1 sh -c 'mysql -e "delete from phvalheim.world_mods where world_id=20 and mod_id in (10352,10596)" phvalheim'
docker exec 37648-phvalheim1 rm -f /opt/stateful/games/valheim/worlds/modtest67/game/BepInEx/plugins/Issue90Probe.dll
docker exec 37648-phvalheim1 sh -c 'mysql -e "update phvalheim.worlds set mode=\"update\" where id=20" phvalheim'
```
Original `modtest67` mod set (world_id,mod_id,pin,is_dep): `20,881,NULL,0` `20,4618,NULL,0`
`20,8375,NULL,0` `20,11030,NULL,0` `20,28082,NULL,0`.

## Round 2 — his Pathfinder set rebuilt, 2026-09-23

He posted two worlds: **GuildAvatar** (15 mods, works) and **Pathfinder** (47 mods, fails). All 47
Pathfinder mods resolve in our catalogue (two slightly behind his: AzuCraftyBoxes 1.8.19 vs 1.8.22,
AzuExtendedPlayerInventory 2.4.14 vs 2.5.1). Rebuilt that set on `modtest67`.

**The mod set is faithfully reproduced** — his fingerprint line comes back byte-for-byte:
```
[ModPrefabIndex] indexed 3745 prefab name(s) from 6/39 captured bundle(s); 12 DLL fingerprint(s).
```
and `recorded installed versions for 47 of 47 planned mod(s)`. Also reproduced, unprompted:
`Could not load [AzuExtendedPlayerInventory 2.4.14] because it is incompatible with: shudnal.ExtraSlots`.

### What actually changes between his two worlds

Not the prefab scan. **The number of mods contending for the same handshake method.**

| | displacing (`Boolean`) prefixes on `ZNet.RPC_PeerInfo` |
|---|---|
| 7-mod world (round 1) | **2** — Jötunn(800), OneMap(400) |
| 47-mod Pathfinder set | **19** of 35 total prefixes |

Roughly 15 of those 19 are *independent vendored copies of `ServerSync.VersionCheck.RPC_PeerInfo`*,
one per mod that bundles ServerSync (Azumatt, sighsorry ×10, WackyMole, DadsEZContainers…), plus
Jötunn's `ModCompatibility`, `InventoryActions.OptionalServerSupportPeerInfoPatch`,
`AzuCraftyBoxes.VerifyClient`, `DadsEZContainers.VerifyClient`, and OneMap's `VersionGate`.

`ZNet.OnNewConnection` is equally crowded — every ServerSync copy patches
`VersionCheck.RegisterAndCheckVersion` there.

**Harmony skips every remaining prefix once one returns false.** OneMap's gate sits at priority 400
alongside ~17 other 400s, where relative order is effectively plugin load order. So which gate
decides a connection — and whether OneMap's even runs — is not a stable property of the mod set.
That is a far better fit for "works on one world, fails on the other" than the prefab scan, and it
does not require OneMap to be the only culprit.

His scanner-feeds-a-compat-hash theory stays disproven (no Jötunn code path exists in the assembly),
but the *correlation* he found is real — heavy worlds simply carry more competing gates.

### Probe hardened and armed

`BeforePeerInfo` now runs at **priority 10000**, above every gate, so it cannot be short-circuited —
otherwise silence would be indistinguishable from "no attempt". It now also reports, per peer:

- `registeredRpcCount=N OM_VersionHandlerPresent=<bool>` — read from `ZRpc.m_functions`
  (keyed by `GetStableHashCode`), i.e. **did the server ever arm OneMap's side of the handshake**
- `_validatedPeers count=N containsThisPeer=<bool>`
- plus a postfix on `OnNewConnection` (priority −10000, runs last) showing the same

Leading hypothesis to kill or confirm with one connection: OneMap's `OnNewConnection` postfix never
runs on the server (displaced or aborted in that crowded chain), so the `OM_Version` handler is never
registered, the client's reply is dropped as an unknown RPC, `_validatedPeers` stays empty, and
**every** client is rejected with `ErrorVersion`. That is deterministic, matching "every attempt".

World is up on `valheim.phospher.com:25020`; client payload rebuilt 21:11 (47 mods, probe excluded).

## Round 3 — VERDICT: the 47-mod set does NOT reproduce #90

Real client joined the rebuilt Pathfinder world. Probe output:

```
OnNewConnection DONE isServer=True | registeredRpcCount=48 OM_VersionHandlerPresent=True
>>> RPC_PeerInfo arriving, isServer=True
    peer rpc: registeredRpcCount=48 OM_VersionHandlerPresent=True
    OneMap gate: _validatedPeers count=1 containsThisPeer=True -> WILL PASS
```

Counts, with #90's own signature line as the control:

| | |
|---|---|
| `Skipping initial data send` (the #90 signature) | **0** |
| `Sending initial data to peer` (the opposite) | **1** |
| `ErrorVersion` sent | **0** |
| gate verdict `WILL BE REJECTED` | **0** |
| gate verdict `WILL PASS` | **1** |

The handshake completed cleanly through all 19 displacing gates. Jötunn sent initial data,
`WackysDatabase: All clients have successfully synced server assets`, and OneMap itself pushed
content to the client (`[SendPinsToClient] dispatched 1 pins`).

**My leading hypothesis is dead.** `OM_VersionHandlerPresent=True` at `OnNewConnection` — the
server *did* arm OneMap's side even in the crowded patch chain, and validation landed before
`PeerInfo`. Patch-order contention is real but is not what breaks his world.

The client exited during world load afterwards, with no rejection sent — that is the tester's own
Hyprland/Vulkan window-resize crash (see his writeup), unrelated to #90.

### What this means

A faithful rebuild of his exact mod set, same versions, same Jötunn, same scanner fingerprint,
**connects fine**. So #90 is not a property of the mod list. It is specific to his installation —
and the gate can only fail if his *client* does not answer `OM_Version` with exactly `2.8.1`.

### The one artifact still missing

His **client-side** `BepInEx/LogOutput.log`. Specifically whether it contains
`Loading [One Map To Rule Them All 2.8.1]`. If the mod is not loading on his client, the server
rejects every attempt with `ErrorVersion` — deterministic, matching "every attempt", and exactly
what he sees. That is now the only hypothesis left standing, and he can confirm it in one grep.

## Recommended reply

Not a PhValheim defect; no patch here. Point the reporter at the mod author
(discord.gg/zUngMHPDsz) with the decompiled gate, and ask for the client `LogOutput.log` plus
`BepInEx/config/drummercraig.one_map_to_rule_them_all.cfg` on the client.
