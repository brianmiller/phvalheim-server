# RESEARCH — Nexus Mods as a third catalogue source

Status: **research only, nothing implemented.** Every number below was measured against the
live Nexus API on 2026-10-04, not taken from documentation.

Read `DESIGN-2.43-multisource-mods.md` first. That document's central claim is that
`source` is *data and not code* — Thunderstore and Hexium are "one adapter with two URLs"
because Hexium's `/api/v1/package/` returns the same keys, the same nesting and the same
newest-first `versions[]` ordering as Thunderstore's.

**Nexus breaks that assumption at every layer.** It is not a third URL in `SOURCES`. The
headline finding is that the *metadata* integration is very achievable, and the
*download/install* integration is blocked by Nexus policy rather than by engineering.

---

## 1. The blocker: PhValheim cannot download a Nexus mod server-side

This is the finding that decides the whole feature, so it goes first.

Thunderstore and Hexium both publish an unauthenticated, permanent `download_url` per
version. That single fact is what makes `mod_versions.download_url` and
`downloadAndInstallTsModsForWorld()` work. Nexus has no equivalent.

### Measured

The CDN refuses an unsigned request:

```
GET https://cf-files.nexusmods.com/cdn/3667/92/Equipment%20and%20Quick%20Slots-92-3-0-0-....zip
→ 403  "Missing parameters"
```

`ModFile.uri` in the GraphQL API is **a bare filename, not a URL** —
`"Equipment and Quick Slots-92-1-0-0-1614061097.zip"`. There is nothing to template.

The only link-minting endpoint is v1 `download_link.json`, and its own spec states the rule:

> **403** — "You don't have permission to get download links from the API without visiting
> nexusmods.com — **this is for premium users only**."
>
> "NOTE: Non-premium members must provide the `key` and `expiry` from the `.nxm` link
> provided by the website. … **This ensures that all non-premium members must access the
> website to download through the API.**"

So there are exactly two paths, and both fail for us:

| Path | Requirement | Why it fails for PhValheim |
|---|---|---|
| Premium | Operator's **premium** personal API key stored on the server, used unattended by cron and by world rebuilds | Violates the AUP (below); also silently excludes every non-premium operator |
| Free | A human clicks Download on nexusmods.com, producing an `nxm://` link carrying `key`+`expires` | There is no human in a world rebuild. Links expire. Does not survive a modpack rebuild |

### The Acceptable Use Policy names our architecture directly

From the API AUP (last updated 2020-12-01), under **Unacceptable Usage**:

- *"Storing user API keys on your own server and/or using them without the action being
  initiated by the user."*
- *"Using personal API keys for a public-facing application."*
- *"Fetching data en-masse with the intent to rehost this information on your own service
  (i.e. scraping)."*

PhValheim does all three structurally, not incidentally:

1. The key would live in `settings`, exactly where `thunderstoreApiKey` and `hexiumApiKey`
   live — *stored on our server*.
2. `cron.d/modSync` and `syncModCatalogue` at engine start are *not user-initiated*.
3. `packageClient()` zips the server's `./BepInEx` tree **whole** and serves it to every
   player who joins the world. That is redistribution of Nexus-hosted files to third
   parties — the thing the premium gate and the nxm flow exist to prevent. See
   `phvalheim_client_per_world_tree` / the 2.49 notes: the server's tree *is* the client's
   payload, so this is not avoidable by changing the server side alone.

Point 3 is worth stating plainly: even with a premium key and a willing operator, shipping
Nexus mod files inside the client payload is the part most likely to draw a complaint, and
it is load-bearing in PhValheim's design.

**There is no registered-application escape hatch for this.** Registration (emailing
support@nexusmods.com with a test build) raises your rate limit and gets you an app key; it
does not grant the right to mint download links for arbitrary users or to rehost files.

### Consequence

A Nexus integration that *installs* mods needs Nexus's written permission. Before any code
is written, the question for Nexus support is narrow and worth asking directly:

> PhValheim is a self-hosted, open-source Valheim dedicated-server manager. Each operator
> runs their own instance with their own API key. The server downloads a mod once and
> redistributes it to the players joining that specific server, because Valheim requires
> client and server mods to match. Is there a licensing path for this?

Everything in §2–§5 is still worth building if the answer is no, because a
**metadata-only, link-out catalogue** needs none of the above (see §6, Option A).

