# RESEARCH — Mod config editor (2.55)

Status: **research only, nothing implemented.** Every claim about PhValheim below was read
out of the tree at commit `57c686a4` (2.54) and is cited by file:line. Every claim about
other managers / BepInEx is cited to a source at the bottom.

---

## 1. The override pipeline already exists. 2.55 is a UI and a store over it.

The world-update path in `container/engine/phvalheim` runs, in this order:

| # | step | file:line | what it does to `BepInEx/config` |
|---|------|-----------|----------------------------------|
| 1 | `purgeWorldModsConfigsPatchers` | `phvalheim:498,515` → `0-functions.sh:586-604` | `find BepInEx/config -mindepth 1 ! -name 'BepInEx.cfg' -delete`, on **both** trees |
| 2 | `downloadAndInstallTsModsForWorld` | `0-functions.sh:1034-1037` | extracts the zip's `config/*` → `BepInEx/config` — **only if the zip ships one** |
| 3 | `installCustomModsConfigsPatchers` | `phvalheim:563` → `0-functions.sh:1151-1197` | copies `custom_configs/*` → **both** trees |
| 4 | `packageClient` | `phvalheim:572` | zips the client staging tree |
| 5 | `InstallCustomConfigSecureFiles` | `phvalheim:585` → `0-functions.sh:1264-1278` | copies `custom_configs_secure/*` → **server tree only** |

So the two directories are not two features; they are **two destinations of one override
layer**: `custom_configs/` = "both trees", `custom_configs_secure/` = "server only". That is
the *same* axis as 2.53's per-mod destination flags (`world_mods` gained a dest column per
side, `dbUpdates/dbUpdate_2.53.sh:81`).

**Design consequence:** config scope must reuse the 2.53 destination concept, not invent a
second vocabulary. An operator should not have to learn that "secure" means "server" when the
mod row one column to the left already says Server / Client.

