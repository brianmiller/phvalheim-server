#Running Environment
FROM ubuntu:focal

#Version of this build
ENV phvalheimVersion=1.8

#Me
LABEL maintainer=brian@phospher.com

ARG DEBIAN_FRONTEND=noninteractive

#Update the container
RUN apt-get -y update
RUN apt-get -y upgrade

#Basic tools
RUN apt-get install --no-install-recommends --no-install-suggests -y bash zip unzip supervisor curl vim jq wget language-pack-en rsync ca-certificates bc
RUN apt-get install --no-install-recommends --no-install-suggests -y nginx php-fpm sqlite3 mysql-server php-mysql cron inetutils-ping time
RUN apt-get install --no-install-recommends --no-install-suggests -y lib32gcc-s1
RUN apt-get install --no-install-recommends --no-install-suggests -y gawk sysstat

#Steam stuff
RUN apt-get update
RUN apt-get install --no-install-recommends --no-install-suggests -y software-properties-common
RUN add-apt-repository multiverse
RUN dpkg --add-architecture i386
RUN apt-get update
RUN echo steam steam/license note '' | debconf-set-selections
RUN echo steam steam/question select "I AGREE" |debconf-set-selections
RUN apt-get install --no-install-recommends --no-install-suggests -y steamcmd

#Small prep stuff
RUN echo "set mouse-=a" > /root/.vimrc
RUN useradd phvalheim

#PATH
ENV PATH=/usr/local/sbin:/usr/local/bin:/usr/sbin:/usr/bin:/sbin:/bin:/opt/stateful/games/steamcmd:/opt/stateless/engine:/opt/stateless/engine/tools:/opt/stateless/games/valheim/scripts

#RUN and COPY
RUN mkdir -p /opt/stateless/supervisor.d
RUN mkdir -p /opt/stateless/nginx/www
RUN mkdir -p /opt/stateless/engine
RUN mkdir -p /tmp/dumps
RUN touch /var/log/cron.log
COPY container/supervisor.d/ /opt/stateless/supervisor.d/
COPY container/supervisor/supervisord.conf /etc/supervisor/supervisord.conf
COPY container/nginx/nginx.conf	/etc/nginx/nginx.conf
COPY container/nginx/phvalheim.conf /etc/nginx/sites-available/phvalheim.conf
COPY container/nginx/www/ /opt/stateless/nginx/www/
COPY container/engine/ /opt/stateless/engine/
COPY container/games/ /opt/stateless/games/
COPY container/games/valheim/custom_plugins/ZeroBandwidth-CustomSeed /opt/stateless/games/valheim/custom_plugins/ZeroBandwidth-CustomSeed
COPY container/php-fpm/php.ini /etc/php/7.4/fpm/php.ini
COPY container/php-fpm/www.conf /etc/php/7.4/fpm/pool.d/www.conf
COPY container/php-fpm/php-fpm.conf /etc/php/7.4/fpm/php-fpm.conf
COPY container/mysql/* /etc/mysql/
COPY container/cron.d/* /etc/cron.d/

RUN chown -R phvalheim: /opt/stateless
RUN chown -R phvalheim: /run/php

#Enable PhValheim within NGINX
RUN ln -s /etc/nginx/sites-available/phvalheim.conf /etc/nginx/sites-enabled/phvalheim

#Dooyet
CMD ["/opt/stateless/engine/tools/startSupervisord.sh"]
#CMD ["/usr/bin/supervisord", "-n", "-c", "/opt/stateless/supervisor.d/supervisord.conf"]
