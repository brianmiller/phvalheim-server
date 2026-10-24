# Hugin's Vibes — design (PhValheim 2.59)

*Status: DRAFT for the 2.59 cycle. Written 2026-10-24. This document is the brief every piece of
Vibes code is built from; when code and this document disagree, fix one of them the same day.*

---

## 0. The one-paragraph version

Hugin's Vibes is a new door at the top of the admin sidebar's **Tools** section. Behind it is a
workbench where the operator describes a Valheim mod in plain language, Hugin writes, builds and
packages it inside the PhValheim container, and the result lands in a **local mod catalogue**
("Hugin's Vibes" shows up as a third catalogue beside Thunderstore and Hexium) so any world can
pick it up exactly like a published mod. Every mod is a project with its own git repository and
its own remembered conversation, so the operator can leave, come back and "vibe the next
version". The compiler, git and the .NET toolchain are **not** in the server image: the first
launch downloads a **Build Pak** release asset from GitHub into the stateful volume, after the
operator has seen what it is, how big it is and how much disk they have. Vibes needs an AI
provider; if none is configured it says so and points at Server Settings → AI Helper.

---

## 1. Why it is shaped this way

| Constraint | Consequence |
|---|---|
| The server image went on a CVE diet in 2.58 (no git, no gh, no -dev packages). Adding a 450 MB .NET SDK and git to the image would undo that and cost every operator the download whether or not they ever vibe. | The toolchain is a **separate, opt-in download** (the Build Pak) into `/opt/stateful/vibes/`, which survives image upgrades. The image only gains text: PHP, bash, templates, knowledge. |
| Valheim's own assemblies (`assembly_valheim.dll`, `UnityEngine.*`) are copyrighted and cannot be redistributed in a GitHub release. | The Build Pak carries **no game files**. Install **harvests** them from a world's own dedicated-server install (`<world>/game/valheim_server_Data/Managed/`, 33 MB, present on every PhValheim world) and publicizes them locally. A server with no world yet cannot harvest; the UI says so and offers to create one or to download the dedicated server files via steamcmd (already in the image). |
| Hugin already has a provider-agnostic chat transport with negotiated tool capability (`includes/aiproviders.php` `aiChat()`, 2.45) and the hard-won rule "never hardcode a model id". | The coding harness **reuses `aiChat()`**. One transport, one provider registry, one set of API keys. See §9 for why we did not embed pi. |
| Operators run `:rc`; a bug here lands on strangers' servers. | Vibes can be **deleted** from its own settings, leaving nothing behind but what the operator chose to keep. Nothing in Vibes runs unless `settings.vibesInstalled = 1`. |
| It must stay maintainable across release cycles: the SDK, BepInEx, the publicizer, git and the templates all move. | Binaries live in the Build Pak with its own repo, CI and release cadence (`§8`). Text that must track the server (templates, knowledge, the agent's rules) ships **in the server image** and versions with it. A `pakFormat` integer couples the two. |

---

## 2. Naming

| Thing | Name in the UI | Name in code |
|---|---|---|
| The feature | **Hugin's Vibes** | `vibes` (files), `hv_` (tables), `vibes*` (settings columns) |
| One mod being worked on | **a Vibe** (noun) / **vibing** | `project` |
| The downloaded toolchain | **Build Pak** | `buildpak` |
| The local catalogue source | **Hugin's Vibes** (pill) | `source = 'vibes'` |
| One agent turn | Hugin **forging** | `turn` job |
| The stored per-project conversation | **Session** | `session` |

---

## 3. Storage

Everything lives under `/opt/stateful/vibes/` (the data volume), owned by `phvalheim:phvalheim`,
created by the engine at boot (`container/engine/phvalheim`, root) whether or not Vibes is
installed — an empty directory costs nothing and means the PHP side never has to be root.

```
/opt/stateful/vibes/
  buildpak/
    <pakVersion>/              one extracted Build Pak (see §8 for its layout)
    current -> <pakVersion>    symlink; everything else goes through `current`
  libs/                        harvested + publicized game assemblies (§6.2)
    manifest.json              { gameVersion, sourceWorld, harvestedAt, files:{name:sha256} }
    decompiled/                optional: ilspycmd project decompile of assembly_valheim (§6.2)
  projects/<slug>/             one git repository per Vibe (§5)
  assets/                      operator-uploaded files (icons, textures, audio, reference zips)
  catalog/                     published packages: <Owner>-<Name>-<version>.zip + index.json
  downloads/                   in-flight Build Pak download; cleaned on success/failure
  logs/
    vibes.log                  the runner's own log (phvlog format, source 'vibes')
    jobs/<jobId>.jsonl         one event stream per job (§7.3)
```

Disk policy: before the Build Pak download the installer requires
`free >= 2 × pakSize + 1 GiB` and shows the operator both numbers. Builds write into
`projects/<slug>/bin|obj` which `vibes gc` can prune; the Package tab shows per-project size.

---

## 4. Database (`container/engine/dbUpdates/dbUpdate_2.59.sh`)

Object-by-object idempotent like every migration since 2.40.

```sql
-- settings (one row): three columns, not thirty. Vibes has many knobs and they are only read
-- by Vibes, so they live in one JSON document with a documented schema (§4.1).
ALTER TABLE settings ADD COLUMN vibesInstalled      TINYINT      NOT NULL DEFAULT 0;
ALTER TABLE settings ADD COLUMN vibesBuildpakVersion VARCHAR(32)     NULL;
ALTER TABLE settings ADD COLUMN vibesSettings        JSON            NULL;
ALTER TABLE settings ADD COLUMN vibesNoticeShown     TINYINT      NOT NULL DEFAULT 0; -- first-launch modal

CREATE TABLE hv_projects (
  id            INT UNSIGNED NOT NULL AUTO_INCREMENT,
  slug          VARCHAR(64)  NOT NULL,            -- [a-z0-9-], directory name
  name          VARCHAR(96)  COLLATE utf8mb4_0900_as_cs NOT NULL,  -- mods.name (PascalCase, no spaces)
  namespace     VARCHAR(80)  COLLATE utf8mb4_0900_as_cs NOT NULL,  -- mods.owner (Thunderstore "team")
  description   VARCHAR(250) NOT NULL DEFAULT '',
  version       VARCHAR(32)  NOT NULL DEFAULT '0.1.0',             -- manifest.json version_number
  template      VARCHAR(32)  NOT NULL DEFAULT 'basic-plugin',
  status        VARCHAR(16)  NOT NULL DEFAULT 'draft',             -- draft|built|published|broken
  provider_id   INT UNSIGNED     NULL,            -- NULL = server default provider
  model         VARCHAR(190) NOT NULL DEFAULT '', -- '' = provider's pinned model (verbatim, never validated)
  last_build_ok TINYINT          NULL,            -- NULL = never built
  last_build_at DATETIME         NULL,
  published_version VARCHAR(32)  NULL,            -- newest version in the local catalogue
  github_remote VARCHAR(255) NOT NULL DEFAULT '', -- e.g. owner/repo ; '' = not linked
  gitlab_remote VARCHAR(255) NOT NULL DEFAULT '', -- e.g. group/sub/repo ; '' = not linked
  created       DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated       DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id), UNIQUE KEY uk_slug (slug), UNIQUE KEY uk_ident (namespace, name)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE hv_sessions (
  id          INT UNSIGNED NOT NULL AUTO_INCREMENT,
  project_id  INT UNSIGNED NOT NULL,
  title       VARCHAR(120) NOT NULL DEFAULT '',   -- first user message, trimmed
  status      VARCHAR(16)  NOT NULL DEFAULT 'idle',  -- idle|running|error
  turns       INT          NOT NULL DEFAULT 0,
  summary     MEDIUMTEXT       NULL,            -- compaction note (§7.5); NULL until compacted
  tokens_in   BIGINT       NOT NULL DEFAULT 0,
  tokens_out  BIGINT       NOT NULL DEFAULT 0,
  started     DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated     DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id), KEY idx_project (project_id, updated)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
-- the transcript itself is the file projects/<slug>/.vibes/sessions/<id>.jsonl (§7.3)

CREATE TABLE hv_jobs (
  id          INT UNSIGNED NOT NULL AUTO_INCREMENT,
  kind        VARCHAR(24)  NOT NULL,   -- install|uninstall|harvest|build|package|publish|unpublish|turn|push|pak-update|gc
  project_id  INT UNSIGNED     NULL,
  session_id  INT UNSIGNED     NULL,
  status      VARCHAR(16)  NOT NULL DEFAULT 'queued', -- queued|running|done|failed|cancelled
  step        VARCHAR(64)  NOT NULL DEFAULT '',       -- human step label, e.g. "Extracting .NET SDK"
  progress    TINYINT      NOT NULL DEFAULT 0,        -- 0..100, -1 = indeterminate
  pid         INT              NULL,
  args        JSON             NULL,
  result      JSON             NULL,
  error       VARCHAR(500)     NULL,
  started     DATETIME         NULL,
  finished    DATETIME         NULL,
  created     DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id), KEY idx_open (status, id), KEY idx_project (project_id, id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
```

The local catalogue reuses the 2.43 tables with `mods.source = 'vibes'` (§6.4). No new columns
there. `modSync.py` prunes by key diff **per source** and never iterates `'vibes'`, so it cannot
delete our rows; a full dependency rebuild recomputes edges from every `mod_versions.deps`, ours
included, because we store deps in the Thunderstore string form `Owner-Name-1.2.3`. Test
`dev_tools/test-vibes-catalog-survives-sync.sh` proves both.

### 4.1 `settings.vibesSettings` schema

```jsonc
{
  "schema": 1,
  "author":      { "namespace": "MyServer", "display": "Brian", "url": "" },
  "defaults":    { "template": "basic-plugin", "provider_id": null, "model": "", "configuration": "Release" },
  "agent":       { "max_rounds": 40, "turn_timeout_s": 900, "unrestricted_shell": false,
                   "context_budget_tokens": 60000, "auto_build": true, "auto_commit": true },
  "libs":        { "source_world": "", "decompile": true, "publicize": true },
  "buildpak":    { "repo": "brianmiller/phvalheim-vibes-buildpak", "channel": "stable",
                   "auto_check": true, "last_check": null, "available": null },
  "assets":      { "max_upload_mb": 64 },
  "github":      { "enabled": false, "token": "", "owner": "", "visibility": "private", "api": "https://api.github.com" },
  "gitlab":      { "enabled": false, "token": "", "host": "https://gitlab.com", "group": "", "visibility": "private" },
  "advanced":    { "keep_transcripts_days": 0, "log_level": "info", "nuget_online": true,
                   "dotnet_verbosity": "minimal", "allow_prerelease_pak": false }
}
```

`vibesSettingsGet($pdo)` returns this merged over the defaults (missing keys filled), so a
newer server never trips on an older document. Tokens are stored as the AI keys are (plaintext in
the DB, admin UI only, never logged, masked in every API response as `"••••"` + last 4).

---

## 5. A project on disk

```
projects/<slug>/
  .git/
  .gitignore                     bin/ obj/ dist/ .vibes/sessions/ .vibes/builds/
  <Name>.csproj                  SDK-style, net472, references via $(VibesLibs) (from Directory.Build.props)
  Directory.Build.props          GENERATED, points VibesLibs/VibesPak at /opt/stateful/vibes/...; in .gitignore? NO —
                                 committed, but every path is a property so a clone builds elsewhere by overriding it
  src/Plugin.cs                  [BepInPlugin(GUID, Name, Version)], Harmony instance, ConfigEntry examples
  src/...                        whatever Hugin writes
  manifest.json                  Thunderstore manifest: name, version_number, website_url, description, dependencies[]
  README.md                      shown on the catalogue card and shipped in the package
  CHANGELOG.md
  icon.png                       256×256 (Thunderstore rule); template ships a generated placeholder
  .vibes/
    project.json                 { slug, name, namespace, created, template, pakFormat, serverVersion }
    notes.md                     Hugin's own durable memory for THIS project (decisions, open bugs, TODO).
                                 Read into every turn; written via the `note` tool. Committed.
    sessions/<id>.jsonl          transcripts (§7.3). Not committed.
    builds/<n>.log               raw dotnet output. Not committed.
  dist/                          <Owner>-<Name>-<ver>.zip from `package`. Not committed.
```

GUID convention: `<namespace>.<Name>` lower-cased (`myserver.nomovementpenalty`); the agent is
told the GUID must never change once published.

---

## 6. The runner: `container/engine/tools/vibes/`

All long work happens in a **detached PHP CLI process**, never inside php-fpm:
`vibesAPI.php` inserts an `hv_jobs` row and does
`exec("setsid nohup php /opt/stateless/engine/tools/vibes/vibes.php job <id> >> /opt/stateful/vibes/logs/vibes.log 2>&1 &")`
exactly as `updateChecker.py` is launched today. php-fpm runs as `phvalheim`, so the job does too;
nothing in Vibes needs root after boot.

```
tools/vibes/
  vibes.php            entry point: `job <id>` | `install` | `uninstall` | `harvest` | `build <slug>` |
                       `package <slug>` | `publish <slug>` | `turn <session> <msg-file>` | `push <slug> <remote>` |
                       `gc` | `status` ; every subcommand is also a job kind
  lib/env.php          paths (§3), pak env (PATH, DOTNET_ROOT, DOTNET_CLI_HOME, NUGET_PACKAGES, GIT_EXEC_PATH,
                       DOTNET_SYSTEM_GLOBALIZATION_INVARIANT=0 since libicu70 is in the image, HOME=/opt/stateful/vibes)
  lib/jobs.php         hvJobStart/Step/Progress/Finish, event emit (§7.3), cancel check (pid + status)
  lib/pak.php          download (curl --fail -L, resume, sha256 verify against the release's .sha256),
                       extract, `current` symlink swap, manifest compatibility (pakFormat), cleanup
  lib/libs.php         harvest from a world (§6.2), publicize, decompile, manifest
  lib/project.php      create from template, csproj/manifest read+write, version bump, tree listing
  lib/git.php          init/add/commit/log/diff/tag/remote/push with the pak's git; author from settings
  lib/build.php        dotnet build, diagnostic parser (file:line:col: error CSxxxx: text), warnings count
  lib/package.php      Thunderstore-layout zip (manifest.json README.md CHANGELOG.md icon.png plugins/<Name>.dll)
  lib/catalog.php      publish/unpublish into mods/mod_versions/mod_deps (§6.4) + catalog/index.json
  lib/agent.php        the Forge loop (§7)
  lib/tools.php        the agent's tool implementations (§7.2)
  lib/scm.php          GitHub + GitLab REST: create repo if missing, push with token, report URL
  templates/<name>/    project templates (text, versioned with the server)
  knowledge/*.md       what Hugin knows about Valheim modding (§7.4)
```

### 6.1 Install (job `install`)

1. Preconditions, each a distinct error the UI shows verbatim: an enabled AI provider with a
   model; free disk ≥ 2 × pak size + 1 GiB; outbound HTTPS to github.com.
2. Resolve the release: `GET https://api.github.com/repos/<repo>/releases` (reuse the pattern and
   60/h caution in `includes/git.php`), pick the newest non-draft, non-prerelease (prerelease
   allowed if `advanced.allow_prerelease_pak`) whose `manifest.json` asset declares a `pakFormat`
   this server supports (`VIBES_PAK_FORMAT` in `lib/env.php`, currently `1`) and whose `base`
   matches the image's distro codename (`jammy` now, `noble` later). Step: "Finding a Build Pak".
3. Download `phvalheim-vibes-buildpak-<ver>-linux-x64.tar.gz` to `downloads/` with progress
   events every 2% (curl `--progress-bar` parsed, or PHP curl `CURLOPT_PROGRESSFUNCTION`). Verify
   sha256 against the `.sha256` asset. Step: "Downloading Build Pak (212 MB)".
4. Extract to `buildpak/<ver>.tmp`, run the pak's own `selftest.sh` (`dotnet --version`,
   `git --version`, `ilspycmd --version`, publicizer present), rename to `buildpak/<ver>`, swap
   `current`. Step: "Unpacking", "Checking the toolchain".
