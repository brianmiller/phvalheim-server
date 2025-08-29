#!/bin/bash

if [ ! "$1" ]; then
	echo "Missing commit comment, exiting..."
	exit 1
fi

git pull
git add --verbose *
git commit --verbose -a -m "$1"
git push --verbose --set-upstream

