# phvalheim-server — Project Context

> Maintained by Skippy. Updated when significant changes are made.
> Last updated: 2026-08-08

## Overview

Single Docker container that manages Valheim worlds and keeps server/client mod configurations in sync.
Provides admin and public web UIs, Steam authentication, Thunderstore mod catalog, and automatic backups.

## Current Version
**2.39** — released 2026-08-08, published as `:2.39`, `:rc`, and `:latest`

## Stack
- Single Docker container: NGINX, PHP, MariaDB, Supervisor
- Ports: `8080` (public UI), `8081` (admin UI — do NOT expose publicly), `25000-26000/udp` (game ports)
- Volume: `/opt/stateful` for persistent data, optionally a separate backup volume

## Key Features
- One-click world deployment with any combination of Thunderstore mods
- Automatic mod sync — server and client stay in lock-step via world zip
- Setup wizard on first run (no env vars required)
- Steam authentication, per-world ACLs
- Thunderstore catalog synced every 12 hours with dependency resolution
- Automatic backups every 30 minutes, configurable retention
- Live CPU/memory/load monitoring per world
- AI Helper: log analysis via OpenAI, Gemini, Claude, or self-hosted Ollama
- Analytics with opt-out support

## Key Architecture Notes
- `tools/patch-bepinex/`: C# .NET 9 IL patcher using AsmResolver.DotNet
  - `patch-runtimedetour` command: patches MonoMod.RuntimeDetour.dll for macOS arm64 MAP_JIT W^X fix
  - `inspect-method` command: for debugging IL
- `InstallAndUpdateBepInEx()`: copies patched preloader after every BepInEx install/update
- `packageClient()`: includes patched DLLs in world zip so macOS clients receive them automatically
- `clientDownloadButton.php`: macOS detection via `userAgent.php`, shows install modal (not .pkg)
- Analytics payload written to file to avoid null-byte truncation
- `container/games/valheim/bepinex_patches/`: patched DLLs for macOS arm64

## macOS arm64 / BepInEx Compatibility (v2.37)

**Problem:** BepInEx 5.4.23.3 crashes on macOS arm64 — `DetourHelper.GetIdentifiable()` returns null
for methods like `Console.SetOut` on the macOS Mono runtime, causing NullReferenceException in
`ConsoleSetOutFix.Apply()` which prevents BepInEx from initializing.

**Also:** MonoMod's `PlatformHelper.DeterminePlatform()` only checks ARM32 (0x01C4), missing ARM64
(0xAA64) → selects `DetourNativeX86Platform` → writes x86 JMP bytes into arm64 code → SEGV crash.

**Fixes applied:**
- `BepInEx.Preloader.macos_arm64.dll`: patched via AsmResolver to wrap `ConsoleSetOutFix`,
  `XTermFix`, and `HarmonyInteropFix` `Apply()` methods in try-catch blocks
- `MonoMod.Utils.dll`: patched to add ARM64 (0xAA64) to `DeterminePlatform()`
- `MonoMod.RuntimeDetour.dll`: patched with 5-part fix for MAP_JIT W^X enforcement on Apple Silicon
  - Part 1: `libmonobdwgc-2.0.dylib` fallback in `DetourHelper.get_Native()`
  - Part 2/2b: wrap `_HookSelftest` + selftest block in try-catch
  - Part 3: fix `get_Runtime` to retry when `_RuntimeInit=true` but `_Runtime=null`
  - Part 4: `pthread_jit_write_protect_np` P/Invoke (WIP — causes SIGBUS, under investigation)
  - Part 5: null-safe `GetIdentifiable`
- Patched DLLs staged in `BepInEx/patches/macos_arm64/`, applied only on Apple Silicon

