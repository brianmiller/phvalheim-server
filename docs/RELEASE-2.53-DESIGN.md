# Release 2.53 — crossplay on modded worlds, and retiring QuickConnect

**START AT §15.** It records what §14's two open items turned into once they were measured
against the live server — the admin Launch button was never missing, and the dialog's real
problem was that the panel and its contents were one knob. §14 is kept for its method note
but its conclusion about the offline table is **wrong and retracted in §15.1**.
§12 (the IP:PORT join) and §13 (vanilla = join code only) are correct and
still apply. §1-§7 predate two design changes and parts are superseded, each marked where
it sits. §8.10, §8.11 and §9 record what was actually built and why.
Status, 2026-10-01 (late). `:rc` = `sha256:6958f71e…`, `IMAGE VERIFY OK`. Crossplay connect is
**working on a real client**. Two fixes sit unbuilt in the working tree and the IP:PORT connect
is still broken — all three are in §11.

The table below is the state as of §10 and is kept for context; §11 supersedes it.

| | |
|---|---|
| Crossplay on modded worlds (§1-§7) | **BUILT**, on `:rc` |
| QuickConnect retirement, one-shot notice | **BUILT.** Still gated OFF (`companionProvidesConnect=0`) — see §9.4 |
| Companion bundled into the image (§9) | **BUILT** |
| Per-mod destinations: schema, union, tree split, picker (§8 steps 1-4) | **BUILT** |
| Derived join path (§8 step 5) | **NO-OP** — see §8.11 |
| Access control decoupling (§8.6, step 6) | **NOT DONE**, and deliberately — see §8.11 |
| Companion actually connects | **NOT DONE** — this is the one piece of real engineering left |

Two local commits are unpushed: `67d243d5` (crossplay feature) and `2cb710c6` (verify-marker
repairs). Everything since is uncommitted.

---

## 1. Why this is being folded into 2.53

2.53 made crossplay available on modded worlds. That shipped a **two-step join**: the client
installs mods and starts Valheim, then the player types the join code into Valheim's
*Join by code* box. It works, but it is the only place in the product where Launch does not
finish the job.

The cause is QuickConnect. `bdew/QuickConnect` is how a modded world has always been joined,
and its config file is `world:host:port:password` — a format with nowhere to put a join code.
A crossplay world is a PlayFab server with no host:port at all, so QuickConnect's entry for one
points at an address that cannot answer. It is inert rather than harmful, but it is a dead
control sitting in the player's server list.

Owning the connect path fixes the two-step join, deletes `quick_connect_servers.cfg` and its
whole family of bugs, and removes a third-party single point of failure from
`requiredMods` — a list the engine hard-WARNs on and then builds the world *without*.

---

## 2. The hard constraint that shapes everything below

> **SUPERSEDED on 2026-09-30 by §9 — read that first.** This section's constraint was real and
> it is the reason the derivation machinery was built. It no longer applies: the Companion now
> ships **inside the image** and is installed by `installSystemPlugins()`, so it is not resolved
> from the catalogue at all and does not need to exist on Thunderstore. `companionSupportsConnect()`
> survives, but it reads a build flag rather than probing `mod_versions`;
> `companionConnectMinVersion`, `companionModIdentity` and `versionAtLeast()` are gone.
>
> What is written below still holds for `serverblankpassword` and for `legacyConnectMods`
> (QuickConnect), which *are* still catalogue-resolved — and the "warning, not an error" failure
> mode it describes is exactly why §9.4 keeps QuickConnect installed until the flag is flipped.
> The section is kept rather than deleted because it explains why the safety property exists.

**`requiredMods` resolves against the mod catalogue, not against a bundled file.**
`container/engine/includes/0-functions.sh` ~line 610:

```sh
reqModId=$(SQL "SELECT id FROM mods WHERE source='$reqSource' AND owner='$reqOwner' AND name='$reqName' LIMIT 1;")
if [ -z "$reqModId" ]; then
        echo "... Required mod ... is NOT in the catalogue. World '$worldName' will be built WITHOUT it. ..."
        continue
fi
```

So a connection-capable Companion has to **exist on Thunderstore** before any world can install
it. And the failure mode when it does not is a *warning*, not an error — the world is built
anyway. If 2.53 removes QuickConnect from `requiredMods` and the new Companion is not yet
published, **every rebuilt modded world comes up with no connect path at all.**

This is not a thing to be careful about; it is a thing to make structurally impossible.

### The resolution: deprecation is DERIVED, never assumed

2.53 must not hardcode "QuickConnect is gone". It must ask, at world-build time, whether a
Companion capable of connecting is actually available, and keep QuickConnect until it is.

- Add a minimum-version constant for the connecting Companion, e.g.
  `companionConnectMinVersion="1.1.0"` in `phvalheim-static.conf`.
- At build time, resolve the Companion from the catalogue and compare its effective version.
- **Capable** → install Companion, do NOT install QuickConnect, do NOT write
  `quick_connect_servers.cfg`.
- **Not capable / absent** → install QuickConnect exactly as today, write the cfg, and log one
  NOTICE saying why the new path is not active yet.

This makes the release safe to ship before the mod is published, makes the cutover automatic
when it is, and means a catalogue that has not synced yet degrades to the old working path
instead of to a world nobody can join. It is the same "derive the verdict, never remember it"
rule the Ro!Cade update check learned the hard way.

**Do not** gate this on `phvalheimVersion`. The server version says nothing about what is in
the catalogue.

---

## 3. The mod work

### 3.1 State of the source — read this before estimating

`github.com/brianmiller/phvalheim-companion` is public and clonable. It is **not** in the state
the shipped artifact is:

| | Source (repo HEAD) | Shipped (1.0.6) |
|---|---|---|
| Version | `Main.cs` says `1.0.4` | 1.0.6 |
| Last change | 2023-06-12 | 2024-05-06 |
| DLL size | — | 10752 bytes (1.0.4 was 23552) |
| `ZNet` string refs | — | 1 (1.0.4 had 8) |

The DLL **halved** between 1.0.4 and 1.0.6 and its `ZNet` references dropped 8 → 1. That is the
fork's discord-notifier guts finally being stripped, and it happened ~11 months after the last
commit. **Treat repo HEAD as the 1.0.4 codebase, not as current.** The namespace is still
`DiscordNotifier.Patches`, which is the clearest evidence of what this actually is.

Since the decision is a **rewrite**, this gap mostly does not matter — a rewrite needs the
contract, and the contract is documented server-side. Do not spend time reconciling 1.0.6.

### 3.2 The real blocker is the build, not the Harmony hook

The hook is the easy part and the pattern is already proven in this codebase:
`Patches/FejdStartupPatch.cs` already does `[HarmonyPatch(typeof(FejdStartup), "SetupGui")]`
with a `Postfix`. Extending FejdStartup is established ground here.

What actually blocks a build on dev1:

1. **`PhValheimCompanion.csproj` HintPaths are absolute Windows paths** into
   `C:\Program Files (x86)\Steam\steamapps\common\Valheim\...` and
   `AppData\Roaming\PhValheim\worlds\phvalheim.phospher.com\test1\...`. Every one must be
   re-pointed at a local `libs/` directory. `phvalheim-tickmonitor` already has a working
   `libs/` + `build.sh` layout on this box — copy that, do not invent a new one.
2. **It references *publicized* assemblies** (`Assembly-CSharp_publicized.dll` et al). Publicized
   refs **compile green and throw at runtime**. Anything the rewrite touches through a publicized
   private member needs a runtime smoke test, not a successful build, as its oracle.
3. **It targets Valheim 0.216.9.** Valheim is now 1.0. `UpdateModifiers` was byte-identical
   across that jump, but `FejdStartup` and matchmaking are exactly where Valheim churns when it
   adds platforms. Rebuild against current assemblies and expect signature drift.

### 3.3 What the mod must do

**Connection, client-side.** One path for both transports, replacing QuickConnect:

- **host:port world** → the existing direct-connect behaviour, but driven by the mod rather than
  QuickConnect's server-list entry.
- **crossplay world** → join by code, **after** character selection.

The post-character-selection requirement is not a preference. Valheim's own `-joincode` CLI flag
registers `AutoJoinServer()` → `JoinServer()` and never calls `SelectCharacter()`, so the player
lands as **"Odev (Developer)"** on a character they never made. The in-game *Join by code* box
does it correctly because it runs after character selection. Hook the path the UI uses; do not
reach for the CLI flag. This has been rejected twice now — write it down in the code.

**Where the world's connect details come from.** The client already receives a payload per world.
Decide deliberately between:
- reading a mod config file the engine writes (the QuickConnect model, which is what produced the
  stale-`gameDNS` and empty-host bugs), or
- reading the launch string / an engine endpoint at run time (no on-disk copy to go stale).

Prefer the second. The whole reason `quick_connect_servers.cfg` is worth deleting is that it is a
denormalised copy of live settings written only at world create/update.

**Side detection is load-bearing.** The Companion is dual-role — client `HungHeads` hook plus
server `ZNet` telemetry — and the connection code is client-only.

- **`[BepInProcess("valheim_server.x86_64")]` DOES NOT WORK HERE.** The actual process does not
  match; this is precisely what broke TickMonitor. Do not use it.
- Decide at runtime from `ZNet` once it exists. **Never in `Awake()`.**
- Packaging cannot separate the halves either: `packageClient()` zips `./BepInEx` **whole**, so
  the server tree *is* the client tree and both halves reach both sides regardless of how many
  DLLs you produce. What you control is what *runs*. This is why one assembly with a reliable
  runtime side check beats two mods.

