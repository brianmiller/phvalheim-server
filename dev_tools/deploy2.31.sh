#!/bin/bash
# this script is used to test upgrades version prior to 2.31. 2.31, moves environment variables into the database.
. ../secrets.cfg


docker stop phvalheim-server
docker rm -f phvalheim-server
#docker rmi -f theoriginalbrian/phvalheim-server:rc

docker run -d \
  --name phvalheim-server \
  -p 8082:8080 \
  -p 8083:8081 \
  -p 25000-25050:25000-25050/udp \
  -e "geminiApiKey=$geminiApiKey" \
  -e "claudeApiKey=$claudeApiKey" \
  -e "openaiApiKey=$openaiApiKey" \
  -e "ollamaUrl=http://2.2.20.11:11434" \
  -e "steamAPIKey=$steamAPIKey" \
  -e 'basePort'='25000' \
  -e 'defaultSeed'='szN8qp2lBn' \
  -e 'phvalheimHost'='phvalheim-dev.phospher.com' \
  -e 'gameDNS'='37648-dev1.phospher.com' \
  -e 'phvalheimClientURL'='https://github.com/brianmiller/phvalheim-client/raw/master/published_build/phvalheim-client-installer.exe' \
  -v /opt/phvalheim-test:/opt/stateful:Z \
  --restart unless-stopped \
  theoriginalbrian/phvalheim-server:2.31

docker exec -it phvalheim-server /bin/bash
