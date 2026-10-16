# PhValheim 2.55 — test plan

Every 2.55 feature, what proves it, and — stated plainly — what no automated check can reach.

Automated coverage runs in three layers:

| Layer | What it is | Where |
|---|---|---|
| **Oracle** | A test that drives the real code and fails on the pre-fix behaviour | `dev_tools/test-*.sh`, `dev_tools/test-*.php` |
| **Verify marker** | A grep/position assertion run **inside the built image**, joined to the `&&` chain that gates `IMAGE VERIFY OK` | `dev_tools/buildRcDetached.sh` |
| **Runner** | Real macOS hardware, for claims no Linux check can answer | `.github/workflows/macos-verify.yml` |

A marker that is not in the `&&` chain gates nothing — 2.47 shipped ~50 of those. Every marker
below is in the chain, and every marker's expected value was **predicted locally** before a
build was spent, which is how `v55em` was caught wanting 1 when the truth is 2.

---

## 1. Mod config editor — per-key overrides that survive a mod update

**The claim.** An operator edits any mod's settings in the admin UI; the changes are stored as
sparse per-key rows and re-applied after an update that wipes the mod tree.

**Why the obvious design is wrong.** Copying whole config files into `custom_configs/` froze
them: a setting the new mod version *added* never appeared, one it *removed* stayed forever.
`mod_config_overrides` stores only keys the operator actually changed.

| Check | Oracle |
|---|---|
| cfg parse, discover, materialise | `test-mod-config-editor.sh` |
| settings survive the update-path purge | `test-mod-config-update-survival.sh` |
| missing cfg files created, missing keys injected | `test-mod-config-editor.sh` |
| editor entry points reachable from a running world | `test-config-entry-point.sh` |

**Manual, not covered:** that a real mod actually *honours* the written value at load. Nothing
in CI loads BepInEx.

---

## 2. Config-only payload sync — 80 KB instead of 573 MB

**The claim.** Changing a mod setting sends players a config archive of a few tens of KB rather
than the whole modpack.

**The trap this release learned.** `world_md5` must always equal the md5 of the real `.zip`,
because `Syncer.cs` hashes the file on disk and compares. Making it a composite puts every old
client in a permanent re-download loop. So `config_md5` is strictly additive, and
**`mods_md5`** — md5 of the payload's sorted per-entry CRC-32s and sizes, config excluded — is
the identity the client decides on. Re-zipping an unchanged tree changes the bytes but not
`mods_md5`.

| Check | Oracle / marker |
|---|---|
| sidecar checksums, config-only branch | `test-client-payload-sync.sh` |
| old client degrades to a full download | `test-client-payload-sync.sh` |
| bytes actually transferred | `test-client-payload-sync.sh` |
| `mods_md5` never NULLed | marker `v55ch` (**wants 0**) |
| `setModsMD5` called in `packageClient` | marker `v55cd` |

**Manual:** a real client against a real world, confirming the small download path fires and the
world keeps running. Needs the 2.0.15+ client.

---

## 3. Config shape memory — the editor stops emptying after an update

**The claim.** `mod_config_shapes` remembers each cfg's text **before** the purge, so the editor
still offers those settings during the gap between an update and each mod's next load. Went from
26-of-29 → 8 after every update.

**Ordering is the whole proof and fails silently:** after the purge there is nothing left to
read, and the snapshot would quietly remember nothing.

| Check | Oracle / marker |
|---|---|
| remembered surface, prune of unclaimed shapes | `test-config-shape-memory.sh` |
| `--snapshot` runs **before** both purge sites | positional markers |
| coverage banner: written / remembered / never seen | `test-config-shape-memory.sh` |

---

## 4. Legacy import + Migration Review

**The claim.** Pre-2.55 `custom_configs/` files are imported (only keys differing from the
documented default), originals moved to `.imported-pre-2.55`, and the **operator** decides
whether the originals are destroyed.

`custom_configs/` could **not** be retired — it carries the world seed across the purge.

| Check | Oracle |
|---|---|
| import compares against the documented default | `test-mod-config-migration.sh` |
| per-file safe/at-risk classification | `test-config-migration-delete.php` |
| at-risk files left **unticked** | `test-config-migration-delete.php` |
| `configEditorMigrated` only set on exit 0 | migration source |
| Import button, Continue-once-empty | `test-config-migration-delete.php` |

**Note:** `dropStale()` still deletes with **no confirmation** — the likely cause of 12 lost
rows. Not fixed in 2.55; flagged below.

---

## 5. Config attribution by plugin GUID

**The claim.** A cfg is named after the **author's GUID**, not the package — `SkillInjector`
ships `com.pipakin.SkillInjectorMod`. GUIDs are learned from each DLL's `BepInPlugin` blob into
`mod_plugin_guids`; rows written before a world's GUIDs were known are re-attributed on every
boot (not one-shot — a GUID can become knowable at any later install).

