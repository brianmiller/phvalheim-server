# PhValheim Server 2.61 — player portal security fixes

Release 2.61 is a security release for the player portal on port 8080. Update promptly.
No configuration, template, client or Companion change is needed. Take a consistent backup
of application data before updating, as with every release.

Image: `theoriginalbrian/phvalheim-server:dev` (candidate; the stable digest is recorded here
when 2.61 is promoted).

Reported by a community member on Discord on 2026-11-03. Thank you.

## What was wrong

**Steam sign-in was not verified.** The portal read the Steam ID straight out of the
`openid_claimed_id` parameter of the sign-in callback and never asked Steam whether it had
issued that response. Anyone could open `/authenticated.php?openid_claimed_id=.../<SteamID64>`
and the portal treated them as that player: their accessible worlds, any password the
operator chose to publish on the portal, and their Steam profile name and avatar. Valheim's
own access list still checked the real Steam ID at join time, so this was information
disclosure, not a way into a game. The admin interface on port 8081 was not affected.

Reviewing the rest of the public surface found three more problems in the same area:

- `api.php?mode=getMyWorldsStatus` accepted a `steamID` query parameter when there was no
  session, "for backward compatibility". Nothing ever used it, and it handed any visitor any
  player's world list and launch links without signing in at all.
- The `/stateful/` download location aliased the whole `/opt/stateful/games` tree. Directory
  listing was off, but paths are predictable from a world name, so each world's
  `permittedlist.txt`, `adminlist.txt`, `bannedlist.txt`, its save files once it had run, its
  BepInEx configs and the Steam home directory were downloadable without a session.
- Several database lookups on the public side interpolated the world name or the Steam ID
  into SQL. With the identity unverified, that was reachable by anyone.

## What changed

- The sign-in callback is posted back to Steam (OpenID 2.0 `check_authentication`) and a
  session is created only for a response Steam confirms it signed, for this site's own
  callback URL, naming a Steam profile with a 17-digit SteamID64. A failed check returns the
  player to the sign-in page with a notice. Steam honours each response once, so a captured
  callback cannot be replayed. Logic lives in `includes/steam_openid.php`.
- `getMyWorldsStatus` answers 401 without a session. The portal page's own poll is unchanged.
- nginx serves exactly `/stateful/valheim/worlds/<world>/<world>.zip` and
  `<world>-config.zip` and answers 404 for everything else under `/stateful/`. These are the
  only two URLs PhValheim Client requests, unchanged since they were introduced, so every
  shipped client keeps working.
- Every world lookup in `includes/db_gets.php` binds its parameters. `api.php` refuses a world
  name outside `[A-Za-z0-9_-]` with HTTP 400 before any query runs; the admin UI has always
  restricted names to that set, so no real request changes. The session store refuses
  anything that is not a SteamID64.
- Steam persona names, avatar URLs and world names are escaped before they are printed.
- The public server block sends `X-Content-Type-Options: nosniff`, `X-Frame-Options:
  SAMEORIGIN` and `Referrer-Policy: strict-origin-when-cross-origin`; `server_tokens` is off.
- The session cookie is marked `Secure` when the request arrived over TLS (directly or via
  `X-Forwarded-Proto: https`). It is deliberately not unconditional: operators serving port
  8080 over plain HTTP would otherwise lose sign-in entirely.
- The sign-in page loads the repository's jQuery 3.6.0 instead of jQuery 2.1.3 from a CDN.

## Not in this release

- No Content Security Policy. The portal page uses inline handlers and CDN fonts, and a
  policy strict enough to be worth having would break it. Planned separately.
- No rate limiting on sign-in or the API.
- The admin block on 8081 keeps its full `/stateful/` alias; it is documented as private.

## Operator notes

- The Steam API key is unchanged and still required for the portal to show player names.
- The container must be able to reach `https://steamcommunity.com` outbound for sign-in to
  succeed. It already needed that for the Steam Web API; this is the same host.
- If players report "Steam did not confirm that sign-in", check outbound HTTPS from the
  container and the PHP error log (`/opt/stateful/logs/php.log` carries the rejection line).

## Verification

A real Steam account completed the sign-in round trip against this image on a production
server (37648-phvalheim1) on 2026-11-04, and the forged-callback and download-alias checks
behaved as described there.

See `docs/WORKBENCH-2.61-VALIDATION.md` for the evidence collected against the shipped image,
including the forged-login and download-alias checks run against the previous image as a
control, and the end-to-end run of PhValheim Client 2.0.17 against the candidate.