**Must not regress:** new bosses need no mod release. `bosses.php` reads the trophy **prefab
name** off the item stand at runtime and `$PHVALHEIM_BOSSES` is the only gate, so adding a boss
is one registry entry + one column + one PNG. Keep `action` = bare prefab name;
`setHungHeads()` interpolates the column straight into SQL and is safe *only* because `api.php`
validates against that registry first.

**Leave `1010101110/serverblankpassword` alone.** Different concern (server-side, lets a modded
world run passwordless under CITIZENS access). Bundling it into this is scope creep.

### 3.4 Publish

Both stores, one zip — Thunderstore **and** Hexium. Thunderstore is what `requiredMods` pins, so
that one is the gate. Bump `manifest.json` and `Main.cs VERSION` together; they are separate
strings and the source already has them disagreeing with reality.

---

## 4. The server work

### 4.1 One-shot upgrade notice

There is an established pattern for this — copy `huginNoticeShown` (2.45) exactly. Five sites:

| Site | File |
|---|---|
| Column | `container/engine/dbUpdates/dbUpdate_2.53.sh` — `addColumn settings <name> "TINYINT NOT NULL DEFAULT 0"` |
| Read | `container/nginx/www/includes/config_env_puller.php` — `(int)($_settingsRow['<name>'] ?? 1)` |
| Markup | `container/nginx/www/admin/index.php` — gated `if ($setupComplete == 2 && ($<name> ?? 1) == 0)` |
| Dismiss | `container/nginx/www/admin/adminAPI.php` — `UPDATE settings SET <name> = 1` |
| Migration | `dbUpdate_2.53.sh` must set it to **0 for upgraders** and **1 for fresh installs** |

**`?? 1` is load-bearing in BOTH the read and the markup.** An undefined variable is `null`, and
PHP evaluates `null == 0` as true — so a missing `config_env_puller.php` line greets the operator
with this dialog on *every page load, forever*. That comment is already in `index.php` at line
1230 because it has bitten before.

The fresh-install-vs-upgrade distinction matters: a brand new install has no worlds using
QuickConnect and nothing to warn about. Getting this backwards is how 2.31/2.35 shipped the setup
wizard to the wrong audience twice.

**What the notice must say**, in operator language:

1. How players join modded worlds has changed; the PhValheim Companion now does it and
   QuickConnect is no longer installed.
2. **Each existing modded world must be updated once** to pick this up. Until a world is updated
   it keeps working the old way — nothing breaks by waiting.
3. Updating a world **stops it** (`mode='update'` always ends stopped) — so do it at a quiet time
   and start the world again afterwards.
4. Players should update their client payload after the world is updated.

Point 3 is not optional. An operator who updates five worlds during peak hours and finds them all
stopped will have learned it the worst way.

### 4.2 Retire QuickConnect

- `phvalheim-static.conf`: `requiredMods` keeps `PhValheimCompanion` and
  `serverblankpassword`; QuickConnect moves behind the capability check from §2.
- `createQuickConnectConfig()` (`0-functions.sh` ~860) is called from **two** places —
  `container/engine/phvalheim` ~542 and `container/games/valheim/scripts/importWorld.sh` ~117.
  Both must respect the same check. `importWorld.sh` also deletes a stale cfg from
  `custom_configs/` at ~142; that logic stays.
- The engine's stale-`gameDNS` repair block (`phvalheim` ~167–196) exists only to keep
  `quick_connect_servers.cfg` honest. When the cfg is no longer written, that block becomes dead
  code — remove it in the same pass or it will be maintained forever for nothing. **No orphans.**
- Decide whether to **delete** an existing `quick_connect_servers.cfg` on world update. Leaving it
  means a stale entry in the player's server list pointing at a world they should now reach via the
  Companion. Deleting it is the tidier answer but is a destructive act on a file inside a world
  directory — scope it precisely and do not glob.

### 4.3 Release chores

- `whatsnew.php` — the existing 2.53 entry describes only the crossplay work. It must be rewritten
  to cover the connection change, or it will document half a release.
  `check-whatsnew.sh` gates on *presence*, not accuracy, so nothing will catch this.
- `CHANGELOG.md` — same; the 2.53 section currently ends with a claim that the client needs no
  change, which stops being true.
- **`dev_tools/buildRcDetached.sh` markers.** Add a 2.53 block for this work and **put every new
  variable in the `&&` chain** before `echo "IMAGE VERIFY OK"`. The chain is the only thing that
  gates; a marker present in the `echo` alone prints a tidy `(want N)` and is compared to nothing.
  That is how ~50 markers (all of 2.52's and all of 2.53's first cut) verified nothing.
  Prefer negatives: assert the QuickConnect write path is *gone* from the capable branch.
- The verify payload must contain **exactly one apostrophe**. Do not quote a PHP or C# string
  literal in a comment there — that is how the last attempt tripped its own guard.

### 4.4 Tests

- `dev_tools/test-crossplay-any-world.sh` and `test-startWorld-args.sh` already exist and pass;
  do not disturb them.
- New oracle needed for §2: a world built with a **capable** Companion must get no
  `quick_connect_servers.cfg` and no QuickConnect; a world built with an **absent or old** one
  must get both. Those two cases together are the test — either alone passes on a broken
  implementation.
- New oracle for the notice: fires once for an upgrader, **never** for a fresh install, and not
  again after dismissal. Include a control asserting it is *not* shown when the column is 1;
  without it a modal that never renders at all passes.
- Every new marker and assertion must be run against the **pre-change tree** to confirm it fails
  there. Four of 2.53's first-cut markers could not fail — they used a single `.` to stand for a
  quote in `[ "$isCrossplay"` and matched neither tree.

---

## 5. Sequencing

1. Re-point the `.csproj` to a local `libs/`; get the existing source building on dev1 unchanged.
   **This is the gate.** Nothing else can be verified until a build exists.
2. Rewrite the mod: clean side detection, connection for both transports, HungHeads preserved.
3. Smoke-test on a real Valheim client — host:port world *and* crossplay world, character
   selection intact in both. A successful build is not evidence, because of the publicizer.
4. Publish to Thunderstore (and Hexium).
5. Server side: capability check, notice, QuickConnect retirement, dead-code removal, markers,
   tests, whatsnew, CHANGELOG.
6. Rebuild `:rc`, confirm `IMAGE VERIFY OK`, hand over the digest.
7. Brian tests. Promotion and version tags stay his call.

Steps 1–4 are in `phvalheim-companion`; 5–6 are in `phvalheim-server`. They can overlap, because
§2 makes the server safe to ship before the mod is published.

---

## 6. Open questions for Brian

1. **Vanilla worlds.** They join via `steam://run/892970//+connect` with no mod at all. Does the
   Companion take those over too (one path everywhere), or do they stay as they are? Taking them
   over would mean a vanilla world needs a mod installed, which contradicts what vanilla means.
   Recommendation: leave vanilla alone.
2. **Deleting `quick_connect_servers.cfg`** on update — yes or leave it? (§4.2)
3. **Does QuickConnect stay in the catalogue as an operator-selectable mod?** Retiring it from
   `requiredMods` is not the same as forbidding it.
4. **2.0.14 client.** Still not required — the launch string is unchanged and the mod ships inside
   the BepInEx payload. Only needed if §3.3 chooses to get connect details from the client rather
   than from the mod at runtime.

---

## 7. What executing sections 1-4 actually found (2026-09-30)

Recorded here because three of the assumptions above turned out to be wrong, and the wrong
ones were the load-bearing ones.

### The publicizer hazard is smaller than 3.2 claimed, and the reflection is what matters
There is **no publicizer on this box and no `publicized_assemblies` directory in any
container**; `phvalheim-tickmonitor` builds green against the RAW `assembly_valheim.dll`.
`thorskist/lib/` does carry a full Valheim 1.0 set including `assembly_valheim_publicized.dll`,
and that is what the Companion now builds against.

The real trap is narrower and sits in `HungHeads.cs`: it reads `m_world` via
`Utils.GetPrivateField<World>(...)`. Against the publicized assembly, rewriting that as a direct
field access compiles green and throws at runtime. **The reflection helper is the thing that
makes it survive the real assembly** and must not be "cleaned up".

### Valheim 1.0 signature drift is real, but only in the code being deleted
The unchanged source produced 12 errors. Every one was in the valheim-discord-notifier code
(`ZNet.PlayerInfo.m_host` and `ZDOID.userID` are both gone in 1.0), plus one genuine bug:
`FejdStartupPatch.cs` was still in namespace `DiscordNotifier.Patches` and so could not see
`Main`. `Main.cs`, `HungHeads.cs`, `phvalheim-backend.cs` and `JsonTemplates.cs` compiled clean.

`serverStarted` and `playerJoined` are consumed by **nothing** in phvalheim-server -- verified
repo-wide, with `setHungHeads`/`HungHeads` hits in the same search as a positive control. So the
telemetry half was dead upstream code and was deleted rather than repaired. Step 1's "build it
unchanged" is therefore not achievable and is not worth achieving.

Result: `PhValheimCompanion.dll`, net472, 0 errors 0 warnings, 11,776 bytes -- close to the
shipped 1.0.6's 10,752, which supports the theory that 1.0.6 *was* the discord guts stripped.
`libs/` is gitignored: they are Iron Gate's and Unity's assemblies and that repo is public.

### The stale-gameDNS block in 4.2 is NOT dead code
4.2 says to delete it. Do not. Because the retirement is derived, QuickConnect is still installed
on the fallback path, so `worldHost` still feeds `createQuickConnectConfig()` -- and it also feeds
the `external_endpoint` the admin UI displays. It stays.

