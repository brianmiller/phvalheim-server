#!/bin/bash

if [ ! $1 ]; then
	echo "USAGE: save_dockerhub.sh <tag>"
	echo "USAGE: save_dockerhub.sh latest"
	echo "USAGE: save_dockerhub.sh rc"
	exit 1
fi

docker login --username=theoriginalbrian docker.io
#podman build --format=docker -t theoriginalbrian/phvalheim-server .

if [ "$1" = "latest" ]; then
	docker buildx build -t theoriginalbrian/phvalheim-server:latest .
	docker push theoriginalbrian/phvalheim-server:latest
elif [ "$1" = "rc" ]; then
	docker buildx build -t theoriginalbrian/phvalheim-server:rc .
	docker push theoriginalbrian/phvalheim-server:rc
fi