5. Harvest (§6.2) unless no world has server files; then this step is skipped and the UI shows
   the "no game files yet" card with its two remedies.
6. Smoke build: create a throwaway project from `basic-plugin` in `downloads/smoke`, `dotnet
   build` it offline against the pak's NuGet cache. A green smoke build is the only honest
   "installed". Step: "Compiling a test mod".
7. `settings.vibesInstalled = 1`, `vibesBuildpakVersion`, `phvEvent('notice', "Hugin's Vibes
   installed (Build Pak x.y.z)")`, result `{pak, gameVersion, smoke: true}`.

Every step is resumable: re-running `install` after a failure skips finished steps (pak dir
present and self-tested → no re-download).

### 6.2 Harvest (job `harvest`)

Source world: `libs.source_world` if set and present, else the world with the newest
`game/valheim_server_Data/Managed/assembly_valheim.dll`. Copy the reference set (the Companion's
`refresh-libs.sh` NEEDED list plus `assembly_valheim.dll` raw) into `libs/`. Run the publicizer
(`BepInEx.AssemblyPublicizer.Cli` from the pak) → `assembly_valheim_publicized.dll`. BepInEx core
(`BepInEx.dll`, `0Harmony.dll`) comes from the pak, **not** from the world (a world may be
vanilla). Optionally (`libs.decompile`, default on) `ilspycmd -p -o libs/decompiled
libs/assembly_valheim.dll` so the agent can grep the game's real source (§7.2 `game_source`).
Game version = `assembly_valheim.dll` file version. Write `libs/manifest.json`. Re-harvest is one
click in Settings → Libraries and is suggested when a world's assembly is newer than the harvest.

