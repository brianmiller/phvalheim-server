**PhValheim Server 2.57 is out. This one is a security release, please update now.** 🔒

A critical bug in PHP itself (**PHP2Shell**, CVE-2026-109618 / CVE-2026-109619) lets anyone who can reach a PHP site run code on it, before the app's own code runs. There's a public exploit tool and it's being used in the wild. Every PhValheim Server image up to 2.56 shipped the affected PHP, so if you're running one, your public port (8080) is exposed.

**What to do:**
```
docker pull theoriginalbrian/phvalheim-server:latest
```
then recreate your container. Nothing else changes for you or your players.

**What 2.57 does:**
- Ships Ubuntu's patched PHP (8.1.2-1ubuntu2.28). PHP upstream dropped 8.1, but Ubuntu backports security fixes to it, and that's what PhValheim uses.
- The build itself now refuses to produce an image with a vulnerable PHP, so this can't quietly come back.
- The public UI (8080) now rejects file-upload style (`multipart/form-data`) requests outright. Every known exploit uses them and nothing on the public side ever did.
- Bonus fix: restarting a crossplay world no longer flashes the previous session's dead join code before the real one appears.

**Can't pull right now?** This patches a running container in place (it's lost when you recreate, so still pull 2.57 after):
```
docker exec <container> sh -c 'apt-get update && apt-get install -y --only-upgrade "php8.1-*" && supervisorctl restart php-fpm8'
```

And as always: keep the admin port (8081) off the internet.

Details: https://sansec.io/research/php2shell
Release: https://github.com/brianmiller/phvalheim-server/releases/tag/v2.57
