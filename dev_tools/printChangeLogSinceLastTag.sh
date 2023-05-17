#!/bin/bash

if [ ! $1 ]; then
        echo "USAGE: printChangeLogSinceLastTag.sh <tag>"
        echo " Example: printChangeLogSinceLastTag.sh 2.10"
        exit 1
fi

echo "- **Commits related to this release**"
git log $1..HEAD --oneline|sed -s 's/^/  - ``/'|sed -s 's/$/``/'
