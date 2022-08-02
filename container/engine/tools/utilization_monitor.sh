#!/bin/bash


cpuModel=$(cat /proc/cpuinfo |grep "model name"|head -1|tr -s " "|cut -d " " -f3-)
cpuCores=$(cat /proc/cpuinfo |grep "model name"|wc -l)
cpuTotalMHz=$(cat /proc/cpuinfo |grep "cpu MHz"|cut -d " " -f3|paste -sd+ - | bc)
memTotal=$(cat /proc/meminfo |grep MemTotal|tr -s " "|cut -d " " -f2)
memTotal=$(echo $memTotal/1024|bc)


echo "CPU Model: $cpuModel"
echo "CPU Cores: $cpuCores"
echo "Total MHz in system: $cpuTotalMHz MHz"
echo "Total Memory in system: $memTotal MB"


#if [ "$2" = "mem" ]; then
#	ps -o size -p $1 --sort -size|tail -1|tr -s " "
#fi
