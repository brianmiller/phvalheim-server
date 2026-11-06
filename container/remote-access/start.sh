#!/bin/bash
# Supervised inside the normal PhValheim container. No extra Docker privileges.
set -euo pipefail
umask 077
install -d -m 0711 -o 0 -g 0 /opt/stateful/remote-access /tmp/remote
install -d -m 0700 -o 1001 -g 1001 /opt/stateful/remote-access/tailscale /tmp/remote/provider
install -d -m 0775 -o 1000 -g 1000 /run/phvalheim-remote
# The controller can assign file/process ownership, but cannot administer networks.
# Clients run as UID 1001 with no capabilities and cannot open the control socket.
exec setpriv --regid=1000 --clear-groups --bounding-set=-all,+setuid,+setgid,+chown,+kill --inh-caps=-all --ambient-caps=-all --no-new-privs \
    python3 /opt/stateless/remote-access/service.py
