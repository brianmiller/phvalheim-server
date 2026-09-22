# Changelog

## v2.50

### A failed Valheim update was reported as a success, and that disabled the retries

Reported from a world log that read, in order:

```
Error! App '896660' state is 0x6 after update job.
[NOTICE : phvalheim] Valheim server installed successfully for 'Brotality'
```

`InstallAndUpdateValheim()` decided whether steamcmd had worked like this:

```sh
# Check if valheim_server.x86_64 was installed
if [ -f ".../game/valheim_server.x86_64" ]; then
        steamcmdSuccess=true
        echo "... Valheim server installed successfully for '$worldName'"
fi
```

`StateFlags` `0x6` is `StateFullyInstalled|StateUpdateRequired` — Steam has the files on disk
and still considers the app to need an update. The server binary is therefore present in
exactly the failure this check exists to catch, so it returned the same answer whether the
update had completed or not. A non-oracle: it could never go red.

**Second-order damage.** `steamcmdSuccess=true` is also the loop condition. Setting it on a
failed run broke out of the retry loop on attempt 1, so the five retries never ran for the one
fault they were written for. The operator got a single failed attempt, labelled a success.

**The fix.** Two new functions in `0-functions.sh`:

- `valheimAppStateFlags()` reads `StateFlags` out of `game/steamapps/appmanifest_896660.acf`
  — the manifest steamcmd writes for itself, and the same file `updateChecker.py` already
  reads the installed buildid from. Not a new dependency; an existing one asked a second
  question.
- `valheimInstallVerdict()` returns **three** states: verified (0), did not complete (1),
  installed-but-unverifiable (2). The third is not folded into either of the others — a
  missing manifest is unknown, and refusing to run a world over it would be a worse bug than
  the one being fixed.

`StateFlags` is a bitmask, so "is it 6" is the wrong test. Success requires `0x4` set and
every not-done bit clear (`0x1|0x2|0x8|0x20|0x80|0x100|0x200|0x400|0x800` = 4011). The first
cut of this patch checked only bits `0x4` and `0x2`; `dev_tools/test-steamcmd-install-verdict.sh`
caught that state 12 (update queued) and state 36 (files missing) were still verifying as
clean. `0x10 UpdateOptional` is deliberately outside the mask — an optional update on offer
says nothing about whether this install finished, and failing on it would stop healthy worlds.

A run that fails all five attempts now also logs `df -h` for the worlds volume. A partial
steamcmd update leaves state `0x6` and no other clue, and the usual cause is simply a full
disk.

**Tests.** `dev_tools/test-steamcmd-install-verdict.sh` drives the shipped functions against
fixture manifests (states 4, 6, 12, 36, 1, 20, no-manifest, no-binary). Mutation-checked:
reverting `valheimInstallVerdict` to the bare existence test turns 5 of the 10 cases red.

### World creation was gated on a chown exit status, and deleted the world when it failed

Found while investigating an unrelated report. `phvalheim` decided whether a new world had
deployed like this:

```sh
chown -R phvalheim: $worldsDirectoryRoot/$worldName
RESULT=$?
if [ $RESULT = 0 ]; then
        # ...created...
else
        deleteWorldModRows "$worldName"
        SQL "DELETE FROM worlds WHERE name='$worldName'"
        rm -rf /opt/stateful/games/valheim/worlds/$worldName
fi
```

`chown -R` answers "could I change ownership of every file I walked". That is not "did this
world deploy", and the two come apart in both directions: **one** unchownable file — NFS
`root_squash`, an immutable bit, a file vanishing mid-walk — destroyed a world whose
deployment had gone fine, while an incomplete but chownable tree passed as created.

This is the third appearance of the same bad oracle. `InstallAndUpdateValheim` carries a
comment describing it exactly, from when it turned a successful update into "update failed";
there it printed a wrong message. Here it deleted the world. The branch also handles
**clones**, whose directory arrives already holding a copied save.

The `chown` was additionally redundant: `worldDirPrep()` ends with that identical command and
runs immediately above on this path. It existed only to set `RESULT`.

**Two changes.** `worldDirIsPrepared()` asserts the postcondition — the six directories
`worldDirPrep` is contracted to produce — and names any that are missing. And a failed
deployment is now **marked `mode='broken'` and otherwise left alone**. `broken` is the state
this engine already uses at three other sites, none of which delete anything; the create
branch was the outlier. A deleted row is indistinguishable from a world that never existed,
so the operator watched their world vanish with the only explanation in the engine log.

Removing the `rm -rf` also deletes a second, partial implementation of "remove a world" — it
skipped the orphan-PID kill and `deleteSupervisorWorldConfig` that the real delete path does.
Two implementations of one operation drift; the UI's Delete already does it correctly.

**Tests.** `dev_tools/test-world-deploy-verdict.sh`, 13 assertions. The oracle case is a
complete tree containing a file that cannot be chowned: the old code destroyed that world,
and it is a perfectly good deployment. Mutation-checked against restored chown-gating.

Three of that test's first-run failures were **its own probes**, not the code: two markers
matched the new comments, which quote `rm -rf` and `RESULT=$?` verbatim while explaining the
bug, and the fixture for "missing `game`" recreated `game` via `mkdir -p` of the savedir
beneath it. Both the test and the build markers now read the engine with comments stripped.

### Escalating self-repair, and the reason it is not a reinstall

Restoring the retries exposed the next problem: five attempts ran the *identical* command
against *identical* state, so they could only produce the identical failure. The retries were
honest but useless.

`healSteamcmdState()` now escalates. Level 2 resets the steamcmd bootstrap and repairs
ownership and permissions on the game tree; level 3 additionally discards a partial transfer;
level 4 additionally drops the manifest, forcing a full re-verify. Each step logs what it did.

Permission repair is at level 2 deliberately — it is the cheapest real fault to fix, it is
non-destructive, and `docs/RELEASING.md` already records steamcmd failing for want of a
writable `HOME` as a trap this project has hit before.

**The constraint that shaped the whole function.** `startWorld.sh` passes

```
-savedir /opt/stateful/games/valheim/worlds/$worldName/game/.config/unity3d/IronGate/Valheim
```

**The world save lives inside the game directory.** So does `BepInEx`. The obvious
implementation of self-healing — wipe the game dir and let steamcmd reinstall — would have
deleted every world save on every server that hit a failed update. Everything the repair
touches is therefore confined to `game/steamapps` plus the two steamcmd bootstrap dirs, and
the function carries a comment saying so in the imperative.

**Tests.** `dev_tools/test-steamcmd-self-heal.sh`, 32 assertions. The survival cases are the
real subject: saves, world db, `permittedlist.txt`, BepInEx plugins, the loader config and
installed game content are asserted intact at *every* level, plus an empty-world-name guard.
Mutation-checked: replacing the repair with `rm -rf "$game"` turns 22 cases red, the first six
being the data-loss ones. The build gate additionally asserts the repair function never so
much as names `unity3d`, `BepInEx` or `savedir`, and pins its `rm` count at 5 so a sixth
cannot be added without someone re-reading the path list.

## v2.49

### The world log stopped naming loaded plugins, and the client's BepInEx window stopped appearing

Two symptoms, one deleted file.

`BepInEx/config/BepInEx.cfg` is the **loader's** config. The loader is engine-installed on
every modded world (2.44+) and is deliberately not a mod — but its config was being treated as
one. `purgeWorldModsConfigsPatchers()` clears mod configs on every world rebuild so a removed
mod cannot leave a stale one behind, and it did this:

```sh
rm -rf $worldsDirectoryRoot/$worldName/game/BepInEx/config/*
```

That runs at `phvalheim:457`, three lines after `InstallAndUpdateBepInEx()` installs the pack
at `:454`. So the pack's cfg was laid down and immediately deleted, and nothing restored it
before the world booted or before the client payload was built.

With no cfg, BepInEx regenerates one from its **stock defaults, where
`[Logging.Console] Enabled = false`**. One setting, two visible consequences:

| | how it breaks |
|---|---|
| World log | BepInEx's console logger writes to **stdout**, and supervisor captures a world's stdout into `valheimworld_<name>.log`. Console off ⇒ the world log never sees BepInEx, so no `Loading [Plugin x.y]` lines. |
| Client window | `packageClient()` zips `./BepInEx` wholesale. No cfg on the server ⇒ no cfg in the payload ⇒ the client's console window never opens. |

Mods were loading correctly throughout. Only the reporting of it was gone — which is why this
looked like two unrelated regressions rather than one.

**Fixes**

- `purgeWorldModsConfigsPatchers()` clears mod configs with a scoped `find ... ! -name
  'BepInEx.cfg' -delete`. Mod configs and their subdirectories go exactly as before; the
  loader's own config stays.
- `InstallAndUpdateBepInEx()` stashes the pack's `BepInEx.cfg` to `game/bepinex_default.cfg`
  before the unpacked pack directory is removed. It cannot rely on `rsync -purval` to deliver
  it: `-u` skips any file the world already has with a newer mtime, and BepInEx rewrites its
  cfg on every boot.
- New `ensureBepInExLoaderConfig()` restores the cfg when missing (from the stash, else a
  minimal one) and asserts `[Logging.Console] Enabled = true`, scoped to that section —
  `[Logging.Disk]` has an `Enabled` key too. Called from `phvalheim` after
  `installCustomModsConfigsPatchers()` and before `packageClient()`, so it is the last word
  before the payload is built. It is idempotent and skips vanilla worlds entirely.

Already-affected worlds repair themselves on the next rebuild.

### Changing Game DNS never reached quick_connect_servers.cfg

Open for years. `worlds.external_endpoint` was stamped from `gameDNS` when a world was
**created** and never touched again — the column had **two INSERTs and zero UPDATE statements
in the entire tree**. The engine read that frozen copy into `$worldHost` and handed it to
`createQuickConnectConfig()`, so editing Game DNS in Server Settings changed nothing a player
would ever see through QuickConnect.

What made it so hard to pin down: the **Steam launch string reads `gameDNS` live**
(`admin/index.php:120`, `adminAPI.php:914`) while **QuickConnect read the frozen column**. After
a DNS change one join path worked and the other didn't.

Reproduced live before changing anything:

```
BEFORE  settings.gameDNS=valheim.example.com   midgard endpoint=valheim.example.com
        (admin changes Game DNS)
AFTER   settings.gameDNS=newdns.example.org    midgard endpoint=valheim.example.com
        engine would feed createQuickConnectConfig: worldHost=valheim.example.com
```

**Fixes**

- `phvalheim` takes `worldHost` from the **live `$gameDNS`**, falling back to the stored
  column only when `gameDNS` is unset (setup wizard unfinished) — so it can never write an
  empty host.
- The update path now runs `UPDATE worlds SET external_endpoint='$worldHost'` immediately
  before writing the cfg, so the setting, the file and the admin UI display cannot drift apart
  again. This is what makes "update your worlds" a real remedy rather than advice.
- **`importWorld.sh` never assigned `$worldHost` at all** — it expanded to nothing, so every
  imported world got a `quick_connect_servers.cfg` with an **empty hostname** and a QuickConnect
  entry that could not resolve. It now uses the same `gameDNS` the INSERT already stores.

**A second read of `gameDNS` that was not live.** The first fix above read `$gameDNS`, which
the engine sets **once at startup**:

