#!/bin/bash

echo
echo "removing old images..."
cd ../../
docker rmi -f theoriginalbrian/phvalheim-server:rc
docker images -f "dangling=true" -q
docker system prune -af

echo
echo "Building..."
docker buildx build --no-cache -t theoriginalbrian/phvalheim-server:rc .
echo
echo "Pushing to theoriginalbrian/phvalheim-server:rc..."
docker push theoriginalbrian/phvalheim-server:rc
echo