Also note 2.53 deliberately made the server-only guarantee *structural* rather than
ordering-dependent (`0-functions.sh:1186-1189`: "It used to be server-only by virtue of
running AFTER packageClient — an ordering nothing declared"). Whatever 2.55 does, the
"never reaches the client" property must stay a named tree, not a call order.

---

## 2. The hard problem: there is usually no default config to edit

Step 2 above only lands a file **if the mod zip ships `config/`**. Most BepInEx mods don't —
the `.cfg` is written at first boot by `Config.Bind()` [BepInEx-config]. Therefore:

- A mod that was just added to a world has **no config file at all** until the world has
  booted once with it. The editor cannot show a form for it.
- The purge (step 1) runs on **update**, not on every start — so a generated `.cfg` *does*
  survive ordinary restarts. That live post-boot file is the only reliable source of the
  default set and of the type metadata.
- The UI therefore needs a real third state: *not yet generated — start this world once*.
  Rendering "no file" as "all defaults, nothing overridden" is the
  [[phvalheim_unknown_is_not_uptodate]] failure again (shipped three times in one release).

**Open design question for Brian:** do we accept "boot once, then configure", or do we
pre-generate by booting the world headless after a mod add? The former is honest and cheap;
the latter is a materially bigger feature.

---

## 3. BepInEx `.cfg` is self-describing — a typed form UI needs no per-mod schema

The generated file carries its own metadata [BepInEx-config]:

```
[General]

## Whether the Config Handler patch should run on game launch.
# Setting type: Boolean
# Default value: true
Enabled = true

[Override: Entry point]
## A value other than Automatic will always be applied.
# Setting type: EntryPointOverrideMode
# Default value: Automatic
# Acceptable values: Automatic, Default, QModManager, Disabled
Mode = Automatic
```

`## …` = description, `# Setting type:` = widget, `# Default value:` = the diff baseline,
`# Acceptable values:` / `# Acceptable value range: From X to Y` = enum / slider bounds.
This is exactly what Gale renders from, and why Gale needs no mod-specific knowledge
[gale-features]. It means **PhValheim can ship a typed editor without a config registry**,
and can compute "modified from default" per key with no extra storage.

Caveat worth copying from Gale: it re-serialises the whole file on save, which reorders
sections and adds newlines [gale-features]. We should prefer **surgical in-place rewrite**
(replace the value on the matched key's line, leave everything else byte-identical) so a
diff against the mod's own file stays readable and so unparsed exotica survives.

---

## 4. Store a sparse key-level override, NOT a full-file snapshot

This is the direct answer to directive #1 ("changes persist when a mod is updated"), and it is
the one decision that is expensive to reverse.

**Full-file snapshot** (what `custom_configs/` is today) pins the file at the *old* mod's
shape. On a mod update:
- a setting the new version **adds** is silently lost — the snapshot is copied over the
  freshly generated file at step 3 and the new key disappears;
- a setting the new version **removed or renamed** lingers as a dead key forever;
- the question "what did the operator actually change?" becomes unanswerable, so the UI can
  never show "3 settings modified" honestly.

**Sparse override** — rows of `(world_id, mod_id, file, section, key) → value` — re-applies
key-by-key onto whatever the new version generates. New keys arrive at their new defaults,
dead keys can be *shown* as dead instead of silently re-injected, and "modified from default"
is a real computation.

r2modman and Gale get away with full-file editing because they edit the live tree and nothing
ever purges it. **PhValheim purges on every update (step 1), so for us the diff model is a
requirement, not a refinement.**

### 4a. No backup captures the database — which settles the store question

`grep -rl 'mysqldump\|mariadb-dump' container/` matches **nothing**. `worldBackup:225` tars the
world dir, excluding only `./client` and `./<world>.zip`. So `world_mods` — the mod *selection*
itself — is already DB-only and is in no backup.

That decides it: **an override must not be more durable than the selection it modifies.** In
files, a tar restored onto a fresh DB yields override files for mods the world no longer
claims. In the DB, overrides sit at exactly the `world_mods` tier they hang off.

Note the tar still carries the *rendered* configs either way, because it includes `game/`.

### 4b. Materialise into the destination trees, NOT back into `custom_configs/`

An earlier draft of this document recommended materialising into `custom_configs/` +
`custom_configs_secure/` "so the engine seam does not change". That is wrong, and the reason
is worth stating: those two directories exist **only to encode a destination** —
`custom_configs/` means both trees, `custom_configs_secure/` means server only (§1). Once
destination is a column, the directories are a redundant indirection, and making them a render
target creates **two writers for one file** with no gain: an operator hand-edit via
`fileBrowser.php` would lose silently on the next update.

Write the materialised `.cfg` straight into `game/BepInEx/config` and, for both-trees
overrides, the client staging root — at step 3's position in the sequence. One writer.

See §4c for what the directories are still needed for, which is not this.

---

### 4c. What the two directories are still for

Measured, not assumed:

**`custom_configs_secure/` is genuinely deprecable.** It has exactly **one** reader in the
whole tree — `InstallCustomConfigSecureFiles()` (`0-functions.sh:1271`). One reader, one job
("server tree only"), and that job becomes a `server_only` flag on the override row. After
migration it is dead. The *guarantee* it carried must survive as code that names the server
tree — 2.53 already made that structural (`0-functions.sh:1186-1189`); do not regress it to a
call-ordering dependency.

**`custom_configs/` cannot be retired.** The editor is keyed on `world_mods`, and three live
classes of config file have no `world_mods` row to hang an override on:

1. **Engine-installed non-catalogue plugins.** `ZeroBandwidth-CustomSeed/CustomSeed.dll` is
   copied in from `/opt/stateless` (`0-functions.sh:469-476`) and its config is written by the
   engine to `custom_configs/ZeroBandwidth.CustomSeed.cfg` (`0-functions.sh:480-481`).
   `PhValheim-TickMonitor` is installed the same way (`installSystemPlugins()`). Neither is in
   the catalogue, so neither has a row.
2. **Operator-dropped DLLs.** `custom_plugins/` and `custom_patchers/` are not going away —
   2.53's own comment says why: "A file in `custom_plugins/` has no catalogue identity, so the
   only honest default is the behaviour it has always had" (`0-functions.sh:1183-1184`). If an
   operator can drop a DLL the editor cannot see, they need somewhere to put its config.
3. **Imported worlds.** `importWorld.sh:143` dumps an imported world's whole config tree there
   (see §5b).

So `custom_configs/` survives as the **escape hatch for files without catalogue identity** —
no longer the override mechanism, and no longer a render target. The seed cfg should become an
engine-owned, operator-locked override row; everything in class 1 and 2 keeps the directory.

---

## 5. Migration (directive #3) — the two traps that will bite

Directive #3 asks for a migration path for worlds with non-default files in those
directories. Two findings make a naive "import every file as an override" migration actively
destructive:

### 5a. `custom_configs/` is not purely operator state — the engine writes to it

`0-functions.sh:480-481` writes `ZeroBandwidth.CustomSeed.cfg` (`custom_seed = $worldSeed`)
into `custom_configs/` itself. A migration that imports it as an operator override hands the
operator a knob that fights the engine on the next world update. It must be excluded by name,
and the editor must not expose it.

### 5b. Imported worlds have a `custom_configs/` full of *defaults*, not edits

`container/games/valheim/scripts/importWorld.sh:143` copies an imported world's **entire**
`BepInEx/config/*` into `custom_configs/` (deleting only `BepInEx.cfg` and
`quick_connect_servers.cfg`, lines 144-149). So every imported world looks, on disk, exactly
like an operator who hand-copied hundreds of files — when in fact almost all of them are
untouched defaults.

Import those as overrides and **those worlds are frozen at their import-time defaults
permanently**: every future mod update would have its new defaults overwritten by a row the
operator never set and cannot reason about. This is the [[feedback_impossible_row_refutes_the_rule]]
shape — the rule "a file in `custom_configs/` is an operator edit" has a large, real
counterexample class sitting in production.

**Mitigation to design around:** import only keys that *differ* from a knowable default
(the mod's shipped `config/` entry, or the `# Default value:` comment in the file itself —
which is present in the very file we are reading, and is the better baseline because it needs
no network). Where no baseline is knowable, import as a **flagged legacy passthrough**
(full-file, operator-review-required) rather than silently as per-key overrides. Before
writing any of this, **`SELECT`/`find` across a real world set and count how many files are
byte-identical to their `# Default value:` reconstruction** — that number decides whether 5b
is a footnote or the whole migration.

---

## 6. Server vs client is not only a file-location question

Many Valheim mods make the **server's** config authoritative at runtime:

- **ServerSync** (blaxxun) — clients register a config-sync RPC; the lock check runs
  server-side, so the server enforces locked config [serversync-issue].
- **ConditionalConfigSync** (shudnal) — keeps separate local and active-server values, adds
  `AlwaysServerControlled` / `Conditional` / `AlwaysClientControlled` and a server-side
  `SyncPolicy.cfg` that overrides exact settings and whole sections [ccs].
- **Jotunn** has its own persistent/synced config story [jotunn-config].

Consequence: for a ServerSync-locked mod, a "Client" config edit is cosmetic — the server
overwrites it on join. 2.55 should not *promise* a client-side value will take effect. This is
a labelling/notice concern, not a feature; but silently offering a Client toggle that does
nothing for a large class of mods is the kind of thing that generates an issue.

---

## 7. Option A vs Option B — recommend A as the entry point *into* B

**A (icon in the mod row, right of "Installs on")** is the correct affordance: the mod row
already carries identity, pin, dep status and destination
(`edit_world.php:866,902,967`; same in `new_world.php:1050,1087,1266`).

But do **not** build the editor inline in that table. That picker is already a dense state
machine — `checkedSet` as source of truth, two DataTables, `redrawInPlace()` to stop paging
resetting on every click, and a separate scroll restore inside `requestAnimationFrame`. Every
one of those was a bug fix. Adding per-row expanding forms re-opens all of them.

**Recommendation:** the icon opens a dedicated editor (`world_configs.php?world=X&mod=Y`).
The same page with the `mod` filter dropped **is** Option B — "all configs for this world" —
for free. One page, two entry points. The icon needs three visual states: *not generated
yet*, *defaults*, *N modified*.

Also: `fileBrowser.php` already exists and is already linked from the admin nav
(`index.php:261`). It is today's way of touching these directories and is the escape hatch;
it must keep working, and 2.55 should decide what it shows for a file the editor now owns.

---

## 8. Known traps to carry into implementation

- **The loader's own `BepInEx.cfg` is not a mod config.** The purge exempts it by name
  (`0-functions.sh:601-604`) and `ensureBepInExLoaderConfig()` restores it; 2.49 shipped a
  world-log + client-console blackout by sweeping it. The editor must exclude it from the
  mod-config list.
- **`worlds.modsViewer` is a cached snapshot** ([[v2.44-release]]). Do not read config state
  from it.
- **A migration without the +x bit runs as nothing** ([[phvalheim_migration_needs_exec_bit]]).
- **`:rc` is a live channel** — real operators run it; batch the pushes
  ([[feedback_rc_is_a_live_channel]]).
- **Every release needs a `whatsnew.php` entry** or `check-whatsnew.sh` fails
  ([[v2.42-release]]).
- Issue **#84** (admin UI + docs for `custom_configs/`) is the ticket this feature closes, and
  Brian has already ruled that `custom_configs/` is a **feature, not a bug** — 2.55 should be
  framed as "give it an interface", never as "fix it" ([[phvalheim_config_ownership]]).

---

## 9. Open questions for Brian

1. **Scope collision:** `dev_tools/RESEARCH-2.55-nexus-mods-catalogue.md` is also labelled
   2.55. Is the config editor *instead of* the Nexus catalogue, or alongside it?
2. **Boot-once or pre-generate?** (§2) — this is the biggest scope fork in the feature.
3. **Sparse-override store confirmed?** (§4) — if yes, 2.55 needs a new table and a
   `dbUpdate_2.55.sh`.
4. **Do `custom_configs*/` stay as documented escape hatches** (editor materialises into them)
   or get fully retired with the editor owning a separate path? §4 recommends the former.

---

## Sources

- [BepInEx-config] [Configuration — BepInEx docs](https://docs.bepinex.dev/articles/user_guide/configuration.html) ·
  [Reading and writing configuration files](https://docs.bepinex.dev/articles/dev_guide/plugin_tutorial/4_configuration.html) ·
  [Class ConfigFile](https://docs.bepinex.dev/master/api/BepInEx.Configuration.ConfigFile.html)
- [gale-features] [Features — Kesomannen/gale wiki](https://github.com/Kesomannen/gale/wiki/Features) ·
  [Making mods for Gale](https://github.com/Kesomannen/gale/wiki/Making-mods-for-Gale)
- [r2modman] [R2ModMan features](https://www.r2modman.co/features.html)
- [serversync-issue] [blaxxun-boop/ServerSync issue #12](https://github.com/blaxxun-boop/ServerSync/issues/12)
- [ccs] [shudnal/ConditionalConfigSync](https://github.com/shudnal/ConditionalConfigSync)
- [jotunn-config] [Persistent & Synced Configurations — Jotunn](https://valheim-modding.github.io/Jotunn/tutorials/config.html)
- [configmanager] [BepInEx.ConfigurationManager](https://github.com/BepInEx/BepInEx.ConfigurationManager)