### Open question 2 is answered by the mechanism
Deleting a stale `quick_connect_servers.cfg` on update is moot: the file is simply not written
once the Companion is capable. Deliberately not adding a destructive delete of a file inside a
world directory to buy nothing.


---

## 8. Per-mod client/server destination switches (folded in from 2.54)

Status: **design, not started.** FOLDED INTO 2.53 on 2026-09-30 at Brian's direction --
2.53 has not shipped, so this goes in the same release rather than trailing it.
There is no 2.54 doc; if you are looking for one, this is it.

### 8.1 What this is

Every mod on a world gets two switches: **Server** and **Client**. The operator decides where
each mod is installed.

This replaces an earlier proposal for a third world type ("Modded / Unmodded / Vanilla") and is
strictly better, for one reason: **server-side-ness is a property of the mod, not of the
world.** A networking mod is server-side whichever world it is on. Putting the choice on the
mod lets one world mix both — a networking mod server-only alongside a UI mod client-only —
which a world-level type cannot express at all.

It is also far cheaper. `worlds.vanilla` is a `TINYINT` read at **79 decision sites** (198
references in the web UI alone). A third state means widening it and auditing every reader, and
a migration that changes no read sites is exactly how the world-card mod counts broke in 2.43.
This design touches none of them: a world is still Modded or Vanilla.

**"Unmodded" stops being a thing you declare and becomes a thing that happens.** Flip every mod
on a world to Server-only and the client has nothing to install — so the world behaves, from a
player's side, exactly like a vanilla one. That falls out of the switches instead of being a
fourth code path.

### 8.2 Switch semantics (decided)

| Rule | Behaviour |
|---|---|
| Default for a newly selected mod | **Both ON** — identical to today, so nothing changes until someone deliberately flips a switch |
| Mod not selected | Both switches **disabled and greyed out**, not merely unchecked |
| Both switches OFF | **Not reachable.** Indistinguishable from deselecting the mod, so the picker unticks the mod instead of storing one that installs nowhere |
| A dependency's destination | **The union of its parents'.** A dep pulled in by a Server-only mod and a Client-only mod lands on BOTH |
| Operator editing a dependency's switches | **No.** Derived and shown read-only, like today's dependency badges |

### Why the union, specifically

A dependency missing from a side where its parent runs is a BepInEx load failure, and it
surfaces as "the mod just doesn't work" rather than as anything naming the dependency. Jotunn
pulled in by a Server-only networking mod and a Client-only UI mod has to be on both or one of
them breaks. Erring toward installing is the only rule that cannot starve a mod of its
dependency, which is also why the operator does not get to override it (§2, row 5).

### 8.3 The structural work — this is the whole cost

**Today the client payload is not built. It *is* the server's live tree.** Mods unzip into
`game/BepInEx/plugins/$modName/` (`0-functions.sh` ~840) and `packageClient()` zips `./BepInEx`
wholesale (~1057). So right now there is physically no way to give the server a mod and withhold
it from the client — they are one directory. It cuts both ways: a client-only mod dropped in that
tree is loaded by the server too.

That shared tree is not an accident to be casually undone. It is why 2.49's deletion of the
loader's `BepInEx.cfg` broke the world log **and** the client's console window together. Read
[[v2.49-release]] before touching `packageClient()`.

The split needs two destinations:

- **server tree** — `game/BepInEx/` ← mods with `deploy_server=1`
- **client staging** — a separate directory ← mods with `deploy_client=1`
- `packageClient()` zips the **staging** tree plus the doorstop bits, not the live one

Both trees need the loader, the `chmod -R u+rwX` from 2.39, and the `ensureBepInExLoaderConfig()`
treatment. The purge at `0-functions.sh` ~500 (`rm -rf .../BepInEx/plugins/*`) needs a sibling
for the staging tree, or a stale client-only plugin survives forever.

### 8.4 Schema

`world_mods` is keyed `(world_id, mod_id)`, so the flags belong on it:

```sql
ALTER TABLE world_mods ADD COLUMN deploy_server TINYINT NOT NULL DEFAULT 1;
ALTER TABLE world_mods ADD COLUMN deploy_client TINYINT NOT NULL DEFAULT 1;
```

`DEFAULT 1` on both is the whole migration story: every existing row keeps today's behaviour, so
an upgrade changes nothing until an operator flips something. No backfill, no notice needed.

Dependency rows get their flags **computed**, not stored by the picker — `worldMods.py`'s
`resolve()` / `install_rows()` / `by_plugin()` already flatten the closure, so the union
propagates there. Note `by_plugin()` collapses duplicate `(owner, name)` across catalogues; the
surviving row must carry the union of the collapsed rows' flags, not the winner's alone.

### 8.5 Derived, never stored: does this world have a client payload?

```sql
EXISTS (SELECT 1 FROM world_mods WHERE world_id = ? AND deploy_client = 1)
```

Everything else follows from that one question, and none of it is a new stored flag:

| Client payload | Join path offered |
|---|---|
| yes | Today's behaviour — `phvalheim://` Launch, mods installed, Companion connects |
| no, world not crossplay | `steam://run/892970//+connect host:port` — the working one-click link a vanilla world already gets |
| no, world is crossplay | No link is possible. Join code + instructional hint — the path `getVanillaJoinInfo()` already serves by returning `href => NULL` |

**The Launch link must never simply vanish.** A button that disappears reads as a broken page.
In both payload-less cases the card routes down a path the vanilla card already implements, so
this adds no new UI concept — only new callers of existing behaviour.

This is the same "derive the verdict, never remember it" rule as 2.53's
`companionSupportsConnect()`. A stored `hasPayload` column would be a second source of truth for
something `world_mods` already answers.

### The Companion is the interesting case

The Companion is what does the automatic connecting, so **setting it to Server-only is what
trades Launch for manual joining.** An operator who wants server-side mods *and* click-to-join
keeps the Companion on Client. That is a coherent thing to want and the UI should not make it
surprising. Its default is Both (it is dual-role: client HungHeads, server telemetry).

BepInEx itself is engine-installed, excluded from the catalogue since 2.44, and must be in
**both** trees whenever that tree has any mod at all. It is not operator-switchable.

### 8.6 Access control, decoupled from world type

**Q2 = C:** modded worlds gain `password`, `listed` and `crossplay` **alongside** the CITIZENS
allowlist. Access control stops being coupled to whether a world runs mods.

Today modded worlds run `-public 0` with no `-password` (the `hammertime` literal in the launch
string is historical and inert), and only vanilla worlds get real password/listed/crossplay
columns. This means:

- `startWorld.sh` must pass a real `-password` for a modded world that has one
- listing still requires a non-empty password — Valheim refuses `-public 1` with an empty one and
  dies on *"Error bad password: The password is too short"*
- `permittedlist.txt` is enforced server-side regardless of what the client runs, so CITIZENS and
  a password compose rather than conflict

This is the only part of 2.54 that produces a combination nothing in the codebase currently
emits, so it is where the bugs will be. The access-list guards in
`dev_tools/test-create-access-guards.sh` must keep passing untouched — **never write an access
list entry the operator did not supply.**

### 8.7 Sequencing

1. Schema + `worldMods.py` union propagation, with no UI. Plan output is the oracle.
2. Split the install path: server tree and client staging populated from the flags.
3. Rewrite `packageClient()` to zip the staging tree. Verify a payload's contents, not its size.
4. Mod picker UI: two switches per row, greyed when the mod is unselected, read-only on deps.
5. Derived join path (§5) in both UIs.
6. Access control decoupling (§6).
7. Release chores, markers, whatsnew, CHANGELOG.

Steps 1-3 are invisible to operators and can ship behind the default-both flags without any UI at
all, which makes them separately testable.

### 8.8 Tests that have to exist

Each of these needs its opposite as a control, or it passes on a broken implementation:

- A Server-only mod is in the server tree and **absent from the payload**; a Client-only mod is in
  the payload and **absent from the server tree**. Both directions, or "the split happened" is
  indistinguishable from "nothing was split".
- A dep of a Server-only mod and a Client-only mod lands in **both** trees.
- A world with every mod Server-only produces **no payload** and offers the `+connect` link; the
  same world with one mod flipped to Client produces a payload again and offers Launch.
- `by_plugin()` collapsing two catalogue copies of one plugin keeps the **union** of their flags.
- A modded world with a password actually gets `-password` in its argv, and one with `listed=1`
  and an empty password is still refused.
- Every existing world after migration installs byte-identically to before. This is the
  regression that matters most and the easiest to skip.

### 8.9 Open

- §6 confirmation: Q2=C was answered before the world tiers collapsed into per-mod switches. The
  reading recorded here is "modded worlds gain password/listed/crossplay alongside CITIZENS".
  Worth one explicit re-confirmation before step 6, since it is the riskiest part.
- Whether flipping a switch should raise the existing `restart pending` badge or require a full
  world update. It changes the tree on disk, so it is an update — and an update always ends
  stopped ([[phvalheim_autoupdate_lifecycle]]), which the UI must say plainly.

### 8.10 Execution notes — step 1 (schema + union propagation), done 2026-09-30

Step 1 of §8.7 is implemented and tested. Four things came out of doing it that were not in the
design, two of them decisions rather than discoveries.

**A pick can be widened too, not just a dependency.** §8.2 settled that a dependency lands on
the union of its parents and that the operator does not get to override it. The same hazard
exists one level up and the design did not name it: the operator picks plugin X **Client-only**
by hand, and a Server-only mod Y depends on X. By plugin identity there is only one copy of X,
so either X goes on the server — against the operator's explicit switch — or Y silently fails to
load on the server. The reasoning in §8.2 applies unchanged (erring toward installing is the
only rule that cannot starve a mod of its dependency), so **`fold_by_plugin()` widens picks as
well as dependencies.**

