#!/bin/bash
# Run ONLY in the optional connector container, never in the main app's network.
set -euo pipefail
umask 077
if [ "${PHV_REMOTE_CONNECTOR:-}" != 1 ]; then
    echo 'Connector mode requires its dedicated container configuration.' >&2
    exit 78
fi
# Refuse a shared host/app namespace: the main app must be reachable ONLY by socket.
if [ -S /run/php/php8.1-fpm.sock ] || [ -e /var/run/supervisor.sock ]; then exit 78; fi
install -d -m 0711 -o 0 -g 0 /var/lib/phvalheim-remote /tmp/remote
install -d -m 0700 -o 1001 -g 1001 /var/lib/phvalheim-remote/tailscale /tmp/remote/provider
install -d -m 0775 -o 1000 -g 1000 /run/phvalheim-remote

# Fail closed. NET_ADMIN is required only for this initialization; all capabilities
# are dropped from provider processes. The controller retains only the ability to
# start those processes as a different UID and assign socket/file ownership.
iptables -w -P OUTPUT DROP
iptables -w -P INPUT DROP
iptables -w -F OUTPUT
iptables -w -F INPUT
iptables -w -A INPUT -i lo -j ACCEPT
iptables -w -A INPUT -m conntrack --ctstate ESTABLISHED,RELATED -j ACCEPT
iptables -w -A OUTPUT -m conntrack --ctstate ESTABLISHED,RELATED -j ACCEPT
iptables -w -A OUTPUT -o lo -p tcp -m multiport --dports 8080,20241,1055 -j ACCEPT
# Docker's embedded DNS is the sole exception to the loopback/private block.
for proto in udp tcp; do
    iptables -w -A OUTPUT -d 127.0.0.11 -p "$proto" -m conntrack --ctorigdst 127.0.0.11 --ctorigdstport 53 -j ACCEPT
done
for cidr in 0.0.0.0/8 10.0.0.0/8 100.64.0.0/10 127.0.0.0/8 169.254.0.0/16 172.16.0.0/12 192.0.0.0/24 192.0.2.0/24 192.168.0.0/16 198.18.0.0/15 198.51.100.0/24 203.0.113.0/24 224.0.0.0/4 240.0.0.0/4; do
    iptables -w -A OUTPUT -d "$cidr" -j REJECT
done
# Provider control, HTTPS certificates and DNS. Public UDP permits Tailscale peers
# and Cloudflare QUIC, but never LAN peers; relays remain available for those peers.
iptables -w -A OUTPUT -p tcp -m multiport --dports 443,7844,53 -j ACCEPT
iptables -w -A OUTPUT -p udp -j ACCEPT
# No IPv6 bypass: connector transport is IPv4 in this initial packaging.
ip6tables -w -P OUTPUT DROP
ip6tables -w -P INPUT DROP
ip6tables -w -F OUTPUT
ip6tables -w -F INPUT
printf 'restricted-v1\n' > /tmp/remote/network-policy
exec setpriv --regid=1000 --clear-groups --bounding-set=-all,+setuid,+setgid,+chown,+kill --inh-caps=-all --ambient-caps=-all --no-new-privs \
    python3 /opt/stateless/remote-access/service.py