### 6.3 Build, package

`dotnet build <csproj> -c <Release|Debug> -nologo -v <verbosity> -p:VibesLibs=... ` with the pak
env; stdout+stderr to `.vibes/builds/<n>.log`; diagnostics parsed into
`[{file,line,col,code,severity,message}]`; `hv_projects.last_build_ok/at`. Package reads
`manifest.json`, validates the Thunderstore rules (name `^[A-Za-z0-9_]+$`, semver
`version_number`, 256×256 PNG icon, description ≤ 250), and zips to `dist/`.

### 6.4 Publish into the local catalogue (job `publish`)

Copy `dist/<Owner>-<Name>-<ver>.zip` → `catalog/`. Upsert `mods` (`source='vibes'`, owner =
namespace, name, `full_name = owner-name`, `latest_version`, `version_count`, `date_updated`,
`description` from the manifest, `categories = ["Hugin's Vibes"]`). Insert `mod_versions`
(`download_url = file:///opt/stateful/vibes/catalog/<zip>`, `file_size`, `deps` =
`manifest.dependencies`, `source_rank` renumbered so 0 is newest, `is_active = 1`). Insert
`mod_deps` edges by resolving each `Owner-Name-X.Y.Z` against `mods (owner, name)` (any source;
prefer the source the operator has enabled, then thunderstore). Write `catalog/index.json`.
`hv_projects.status='published'`, `published_version`. `phvEvent`. Unpublish removes one version
(refusing if any `world_mods` row pins it; the UI lists the worlds).

