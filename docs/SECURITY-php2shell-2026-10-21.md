# PHP2Shell (CVE-2026-109618, CVE-2026-109619) and PhValheim Server

Checked 2026-10-21 after a Discord report pointing at
<https://sansec.io/research/php2shell>.

## Verdict

**Every shipped PhValheim Server image is vulnerable, including 2.56 on `:rc` and
`:latest`.** The flaw is in the PHP interpreter, not in PhValheim's code, and it is reachable
before any PhValheim code runs. The fix is a rebuilt image; operators cannot fix it from inside
a running container except by upgrading the `php8.1-*` packages by hand.

## What the bug is

Two bugs in PHP's own request handling, chained by a public exploit tool ("PHP2Shell"):

- CVE-2026-109618: out-of-memory handling interrupts PHP while request data is only partially
  initialised, and request cleanup then treats the invalid data as valid.
- CVE-2026-109619: request-owned state survives after its memory is freed, and the next request
  on the same worker uses it.

Every known trigger is a `multipart/form-data` body. PHP parses that body before the
application sees the request, so an application whose own code is clean is still exposed.
Under PHP-FPM the chain needs a fatal error after startup, reached through `session_start()`
or `unserialize()`; PhValheim's public pages call `session_start()` (includes/session_auth.php),
so the FPM path applies. Both CVEs are CVSS 9.2, unauthenticated, and exploitation was seen in
the wild before the patch shipped.

## What we ship

| Fact | Value |
| --- | --- |
| Base image | `ubuntu:jammy` (22.04) |
| PHP in every current tag (`:rc`, `:latest`, `:2.56`, `:dev`) | `php8.1-fpm 8.1.2-1ubuntu2.26` |
| Ubuntu's fixed package for jammy | `php8.1 8.1.2-1ubuntu2.28` (jammy-security, available 2026-10-21) |
| Exposure | Port 8080 (public UI, internet-facing by design) and 8081 (admin, documented as private) both hand `.php` to the same php-fpm pool |

PHP upstream no longer supports 8.1, but Canonical patched jammy's package, so no PHP major
upgrade is needed for this fix.

Why 2.56 shipped the old package although the fix was in the repo at build time:
`dev_tools/buildRcDetached.sh` builds with the layer cache on, so the `apt-get upgrade` at the
top of the Dockerfile only re-runs when `ubuntu:jammy` itself changes. A plain rebuild would have
produced the same vulnerable interpreter.

## What changed (uncommitted in the working tree, built to `:dev`)

1. **Dockerfile**: a late `ARG SECURITY_REFRESH` + `apt-get update && apt-get upgrade` layer
   after the apt installs, with `dpkg --compare-versions` asserting `php8.1-fpm >= 8.1.2-1ubuntu2.28`.
   The build fails if the interpreter is still vulnerable. Everything before it stays cached.
2. **dev_tools/buildRcDetached.sh**: passes `--build-arg SECURITY_REFRESH=$(date -u +%F)`, so the
   upgrade layer re-runs once per UTC day; and three verify markers (`v57aa` the package version
   comparison, `v57ab` the nginx rule inside the 8080 block, `v57ac` no such rule in the 8081 block).
   The old `:rc` image fails the updated verify on exactly `v57aa` and `v57ab` (control run).
3. **container/nginx/phvalheim.conf**: the public (8080) server block returns 403 to any request
   with a `multipart/form-data` content type. Nothing public uses multipart: `api.php` reads JSON
   from `php://input`, the pages are GET or urlencoded, and the client never sends multipart. The
   admin block keeps multipart because the file browser and database editor upload with it.
   This is defence in depth for the next multipart-parser bug; the patched PHP is the fix.

Not applied: the article's `memory_limit = -1` / `session.upload_progress.enabled = 0` stopgaps.
They are partial, the first lets one request take all memory, and the real fix is in the image.

## Verified

- A throwaway container from `:rc` with the upgraded packages and the new nginx config: php-fpm
  reports `8.1.2-1ubuntu2.28` on a request served by FPM, `nginx -t` passes, the whole verify
  payload prints `IMAGE VERIFY OK`.
- Live requests: multipart POST to 8080 gets 403 (index.php, api.php, mixed-case content type);
  JSON and urlencoded POSTs to 8080 and multipart POSTs to 8081 are not 403.
- Indicators on the dev container (also `:rc`-based): zero `Allowed memory size`
  fatals in php.log. The maintainer's production instance was not checked at this point.

## What operators must do

Pull the fixed image once it is on `:rc` / `:latest` and recreate the container. Until then,
anyone who can reach port 8080 can run code inside the container as the `phvalheim` user.
An operator who cannot wait can upgrade in place (lost on the next recreate):

```
docker exec <container> sh -c 'apt-get update && apt-get install -y --only-upgrade "php8.1-*" && supervisorctl restart php-fpm8'
```

## Suggested CHANGELOG entry (version bump is Brian's call)

### Security: PHP2Shell (CVE-2026-109618, CVE-2026-109619)

Every image up to 2.56 shipped `php8.1-fpm 8.1.2-1ubuntu2.26`, which carries a pre-auth remote
code execution bug in PHP's multipart parser (public exploit, exploited in the wild). The image
now carries Ubuntu's fixed `8.1.2-1ubuntu2.28`, the build refuses to produce an image with an
older interpreter, and the public UI (8080) rejects `multipart/form-data` bodies outright because
nothing on the public side ever used them. Update now.

## Suggested Discord post

> **Security update, please pull now.** A critical PHP bug (PHP2Shell, CVE-2026-109618 /
> CVE-2026-109619) lets anyone who can reach a PHP site run code on it, before the app's own
> code runs. PhValheim Server images up to 2.56 ship the affected PHP. The fixed image is on
> `:latest` / `:rc`: `docker pull theoriginalbrian/phvalheim-server:latest` and recreate your
> container. Nothing else changes. If you cannot pull yet, run
> `docker exec <container> sh -c 'apt-get update && apt-get install -y --only-upgrade "php8.1-*" && supervisorctl restart php-fpm8'`
> as a stopgap. Details: https://sansec.io/research/php2shell
