# 2.58: the Docker Hub vulnerability list, read and fixed

Written 2026-10-22. Scope: `theoriginalbrian/phvalheim-server:latest` as Docker Hub shows it
(2.57, digest `sha256:fe3a8e9c…`, pushed 2026-10-22 04:28 UTC).

## 1. What the list actually says

Docker Hub's Vulnerabilities tab is Docker Scout. The Hub's API refuses unauthenticated reads
of the per-image report, so the list was read with the Scout CLI against the same digest
(`docker scout cves theoriginalbrian/phvalheim-server:latest`, SBOM of 704 packages). The
headline numbers are the ones on the Hub page.

| Severity | Count | Fixable |
| --- | --- | --- |
| Critical | 17 | 0 |
| High | 491 | 17 |
| Medium | 829 | 29 |
| Low | 1,070 | 7 |
| Unspecified | 1 | 0 |
| **Total** | **2,408** across 42 packages | 53 |

### Where they come from

| Carrier | Findings | Why it is in the image | Used at runtime? |
| --- | --- | --- | --- |
| `linux-libc-dev` 5.15.0-198 (kernel userspace headers) | **2,290** (C17 H472 M756 L1045), none fixable | `libpulse-dev` → `libglib2.0-dev` → `libc6-dev` → `linux-libc-dev` | No. They are header files; the CVEs are in kernel code a container never runs. |
| `python3-cryptography` 3.4.8, `PyJWT` 2.3.0, `httplib2`, `oauthlib`, `zipp` | 35 (H10 M19 L6), all fixable upstream, none fixable on jammy | `software-properties-common` → `python3-launchpadlib` chain | No. Installed only to run `add-apt-repository multiverse`. |
| composer `vendor/` (phpseclib 3.0.14, guzzle 7.4.5, psr7 2.4.0, php-jwt 6.3.0) | 25 (H8 M15 L2), all fixable | `google/apiclient ^2.12` in `www/composer.json`, required by `public/index.php` and `public/authenticated.php` | **No class from it is referenced anywhere.** The autoloader was loaded and never used (a leftover from a Steam OpenID experiment, see the `#use xPaw\Steam\SteamOpenID` comment). 132 MB. |
| `gh` 2.103.0 (Go binary: `docker/cli`, `golang.org/x/crypto`) + `git` | 3 (H1 M1 U1), unfixable | "github cli" block in the Dockerfile; `engine/tools/installGitHubCLI.sh` | No. Nothing runs `gh`; release lookups are plain HTTPS from PHP (`includes/git.php`). |
| `packagekit`, `policykit-1`, `dbus`, `libdw1`/gstreamer, `lvm2` | 7 (M1 L6) | `software-properties-common` again | No. |
| `inetutils-ping` | 3 (M3) | "basic tools" line | No. Nothing calls `ping`. |
| Base image + tools we DO use (`pcre2`, `gnutls28`, `jq`, `libsndfile`, `dash`, `glibc`, `tar`, `vim`, `wget`, `expat`, `git`, `glib`, `libxslt`, `ncurses`, `php8.1`, `shadow`, `icu`, `zstd`, `systemd`…) | ~55 (M~35 L~20) | jammy base or a real dependency | Yes, or part of the OS. Unfixed in jammy main; several are fixed only in Ubuntu Pro ESM. |

Two further facts that shaped the plan:

- **`add-apt-repository multiverse` was a no-op.** `ubuntu:jammy` ships `/etc/apt/sources.list`
  with every multiverse line already enabled. The package that ran it cost 98 packages.
- **The base image was stale.** The build is cached and never passed `--pull`; the jammy tag
  current on 2026-10-22 (`jammy-20260930`) carries 13 fewer findings than the one in the image.

The 17 criticals are all kernel CVEs attributed to `linux-libc-dev`
(CVE-2023-53867, CVE-2024-50286, CVE-2025-37777, CVE-2026-63825, -63940, -68426, -72399,
-74345, -89530, -89652, -89659, -89708, -90037, -90162, -93221, -98365, -98805). None affect a
header file. The 17 fixable highs are phpseclib (7), cryptography (5), PyJWT (3), guzzle (1),
httplib2 (1): all in packages the product never executes.

## 2. The fix: Phase A (this change, stays on jammy)

Four Dockerfile lines, one dead directory, two PHP lines. No behaviour change for operators.