Two things keep that from being a surprise:

- The **stored row is left alone.** The operator's switches stay exactly as typed; only the
  *effective* destination widens. So the widening evaporates by itself the moment Y is
  deselected — it is derived, not written.
- `resolve()` **logs it by name**, saying which mod widened and that a selected mod depends on
  it there. A mod appearing on a side the operator switched off is defensible; doing it
  silently is not.

Worth Brian's eye: the alternative was to refuse the combination in the picker. That is
arguably more honest but it makes the UI say "no" to a state the operator can reach in two
independent clicks, which is worse.

**`record_installed()` needs attention in step 2, before the trees are split.** It marks a
planned mod as installed only when the installer reports it landed. Once a client-only mod
stops landing in the server tree, it will be *in the plan* and *absent from `installed_ids`*,
so the row keeps `installed_at` NULL — which the Updates tab reads as **"unknown"**, forever.
That is exactly [[phvalheim_unknown_is_not_uptodate]], which shipped three times in one
release. `--record-installed` has to learn about destinations at the same time the install path
does, not after. Nothing is wrong today only because both flags are still 1 everywhere.

**The plan TSV's last column is load-bearing in `0-functions.sh`.** The install loop reads the
plan with `IFS=$'\t' read -r … modId`, and `read` gives its **last** variable every remaining
field. Appending a column without adding a variable there does not lose the column — it *glues*
it onto `mod_id`, which then goes into `--record-installed` and into SQL. `mod_id` was appended
in 2.47 under the rule "appending is safe because the awk readers take fields 1-5", and that
rule is only half true. `dev_tools/test-mod-destinations.py` §8 now asserts the column count
against the variable count across the two languages, so the next person cannot do half of it.

**`mod_versions.source_rank` is ZERO-based** — rank 0 is newest, which is what
`effective_version()`'s `ORDER BY source_rank ASC LIMIT 1` and `modcatalog.php`'s
`source_rank = 0` both mean. The first cut of the live test built its fixture with
`source_rank = 1`, read as "the newest", and so tested each mod's *second-newest* release: the
shared dependency it found was shared between two versions neither pick installs, and the union
assertion failed against correct code. The fixture now states its own precondition as a check
that can fail, and pins the convention.

#### What is tested

- `dev_tools/test-mod-destinations.py` — the graph rule against a hand-built graph with stubbed
  SQL: inheritance in both directions, the opposite-sides union, a widening travelling two
  levels down (the case a `seen`-set-only walk fails), cycle termination, `fold_by_plugin`
  idempotence, both-switches-off preserved, and the TSV/`read` column contract. 21 checks;
  every one paired with its opposite, because the failure mode of a union is answering `True`
  for everything. Mutation-tested: 8 deliberate breaks, 8 caught.
- `dev_tools/test-mod-destinations-live.sh` — the real migration and the real `worldMods.py`
  against a clone of the live catalogue (13,452 mods, 289,143 resolved edges), in a scratch
  schema dropped on exit. 18 checks. The headline one is §8.8's regression: for a world at the
  migrated default, the new plan minus its two appended columns is **byte-identical** to the
  plan produced by the `worldMods.py` in `git HEAD` — diffed, over a 229-mod closure, not
  eyeballed.

## 9. The Companion ships in the image (decided 2026-09-30)

Status: **done for packaging; the connect capability itself is still #9.**

The Companion is no longer published to Thunderstore or Hexium. It ships inside the
phvalheim-server image, installed by `installSystemPlugins()` from
`/opt/stateless/games/valheim/custom_plugins/PhValheimCompanion`, exactly as
`PhValheim-TickMonitor` and `ZeroBandwidth-CustomSeed` already did.

### 9.1 Why

**It is PhValheim's own infrastructure, not a mod anyone chose.** It posts to the PhValheim
backend and reads PhValheim config, so it has no audience outside this server. Publishing it
to a public catalogue was always a distribution mechanism rather than a statement about what
it is.

**Bundling deletes version skew, and with it the machinery that coped with skew.** A separately
published Companion could be older or newer than the server that expects it, which is the only
reason `companionConnectMinVersion`, `companionModIdentity`, `versionAtLeast()` (with its
`sort -V` prerelease carve-out) and a SQL probe of `mod_versions` ever existed. Server and
Companion are now one artifact and cannot disagree, so all of that is gone — about 200 lines
of engine code and a 201-line test replaced by one flag.

It also removes the catalogue as a dependency for the join path entirely. `modSync` once lost
every dependency edge for 668 versions; a bundled plugin is not exposed to that.

### 9.2 What it costs

**The Companion has no Server/Client switches.** There is no `world_mods` row to hang them on.
That is the right outcome rather than a compromise: it is dual-role by design — server-side
`ZNet` work, client-side HungHeads — so its destination was always going to be Both, which is
exactly what landing in `custom_plugins/` gives it now that
`installCustomModsConfigsPatchers()` writes both trees.

**Section 8.5 changes, and improves.** Because the Companion is always in both trees, every
modded world always has a client payload, so "flip every mod to Server-only and the world
behaves like vanilla" (§8.1) is no longer literally true. The outcome is better: such a world
still offers one-click **Launch**, with a payload containing just the Companion and the loader.
The player installs nothing they would notice and still joins by clicking. So §5's derivation
simplifies to *modded worlds always offer Launch; the `+connect` fallback is vanilla-only*, and
the disappearing-button risk §8.5 worried about goes away.

**Do not bundle AND publish.** The published copy would land from the install plan while the
bundled copy lands from `custom_plugins/` — two DLLs with the same BepInEx GUID in one
`plugins/` folder, one of which silently fails to load. If it is ever published again it must
be excluded from the catalogue the way BepInEx has been since 2.44.

### 9.3 Newtonsoft is gone

`HungHeads.cs` used `JsonConvert.SerializeObject` on one object with two string fields.
Published, that is a declared dependency the catalogue resolves. Bundled, it would mean
shipping a 712 KB `Newtonsoft.Json.dll` into `BepInEx/plugins` beside whatever copy another
mod bundled — two versions of one assembly in a single plugin folder, which is a load-order
lottery that fails at runtime rather than at build.

Replaced with `Utils.HeadHungJson()` + `Utils.JsonEscape()`, and `JsonTemplates.cs` deleted
(it existed only to be handed to a serializer; the wire format is now declared in one named
place). The result is a single self-contained **10,752-byte** DLL, the same shape as
TickMonitor. Verified by probing the built assembly for a Newtonsoft reference **with a
control string** — a binary that cannot be read returns zero hits for everything, including
the thing you are trying to prove absent.

### 9.4 QuickConnect is retired by a flag, and NOT yet

`companionProvidesConnect` in `phvalheim-static.conf`: `0` = QuickConnect still installed,
`1` = retired. It is **0**, because the bundled Companion still cannot connect — #9 has not
been done.

This is the part that is easy to get wrong in a hurry. A required mod missing from the
catalogue only produces a `[WARN]` from `mergeRequiredTsMods()` and the world is then built
without it, so flipping this to 1 early does not fail loudly — it produces modded worlds with
**no way to join them** and a warning in a log nobody reads. `companionSupportsConnect()` now
reads the flag instead of the catalogue, but it is still a function and both call sites still
gate on it, so the cutover remains one line in one place.

`dev_tools/test-companion-bundled.sh` enforces the sequencing directly: it fails if the flag
is 1 while the Companion source contains no connect code, and equally if the Companion gains
connect code while the flag is still 0.

### 8.11 Execution notes — steps 4 and 5, done 2026-09-30

**Step 4 (picker UI) is done.** Two switches per selected row in a new *Installs on* column,
in both `new_world.php` and `edit_world.php` — each page carries its own copy of ~600 lines of
that JavaScript, so every assertion in `dev_tools/test-mod-destination-picker.sh` runs against
both. A change applied to one and not the other is invisible until an operator uses the other
page.

Two decisions worth recording:

- **A dependency the operator did not tick shows the word "derived", not a computed pair.**
  Showing the union was the obvious alternative and it would lie: `reverseDepMap` in the picker
  is **one level deep**, so a dependency three levels down a chain would display a destination
  that is not the one the engine installs. `walk_closure()` walks the whole closure
  transitively; the page should not pretend to. A hover explains that it is worked out when the
  world is updated.
- **An absent flag means BOTH, in the save path as well as the schema.** `aiactions.php` posts
  bare mod ids through `saveWorldModSelection()`, and a browser tab opened before the upgrade
  posts the old shape. `?? false` there would install those worlds' mods nowhere. The marker
  `pw` and a test both pin this, because it is a falsy default doubling as a real answer —
  the shape of [[phvalheim_unknown_is_not_uptodate]].

The duplicate-plugin collapse in `saveWorldModSelection()` now hands the loser's destinations
to the winner, mirroring `fold_by_plugin()`. Without it, ticking one catalogue's copy for the
client and the other's for the server silently loses a side.

**Step 5 (derived join path) turned out to be a NO-OP, because of §9.** `getModdedJoinInfo()`
already returns a `phvalheim://` Launch href for every modded world, and bundling the Companion
means every modded world always has a client payload — so there is no payload-less modded case
to derive a different path for. The `+connect` fallback stays what it already is: vanilla-only.
Nothing was changed, and that is the correct outcome rather than an omission. §8.5's table
survives as a description of what the code already does.