```sh
export gameDNS=$(SQL "SELECT gameDNS FROM settings")    # line 84, engine boot only
```

The engine is a long-running process — weeks between restarts — so that variable holds
whatever Game DNS was when the container last booted. Worse, the main loop opens with
`source /etc/environment`, and that file is written once at boot from `printenv`, so it
actively **re-imposes** the stale value on every pass. A world updated right after a DNS
change still got the old hostname written into its cfg, with every other part of the fix
working correctly.

`gameDNS` is now re-read from the database on every loop pass, **after** the environment
source — placed before it, the refresh is silently undone. It stays exported because
`importWorld.sh` and the other spawned scripts read it too.

**The notice.** The cfg is only rewritten when a world is updated, so changing the setting
alone still isn't enough. Saving a *changed* Game DNS now raises a blocking dialog saying every
world needs updating before players get the new address. It fires only on an actual change, and
it blocks the page reload — a status line would have been wiped a second later by the reload,
which is part of why this went unnoticed for so long.

### Tests

`dev_tools/test-gamedns-quickconnect.sh` — models the engine's update path and pins its
assertions to the engine source, including a NEGATIVE that the frozen read survives at exactly
one site (the fallback). Three cases fail against the pre-fix code.

### Related, and worth knowing

`denikson/BepInExPack_Valheim` **5.4.2350** (2026-09-09, BepInEx core 5.4.23.5) carries upstream
fixes for this same area on Unity 6 — Valheim 1.0 runs `v6000.0.61f1`, and its stripped Unity
log callbacks used to take the chainloader down:

```
* v5: Don't kill the chainloader when Unity log callbacks are stripped (#1392)
* Fold the two Unity 6 log writer probes into one
* Fix logging issues in UnityLogListener.cs for Unity 6
```

`InstallAndUpdateBepInEx()` compares `bepinex_version.txt` against the feed's latest, so a
world still on 5.4.2333 picks 5.4.2350 up at its next update. No code change was needed for
that, and it is not what caused either symptom.

### Tests

`dev_tools/test-bepinex-loader-config.sh` — seven cases, all of which fail against the pre-2.49
code: the purge keeping the cfg while still clearing mod configs, restore-from-stash, the
no-stash fallback, the section-scoped console flip (asserting `[Logging.Disk]` is untouched),
appending a missing section, idempotency, and leaving vanilla worlds without a BepInEx tree.

## v2.48

### Restoring a backup generated a fresh world (issue #89)

Every world backup taken since 2.38 restored into the wrong directory, so the server found no
save at its `-savedir` and generated a new world. The restore reported success.

`worldRestore` supports two archive layouts, and picked between them like this:

```sh
tar tf "$backupFilePath" | grep -q "worlds_local/" && isOldFormat=1
```

**Both layouts contain `worlds_local/`.** They differ only in where it sits, because the two
generations of `worldBackup` archive from different directories:

| | archives from | `worlds_local/` appears at |
|---|---|---|
| pre-2.38 | `$worldDir/game/.config/unity3d/IronGate/Valheim` | `./worlds_local/` — archive root |
| 2.38+ | `$worldDir` | `./game/.config/unity3d/IronGate/Valheim/worlds_local/` |

Unanchored, that `grep` matched every modern archive too. So every restore took the legacy
branch and unpacked the whole world tree into
`$worldDir/game/.config/unity3d/IronGate/Valheim/`, leaving the real save at

```
<worldDir>/game/.config/unity3d/IronGate/Valheim/game/.config/unity3d/IronGate/Valheim/worlds_local/<world>/
```

one full world tree below where Valheim looks. The `-savedir` `worlds_local` was empty, so
Valheim did what it does with an empty save directory: generated a new world. The 2.38+ branch
was unreachable code — it had never run in any release.

The probe is now anchored to the archive root, which is the actual distinction:

```sh
grep -qE '^\./worlds_local/|^worlds_local/'
```

It is a function, `archiveListingIsLegacy`, so the regression test exercises the shipping code
rather than a copy of it.

### Recovering worlds already damaged (step 5b)

Fixing the probe stops new damage but cannot help a world already restored by 2.38–2.47: that
world directory is nested, and since automatic backups run every 30 minutes, **every backup
taken since is nested too**. Unpacking one of those correctly still leaves the save buried and
still boots a fresh world, so an affected operator had no route back through the UI.

A world directory can never legitimately contain
`.../IronGate/Valheim/game/.config/unity3d/IronGate/Valheim` — a real Valheim save directory
holds `worlds_local/`, `cache/` and the access lists, never another `game/` tree. That doubled
path is an unambiguous signature of the old bug, so restore now lifts the buried tree back to
the world root. Each pass peels one level and the loop is bounded, so a world damaged by two
successive bad restores (nested three deep) also recovers. No data was ever deleted: the save
was inside the archive the whole time, and the pre-restore safety backup taken before every
restore still holds the state from the moment it happened.

### Verification

Verified on the shipped image, not only in the harness. On a fresh 2.48 container, for a vanilla
world and a modded one (BepInEx + NoMovementPenalty), each generating its **own** Valheim save:

- backup **and** restore driven through the real admin HTTP API (`nginx` → `php-fpm` →
  `adminAPI.php` → `startDetachedJob`), with the job polled the way the UI polls it;
- the restored save byte-identical by `sha256`, and **still byte-identical after the live engine
  finished the post-restore `mode='update'` rebuild** — the rebuild does not clobber it;
- BepInEx and the selected mod reinstalled by that rebuild;
- Valheim then **loaded** the restored world (`ZNet.LoadWorld`) with its seed preserved, where the
  pre-fix code generated a new one (`LDDQYb6tzP` → `JQrJyFl97b`);
- all three archive formats — `.tar`, `.tar.gz`, `.tar.zst` — restore byte-identically, the last
  of which exercises the separate `eval` branch;
- `backupDir` on its own mount whose `st_dev` matches its parent's, which is the condition that
  broke backup detection in 2.42;
- and the reporter's whole chain: healthy backup → the **real pre-fix code** buries it → the
  30-minute automatic backup captures the nesting → 2.48 restores that nested archive and returns
  every original byte.

Two log lines are *not* evidence of loading and were discarded as oracles: `Loading: Generating
locations` appears on loaded worlds too, and a freshly generated world loads `0 zdos`. The seed in
`_main.N.fwl2` is the discriminator, because generation always changes it.

Guarded by `dev_tools/test-restore-format-detection.sh`, which extracts both the probe and the
repair loop out of `worldRestore` itself. Re-introducing the unanchored `grep` fails 6 of its
checks; deleting the repair fails another.

## v2.47

### Player counts

Valheim gives a dedicated server no reliable live player count, and three of the four
obvious ways to get one do not work here:

- **Socket enumeration** — the server serves every peer from one unconnected UDP socket,
  so there is nothing per-peer to enumerate.
- **conntrack** — `/proc/net/nf_conntrack` is absent (netlink only), the `conntrack` CLI
  ships in neither the host nor the image, and a *bridged* container's conntrack table does
  not contain the host's DNAT entries. Reading them needs host netns or `NET_ADMIN`, which
  cannot be required of an image other people run on Unraid, K8s and plain Docker.
- **The TickMonitor plugin** — already computes a correct count via `ZNet.GetNrOfPlayers()`
  and already writes it to `tick_stats.json`, but it is a BepInEx plugin and vanilla worlds
  have no BepInEx to load it.

What does work splits by the world's `crossplay` flag, and the split is not cosmetic. A
crossplay world logs an absolute count on every join and leave (`… now N player(s)`). A
non-crossplay world logs `Connections N` every ten minutes, with `Got connection SteamID` /
`Closing socket` filling the gaps. `Connections N` reads **0 on a crossplay world with a
player connected** — verified against a real session — so using it everywhere would have
reported every crossplay world as permanently empty and restarted servers out from under
players.

Two traps are guarded in code and pinned by tests:

- `Closing socket` is logged **twice** per departure, the copies differing only in the run
  of spaces after the timestamp. Measured on a real log: 24 lines, 12 departures. Stripping
  leading whitespace does nothing — the difference is mid-line.
- `player_count_at` stores the **log line's** timestamp, never the scan time. A nonzero
  count can stick after everyone leaves (observed: 39 minutes). A scan-time column would
  refresh every two minutes, never look stale, and make auto-update wait forever on an
  empty world.

Counts appear on the admin world rows always, and on the public page per world by opt-in.
Both say *approximate*, and nothing anywhere claims a world is "empty".

### Automatic game and mod updates (#87)

Off by default; upgrading changes no behaviour until it is switched on.

`updateChecker.py` records what is available and never applies anything. The published
Valheim buildid is fetched **once per run** with `app_info_print` and compared against each
world's own `appmanifest_896660.acf`, so N worlds cost one steamcmd call rather than N
downloads. Mod updates come from comparing `world_mods.installed_version_id` — what the
installer actually put on disk — against the catalogue's current `latest_version`. **Pinned
mods are excluded entirely**: not counted, not reported, never updated.

A cold check costs ~31 seconds, essentially all of it steamcmd signing in; `app_info_update`
accounts for none of it. The published buildid is the same for every world, so it is cached
in `settings.publishedBuildid` for 15 minutes — cold 32.4s, cached 0.40s, measured. steamcmd
also needs an explicit `HOME`: it runs as the `phvalheim` user, whose inherited home is not
writable, and without one it dies before printing anything.

`updateApplier` decides only *when*. Every gate must say yes and anything unestablished
counts as no: a world with no player observation at all is never considered idle. A backup
is taken first by default, and a failed backup abandons the update rather than proceeding
without a way back. Stopped worlds are untouched — they update on next start, as before.

Per-world overrides mirror the backup system exactly, including that a global "on for all
worlds" does not overrule a world explicitly set to off.

### Recording what is actually installed

Update detection first hung off `worlds.modsViewer`. That was wrong, and wrong in a way that
looked right: `modsViewer` is the **display cache** behind the admin UI's mod dropdown, and
every version in it comes from `effective_version()` — the live catalogue. Comparing it
against the catalogue compared a number with itself, so it could only ever answer "up to
date". It gave correct answers purely because both of its writers sit immediately after a mod
install; anything that refreshed it at some other moment would have silently rewritten every
"installed" version to whatever was newest and blinded every world, permanently.

So the fact is recorded rather than derived:

```sql
world_mods.installed_version_id  -- mod_versions.id; immutable, so a catalogue resync
world_mods.installed_at          -- cannot rewrite history underneath us
```

Written by exactly one thing — `worldMods.py --record-installed`, called from
`downloadAndInstallTsModsForWorld()` with the ids of the mods that actually landed.
`--plan` gained a ninth column (`mod_id`, **appended**, so the four scripts that read fields
1–5 with awk are untouched) to carry those ids out to the installer.

The two columns carry **three** states, and all three are load-bearing:

| `installed_at` | `installed_version_id` | meaning |
| --- | --- | --- |
| NULL | NULL | never recorded → **unknown** |
| set | NULL | known **not** installed — a duplicate plugin `by_plugin()` collapsed away |
| set | set | comparable |

Collapsing the middle case into the first is the same can't-tell-the-difference bug pointing
the other way: after a clean rebuild, a world with one collapsed duplicate would sit on
"waiting for data" forever, and every modded world has at least one such row. A mod whose
install **failed** is left untouched — its previous copy is still in `BepInEx/plugins`, so
its previous recorded version is still true.

