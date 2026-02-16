# running environment
FROM ubuntu:jammy

# version of this build
ENV phvalheimVersion=2.30

# me
LABEL maintainer="Brian Miller <brian@phospher.com>"

ARG DEBIAN_FRONTEND=noninteractive

# update the container
RUN apt-get -y update
RUN apt-get -y upgrade

# basic tools
RUN apt-get install --no-install-recommends --no-install-suggests -y bash zip unzip supervisor curl vim jq wget language-pack-en rsync ca-certificates bc
RUN apt-get install --no-install-recommends --no-install-suggests -y nginx php-fpm sqlite3 mysql-server php-mysql php-curl cron inetutils-ping time
RUN apt-get install --no-install-recommends --no-install-suggests -y lib32gcc-s1
RUN apt-get install --no-install-recommends --no-install-suggests -y gawk sysstat openssh-client

# github cli
RUN curl -fsSL https://cli.github.com/packages/githubcli-archive-keyring.gpg | dd of=/usr/share/keyrings/githubcli-archive-keyring.gpg
RUN chmod go+r /usr/share/keyrings/githubcli-archive-keyring.gpg
RUN echo "deb [arch=$(dpkg --print-architecture) signed-by=/usr/share/keyrings/githubcli-archive-keyring.gpg] https://cli.github.com/packages stable main" | tee /etc/apt/sources.list.d/github-cli.list > /dev/null
RUN apt update
RUN apt install --no-install-recommends --no-install-suggests -y gh

# steam stuff
RUN apt-get update
RUN apt-get install --no-install-recommends --no-install-suggests -y software-properties-common
RUN add-apt-repository multiverse
RUN dpkg --add-architecture i386
RUN apt-get update
RUN echo steam steam/license note '' | debconf-set-selections
RUN echo steam steam/question select "I AGREE" |debconf-set-selections
RUN apt-get install --no-install-recommends --no-install-suggests -y steamcmd

# crossplay deps
RUN apt-get install --no-install-recommends --no-install-suggests -y libpulse-dev libatomic1 libc6

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
COPY container/games/valheim/custom_plugins/ZeroBandwidth-CustomSeed /opt/stateless/games/valheim/custom_plugins/ZeroBandwidth-CustomSeed
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

# enable PhValheim within NGINX
RUN ln -s /etc/nginx/sites-available/phvalheim.conf /etc/nginx/sites-enabled/phvalheim

# dooyet
CMD ["/opt/stateless/engine/tools/startSupervisord.sh"]
