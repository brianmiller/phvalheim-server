# PhValheim 2.40 — Vanilla worlds, Deep North boss, launch params, admins

Target: ship alongside Valheim 1.0 (Deep North). Version bumped `2.39` → `2.40` (`Dockerfile:5`).

## STATUS (2026-09-08) — built and pushed to `:rc`

| § | Item | State |
|---|---|---|
| 1 | Vanilla worlds (#81) | **DONE** — schema, `startWorld.sh`, build gating, admin UI, create-world, public card, client |
| 2 | Deep North boss | **BLOCKED on the prefab name.** All plumbing done and data-driven; landing it is 3 lines + a PNG |
| 3 | Custom launch parameters | **DONE** |
| 4 | ADMINS editor | **DONE** |
| 5 | Client dead code | **DONE** — `ProgressBar/` removed (524 lines) |
| 6 | Access lists (CITIZENS / ADMINS / BANNED) | **DONE** — see §7 |
| 7 | Valheim 1.0 `V_` ID format | **DONE** — see §8. Confirmed live: permitted + admin |

Tests: `dev_tools/test-startWorld-args.sh` (9 cases, mutation-checked),
`dev_tools/test-bosses.php` (12 cases), and `dev_tools/test-accesslists.sh`
(33 cases end-to-end against a live container, mutation-checked: 9 fail on the pre-§7 code,
8 more on the pre-§8 code). All green.

**To land the boss once the name is known:**
1. Uncomment + fill the Deep North entry in `container/nginx/www/includes/bosses.php`
2. Uncomment + fill the `addColumn` line in `container/engine/dbUpdates/dbUpdate_2.40.sh`
   (the script is column-by-column idempotent, so re-running it on an RC server applies it)
3. Drop `Trophy<NewBoss>.png` into `container/nginx/www/images/`
4. Add a `.trophy-<key>` rule to `css/phvalheimStyles.css` mirroring `.trophy-fader`

The prefab name will appear in `/opt/stateful/logs/phvalheim.log` as
`UNKNOWN BOSS TROPHY: prefab='...'` the first time anyone hangs the new head.

---

## 0. What I found (ground truth, verified in source)

### 0.1 The world password is a hardcoded literal
There is **no `worlds.password` column**. The string `"hammertime"` is baked into three places:

| File | Line | Note |
|---|---|---|
| `container/nginx/www/includes/db_gets.php` | 296 | line 297 is `#$password = $row['password'];` — someone started this and stopped |
| `container/nginx/www/admin/adminAPI.php` | 575 | duplicate of the same launch-string builder |
| `container/games/valheim/scripts/importWorld.sh` | 18 | `worldPassword="hammertime"` |

### 0.2 The password is never actually given to the server
`container/games/valheim/scripts/startWorld.sh` accepts `$2` as `worldPassword` **and never uses it**. Its exec line is:

```
-nographics -batchmode -name $worldName -port $worldPort -world $worldName \
-oldconsole -public 0 -savedir ...
```

So: `-public` is **hardcoded to 0**, there is **no `-password`**, there is **no `-crossplay`**. Every world today is passwordless and unlisted. The password only ever reaches
`BepInEx/config/quick_connect_servers.cfg` (`0-functions.sh:330 createQuickConnectConfig`), which is read by the **quickconnect mod**.

### 0.3 The client never passes the password to Valheim either
`Launcher.cs Launch(ref worldPassword, ...)` **prints** the password and then launches
`steam -applaunch 892970 --doorstop-enabled true --doorstop-target-assembly <preloader>`.
Connection is done entirely by the quickconnect mod reading the cfg file above.

**Consequence for #81:** a zero-mod world has no BepInEx, therefore no quickconnect, therefore
the current connect path does not exist. Vanilla worlds must use Valheim's own
`+connect <host>:<port>` client launch argument.

> ⚠️ Vanilla Valheim has **no client-side argument to pre-fill the server password.** `+connect`
> gets the player to the password prompt; they must type it. That is why 1.2 (show the password
> in the public UI) is not a nicety — it is the required half of the feature.

### 0.4 Every world unconditionally gets BepInEx
`0-functions.sh` runs `InstallAndUpdateBepInEx()` and `installSystemPlugins()` for every world.
A vanilla world must skip both, plus the doorstop `export`s in `startWorld.sh`.

### 0.5 The hung-heads chain (item 2) — fully traced

```
player hangs a boss trophy on the sacrificial stone
  └─ ItemStand.DelayedPowerActivation           [Valheim]
      └─ HungHeads.Postfix                      [phvalheim-companion/HungHeads.cs]
          ├─ guard: !ZNet.IsServer() && !ZNet.IsDedicated()   (runs on the CLIENT)
          ├─ bossHead = __instance.m_supportedItems[0].name   ← prefab name, e.g. "TrophyFader"
          ├─ backend URL ← BepInEx/phvalheim.backend
          │    written by phvalheim-client: PhValheimPrep.cs WriteBackendFile()
          └─ POST {action:"<TrophyName>", world:"<name>"}  →  <backend>/api.php
                                                    [Configuration.cs:15 phvalheimPublicApi]
              └─ container/nginx/www/public/api.php:48-58
                   ├─ hardcoded 7-name whitelist  ← THE ONLY GATE
                   └─ setHungHeads()  [db_sets.php:202]
                        └─ UPDATE worlds SET <strtolower(name)>=1
```

**The single most useful finding: the pipeline is already data-driven end to end except for one
hardcoded list.** `HungHeads.cs` reads the trophy name off the prefab at runtime, so:

- **phvalheim-client needs NO change for the new boss.**
- **phvalheim-companion needs NO change for the new boss.**
- Only the server's whitelist, a DB column, and the public UI need the new name.

Read paths for display: `public/api.php` (`getMyWorldsStatus` → `trophies{}`),
`public/authenticated.php` (server-render ~L126–197 + L238-ish `<td>` cells + JS map ~L460),
`db_gets.php:318 getBossTrophyStatus`.

Existing columns: `trophyeikthyr`, `trophytheelder`, `trophybonemass`, `trophydragonqueen`,
`trophygoblinking`, `trophyseekerqueen` (2.7), `trophyfader` (2.17).

> ⚠️ `setHungHeads()` interpolates `$hungHead` straight into SQL. It is only safe today *because*
> of the api.php whitelist. Keep the whitelist authoritative — see §2.

---

## 1. Item 1 — Vanilla (zero-mod) worlds  [issue #81]

### 1.0 Scope decision (Brian, 2026-09-08)
> **CORRECTED 2026-09-09:** crossplay is **not** vanilla-only. Brian scoped *password* to
> vanilla worlds; I extended that to crossplay and listing on my own. Listing is right to
> keep vanilla-only, but crossplay controls whether Xbox / Microsoft Store players can join
> and is orthogonal to mods — a modded world can legitimately want it. It now applies to
> every world, and `dev_tools/test-startWorld-args.sh` asserts a modded world honours it.
> Nothing caught this originally because every modded test case used `crossplay=0`, so the
> assertion held whether or not the flag was scoped correctly.

**Password and server-browser listing are vanilla-only options.** Modded worlds keep
today's behaviour exactly: gated by the **CITIZENS** list (`permittedlist.txt`), `-public 0`, no
`-password`. `"hammertime"` stays hardcoded on the modded path — it is inert anyway, since the
server has never been given a `-password` to match it against.

This keeps the modded path byte-identical and means #81 cannot regress existing worlds.

### 1.1 🔴 Name collision: `worlds.public` does NOT mean "public server"

Verified: `public` is an **access-control** flag, not a listing flag. `saveCitizensJson`
(`adminAPI.php:892-910`) writes it alongside the citizens list, and when it is `1` it **blanks
`permittedlist.txt`** — i.e. "anyone may join, stop enforcing the citizens list". It is surfaced in
the UI as the checkbox next to CITIZENS (`citizensEditor.php:184`).

If we route `public` into `-public 1`, then **every world an admin ever opened up to all citizens
silently appears in the global Steam server browser** on upgrade. Do not reuse it.

Use a new, separate column `listed` for the online-catalog flag.

### 1.2 Schema — `dbUpdate_2.40.sh`

```sql
ALTER TABLE worlds ADD COLUMN vanilla       TINYINT      DEFAULT 0;
ALTER TABLE worlds ADD COLUMN password      VARCHAR(64)  DEFAULT NULL;  -- vanilla only
ALTER TABLE worlds ADD COLUMN crossplay     TINYINT      DEFAULT 0;     -- ALL worlds
ALTER TABLE worlds ADD COLUMN listed        TINYINT      DEFAULT 0;     -- vanilla only, -public 1
ALTER TABLE worlds ADD COLUMN launch_params VARCHAR(512) DEFAULT NULL;  -- item 3, all worlds
ALTER TABLE worlds ADD COLUMN admins        TEXT         DEFAULT NULL;  -- item 4, all worlds
ALTER TABLE worlds ADD COLUMN trophy<NEW>   BOOL         DEFAULT 0;     -- item 2
```

**No backfill.** Every existing world is `vanilla=0, listed=0, password=NULL` — which is exactly
what they do today. Follow the re-runnable guard idiom (`sql "DESCRIBE worlds" | grep <col>` before
each ALTER) per `dbUpdate_2.7.sh`.

### 1.3 `startWorld.sh` — the load-bearing change

Extend the single `sql` lookup the script already does for `public` to fetch
`vanilla, listed, crossplay, password, launch_params`, then build the arg list:

- **`vanilla=0` (modded):** emit exactly today's args — `-public 0`, no `-password`, no
  `-crossplay`, doorstop `export`s intact. Nothing changes.
- **`vanilla=1`:** `-public $listed`, `-password "$pw"` (**omit the flag entirely when empty**),
  `-crossplay` when set, and **skip the four `DOORSTOP_*`/`LD_*` exports**.
- `$launch_params` appended verbatim last for both, so an operator can override anything.

**Valheim's password rules — the server refuses to boot otherwise, so validate in the UI too:**
- minimum 5 characters
- must not be a substring of the world name or the server name
- `-public 1` **requires** a password → the UI must not let "list it" be saved without one

### 1.4 Build path
Gate on `vanilla`: skip `InstallAndUpdateBepInEx`, `installSystemPlugins`,
`downloadAndInstallTsModsForWorld`, `installCustomModsConfigsPatchers`,
`createQuickConnectConfig`.

`createCustomSeedConfig` is itself a BepInEx mod (`ZeroBandwidth-CustomSeed`), so a vanilla world
cannot get a custom seed that way — it must be set at world-generation time instead. Worth calling
out in the create-world UI.

### 1.5 The vanilla world card is bespoke, not degraded (Brian, 2026-09-08)
A vanilla world has no companion mod, so hung heads, player-join events and tickmonitor data
genuinely do not exist for it. The card must **not** render an empty trophy row or an "unavailable"
notice — a vanilla world is not a second-class citizen.

Instead `authenticated.php` branches on `vanilla` and renders a **different card body**, built from
what a vanilla world *does* have. Where the modded card shows Mods / MD5 / trophy row, the vanilla
card shows:

- **Endpoint** `<gameDNS>:<port>` with a copy button
- **Password** — masked, click-to-reveal, copy button
- **Crossplay** and **Listed in server browser** as badges
- **Seed**, **Deployed**, **Updated**, **Memory** (shared with the modded card)
- A **Join** primary action → `steam://run/892970//+connect <host>:<port>`
  (plus "Copy connect string" for players who prefer the in-game Join IP dialog)

Both cards keep the same outer `.catbox` chrome, online/offline dimming and AJAX refresh contract,
so `getMyWorldsStatus` gains a `vanilla` bool plus a `connection{}` object and the JS picks a
template on it.

Respect the `hideseed` precedent: the password is only rendered for a citizen of that world.

### 1.6 Client (req 1.1)
`Arguments.cs` launch string is positional and base64'd:
`launch?world?password?gameDNS?port?phvalheimHost?httpScheme`.
Add an **8th field `vanilla`** — appending keeps every older client working (they just ignore it),
and `argumentsPassed` is index-accessed so nothing shifts.

`Launcher.cs` then, for `vanilla=1`, launches `-applaunch 892970 +connect <host>:<port>` with
**no doorstop arguments**, and `Syncer.cs` skips the BepInEx/world-file sync entirely — there is
nothing to sync.

The password still travels in the launch string (field 2) so the client can print it and offer to
copy it, but it cannot be injected into Valheim — see §0.3.

---

## 2. Item 2 — Deep North boss

Make the whitelist data-driven so this is a one-line change now and forever:
new `container/nginx/www/includes/bosses.php` exporting one ordered array
(`key`, `column`, `trophyPrefab`, `displayName`, `icon`), consumed by `public/api.php`,
`public/authenticated.php`, and validated against in `setHungHeads()`.

Then the new boss is: **one array entry + one DB column + one PNG.**

### Change surface for the new boss
| # | File | Change |
|---|---|---|
| 1 | `container/engine/dbUpdates/dbUpdate_2.40.sh` | `ADD COLUMN trophy<newboss>` |
| 2 | `container/nginx/www/includes/bosses.php` | new array entry |
| 3 | `container/nginx/www/images/Trophy<NewBoss>.png` | icon asset |
| 4 | `container/nginx/www/css/phvalheimStyles.css` | `.trophy-<key>` rule (mirror `.trophy-fader`) |
| 5 | `container/nginx/www/public/authenticated.php` | falls out of the array once refactored |
| 6 | `container/nginx/www/public/api.php` | falls out of the array once refactored |

**No change** to phvalheim-client. **No change** to phvalheim-companion.

### 🔴 Blocking unknowns — need the 1.0 build to confirm
1. The boss's **trophy prefab name** (`TrophyX`) — this is the literal `action` value POSTed. It is
   the prefab's `name`, not its display name. Grab it from a companion log line
   (`Completed BossStone Detected: <name>`) on first kill, or from the 1.0 asset dump.
2. Whether Deep North's altar still uses `ItemStand.DelayedPowerActivation`. If Iron Gate changed
   the offering mechanic, `HungHeads.cs` needs a new Harmony target and **the companion mod does
   need a release** after all.

Until (1) is known, ship the array with the entry present but commented, and have `api.php` log
any rejected `Trophy*` action to `/opt/stateful/logs/` — that turns the first real kill into the
answer instead of a silent no-op.

---

## 3. Item 3 — Custom launch parameters

`worlds.launch_params` (§1.1). Textarea in the world Settings modal
(`admin/index.php` L710–721, "Settings" tab). New `adminAPI.php` cases
`getLaunchParams` / `saveLaunchParams`.

Appended last in `startWorld.sh` so it can override generated flags. Warn in the UI that a bad
value silently prevents the world from booting; changes require a world restart.

> ⚠️ This value is interpolated into a supervisor `command=` line. Reject `;`, `&`, `|`, backticks
> and `$(` — otherwise it is arbitrary command execution as the `phvalheim` user by anyone with
> admin UI access.

---

## 4. Item 4 — ADMINS editor

Exact mirror of Citizens, which is the template to copy:
- `adminAPI.php:61-77` (`getCitizens` / `saveCitizens` cases)
- `adminAPI.php:877-916` (`getCitizensJson` / `saveCitizensJson`, incl. `permittedlist.txt` write)
- `db_sets.php:124 setCitizens`, `db_gets.php getCitizens`

New: `getAdmins` / `saveAdmins`, `setAdmins` / `getAdmins`, writing
`.../IronGate/Valheim/adminlist.txt` (`// List admin players ID ONE per line`) next to
`permittedlist.txt`. UI section goes under CITIZENS in the Settings modal tab.

Valheim reads `adminlist.txt` at start; note in the UI that it needs a world restart.

> `saveCitizensJson` interpolates into SQL via `setCitizens`. Don't copy that part — parameterise
> the new one.

---

## 5. Item 5 — phvalheim-client dead code

**Confirmed dead — `ProgressBar/` (6 files, 524 of 1591 lines, 33% of the codebase).**
Every file declares `namespace UpdateHOB` — it was pasted in from an unrelated project. Nothing
outside the directory references `ConsoleProgressBar`, `FileTransferProgressBar`,
`ProgressAnimations`, `ProgressEventArgs`, `FileHelper` or `Result`; they only reference each
other. Delete the directory and the `<Folder Include="ProgressBar\" />` line in
`phvalheim-client.csproj:51`.

Everything else is reachable: `Downloader` ← `Syncer.cs:105`, `Tooling` ← `Syncer.cs:53,175`,
`Steam`/`Launcher`/`Syncer`/`Ver`/`Prep`/`Arguments`/`Platform` ← `Main.cs`. `Octokit` is used by
`Version.cs` — keep it.

Two real defects found while scanning, worth fixing in the same pass:
- `Downloader.Go` is `async void` with a blocking `completedSignal.WaitOne()` — fire-and-forget,
  so `Syncer` cannot observe a failed download. Should be `async Task` and awaited.
- `WebClient` is obsolete in .NET 9 (`SYSLIB0014`); `HttpClient` is the replacement.

---

## 6. Suggested order

1. `dbUpdate_2.40.sh` + `Dockerfile` bump to 2.40 — everything else depends on the columns
2. `bosses.php` refactor (no behaviour change, all 7 existing bosses) — de-risks item 2
3. `startWorld.sh` + `0-functions.sh` vanilla gating (modded path must stay byte-identical)
4. adminAPI + Settings modal: password, crossplay, `listed`, launch params, admins
5. Public UI: bespoke vanilla card + `connection{}` in `getMyWorldsStatus`
6. Client: 8th launch-string field, `+connect` path, delete `ProgressBar/`
7. New boss entry — **last, once the prefab name is confirmed**

### Regression checks that actually discriminate
Per the standing lesson about tests that pass either way, each of these must fail if the change is
wrong:

- **Modded path unchanged:** diff the generated `valheim_server.x86_64` arg list for an existing
  world before/after. Must be identical, including `-public 0`.
- **`public` ≠ `listed`:** create a world with `public=1` (citizens gate off) and `listed=0`.
  Assert the boot args contain `-public 0`. This is the upgrade-safety test for §1.1.
- **No password ⇒ no flag:** assert the string `-password` is absent, not that it is empty —
  `-password ""` makes Valheim refuse to boot.
- **Vanilla has no BepInEx:** assert `BepInEx/` does not exist in the built world dir *and* that
  no `DOORSTOP_*` var is in the process environment. A world that launches proves nothing; the
  doorstop exports are harmless when the DLL is missing.
- **`launch_params` injection:** feed `; touch /tmp/pwned` and assert the file is not created.

---

## 7. Access lists — CITIZENS / ADMINS / BANNED (reported 2026-09-09)

Brian's report: *"the CITIZENS editor ... doesn't seem to be respected anymore and users are
not being allowed into their servers. ADMINS doesn't write at all to the adminlist.txt file."*

### 7.1 Ground truth, measured against the real dedicated server

Before changing anything I downloaded the actual Valheim dedicated server (app 896660) and
observed it, because every theory about these files hinged on behaviour I could not read off
the source. `dev_tools/` has no copy of the probes; they are recorded here.

| Question | Answer | How it was established |
|---|---|---|
| Where does Valheim read these files? | The `-savedir` **root** | Started with a clean savedir; it created `adminlist.txt`, `bannedlist.txt`, `permittedlist.txt` there and nowhere else |
| Does it create missing ones? | Yes, at startup, with a header comment | Same run — the savedir began empty |
| Does it clobber external edits? | **No** | Pre-populated all three before boot: entries survived. Edited all three while running, waited 90s: entries survived, including across a clean shutdown |
| Exact header text | `// List permitted players ID ONE per line`, `// List admin players ID  ONE per line`, `// List banned players ID  ONE per line` | Read back from the files it wrote. **The admin and banned headers have a DOUBLE space** — that is Valheim's, not a typo |

So PhValheim's path was right all along, and writing these files from outside is safe. The
fault was never *where* or *whether Valheim cooperates* — it was the write itself.

### 7.2 Root cause — a silently swallowed write

Both editors did:

```php
if (is_dir($dirPath)) {
    file_put_contents($path, $header . "\n" . $ids);   // return value ignored
}
echo json_encode(['success' => true, 'message' => 'Saved successfully']);
```

`file_put_contents()` cannot replace a file the web user does not own, and **nothing checked**.
Reproduced end-to-end against a live container: with the list files owned by `root` (which an
older engine, or `worldRestore` running as root, can leave behind), the API returned

```
{"success":true,"message":"Citizens saved successfully"}
{"success":true,"message":"Admins saved. Restart the world for this to take effect."}
```

the database updated, and **neither file changed**. PHP logged `Failed to open stream:
Permission denied` where no operator would ever see it.

That is both reported symptoms from one defect:

* **CITIZENS "not respected"** — `permittedlist.txt` freezes with an older list, so a player
  the operator just added is genuinely refused. The file is non-empty, so Valheim keeps
  enforcing it.
* **ADMINS "doesn't write at all"** — `adminlist.txt` never picks up any change, so it looks
  like the editor does nothing.

### 7.3 Contributing defects found while tracing it

* **Nothing rewrote these files from the database, ever.** The admin UI was the only writer.
  A restore or rebuild silently reinstated an old list, permanently.
* **`worldDirPrep` created only `permittedlist.txt`.** The ADMINS editor was writing a file
  that did not exist until Valheim's first boot created it.
* **`importWorld.sh` wrote `adminlist.txt` but never set `worlds.admins`.** An imported world
  showed no admins in the Settings modal, and under the new sync would have lost them.
* Citizens were not validated as SteamID64, though admins were.

### 7.4 The fix

* `nginx/www/includes/accesslists.php` — one module owning the paths, the header text, and the
  write. Writes are **atomic (temp + `rename`)**, which needs permission on the *directory*
  rather than the target file, so it succeeds where `file_put_contents()` failed *and* leaves
  the file owned by the web user, repairing the condition permanently. Every failure path
  returns an error; callers surface it.
* `games/valheim/scripts/syncAccessLists.sh` — renders all three files from the database, run
  from `startWorld.sh` on **every** world start and from `worldDirPrep`. The database is now
  the single source of truth and drift heals itself on the next restart.
* BANNED list added end to end: `worlds.banned` column, `getBanned`/`saveBanned`, Settings
  modal section, and the engine sync.
* `citizensEditor.php` (the legacy page) routed through the same module and now shows write
  failures.

### 7.5 Why the tests are worth something

`dev_tools/test-accesslists.sh` asserts on **the bytes in the real files**, never on what the
API said — the bug was an API that said "saved" about a file it had not written. It runs
against a live container and includes a control case proving the assertions can detect a wrong
file at all.

Mutation-checked: restoring the pre-fix `adminAPI.php` and `0-functions.sh` turns **9 of the
22 cases red**, including the two reported symptoms verbatim. A suite that stays green against
the broken code would have been worthless here.

### 7.6 Not established

Whether a **running** Valheim server re-reads these files, or only reads them at startup, was
not determined — proving it needs a real game client connecting. The UI therefore tells the
operator a restart is required for all three lists, which is correct either way.

---

## 8. Valheim 1.0 broke the access lists — the `V_` prefix (2026-09-09)

Brian tested §7 on the live server and still got **"Banned"** with `public` off and his SteamID64
in `permittedlist.txt`. That turned out to be a *second*, unrelated fault: a Valheim 1.0
behaviour change, not anything §7 introduced.

### 8.1 What the live log established

```
17:42:13  bare SteamID64 in file   -> Kicking player not in permitted list <player> host: 76561198XXXXXXXXX
18:39:03  bare SteamID64 in file   -> Player <player> : 76561198XXXXXXXXX is blacklisted or not in whitelist.
18:41:04  list emptied (public on) -> New peer connected ... Got character ZDOID from <player>   [ADMITTED]
```

Three facts, all from the server's own log:

1. A **bare SteamID64 does not match** — it is in the file, Valheim reads the file, and kicks anyway.
2. An **empty list still means "allow everyone"** — that is the only reason 18:41 succeeded.
3. **Valheim hot-reloads these files.** Rejected at 18:39, admitted at 18:41, with no
   `Game server connected` line in between, so no restart. This retires the open question from §7.6
   and the "restart required" wording that went with it.

Also: `rpc.Invoke("Error", 8)` is the allowlist rejection, and the client renders error 8 as
**"Banned"**. A player who is merely not on the allowlist is told they are banned.

### 8.2 Root cause, from the decompiled assembly

`ZNet.ListContainsId()` (ilspycmd on the shipped `assembly_valheim.dll`):

```csharp
if (val.m_platform == m_steamPlatform)
    flag = list.Contains(val.ToString()) || list.Contains(val.m_userID.ToString());  // bare DOES match here
else
    flag = list.Contains(val.ToString());

PlatformUserID val2 = PlatformUserID.FilterPlatformUserID(val);
if (val2 != val)
    flag = list.Contains(val2.ToString());     // <-- ASSIGNS over the result, does not OR into it
```

and in `Splatform.dll`:

```csharp
s_platformToDisplayPrefixes = { PlayStation:"S", Xbox:"X", Nintendo:"N", GameCenter:"A", Steam:"V" }

FilterPlatformUserID(platID):
    platform = s_platformToDisplayPrefixes[platID.m_platform]        // "Steam" -> "V"
    if (IsPlatformUserIDNumberFiltered(platID))                      // false for Steam
        return new PlatformUserID(platform, id * 11400714819323198485);
    return new PlatformUserID(platform, id);

PlatformUserID.ToString() => $"{m_platform}_{m_userID}"
```

So for a Steam player `val2` is `("V", id)`, `val2 != val` is always true, and the final line
throws away the bare and `Steam_` matches. **Only `V_<steamid64>` can ever match.** Verified live:
`V_76561198XXXXXXXXX` in `permittedlist.txt` admitted him immediately, with no restart.

This is a Valheim bug. Steam is not number-filtered, so the "filtered" ID is a plain relabel
obviously meant for display. The same overwrite makes Valheim's own `ban` command broken:
`InternalBan()` stores `peer.m_socket.GetHostName()` — the bare host name — which
`ListContainsId()` then cannot match.

### 8.3 Scope — all three lists, one function

| List | How it is checked | Needs `V_` |
|---|---|---|
| `permittedlist.txt` | `ListContainsId(m_permittedList, hostName)` | yes |
| `adminlist.txt` | `ListContainsId(m_adminList, hostName)` — every admin RPC, and `IsAdmin()` | yes |
| `bannedlist.txt` | `ListContainsId(m_bannedList, hostName) \|\| m_bannedList.Contains(playerName)` | yes (**or** a bare character name, which also bans) |

Permitted and admin are confirmed live. Banned is inference from the identical call — not tested,
because testing it means kicking the only player available.

### 8.4 The fix

Convert at the last moment, in both writers, keeping the DB human-readable:

* `canonicalAccessId()` in `includes/accesslists.php` and `canonicalId()` in
  `syncAccessLists.sh` — deliberately duplicated logic, and test case 12 asserts the two agree,
  because a disagreement would mean a world start silently rewrites what the UI just saved.
* Bare 17-digit → `V_`; `Steam_`/`Xbox_`/`PlayStation_`/`Nintendo_`/`GameCenter_` → their letter;
  `V_`/`X_`/`S_`/`N_`/`A_` pass through.
* Console IDs are opaque (number-filtered), so they are accepted as-is and **cannot be derived** —
  the UI now tells operators to get them from the player's **F2** panel, which is also the one
  method that works for every platform.
* The validator added in §7 only accepted 17 digits, which would have made console players
  impossible to add at all. That was my regression, cleared here.

Forward-safe: if Iron Gate fixes the overwrite, `V_<id>` still matches, because it parses back to
the same `(Steam, id)` pair.

### 8.5 Tests

`dev_tools/test-accesslists.sh` grew to **33 cases**. The new ones (9–12) are mutation-checked:
reverting `accesslists.php` and `syncAccessLists.sh` turns exactly **8** of them red.