1. **Crossplay deps: runtime libraries, not `-dev`.** `libpulse-dev libatomic1 libc6` →
   `libpulse0 libpulse-mainloop-glib0 libatomic1`. Valheim's `libparty.so` links exactly
   `libpulse.so.0`, `libpulse-simple.so.0`, `libpulse-mainloop-glib.so.0`, `libatomic.so.1`
   (checked with `ldd` on a real world in the dev volume). The Dockerfile now fails the build
   if any of the four stops resolving. Removes 2,290 findings. A careless
   `apt-get autoremove` of `libpulse-dev` would also have removed `libpulse0`: that is why the
   runtime packages are named explicitly and asserted.
2. **Drop `software-properties-common` and `add-apt-repository`.** Replaced by a `grep` that
   fails the build if the base image ever stops enabling multiverse. Removes ~42 findings and
   98 packages.
3. **Drop the GitHub CLI block** (keyring, apt source, `gh`, and with it `git`) and delete
   `engine/tools/installGitHubCLI.sh`. Removes 4.
4. **Drop `inetutils-ping`.** Removes 3.
5. **Delete `container/nginx/www/vendor/`, `composer.json`, `composer.lock`** (17,575 files,
   132 MB) and the two `require … vendor/autoload.php` lines. Removes 25, all of the fixable
   highs that were not in the Python stack.
6. **`docker buildx build --pull`** in `dev_tools/buildRcDetached.sh`, so the dated
   `SECURITY_REFRESH` layer refreshes packages on top of a current base rather than a stale
   one. Removes up to 13.

### Gates added to the build (`dev_tools/buildRcDetached.sh`, markers v58aa–v58al)

- Negatives, read from `/var/lib/dpkg/status` inside the pushed image: `linux-libc-dev`,
  `software-properties-common`, `gh`, `git`, `libpulse-dev`/`libglib2.0-dev`/`libc6-dev`,
  `inetutils-ping` all absent; no `vendor/` directory; no `vendor/autoload` require.
- Positives: the four crossplay sonames resolve (`ldconfig`), `steamcmd` installed (multiverse
  still works), `python3` present (the mod system).
- **Scout gate (v58al):** the builder runs `docker scout cves` on the pushed image and passes
  the critical+high count into the verify; the verify wants 0. A new carrier shows up as a
  number in the build log, not on the Hub page a day later. No Scout on the builder fails the
  marker on purpose (install from github.com/docker/scout-cli into `~/.docker/cli-plugins/`).
  `SCOUT_MAX_CH=<n>` accepts a known, documented count. Jammy's unfixable medium/low noise is
  deliberately excluded so the gate cannot block an urgent release for a `dash` advisory.

`predict-verify.sh` reports the dpkg/ldconfig markers as SKIP (image-only), the rest pass.

### Expected result after Phase A

Predicted from the SARIF: **0 critical, 0 high, ~35 medium, ~20 low, all unfixable on jammy
main.** Measured numbers are in section 5.

## 3. What remains after Phase A, and why

Everything left is an Ubuntu 22.04 base or runtime package with no fix in jammy main
(Canonical fixes many of these only in Ubuntu Pro's ESM pocket, which a public image cannot
use). Per package (medium/low only): `pcre2` 8, `gnutls28` 5, `jq` 5, `libsndfile` 4 (needed
by `libpulse0`), `dash` 2, `glibc` 2, `tar` 2, `vim` 2, `wget` 1, `expat` 1, `glib2.0` 1,
`libxslt` 1, `cyrus-sasl2` 1, `ncurses` 1, `php8.1` 1 (low), `shadow` 2, `icu` 1, `zstd` 1,
`libbpf` 1, `gcc-12` 1, `systemd`/`dbus`/`elfutils`/`policykit` if still pulled transitively.

Only three of those are removable by choice: `vim` (2, a debugging convenience), `wget` (1,
used once in `0-functions.sh` for mod downloads; `curl` does the same job) and `jq` (5, used 23
times across the engine; not worth replacing). Left in place; the maintainer's call.

## 4. Phase B (optional, a separate release): move the base to Ubuntu 24.04

Scout's own recommendation. `ubuntu:noble` as a base scores **0C 0H 20M 2L** against jammy's
`0C 1H 25M 12L`, and its packages are a generation newer (php 8.3.6, nginx 1.24, python 3.12,
glibc 2.39). Probed on 2026-10-22:

- Every package the Dockerfile installs exists on noble, including `steamcmd:i386`
  (0~20180105-5), `lib32gcc-s1`, `libpulse0`, `supervisor`, `sysstat`.