**No backfill.** Nothing on the box knows which version of a plugin is sitting in a world's
`BepInEx/plugins` — the extracted folders carry no `manifest.json` — so any value written
would be a guess wearing the costume of a fact. NULL is the true answer, the UI says *waiting
for data*, and a world's next mod rebuild records the real versions.

### Nothing claims "up to date" without checking

Three separate gaps all rendered as the same reassuring green, because a `0`/false default
silently doubles as a real answer:

1. steamcmd could not run, so no published build → a world thousands of builds behind read
   as current.
2. A world's mod versions were never recorded → the loop skipped every entry and the count
   came out 0. **28 of 35 worlds on one real server.**
3. The world had never been checked at all — both columns `DEFAULT 0`. **26 of 35.**

Each now has its own state in the schema (`update_check_error`, `update_mods_error`, a gate on
`update_checked_at`) rather than being reconstructed in the UI. A pending state reads as muted
*waiting for data*, not a red *could not check* — nothing is broken.

Also fixed: the Updates tab drew **two Mods rows**. A leftover unconditional block sat after
the never-checked gate, so a never-checked world showed a muted "waiting for data" and a green
"up to date" one line apart — two contradictory answers to the same question, in the same
panel.

### Rebuild Mods

A per-world button in the Updates tab, beside the *waiting for data* message. Worlds built
before version recording have nothing to compare, and no amount of checking will change that;
reinstalling their mods is what records it. Per-world and manual on purpose — a rebuild stops
the world, and a world whose mod list no longer resolves is left stopped by design, so doing
this to two dozen worlds unattended could take a server down overnight. Worlds also fix
themselves the next time their mod list changes.

### Tests

`dev_tools/test-playerMonitor.sh` (9), `test-updateApplier.sh` (16),
`test-updateChecker.py` (19), `test-record-installed.py` (18) — 62 in total. Each case is one
where a plausible wrong implementation gives a different answer than the right one, and the
new ones are mutation-checked: collapsing either of the three installed-states, suppressing a
confirmed update because some other mod is unrecorded, and re-reading the display cache each
fail the suite.

Verified against a real database as well as in unit tests — a synthetic 36-mod dependency
closure built from the live catalogue, covering a simulated failed install, a rewound version,
a pin, and a recorded-but-not-installed row.

## v2.46

### What Hugin is told about passwords

Reported in Discord: Hugin said a server was not using a password and was gated by its
access control list, and the operator did not believe that was their setup.

Hugin was right. `startWorld.sh` reads the password column but consumes it only inside the
`isVanilla = 1` branch — a modded world is started with `-public 0` and no `-password` at
all, and entry is gated by `permittedlist.txt` alone. The admin UI still accepts, stores and
displays a password for a modded world, which is why the answer was surprising rather than
wrong.

Driving the real tool loop against a live provider turned up three defects in what the model
is handed, all in `includes/aicontext.php`:

**`password_public` is not a password.** It is a `TINYINT` controlling whether the password
is shown on the public world card. It was being redacted as a credential, and because both
`"0"` and `"1"` are `!== ''`, it reported `(set — redacted)` *either way*. That destroys the
boolean and invents a second credential — which a live model duly described to an operator as
"a separate password used for the public/spectator view". It is now passed through as
`show_password_on_public_card`, an int.

**A NULL password skipped redaction entirely.** The guard was `isset()`, which is false for
NULL — the column's default — so the commonest case went out as a bare `"password": null`
while an empty string became `"(not set)"`. Now `array_key_exists()`, so both read alike.

**Nothing said a password is vanilla-only.** `has_password` and `password_in_effect` are now
on both `list_worlds` and `get_world`; the second is false on a modded world whatever the
first says. `list_worlds` previously carried access-control state and no password state at
all, so a question about how the server was secured could only be answered from half the data.

The vanilla/modded password rule also moved into `DOMAIN FACTS`. It had lived only in
`OPERATING PROCEDURES`, which `aiSystemPrompt()` omits when the model cannot act — so a
read-only Hugin was never told it.

Guarded by `dev_tools/test-ai-password-context.sh`: 17 assertions on the JSON the tools
actually return, not on the source. 14 of them go red against 2.45.

### The Hugin panel, measured against a small model

Driven against the real DeepSeek v4 Flash provider on the production box rather than
reasoned about, because the complaint was "the formatting isn't very pretty" and the causes
turned out not to be the model.

**The renderer was the problem, not the model.** `aiMd()` had no table support at all, so a
side-by-side comparison — which a small model reaches for constantly — arrived as rows of
literal `|` characters. Nor did it handle `---`, `>` quotes, indented sub-bullets, lists
numbered from anything but 1, or a code fence the model opened and never closed (every
truncated answer). Every heading level rendered identically. All of that now renders, with
column alignment, and `dev_tools/test-ai-markdown.js` asserts on the HTML using fixtures
pasted from real replies — 29 assertions, 20 of which go red against 2.45. The three
escaping assertions in there are load-bearing: `aiMd()` is the only thing between model
output and `innerHTML`.

**Small models narrate their investigation into the answer.** A health summary opened with
three to five paragraphs of "let me check…", "now I have enough…" before the first real
sentence. Three changes, in order of how much each can cost:

- the client now discards any prose that arrived *before* a tool call. A tool call is proof
  the model was still investigating, and the trace strip already records what it looked at.
- the system prompt says not to narrate. Helps; does not stop it.
- `aiStripNarration()` removes leading process talk from the final round, which has no tool
  call after it to key off. Deliberately conservative — it only fires on leading,
  structure-free, first-person process talk, and returns the original if everything looks
  like narration, so the worst case is 2.45's output rather than an empty bubble. The
  second pass (cut to the first heading) additionally requires **two or more** narration
  paragraphs, which is what keeps a normal lead-paragraph-then-heading answer intact.
  `dev_tools/test-ai-narration.php` — 19 assertions, and the nine KEEP cases are the real
  specification, since this function deletes text the operator asked for.

`done` now takes the server's content even when it is *shorter* than what streamed. It was
taken only if longer, as a guard against a dropped delta — which would have thrown the
strip away and kept the narration.

**The working strip is pinned to the bottom.** It was the first child of a growing bubble,
so on any answer longer than the panel the raven, the phrase and the timer scrolled off the
top exactly when the wait was longest. Now `position: sticky`, which works because
`.ai-message.assistant` is a column flexbox, and it leaves with its own bubble.

### Hugin was reading the wrong column for "is this world running"

Reported from the panel: Hugin insisted a world the operator was standing in was stopped,
and explained its live log as history.

`worlds.status` is not a running indicator. On the production box:

| status | mode | count |
|---|---|---|
| `Down` | `stopped` | 31 |
| `Down` | `running` | 2 |
| `failed` | `stopped` | 2 |

`status` is the literal string `Down` for **all 33 worlds**, including the two whose
`valheim_server` processes were live at that moment. `worlds.mode` is the column the engine
maintains and the one `admin/index.php` renders from — and there the two `running` rows were
exactly the two with processes.

`aiTruthy($row, 'status')` was used as "is it running" in six places, so it was permanently
false:

- the prompt injected `0 running, 33 stopped`, plus the line telling the model that every
  world being stopped is the server's resting state — which is how a live world got
  described as stopped;
- `stop_world` and `restart_world` refused **every** world with "already stopped", so
  nothing could be turned off through Hugin at all;
- `start_world` would have started a world that was already up;
- `aidiagnose` downgraded every finding to history and skipped the restart-loop and
  backup-freshness checks for precisely the worlds that were serving players.

All six now go through one predicate, `aiWorldIsRunning()`, which reads `mode`. `status` is
still reported, because it carries a signal `mode` does not — a world whose last start
attempt failed reads `mode=stopped, status=failed`, and `list_worlds` now says
"stopped (last start failed)". `get_world` also stops calling its `mode` field
running/stopped while `list_worlds` calls its own field vanilla/modded; both now mean
vanilla/modded, with running state in `status` and a `running` boolean.

`aiTruthy()` itself was fine and is untouched — it is still correct for the boolean columns
(`public`, `vanilla`). Only the running check was pointed at the wrong field.

Guarded by `dev_tools/test-ai-world-state.sh`, whose fixture is the production shape —
`status='Down'` **with** `mode='running'`. That combination *is* the bug; a fixture that set
`status='Running'` for a running world would pass against the broken code and prove nothing,
which is how this shipped in 2.45. 13 of its 15 assertions go red against 2.45.

**Two things that were simply missing.** The default provider could only be changed by
re-running the whole Add-provider wizard over an existing row; there is now a "Make default"
button per provider, backed by `aiProviderSetDefault()` — its own one-field endpoint rather
than a partial `aiSaveProvider()` call, which would have blanked the label, endpoint and
model of the row it was promoting. It refuses an unknown id, because clearing the flag and
then failing to set it would leave the registry with no default, which the panel cannot open
in. And `.mods-modal-footer` had no CSS rule at all, which is why Back and Next sat hard
against the dialog corners.

## v2.45

### The AI Helper stops holding opinions about which models exist (issue #83)

Reported as "Gemini models are retired/deprecated": `models/gemini-2.0-flash` was gone and
the helper still asked for it. Bumping the string would have been a fix with a shelf life
of weeks. The defect is that a model id was a constant in our source at all.

2.44 carried **three** hardcoded model tables. `getAiProvidersJson()` held one for the
picker, `aiHelperDispatch()` held a second for validation, and the validator did this:

```php
if (!in_array($model, $allowedModels[$provider])) {
    $model = $allowedModels[$provider][0];      // operator asked for X, silently got Y
}
```

So the failure was not only "our default is stale" but "a *correct* model the operator
typed gets replaced by a stale one, with no error". Both tables are gone. Every supported
provider publishes its catalogue over HTTP — `GET /models` (OpenAI-compatible),
`GET /v1/models` (Anthropic), `GET /v1beta/models` (Gemini), `GET /api/tags` (Ollama) —
so we ask, cache for 6h, and send whatever the operator picked **verbatim**. A model that
discovery has never heard of is used as entered, with a warning, never a substitution.

`dev_tools/test-ai-helper.sh` token-strips the AI sources and fails the build if a
model-id shape reappears in live code. Verified by mutation: reintroducing the 2.44
constant, the silent rewrite, the swallowed error text, a weakened path check and a
mutating tool are each caught.

### A provider is a row, not a hardcoded case

`settings` held exactly four AI columns: one OpenAI key, one Claude key, one Gemini key,
and `ollamaUrl` — which had **no key field at all**, so a self-hosted vLLM or LM Studio
behind `--api-key` was unusable. Providers now live in `ai_providers`, so an operator can
configure any number of them, several of the same kind, each with its own endpoint,
credential, extra headers and pinned model. `openai_compatible` covers vLLM, LM Studio,
llama.cpp, OpenRouter, Groq, Together, DeepSeek, Mistral and xAI with one adapter.

`dbUpdate_2.45.sh` migrates the four columns into rows and deliberately does **not**
carry the model across — the only ids 2.44 could have stored are from its own stale
tables, `gemini-2.0-flash` among them. Leaving it empty forces live resolution on first
use, which fixes #83 for upgrades and not just fresh installs. The legacy columns are
kept as a rollback record; `pushAnalytics.sh` was the one live reader left and now reads
`ai_providers` instead. A migration that changes no read sites is how the 2.43 world-card
mod counts broke, so the test suite greps for stragglers.