**Step 6 (§8.6, access control) is NOT done.** It is the only remaining scope item and is
deliberately left: its reading of Q2=C was answered while three world *types* were still on the
table, it is by §8.6's own admission "where the bugs will be", and it touches the access-list
code that `dev_tools/test-create-access-guards.sh` exists to protect. It needs one explicit
confirmation before it is written, not a guess at the end of a long session.

---

## 10. Where 2.53 stands, and exactly what is left (2026-10-01)

`:rc` is **`sha256:1a5da92b69a1786210356ab03a4ad2c2226fdaa3d1c8d08911237cfd4016bd08`**,
`IMAGE VERIFY OK`, served digest confirmed. Everything below the "remaining" line is in it.

### 10.1 Done

Crossplay on modded worlds · per-mod Server/Client switches (schema, transitive union, picker
UI) · server tree split from client staging · `packageClient()` rewritten · Companion bundled
into the image with Newtonsoft dropped · one-shot upgrade notice · the launch string widened to
ten fields through one builder.

Two bugs found by Brian testing the RC, both fixed and verified live:

- **"restart pending" that no restart could clear.** `savedWorldOptions()` still gated
  crossplay on `vanilla === 1`, mirroring a `startWorld.sh` gate this very release removed. The
  column said 1, `.running-options` said 1, the reader said 0, so the badge compared two
  different questions forever. Fixed; `listed` and `passwordhash` stay gated because
  `startWorld.sh` still gates those. **A mirror is only safe while it is actually a mirror.**
- **The modded card's crossplay hint rendered huge.** It reused the `vanilla-hint` class whose
  sizing is scoped `.catbox-vanilla .vanilla-hint`; a modded card is a plain `.catbox` and
  inherited none of it. Removed entirely -- the CROSSPLAY pill and the join code with its copy
  link already said it.

### 10.2 The Companion connection -- WRITTEN. Unverified on a real client.

**Status as of 2026-10-01, later the same day:** built, bundled, markers and tests in place.
`companionProvidesConnect` is still **`0`**, declared by a `COMPANION CONNECT PENDING SMOKE
TEST` marker beside the flag. Only §10.6 is left.

**A correction that changes the code, not just the words.** §10.3 below recorded
`SetServerToJoin` as the hook. That is the wrong door, and reading `FejdStartup`'s IL is what
showed it:

- `SetServerToJoin(data)` writes **`m_joinServer`**.
- `OnCharacterStart()` then *overwrites* `m_joinServer` from **`m_queuedJoinServer`** -- which
  would still be `None` -- so the branch fails and the player lands in the world list after
  picking a character. No exception, no log line.

The field that matters is `m_queuedJoinServer`, and the only thing that sets it correctly is
the private `FejdStartup.ProceedJoinRequest(ServerJoinData)`. That one call does the privilege
check, queues the join, and opens character selection; `OnCharacterStart` completes it. So
Brian's spec is **vanilla behaviour reached from a different button**: no Harmony patch on
`OnCharacterStart`, nothing reimplemented.

The corrected table, every row verified against metadata and IL:

| Step | API | Note |
|---|---|---|
| attach on the main menu | `FejdStartup.SetupGui` postfix | adds a `ConnectDialog` component; does not show the popup (`UnifiedPopup` is not accepting pushes that early) |
| the dialog | `YesNoPopup` + `UnifiedPopup.Push` | native panel, fonts and input blocking for free |
| Connect / Close labels | `UnifiedPopup.yesText` / `noText` | **private**; `yesText` is the RIGHT button, `noText` the LEFT. Saved and restored -- they live on the singleton, so not restoring them relabels every later Yes/No dialog in the session |
| join, then character select | **`FejdStartup.ProceedJoinRequest`** | **private** -- reflected |
| auto-connect once chosen | vanilla `OnCharacterStart` | no patch needed |
| IP:PORT | `ServerJoinDataDedicated(string, ushort)` | takes a DNS name; `JoinServer` resolves it |
| crossplay join code | `ZPlayFabMatchmaking.ResolveJoinCode` | same resolver vanilla's `-joincode` uses; we discard its join and route through `ProceedJoinRequest` instead, which is precisely why this does not land the player as "Odev" |
| password pre-fill | `FejdStartup.ServerPassword` | read by `ZNet.RPC_ClientHandshake` |

**`dev_tools/test-publicizer-trap.sh`** (in the Companion repo) is the test that makes this
safe. It asks a real un-publicized `assembly_valheim.dll` for each member's true visibility and
fails if anything called as ordinary C# is private. It found three -- `UnifiedPopup.instance`,
`yesText` and `noText` -- that built with zero warnings and would have thrown the moment the
dialog appeared. It also pins the four IL facts the flow rests on, so a Valheim update that
changes how joining works fails the test instead of the player. It needs a **client**
assembly, not the dedicated-server one, and exits **2** when it cannot find one: an absent
oracle is not a pass.

**`dev_tools/test-launch-payload-contract.sh`** (server repo) pins the positional launch-string
format across all three of its readers in two repos, by INDEX rather than by presence, plus the
transport argument name on both sides. Mutation-tested: swapping two indices, drifting the
argument name, and an off-by-one in a length guard are each caught.

The original groundwork note, kept because it is still true of the design: the five source
files were untouched before this, and what existed was the transport decision and the widened
launch string.

**Brian's UX spec, agreed:** click Launch -> client syncs mods -> Valheim starts -> the
Companion shows a dialog ("this is a PhValheim world, with these mods", short and scrollable)
carrying **Connect** and **Close**. Connect takes the player to the character-select screen;
once a character is chosen the game connects on its own, by IP:PORT or by crossplay join code.

Selecting the character FIRST is what makes this correct rather than clever: Valheim's own
`-joincode` registers `AutoJoinServer()` without `SelectCharacter()`, which is why it drops the
player in as "Odev (Developer)". This design sidesteps that instead of fighting it.

**Every step has a real API** (verified against `assembly_valheim_publicized.dll`, with a
control string proving the probe could read the binary):

| Step | API |
|---|---|
| dialog on the main menu | `FejdStartup.SetupGui` (already patched for the version label) |
| Connect -> character screen | `SelectCharacter` |
| auto-connect once chosen | **`OnCharacterStart`** |
| IP:PORT | `ServerJoinDataDedicated` -> `SetServerToJoin` |
| crossplay join code | `ServerJoinDataPlayFabUser` + `ResolveJoinCode` |

Both transports end at the same `SetServerToJoin`, so this is one code path with two data
objects, not two implementations.

**Brian's four decisions:**

1. The world and how to reach it come from the **encoded Launch! link**, not a file -- a
   crossplay world reissues its join code on every restart, so a disk copy goes stale
   silently. That is the `quick_connect_servers.cfg` bug wearing a new costume. **Done:**
   fields 8 (`crossplay`) and 9 (`joinCode`) are in the launch string as of this release.
2. The mod list comes from the local `BepInEx/plugins/` directory -- what is actually
   installed on that machine beats what the server believes.
3. Close drops to the normal Valheim menu, with a small centred button to bring the dialog
   back.
4. The dialog appears **only** when launched by the PhValheim client. This needs no separate
   check: no launch payload on the command line means it was not a PhValheim launch, so the
   transport and the gate are the same thing.

### 10.3 The transport, and a correction

The payload travels as **`--phvalheim-launch <base64>` on Valheim's argv**, read by the
Companion from `Environment.GetCommandLineArgs()`.

A correction worth keeping, because the first reasoning given for it was wrong: Valheim is
**not** launched through Steam on Linux. `Launcher.cs` execs `valheim.x86_64` directly with
doorstop environment variables, the same strategy as BepInEx's `start_game_bepinex.sh`, so
environment variables *do* reach the game there. Only the **Windows** path goes through Steam's
`-applaunch`, where env does not survive. argv is chosen because it is the one mechanism that
works on every platform, not because env is impossible.

**Four insert points in `phvalheim-client/Launcher.cs`**, all one-liners:

| Platform | Line | Where |
|---|---|---|
| **Flatpak Steam** (Brian tests here first, Fedora Atomic) | ~204 | after `-console` in `fpArgs`; everything after the app id reaches the game |
| Linux native | ~212 | the `new[] { exec, "-console" }` array |
| Windows | ~86 | the `-applaunch` argument string |
| macOS | ~237 | same shape |

The Flatpak path already grants `--filesystem=` to the PhValheim directory, so reading the
local `plugins/` folder needs no new permission, and `setsid` means the game outlives the
client regardless.

**One thing to solve on the way in:** `Arguments.cs` decodes the base64 into separate fields
and does not keep the original string. Stash the raw base64 on `Platform.State` as it is
parsed and pass that through -- re-encoding from the parsed fields would be a second place to
get the positional order wrong.

**One risk to watch:** this passes an argument Valheim itself does not recognise. Unity games
normally ignore unknown args, but if the game chokes on it the fallback is a file, which works
and goes stale on a world restart.

### 10.4 The order to do it in -- steps 1 to 3 DONE

1. ~~Companion: `LaunchPayload.cs`, `ConnectDialog.cs`, `ConnectFlow.cs`, the reopen button.~~
   **Done.** Added to the `.csproj`'s explicit compile set, which is not globbed -- a new file
   left out of it is silently not built.
2. ~~Delete the remaining fork remnants.~~ **Done.** `manifest.json`, `icon.png` and
   `NexusReadme.txt` went with the decision not to publish to Thunderstore or Hexium; the
   README no longer describes a Discord notifier. `HungHeads.cs`, `Utils.cs` and
   `phvalheim-backend.cs` kept -- those are PhValheim's.
