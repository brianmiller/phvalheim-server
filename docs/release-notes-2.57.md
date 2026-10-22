**Security release. Update now.** Every PhValheim Server image up to 2.56 shipped a PHP build
affected by **PHP2Shell** (CVE-2026-109618, CVE-2026-109619): a critical bug in PHP itself that
lets anyone who can reach the public UI (port 8080) run code inside the container, before any
PhValheim code runs. A public exploit tool exists and attacks were seen in the wild before the
fix was published. Pull this image and recreate your container; nothing else changes for you or
your players.

```
docker pull theoriginalbrian/phvalheim-server:latest
```

## What this image does about it

- **PHP is patched.** The image carries Ubuntu's fixed `php8.1 8.1.2-1ubuntu2.28`. PHP upstream
  no longer patches 8.1, but Ubuntu backports security fixes to its own package, and that is the
  one PhValheim uses.
- **The build cannot regress.** The reason 2.56 shipped a vulnerable PHP although the fix was
  already available is that the image build is cached, so its system upgrade step had not re-run
  in weeks. The Dockerfile now has a late, date-keyed upgrade step and refuses to produce an image
  whose PHP is older than the fix.
- **The public UI rejects file-upload style requests outright.** Every known PHP2Shell trigger
  is a `multipart/form-data` body. Nothing on the public side ever used one (the client talks
  JSON), so nginx now returns 403 to them on port 8080 before PHP sees the body. The admin UI
  (8081) keeps uploads for the file browser and database editor; keep that port private, as
  always.

If you cannot pull yet, this patches a running container in place. It is lost the next time you
recreate the container, so still pull 2.57 afterwards:

```
docker exec <container> sh -c 'apt-get update && apt-get install -y --only-upgrade "php8.1-*" && supervisorctl restart php-fpm8'
```

## Also in this release

**A restarted crossplay world no longer flashes its previous session's join code.** On a restart
the dashboard showed a code at once, then "code waiting", then the real code. The first one was
dead: Valheim's log still ended with the old session until the game printed its start line, and
PhValheim read it as current. The engine now marks where each session begins in its own event
log, and the dashboard reads only what Valheim logged after that mark. If the log has been
rotated since the mark, the 2.56 behaviour applies unchanged, so this cannot bring back the
"waiting for its join code" bug that 2.56 fixed.

## Testing

Standalone suite 62/62 and cross-repo contracts 4/4. The container-driven suite was run against
this image and, as a control, against 2.56 on the same data volume (a 2.5x-era volume with six
worlds, upgraded in place): no failure is unique to 2.57. The join-code change was proven against
the real database with the real engine mark. `docs/TEST-RUN-2.57-2026-10-21.md` has the full
breakdown. Not exercised end to end: a real crossplay world restarting under the new join-code
reader in production; the live proof drove the PHP reader and the engine's mark function directly.
