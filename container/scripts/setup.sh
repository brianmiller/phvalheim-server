#!/bin/bash

if [ ! -d "/opt/stateful/mysql/data" ]; then
	mkdir -p /opt/stateful/mysql/data
	chown -R /opt/stateful/mysql/data
fi

if [ ! -d "/opt/stateful/mysql/temp" ]; then
        mkdir -p /opt/stateful/mysql/temp
        chown -R /opt/stateful/mysql/temp
fi
