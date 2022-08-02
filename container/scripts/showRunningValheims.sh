#!/bin/bash

ps -ef|grep phvalhe+|grep "/opt/stateful/games/valheim/worlds"|grep -v "sh -c"|grep -v tee|tr -s " "|cut -d " " -f3,8