**Engine seam (the only one):** in `installMods` (`0-functions.sh` ~line 953) a
`download_url` beginning `file://` is copied with `cp` instead of fetched with wget, with the same
size check. Marker v59 guards it.

**Picker seam:** `catalogSources()` in `includes/modcatalog.php` gains
`'vibes' => ['label' => "Hugin's Vibes", 'colour' => 'vibes', 'enabled_col' => 'vibesInstalled']`.
A `.source-pill.vibes` CSS rule (violet, Hugin's colour). Nothing else in the picker changes: a
vibes mod is a mod.

### 6.5 Uninstall (job `uninstall`, "Delete Hugin's Vibes")

Arguments `{keep_projects, keep_catalog, keep_assets}`. Refuses while any job is running. Removes
`buildpak/`, `libs/`, `downloads/`, `logs/jobs/`; removes `projects/` unless kept (the UI offers a
tar.gz export first); removes `catalog/` + the `source='vibes'` rows unless kept (warning naming
the worlds that use them — a kept catalogue keeps working because the engine only needs the zips).
Sets `vibesInstalled=0`, `vibesBuildpakVersion=NULL`, keeps `vibesSettings` (tokens included)
unless `wipe_settings`. Truncates `hv_*` unless projects kept. Event + result summary of bytes freed.

---

## 7. The Forge loop (the agent)

### 7.1 Shape

`vibes.php turn <sessionId> <messageFile>` →
`agent.php: forgeTurn($pdo, $session, $userText, $emit)`:

```
system  = forgeSystemPrompt(project, notes.md, tree, git log -5, libs manifest, knowledge index, rules)
messages = compaction summary (if any) + last N transcript turns + the new user turn
loop up to agent.max_rounds:
    res = aiChat(provider, messages, system, forgeToolDefinitions(), onDelta → emit token)
    if no tool_calls: final; break
    for each call: emit tool_call; result = forgeRunTool(...); emit tool_result; append
after: if auto_build and files changed and no build this turn → run build, feed a one-line
       result to the transcript as a system note (NOT a model call)
       if auto_commit and tree dirty → git add -A && commit "vibe: <first 60 chars of user text>"
       update hv_sessions.turns/tokens; emit done
```

Hard limits: `turn_timeout_s` wall clock (SIGALRM via `pcntl_alarm` if available, else checked
between rounds), tool result cap 60 KB (as Hugin), total tool output per turn 400 KB. A cancel
from the UI sets `hv_jobs.status='cancelled'`; the loop checks it between rounds and tools.

### 7.2 Tools (all paths relative to the project root; `..` and symlinks out are refused)

| tool | args | notes |
|---|---|---|
| `list_files` | `path?` | tree with sizes, ignores bin/obj/.git |
| `read_file` | `path, start?, end?` | line-numbered |
| `write_file` | `path, content` | creates dirs; emits `file_changed` |
| `edit_file` | `path, old, new` | exact-match replace, must match once; returns the diff hunk |
| `delete_file` | `path` | |
| `search` | `pattern, path?, regex?` | grep -rn, capped |
| `build` | `configuration?` | runs §6.3, returns diagnostics; the model is told to always build before declaring done |
| `shell` | `cmd` | **allow-listed** binaries (dotnet, git, ls, cat, head, tail, grep, find, wc, sed -n, sort, uniq, diff, unzip -l, file) unless `agent.unrestricted_shell`; cwd = project; 120 s; no network env |
| `game_source` | `query, kind: type|member|text` | greps `libs/decompiled/` (if present) — "show me Player.UseStamina" returns the real method |
| `game_types` | `prefix` | lists public types from the publicized assembly via a cached `ilspycmd -l` index |
| `knowledge` | `topic` | returns one `knowledge/<topic>.md`; the index of topics is in the system prompt |
| `read_asset` / `list_assets` | | the operator's `assets/` library; `copy_asset` into the project |
| `manifest` | `set: {version_number?, description?, dependencies?, website_url?}` | validated |
| `note` | `text` | appends to `.vibes/notes.md` — Hugin's durable project memory |
| `git` | `op: status|diff|log|commit|tag`, `message?` | no push; push is an operator button |

No tool reaches the network. No tool reads outside `/opt/stateful/vibes/` except `read_file` on
the harvested libs' manifest. Nothing in Vibes can start, stop or alter a world; publishing adds
to the catalogue only, and a world picks a vibes mod up through the ordinary Mods editor + Update.

### 7.3 Events (JSONL; the same shape in `logs/jobs/<id>.jsonl` and `.vibes/sessions/<id>.jsonl`)

```jsonc
{"seq":1,"t":1761300000.123,"type":"status","step":"Downloading Build Pak","progress":42,"detail":"88 of 212 MB"}
{"seq":2,"t":...,"type":"user","text":"..."}                       // transcript only
{"seq":3,"t":...,"type":"token","text":"Let me look at"}            // streamed assistant delta
{"seq":4,"t":...,"type":"tool_call","id":"c1","name":"read_file","args":{"path":"src/Plugin.cs"}}
{"seq":5,"t":...,"type":"tool_result","id":"c1","ok":true,"preview":"1  using BepInEx;...","ms":12}
{"seq":6,"t":...,"type":"file_changed","path":"src/Plugin.cs","kind":"edit","adds":12,"dels":3}
{"seq":7,"t":...,"type":"build","ok":false,"errors":[{"file":"src/Plugin.cs","line":41,"col":9,"code":"CS0103","message":"..."}],"warnings":2,"ms":8800}
{"seq":8,"t":...,"type":"assistant","text":"...final prose..."}      // the stored turn
{"seq":9,"t":...,"type":"commit","hash":"ab12cd3","message":"vibe: add stamina config"}
{"seq":10,"t":...,"type":"error","error":"..."}
{"seq":11,"t":...,"type":"done","usage":{"in":12345,"out":2345},"model":"...","rounds":6}
```

`admin/vibesStream.php?job=<id>&after=<seq>` and `?session=<id>&after=<seq>` tail the file
(SSE, `X-Accel-Buffering: no`, same prologue as `aiStream.php`), poll every 250 ms, exit on
`done`/`error` or client abort. The UI reconnects with the last `seq` it rendered.

### 7.4 Knowledge (`tools/vibes/knowledge/`, shipped in the image)

One topic per file, ~1–3 KB each, written for a model not a human, titles in the system prompt:

`bepinex-plugin-anatomy`, `harmony-patterns` (prefix/postfix/transpiler, `__instance`,
`__result`, `ref`), `config-entries`, `valheim-core-classes` (ZNet, ZNetScene, ZDO/ZDOMan, Game,
Player, Character, Inventory, ItemDrop, ObjectDB, Localization, Terminal/Console, RPC via
`ZRoutedRpc`, `ZPackage`), `server-vs-client` (what runs where, `ZNet.instance.IsServer()`,
`ZNet.IsDedicated()`, syncing config with ServerSync/Jotunn), `jotunn-basics`,
`thunderstore-manifest-and-package`, `versioning-and-guid`, `pitfalls` (the publicizer trap —
compiles green, throws at runtime; `Character.Message` arity change; IMGUI buttons that draw but
never click; cloned buttons keep Inspector-wired listeners; 1E-18f tooltip sentinel; world-level
item stamp; UnifiedPopup panel tree), `phvalheim-worlds` (how PhValheim installs a mod on a
world, server/client destination switches, that `BepInEx/config` is regenerated, custom_configs),
`testing-a-mod` (why a green build proves nothing; how to use the operator's world).

The agent's rules, in the system prompt: build before saying done; never change the GUID after
publish; bump `version_number` when behaviour changes; one feature per commit; prefer Harmony
postfix to transpiler; never invent a game API — use `game_source`/`game_types` to confirm; say
plainly when something cannot be verified without a running client.

### 7.5 Context across sessions

A project's durable context is three things, all on disk: `notes.md` (Hugin's own memory, read
every turn), the git history (`git log -5` in the prompt, full via the tool) and the session
transcript. When a session's transcript exceeds `agent.context_budget_tokens` (estimated at
4 chars/token), the runner asks the model once for a ≤ 800-token summary of the older half,
stores it in `hv_sessions.summary`, and from then on sends summary + the newer half. Switching
projects switches all three. "New session" starts a fresh transcript; notes and git carry over.