---

## 2. There are three Nexus APIs, and the one you want is undocumented

`https://api-docs.nexusmods.com/` documents **v3 only**, and v3 is almost entirely a
*mod-authoring* API — uploads, collections, changelogs, dependency editing. Of its 32 paths,
the only read endpoints useful to a catalogue are `getMod`, `getModFiles`,
`getTrendingMods` and `getGameDlcs`. **There is no mod search or mod list in v3.** You
cannot enumerate a game's catalogue with the documented API.

| API | Base | Auth | Use to us |
|---|---|---|---|
| v1 (legacy) | `api.nexusmods.com/v1` | API key **required** (401 without) | `download_link.json`; `latest_added`/`updated`; per-mod detail. 1 request per mod — unusable for a 2,983-mod sweep |
| v2 GraphQL | `api.nexusmods.com/v2/graphql` | **none needed** | **The only way to enumerate a catalogue.** Undocumented but introspectable |
| v3 REST | `api.nexusmods.com/v3` | key for writes; reads worked unauthenticated | Authoring. `trending-mods` worked with no key |

The whole metadata integration therefore rests on **v2 GraphQL, which Nexus does not
document and makes no stability promise about.** v3's published deprecation policy
(90 days, stable endpoints) does not cover it. That is a real maintenance risk to accept
explicitly, not a detail: a schema change there breaks catalogue sync with no notice.
Introspection is enabled, so the schema can at least be diffed automatically — worth a
guard if this ships.

### Hard limits measured on v2 GraphQL

- **Query complexity cap 10,000.** Exceeding it returns HTTP **200** with
  `{"errors":[{"extensions":{"code":"QUERY_TOO_COMPLEX","complexity":12720}}]}`.
  This bit during this research: the obvious `mods{…modRequirements{…}}` query at
  `count:80` is complexity 12,720 and returned **zero rows while looking like a success**.
  A sync loop that checks only `response.status === 200` would log a clean no-change tick
  forever. `count:60` is the measured ceiling with requirements nested.
- **Page size caps at 80** regardless of what you ask for (`count:100` → 80 nodes,
  `count:200` → 80 nodes). No error, it just silently returns fewer.
- **`offset` beyond the result set returns `nodes: []`**, not an error.
- Full Valheim catalogue = **2,983 mods in 50 HTTP requests** at `count:60`, concurrency 3.
  Cheap. Comparable to the existing sync's cost.
- Rate limits (keyed APIs): **20,000 requests / 24h**, then **500/hour**; daily quota
  resets 00:00 GMT. Remaining counts come back in response headers. Not a constraint for
  metadata; it *is* a constraint if you ever go per-mod on v1.

Valheim identifiers: `game_domain_name = "valheim"`, `gameId = 3667`.

---

## 3. The data model does not map onto `(source, owner, name)`

2.43's identity is `(source, owner, name)`, case-sensitive, with dependency strings matched
by **longest known `owner-name` prefix**. Nexus supplies none of these cleanly.

Measured across all 2,983 Valheim mods:

| Property | Measurement | Consequence |
|---|---|---|
| `uploader.name` / `name` unique? | **2,983 distinct of 2,983 — zero collisions** | Identity *would* hold… |
| …but names contain spaces | **2,314 (77.6%)** | `"randyknapp/Equipment and Quick Slots"` is a display title, not a slug |
| Names with chars illegal in a TS-style `owner-name` | **686** | e.g. `willybach/Willybach's HD Valheim 2.0` |
| `author` ≠ `uploader.name` | **1,230 (41%)** | Two plausible "owner" fields that disagree. `uploader.name` is the stable one; `author` is free text |
| Version not `N.N…` | **535 (17.9%)** | `"Deprecated"`, `"1"`, `"004"`, `"v2.1.3"`, `"Beta-1.2.1"`, `"19"` |

The version finding is the sharp one. `mod_versions.version` drives pinning, "newest
version", and the update-detection that `worlds.modsViewer` displays. A sixth of the Nexus
catalogue has **non-monotonic, non-comparable version strings**, and `"Deprecated"` is a
literal version value. Feeding those into the existing newest-wins comparison produces
wrong answers quietly — the exact shape of
`phvalheim_unknown_is_not_uptodate`, which shipped three times in one release.

