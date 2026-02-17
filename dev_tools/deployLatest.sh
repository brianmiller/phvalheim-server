#!/bin/bash
. ../secrets.cfg


docker stop phvalheim-server
docker rm -f phvalheim-server
#docker rmi -f theoriginalbrian/phvalheim-server:rc

docker run -d \
  --name phvalheim-server \
 -p 8082:8080 \
  -p 8083:8081 \
  -p 25000-25050:25000-25050/udp \
  -v /opt/phvalheim-test:/opt/stateful:Z \
  --restart unless-stopped \
  theoriginalbrian/phvalheim-server:latest

docker exec -it phvalheim-server /bin/bash