3. ~~The four `Launcher.cs` inserts.~~ **Done**, via `CompanionArgList()` (argv list: Flatpak,
   native Linux, macOS) and `CompanionArg()` (one string: Windows `-applaunch`). Both return
   nothing when there is no payload, which is load-bearing -- the Companion treats the
   argument's presence as proof of a PhValheim launch, so sending the flag with an empty value
   would make a plain Steam launch look like one. The raw base64 is stashed on
   `Arguments.PhValheim.RawLaunchPayload` rather than `Platform.State`, because `State` is
   built after argument parsing.
4. **Build and push.** Not done -- Brian's call. `:rc` is a live channel.
5. Brian smoke-tests on Fedora Atomic + Flatpak. **Only then** flip
   `companionProvidesConnect` to `1` and remove the pending marker in the same edit.

### 10.6 What is left, exactly

- **Build the two artifacts and push `:rc`.** The server image needs rebuilding so a modded
  world installs the new Companion, and the Flatpak client needs rebuilding so it passes
  `--phvalheim-launch`. Brian needs *both* for one smoke test; neither alone proves anything.
  Ten new `cn*` verify markers read the shipped DLL itself, including a size floor and a
  negative on `SetServerToJoin`.
- **Smoke-test.** Click Launch on a modded world; the dialog should name the world and list
  the mods from `BepInEx/plugins/`; Connect should open character selection; picking a
  character should join. Then Close, and check the centred button brings the dialog back.
- **Then** flip the flag and remove the marker together. `test-companion-bundled.sh` fails if
  they disagree in either direction, so this cannot be half-done.
- **§8.6 access control** -- still needs one sentence from Brian. See §10.5.

**Two known deviations, both deliberate, both cheap to change:**

- The mod list is capped at 12 entries with "…and N more" rather than being a scrolling list.
  `YesNoPopup`'s body is a single `TMP_Text` with no `ScrollRect`, and hand-building one
  without a client to look at is exactly the kind of uGUI work that burns a session for no
  gain. Same information, fixed height; swapping in a real scroll view changes only
  `BuildBody()`.
- The reopen button is IMGUI, not uGUI. It needs no prefab, no canvas and no layout group, and
  a button that fails to appear leaves the player with no route back to the dialog.

`companionProvidesConnect` stays **`0`** until that smoke test passes. A required mod missing
from the catalogue only produces a `[WARN]` and the world builds anyway, so flipping it early
does not fail loudly -- it produces modded worlds with no way to join them.
`dev_tools/test-companion-bundled.sh` fails if the flag disagrees with the Companion's actual
connect support, in either direction.

**A green build proves nothing here.** The Companion compiles against publicized assemblies,
which compile green and throw at runtime. The only oracle is a real Valheim client.

### 10.5 Also still open

- **§8.6 access control** (password/listed/crossplay on modded worlds alongside CITIZENS).
  Not built, deliberately: the Q2=C answer predates the collapse from three world *types* to
  per-mod switches, and it touches the access-list code `test-create-access-guards.sh` exists
  to protect. Needs one explicit confirmation first.
- 33 files uncommitted; `67d243d5` and `2cb710c6` unpushed; `phvalheim-client` untouched.
  Nothing has been committed -- that is Brian's call.

## 11. SUPERSEDED BY §12 (2026-10-01, after Brian's second round of testing)

**§11 is HISTORY** -- it was the resume point before §12, and §11.3's diagnosis is retracted there. Read §12 instead.

### 11.1 What is on `:rc` right now

`sha256:6958f71e2448a88c6c8f2a9e77997b9d08545d4dc1ef136cedd79d1a21079bcc`, `IMAGE VERIFY OK`.
Contains: the Companion connect flow, `companionProvidesConnect="1"`, the join-code reader fix,
and the one-time QuickConnect retirement migration.

Brian's verdict on it: **crossplay connect works end to end** (dialog, Connect, character
select, auto-join). Three problems reported, below.

### 11.2 Fixed in the working tree, NOT YET BUILT OR PUSHED

**(a) World create/edit hung forever — the blocker.** `destinationCell()` in `new_world.php`
and `edit_world.php` read `neededDeps`, which is declared `var neededDeps = {}` *inside*
`rebuildTables()`. A sibling function cannot see it, so every **unchecked** row threw
`ReferenceError: neededDeps is not defined`. The exception escaped the AJAX success handler and
the picker's spinner never cleared. The CHECKED branch never touches it, which is exactly why
click-through testing of selected mods never caught it. Introduced by the per-mod destinations
work, not by the migration -- the admin API was verified returning valid JSON in ~500 ms first.
Fixed by passing `neededDeps` in as a third argument. Verified by *executing* the real function
text from both files (checked / unchecked / is-a-dep / argument-omitted) with the pre-fix
version still throwing as a control.

**(b) Dialog body text was tiny.** Self-inflicted: `<size=85%>` / `<size=80%>` applied to a
body `TMP_Text` that Valheim already sizes for one or two short sentences. The header looked
right because it is a separate component with its own size, which is what made the body look
broken rather than merely small. Now sets an absolute `bodyText.fontSize = 18` (saved and
restored with the rest of the popup skin) and no markup goes below 95%. `enableAutoSizing` is
turned off too -- with it on, TMP shrinks text to fit and a long mod list would scale itself
back to unreadable regardless of what is set here.

### 11.3 SUPERSEDED -- read §12. The matchmaking-gate diagnosis below was WRONG.

**Do not implement from §11.3.** It is kept because the reasoning is instructive and because
the `OnAddServer` recipe in it is still accurate about what that method does -- but its central
claim, that `JoinServer()` refuses a world with no matchmaking data, is false. §12 has the
real cause, found by decompiling the method instead of reading its IL call list.

### 11.3 (superseded) NOT FIXED -- the IP:PORT (non-crossplay) connect

**Symptom:** Connect on a non-crossplay modded world returns to the main menu, and both the
dialog and the reopen button are gone.

**Two separate defects.**

**1. The join is gated by matchmaking data the world does not publish.** `FejdStartup.
JoinServer()`'s IL calls `MultiBackendMatchmaking.GetServerMatchmakingData`, then checks
`OnlineStatusExtentions.IsOnline`, then `m_networkVersion`, then `ServerMatchmakingData.
get_IsUnjoinable`, pushing a `WarningPopup` and returning *before* it ever reaches
`ZNet.SetServer`. A modded PhValheim world is started with `-public 0` and publishes nothing to
matchmaking, so that gate fails. Crossplay works because PlayFab registers the lobby, which is
what gives the gate something to read.

`+connect` is NOT the answer: `FejdStartup.HandleStartupJoin`'s IL shows it goes through
`ZSteamMatchmaking.QueueServerJoin` -- also matchmaking.

The path that genuinely joins an unlisted server by address is the server list's **Add Server**
dialog, `ServerListGui.OnAddServer`, whose IL sequence is:

```
ServerJoinDataUtils.GetAddressAndPortFromString(text, out addr, out port)
new ServerJoinDataDedicated(...)
ServerListGui.OnManualAddToFavoritesStart()
MultiBackendMatchmaking.GetServerIPAsync(entry, new ResolveDomainCompletedHandler(...))
   -> on success: new ServerJoinData(entry)
                  ServerListGui.OnManualAddToFavoritesSuccess(...)
```

The resolve-and-register step is the missing piece. Next step is to probe
`OnManualAddToFavoritesStart` / `OnManualAddToFavoritesSuccess` /
`MultiBackendMatchmaking.GetServerIPAsync` for signatures and visibility, then replicate the
minimum of it needed before calling `ProceedJoinRequest`. **Do not guess at this** -- read the
IL, and remember `IsURL` is a red herring: its only reader in the whole assembly is
`OnAddServer`'s own lambda, not the join path.

**2. `ConnectFlow.Connecting` is never reset when Valheim bounces back to the menu itself.**
`Begin()` sets it true; only the explicit error paths set it false. When the join dies inside
Valheim's own code the flag stays true forever, and `ConnectDialog.Update()` and `OnGUI()` both
bail on `ConnectFlow.Connecting` -- so the dialog never returns and the reopen button never
draws. That is the "dialog/button gone" half of the report and it is fixable on its own:
watch for the main menu becoming active again while `Connecting` is true, and clear it.
This one is certain and independent of defect 1.

### 11.4 Order of work when resuming

1. **`ConnectFlow.Connecting` reset.** Small, certain, and restores the way back even while
   the IP join is still broken.
2. **The IP:PORT join**, per §11.3 defect 1. Probe first, then implement.
3. **Bundle the Companion DLL.** `dotnet build -c Release`, then copy
   `bin/Release/net472/PhValheimCompanion.dll` over
   `phvalheim-server/container/games/valheim/custom_plugins/PhValheimCompanion/PhValheimCompanion.dll`.
   **As of this writing the built DLL (`6f11cd08…`) and the bundled one (`f5a8c10d…`) DIFFER --
   the font fix is NOT in the image.** They are the same SIZE, so an `ls` will not tell you;
   compare hashes. The project builds Debug by default while shipping from `bin/Release`.
4. Add verify markers for whatever lands, dry-run them against the real files first, then
   rebuild `:rc` and hand Brian the digest.

### 11.5 Also still open

- **§8.6 access control** (Q2=C) -- still needs one sentence from Brian. Unchanged.
- **`phvalheim-dev` on dev1 is from 2026-09-11** and contains ZERO occurrences of
  `phvBuildLaunchString`, so it predates 2.53 entirely. `dev_tools/test-admin-crossplay-launch.sh`
  drives it and reports 1 failure whose verdict is therefore meaningless in either direction.
  Refresh that container to the current digest before trusting that suite. Do NOT edit the test
  to agree with September code.