**Recommendation:** do not try to synthesise `(owner, name)` for Nexus. Key Nexus rows on
`(nexus, game_domain, mod_id)` — `mod_id` is a stable integer, and `modFiles.fileId` is a
stable integer per version. Keep `uploader.name`/`name` as display fields only. This means
`mods` needs a nullable `source_mod_id` column and the prefix-matching dependency resolver
needs a second, source-aware path. The collation requirement
(`utf8mb4_0900_as_cs`) still applies to any new column joined against the existing ones.

---

## 4. Dependencies are display text, and they point at Thunderstore

Thunderstore gives machine-readable `Owner-Name-Version` dependency strings. 2.43 resolves
1,371 of Hexium's 1,372 distinct strings off the back of that.

Nexus's `modRequirements.nexusRequirements` is a **display list for the mod page**.
Measured across all 2,983 mods — 1,755 (58.8%) have at least one requirement,
**2,648 edges total**:

| Edge type | Count | Usable? |
|---|---|---|
| `externalRequirement: false` — real Nexus `modId` | **1,463 (55%)** | Yes, resolvable within Nexus |
| `externalRequirement: true` — `modId: "0"`, URL only | **1,185 (45%)** | Only by parsing the URL |

Where those 1,185 external edges point:

```
valheim.thunderstore.io   754        github.com      73
thunderstore.io           226        reshade.me      13
www.nexusmods.com          89        docs.bepinex.dev 10
old.thunderstore.io         4        valheim.hexium.gg 3
```

**985 of 1,185 external edges (83%) point back at Thunderstore.** Epic Loot's three
requirements are all Thunderstore URLs — `denikson/BepInExPack_Valheim`,
`ValheimModding/JsonDotNET`, `ValheimModding/Jotunn` — each with `modId: "0"` and no
version constraint anywhere.

This is the one genuinely encouraging finding. 2.43's dependency resolution is **already
deliberately cross-source** (576 Hexium deps resolve only against Thunderstore rows), so
the machinery exists. What is missing is a **URL → `(source, owner, name)` parser**:
`https://valheim.thunderstore.io/package/denikson/BepInExPack_Valheim/` →
`(thunderstore, denikson, BepInExPack_Valheim)`. That is a small, well-defined piece of
work against four URL shapes, and it would resolve ~985 edges into rows PhValheim already
has.

Two caveats that must not be glossed:
- **No version constraints at all.** Nexus requirements carry no version. Every resolved
  edge is "latest", so `mod_deps` would need to record that the constraint is unknown
  rather than inventing one.
- **The 89 `www.nexusmods.com` edges still need URL parsing** even though they are Nexus —
  `modId` is `"0"`, so the internal path does not cover them.

---

## 5. The archive format is the hardest engineering problem

Thunderstore mandates a package layout: `manifest.json` at the root, plugins under
`plugins/`. `downloadAndInstallTsModsForWorld()` unzips straight into `game/BepInEx` and
that convention is what makes it land correctly.

**Nexus mandates nothing.** Sampled the 300 most-downloaded Valheim mods —
**4,167 archives**, classified by their real file listings via `modFileContents`:

| Layout | Archives | Share |
|---|---|---|
| **Root-level `.dll`, no folder** | 2,434 | **58.4%** |
| Thunderstore-style (`manifest.json`) | 556 | 13.3% |
| `.dll` nested in some other folder | 484 | 11.6% |
| **No `.dll` at all** (ini/assets/presets) | 386 | 9.3% |
| `plugins/` somewhere | 217 | 5.2% |
| `BepInEx/plugins/` prefix | 90 | 2.2% |

Per mod: **212 of the top 300 (70.7%) have no `manifest.json` in any archive.**

Concretely — Jotunn, the single most important Valheim mod library, ships on Nexus as a
bare `Jotunn.dll` at the archive root. Unzipped into `game/BepInEx` by the current code
path it lands at `game/BepInEx/Jotunn.dll`, where **BepInEx will never load it**. It needs
`game/BepInEx/plugins/Jotunn.dll`. The install would complete, report success, and the mod
would simply not be there.

So a Nexus installer needs a **layout-inference step** the other two sources have never
needed: inspect the archive, decide where each file belongs, and place it. The pathological
cases found in the sample are not rare edge cases:

- `Bigger Item Stack` ships **an entire BepInEx distribution** —
  `Valheim_Bepinex_5.4.5/BepInEx/core/…` plus `valheim_Data/Managed/…`. Unzipped naively
  this overwrites the engine-installed loader, which is the exact failure mode of 2.49
  (`phvalheim_v249_release`: one missing `BepInEx.cfg` silenced both the world log and the
  client console).
- `Valheim Unstripped DLLs` ships `unstripped_managed/*.dll` — not plugins at all; those
  belong in `valheim_Data/Managed`.
- ReShade presets (the 9.3% with no DLL) are **client-side only and meaningless on a
  dedicated server**. The catalogue would need to mark them unusable rather than offer
  them and silently install nothing.

`modFileContents` is genuinely useful here: it lets you classify an archive's layout
*before* downloading, so the picker can refuse what it cannot place. It needs both
`modId` and `gameId` in the filter — filtering on `modId` alone returns global results
across every game (474,704 rows, from X4 and Resident Evil) rather than erroring.

Also note `directDownloadEnabled` is **`false` for all 2,983 Valheim mods** (there is an
`updateModDirectDownloadEnabled` mutation, so it is an author opt-in). I could not
establish from the docs exactly which download paths that flag gates, so I am not resting
any conclusion on it — the premium/nxm rule in §1 is the documented blocker. But 0 of 2,983
is not an encouraging signal and should be clarified with Nexus if this is pursued.

For scale: the Valheim Nexus catalogue is ~52 GB of files in total. Mirroring is not on the
table anyway, per §1.

---

## 6. Options

### Option A — metadata-only, link-out catalogue *(recommended if Nexus says no)*
Sync Nexus metadata via v2 GraphQL. Show Nexus mods in the picker as **browsable but not
installable**, linking to the mod page. Resolve the ~985 Thunderstore-pointing dependency
edges so a Nexus mod page can say "its dependencies are available here".

- Needs no API key, no premium, no file downloads → **no AUP collision**, nothing from §1.
- Avoids §5 entirely.
- Honest about what it is. Does not make `world_mods` lie.
- Still needs §3's `source_mod_id` identity work and the §2 complexity-cap guard.
- Risk: a catalogue entry a user cannot install is a support question. Must be visibly
  distinct in the picker, not a disabled checkbox.

### Option B — full installable integration *(blocked)*
Requires, in order: written permission from Nexus covering server-side download **and**
client-payload redistribution; a per-operator premium key with the AUP's
unattended-use problem resolved; then the §5 layout-inference engine and the §3/§4 schema
work. Do not start the engineering before the permission.

### Option C — operator-supplied Nexus mods
Sidestep the API: let an operator drop a Nexus-downloaded zip into a per-world directory,
with the layout-inference logic from §5 applied to it. The operator performs the download
themselves, so the premium/nxm rule and the AUP are satisfied by construction. Overlaps
heavily with issue #84 and the `custom_configs/` design (which Brian has already called a
feature, not a bug — `phvalheim_config_ownership`). Probably the most honest way to support
Nexus content, and it is a different feature from "a third catalogue".

---

## 7. If this proceeds, verify these first

Each of these is a measurement, not a reading:

1. **Ask Nexus support** the §1 question. Everything in Option B waits on the answer.
2. Confirm what `directDownloadEnabled` gates, and why it is 0/2,983 for Valheim.
3. Confirm v2 GraphQL has no undocumented per-IP throttle at sync cadence — 50 requests
   succeeded at concurrency 3, but an 8-wide burst earlier returned zero rows for every
   page. I did not isolate whether that was the complexity cap or throttling, and the
   distinction matters for the sync loop's error handling.
4. Any sync adapter must treat a **200 with an `errors` array as a failure**. The
   complexity cap produces exactly that, and it is indistinguishable from a clean
   no-change tick to a status-code check. This is the `phvalheim_verify_chain_ungated`
   failure shape.
5. Decide the `"Deprecated"`-as-a-version question before writing schema, not after.

## Artifacts

Raw measurement data, regenerable from the queries above:

- `/tmp/valheim-nexus-mods.json` — all 2,983 Valheim mods with requirements
- `/tmp/nexus-layouts.json` — 4,167 archive file listings for the top 300 mods
- `/tmp/nexus-openapi.yaml` — the v3 spec
- `/tmp/nexus-gql-schema.json` — full v2 GraphQL introspection (354 types)
