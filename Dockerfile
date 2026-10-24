# running environment
FROM ubuntu:jammy

# version of this build
ENV phvalheimVersion=2.59

# me
LABEL maintainer="Brian Miller <brian@phospher.com>"

ARG DEBIAN_FRONTEND=noninteractive

# update the container
RUN apt-get -y update
RUN apt-get -y upgrade

# basic tools
RUN apt-get install --no-install-recommends --no-install-suggests -y bash zip unzip supervisor curl vim jq wget language-pack-en rsync ca-certificates bc sudo
# inetutils-ping was here until 2.58; nothing in the engine or the UI runs ping.
RUN apt-get install --no-install-recommends --no-install-suggests -y nginx php-fpm sqlite3 mysql-server php-mysql php-curl cron time
RUN apt-get install --no-install-recommends --no-install-suggests -y lib32gcc-s1
RUN apt-get install --no-install-recommends --no-install-suggests -y gawk sysstat openssh-client zstd
# python3 is REQUIRED, not optional: the mod catalogue sync (engine/tools/modSync.py) and a
# world's mod resolution (engine/tools/worldMods.py) are Python. It was previously present
# only as a transitive dependency of software-properties-common, which would have made the
# entire mod system vanish the day that package stopped pulling it in.
# Standard library only -- the image has no pip and no Python MySQL driver, and modSync.py
# talks to the database through the mysql client on purpose.
RUN apt-get install --no-install-recommends --no-install-suggests -y python3-minimal python3

# The GitHub CLI (gh) was installed here until 2.58. Nothing in the image ever ran it: the
# client-release lookup is a plain HTTPS call from PHP (includes/git.php). It carried a Go
# binary with its own unfixable findings (docker/cli, golang.org/x/crypto) and pulled in git.

# steam stuff
# ubuntu:jammy ships /etc/apt/sources.list with the multiverse lines already enabled, so the
# `add-apt-repository multiverse` that ran here until 2.58 was a no-op that cost
# software-properties-common and ~90 dependencies (python3-cryptography, PyJWT, httplib2,
# oauthlib, PackageKit, polkit, dbus, gstreamer), the second-largest block of the Docker Hub
# vulnerability list. The grep states the assumption: the build fails HERE, not at steamcmd,
# if a future base image stops enabling multiverse.
RUN grep -qE '^deb .* jammy multiverse' /etc/apt/sources.list
RUN dpkg --add-architecture i386
RUN apt-get update
RUN echo steam steam/license note '' | debconf-set-selections
RUN echo steam steam/question select "I AGREE" |debconf-set-selections
RUN apt-get install --no-install-recommends --no-install-suggests -y steamcmd

# crossplay deps. Valheim's PlayFab Party library (valheim_server_Data/Plugins/libparty.so)
# links libpulse.so.0, libpulse-simple.so.0, libpulse-mainloop-glib.so.0 and libatomic.so.1:
# RUNTIME libraries. Until 2.58 this installed libpulse-dev, whose libglib2.0-dev -> libc6-dev
# -> linux-libc-dev chain put the kernel's userspace headers in the image, and Docker Hub
# counted every open kernel CVE against them: 2,290 of the 2,408 findings on 2.57, including
# all 17 criticals, were that one header package (kernel code this container never runs).
# The ldconfig loop is the proof the four sonames still resolve; the build fails here if the
# runtime packages ever stop providing them, instead of crossplay worlds failing to boot.
RUN apt-get install --no-install-recommends --no-install-suggests -y libpulse0 libpulse-mainloop-glib0 libatomic1
RUN for so in libpulse.so.0 libpulse-simple.so.0 libpulse-mainloop-glib.so.0 libatomic.so.1; do ldconfig -p | grep -qE "^[[:space:]]*$so \(libc6,x86-64\)" || { echo "crossplay: $so does not resolve"; exit 1; }; done

