#!/bin/sh
# Runs when the tunnel comes up ($1 is the device). Routers on the VPN can only answer SmartOLT:
# they may not open connections to this box or to each other. Forwarding stays off on this box.
dev="$1"
set -eu
for chain in INPUT FORWARD; do
    while iptables -D "$chain" -i "$dev" -j SMARTOLT-VPN 2>/dev/null; do :; done
done
iptables -N SMARTOLT-VPN 2>/dev/null || iptables -F SMARTOLT-VPN
iptables -A SMARTOLT-VPN -m conntrack --ctstate ESTABLISHED,RELATED -j ACCEPT
iptables -A SMARTOLT-VPN -p icmp --icmp-type echo-request -j ACCEPT
iptables -A SMARTOLT-VPN -j DROP
iptables -I INPUT 1 -i "$dev" -j SMARTOLT-VPN
# Only this server may initiate OLT requests. Never forward between routers or from another LAN.
while iptables -D FORWARD -i "$dev" -j SMARTOLT-VPN-FWD 2>/dev/null; do :; done
while iptables -D FORWARD -o "$dev" -j SMARTOLT-VPN-FWD 2>/dev/null; do :; done
iptables -N SMARTOLT-VPN-FWD 2>/dev/null || iptables -F SMARTOLT-VPN-FWD
iptables -A SMARTOLT-VPN-FWD -j DROP
iptables -I FORWARD 1 -i "$dev" -j SMARTOLT-VPN-FWD
iptables -I FORWARD 1 -o "$dev" -j SMARTOLT-VPN-FWD
exit 0