### The assistant can look things up instead of being handed one log

Context was `tail -200` of a single file pasted into the system prompt. It could not
follow a lead, compare two worlds, check whether the thing it was blaming was even
configured, or see further back than 200 lines — so the most common real answer, *"the
failure is above the window you were given"*, was unreachable by construction.

There are now ten read-only tools: list/search/read any log (whole file, not just the
tail; `since_last_start` for a world), world config, the resolved mod install plan,
catalogue sync state, backups, host health, and the diagnostics below. Log paths resolve
through `realpath()` containment inside `/opt/stateful/logs` — `basename()` alone stops
traversal but not a planted symlink. Nothing mutates: a wrong answer should waste the
operator's time, never their world. Each reply shows which tools it called.

### A health scan that needs no model at all

`aidiagnose.php` is pure PHP pattern matching over the logs and database, run before any
LLM call. It reports mod load failures, missing dependencies, mods configured but never
loaded, the 2.39 permission class, Steam download storms, port conflicts, restart loops,
overdue backups, failed syncs, low disk and stopped services — each with the lines that
triggered it.

Three reasons it exists: the helper is useful with nothing configured; a small local
model handed evidence does well where the same model handed 200 raw lines hallucinates;
and the expensive model reasons about findings rather than scrollback.

It also flags a world whose `permittedlist.txt` is **enforced but empty** — Valheim only
enforces that list when it has entries, so an empty one is a wide-open server whose
Access tab says otherwise.

### What a real Gemini key found that a mock could not

Tested against the live API. Discovery returned 40 models and `gemini-2.0-flash` — the
model 2.44 hardcoded and issue #83 reported — is **absent**. The current generation is
`gemini-3.8-flash`; the fix the issue itself suggested (`3.6-flash`) would already be one
behind. Three defects surfaced that an OpenAI-shaped mock is structurally unable to show:

**Tool calling was broken on every current Gemini model.** Gemini 3 attaches a
`thoughtSignature` to parts and *requires* it back on `functionCall` parts. The adapter
rebuilt each part from name+args, dropping it, so the second round trip died with
*"Function call is missing a thought_signature in functionCall parts"*. Plain chat worked,
which is why nothing looked wrong. The model turn is now replayed verbatim — Google's own
guidance — via an opaque `provider_raw` that `aiConverse` passes through untouched and
other adapters ignore.

**An empty `args` object became a JSON list.** `json_decode($body, true)` turns `{}` into
an empty PHP array, indistinguishable from `[]`, and `json_encode` emits `[]`. Every
no-argument tool call replayed as `"args": []` and was rejected: *"Proto field is not
repeating, cannot start list."* The replay copy is now decoded as `stdClass`, which
round-trips exactly.

**The wizard's connection probe asked for 16 output tokens.** On a reasoning model that
budget is consumed by thinking before any visible character, so the step passed while
rendering `Replied:` and nothing — measured at ~150 thinking tokens for a one-word
question. Raised to 512, and an empty-but-successful reply is now reported as a warning
explaining why rather than as a blank pass.

Also worth knowing: **discovery listing a model does not mean the key can use it.**
`gemini-2.5-flash` is still advertised by `/v1beta/models` but returns *"no longer
available to new users"* on `generateContent`. The wizard's separate chat round trip is
what catches this, at setup time, instead of it becoming a confusing chat error later.

### Three more found by pointing a real model at a real server

A live Gemini key against a booted container, asked open questions about the engine log
and two broken worlds.

