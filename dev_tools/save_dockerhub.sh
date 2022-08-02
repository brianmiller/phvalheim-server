#!/bin/bash

podman login --username=theoriginalbrian docker.io
podman build -t theoriginalbrian/phvalheim-server .
podman push theoriginalbrian/phvalheim-server:latest
