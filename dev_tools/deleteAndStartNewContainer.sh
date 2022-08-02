#!/bin/bash

podman stop 37648-phvalheim1
#sudo rm -rf running_container/mysql
podman rm 37648-phvalheim1
sh dev_tools/buildit.sh
sh dev_tools/runit.sh
podman start 37648-phvalheim1
sh dev_tools/open_docker_shell_running.sh 37648-phvalheim1