# Security refresh. The build is CACHED (dev_tools/buildRcDetached.sh), so the apt-get upgrade
# at the top only re-runs when ubuntu:jammy itself changes: 2.56 shipped php8.1-fpm
# 8.1.2-1ubuntu2.26 while jammy-security already carried the fix for the PHP2Shell pre-auth
# RCE (CVE-2026-109618, CVE-2026-109619; fixed in 8.1.2-1ubuntu2.28). A different
# SECURITY_REFRESH value invalidates every layer from here on (the build script passes the UTC
# date), and the build FAILS outright if php8.1-fpm is still older than the fix, so a cached or
# offline build cannot ship the vulnerable interpreter again by accident.
ARG SECURITY_REFRESH=2026-10-21
RUN apt-get -y update && apt-get -y upgrade && dpkg --compare-versions "$(dpkg-query -W -f='${Version}' php8.1-fpm)" ge 8.1.2-1ubuntu2.28

# small prep stuff
RUN echo "set mouse-=a" > /root/.vimrc
RUN useradd phvalheim -d /opt -s /bin/bash

# system path
ENV PATH=/usr/local/sbin:/usr/local/bin:/usr/sbin:/usr/bin:/sbin:/bin:/opt/stateful/games/steam_home/.steam/steamcmd:/opt/stateless/engine:/opt/stateless/engine/tools:/opt/stateless/games/valheim/scripts

# run and copy some stuff
RUN mkdir -p /opt/stateless/supervisor.d
RUN mkdir -p /opt/stateless/nginx/www
RUN mkdir -p /opt/stateless/engine
RUN mkdir -p /tmp/dumps
RUN mkdir -p /var/lib/php/sessions && chown phvalheim:phvalheim /var/lib/php/sessions && chmod 700 /var/lib/php/sessions
RUN touch /var/log/cron.log
COPY container/supervisor.d/ /opt/stateless/supervisor.d/
COPY container/supervisor/supervisord.conf /etc/supervisor/supervisord.conf
COPY container/nginx/nginx.conf	/etc/nginx/nginx.conf
COPY container/nginx/phvalheim.conf /etc/nginx/sites-available/phvalheim.conf
COPY container/nginx/www/ /opt/stateless/nginx/www/
COPY container/engine/ /opt/stateless/engine/
COPY container/games/ /opt/stateless/games/
COPY container/games/valheim/macos/libdoorstop.dylib /opt/stateless/games/valheim/macos/libdoorstop.dylib
COPY container/games/valheim/custom_plugins/ZeroBandwidth-CustomSeed /opt/stateless/games/valheim/custom_plugins/ZeroBandwidth-CustomSeed
# The PhValheim Companion ships IN THE IMAGE from 2.53, not through Thunderstore. It is
# PhValheim's own infrastructure -- it talks to the PhValheim backend and reads PhValheim
# config, so it has no audience outside this server -- and bundling it means the server and
# the Companion are one artifact that cannot drift apart. See docs/RELEASE-2.53-DESIGN.md 9.
COPY container/games/valheim/custom_plugins/PhValheimCompanion /opt/stateless/games/valheim/custom_plugins/PhValheimCompanion
COPY container/php-fpm/php.ini /etc/php/8.1/fpm/php.ini
COPY container/php-fpm/www.conf /etc/php/8.1/fpm/pool.d/www.conf
COPY container/php-fpm/php-fpm.conf /etc/php/8.1/fpm/php-fpm.conf
COPY container/mysql/my.cnf /etc/mysql/my.cnf
COPY container/mysql/init-file /etc/mysql/init-file
COPY container/mysql/client.cnf /etc/mysql/conf.d/client.cnf
RUN chmod 644 /etc/mysql/init-file
RUN chmod 644 /etc/mysql/conf.d/client.cnf
RUN chmod 644 /etc/mysql/my.cnf
COPY container/cron.d/* /etc/cron.d/

RUN chown -R phvalheim: /opt/stateless
RUN chown -R phvalheim: /run/php

# Allow phvalheim user to apply timezone changes at runtime (via sudo)
RUN echo "phvalheim ALL=(root) NOPASSWD: /opt/stateless/engine/tools/applyTimezone.sh" > /etc/sudoers.d/phvalheim-tz && chmod 440 /etc/sudoers.d/phvalheim-tz

# enable PhValheim within NGINX
RUN ln -s /etc/nginx/sites-available/phvalheim.conf /etc/nginx/sites-enabled/phvalheim

# dooyet
CMD ["/opt/stateless/engine/tools/startSupervisord.sh"]
