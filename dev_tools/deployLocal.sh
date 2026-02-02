#!/bin/bash

docker stop phvalheim-server
docker rm -f phvalheim-server
#docker rmi -f theoriginalbrian/phvalheim-server:rc

docker run -d \
  --name phvalheim-server \
  -p 8082:8080 \
  -p 8083:8081 \
  -p 25000-25050:25000-25050 \
  -e 'basePort'='25000' \
  -e 'defaultSeed'='szN8qp2lBn' \
  -e 'phvalheimHost'='phvalheim-dev.phospher.com' \
  -e 'gameDNS'='37648-dev1.phospher.com' \
  -e 'phvalheimClientURL'='https://github.com/brianmiller/phvalheim-client/raw/master/published_build/phvalheim-client-installer.exe' \
  --restart unless-stopped \
  theoriginalbrian/phvalheim-server:rc

docker exec -it phvalheim-server /bin/bash