---

## 8. The Build Pak

Repository: `github.com/brianmiller/phvalheim-vibes-buildpak` (new). Release assets:
`phvalheim-vibes-buildpak-<ver>-linux-x64.tar.gz`, `.sha256`, `manifest.json`.

```
<pak>/
  manifest.json      { "pakFormat": 1, "version": "1.0.0", "base": "jammy", "arch": "x64", "builtAt": "...",
                       "components": { "dotnet-sdk": "8.0.4xx", "git": "2.34.1-1ubuntu1.x", "bepinex": "5.4.23.x",
                                       "harmonyx": "2.x", "publicizer": "0.4.x", "ilspycmd": "9.x", "nuget-cache": "sha256" },
                       "sizeBytes": ..., "selftest": "selftest.sh" }
  selftest.sh        prints every component's version; non-zero if any is missing
  dotnet/            Microsoft's linux-x64 SDK tarball, extracted (DOTNET_ROOT)
  git/               `dpkg -x` of jammy's git + libcurl3-gnutls (+ their deps not already in the image), under usr/;
                     GIT_EXEC_PATH=git/usr/lib/git-core, LD_LIBRARY_PATH prefix git/usr/lib/x86_64-linux-gnu
  nuget/             a warmed NUGET_PACKAGES cache: Microsoft.NETFramework.ReferenceAssemblies, HarmonyX, BepInEx.Core,
                     BepInEx.AssemblyPublicizer.MSBuild, JotunnLib — warmed by restoring every server template offline
  tools/             dotnet tools installed with --tool-path: ilspycmd, BepInEx.AssemblyPublicizer.Cli
  bepinex/           BepInEx_linux_x64 core DLLs (BepInEx.dll, 0Harmony.dll, Mono.Cecil …) for referencing
  NuGet.Config       offline source first, nuget.org second (disabled by advanced.nuget_online=false)
```

