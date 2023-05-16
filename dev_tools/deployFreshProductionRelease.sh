#!/bin/bash

podman stop phvalheim-release
podman rm phvalheim-release

containerName="phvalheim-release"

podman create \
       --name="$containerName" \
               -p '9090:8080/tcp' \
               -p '9091:8081/tcp' \
               -p '15000-16000:15000-16000/udp' \
               -e 'basePort'='15000' \
               -e 'defaultSeed'='szN8qp2lBn' \
               -e 'backupsToKeep'='10' \
               -e 'phvalheimHost'='phvalheim-dev.phospher.com' \
               -e 'gameDNS'='37648-dev2.phospher.com' \
               -e 'steamAPIKey'="`cat ../not_git/steamAPIKey.txt`" \
               -e 'phvalheimClientURL'='https://github.com/brianmiller/phvalheim-client/raw/master/published_build/phvalheim-client-installer.exe' \
               -v "/home/brian/docker_persistent/$containerName/":'/opt/stateful':Z \
               -v "/home/brian/docker_persistent/$containerName/backups/":'/opt/stateful/backups':Z \
	       docker.io/theoriginalbrian/phvalheim-server:rc

podman start phvalheim-release