- 36 files uncommitted in phvalheim-server. Nothing committed -- Brian's call.

## 12. The IP:PORT join -- the real cause, and the fix (2026-10-02)

**START AT §12.** §11 is the previous resume point; §11.3's diagnosis is retracted here.

### 12.1 Why §11.3 was wrong

§11.3 said `JoinServer()` gates on matchmaking data and that a `-public 0` world publishes
none, so the join is refused. That was inferred from the method's IL *call list*, which does
show `GetServerMatchmakingData`, `IsOnline`, `m_networkVersion` and `IsUnjoinable` in sequence.
Decompiling the method shows what the IL list cannot -- how those calls are **nested**:

```csharp
ServerMatchmakingData d = MultiBackendMatchmaking.GetServerMatchmakingData(m_joinServer);
if (d.m_onlineStatus.IsOnline() && d.m_networkVersion != 40) { /* warn */ return; }
if (d.IsUnjoinable) { /* warn */ return; }
ZNet.SetServer(...);
```

Both gates are conditioned on the server being **online**. And `IsUnjoinable`'s first line is
`if (!m_onlineStatus.IsOnline()) return false;`. So a world with *no* matchmaking data skips
the version check and is reported joinable -- absent data sails straight through. The gate I
"found" cannot fire in the case I claimed it fired in.

**The lesson, which is the same one §11.3 itself preached and I then broke:** an IL call list
tells you which members a method touches, never which branch they sit in. Four calls in a row
read like a pipeline and were actually two guarded clauses. Decompile before concluding; the
probe's `!Type.Method` mode is for finding *what to read*, not for drawing conclusions.

### 12.2 The actual cause -- a race, not a gate

`JoinServer()`'s dedicated branch:

```csharp
ZNet.ResetServerHost();
MultiBackendMatchmaking.GetServerIPAsync(serverJoin, delegate(bool ok, IPv6Address? addr) {
    ...
    ZNet.SetServerHost(endPoint.m_address.ToString(), endPoint.m_port, ...);   // host set HERE
});
flag = true;
...
TransitionToMainScene();                                                      // scene loads HERE
```

**Nothing waits for that callback.** Whether the join works comes down entirely to whether
`GetServerIPAsync` answers *synchronously*, and it only does that when the address is already
known:

```csharp
if (server.TryGetIPAddress(out var address)) completedHandler?.Invoke(true, address);
else s_instance.m_dnsResolver.ResolveDomainNameAsync(server.m_host, completedHandler);
```

`ResolveDomainNameAsync` in turn answers synchronously **only on a DNS cache hit**; otherwise
it runs a `BackgroundWorker`. PhValheim hands over `gameDNS` -- a name, not a literal IP -- so
on a cold cache `TransitionToMainScene()` runs with the server host still reset. The client
loads in with nowhere to connect and bounces back to the main menu with no error.

**Why the server list never hits this:** populating the list resolves every entry, so by the
time anyone presses Join the cache is warm and vanilla's own call is synchronous. We arrive
from a dialog, having skipped the list, so we are the only caller with a cold cache.

This also explains the one fact §11.3 could not: crossplay worked. The PlayFab path never
touches the resolver -- `ZNet.SetServerHost(remotePlayerId)` is called inline.

### 12.3 The fix

Warm the cache and nothing else. `ConnectFlow.JoinByAddressRoutine` is now a coroutine that
calls Valheim's **own** resolver, waits where waiting is allowed, then hands over an unchanged
join. No reimplementation, no second code path, and the address format stays whatever Valheim
decided it should be. `SetCacheEntry` runs before our callback, so by the time
`ProceedJoinRequest` is called the cache is warm and vanilla's own lookup is synchronous.

Three things fall out of it:

- **A dead gameDNS is now reported instead of crashing.** That callback sets `retries = 50` on
  a failed resolve and then dereferences `address.Value` regardless -- a
  `NullReferenceException` inside Valheim. Resolving up front means a bad name produces a log
  line with the dialog still on screen.
- **`IsURL` really was a red herring**, as §11.3 warned. It is not involved.
- **The `ServerListUtils.UpdateServerOnlineStatus` / `RefreshServer` path is NOT needed.** It
  is the public way to populate matchmaking data, and it was the fix §11.3 implied; since the
  gate does not fire, pre-populating it would have been elaborate and inert. Noted so nobody
  re-derives it.

### 12.4 The second defect, fixed as described

`ConnectFlow.Connecting` is now cleared by a watchdog: `ConnectDialog.Update` calls
`ConnectFlow.NoticeMainMenu()` when the menu is up again after a 2 s grace period, and puts
the dialog back. The watchdog **must stay above** `Update`'s early return -- the state it
clears is the state that return triggers on, so moving it down makes it dead code in the only
situation it exists for. Pinned by `test-publicizer-trap.sh`.

### 12.5 Verification

- `dev_tools/test-publicizer-trap.sh` -- **49 checks**, extended with the four DNS-timing facts
  the fix rests on, a negative control on the IL dump, and the watchdog-reachability pair.
  All eight mutations tried were caught; baseline clean.
- `buildRcDetached.sh` -- `cnn` (pre-resolve), `cno` (`JoinByAddressRoutine`, i.e. still a
  coroutine), `cnp` (watchdog), all gated. Size floor raised 20000 -> **30000**: the stale
  bundle that shipped last time was 29,184 bytes, cleared a 20 KB floor, and carried every
  other string literal. Dry-run against the real DLL before building.
- The mod-picker blocker now has markers too (`zpa`/`zpb`/`zpc`) -- it had none, and it is the
  fix that unblocks world creation.

### 12.5a What is on `:rc` now

`sha256:9d5ff6feaee1fffa8bf29630444bfcf85b88156031c56c2d281a39d7c6cae6bd`, `IMAGE VERIFY OK`,
registry `:rc` tag confirmed on that digest. Verified by running the pushed image, not by
trusting the build: bundled Companion is **32,256 bytes / `d112ee5c…`**, byte-identical to the
local Release build; `GetServerIPAsync`, `JoinByAddressRoutine` and `NoticeMainMenu` all
present; `SetServerToJoin` absent; the picker fix and the corrected What's New item both in.

**The IP:PORT join is NOT yet confirmed on a real client** -- it is reasoned from decompiled
source, pinned by 49 oracle checks and 8 mutations, and built. Brian's test is the oracle that
matters. Crossplay should be unaffected: it never enters `JoinByAddressRoutine` unless the join
code is missing.

Two notes on how this build went, both worth not repeating:

- The first attempt pushed an image whose `whatsnew.php` predated an edit made *after* the
  COPY layers ran. Editing source during a build silently ships the pre-edit file.
- The second reported `IMAGE VERIFY FAILED` on a **marker I had broken**, not on the code: a
  line-number-based comment cleanup ate `hx=$(grep -c ...)`, so an unrelated 2.45 marker
  printed empty. Restored. The lesson is to not do line-number edits in a 2,000-line gate
  script, and that the right response to a failed marker is to find which one, never to
  re-baseline. A mechanical scan for "gate variable with no assignment" found it in seconds
  and is worth keeping as a habit.

### 12.6 Still open

- **§8.6 access control** (Q2=C) -- one sentence from Brian. Unchanged.
- **`phvalheim-dev` on dev1 is still the 2026-09-11 image** (`884e7d8f6118`), with zero
  occurrences of the per-mod destination work. `test-duplicate-plugin.sh` drives it and reports
  3 failures whose verdict is meaningless in either direction, exactly like
  `test-admin-crossplay-launch.sh`. **Refresh the container; do not edit either test to agree
  with September code.**
- `buildRcDetached.sh` repeats a now-obsolete "NO APOSTROPHES" warning in ~10 more comment
  blocks. The body became a quoted heredoc run from a file on 2026-10-01, so apostrophes are
  safe; the file header says so. The ones in the sections touched here were corrected, the rest
  are stale but harmless.
- Nothing is committed. 37 files modified in phvalheim-server -- Brian's call.

## 13. DECIDED: a vanilla world stays join-code only (2026-10-02, Brian)

**Do not "complete" the Companion story for vanilla worlds. This is settled.**

Brian tested a vanilla world with crossplay, password and listed all on, and reported that
Launch still gave the join-code modal instead of launching. That is **correct behaviour**, not
a regression, and he has confirmed it should stay.

### Why it looks like a gap

`container/engine/phvalheim`'s vanilla branch skips `installSystemPlugins` and `packageClient`
outright -- "a vanilla world means ZERO MODS: no BepInEx, no system plugins, no quickconnect,
no Thunderstore mods". The Companion is a BepInEx plugin, so a vanilla world has nothing on the
client that could do the joining. Every part of 2.53's connect work lives behind that `else`.

`getVanillaJoinInfo()` then splits on backend, not on `listed` or `password`:

| world | href |
|---|---|
| vanilla, no crossplay | `steam://run/892970//+connect <gameDNS>:<port>` -- launches *and* connects |
| vanilla, crossplay | `NULL` + join code -- the modal |

Crossplay is the whole trigger: a PlayFab-hosted world has no address, so `+connect` has
nothing to point at.

### Why it is staying that way

Making Launch join a vanilla crossplay world would mean shipping it a client payload of
BepInEx + the Companion and moving its link from `steam://` to `phvalheim://`. Mechanically
that is easy -- the per-mod Client destination already expresses "client, not server" -- but it
would make "vanilla" mean *no gameplay mods* rather than *no BepInEx*, and every vanilla world
would start installing a mod loader on its players' machines. Brian's call: **vanilla means
join code only; leave the modal.**

