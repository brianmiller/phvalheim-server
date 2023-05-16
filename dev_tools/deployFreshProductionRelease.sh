#!/bin/bash
containerName="phvalheim-release"

if [ ! "$1" = "latest" ] && [ ! "$1" = "rc" ] || [ "$1" = "-h" ] || [ "$1" = "--help" ]; then

	echo "$1"
	echo "USAGE: ./deployFreshProductionRelease.sh <release tag> -w"
        echo " Example: ./deployFreshProductionRelease.sh latest -w"
        echo " Example: ./deployFreshProductionRelease.sh rc"
	echo " Options: -w, --wipe	delete all stateful data first"
	exit 1
fi

if [ "$2" = "-w" ] || [ "$2" = "--wipe" ]; then
	echo "Stopping container: $containerName"
	podman stop phvalheim-release
        echo "Removing container: $containerName"
        podman rm phvalheim-release
	echo "Deleting stateful data directory: /home/brian/docker_persistent/$containerName"
	sudo rm -rf /home/brian/docker_persistent/$containerName
	echo "Creating stateful data directory: /home/brian/docker_persistent/$containerName"
	mkdir -p /home/brian/docker_persistent/$containerName/backups
else
	echo "Stopping container: $containerName"
	podman stop phvalheim-release
	echo "Removing container: $containerName"
	podman rm phvalheim-release
fi

echo "Creating container: $containerName"

podman create \
       --name="$containerName" \
               -p '9090:8080/tcp' \
               -p '9091:8081/tcp' \
               -p '15000-16000:15000-16000/udp' \
               -e 'basePort'='15000' \
               -e 'defaultSeed'='szN8qp2lBn' \
               -e 'backupsToKeep'='10' \
               -e 'phvalheimHost'='phvalheim-dev.phospher.com' \
               -e 'gameDNS'='37648-dev2.phospher.com' \
               -e 'steamAPIKey'="`cat ../not_git/steamAPIKey.txt`" \
               -e 'phvalheimClientURL'='https://github.com/brianmiller/phvalheim-client/raw/master/published_build/phvalheim-client-installer.exe' \
               -v "/home/brian/docker_persistent/$containerName/":'/opt/stateful':Z \
               -v "/home/brian/docker_persistent/$containerName/backups/":'/opt/stateful/backups':Z \
	       docker.io/theoriginalbrian/phvalheim-server:$1


echo "Starting container: $containerName"

podman start phvalheim-release