- **PHP 8.3 lint is clean on all 523 PHP files** (`php -l` under 8.3.6). The one failure,
  `admin/gridphp/lib/inc/JSON.php` (curly-brace offsets), is fatal on 8.0+ already, so it is
  dead code today. The deprecation sweep (`utf8_encode`, `strftime`, `${var}` interpolation,
  `FILTER_SANITIZE_STRING`, `each()`) found nothing in app code.
- `engine/tools/*.py` compile under 3.12 (the repo's `__pycache__` is already cpython-312).
- **MySQL is the same version on both: 8.0.46.** No datadir upgrade, no downgrade trap.

What must change, and the traps:

1. `FROM ubuntu:noble`; the multiverse grep moves to `/etc/apt/sources.list.d/ubuntu.sources`
   (deb822: `Components: main restricted universe multiverse`).
2. Every `8.1` path: Dockerfile `COPY … /etc/php/8.1/…` (3), `supervisor.d/phvalheim.conf`
   (`/usr/sbin/php-fpm8.1`), `nginx/phvalheim.conf` (`php8.1-fpm.sock`, 3 places), the
   `dpkg --compare-versions php8.1-fpm` gate and marker v57aa, `phvalheimStyles`-independent
   tests that `docker exec … php-fpm8`.
3. **uid 1000.** `useradd phvalheim` gets 1000 on jammy; noble's base image ships an `ubuntu`
   user at 1000, so `phvalheim` would become 1001 and every file on an existing data volume
   would stop being ours. Must be `userdel -r ubuntu` first or `useradd -u 1000`.
4. PHP 8.2+ deprecations at RUNTIME (dynamic properties in classes, `null` to non-nullable
   internal parameters) do not show in lint; they land in `php-fpm` error logs. The container
   bucket and a session of real admin use are the oracle.
5. Scout's base-image detection depends on provenance attestations; `--provenance=mode=max`
   on the build makes the Hub page show the right base.

Recommended as its own release after 2.58 ships, with the full container bucket as its gate.
It is a version-bump-level change (Brian's call); 2.58 ships Phase A alone.

## 5. Test plan and results

Fill-in for the `:dev` build of this change (image verify, Scout, buckets, crossplay).

| Check | Result |
| --- | --- |
| `predict-verify.sh` on the working tree | 674 pass, 0 fail, 215 skip |
| Standalone bucket (62) on the working tree | 62 passed |
| Cross-repo bucket (4) | 4 passed |
| `:dev` build + in-image verify (v58aa–v58aq) | First build (still labelled 2.57): IMAGE VERIFY OK 2026-10-22 05:05 UTC, digest `sha256:3c0d00db…`. **Bumped build: version 2.58, IMAGE VERIFY OK 2026-10-23 01:3x UTC, digest `sha256:ba4efdff…` (registry confirmed), every 2.58 marker at its wanted value, Scout critical+high 0**; image 1.25 GB → 958 MB, 355 → 266 packages; php8.1-fpm still 8.1.2-1ubuntu2.28 |
| Scout on `:dev` (C / H / M / L) | **0 / 0 / 34 / 14 = 48** (was 17 / 491 / 829 / 1,070 = 2,408); 0 fixable; Scout health score D → B; "No fixable critical or high" policy now met |
| Public UI (`/`) and admin UI render without the vendor autoloader | OK: `/` 200, `authenticated.php` 302 to login, admin `/` 200, `getServerHealth` 200; no PHP error shapes in any body; 0 autoload/vendor/Fatal lines in php.log after the swap; served tree has 0 `vendor/autoload` requires and no `vendor/` dir (the same probe on 2.57 read 19 and 1) |
| Container bucket on `:dev`, same volume as 2.57 | First run (bare runner, as 2.57 was tested): 24 passed, 45 failed. After the triage and the runner rebuild in section 5b: **68 passed, 0 failed, 0 unrunnable, exit 0** (2026-10-23 00:45 UTC, `run-oracles.sh --container` end to end, seeded, no hand steps; `test-mod-destinations-live.sh` listed as MANUAL). Repeated on the bumped 2.58 image `ba4efdff` after the version bump: **68 passed, 0 failed, 0 unrunnable, exit 0** (02:19 UTC). The first run on the bumped image lost eight browser tests to the What's New modal, which opens on every admin load after a version change; the seed now marks the image's own version as seen. Standalone 62/62 and cross-repo 4/4 unchanged. |
| Container bucket on 2.57 `:dev` as control, same volume, same invocation | 23 passed, 46 failed. **Failure sets: 45 in common, 0 only on 2.58**, 1 only on 2.57 (`test-boot-sync.sh`, which passes on the freshly booted `:dev`). Both runs had more failures than the 2.57 test run (39) because the control ran while the image was building and the standalone bucket was running; the diff, not the absolute count, is the verdict. The 45 common failures are the known runner limitations listed in `TEST-RUN-2.57-2026-10-21.md`. |
| Crossplay world boots and registers a join code on `:dev` (libparty.so loads) | **OK.** `test123456` set to `start` at 05:16:33 UTC; log lines after that offset: "Joined PlayFab Party network", "Session \"test123456\" registered with join code 911324" at 05:17:05; no libparty/libpulse/dlopen errors; `ldd libparty.so` resolves all four sonames; `stop` returned the world to `stopped`. |

### Second `:dev` build (2026-10-23 03:00 UTC): the two things the maintainer found watching `:dev`

Both are in CHANGELOG v2.58 ("the catalogue sync bar sat at 80%", "a running world's row is no
taller than a stopped one"); markers v58ar–v58ba; tests `test-modsync-progress.sh` and
`test-admin-row-consolidation.js`, both in the container bucket (now 70).

| Check | Result |
| --- | --- |
| `predict-verify.sh` on the working tree | 684 pass, 0 fail, 220 skip |
| `check-whatsnew.sh` | PASS, v2.58 has 4 items |
| `:dev` build + in-image verify (v58aa–v58ba) | **IMAGE VERIFY OK**, registry digest `sha256:ee93c311…` (confirmed with `imagetools inspect`), image `ba56d81fe216`; Scout critical+high 0 |
| Standalone bucket (62) / cross-repo (4) | 62 passed / 4 passed |
| Container bucket on `:dev` `ee93c311`, same volume, from a shell with NO `NODE_PATH` | **68 passed, 2 failed, 0 unrunnable** (both new tests pass). The two failures are the same outbound-network blip: `test-access-tab-refactor.js` case 7 saw `ReferenceError: Chart is not defined` (Chart.js is loaded from cdn.jsdelivr.net) and `test-steamid-lookup-format.js` timed out waiting for `networkidle` on the same page. Both pass on an immediate rerun (20/20, 6/6) and the CDN answers 200 from dev1. Pre-existing fragility, not this change: the admin dashboard needs the CDN for its charts. |
| Runner defect found by this run | The first bucket run from a background shell reported **30 browser tests UNRUNNABLE**: `run-oracles.sh` applied its Playwright `NODE_PATH` default AFTER sourcing the container env, whose `oracleCheck` resolves Playwright once for the run. Moved above the sourcing; the final run above is the proof (`playwright: ok` with no `NODE_PATH` in the environment). A second attempt died with a bash syntax error because the runner was edited while bash was still reading it; never edit a running script. |
| Live measurements behind the two changes | Sync: run 263 (forced Thunderstore) 44.9 s total, 40.4 s in `resolving deps`; run 261 (boot, incremental) 8.5 s total, 0.5 s in that phase. Rows at 1500 px: stopped modded 60 px, running 82 px, running + update 105 px; Resources column 218 px (a 2x2 regrid clips); after the change a running modded row with an update is 59 px. Progress test sees 16–17 distinct percentages between 80 and 100, never backwards. |

Dev box afterwards: `phvalheim-dev` recreated on image `ba56d81fe216` (`:dev` `ee93c311`), same
volume, ports and env; engine running, no world left running. A scratch container
`phv-mutate-test-2579806` (created 2026-09-30, on `:rc`) predates this work and was left alone.

### Third `:dev` build (2026-10-23 18:16 UTC): the update badge and player counts

From the maintainer's screenshot of `ee93c311`: the Settings badge stayed "1" after he updated
the world and named no mod; the pill's player count was wrong. CHANGELOG v2.58 has both sections
("the update badge outlived the update", "player counts are opt-in"); migration
`dbUpdate_2.58.sh`; markers v58bb–v58bk; tests `test-update-badge-after-update.sh`,
`test-player-counts-setting.sh`, gate cases in `test-playerMonitor.sh`, served-HTML and named-mod
assertions in `test-admin-row-consolidation.js`.

| Check | Result |
| --- | --- |
| `predict-verify.sh` on the working tree | 692 pass, 0 fail, 222 skip (v56cp now wants 2: the opt-in gate writes the same "unknown" shape) |
| `check-whatsnew.sh` | PASS, v2.58 has 6 items |
| `:dev` build + in-image verify (v58aa–v58bk) | **IMAGE VERIFY OK**, registry digest `sha256:c93caa00…`, image `e121a0b73cdd`; Scout critical+high 0 |
| Standalone (62) / cross-repo (4) | 62 passed / 4 passed (after `test-playerMonitor.sh`'s harness learned to switch counts on) |
| Container bucket on `:dev` `c93caa00`, no `NODE_PATH` in the environment | Run 1: **63 passed, 9 failed** — one cause: the badge test's REAL world update arms the "client update required" overlay (`settings.clientUpdateNoticeState=1`, z-index 1073), which then intercepted every click in nine later browser tests. The seed now dismisses it and the badge test's post-hook re-dismisses it. Run 2: **71 passed, 1 failed, 0 unrunnable** — `test-mods-hub.js` required every Configs-picker row to carry `&mod=`, which a world with an "Unmatched config files" bucket (left by the same real rebuild) legitimately does not; its own next assertion checks exactly that shape. Fixed the assertion; 30/30 alone. All four new/changed tests pass in the bucket. |
| Bug found on the way | `getWorldsData()` in `admin/index.php` never copied the player/update fields into its row literal, so every server-rendered PLAYERS/UPDATE item (and the 2.58 pill count and badge) was empty on page load until the first 5-second poll. The row test now reads the served HTML before any JavaScript runs. |
| Probable cause of the wrong count (2.59) | `playerMonitor` reads the last PlayFab "now N player(s)" line from the whole log history rather than from the current session (`startNo`), so a count from before a restart survives it. Counts are off by default until that is fixed. |

Dev box afterwards: `phvalheim-dev` recreated on image `e121a0b73cdd` (`:dev` `c93caa00`), same
volume; engine running, no world running; `playerCountsEnabled=0`; the client-update overlay
dismissed. The fixture world `midgard` was genuinely rebuilt by the badge test (its mods are
current, `update_mods_list` empty).

### Fourth `:dev` build (2026-10-23 23:09 UTC): real player telemetry, version repair, supervisor program

From the maintainer's production server (running `:dev`): a count under
an "Updating" pill; a badge reading `CraftFromContainers 3.5.8 -> "3.5.8"`; and the verdict that
the count must be real data from the Companion. CHANGELOG v2.58 has the three sections; Companion
1.0.5 (repo `phvalheim-companion`, uncommitted: `PlayerTelemetry.cs`, `Main.cs`, csproj,
`test-publicizer-trap.sh`); migration revised; markers v58bl–v58by; markers `of`/`og`/v56co
retired to assert the log parser's absence; v56cp wants 4.

| Check | Result |
| --- | --- |
| Prod evidence | `mods` row 6165 `micolho/CraftFromContainers` has `latest_version` `"3.5.8"` with literal quotes (0x22); the only such row on prod; the package is delisted so no sync rewrites it. |
| Companion members, real dedicated-server assembly | `ZNet.GetPlayerList`, `IsDedicated`, `IsServer`, `instance`, `PlayerInfo.m_name`, `m_characterID` all PUBLIC (apiProbe `=` queries); added to the publicizer-trap list. |
| `predict-verify.sh` / `check-whatsnew.sh` | 704 pass, 0 fail / PASS, 8 items |
| `:dev` build + in-image verify (v58aa–v58by) | **IMAGE VERIFY OK**, digest `sha256:94187572…`, image `811ad27ec662`; Scout critical+high 0. (The first build of this round pushed the same digest and failed only on the three retired markers; the rebuild differed in `dev_tools/` alone, so the digest is identical.) |
| Standalone (63) / cross-repo (4) | 63 passed / 4 passed (`test-companion-bundled.sh` sees the new DLL) |
| Live on the dev box before the build | `test-world-player-telemetry.sh` 14/14: the Companion wrote `player_stats.json` 30 s after a real start, count 0, source `companion`, pill "Running · 0" in the served HTML, cleared on stop. `test-playerMonitor.sh` 18/18, `test-player-counts-setting.sh` 9/9, `test-startworld-args.sh` 11/11. |
| Container bucket on `:dev` `94187572`, no `NODE_PATH` | Run 1: **71 passed, 2 failed** — the row test lost its count between widths (a cron tick of the new file-based monitor cleared a database-only fixture: the fixture is now the roster file, with counts enabled for the run) and `test-vanilla-seed.sh`'s control paired the newest `.fwl2` (now midgard's, really started by the telemetry test) with a modded world's input seed (it now picks a vanilla world's file). Run 2: **72 passed, 1 failed** — `test-backend-session-scoped.sh`'s "falls back to the column" case read a `.running-options` file left by a real start earlier in the run (by design that file wins over the column); the test now parks it. Each fix verified alone (50/50, 9/9, 9/9). Run 3 (2026-10-24 00:0x–00:4x UTC): **73 passed, 0 failed, 0 unrunnable, exit 0**. |
| Found on the way | The dev volume's `midgard` had no supervisor program → the engine now regenerates a missing one at start. That exposed a pre-existing bug: `createSupervisorWorldConfig()` wrote `startWorld.sh $worldName $worldPassword $worldPort` unquoted and `startWorld.sh` demanded three arguments, so a no-password world's regenerated program failed "Missing arguments". Now `startWorld.sh "name" "port"`; the script accepts both shapes; the password is no longer written to disk there. |

Dev box afterwards: `phvalheim-dev` on image `811ad27ec662` (`:dev` `94187572`), same volume; engine
running, no world running; `playerCountsEnabled` stored 0 on this volume (operator value; the
column DEFAULT is 1 now). The maintainer's production server also stores 0 from the earlier revision
and must be switched on under Server Settings → Player Counts to see the real count; its
worlds pick up Companion 1.0.5 at their next update or Rebuild Mods.

### Fifth build — the UI still described the old count (2026-10-24 00:36 UTC)

Finding (maintainer): the per-world "Show players on the public page" help still read "The number
is read from the world's log on a best-effort basis — Valheim provides no live count — so it can
lag a disconnect by up to ten minutes and is labelled approximate." That sentence, and six others
like it, described the 2.47 log scrape the fourth build deleted. Rewritten: both pill titles (PHP
and JS twins), the per-world option help, the Automatic Updates footnote and its Quiet For tip, the
Server Settings → Player Counts section (which also lost its BETA badge and a stale "Off by
default" — the default has been ON since the fourth build), and the public card's players row
(no more "· approximate"). Historical copies in the 2.47 What's New and the 2.47 design doc are
left as history. The row test's assertion was re-anchored on the new wording and its fixture no
longer plants `player_count_source='log'`, a value nothing writes any more (the stricter assertion
caught it: only the first width failed, because the cron tick overwrote the fixture's row from the
roster file). Markers v58bz (old claims left in the served admin + public PHP, want 0; the 2.57
file scores 7) and v58ca (Companion credited, want 3).

| Check | Result |
| --- | --- |
| `predict-verify.sh` / `check-whatsnew.sh` | 706 pass, 0 fail / PASS, 8 items |
| Deployed PHP on the dev box before the build | `test-admin-row-consolidation.js` 50/50 (after the fixture fix; 49/50 before it), `test-public-card-render.js` 15/15 |
| `:dev` build + in-image verify (v58aa–v58ca) | **IMAGE VERIFY OK**, digest `sha256:c7ba6d5a…`, image `89ce6581ef84`; Scout critical+high 0; `count wording: log/approximate claims left=0 (want 0)  Companion credited=3 (want 3)` |
| Standalone (63) / cross-repo (4) | 63 passed / 4 passed |
| Container bucket on `:dev` `c7ba6d5a`, no `NODE_PATH` | **73 passed, 0 failed, 0 unrunnable** (first run) |

Dev box afterwards: `phvalheim-dev` on image `89ce6581ef84` (`:dev` `c7ba6d5a`), same volume, engine
running, no world running, `playerCountsEnabled` still stored 0 here and on production.

### Sixth build — the maintainer's seven items (2026-10-24 03:11 UTC)

1. **Player Counts switch retired** — `dropColumn settings playerCountsEnabled` in `dbUpdate_2.58.sh`;
   the Server Settings section, the API field, the Updates-tab "off" branch and playerMonitor's gate are
   gone. Markers v58bd/be/bf/bg/bk/bt now want 0, v58cl wants the drop, v58cm wants no `countsEnabled`
   in the monitor, v56cp wants 3.
2. **Logs badge never cleared** — root cause read off production: watermark 669, newest event 6429,
   acks posted (nginx log) and ignored. `GREATEST(logsSeenEventId, ?)` with a PDO string-bound id
   compares as strings in MySQL (`GREATEST(669,'6429')` = `'669'`). Replaced by a per-event
   `phv_events.acked` (backfilled from the watermark), world-scoped acks from the world log view,
   acks on load/visibility from the Logs page (it used to ack only when new events arrived while
   visible). `test-events-ack.sh` plants watermark 999 vs four-digit ids; its control shows the
   string comparison on this MySQL.
3. **Auto-update warnings** — BETA badges off both surfaces (and the per-world `<summary>`), the red
   paragraphs rewritten around the Companion's count; a vanilla world's Updates tab says its players
   cannot be counted and to use a window + "Update anyway" (`getWorldAutoUpdateSettings()` carries
   `vanilla`). The 2.55 marker v55eo (wanted both badges) and `test-autoupdate-idle-gate.sh` inverted.
   NOTE: `predict-verify.sh` SKIPs v55eo (its definition spans lines) — the first build of this round
   failed only on it; the rebuild reproduced digest `743ba4b2` and verified OK.
4. **Pill says what it is** — tooltip leads with "N players online"; the number carries a player glyph
   (`Running [person]3`). A middot + glyph + spaces clipped the pill at 1280/1557 px (the row test
   caught it: `clipped=true`); the glyph is the separator now.
5. **Issue #93** — real: `last_operation` / `world_activity` / `server_activity` return arrays and
   `aiConverse()` ran `strlen()` on them. `aiRunTool()` wraps `aiRunToolRaw()` and encodes non-strings.
   `test-hugin-tool-results.sh` runs every read-only tool in the container.
6. **Update modal + running worlds** — Update is a door on a running row; modal (three steps, player
   warning from the row's count) → `worldAction cmd=update` → live `getEvents` stream → verdict from
   `getOperations`. The first cut compared against the newest op id only and matched an older, closed
   update (bucket run: log stopped at 2 lines while the update went on); it now remembers every
   pre-click op id. `test-update-modal.js` really starts, updates and waits for the world.
7. **Delete modal** — what goes, backups kept, name typed back; `test-delete-modal.js` intercepts the
   worldAction so nothing is deleted.

| Check | Result |
| --- | --- |
| `predict-verify.sh` / `check-whatsnew.sh` | 719 pass, 0 fail / PASS, 13 items |
| Deployed on the dev box before the build | hugin 8/8, events-ack 12/12, delete-modal 15/15, updates-tab-vanilla 13/13, update-modal 21/21 (44 live lines, world back running), telemetry 14/14, row 55/55, public card 15/15, playerMonitor 17/17 |
| `:dev` build + in-image verify (v58aa–v58co) | **IMAGE VERIFY OK**, digest `sha256:e1b6c663…`, image `54114c7a7d24`; Scout critical+high 0 (two earlier builds this round: `743ba4b2` failed v55eo then verified OK after retiring it; `e1b6c663` carries the modal verdict fix) |
| Standalone (63) / cross-repo (4) | 63 passed / 4 passed |
| Container bucket on `:dev` `e1b6c663`, no `NODE_PATH` (77 tests) | **77 passed, 0 failed, 0 unrunnable** (first run on the final image; the run on `743ba4b2` had 76/1 — the modal-verdict bug fixed above) |

Dev box afterwards: `phvalheim-dev` on image `54114c7a7d24` (`:dev` `e1b6c663`), same volume, engine
running, no world running; the migration dropped `settings.playerCountsEnabled` and added
`phv_events.acked` on this volume (production gets both at its next pull).

Screenshots for the maintainer: `/tmp/phv-shots/` on dev1 (delete modal, update modal stopped/running,
Updates tab vanilla, Server Settings, running row pill).

Verdict: no regression attributable to the change; the Hub list for this image will read 0C 0H 34M 14L.

Dev box state afterwards: `phvalheim-dev` runs `:dev` `0d9d463b` (2.58 WIP, still labelled 2.57) on
the original `phvalheim-dev-data` volume; the previous 2.57 image `b9b5148290b7` is still local for
a rollback; `phvalheim-dev-old` (stopped) and `phvalheim-dev-data-bak-20261021` are the 2.57
leftovers, unchanged. The working tree is NOT committed: version bump, What's New entry and
CHANGELOG are the maintainer's call.

## 5b. The container bucket, made into a gate (same release)

The 2.58 validation above rested on "same failures as 2.57". That was never a verdict: the
container bucket had 34 failures that nobody owned, and a regression hidden among them would
have been invisible. Triaged on 2026-10-22, every one of the 34:

| Verdict | Count | What it was |
| --- | --- | --- |
| **Product bug** | 1 | The mod-sync log pane (2.56): the panel's live render emitted no pane element, so the pane VANISHED for the whole of every sync and reappeared complete at the end; every status repaint rendered it empty until an un-awaited fetch returned; overlapping polls appended the same lines twice (a 53-line run rendered as 83). All three fixed in `admin/index.php`, measured in a real browser before and after (0 lines during a 40 s run → tracks the API line for line; 83 → 53). Markers v58am–v58aq. |
| Test bugs | 5 | `test-empty-accesslist-modal.js` assumed `test2` was enforced-but-empty (it gained a citizen); two layout tests never dismissed the correct enforced-but-empty heads-up; `test-analytics-payload-size.sh` read a payload path the script's hardening had moved under a cleanup trap, then grepped a 400-byte display head for JSON; `test-world-stats-clear.js` asserted a TPS readout on a vanilla world's card. |
| Stale tests | 4 | `test-duplicate-plugin.sh` asserted the BepInEx loader in the plan (removed by 2.44; rebuilt around a package both catalogues carry); `test-plugin-unzip-parent-dir.sh` grepped a variable renamed on 2026-10-02; `test-create-access-guards.sh` counted Server Health's Steam probe id as a placeholder; `test-admin-crossplay-launch.sh` asserted the abandoned `-joincode` link; `test-mods-hub.js` asserted pre-2.56 wording. |
| Harness could not drive them | 24 | Arguments, fixtures, in-container execution, a scratch container, the Steam bypass, the image under test. Now provided by `dev_tools/oracle-container-env.sh` (seed / per-test args / special runners / preconditions), sourced by `run-oracles.sh --container`. The five Hugin suites booted their own container from `:rc` by default and so were validating the released image; they now take the image under test. |
| Inherently manual | 1 | `test-mod-destinations-live.sh` diffs against a pre-2.53 checkout; marked `requires: manual`, listed by `--list`, never counted as passed. |

Also found: `run-oracles.sh` never exported `NODE_PATH`, so 27 browser tests failed with
"Cannot find module 'playwright'" and read as product failures. A test whose precondition the
host cannot provide is reported UNRUNNABLE and fails the bucket; the seed prints what to fix.

Driving the 24 for the first time surfaced a second layer of rot, all in tests or fixtures:

- **The Hugin suites seeded "running" through `worlds.status`.** The readers moved to `mode`
  in 2.50, so every "already running" case saw a stopped world; and a process-less
  `mode='running'` is reconciled to stopped by the engine within two seconds, so the fixture
  can only hold while the engine is stopped in the throwaway container. Fixed in
  `ai-e2e/actions.php`, `ai-e2e/capabilities.php`, `test-ai-actions.sh`,
  `test-ai-actions-mutations.sh`, `test-proposal-ui.sh`. The actions suite went from 10 failures
  to 75/75; the mutation suite from "refusing to run" to 12 caught, 0 missed (it also exposed
  that the replay test was green with both replay guards removed, because re-validation refused
  the replay for a different reason; the suite now requires the guard's own wording).
- `test-ai-e2e.sh` waited for exactly two `ai_*` tables; 2.45 has four.
- `test-ai-telemetry.sh` captured the payload at a `rm -f` line that no longer exists and ran
  the pusher before the engine had minted `analyticsUUID`.
- `test-config-migration-delete.php` used "the first world", whose parked folder now holds
  fixtures; it creates a scratch world row of its own.
- The migration review's Delete button is server-wide, so the gate test deleted the orphan
  test's fixture; parked fixtures are now created and removed per test (pre/post hooks).
- The one-time Access-ID notice, re-armed for its test and left armed when that test failed,
  covered every later Access-tab save (the toggle test read as a broken save); a post-hook
  disarms it unconditionally.
- `test-accesslists.sh` inserted its world with `mode='idle'`, a value no reader knows; that
  world then rendered with no resource readouts and broke the stats test's pick.
- `test-crossplay-join-modal.js` read the FIRST join-code cell on the page, not the driven
  card's; `test-public-card-render.js` looked for a mixed modded/vanilla row at one fixed
  width; two harness waits of 5 s were too short for a dashboard with seven worlds under load.
- `docker cp` keeps the host file's 0660 mode, so the in-container PHP tests were unreadable
  by the `phvalheim` user they run as.

## 6. Operator notes (draft CHANGELOG text)

**Image slimmed; Docker Hub vulnerability list down from 2,408 to the base image's residue.**
The image no longer carries kernel headers (`linux-libc-dev`, 2,290 findings including every
critical), the `software-properties-common` Python stack, the GitHub CLI, `git`, `ping`, or a
132 MB composer tree that nothing used. Nothing operators see changes: crossplay still has its
PulseAudio runtime libraries, Steam still installs from multiverse, the mod system's Python is
still there. The build now pulls a current base image and refuses to publish if Docker Scout
finds a critical or high finding in the pushed image.
