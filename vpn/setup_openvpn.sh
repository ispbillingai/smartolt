#!/bin/bash
# One time on the SmartOLT box, as root: the OpenVPN server that ISPs' MikroTik routers dial so
# SmartOLT can reach an OLT that has no public IP.
#   sudo bash vpn/setup_openvpn.sh
#
# Each OLT gets its own login and a fixed tunnel IP (17.0.x.y), made by /usr/local/sbin/smartolt-vpn
# when the ISP chooses "Through the SmartOLT VPN" for an OLT. On the router a dst-nat sends
# <tunnel IP>:2323 to the OLT's own IP port 23. 17.0.0.0/16 is used, not 16.0.0.0/16, so a router that
# also has the Hotspot AP tunnel (16.x) does not get two routes for one network.
# Safe to run again: existing keys, logins and fixed IPs are kept.

set -euo pipefail
[ "$EUID" -eq 0 ] || { echo "Run as root"; exit 1; }
HERE="$(cd "$(dirname "$0")" && pwd)"
DIR=/etc/openvpn/server
PKI=/etc/openvpn/smartolt-pki

apt-get install -y -q openvpn easy-rsa netcat-openbsd >/dev/null

# Keys: a CA and the server certificate. Routers log in with a username and password, no client cert.
if [ ! -f "$PKI/pki/issued/server.crt" ]; then
    rm -rf "$PKI"
    make-cadir "$PKI"
    cd "$PKI"
    ./easyrsa --batch init-pki
    EASYRSA_REQ_CN="SmartOLT VPN CA" ./easyrsa --batch build-ca nopass
    ./easyrsa --batch --days=3650 build-server-full server nopass
    # MikroTik RouterOS 6 needs classic Diffie Hellman
    ./easyrsa --batch gen-dh
    cd - >/dev/null
fi

# Logins and fixed IPs under /var/lib: the sites' PHP-FPM runs with /etc read-only (ProtectSystem).
DATA=/var/lib/smartolt-vpn
mkdir -p "$DATA/ccd" /var/log/openvpn
chmod 700 "$DATA"
install -m 600 "$PKI/pki/ca.crt" "$DIR/smartolt-ca.crt"
install -m 600 "$PKI/pki/issued/server.crt" "$DIR/smartolt-server.crt"
install -m 600 "$PKI/pki/private/server.key" "$DIR/smartolt-server.key"
install -m 600 "$PKI/pki/dh.pem" "$DIR/smartolt-dh.pem"
touch "$DATA/users"
chmod 600 "$DATA/users"

install -m 755 "$HERE/smartolt-auth.sh" "$DIR/smartolt-auth.sh"
install -m 755 "$HERE/smartolt-fw.sh" "$DIR/smartolt-fw.sh"
install -m 750 "$HERE/smartolt-vpn" /usr/local/sbin/smartolt-vpn
# The SmartOLT sites run as www-data and may only call this one helper as root.
echo 'www-data ALL=(root) NOPASSWD: /usr/local/sbin/smartolt-vpn' > /etc/sudoers.d/smartolt-vpn
chmod 440 /etc/sudoers.d/smartolt-vpn
visudo -cf /etc/sudoers.d/smartolt-vpn >/dev/null

cat > "$DIR/smartolt.conf" <<'EOF'
# SmartOLT VPN. Written by vpn/setup_openvpn.sh in the smartolt repo.
port 1194
proto tcp-server
dev tun-smartolt
dev-type tun
topology subnet
server 17.0.0.0 255.255.0.0
# Only logins made by smartolt-vpn (each has a ccd file with its fixed IP) may connect.
client-config-dir /var/lib/smartolt-vpn/ccd
ccd-exclusive
ca /etc/openvpn/server/smartolt-ca.crt
cert /etc/openvpn/server/smartolt-server.crt
key /etc/openvpn/server/smartolt-server.key
dh /etc/openvpn/server/smartolt-dh.pem
verify-client-cert none
username-as-common-name
script-security 2
auth-user-pass-verify /etc/openvpn/server/smartolt-auth.sh via-file
up /etc/openvpn/server/smartolt-fw.sh
# RouterOS 6 only does AES CBC with SHA1 and does not negotiate ciphers.
data-ciphers AES-256-GCM:AES-256-CBC:AES-128-CBC
data-ciphers-fallback AES-256-CBC
auth SHA1
keepalive 10 60
persist-key
persist-tun
management /run/smartolt-vpn-mgmt.sock unix
status /var/log/openvpn/smartolt-status.log 20
status-version 2
verb 3
EOF

systemctl enable --now openvpn-server@smartolt >/dev/null 2>&1 || true
systemctl restart openvpn-server@smartolt
sleep 2
systemctl is-active openvpn-server@smartolt
ss -ltn | grep -q ':1194 ' && echo "OpenVPN listening on TCP 1194"
