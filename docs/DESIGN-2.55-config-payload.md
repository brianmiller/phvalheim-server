# 2.55 — the config-only client payload and `mode='repackage'`

Design record for what shipped, not a proposal. Written 2026-10-04. Every number was measured
on a real modded world on the maintainer's test server.

Supersedes `docs/PROPOSAL-repackage-mode.md`, which was the pre-implementation version of this
and has been removed so the two cannot drift.

## The measurements

```
full client payload   VikingOutlaws.zip   577,604,688 bytes   (573 MB)
config-only archive   <world>-config.zip       80,174 bytes   (78 KB)
                                            ---------------
                                                    ~7,200x smaller

staging tree split    572M client/BepInEx/plugins   <- untouched by a config edit
                      224K client/BepInEx/config    <- the only thing that changes
```

## What was wrong

Saving in the Mod Configs editor wrote `mod_config_overrides` rows and set no `worlds.mode`, so
the engine never woke. `materialiseModConfigs()` ran on world **start** and world **update**
only, and the file players download is `<world>.zip`, which only `packageClient()` rebuilds — in
the update branch alone. For a mod that runs on players' clients, a start applied the value to a
tree nobody downloads.

Measured, with a natural control in the same file:

```
Show Clock = Off          saved 17:29   game/ yes   client/ yes   PAYLOAD no
Clock Font Color=1500FF   saved 05:18   (before the 17:08 update) PAYLOAD yes
worlds.date_updated       17:08:44  <- last packageClient
```

Same mod, same file. The only difference is which side of `packageClient()` each was saved on.

## The compatibility trap that decided the design

`Syncer.cs` compares the server's `world_md5` against `getMD5()` of the payload file **on the
player's own disk**.

The obvious design — a repackage rebuilds only the 78 KB archive and bumps a new `config_md5`,
leaving `world_md5` alone — **silently breaks every existing client**: unchanged `world_md5`
reads as "in sync", so an old client never learns the config moved.

The repair that first suggests itself is worse. Make `world_md5` a composite
(`md5(fullMd5 + configMd5)`) so old clients notice, and they download the 573 MB zip, hash the
bytes they just got, find the real zip md5 still disagrees with the composite, and
**re-download on every launch forever**.

> **`world_md5` must always equal the md5 of the real `<world>.zip`.** `config_md5` is an
> additional, finer signal — never a replacement.

So a repackage rebuilds **both** archives and sets **both** checksums. New clients pay 78 KB;
old clients degrade to exactly today's behaviour. `mode=getMD5` is untouched. Guarded by **T1**
in `dev_tools/test-client-payload-sync.sh`, which is first in the file because no assertion
about the new client could ever see this.

Once `clientMinVersion` has moved past the old clients, the full re-zip could be dropped from
the repackage path. That is a later, separate decision; the manifest's `minClientVersion` is the
mechanism already built for it. **`clientMinVersion` was deliberately NOT bumped for this** — an
old client still works, so bumping it would block players for no reason.

## Things that must not be "tidied up"

- **`InstallCustomConfigSecureFiles` must NOT be called in the repackage branch.** In the update
  branch it runs *after* `packageClient`, but the `custom_configs` copy it belongs to runs
  *before* `materialiseModConfigs` — and that ordering is what makes the database win over the
  directory. Calling it after materialise inverts the precedence and lets a stale file overwrite
  the value the operator just saved.
- **The repackage branch must land on `running`/`stopped` read from the process table.**
  `repackaging` is not a command, so leaving it set has the 2-second loop revisit that world
  forever. And it must never set `mode='start'` — it never stopped anything, so `start` would
  boot a world the operator deliberately left stopped.
- **A failed repackage keeps the previous `world_md5`.** `setMD5 ""` means "no payload" and
  would tell every client there is nothing to sync, while the previous payload is still on disk
  and still correct.
- **`date_updated` is not touched by a repackage.** It reads as "World last updated" and sorts
  the offline list; it means *mods last rebuilt*.
- **The config archive is written to a temp file and moved into place.** `rm -f` then `zip`
  destroys the last good archive the moment zip fails, and the stored checksum then names a file
  that is not there — so every client asks for a 404 instead of falling back.
- **`config_md5` is `NULL`, never `''`, when there is nothing to compare.** `''` would read as a
  legitimate answer the first time anything compared it with `==`.
- **The client replaces `BepInEx/config` wholesale, never merges.** Resetting a setting *removes*
  a key and can remove a whole file; a file-by-file copy leaves the stale one behind.
- **The client must verify a downloaded payload before writing the sync record.** The old
  per-launch re-hash caught corrupt downloads by accident; recording without verifying turns that
  into a permanent lie.
- **`repackageWorld()`'s mode check is a whitelist.** `worlds.mode` is one column and the loop
  reads it once per world per pass, so writing `repackage` over `updating` replaces the command
  the engine is acting on rather than queueing behind it.
- **A new mode needs THREE places, not two:** the PHP label map and the JS label map in
  `admin/index.php`, *and* a `.status-badge` rule in `phvalheimStyles.css`. A mode with a label
  but no CSS rule renders with the bare badge style — no colour, no pulse — so a busy world looks
  idle.

## Probe defects found while testing this, worth not repeating

Three of these cost real time and every one of them reported a fault that did not exist:

- **A grep that matched the code's own explanatory comments.** The comment saying `mode='start'`
  would be wrong *contains* `mode='start'`; the comment saying
  `InstallCustomConfigSecureFiles` is deliberately not called contains its name. T8 now strips
  whole comment lines before matching.
- **`\t` is not a tab.** In a double-quoted bash string `"\t"` is backslash-t, and grep's BRE/ERE
  does not interpret it inside a bracket expression either. `[ \t]*` silently matched nothing —
  once in the test (reporting a structural bug) and once in two new verify markers in
  `buildRcDetached.sh` (which would have failed a correct build). `[[:space:]]` in markers,
  `$'\t'` in bash. awk *does* interpret `\t`, which is why `v55g` was fine.
- **A 200 KB fixture of `/dev/zero` compresses to nothing**, so the "archive is small" assertion
  passed against a mutant that zipped the whole plugin tree. `/dev/urandom` makes it a real
  oracle — verified by mutation: with zeros the mutant tripped one assertion, with random bytes
  two.
- **`packageClientConfig` leaves the shell's cwd inside the staging tree** (as `packageClient`
  always has). A later `rm -rf` of that tree invalidated every relative path in the test with
  ESTALE. Called in a subshell now.

## What has actually been exercised

See `docs/NEXT-STEPS-2.55.md` for the full list, including the live run on the test server.
In short: 35 mutation-verified local oracles, 43 in-image verify markers, and a live repackage
of VikingOutlaws that put `Show Clock = Off` into the payload players download, built an
80,174-byte config archive, kept `world_md5` equal to the real zip's hash, and landed the world
back on `stopped` without booting it.

Still untested: a real client doing a config-only sync, and a real **old** client against the
new server.

## One more measured fact, worth knowing before reading a config archive

The config files in the archive are **sparse** — on VikingOutlaws, AzuClock's is 12 lines
holding only the four keys the operator set, where the server's own copy is 185 lines. That is
the designed mechanism, not a truncation: BepInEx adopts the stored values when the plugin binds
them and rewrites the file with its own documentation on the player's machine.

It also means a probe that greps a config archive for an *unset* key's default will not find it,
and that looks exactly like a missing value. The discriminating check is an *overridden* key
whose value differs from the documented default — `Clock Font Size = 18` against a default of
24. Asserting "the unset key reads its default" reported a failure that did not exist.
