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

Tests: `dev_tools/test-startWorld-args.sh` (9 cases, mutation-checked) and
`dev_tools/test-bosses.php` (12 cases). Both green.

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
**Password, crossplay and server-browser listing are vanilla-only options.** Modded worlds keep
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
ALTER TABLE worlds ADD COLUMN crossplay     TINYINT      DEFAULT 0;     -- vanilla only
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
