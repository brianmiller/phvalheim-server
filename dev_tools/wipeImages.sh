#!/bin/bash

echo "Stopping phvalheim..."
podman stop 37648-phvalheim1


echo "Reading in all images..."
IDs=$(podman images|grep -v "REPOSITORY"|tr -s " "|cut -d " " -f3)

for ID in $IDs; do
	echo "Deleting image '$ID'"
	podman rmi -f $ID
done

echo "Done."