Built by a Dockerfile `FROM ubuntu:jammy` (the same base as the server, so dpkg-extracted
binaries just work; a `noble` pak is the same file with one ARG), by GitHub Actions on tag, on
a weekly cron (to pick up SDK patch releases — a new pak is released only when `components`
changed) and on demand. The workflow checks out `phvalheim-server/container/engine/tools/vibes/
templates` to warm the NuGet cache and to smoke-build each template with the pak, then runs
Trivy on the tarball. The server shows "Build Pak x.y.z available" from the releases API
(`buildpak.auto_check`, daily, same cache discipline as `git.php`) and updates with one click
(download, selftest, swap `current`, keep the previous pak until the next smoke build passes).

Compatibility: `pakFormat` is bumped only when the server-side layout expectations change; the
server lists the formats it accepts. `base` must match the image. Everything else floats.

---

## 9. pi (and other harnesses) — considered

[pi](https://github.com/badlogic/pi-mono) is a small, well-made TypeScript coding agent with an
RPC mode and pluggable providers. Putting it under the hood would buy a mature loop, session
trees, compaction and tool-call parsing hardened across providers. It would cost:

- a Node runtime (~110 MB) plus an npm dependency tree in the Build Pak, with its own CVE churn;
- a **second provider layer**: pi has its own provider config, so every API key in `ai_providers`
  would be exported into pi's config, and the 2.45 capability negotiation (tool-capable vs text vs
  inert, Gemini thought signatures, the "never rewrite a model id" rule) would be re-learned;
- a process boundary for every tool call (pi's tools are JS; ours need PHP/DB access for `manifest`,
  `note`, publish state), and a TUI-first design we would use none of.

Decision: **build the loop ourselves on `aiChat()`** — it is ~400 lines because the transport,
registry and negotiation already exist — and keep `lib/agent.php` behind one function
(`forgeTurn`) with the event stream of §7.3 as its only output, so pi, the Claude Agent SDK or a
local model runner could replace it later without touching the UI. Revisit if the loop grows
compaction/branching needs we cannot meet in a day.

---

## 10. The UI

`admin/hugins_vibes.php` (own page, opened from the sidebar in a new tab like the other Tools),
`admin/vibesAPI.php` (JSON), `admin/vibesStream.php` (SSE), `css/vibes.css`, `js/vibes.js`. The
page loads the admin theme's `phvalheimStyles.css` first; `vibes.css` only adds.

### 10.1 Visual language

PhValheim's dark slate palette with Hugin's violet (`--accent-secondary`) as the page's accent
instead of the dashboard's cyan — this is *his* room. Fancy means: a faint animated aurora
gradient behind the header (CSS only, `prefers-reduced-motion` honoured); glass panels
(`backdrop-filter: blur`, 1px violet-tinted borders); a monospace "forge" rail where tool calls
tick in as chips with a status dot (pending pulse → ok green → fail red) and elapsed ms;
Hugin's SVG (`huginSvg()` — the one drawing, cloned) with the existing `thinking`/`idle` states
driving the header; live skeletons; micro-transitions ≤ 200 ms; nothing bounces. Futuristic
without being a gamer skin: no neon pink, no scanlines, type stays JetBrains Mono for data and
the system sans for prose, exactly as the dashboard.

### 10.2 Layout

```
┌ header ── Hugin + "Hugin's Vibes"  ·  chips: Build Pak 1.0.0 ✓ · Valheim 0.220.5 ✓ · Model: … · Disk 54 GB ─ ⚙ Settings ─┐
├ library (left, 280px) ──────┬ workspace ───────────────────────────────────────────────┬ context (right, 300px) ┤
│ search · + New Vibe         │ tabs: Vibe · Code · Build · Package · History             │ Project notes (editable) │
│ ▣ NoMovementPenalty 1.2.0   │                                                           │ Sessions (switch/new)    │
│   published · 2 worlds      │ Vibe: transcript (markdown, code blocks), forge rail,     │ Build: ✓ 0 errors 2 warn │
│ ▣ HirdVault 0.3.1 built     │       composer with ⌘↵, Stop, model picker (verbatim)     │ Published: 1.2.0 → 2 worlds│
│ ▣ ClimbingStamina draft     │ Code: tree + viewer (C#/JSON/MD highlight, in-house)       │ Disk: 14 MB              │
└─────────────────────────────┴───────────────────────────────────────────────────────────┴──────────────────────────┘
```

Panels collapse below 1200 px (library becomes a drawer; context becomes a tab).

### 10.3 States the page must handle explicitly

1. **Not installed, first visit** → the introduction modal (§10.4). No workspace behind it.
2. **Not installed, AI provider missing/disabled/no model** → the same modal with its Install
   button disabled and a red line: "Hugin's Vibes needs an AI provider. Add one in Server
   Settings → AI Helper." (link). Re-checked live every 5 s while the modal is open.
3. **Installing** → the modal turns into a progress sheet (steps with ticks, a bar, live
   detail, the job log behind a "details" disclosure). Survives a page reload (it reads the open
   job). Failure shows the step, the error, and Retry / Copy log.
4. **Installed, no game libraries** (no world has server files yet) → a card in place of the
   workspace with two remedies (create a world; download the dedicated server files).
