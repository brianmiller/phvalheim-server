#!/bin/bash

docker stop phvalheim-server
docker rm -f phvalheim-server
docker rmi -f theoriginalbrian/phvalheim-server:rc
docker run -d \
  --name phvalheim-server \
  -p 8080:8080 \
  -p 8081:8081 \
  --restart unless-stopped \
  theoriginalbrian/phvalheim-server:rc

docker exec -it phvalheim-server /bin/bash