## Recent Version History
- **v2.39** (2026-08-08): Fix world boot failure after modpack rebuild (#80); first stable ship of the
  v2.38 backup system. Mod zips packaged on Windows can store dirs without the execute bit, which `unzip`
  preserves — BepInEx then can't traverse extracted plugin dirs and aborts with a fatal
  `UnauthorizedAccessException`. `0-functions.sh` now restores `u+rwX` after Thunderstore extraction and
  after custom mods/configs/patchers installs (`cp -p` preserves bad source perms the same way).
- **v2.38** (2026-04-07, pre-release): Backup system modernization — activity-aware scheduling, tiered
  retention, compression, one-click restore, backup management UI, startup reconciliation
- **v2.37** (2026-03-11–13): Fix BepInEx loading on macOS arm64 (see above)
- **v2.36** (2026-03-07): Add macOS client support
  - Bundle `libdoorstop.dylib` (universal fat binary, NeighTools/UnityDoorstop v4.5.0) in world zip
  - macOS download button in `clientDownloadButton.php` via `userAgent.php` UA detection
- **v2.35** (2026-03-07): Fix fresh install showing migration notice instead of setup wizard
  - Removed `TZ` from upgrade-detection env var check (TZ is set by default in most Docker envs)
- **v2.34** (2026-03-06): Silence analytics success notices from engine log

## Recent Commit History
- `c754c77d` 2026-08-08: docs: add v2.39 changelog entry
- `d0ace70a` 2026-08-08: v2.39: fix world boot failure after modpack rebuild (#80)
- `05a7cf90` fix: replace streaming with polling for backup/restore progress (Cloudflare Tunnel buffers streams)
- `da8ad252` fix: disable NGINX FastCGI buffering for streaming progress
- `6b7937be` fix: include orphaned column in initial backups table schema
- `9c954582` 2026-03-14: macOS: replace download popover with install modal (copy-to-clipboard curl command)
- `473d59b4` 2026-03-13: Add patched MonoMod.Utils.dll for ARM64 platform detection
- `a3ca2a8b` 2026-03-13: Fix dead-code bug in patch-runtimedetour: redirect catch leaves to Part 1/1b
- `d4aa55a0` 2026-03-13: macOS arm64: ship patched DLLs separately, apply only on Apple Silicon
- `615e0260` 2026-03-11: tools/patch-bepinex: extend patcher with patch-runtimedetour command
- `3241503d` 2026-03-11: v2.37: Fix BepInEx loading on macOS arm64 (patched preloader + MonoMod)
- `dce6b98b` 2026-03-07: docs: update client section for cross-platform support
- `6172d294` 2026-03-07: v2.36: Add macOS client support
- `156af168` 2026-03-07: v2.35: Fix fresh install showing migration notice
- `6485a513` 2026-03-06: v2.34: Silence analytics success notices from engine log

## Dev Environment

> Host-specific values (dev hostnames, internal IPs, secrets path) are intentionally omitted —
> this repo is public. See the private infra notes for actual addresses.

### Repo location
`<dev-checkout>/phvalheim-server`

### Build
```bash
bash dev_tools/buildImage.sh
# Builds theoriginalbrian/phvalheim-server:rc locally (no push)
# Does: docker system prune -af, then docker buildx build --no-cache --network=host
```

### Local Deploy (RC)
```bash
bash dev_tools/deployRc.sh
# Stops/removes existing phvalheim-server container, runs :rc image
# Sources the out-of-repo secrets.cfg for API keys
```
- Container name: `phvalheim-server`
- Public UI:  `http://<dev-host>:8082`
- Admin UI:   `http://<dev-host>:8083`
- Game ports: 25000–25050/udp
- Stateful volume: `/opt/phvalheim-test`

### Promote RC to Production
```bash
bash dev_tools/promoteRCtoLatest.sh
```

### Other dev_tools scripts
- `deployLatest.sh` — deploy the :latest tag locally
- `saveGit.sh` — git operations helper
- `printChangeLogSinceLastTag.sh` — changelog since last git tag

### Secrets file
A `secrets.cfg` outside the repo, sourced by `deployRc.sh`. Supplies the Steam and AI-provider API keys
and the Ollama URL. Never commit it or its contents — this repo is public.

## Known Issues / Watch Items
- **#79**: Download button vanishes on phvalheim.com in Firefox — open, unresolved
- MonoMod.RuntimeDetour Part 4 (pthread_jit_write_protect_np) causes SIGBUS — still under investigation
- Any BepInEx version bump will require re-patching the affected DLLs

## Release Process
1. `bash dev_tools/buildImage.sh` — builds `:rc` locally. Long builds must run detached
   (`setsid nohup`) — background Bash tasks die when the agent session recycles.
2. Bump `ENV phvalheimVersion` in the Dockerfile; add a `dbUpdates/dbUpdate_X.YZ.sh` if the schema changed.
3. Tag and push `:VERSION`, then `bash dev_tools/promoteRCtoLatest.sh` to promote `:rc` → `:latest`.
   All three tags should end up on the same digest.
4. Add a `CHANGELOG.md` entry, commit, push.
5. `gh release create vX.YZ --latest --notes-file <notes>`.