5. **Installed, no projects** → an empty-state library with three one-click starter ideas.
6. **Working** → everything live. While a job runs on a project, its card shows a progress ring,
   the composer is disabled with the reason, and Stop is offered.
7. **Build Pak update available / pak missing after a volume restore / base mismatch after an
   image upgrade** → a dismissible banner with the one action that fixes it.
8. **Deleted** → back to state 1 (the modal says "Welcome back").

### 10.4 First-launch modal

Hugin large (`huginSvg('idle hugin-hello', 92)`), "I can build mods now." Three short paragraphs:
what it is; what gets downloaded (name, size from the release manifest, where it goes, that it
is **an additional download** separate from the server image and from Valheim); what it needs
(an AI provider — status line green/red; free disk — number vs requirement, green/red). Checkbox
"Also decompile Valheim's code so I can read it (recommended, +~150 MB)". Buttons: **Download &
Install** (primary, disabled until both checks pass), *Not now*. `vibesNoticeShown` is set when
dismissed either way; the sidebar item still opens the page (which shows the modal again until
installed).

### 10.5 Settings (modal, tabbed; every field saves with explicit Save and a toast)

**General** author namespace (validated `^[A-Za-z0-9_]+$`, warns it becomes the catalogue
owner and must not change after publishing), display name, default template, default
provider/model (the model list comes from `discoverAiModels`, free text allowed, never validated).
**Dev Environment** pak version + Check for updates + Update + Re-download; configuration
Debug/Release; dotnet verbosity; max rounds; turn timeout; auto-build; auto-commit; context budget;
**Unrestricted shell** (off; red help text). **Libraries** source world picker, game version,
harvested-at, Re-harvest, decompile toggle, lib list with sizes. **Assets** drag-drop upload
(limit from settings), list with preview for images, delete, "used by" counts. **GitHub**
enabled, token (masked), owner/org, default visibility, Test connection (shows the login it
resolves to). **GitLab** enabled, host, token, group, visibility, Test. Both can be on; each
project's History tab has one Push button per enabled SCM and shows the remote URL. **Advanced**
pak repo (override), channel, allow prerelease pak, keep transcripts N days, log level, NuGet
online, Export settings / Import. **Danger zone** "Delete Hugin's Vibes" → a modal listing what
will be removed with three keep-switches, the worlds that use vibes mods, free-space gained, and a
type-the-word confirm.

### 10.6 `vibesAPI.php` actions (all JSON, admin session required as every admin page)

| action | method | in → out |
|---|---|---|
| `state` | GET | `{installed, pak:{version,available,compatible}, libs:{gameVersion,harvestedAt,decompiled}, ai:{ok,provider,model,reason}, disk:{free,required}, jobs:[open], noticeShown}` |
| `pakInfo` | GET | release lookup: `{version, sizeBytes, components, url}` or `{error}` |
| `install` / `uninstall` / `harvest` / `pakUpdate` / `gc` | POST | `{jobId}` |
| `projects` | GET | `[{...hv_projects, size, worlds:[names using it], lastSession}]` |
| `createProject` | POST | `{name, namespace?, description, template}` → `{project}` |
| `project` | GET `id` | full row + manifest + tree + git log + builds |
| `updateProject` | POST | editable fields |
| `deleteProject` | POST | `{id, confirm:name}` (refuses if a job runs; offers export first) |
| `exportProject` | GET | tar.gz of the repo (streams) |
| `sessions` / `newSession` / `renameSession` | | |
| `transcript` | GET `session, after` | events since seq (for history render; live via SSE) |
| `turn` | POST | `{session, text}` → `{jobId}` (409 if a job runs for the project) |
| `cancel` | POST | `{jobId}` |
| `file` | GET `id, path` | content (size-capped) |
| `saveFile` | POST | `{id, path, content}` (operator edits are allowed; committed as "operator edit") |
| `build` / `package` / `publish` / `unpublish` | POST | `{id, version?}` → `{jobId}` |
| `push` | POST | `{id, remote: github|gitlab}` → `{jobId}` |
| `gitLog` / `gitDiff` | GET | |
| `notes` / `saveNotes` | | `.vibes/notes.md` |
| `assets` / `uploadAsset` / `deleteAsset` | | multipart upload |
| `settings` / `saveSettings` / `testGitHub` / `testGitLab` | | tokens masked on read; `"••••"` on write = unchanged |
| `dismissNotice` | POST | `vibesNoticeShown=1` |
| `job` | GET `id` | row |

Errors: `{success:false, error:"…"}` with 4xx; never a PHP warning in the body.

---

## 11. Sidebar, What's New, markers

- `admin/index.php` Tools section: first item **Hugin's Vibes** (icon: small Hugin? No — the
  nav uses 24px line icons; use a "sparkles + wrench" line icon and the violet dot used for the
  Logs badge when a job is running). `data-nav="hugins-vibes"`, `target="_blank"`.
- `whatsnew.php` 2.59 entry: what it is, that it is opt-in and a separate download, what it
  needs, that nothing changes for operators who never open it.
- Verify markers `v59aa…` in `dev_tools/buildRcDetached.sh`: page present, API present, runner
  present with every subcommand, migration creates the three tables, `catalogSources` has
  `'vibes'`, the `file://` seam in `0-functions.sh`, templates and knowledge dirs non-empty,
  **no** `node`/`dotnet`/`git` binary in the image (the pak carries them), the sidebar item is
  the first child of the Tools section.
- `predict-verify.sh` must evaluate every v59 marker (single-line definitions only).

---

## 12. Tests (dev_tools/, run by `run-oracles.sh`)