A future reader will find this and think it is unfinished. It is not. The join code also has to
stay visible on any crossplay world regardless, because console players cannot run BepInEx at
all -- so the modal is load-bearing for them even on modded worlds.

Nothing in 2.53 changes for this; no code was touched for it. The whatsnew item that describes
one-step joining is scoped to "On a modded crossplay world" deliberately.

## 14. NEXT STEPS (2026-10-02, after Brian's fourth round of dialog testing)

**START AT §15.** §12 and §13 remain correct; this is the live work queue.

### 14.1 The dialog: decouple CONTENT size from PANEL size

Brian, verbatim: *"The dialog box is bigger, good, but EVERYTHING is increasing in size. The
whole idea is that the content FITS. Make the buttons and overall text much smaller and
increase the dialog box size even more -- maybe another 15%."*

He is right and the current design is wrong in a structural way, not a numeric one.
`PanelScale` is a `localScale` on `popupUIParent`, so it multiplies the panel art, the header,
the body text, the mod list and the buttons together. Growing the panel therefore buys exactly
zero extra room for content. Measured across Brian's own crops (he crops tight to the dialog):

| screenshot | size | ratio | PanelScale |
|---|---|---|---|
| badlayout_still  | 572x442 | - | 1.0 |
| badlayout_still2 | 724x554 | 1.26x | 1.28 |
| badlayout_still4 | 995x740 | 1.374x vs still2 | 1.75 |

Both scale changes applied exactly as intended. The panel is now 74% larger than where it
started and the content is 74% larger with it, which is why it still reads as cramped.

**The fix: express every content size in SCREEN units and divide by PanelScale.**

```csharp
private const float PanelScale = 2.0f;          // ~15% more than 1.75

// Screen-space sizes. Dividing by PanelScale makes the on-screen size INDEPENDENT of how big
// the panel is, which is the whole point: the panel grows, the content does not.
private const float BodyScreenSize   = 20f;
private const float RowScreenSize    = 17f;
private const float HeaderScreenMul  = 0.55f;   // fraction of Valheim's own header size
private const float ButtonScaleMul   = 0.6f;    // applied to each button's localScale

private static float BodyFontSize => BodyScreenSize / PanelScale;
```

Everything to shrink, with where it lives:

1. **Body text** -- `bodyText.fontSize`, already reflected in `ApplyBodyStyle`.
2. **Mod list rows** -- `ModListView.RowFontSize`.
3. **Header** -- `UnifiedPopup.headerText`, a private `TextMeshProUGUI`. Not touched today; it
   is the single biggest thing in the screenshot. Save and restore its `fontSize` exactly like
   the body's.
4. **Buttons** -- `UnifiedPopup.buttonLeft` / `buttonRight` (private `Button`) and
   `buttonLeftText` / `buttonRightText` (private `TMP_Text`, assigned in `Awake`). Scaling the
   two button transforms shrinks art and label together; that is cheaper and more predictable
   than resizing the art.

**Everything above is private on the real assembly, so every one is a reflected access and
belongs in test-publicizer-trap.sh's REFLECTED list.** Probe each one before writing the code.

Then raise `ModListView.ListTopFraction` (0.38 -> ~0.5): a smaller body font means the summary
rows need less of the rect, and the list is what Brian wants more of. Re-run
`dev_tools/test-dialog-layout.sh` -- `BodyLineBudgetWithList` must move with the fraction, and
the harness is what tells you by how much.

**Do not ship this without re-reading §12/§13 first:** style AFTER `Push`, measure rather than
eyeball, and the render harness exists so a layout can be seen without a round trip.

### 14.2 The admin Launch button: I have been editing one of FOUR render sites

Brian has reported the Launch button missing for vanilla worlds **three times**. Each time I
changed `container/nginx/www/admin/index.php`'s ONLINE action-group and the poll's
`launchButtonHtml`, executed both in isolation, saw them pass for every vanilla permutation,
and shipped. The dashboard has more render sites than that:

```
$onlineWorlds  = array_filter($worlds, fn($w) => $w['mode'] !== 'stopped');
$offlineWorlds = array_filter($worlds, fn($w) => $w['mode'] === 'stopped');
```

| site | ~line | state |
|---|---|---|
| online actions | 566 | CHANGED -- Launch opens the join-code modal |
| online config | 642 | untouched |
| **offline actions** | **746** | **NEVER TOUCHED. Hardcoded `<span class="action-btn disabled">Launch</span>`** |
| offline config | 754 | untouched |
| poll JS | `launchButtonHtml` / `createWorldRow` | CHANGED |

A stopped world has no join code -- `getVanillaJoinInfo` returns `href => NULL` and no code
when `!$isOnline` -- so a disabled Launch there may well be correct. The point is that it is a
**different code path that was never looked at**, and my isolation harnesses could not see it
because I never extracted it.

**Order of work, and step 1 is not a code change:**

1. **Get ground truth.** The rendered HTML for Brian's vanilla world row, plus that world's
   real `mode`, `vanilla`, `crossplay` and join code. Everything after this is guessing without
   it, and three cycles of guessing is the evidence. Ask Brian which section the world appears
   under (Online or Offline) and whether it is running -- one question resolves it.
2. Fix whichever site it actually is.
3. Add a marker per render site, not one for the feature. The current `zjd` counts 2 openers
   and passed while the button was missing, because it was counting the two sites I had
   already changed.

### 14.3 Still open, unchanged

- **The IP:PORT join on a modded world** -- fixed and built in §12, never confirmed by Brian.
  This is the only functional item in 2.53 still unverified.
- **§8.6 access control (Q2=C)** -- one sentence from Brian.
- `phvalheim-dev` on dev1 is still the 2026-09-11 image; `test-duplicate-plugin.sh` and
  `test-admin-crossplay-launch.sh` report meaningless failures against it.
- Nothing committed. Brian's call.

---

## 15. Resolution of §14 (measured, not reasoned)

### 15.1 The admin Launch button was never missing

§14.2 said step 1 was not a code change. That was right, and the answer it produced reversed
§14's own conclusion. Measured against Brian's live `:rc` box:

| question | answer |
|---|---|
| which table is his world in? | `vanillaunmoddedCrossplay` is `mode=running` → the **ONLINE** table |
| which block renders it? | line 566 — the one block I had been editing all along |
| what does the server emit? | `<a class="action-btn success" data-action="launch" data-joincode="198533" onclick="showJoinCodeModal(this)">Launch</a>` |
| what does the poll payload carry? | `launchHref: null, launchJoinCode: "198533", launchPlayfab: true` — the correct branch |
| what does the browser show? | Launch present, visible, 69×34, **not** in the overflow menu, at 1280/1440/1600/1920 |

So §14.2's premise — that the untouched offline block at line 746 was the bug — was wrong. The
offline block is *correct*: the public card shows `offline`, not Launch, for a stopped world,
because a stopped world has no join code. Changing it would have introduced a bug.

**Method, because this is the part worth keeping.** The admin page cannot be reached from
outside the container and SSH port-forwarding to wopr is administratively prohibited. What
works: `docker exec <container> php -r 'file_get_contents("http://127.0.0.1:8081/")'` for the
rendered HTML, then mirror that HTML plus `/css` and `/js` into a local directory, stub
`adminAPI.php?action=getWorlds` with the saved JSON, and drive
`google-chrome --headless --virtual-time-budget=20000 --dump-dom` against it with a probe
script that reports `getBoundingClientRect()`, `closest('.action-overflow-menu')` and computed
style per row. Chrome returns its own error page with a plausible byte count when the load
fails, so the probe must assert on the world's NAME appearing, never on response size.

### 15.2 What the measurement did find

The join-code chip is not an `.action-btn`, and `reflowActionGroups()` only ever moves
`.action-btn` elements into the overflow menu. The chip's ~65 px therefore came straight out of
the button row's budget, and **Start, Stop and Logs were pushed behind the "…" menu on every
crossplay row, at every width measured.** Nothing in the markup hints at it; the chip is a
sibling of the buttons and looks free.

Fixed with `.action-group:has(.join-code-chip) { flex-wrap: wrap; row-gap: 0.25rem }` — the
chip is read, not clicked, so it is the one child that may drop to a second line. Re-measured
after the change: `scrollWidth == clientWidth` and the overflow trigger is gone at all four
widths. Marker `cod`.

### 15.3 The dialog: content size decoupled from panel size

§14.1 as planned. `PanelScale` is a `localScale` on the popup root, so it multiplies panel art,
header, body, list and buttons together — raising it twice (1.28 → 1.75) bought **zero** extra
content room, which is why the dialog stayed cramped at 995×740.

Content sizes are now screen-space constants divided by `PanelScale`, so the two knobs are
orthogonal: raising the panel grows the box and leaves the text alone.

| element | was (screen) | now (screen) |
|---|---|---|
| panel scale | 1.75 | **2.0** |
| body text | 18 × 1.75 = 31.5 | 22 |
| mod-list row | 16 × 1.75 = 28 | 20 |
| header | untouched, panel-sized | 34 |
| button text / frame | untouched, panel-sized | 26 / 0.62× |
| list share of body | 0.38 | 0.50 |

`headerText`, `buttonLeft`, `buttonRight`, `buttonLeftText` and `buttonRightText` are all
**private** on the real client assembly — confirmed by the trap test, which now runs 58 checks
and was itself verified to FAIL on a fabricated member name. Direct field access would have
compiled clean and thrown on first use. All five are reflected, saved and restored; a scale or
font size left on the shared `UnifiedPopup` singleton would deform every later confirm dialog
in the session. Styling happens AFTER `Push()` for the same reason the body style does.
Markers `coe`/`cof`/`cog`.
