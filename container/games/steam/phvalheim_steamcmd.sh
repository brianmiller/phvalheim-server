#!/bin/sh

STEAMHOME=/opt/stateful/games/steamcmd
if [ ! -d $STEAMHOME ]; then
        mkdir -p /opt/stateful/games/steamcmd
        chown -R phvalheim: /opt/stateful/games/steamcmd
fi

if [ ! -e $STEAMHOME/steamcmd ]; then
        mkdir -p $STEAMHOME/linux32
        cp /usr/lib/games/steam/steamcmd.sh $STEAMHOME
        cp /usr/lib/games/steam/steamcmd $STEAMHOME/linux32/
fi

                                                                                                                                                                                
exec $STEAMHOME/linux32/steamcmd $@