Standalone: `test-vibes-migration.sh` (idempotent twice), `test-vibes-settings-merge.php`
(old document + new defaults), `test-vibes-tools-sandbox.php` (`..`, symlink, absolute paths
refused; `edit_file` must-match-once), `test-vibes-build-parser.php` (dotnet output fixtures incl.
a warning-only build and a multi-error build), `test-vibes-manifest-validate.php`,
`test-vibes-package-layout.sh` (zip entries exactly Thunderstore's), `test-vibes-shell-allowlist.php`.
Container: `test-vibes-catalog-survives-sync.sh` (publish a fixture, run `modSync.py --force`, rows
and edges still there), `test-vibes-file-url-install.sh` (a world picks a `file://` mod up through
`installMods`), `test-vibes-api.sh` (every action answers JSON, never a PHP warning; a missing
parameter is a 4xx with a sentence — there is no per-request auth on the admin port, as for every
admin page: port 8081 is the trust boundary and must never be exposed), `test-vibes-ui.js` (Playwright: states 1, 2, 5, 6 render; the modal's Install button is disabled
without a provider — asserting the STIMULUS, i.e. the provider row, not just the button). A pak
smoke test runs in the pak repo's CI, not here. A full install against the real GitHub release
is a manual oracle with the dev box until the first pak is published.

---

## 13. Delivery order

1. Migration, `includes/vibes.php` (settings, paths, job enqueue/launch helpers), sidebar item,
   `catalogSources` seam, pill CSS, whatsnew, markers. *(this document's author)*
2. Runner: `tools/vibes/*` with every job kind, templates, knowledge, the `file://` engine seam,
   the boot `mkdir`. *(agent A)*
3. UI: page, API, stream, CSS, JS, in every state of §10.3. *(agent B)*
4. Build Pak repo: Dockerfile builder, `build.sh`, manifest, selftest, CI workflow, README.
   *(agent C; built locally first, published to GitHub only on Brian's go)*
5. Integration on `phvalheim-dev`: migrate, install from a **local** pak (file URL override in
   advanced settings exists for exactly this), harvest from midgard, create a Vibe, forge a turn
   with the configured provider, build, publish, add to a world's mod list, world Update picks it
   up through the `file://` seam. Screenshots of every §10.3 state for Brian.
6. Tests of §12, `predict-verify` 0 failures, `:dev` build.

---

## 13.5 Integration log (dev box, 2026-10-24)

What the first end-to-end run on `phvalheim-dev` found, in order. Each is fixed in the tree and
most have an oracle.

| Found | Cause | Fix / oracle |
|---|---|---|
| Picker offered the Vibes catalogue while uninstalled | `catalogSources()` only SELECTed the two older gate columns, so a present-but-0 column hit the "absent → ON" default | gate column read; `test-vibes-migration.sh` |
| Every `dotnet build` as uid 1000 died: `cannot open the device or file specified : 'NuGet-Migrations'` (root passed) | .NET 8 roots a user-scoped named mutex at `XDG_RUNTIME_DIR`, else at the user's `/etc/passwd` home — `phvalheim`'s is `/opt`, owned by root (coreclr `pal/sharedmemory.cpp`) | `XDG_RUNTIME_DIR=/tmp/vibes-run-<uid>` in the pak's `selftest.sh env` and `lib/env.php`; the pak's offline smoke build is the oracle |
| Smoke compile on install: `MSB4025 An XML comment cannot contain '--'` | prose punctuation inside the template's csproj/props comments (the 2.51 SVG trap again) | `test-vibes-template-xml.sh` parses every template XML |
| `libs/types.txt` held only ilspycmd's update nag | `ilspycmd -l` takes ONE entity kind per call; `-l c,i,s,e,d` lists nothing | per-kind calls, `--disable-updatecheck`; harvest manifest `typesIndexed` (1,008 on build 25909431) |
| `createProject` → `Call to undefined function mb_strlen()` | the image has no mbstring | iconv-backed `vibesStrlen/vibesSubstr/vibesUtf8Ok` in `includes/vibes.php` |
| First real turn died at 120 s with 84 KB streamed | `AI_HTTP_TIMEOUT` (120 s) is sized for a chat reply | `vibes.php` defines it from `agent.turn_timeout_s` before `aiproviders.php` loads |
| Published mod "DOWNLOAD FAILED" on the world update | the running engine had sourced `0-functions.sh` at boot, before the seam was deployed — a dev-box artefact, not a product bug; an image upgrade always restarts the engine | restart the engine after a `docker cp` of any `includes/*.sh` |
| Transcript showed a turn's reply above the previous turn's error | the streaming bubble left open by an errored turn swallowed the next turn's tokens | `finalizeAssistant()` on `user` and `error` events |
| Header chip said "Valheim no libraries" after a harvest | `assembly_valheim.dll`'s file version is 0.0.0.0, so `gameVersion` is null | `gameBuild` (Steam buildid) carried in state and shown as the fallback |

The real turn that passed: 34 rounds, 70 tool calls (30 `game_source`, 19 `shell`, 9 `read_file`,
3 `knowledge`, 2 `game_types`, 2 `write_file`, 2 `git`, `build`, `note`, `manifest`), a green
Release build in 1.6 s, commit `cb0a451`, notes written, published as
`DevVibes-StaminaWhisper-0.1.0` with its BepInEx dependency edge resolved to the Thunderstore
row. Cost: 1,057,910 input tokens for 15,047 output — every round resends the whole context, so
`agent.max_rounds` and the context budget are the operator's cost controls, and a cheaper
"fast" model is a poor fit (it hides tool calls in reasoning text; see the provider notes).

## 14. Open questions for Brian (none block the build; defaults chosen are in brackets)

1. Pak repo name and whether it is public [`brianmiller/phvalheim-vibes-buildpak`, public —
   the release asset must be fetchable without a token from every operator's server].
2. Should a vibes mod be publishable to Thunderstore from the UI later? [not in 2.59; the package
   is already Thunderstore-shaped so it is a one-button addition].
3. Should Hugin be allowed to **start/stop the operator's world** to test a mod? [no in 2.59;
   nothing in Vibes touches worlds — the operator uses the dashboard].
