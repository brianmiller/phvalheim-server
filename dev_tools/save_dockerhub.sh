#!/bin/bash

if [ ! $1 ]; then
	echo "USAGE: save_dockerhub.sh <tag>"
	echo "USAGE: save_dockerhub.sh latest"
	echo "USAGE: save_dockerhub.sh rc"
	exit 1
fi

podman login --username=theoriginalbrian docker.io
podman build --format=docker -t theoriginalbrian/phvalheim-server .

if [ "$1" = "latest" ]; then
	podman push theoriginalbrian/phvalheim-server:latest
elif [ "$1" = "rc" ]; then
	podman push theoriginalbrian/phvalheim-server:rc
fi
