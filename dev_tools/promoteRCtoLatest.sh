#!/bin/bash

# login
docker login

# purge all local images to ensure a clean state
docker rmi -f theoriginalbrian/phvalheim-server:rc
docker rmi -f theoriginalbrian/phvalheim-server:latest

# pull dev image that will be promoted to latest
docker pull theoriginalbrian/phvalheim-server:rc

# create latest tag from dev image
docker image tag theoriginalbrian/phvalheim-server:rc theoriginalbrian/phvalheim-server:latest

# push to registry
docker push theoriginalbrian/phvalheim-server:latest