| Check | Oracle / marker |
|---|---|
| blob scan, version check, named-arg guard | markers `v55ci`–`v55cl` |
| re-attribution NULL → value only, never re-owning | `test-config-reattribution.sh` |
| configs **not** invented for absent mods | `test-mod-config-editor.sh` |
| `custom_plugins/` DLL claims an unmatched cfg | `test-mod-config-editor.sh` |

---

## 6. Save & apply, and the restart-pending notice

**The claim.** A running world says when it is not using the mod settings that were saved —
BepInEx hands a plugin its config at load, so a setting saved while the world is up is stored
and not in effect.

| Check | Oracle / marker |
|---|---|
| writer above the early return | markers `v55dy`, `v55dz` (positional) |
| payload sites, hand-off | markers `v55eb` (=2), `v55ed` (=3) |
| the old "look there yourself" wording is gone | marker `v55ee` (**wants 0**) |
| four mutations, each caught by one assertion | `test-config-restart-pending.sh` |

---

## 7. A password is generated for a NEW world only  ← *request 1*

**The claim.** `ensureNewWorldPassword()` is called from the engine's **create** block and
nowhere else. For an existing world it is the operator's decision in the Settings modal, and
**empty is supported** — `startWorld.sh` omits the flag, and Valheim only validates passwords
under `-public 1`.

**Why it was wrong twice.** 2.53 generated one in the migration (password-protecting every world
at its next restart, while players were still on a client that could not forward one), then moved
it to the **update** path — narrower and still wrong, because `create` ends by setting
`mode='update'`, so the one call site served new worlds *and* every rebuild of an existing one
(mod-list save, Rebuild Mods, Hugin).

**Status: already shipped at `33a844f4`, before this round of work.** Verified, not rewritten.

| Check | Marker |
|---|---|
| the generator refuses an already-published world | `v55du` |
| the call is inside the create block (line positions) | `v55dv` |
| **no generating call at/below the update block** | `v55dw` (**wants 0** — was 1 and shipped in 2.53) |
| the generated value is re-read next to the call | `v55dx` |

Oracle: `test-blankpassword-retirement.sh` — 21 assertions; the already-published control and the
nothing-below-the-update-block assertion both fail on the old behaviour.

**Manual:** create a new modded world → it has a password. Take an existing world with no
password, save its mod list / press Rebuild Mods / run Hugin → it still has none.

---

## 8. A restart no longer strands a world mid-update  ← *request 4*

**The claim.** `reconcileStrandedWorlds()` runs at boot, before `autoStart`, and lands every
in-flight world on the truth from the process table.

**Why it could never clear itself.** `mode` holds **commands** (which the loop has branches for)
and **in-flight states** (`creating`, `updating`, `starting`, `stopping`, `repackaging`,
`backup`) which nothing in the loop matches — so only the code that set them clears them.

**The load-bearing half** is the autostart refusal: `purgeWorldModsConfigsPatchers()` runs early
in an update, so the likeliest interruption leaves a **modded world with zero plugins**, and
autostart would publish it to players as effectively vanilla.

| Check | Marker |
|---|---|
| reconciler exists / is called | `v55ef`, `v55eg` |
| runs **before** `autoStart` (positional) | `v55eh` |
| **no command mode in the stranded list** | `v55ei` (**wants 0** — `update` there would rebuild every world every boot) |
| `autoStart` consults the flag | `v55ej` |
| column exists and **DEFAULT 0** | `v55ek` |
| `creating`/`updating`/`broken` labelled in **both** maps | `v55er` (=6) |
| `broken` has a CSS rule | `v55es` |

Oracle: `test-stranded-world-recovery.sh` — 42 assertions. Six targeted mutations each flipped
exactly their own assertion: removing the autoStart check, adding `broken` to the stranded list,
adding `update` to it, landing on `start`, flagging all six modes, and `DEFAULT 1`.

**Manual, and worth doing:** start a world update, restart the container mid-update, confirm the
world comes back as **Stopped** with "Interrupted by a restart…", is **not** autostarted, and
that pressing **Update** rebuilds it and clears the flag.

---

## 9. An automatic update no longer restarts a world with players on it  ← *request 3*

**The claim.** `isIdle()` returns idle **only** for a recently-observed zero. A nonzero count is
never idle at any age.

**The ambiguity that caused the kick.** On a crossplay world the count comes from PlayFab's
`... now N player(s)`, emitted on join and on leave and **never otherwise**. A stale nonzero
count means either "everyone left uncleanly" or "someone is still playing", and the count cannot
tell them apart. 2.47 resolved it in the dangerous direction.

