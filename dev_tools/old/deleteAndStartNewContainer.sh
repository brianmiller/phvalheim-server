#!/bin/bash

podman stop 37648-phvalheim2

sudo rm -rf /home/brian/docker_persistent/37648-phvalheim2/*
sudo mkdir -p /home/brian/docker_persistent/37648-phvalheim2/backups
sudo chown -R brian: /home/brian/docker_persistent/37648-phvalheim2

podman rm 37648-phvalheim2
sh dev_tools/buildit.sh
sh dev_tools/runit.sh
podman start 37648-phvalheim2
sh dev_tools/open_docker_shell_running.sh 37648-phvalheim2
