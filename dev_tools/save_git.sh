#!/bin/bash

if [ ! "$1" ]; then
	echo "Missing commit comment, exiting..."
	exit 1
fi

git add *
git commit -a -m "$1"
git push --set-upstream origin master
#git push

