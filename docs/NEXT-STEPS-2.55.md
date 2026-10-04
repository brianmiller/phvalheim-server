# 2.55 — state, and what is left to do

Last updated 2026-10-04. `docs/RELEASING.md` says to check this file for the release being
shipped; it records which paths have actually been exercised and which have only been reasoned
about.

2.55 is now **two** bodies of work in one release:

1. the mod config editor (issue #84) — per-key overrides that survive a mod update;
2. the config-only client payload and `mode='repackage'` — applying a config change without a
   full world update, and without sending every player 573 MB.

Design record for (2), including the measurements and the traps: `docs/DESIGN-2.55-config-payload.md`.

## Where 2.55 is right now

| | |
|---|---|
| Docker tags | **`:rc` and `:2.55`**, both on one digest |
| Digest | `sha256:6c4ad28d3c6c83851e67c1efc2d816d1dd3bbb88a3b2ebce1294c0d996096121` — `IMAGE VERIFY OK`, all 43 2.55 markers matching |
| `:2.55` built how | **rebuilt** with `EXTRA_TAGS="2.55"`, never a retag — and it came out on the same digest as `:rc`, which is the expected result for an unchanged tree |
| **`:latest`** | **UNTOUCHED**, still on `sha256:616d3fb3…` — a different digest. Verified with `imagetools inspect` per tag, not assumed |
| Deployed on | the maintainer's test server — registry, local tag and running container all on `6c4ad28d` |
| Git | committed and pushed (`064599f1`), tag **`v2.55`** pushed |
| GitHub release | **`v2.55` published as a PRE-RELEASE.** `v2.54` is still marked Latest |
| Client (`phvalheim-client`) | **2.0.15** committed (`4973445`), tag `2.0.15` pushed, published as a **PRE-RELEASE**; `2.0.14` is still marked Latest |
| `clientMinVersion` | deliberately left at **2.0.14** — a 2.0.14 client still works against 2.55, so raising it would block players for nothing |

**A 2.0.15 pre-release cannot reach players by accident.** `includes/git.php` excludes both
`draft` and `prerelease` when choosing which client version the download UI offers, so the UI
keeps offering 2.0.14 until someone promotes 2.0.15 to a full release. That filter is guarded by
`dev_tools/test-client-release-source.php`.

**`sha256:9a474d57…` was the first ever 2.55 `:rc` and it is BROKEN — never promote it.** It
applied no overrides at all on a world update. See the CHANGELOG entry.

## What is left

Both repos are committed, tagged and published as pre-releases; `:2.55` is on DockerHub. What
remains is all gated on the two untested paths below, plus Brian's call on promotion.

1. **Test the two paths nobody has run** — see "NOT exercised" below. A real client doing a
   config-only sync, and an **old** client against the new server.
2. **Promote the server to a full release**, when the above is satisfied: rebuild with
   `EXTRA_TAGS="2.55 latest"`, then mark the GitHub release Latest. Never `docker tag` the
   tested `:rc`.
3. **Attach the macOS asset to the 2.0.15 client pre-release.** `builds/build-all-2.0.15.sh`
   covers tgz/deb/rpm/msi; macOS comes through its own path (`prompts/macos-build.md`). Two
   traps: do **not** rename a previous version's macOS asset under a 2.0.15 name (that is how
   2.0.13 shipped 2.0.12's bits), and **rebuilding an artifact does not refresh an asset already
   attached to a release** — it has to be re-uploaded.
4. **Promote the client**, which is what actually turns the 80 KB sync on for players: the
   download UI ignores pre-releases, so 2.0.15 reaches nobody until it is a full release.
5. **Optional, Brian's call:** restore 12 override rows deleted by reset clicks during
   exploration — `Azumatt.SleepSkip.cfg` (4), `spectralmemories.fasterboats.cfg` (2),
   `zolantris.ValheimRAFT.cfg` (6). Originals are in `custom_configs/.imported-pre-2.55/`, so
   moving those three files back and re-running
   `modConfigs.py --import-legacy --world VikingOutlaws` would re-take them.
6. **Optional polish:** the `modified` badge means "differs from the mod author's documented
   default", which is not the same as "you changed this". Renaming it to *differs from default*
   would remove the ambiguity.

## What has actually been exercised

**Mod config editor (1)** — verified live on the test server through a real post-purge world
UPDATE of VikingOutlaws: the migration (51 rows imported from 670 keys, 619 untouched defaults
correctly skipped), stored settings reaching the server tree, the client staging tree **and the
payload zip the player downloads**, `server_only` withheld from the zip, the seed and loader
intact, materialise idempotent on re-run, the PHP layer as the `phvalheim` user, the rendered
pages, and the fileBrowser guard. 51 oracles in `dev_tools/test-mod-config-editor.sh`.

**Config payload and repackage (2)** — 35 oracles in `dev_tools/test-client-payload-sync.sh`,
**mutation-verified**: six mutations (wrong zip scope, wrong source tree, no temp-and-move, `''`
instead of `NULL`, `mode='start'`, no `packageClient`) each turn the suite red, and reverting
each restores 35/35. All 43 2.55 verify markers predicted locally before building. Client
compiles clean against net9.0.

Then verified **live on the test server**, on a real modded world:

- The migration added `worlds.config_md5` as nullable `text`, logged, no errors.
- `repackageWorld()` as the `phvalheim` user **refused** a world in `mode='updating'`
  (returned `false`, mode unchanged) and **accepted** a stopped one — the control and the case.
- The engine ran the repackage in ~18s: `80 applied, 0 added, 0 written into new files`, then
  `Config archive for 'VikingOutlaws': 80174 bytes`, and **landed on `mode='stopped'`** — it did
  not boot a world that was stopped.
- **`Show Clock = Off` is now in the payload players download.** It was absent before; this is
  the bug that started the whole change.
- `world_md5` == `md5sum` of the real zip (`48a57216…`) — the old-client identity.
- `config_md5` == `md5sum` of the config archive; the config bytes in the two archives are
  **byte-identical**, so both checksums describe one generation of the tree.
- Ratio measured live: **577,604,696 / 80,174 = 7,204×**.
- `api.php?mode=getSyncState` returns `world=…` and `config=…`; `mode=getMD5` returns the same
  world hash unchanged. Both archives serve **HTTP 200**, and the archive is readable by the
  `phvalheim` user the nginx workers run as.
- `world_configs.php` **rendered** (4.2 MB of HTML, no PHP diagnostics): the Apply to players
  button, its handler, the `repackageWorldNow` call, the new wording, and the old
  "update the world" instruction gone.
- Restarting the world re-applied all 80 overrides and left both archives alone.

**NOT exercised, and these are the ones left to test:**

- A **real client** performing a config-only sync (80 KB instead of 573 MB) — needs a client
  release; the server half is proven but nothing has consumed `config_md5` yet.
- An **old** client against the new server. The server contract is verified unchanged
  (`getMD5` byte-identical to the zip's hash) but no actual old client has been run. This is the
  failure mode no new-client test can see.
- The `repackaging` badge **rendering** on the dashboard mid-repackage. The label maps and the
  CSS rule are all three present and marker-checked, but the repackage completes in ~18s so the
  pill was never caught on screen.
- A fresh install, as opposed to a 2.54/2.55 upgrade.
- The paste-a-config import against a real shared file.

## Do not "tidy up" any of these

Full list with reasoning in `docs/DESIGN-2.55-config-payload.md`. The short version:

- **`custom_configs/` cannot be retired.** `createCustomSeedConfig()` writes the world's SEED
  there and every update redistributes it. Removing the copy regenerates worlds with the **wrong
  map**. `materialiseModConfigs` runs *after* the copy so the database wins.
- **A consumed legacy import must be MOVED** to `custom_configs/.imported-pre-2.55/`.
- **`world_md5` must stay exactly the md5 of the real `<world>.zip`** — a composite puts every
  old client in a permanent re-download loop.
- **`InstallCustomConfigSecureFiles` must not be called in the repackage branch** — it would
  invert the database-over-directory ordering.
- **`new_world.php` deliberately has no Config column** (marker `v55n` pins the asymmetry).
- **`BepInEx.cfg` and `ZeroBandwidth.CustomSeed.cfg`** are excluded from the editor and import.
- **BepInEx normalises typed values** once bound — a stored `1500FF` reads `1500FFFF` on disk.
  Compare with a prefix match, never equality.

Rollback for the test server: `custom_configs-before.tgz` + `phvalheim-before.sql`, taken
before the upgrade and kept in the maintainer's backup directory on that box.