**Accepted cost:** a world whose count sticks nonzero will not auto-update on the idle path. The
escape hatch is `autoupdate_max_wait_hours` + `on_timeout='force'`, checked **before** the gate.

| Check | Marker |
|---|---|
| **staleness arm gone** (`return 0` within 3 lines of a `player_count_at <` test) | `v55el` (**wants 0**) |
| two distinct waiting reasons | `v55em` (=2) |
| force escape hatch checked before the gate (positional) | `v55en` |
| BETA badge on **both** switch-on surfaces, same line as the heading | `v55eo` (=2) |
| the selects name Off as the default | `v55ep` (=2) |
| **nothing enables auto-update by itself** | `v55eq` (**wants 0**) |

Oracle: `test-autoupdate-idle-gate.sh` — 19 assertions. Re-introducing 2.47's arm turns three
red. Removing either badge, removing "Off (default)", adding a self-enabler, or moving the gate
above the force check each turn exactly one red.

**Defaults confirmed, not assumed:** `settings.autoUpdateMode` and `worlds.autoupdate_mode` are
both `TINYINT DEFAULT 0`, and a tree-wide search finds nothing setting either to 1. Auto-updates
were already off by default; what 2.55 adds is the Beta marking and the refusal.

**Backlog:** `docs/BACKLOG.md` — Companion-side `ZNet.GetConnectedPeers()`, as a **heartbeat
rather than an event**, which is the property the log signal lacks.

---

## 10. macOS: "Valheim is damaged and can't be opened"  ← *request 2*

**Repo: `phvalheim-client`.** Commit `e50678a`.

**The cause, measured rather than asserted.** 2.0.15 and earlier wrote the `lipo -thin` output to
`Valheim.app/Contents/MacOS/Valheim.x86_64` and codesigned it in place. A signed bundle seals a
manifest of its contents in `_CodeSignature/CodeResources`, and files under `Contents/MacOS` that
are not the main executable are sealed as resources — so an unlisted file there invalidates the
resource envelope. Every Apple Silicon user who launched a modded world broke their own install,
and it stayed broken after PhValheim exited: Steam's Valheim would not start either.

**Measured on a real `macos-15` runner** (`bundle-seal` job, 6/6):

| Step | Result |
|---|---|
| control: freshly signed bundle verifies | PASS |
| add a file under `Contents/MacOS` | `a sealed resource is missing or invalid` — **the cause** |
| `spctl` on that bundle | same verdict — this is what Gatekeeper calls "damaged" |
| **delete the file, do not re-sign** | **verifies again — the repair is complete** |
| shadow bundle with symlinked `Resources` | executable runs rc=0, `Resources` resolves |
| original bundle after the shadow was built | still valid |

That fourth row is the one worth the runner time: `RepairValheimBundle()` only deletes and never
re-signs, on the reasoning that the seal was computed over a tree without the file. If that
reasoning were wrong, affected users would stay broken and we would not have found out.

Oracle: `dev_tools/test-macos-bundle-integrity.sh` — 17 source assertions, **all 17 fail on
2.0.15**. Two passed blind on the first draft (one searched the codesign call for `valheimDir`
when the pre-fix target was a variable named `x86Exec`; one asked whether the repair re-signs
anything when there was no repair method to read) — both are now stated positively and guarded
on the stimulus.

**Manual, and genuinely unverified:** that Valheim itself launches under Rosetta from the shadow
bundle with mods loaded. CI has no Steam, no Valheim licence and no PhValheim server, so the
`NSBundle` → `Contents/Resources/Data` resolution is proven only with a synthetic Unity-shaped
bundle, not with Valheim. **This needs an Apple Silicon Mac with Valheim installed.**

---

## What no automated check in this release reaches

Stated so it is not mistaken for covered:

- **Mod sync, doorstop injection, and actually launching Valheim** — on any platform. No Steam,
  no licence, no server in CI.
- **A mod honouring a written config value at load.** Nothing in CI loads BepInEx.
- **The small-payload path end to end** against a real client and a real world.
- **The macOS Rosetta launch** (above).
- **Crossplay player counting against real players.** The very signal this release stopped
  trusting; `docs/BACKLOG.md` is the fix.

## Known-not-fixed, carried forward

- `dropStale()` deletes migration rows with **no confirmation**.
- `tgz`/`deb`/`rpm` client builders hardcode `docker run -ti` (die with no tty) and never check
  their innie's exit code, so `-b` commits and pushes a **failed** build.
- `docs/NEXT-STEPS-2.55.md` still claims `:2.55` is on Docker Hub in three places. It is not —
  2.55 ships as `:rc` only, and the `:2.55` Hub tag was deliberately deleted.
- The macOS client fix needs a client **version bump and rebuild** to reach anyone. That is
  Brian's call.
