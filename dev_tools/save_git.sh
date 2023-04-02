#!/bin/bash

if [ ! "$1" ]; then
	echo "Missing commit comment, exiting..."
	exit 1
fi

git add --verbose *
git commit --verbose -a -m "$1"
git push --verbose --set-upstream origin master
#git push

