#!/bin/bash
#
# NOT FOR RELEASES. This retags whatever :rc currently contains, which is not necessarily
# the commit you mean -- finishing the docs after a build changes files inside the image, and
# 2.45 shipped that way. It also skips the in-image verify entirely, so nothing checks that
# the release you think you are promoting is actually in there.
#
# Release path: EXTRA_TAGS="X.YZ latest" setsid nohup dev_tools/buildRcDetached.sh &
# That rebuilds from source and promotes only after IMAGE VERIFY OK.
# See .claude/agents/phvalheim-release.md
#
# Kept for hand-driven local work where you know exactly what :rc is.

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

