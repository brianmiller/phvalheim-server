# 2.61 validation workbench — player-portal security fixes

Candidate: `theoriginalbrian/phvalheim-server:dev`, registry digest
`sha256:bfadfa9563d23e85719dd2743b4537a0b69fcc313621ed7173b441f34f7d317a`, image id
`65b59caf7da5`, built 2026-11-04 00:14 UTC from commit `b6bd703c` (tests revised in `13a73c5b`,
which does not change the image: `dev_tools/` is not shipped). Detached build printed
`IMAGE VERIFY OK`; Docker Scout critical+high 0. Promoted 2026-11-04 01:05 UTC: the
`TAG=rc EXTRA_TAGS="2.61 latest"` rebuild verified clean and came out at the SAME digest, so
`rc`, `2.61`, `latest` and `dev` all resolve to `sha256:bfadfa95…`; `2.60` still `sha256:c63614b8…`.

Scope: the Discord report (unverified Steam sign-in) plus what the review of the 8080
surface found. See `docs/RELEASE-NOTES-2.61.md` for the operator-facing description.

## Gates before the build

| Gate | Result |
|---|---|
| `php dev_tools/test-steam-openid-verify.php` (verifier logic, fake Steam) | 31 pass, 0 fail |
| `bash dev_tools/test-public-ui-hardening.sh` (tree oracles) | 28 pass; **on the pre-change tree: 4 pass, 24 fail** |
| `dev_tools/check-whatsnew.sh` | PASS, 4 items for 2.61 |
| `bash dev_tools/predict-verify.sh` | 796 pass, 0 fail, 242 skip (the three nginx-path markers are image-only) |
| `dev_tools/run-oracles.sh --standalone` | 77 passed, 0 failed, 0 unrunnable |
| `test-public-security-integration.py`, source overlaid on the 2.60 image | 54 pass, 0 fail |
| **Negative control**: the same test against `:2.60`, no overlay | **20 pass, 34 fail** |

The 34 control failures are the findings, observed live on the released image: the bare
`openid_claimed_id` callback redirected INTO the portal with a signed-in session; a full
assertion with a forged signature did the same; `getMyWorldsStatus&steamID=` answered
`success:true` with the world list and launch strings and no session; `getMyWorlds()` with
`' OR 1=1 -- ` returned every world; `getLaunchString()` with an injected name returned a
launch string; `adminlist.txt`, `permittedlist.txt`, the save file, `.running-options` and the
Steam home token were all served 200 under `/stateful/`; `Server: nginx/1.18.0 (Ubuntu)`;
no hardening headers; jQuery from the CDN.

## Exact-image verification

`PHV_SEC_TEST_IMAGE=theoriginalbrian/phvalheim-server:dev@sha256:bfadfa95…
python3 dev_tools/test-public-security-integration.py` — **54 pass, 0 fail**
(`/tmp/phv261-integration-image.log`). A disposable container and volume; the engine is
stopped before fixtures are created so nothing downloads Steam or starts a game. Highlights:

- bare claimed_id → 302 to `index.php?auth=failed`; the session left behind is not signed in;
  the rejection is logged to `/opt/stateful/logs/php.log`.
- full-shaped assertion with a forged signature → Steam itself answers `is_valid:false`
  (precondition check confirms the container reached `steamcommunity.com`).
- `getMyWorldsStatus` without a session → 401; with the dev bypass → success with the world.
- `getMD5` for a real world → its md5; `world=secw' OR '1'='1` → 400; path-shaped name → 400;
  Companion JSON with an injected name → 400; with a real name and prefab → `true` and the
  bound UPDATE landed.
- `getMyWorlds` with an injected id or a bare `%` → no rows; the real id → `secw` only.
- `/stateful/`: `secw/secw.zip` and `secw/secw-config.zip` → 200 with the payload bytes;
  `other.zip`, access lists, save, `.running-options`, `steam_home/token`, directories → 404.
- headers nosniff / SAMEORIGIN / referrer on pages and static assets; `Server: nginx`;
  cookie HttpOnly + SameSite=Lax, not Secure on plain HTTP, Secure with `X-Forwarded-Proto: https`.
- `companion.php` without a token → 403 (unchanged).
- Dev bypass still renders the portal (200, red banner, the player's card) and does not weaken
  the callback (forged callback still refused).

Pre-existing, not changed here: `api.php` answers an unknown boss prefab with HTTP 200 and an
empty body on both 2.60 and this image (the registry comment says it should print `false`).

## Deployment to `phvalheim-dev` (dev1)

`IMAGE=65b59caf7da5 dev_tools/devUp.sh 76561198000000001` on the original
`phvalheim-dev-data` volume, same ports and Steam bypass. `phvalheimVersion=2.61`; all five
core services RUNNING; the 7 world rows (name, mode, port, md5) identical before and after;
every changed served file and `phvalheim.conf` byte-identical to the tree. Smoke: portal 200,
forged login 302 → `index.php?auth=failed`, `acltest` adminlist via the alias 404,
`midgard.zip` 200 (5,775,300 bytes). The portal rendered in Chrome with six world cards, six
launch links, jQuery 3.6.0, and no console errors. ("Welcome, !" plus the SteamAPI warning is
the bypass's fake id, as before.)

## End to end with the shipped client

`dev_tools/test-client-e2e-public.py` runs the published
`phvalheim-client-2.0.17-universal-x86_64.tar.gz` (sha256 `89d518b24db4e261…`) inside a
disposable Ubuntu container on the host network, with the client repo's own stub Steam and
stub Valheim, against the real server. Launch string taken from the real
`getMyWorldsStatus` (bypass session), world `midgard`.

- Against the current 2.60 `phvalheim-dev` first (harness proof): **28 pass, 0 fail**.
- Against the 2.61 candidate on `phvalheim-dev`: **28 pass, 0 fail** (`/tmp/phv261-e2e-dev-261.log`).

What the client did on 2.61, as nginx logged it: `api.php` (reachability) 200,
`getSyncState&configPolicy=1&world=midgard` 200 (×3), `getMD5` 200,
`/stateful/valheim/worlds/midgard/midgard.zip` 200 (5,775,300 bytes),
`/stateful/valheim/worlds/midgard/midgard-config.zip` 200 (2,625 bytes, on the second,
config-only launch after the sync record was marked stale). No request under `/stateful/`
was answered 404. The downloaded zip's md5 equals the server's `world_md5`; the payload was
unpacked (BepInEx tree, doorstop in the game's environment); the stub Valheim received
`--phvalheim-launch <launch string>` intact and was started in its own directory, twice.

## Not covered

- A genuine Steam sign-in: **observed 2026-11-04.** Brian pulled this digest onto the
  production server 37648-phvalheim1 and completed a real Steam OpenID round trip; it
  worked. Before that the accept path rested on the fake-Steam unit test and the reject path
  on Steam's live `is_valid:false`.
- No live Valheim or Companion; the e2e uses stubs for both, as client CI does.
- Windows and macOS clients were not run; they share `Syncer.cs` and the same two URLs.

## Logs

`/tmp/phv261-standalone.log`, `/tmp/phv261-integration-overlay.log`,
`/tmp/phv261-integration-control-260.log`, `/tmp/phv261-integration-image.log`,
`/tmp/phv261-e2e-dryrun-260.log`, `/tmp/phv261-e2e-dev-261.log`, `/tmp/phvalheim-rc-build.log`.