**The tool loop gave up while it was winning.** `aiConverse` capped at 6 rounds, and the
model investigates in *parallel* — an open "full health check" produced twelve tool calls,
two per round, hit the cap, and returned an error blaming the model (*"try a stronger
model"*). The work was done; only the answer was missing. The cap is now 10, and on
exhausting it the loop makes one final call **with no tools**, forcing an answer from what
was already gathered. The system prompt also tells the model to answer as soon as it can
support an answer rather than gathering everything that might be relevant.

**`get_world_mods` had never worked.** It selected `m.version`; the newest version is
denormalised onto `mods` as `latest_version`, so the tool returned *"Column not found:
1054 Unknown column 'm.version'"* every single time. The mock provider only ever called
`get_diagnostics`, so nine of the ten tools had no coverage at all. The e2e now executes
every tool directly and fails on error-shaped output.

**The new dbUpdater error line was itself the bug.** Treating every non-0/1 exit as a
failure printed 13 ERROR lines on every boot of a *healthy* server, because the older
migrations end with a deliberate `exit 2` meaning "already applied". The AI Helper then
read them, believed its own instructions, and reported *"CRITICAL: Engine Database Update
Failures"* advising the operator to hand-edit the database. Noise that looks like a fault
is a fault. `2` is silent again; `126`/`127` — a script that genuinely cannot run — stay
loud, which is the case that mattered.

### A migration that could not run, and said nothing

Found by booting the first 2.45 RC rather than by reading it. `dbUpdate_2.45.sh` was
committed mode `660`; `dbUpdater.sh` invoked each migration as a bare path, which needs
the execute bit, so it exited **126**. That matched neither of the two branches in the
loop — `0` and `1` — so nothing was logged, the engine continued as though the schema were
current, and the only symptom was the AI Helper reporting no providers configured. The
image verified clean: the marker checked that the file *existed*.

Three changes, because any one alone leaves the trap set:

- `dbUpdater.sh` now runs each migration with `bash "$dbUpdateScript"`, removing the
  dependency on a file mode that no reviewer sees in a diff.
- Any exit code that is not 0 or 1 is now logged as an ERROR naming the script. Silence
  was the actual defect; a migration that cannot run must never look like one that did.
- The build verify tests `test -x` rather than existence, and asserts both of the above.

`dev_tools/test-ai-e2e.sh` is new and is what caught it: it boots the image, waits for the
engine to migrate *on its own*, then drives the admin API through the provider wizard,
live model discovery, the diagnostics scan, the tool-calling loop and the SSE stream
against a mock provider served inside the container — no API key, no network egress.
Deliberately never runs the migration by hand, since that would hide this exact class of
bug.

### Issue #83 grew back inside the wizard that was meant to fix it

Found on a live paid Gemini account, on the production upgrade, by setting up a provider
the ordinary way.

The wizard ran **Test before Model**. A connectivity round trip needs a model, and at that
point none had been chosen — so `aiTestProvider()` reached for
`$disc['models'][0]['id']`, "whatever the provider listed first". Google's
`/v1beta/models` is not ordered by preference: the first of the 40 models returned was
`antigravity-preview-05-2026`, an internal preview that rejects `systemInstruction`
outright. A perfectly good API key therefore failed the wizard with

    ✕ Chat round trip — Developer instruction is not enabled for
                        models/antigravity-preview-05-2026

naming a model the operator had never selected and could not see.

This is #83's actual defect — *code choosing a model on the operator's behalf* — in a new
costume, which is why the existing guards missed it: no model id is hardcoded anywhere.
It is **selected**, at runtime, from a list. The guard now forbids indexing into the
discovered list at all, and pins the step order; both were proven by mutation.

The fix is structural rather than a better guess. Steps are now
**Type → Endpoint → Credentials → Model → Test**: discovery runs on entry to the Model
step via a new `discoverAiModels` endpoint, the operator picks, and the round trip tests
*that* model. Reaching the test step with no model is now a plain "go Back and choose
one", not an invented probe. Changing the base URL or the key discards the cached list so
a different endpoint cannot be offered another's catalogue.

Separately, `aiChatGemini()` now retries once with the system prompt folded into the first
user turn when a model refuses `systemInstruction` — tried only *after* the proper field
has been refused, never speculatively. Without it the grounding prompt, which is the only
reason this helper is worth anything, takes the whole request down on those models.

### The AI Helper was the one subsystem that logged nothing

Diagnosing the above needed a screenshot, because the server had nothing to say. Every AI
failure — bad key, refused model, discovery error — was returned as JSON to the browser
and left no trace. The three POSTs in `php.log` were all **HTTP 200**, since a rejection
is a successful request carrying `success:false`.

`aiLog()` now appends to `/opt/stateful/logs/ai.log` (picked up by the existing `*.log`
rotation, and readable by the helper's own `read_log` tool): discovery results, provider
test outcomes, and every upstream chat failure, through a single choke point in `aiChat()`
so no adapter can fail quietly. Keys, endpoint credentials and conversation content are
never written.

### Ollama is a preset, not a provider kind

The dedicated `ollama` kind carried its own ~70-line adapter and its own `/api/tags`
discovery branch. Ollama also serves an OpenAI-compatible API at `/v1`, so all of that was
duplicating `aiChatOpenAI()` to save the operator typing three characters. It is now one of
eleven presets on the OpenAI-compatible type, beside vLLM, LM Studio, llama.cpp, OpenRouter,
Groq, Together, DeepSeek, Mistral and xAI — presets only prefill the form, and nothing
downstream branches on which one was used.

Removing a kind is not a code-only change, because **rows in the database point at it**.
Two upgrade paths exist and `dbUpdate_2.45.sh` handles both:

- **From 2.44**, `settings.ollamaUrl` is a bare `host:port` (2.44 spoke the native API). The
  import now appends `/v1` and creates the row as `openai_compatible`.
- **From an earlier 2.45 RC**, rows with `kind='ollama'` already exist. A second block,
  deliberately outside the "registry is empty" guard because this repairs rows a previous
  revision of this same script created, rewrites the URL and then the kind. It is idempotent
  by construction — after the kind is rewritten nothing matches — and the URL is fixed
  first, while the rows are still identifiable. `TRIM(TRAILING '/')` keeps
  `host:11434/` from becoming `host:11434//v1`.

The provider's cached model list is dropped at the same time: it was fetched from
`/api/tags` in the native shape, and keeping it would show stale, wrongly-parsed ids until
the 6h cache expired.

Leaving those rows behind would have been the 2.43 world-card regression in a new costume —
code deleted, live readers left pointing at it, failing plausibly rather than loudly.

### UI

Replies stream (SSE, `X-Accel-Buffering: no`, with a non-streaming fallback for proxies
that will not pass `text/event-stream`) and render as Markdown — escape-first, because
2.44 asked the model for raw HTML and injected it. The "Context" dropdown that chose
which log to attach is replaced by a world hint. A five-step **Add AI provider** wizard
tests endpoint, credential and model separately, so a failure names which of the three is
wrong instead of surfacing as a chat error later. The AI button is no longer hidden until
a key exists — that hid the diagnostics from the operator most likely to need them. The
wizard overlay sits at `z-index:1060`: it opens from Server Settings, and a stacked
overlay left at the base level renders behind its own dim layer and cannot be dismissed.

`setup.php` now collects nothing for AI. Four bare key fields cannot express a provider,
and a key typed there would have landed in the legacy columns the migration reads *from* —
already run by the time the wizard is on screen, so the credential would have sat in a
dead column while the helper reported nothing configured.

### Hugin can act, and every act is the server's, not the model's

The assistant is named **Hugin** and has twelve actions: `start_world`, `stop_world`,
`restart_world`, `update_world`, `delete_world`, `set_world_options`, `set_world_access`,
`create_backup`, `restore_backup`, `set_world_backup_policy`, `set_world_mods` and
`set_server_settings`.

The design rule is **propose → confirm → execute**, and the load-bearing detail is *where
the confirmation comes from*. A card built from the model's description of a change is a
card that can describe a change other than the one that will happen. So the plan is
authored server-side in `ai_proposals` from the **validated** arguments; the browser gets
an opaque single-use token and never the plan. It expires in 15 minutes and is
re-validated at apply time, so a world that moved on in the meantime stops the apply
rather than acting on stale state.

The executor owns no SQL. It calls the admin UI's own `*Json()` handlers and captures
their output with `ob_start()`/`ob_get_clean()`, which means every guard those handlers
already have applies to Hugin for free and cannot drift from what the UI does.
`startDetachedJob()`, `startManualBackupJob()` and `startRestoreBackupJob()` were lifted
out of the `adminAPI.php` switch so both callers share one implementation.

Tiers: `create_backup` and `start_world` execute immediately (neither can lose anything);
the rest render a confirm card; `delete_world` and `restore_backup` additionally require
the world's name typed.

**`saveWorldOptionsJson` is a full replace, not a patch.** Sending only the changed field
would have blanked the password, dropped the launch parameters, unlisted the world and set
`vanilla=0` — quietly converting a vanilla world to modded. The action now prefills every
key from the current row. Refusals are enforced at *propose* time, so the card never shows
a `0 → 1` that the launch path would ignore: crossplay or a password on a modded world, a
listed vanilla world with no password, an enforced-but-empty access list, and a restore
whose backup belongs to a different world.

Eight operating procedures were added to the system prompt — diagnose before acting, a mod
change is not live until the world is rebuilt, never invent a player ID, `worlds.public` is
not `-public`, stopping disconnects players, prefer the narrow tool, proposing is not
doing, and if a tool refuses, believe it.

### A model that cannot call tools now answers anyway

Tool support is **negotiated from the endpoint's refusal**, never from a model allowlist —
the same law that produced the discovery work above. `aiproviders.php` classifies a
tool-parameter rejection as the `no_tools` quirk, records it on
`ai_providers.tool_capability`, and retries without tools. Hugin then answers plainly and
says it could not inspect anything and cannot make changes. Previously such an endpoint
errored on every single message.

### A streamed reply could silently delete its own words

`json_encode()` returns **`false`** on invalid UTF-8, and a provider may split a multi-byte
character across two chunks. The SSE frame then went out as `data: ` with no payload and
the browser dropped it — text vanished mid-sentence with no error anywhere. `aiUtf8Carry()`
now holds an incomplete trailing sequence until its continuation arrives, and `sse()` falls
back to `JSON_INVALID_UTF8_SUBSTITUTE` rather than emitting nothing.

### A modded world's log said crossplay was enabled

Nothing in the launch path was wrong: `startWorld.sh` has never passed `-crossplay` to a
modded world. The bug was one sentence, which opened `World 'X' has crossplay set but is
MODDED` — and the first six words are what an operator scanning a log takes away. It now
leads with the outcome (`crossplay is OFF -- it is saved as enabled, but ...`) and names
the remedy.

Proving it was ours took two greps: every occurrence of `crossplay` in
`assembly_valheim.dll` and `Splatform.dll` is a .NET **identifier**, which lives in
metadata as UTF-8, not a user string literal, which would be UTF-16. `strings` found
nothing while `grep -a` matched — and that difference is the whole answer. Valheim never
prints the word. Guarded by `dev_tools/test-crossplay-logline.sh`, which extracts the real
block and runs all four vanilla/crossplay combinations.

### Telemetry, and a migration rule that stops version churn

`pushAnalytics.sh` gained `ai_chats`, `ai_tool_calls`, `ai_actions_{proposed,applied,rejected,dismissed,expired}`,
`ai_tools_used`, `ai_errors` and `ai_capability`, all **counts** over a one-day window. No
prompt, reply, world name, mod name, model name, endpoint or key is ever sent. The
analytics service stores them in a new `ai_hugin` column, bounded to 64 keys of 64 chars.

All 2.45 schema is **appended to `dbUpdate_2.45.sh`** rather than opening a 2.46. Migrations
from 2.40 on are object-by-object idempotent and re-run safely on every boot, so a later
revision of the same script is the correct place for late schema —
`exit 2` gating is legacy (2.7–2.38). `dev_tools/test-migration-append-safe.sh` fails the
build if a migration exists for a version newer than the Dockerfile.

### Meeting Hugin

A one-shot `huginNoticeShown` dialog introduces the raven on first login after upgrade and
points at AI Setup. Its default is `?? 1`, not `?? 0`: an undefined variable is `null`, and
**`null == 0` is true in PHP** — so a missing setting would have shown the dialog on every
page load forever. Defaulting to "already seen" fails closed.

## v2.44

### One mod could freeze a world's entire mod list (issue #82)
`unzip` exit code **1 means "extracted, with warnings"** — not failure. A mod packaged on
Windows stores its entries with backslash separators (`PONEIS/SmartContainers` ships
`plugins\SmartContainers.dll`); unzip converts them, warns, and returns 1.

`0-functions.sh:422` treated that as fatal, which did far more than log a wrong line:
`modInstallFailures` incremented → `downloadAndInstallTsModsForWorld` returned 1 →
`phvalheim:403` skipped **both** `packageClient` and `generateModViewerJson` and set the world
`stopped`/`failed`. So the operator's mod-list edit was saved to `world_mods` but never reached
the client payload or the mod viewer — which reads as "changing my mod list does nothing".

Now fails only on 2–10 and 12+, the real format and I/O errors; 11 ("nothing matched") stays
exempt. The same flaw in the BepInEx check at line 395 is fixed too — a backslash-packed loader
pack would have silently skipped installing the loader.

### The mod loader is no longer a mod
`InstallAndUpdateBepInEx()` installs BepInEx on every modded world at engine start,
unconditionally and always latest, before any mod. Selecting it, deselecting it or pinning a
version never had any effect.

But the catalogue carries three loader rows and every mod declares a dependency on one, so
2.43's resolution added a loader row to every modded world — and because Hexium mods resolve to
Hexium's copy, the picker hung a yellow "dependency (deselected)" badge on a row nobody could
act on. The loader is now excluded from the catalogue, the dependency graph, the closure, the
install plan and the viewer; `dbUpdate_2.44.sh` clears the rows already written and names the
worlds it changed.

### Fixed
- The engine log no longer fills with `UDP_PORT_25000-25100: command not found`. `/etc/environment`
  is sourced by the main loop every two seconds, and Unraid templates name port variables after
  the range they map — a hyphen is not a legal shell identifier, so each tick logged a command-not-found.
  `printenv` is now filtered to valid identifiers.
- `generateModViewerJson` took its world from a leaked global instead of `$1`. Correct only
  because the main loop happened to assign that same global first; one reordering would have
  written one world's mod list onto another.

## v2.43

### Mods can come from Hexium as well as Thunderstore
A world may draw on either catalogue or both. Search results carry a coloured pill showing
each mod's origin (Thunderstore blue, Hexium purple), and buttons above the list show or hide
a catalogue. Dependencies resolve **across** sources, so a Hexium mod needing a
Thunderstore-only dependency now works.

Identity is `(source, owner, name)` — never the source's UUID. Hexium mirrors Thunderstore
packages carrying their original `uuid4`, so 600 package UUIDs exist in both catalogues and a
UUID can never identify a mod.

### Version pinning
Every published version of every mod is stored, not just the newest — each with its own
download URL, file size and release date. Pick a version from the dropdown in a mod's row to
freeze it there; "Latest (auto)" keeps following new releases. A pinned version is kept in the
database even if the source delists it.

### The catalogue sync was rebuilt
A full cold build of both catalogues — 11,600+ mods and 91,000+ versions including all
history — takes about 30 seconds instead of hours. A routine check that finds nothing changed
takes about a second: Thunderstore answers a conditional request with a bodiless `304`,
Hexium's body is hashed and compared, and every row carries a content hash so only genuinely
changed rows are written.

Both catalogues are synced at **every server start** as well as on the configured interval, so
a restarted server comes back current rather than waiting up to six hours.

Sync & Maintenance shows a live panel per catalogue — phase, packages and versions seen, what
was added/changed/removed, timing, and a comparison with the previous run — plus a per-catalogue
**live sync log** naming what moved, and a per-mod detail toggle.

Server Settings → Mod Catalogues enables/disables each catalogue, sets the interval, and
accepts an API key. **Neither catalogue needs one** — both are public.

### Removed
The sidebar's **Thunderstore Sync** button, its confirm dialog and stop endpoint; the
**Thunderstore Local Sync** and **Thunderstore Chunk Size** settings; the whole pre-2.43 sync
(`tsSync*.sh`, `tsPrune.sh`, `tsModDepGetter.sh`, `modLookup.sh`) and the 14 MB
`tsmods_seed.sql` GitHub seed. A fresh install now builds the catalogue from the live APIs.

### Fixed
- Two mods whose names differed only in capitalisation (`Iron_ModPack` vs `Iron_Modpack`) were
  treated as one and overwrote each other on every sync. 22 such pairs exist on Thunderstore;
  all are now stored separately.
- Dependency strings containing hyphens in the author or version (`LVH-IT`, `sinai-dev`,
  `2.0.6-beta.1`) matched the wrong package or none. Resolution is by longest known
  `owner-name` prefix.
- A world drawing on both catalogues installed **two copies of the same mod** — usually
  BepInEx, which both catalogues publish. Only one was ever installed, but the mod list showed
  it twice and the count was one too high. One copy per `(owner, name)` is now selected,
  installed and displayed, and the UI says which copy it kept.
- World cards and the mod editor reported **0 mods** for every world; they read the pre-2.43
  columns the new catalogue no longer writes. Backup manifests recorded empty mod lists for the
  same reason.
- In the mod picker, ticking a checkbox sent the list back to page 1 — every time. The redraw
  now holds the current page and scroll position. Checkboxes are smaller.

### Upgrading
Existing mod selections migrate automatically on first start. The previous Thunderstore tables
are left untouched, so nothing is discarded. If a world had selected a mod since delisted, the
engine names that world at startup.

## v2.42

### "What's New" modal after every upgrade
The admin UI now shows a one-shot modal listing what changed, the first time it is opened
after an upgrade. Dismissing it records the running version; it stays gone until the next
upgrade. Skipping a release does not skip its notes — upgrading 2.42 → 2.44 shows both.

Fresh installs do not see it (there is no "before" to describe), and it queues behind the
setup wizard and the settings-migration notice rather than stacking on them.

Release notes live in `container/nginx/www/includes/whatsnew.php`, one entry per version.
**Every release must add one**: `dev_tools/check-whatsnew.sh` fails if the version in the
Dockerfile has no entry, because a missing entry is invisible at runtime — the modal just
silently shows nothing.

The stored state is a version string rather than a "shown" boolean deliberately. Every
script in `dbUpdates/` runs on every boot, so a boolean would need re-arming each upgrade
and an unconditional `UPDATE` would re-raise the modal after every restart. Comparing
stored version against running version is self-arming and needs no migration for 2.43+.

### Automatic backups stayed disabled on Unraid despite a dedicated backup volume
The admin UI reported `✓ dedicated volume` and manual backups worked, but `backups.log`
repeated every scheduled run:

```
[WARN : phvalheim] Backup path (/opt/stateful/backups) is on the same volume as
/opt/stateful — no dedicated backup volume mounted. Automatic backups disabled.
```

The UI and the scheduler were asking two different questions. `isBackupPathMounted()`
(admin UI) asks the **mount table** — is `/opt/stateful/backups` a mount point? The
scheduler in `worldBackup` instead compared **device identity**, the source column of
`df` for the backup path and for `/opt/stateful`.

Device identity cannot see a bind mount. On Unraid every `/mnt/user/<share>` is served by
one FUSE mount, so `df` reports the source as the literal string `shfs` for *all* of them
while each share still reports its own size. A user mapping `/mnt/user/appdata/...` to
`/opt/stateful` and an 11T `/mnt/user/backups/...` to `/opt/stateful/backups` got
`shfs == shfs` — read as "same volume", so automatic backups disabled themselves at every
run while the UI correctly showed two volumes of different sizes. This affected every
Unraid install using the standard `/mnt/user` share layout.

Both callers now use one shared oracle that reads the mount table (`mountpoint`, falling
back to `/proc/self/mountinfo`), so the admin UI and the backup scheduler can no longer
reach opposite verdicts. Covered by `dev_tools/test-backup-volume-detection.sh`, which
reproduces the failing condition with a real bind mount whose `df` source is identical to
its parent's.

## v2.41 — Crossplay join codes, access-list safety, world card rework

### Analytics push no longer fails on larger servers
`pushAnalytics.sh` built the worlds array in a shell variable and handed it to `jq` as
`--argjson worlds "$worlds_json"`. Linux caps a **single** argv entry at 128 KiB
(`MAX_ARG_STRLEN`), separately from the much larger total `ARG_MAX` — so once a server had enough
worlds x mods to cross that line, `jq` never ran:

```
pushAnalytics.sh: line 146: /usr/bin/jq: Argument list too long
[WARN : phvalheim] Failed to build analytics payload
```

Every push failed from then on, with that one WARN line as the only symptom. Worlds and mods are
now accumulated in temp files and passed with `--slurpfile`, which has no such limit (measured
in-container: `--argjson` accepts 129,025 bytes and fails at 201,601; the same data via
`--slurpfile` is fine at 512 KB). Temp files are removed by an `EXIT` trap.

### The OPEN pill matches the others
It was styled muted grey while every other access pill is accent-coloured, so it read as a
different kind of thing on the same row and was hard to see against the card. It is a normal
pill now; the tooltip still explains that "open" means no access list and no password.
Measured across DPR 1/1.25/1.5/2 its text is the best-centred on the row (within 0.25px by both
ink-bounding-box and ink-centroid). `access list` and `crossplay` remain 2-5 device px left of
centre -- monospace glyph side bearing, not yet addressed.

### Crossplay: Launch! now explains how to join
A crossplay world can no longer be launched from the public card, because it never really
worked. Valheim's `-joincode` argument is real, so the button looked correct — but the client's
handler resolves the code and joins immediately without ever selecting a character. Players
arrived in the world as Valheim's built-in `Odev (Developer)` profile and found a character in
their list they had not created.

Clicking **Launch!** on a crossplay world now opens a short modal with the current join code and
three steps: start Valheim, pick your character, use *Join by code*. The code is read at click
time, so a world that restarts and is reissued a code never hands out a stale one. Non-crossplay
vanilla worlds (`+connect`) and modded worlds (`phvalheim://`) are unchanged.

The modal also needed a stacking fix: this page carries a hand-rolled `.modal` rule from before
Bootstrap that makes `.modal` itself the dim overlay at `z-index: 1050`. Bootstrap puts its
backdrop at 1050 too, so the backdrop painted over the dialog — it looked dimmed and Close could
not be clicked. Scoped to `#crossplayJoinModal`, matching what the macOS install modal already
does.

- **Crossplay is a vanilla-only option again, for now.** Enabling it makes Valheim open a PlayFab server, which is reached by join code and has no host:port — but the PhValheim client reaches a modded world through QuickConnect, which connects by host and port. A modded crossplay world therefore starts normally and simply cannot be joined by the client. The option is hidden for modded worlds and enforced at world start, so a world whose flag was already set stops opening a PlayFab server on its next restart. This will be revisited once the client can launch with a join code.
- **A crossplay world now shows its join code instead of a Launch button that cannot work.** Enabling crossplay makes Valheim open a *PlayFab* server rather than a Steam one; the world is reached by join code and cannot be joined by IP at all. The card offered `steam://…+connect <host>:<port>` regardless, which asks for a direct connection the server is not serving — so it failed silently while the in-game browser worked fine. Crossplay cards now show the code with a copy button, and the hint no longer tells players to use *Join IP*. Non-crossplay vanilla worlds keep their Launch button. The code is read from the world log at render time rather than stored: it is reissued on every restart, so a cached one would keep advertising a code that no longer works.
- **The admin dashboard's Launch button now respects a crossplay join link too.** The public card was fixed for this, but the admin one still offered `+connect <host>:<port>` for every vanilla world — which a PlayFab-hosted crossplay server never accepts, so it failed silently. Both of the dashboard's launch paths (the PHP render on load, and the poll payload that re-renders it seconds later) now share one helper with the public card. A crossplay world that is up but has not registered its join code yet shows a disabled "starting…" rather than a link with nothing to pass.
- **Resource readouts are cleared when a world stops.** Memory and tick-health bars stayed on screen for worlds that were offline or mid-update. Both APIs were already correct — they return only running worlds — but the way they report a stopped world is to *omit* it, and the dashboard only ever iterated what was in the payload, so those worlds were never visited again and kept their last drawn numbers.
- **An access list that is enabled but empty is now refused.** Valheim only applies `permittedlist.txt` when it has entries — an empty one is not "nobody may join", it is no restriction at all. A world could therefore sit with *Use Access List* switched on, no players in the list, and be joinable by anyone, while the Access tab showed it as restricted. Saving that combination now fails with an error naming both repairs (add a player, or switch the list off). It is refused rather than silently corrected: "let anyone in" and "let these people in" are one click apart and mean opposite things.
- **"Use Access List" can no longer be switched on with nobody on the list.** Valheim enforces `permittedlist.txt` only when it has entries, so an access list that is on but empty produced a wide open server whose Access tab claimed otherwise. Creating a restricted world now requires a first player ID, and saving an empty enforced list is refused — both checked server-side, not only in the browser. Any world that reaches that state another way (a restored backup, a direct database edit) now logs a loud warning at every world start.
- **A restricted world now needs a first player before it can be created.** Choosing *Only players on the access list* reveals a Steam ID field and the world will not be created without a valid 17-digit SteamID64, so it is genuinely restricted from the moment it exists rather than restricted-with-an-empty-list. Validated at the endpoint as well as in the form, since the endpoint is reachable directly.
- **Access IDs are now shown and stored in the `V_` form everywhere.** Valheim matches only the prefixed form, so a bare 17-digit SteamID64 in an access list matches nothing and the player is refused with a misleading "Banned". Previously the prefix appeared only in the file on disk, while the database, the Access tab and the player page all showed the bare id. Citizens, Admins and Banned now all store and display the canonical form. Pasting a bare SteamID64 still works — it is upgraded automatically — and an already-prefixed id is left alone.
- **World cards space their rows consistently.** A card stretches to match the tallest card in its row, and the leftover height was shared across every row — so an online modded card next to a taller vanilla one had its rows spread twice as far apart as identical offline cards beside it. The leftover now collects in a single empty row at the bottom, so row spacing no longer depends on a card's neighbours. The crossplay hint sits at the bottom of the card, where a modded card shows its boss trophies, instead of jammed under the last row, and there is more space under the world name.
- **Creating a vanilla world explains the password rules where you break them.** Valheim will not start a world whose password appears anywhere inside its name, and it will not list a world in the server browser without one. Both were only enforced after you pressed Create, so the form spun, bounced, and printed the reason in a box at the far end of the page — next to a warning that opened by talking about custom seed mods. Creating `test123132131` with the password `test123` therefore looked like it was being blocked over seeds. The rules are now checked as you type, against the password field, naming both offending values; the note about seeds has been moved out of the password panel to the seed control that was already explaining it.
- **Admin toggle switches are smaller, and there is now only one size of them.** The settings modals overrode the switch to 44×24 while the rest of the admin UI used 36×20, so the loudest thing in a panel of quiet rows was a checkbox. Everything is 32×18 now. The reason the modal ones were enlarged — that the smaller switch was under the minimum size for a pointer target — was a fair point, and it is answered properly instead: the clickable area stays 36×24 while the drawn pill shrinks.
- **A vanilla world can run without a password.** The password field is optional now; leave it blank and anyone who can reach the server may join, which is what the card's **OPEN** pill has always meant. The one thing a passwordless world cannot do is appear in the public server browser — Valheim refuses to start a listed server with no password (*"bad password: the password is too short"*) — so the listing toggle is disabled, and says so, while the password is empty. It is also unticked rather than merely greyed, since a disabled-but-ticked box still submits. Both the create form and the world Settings modal enforce it, and the server re-checks on save.
- **Every world card says how you get in, and every pill says what it means.** Modded worlds are always gated by the citizens list, and were the one kind of world whose card never mentioned it — they have an Access row now, built by the same code as the vanilla one. The pills read **ACCESS LIST** or **OPEN**, each with a hover explanation written for players rather than server operators. A world whose access list is switched on but empty is shown as **OPEN**, because that is what it is: Valheim ignores an empty list and anyone who can reach the server can join. The tooltip says so, and says what to ask the owner for.
- **The "IN SERVER BROWSER" pill is gone.** It answered a question no player has — you are already looking at the world's card, so how it was discovered is the operator's business.
- **Access pills are a third smaller, and their text is centred.** They stood 23px against 20px data rows, making the Access row the tallest on the card, and the last letter's tracking pushed every label off-centre inside its pill.
- **A running world is described by what it is running, not by what has been saved.** Enabling crossplay on a live world lit the CROSSPLAY pill on the public card immediately, while the Launch button correctly stayed a direct-connect link — one card, two sources of truth, and the pill advertising a crossplay world Valheim was not serving. Settings that only take effect at the next restart no longer change what players see. The world is marked **restart pending** in the admin Worlds table instead, naming which settings are waiting (crossplay, server browser listing, password, world type), and that mark clears the moment the world restarts. A stopped world is still shown with its saved settings — there is nothing running to contradict them.
- **The Launch button is right from the moment a world comes up.** Valheim only announces which backend it opened about 30 seconds into starting, and until then the button fell back to the saved crossplay column — which is wrong for exactly the world that has just been restarted into a different mode. It now falls back to what the world was actually started with.
- **The join code lines up with the rest of the card.** Its label was one character shorter than every other label, and since the label column sizes itself to its widest entry, the code sat 8px right of every other value.
- **An online world card no longer sits wider-spaced than an offline one.** Collapsed table borders were set on the *offline* card rule only, so a live card kept the browser's default 2px border-spacing: every row 2px further apart and the card about 14px taller than an identical offline card beside it. The row heights matched the whole time — it was the space between them that differed, which is why online cards looked like they had gaps.
- **World cards are shorter.** A modded card goes from 309px to 248px tall against the same 400px width — a fifth off — so it reads as a card rather than a square; a vanilla one goes from 338px to 275px. Line spacing of 1.5 is the right figure for body text and is what fixed the cramped look, but it was being applied to single-line headings and to fixed tabular rows as well, which cost every card about 25px of pure leading. The world name and the Launch line take a normal heading figure now, and the data rows sit at 1.43 — still inside the range for dense rows, and a whole number of pixels so the rhythm stays exact. Most of what came off was a 50px bottom padding — and that padding turned out to be the only thing keeping the boss trophies on the card at all. The trophy row is a sibling of the card's full-height table, so it was always being laid out past the bottom edge; the padding just hid the overflow. The card stacks its contents properly now, which means nothing hangs off it and the height is whatever is genuinely in it. The spacer row under the Launch line was also malformed HTML — a `<td>` whose tag never closed — and is a real cell now.
- **The player page is set in a real terminal font now.** It asked for Lucida Console, which exists only on Windows, so almost everyone got Courier New — or on Linux its clone, Liberation Mono. Those are thin, wide, small-on-the-body faces, and the page packed them at `line-height: normal`, about 1.17, where the readable range is 1.4–1.6. The result read as tiny and cramped whatever size it was set at. JetBrains Mono now ships with the container (no Google Fonts request, so it works on a server with no outbound internet and tells nobody who visited), line spacing is 1.5, card rows are 14px instead of 12px, and labels are muted so the value you actually came for is the brighter half of the row. Row spacing goes from 14px to 21px. See `docs/TYPOGRAPHY.md`.
- **Card values line up across every card.** The label column took whatever width was left over, so a world with no MD5 sum and no dates yet pushed its values more than twice as far from the colon as a world with a full hash — identical labels, different gap on every card. The labels now size to themselves, which is the same width everywhere.
- **A world restarted into crossplay shows the right join method straight away.** Valheim logs which backend it opened about 30 seconds after starting, and until it did, the card read the *previous* session's line — so a world restarted into crossplay spent that window offering a direct-connect link that could not work. The join code had the same flaw and could hand out the previous session's code, which is already dead.
- **Crossplay cards no longer show a Server address.** It is the value you type into Valheim's *Join IP* screen, and a crossplay server does not accept direct IP connections at all.
- **Fixed the crossplay hint being cut off at the bottom of vanilla cards.** It was a sibling of the card's full-height table, so it was laid out past the bottom of the card. It is part of the table now and cannot be pushed out.
- **World cards are ordered online first, then alphabetically.** The list was ordered by a cron-updated memory column, which is stale for a world that has just started or stopped and meaningless for one that has never run — so the order looked arbitrary, and could disagree with the online/offline state the card itself displayed. (The 5-second refresh updates cards in place and does not reorder, so a world that starts or stops while the page is open keeps its position until reload.)
- **Players can find their own player ID.** It now appears under the welcome line on the player page in small magenta, click to copy — the value a server owner has to ask every player for, which previously required a third-party lookup site.
- **Opening Settings on a world with an enforced but empty access list now says so.** A modal explains that Valheim ignores an empty permitted list, with a button that goes straight to the Access tab. It tracks the live condition rather than being permanently dismissible, so it disappears when the list is fixed.
- **New worlds ask who can join.** The create path never set the access flag at all, so every world inherited the schema default — access list enforced, list empty — and came up open while its Access tab said restricted. The new-world form now has an explicit *Who can join* choice, defaulting to restricted, and the endpoint treats an absent value as restricted so an older client or a replayed request cannot create an open world by omission.
- **Worlds already in that state now announce it at every start.** The save-time guard cannot help a world that was created before it, because nothing re-saves one. `syncAccessLists.sh` renders the lists on every world start and is the only code that can see the condition, so it now logs a `WARNING` saying the world is open despite showing as restricted. It does not change anyone's access.
- **Fixed: a newly created modded world installed no mods at all.** `unzip -d` creates only the last component of a path and fails when a parent is missing. The BepInEx pack ships `BepInEx/config/` and `BepInEx/core/` but not `plugins/`, so on a fresh world every plugin extraction failed — silently, because the command discarded its output — and the world came up vanilla while every log line read "Installing…". It looked intermittent because a world that started got `plugins/` created by BepInEx itself, so a retry appeared to fix it. The directories are now created first, and a failed plugin extraction is logged instead of ignored.
- **A modded world is never published with zero plugins installed.** A failed mod download left the world starting anyway with an empty `BepInEx/plugins`, so a world created with mods selected could come up vanilla. Mod installation failures now leave the world stopped and marked `failed`, with its client payload and md5 untouched, rather than shipping an empty modpack to players.

## v2.40 — Vanilla Servers, Admins, Custom Launch Parameters

Release candidate. Held at `:rc` pending the Valheim 1.0 (Deep North) boss trophy prefab name — see "Known gaps" below.

### Features
- **Vanilla (zero-mod) worlds** ([#81](https://github.com/brianmiller/phvalheim-server/issues/81)): A world can now be created as vanilla — stock Valheim, no BepInEx, no mods, no client payload. Players join with the ordinary Valheim client, so no PhValheim client install is needed. New per-world settings: **server password** and **list in the public server browser** — these are vanilla-only, since modded worlds are gated by the CITIZENS list exactly as before.
- **Crossplay**, on every world: lets Xbox / Microsoft Store players join. This is *not* a vanilla-only setting — it is orthogonal to whether a world runs mods, and lives in every world's **Settings** modal. (It was briefly scoped vanilla-only during development; that was a mistake.)
- **Bespoke public UI card for vanilla worlds**: shows the endpoint, a click-to-reveal password, access badges, and a Join button (`steam://run/892970//+connect`). Vanilla Valheim has no launch argument to pre-fill a server password, so showing it on the card is what makes the world joinable.
- **Custom launch parameters** per world, appended after everything PhValheim generates so they can override it. Validated in the admin UI and never `eval`'d by `startWorld.sh`.
- **ADMINS editor** under CITIZENS in the world Settings modal, writing `adminlist.txt`. Entries are validated as SteamID64 — Valheim silently ignores malformed ones, which previously looked like "I added an admin and nothing happened."
- **Boss registry** (`includes/bosses.php`): the boss list is now defined once and consumed by the API, the public card and the AJAX refresh. Adding a boss is one array entry, one DB column and one PNG.
- **Password visibility control**: a per-world toggle (`password_public`) removes the password row from the public card entirely. It is also stripped from the AJAX payload, not just hidden in the markup.
- **Copy button** next to Show on the vanilla card's password, with a non-secure-context fallback — `navigator.clipboard` is undefined over plain HTTP, which is how most self-hosted installs are reached on a LAN.

### Vanilla world refinements
- Card now reads `Type: unmodded`.
- The **HEALTH** bar is hidden for vanilla worlds in the admin UI. Tick health comes from the TickMonitor BepInEx plugin, which a vanilla world does not run, so the bar could only ever sit empty.
- **Edit Mods is disabled** for vanilla worlds, and `edit_world.php` refuses them server-side — the page is reachable by URL regardless of the button. Switching a world to vanilla now clears its mod selection, so flipping back later does not resurrect a list you thought you had removed.
- Offline vanilla cards grey out fully. The access badges set their own background, so they needed their colour **replaced** rather than faded — opacity alone left a tinted pill on an otherwise grey card.
- The seed control is hidden when creating a vanilla world, replaced with an explanation. Custom seeds require the CustomSeed BepInEx mod and Valheim itself has no seed argument, so the field could only ever be ignored.
- **Vanilla worlds no longer display a fabricated seed.** Creating one stored a seed that could never reach Valheim — the game invents its own when it first generates the `.fwl` — and the public card rendered that stored value as though it were the world's real seed. Nothing failed; the number was simply wrong. Vanilla worlds now store no seed at creation, the card shows *generated on first start*, and the engine reads the real seed back out of the `.fwl` once the world has started (same extraction `importWorld.sh` has always used on uploaded saves, verified against real Valheim saves).

### Access lists (CITIZENS / ADMINS / BANNED)
- **The CITIZENS and ADMINS editors could report a save that never reached the disk.** Both wrote their file with `file_put_contents()` and ignored the return value, so a write that failed — most commonly a list file left owned by `root` by an older engine or by a restore — returned `{"success":true,"message":"Saved successfully"}` while the file kept its previous contents. The database and the file then disagreed permanently: `permittedlist.txt` went on gating the world with a stale list, so players the operator had just added were refused, and `adminlist.txt` looked like it was never written at all. Writes are now atomic (temp file + `rename`, which needs permission on the *directory* rather than the target file, so it also repairs a root-owned file on the next save) and **every failure is reported instead of swallowed**.
- **Nothing ever rewrote these files from the database.** The admin UI was the only writer, at the moment Save was pressed. A world that was restored from a backup, or rebuilt, kept whatever list files came with it. `syncAccessLists.sh` now regenerates all three from the database at every world start, making the database the single source of truth and letting drift heal itself on the next restart.
- **New BANNED list**, editing `bannedlist.txt`, alongside CITIZENS and ADMINS in the world Settings modal. A ban applies even when the world is public.
- **`worldDirPrep` only ever created `permittedlist.txt`.** `adminlist.txt` and `bannedlist.txt` were left for Valheim to create on first boot, so the admin UI was editing files that did not exist yet. All three are now created with the world.
- **An imported world's admins existed only on disk.** `importWorld.sh` wrote `adminlist.txt` directly but never set the `admins` column, so the Settings modal showed no admins for an imported world — and under the new sync those admins would have been dropped at the next start. It now writes the database and renders the files from it.
- **A bare SteamID64 no longer matches anything in Valheim 1.0 — entries are now written as `V_<steamid64>`.** `ZNet.ListContainsId()` ends by looking up the *display-prefix* form of the ID (`Steam` → `V`) and **assigns** that result over the earlier bare/`Steam_` checks instead of OR-ing it, so the plain ID everyone knows can never match. This affected all three lists identically, and is why a world with the correct SteamID64 in `permittedlist.txt` still answered **"Banned"** — Valheim uses that same client message for "not on the allowlist". Found by decompiling the shipped assembly and confirmed on a live server. PhValheim now stores whatever the operator types and converts on write, in both the admin UI and the engine's world-start sync, so existing worlds repair themselves.
- **Console players are supported.** Xbox / PlayStation / Nintendo / GameCenter IDs (`X_`, `S_`, `N_`, `A_`) are accepted; those platforms are *number-filtered* by Valheim, so their ID cannot be derived and must be read from the player's F2 panel.
- **All three editors now explain how to get a player's ID**: join any public world and press **F2**. That is the only method that works for every platform.
- **The "restart the world for this to take effect" prompts are gone.** Valheim re-reads all three files while running — verified on a live server, where a player rejected at 18:39 was admitted at 18:41 with no restart in between. Admin status is the one exception, since a connected client caches it until it reconnects.
- Citizens are validated the same way admins already were. Valheim silently ignores anything else in these files, which is indistinguishable from "I added them and nothing happened".
- File headers now match what Valheim itself writes byte-for-byte, including the double space in the admin and banned headers.

Verified against the real Valheim dedicated server: it reads and writes all three lists in the `-savedir` root, creates any that are missing at startup, and does not overwrite entries written from outside — neither before it starts nor while it is running. `dev_tools/test-accesslists.sh` covers the above end to end against a live container.

### Fixes
- **`-public` was hardcoded to `0`** in `startWorld.sh`, and `-password` was never passed at all despite being accepted as an argument. No world has ever been listed or password protected.
- **`worlds.public` is not a "public server" flag.** It is the CITIZENS access-control flag — when set it blanks `permittedlist.txt`. Valheim's `-public` argument is now driven by a separate `listed` column, so worlds that were opened to all citizens are not silently published to the global server browser on upgrade.
- **Trophy tooltip drift**: the AJAX refresh said "The Seeker Queen" where the server-rendered card said "The Queen", so the tooltip changed on first refresh. Both now come from the registry.
- **SQL injection in `setHungHeads()`**: the world name and trophy column were interpolated into SQL from an unauthenticated POST body. Both are now bound/validated against the registry.

### Admin UI
- **The mod browser is one "All" list.** The old "Available" tab held only the mods you had *not* picked, so ticking one made it vanish from the list you were reading. "All" holds every mod with the selected ones pinned to the top, and it is the landing tab on both Create World and Edit World. "Selected" remains as a filtered view. Previously the pages opened on "Selected" — empty by definition on a world being created — which rendered "No data available in table" and hid the whole catalogue behind a tab.
- **Create World no longer offers mod selection for a vanilla world.** The whole card is hidden, not just the tables; the header and the clone-mods-from-another-world block used to stay on screen for a world that can hold no mods.
- **World Settings modal reorganised** into General / Options / Access / Backups tabs, with the create and edit pages given a single primary action and a sticky action bar.
- **Access tab**: the world access switch moved to the top — it decides whether the Citizens list is consulted at all — and gained its own **Save Access** button. Citizens keeps its own **Save Citizens**, which now hides along with the editor instead of sitting there answering "Citizens saved." for a list that was no longer on screen.
- **One-time notice explaining the access switch change.** Upgrading flips how every world's switch *looks* — `Public World: on` becomes `Use Access List: off` — so the Access tab explains it once, then never again. Armed only for servers that already have worlds, so a fresh install is never told about a change it never saw. If the id-format notice is also due, the two are shown one after the other rather than stacked.
- **One world that will not start no longer takes the engine down.** The start path called `exit 1`; supervisor then restarted the engine, whose pre-flight resets every world to `stopped` — so a single unstartable world became a restart loop that also clobbered `create` and `update` commands queued against the *other* worlds. It is now marked `broken` and the engine carries on. The wait that followed it was also unbounded, so a world that started and then died (a rejected password, a mod that aborts) parked the engine forever; that wait now gives up after 120s and marks the world `broken` too.
- **Access tab decluttered.** The same five-line "how to find a player's ID" note used to sit above each of Citizens, Admins and Banned. It now appears once, above all three, as a disclosure that starts closed — a screen reader was reading the whole procedure out three times per visit. Each list instead carries a one-line hint with a real example (`V_76561198012345678`), replacing a placeholder that vanished on focus and never showed the `V_` form. Each list heading now shows a live count, and **Look Up SteamID is on all three lists** — it used to exist only on Citizens and always pasted its result there, so there was no way to look up an ID while editing Admins or Banned. Banned gains a warning line noting that a ban applies even with the access list switched off.
- **"Public World" is now "Use Access List".** The switch never had anything to do with the Valheim server browser — it only decides whether `permittedlist.txt` is enforced — and calling it *Public* invited it to be read as the separate **List in server browser** option. The sense is inverted to match the new wording: **on** means the Citizens list is enforced. Nothing is stored differently, so a world's actual access is unchanged; a world that read "Public World: on" now reads "Use Access List: off".
- **Look Up SteamID returns the `V_` form** rather than a bare SteamID64. It pastes straight into an access list, so it was manufacturing the exact input Valheim 1.0 refuses. The same helper in the legacy `citizensEditor.php` is fixed too.
- Hint text spacing on the General and Options tabs; the "needs a world restart" note now shares a line with its Save button instead of sitting stranded below it.

### Upgrading from 2.39
- **Stored access IDs are converted to the `V_` form automatically** on first start, and the Access tab explains the change once. Idempotent; already-prefixed and console IDs are left alone, and anything unparseable is kept verbatim rather than dropped — silently deleting an unrecognised ID would lock someone out of their own world. Note this is a *display* fix: `syncAccessLists.sh` already canonicalised on the way out to the files, so an upgraded server was functionally correct before it ran. What it was not was legible — the Access tab showed bare IDs while the file Valheim reads said `V_…`.
- `container/mysql/tsmods_seed.sql` refreshed: **9,390 → 11,030 mods** (the committed seed was seven months old, so a fresh install started that far behind until the first 12-hourly sync).

### Client
- **No client update is required.** 2.0.12 remains current and works with both modded and vanilla worlds — vanilla worlds are joined from the public card's `steam://run/892970//+connect` button, which needs no client at all.

### Known gaps
- **The Deep North boss cannot be a hung head.** Valheim 1.0 shipped without a trophy for it: the assets carry 131 `Trophy*` tokens and only 7 `BossStone*`, none for Kall Fimbulbringer, and the seven existing stones are confirmed. The hung-head wall therefore stays at seven bosses. `public/api.php` still logs any unrecognised `Trophy*` POST, so if one ever appears it identifies itself. See `docs/RELEASE-2.40-DESIGN.md` §9.
- Custom seeds need a BepInEx mod, so vanilla worlds always generate a random seed.

## v2.39 — Modpack Rebuild Boot Fix

First stable release of the 2.38 backup system work, plus a fix for worlds failing to boot after a modpack rebuild.

### Fixes
- **World boot failure after modpack rebuild** ([#80](https://github.com/brianmiller/phvalheim-server/issues/80)): Mod zips packaged on Windows can store directories without the execute bit. `unzip` preserves that, leaving BepInEx unable to traverse the extracted plugin directories and aborting startup with a fatal `UnauthorizedAccessException`. `u+rwX` is now restored after Thunderstore extraction and after custom mods/configs/patchers installs (`cp -p` preserves the bad source permissions the same way).
- **Backup/restore progress behind Cloudflare Tunnel**: Replaced streaming progress with background jobs plus polling, which Cloudflare Tunnels do not buffer.
- **NGINX FastCGI buffering**: Disabled for streaming progress endpoints.
- **Backups table schema**: The `orphaned` column is now present in the initial table creation, not only in the migration path.

### Included from v2.38 (previously pre-release)
The full backup system modernization — activity-aware scheduling, tiered retention, compression, one-click restore, backup management UI, and startup reconciliation. See the v2.38 notes below for details.

---

## v2.38 — Backup System Modernization (Pre-release)

### Backup Engine
- **Activity-aware scheduling**: Backups only trigger when players have been online since the last backup (configurable)
- **Configurable intervals**: Set backup frequency per-server or per-world (default: 30 minutes)
- **Full world backups**: Archives the entire world directory (game data, mods, configs) instead of just Unity save files
- **Compression support**: Optional gzip or zstd compression with deferred scheduling (compress during off-peak hours)
- **Tiered retention**: Keep all backups for N hours, then daily, weekly, and monthly tiers — with per-world overrides
- **Performance tuning**: CPU priority (nice), I/O priority (ionice), and compression level controls to minimize impact on active players
- **Disk space preflight checks**: Backup and compression operations check available space before starting; graceful fallback to uncompressed if space is insufficient
- **Manual backups**: On-demand backup creation from the admin UI with real-time progress polling
- **Transitional state protection**: Backups and restores are blocked while a world is starting, stopping, updating, or being deleted

### Restore
- **One-click restore**: Restore any backup from the admin UI with live progress
- **Pre-restore safety backup**: Automatically creates a safety snapshot before overwriting world data
- **Legacy format support**: Detects and correctly restores backups from pre-2.38 (Unity save path format)
- **Disk space checks**: Warns if insufficient space for the safety backup; skips safety backup rather than failing the restore

### Backup Management UI
- **Backup history table**: View all backups per world with date, type (scheduled/manual), size, compression status
- **Bulk operations**: Select and delete multiple backups at once
- **Download**: Download any backup directly from the browser
- **View details**: Inspect backup metadata including mod list and world settings at time of backup
- **Per-world settings**: Override global backup settings (interval, retention, compression, performance) per world
- **Orphan detection**: Automatically detects and flags backup records whose files are missing from disk

### Dashboard
- **Storage card**: Consolidated volume status showing all mounted volumes (data, backups) with usage bars, mount paths, and capacity warnings
- **Dedicated volume detection**: Uses `mountpoint` detection for accurate bind-mount identification
- **Orphan warnings**: Dashboard shows count of orphaned backup records with a one-click cleanup button
- **Dynamic updates**: Storage card refreshes after backup create, delete, and restore operations

### Backup Reconciliation
- **Startup reconciliation**: On container start, scans for orphaned DB records (files missing) and untracked backup files (files on disk with no DB record)
- **Auto-import**: Discovers backup files on disk that aren't tracked in the database and imports them
- **Orphan recovery**: If a previously orphaned file reappears (e.g., volume remounted), the orphan flag is cleared automatically
- **API endpoints**: Trigger reconciliation or purge orphaned records from the admin UI

### Infrastructure
- **New scripts**: `worldBackupCompress`, `worldBackupRetention`, `worldBackupReconcile`, `worldActivityMonitor`, `worldRestore`
- **New cron jobs**: Activity monitor (every 5 min), backup (every 10 min, self-gating), compression (hourly), retention (hourly)
- **Database migration**: New `backups` table, backup settings columns in `settings` and `worlds` tables
- **CSS tooltips**: Replaced native `title` tooltips with JS-powered tooltips that work inside modals
- **Cloudflare Tunnel compatibility**: Backup/restore progress uses background jobs with polling instead of streaming, which Cloudflare Tunnels buffer

---

## v2.37 — macOS Apple Silicon BepInEx Fixes

- Ship patched MonoMod.RuntimeDetour.dll and BepInEx.Preloader.dll for macOS arm64
- Fixes MonoMod Harmony exceptions caused by MAP_JIT W^X enforcement on M-series chips
- All 8 BepInEx plugins now load with zero errors on macOS arm64

## v2.36 — macOS Client Support

- Cross-platform PhValheim Client (Windows, Linux, macOS)
- Universal `.pkg` installer for macOS (Intel + Apple Silicon)

## v2.35 — Setup Wizard Fix

- Fixed fresh install incorrectly showing settings modal instead of setup wizard
- Removed `TZ` from env var upgrade detection loop

## v2.34 — Anonymous Usage Analytics

- Optional anonymous usage analytics with opt-out
- Analytics dashboard at analytics.phvalheim.com

## v2.33 — Analytics Infrastructure

- Added analytics collection framework
- UUID-based installation tracking

## v2.31 — Setup Wizard & DB-Backed Settings

- All environment variables migrated to database settings
- Setup wizard for fresh installations
- Server Settings modal in admin UI
- Migration notice for upgraders

## v2.27 — Mod Pack Management

- Dependency resolution in PHP
- Two-table mod selection layout
- State-driven mod management architecture
