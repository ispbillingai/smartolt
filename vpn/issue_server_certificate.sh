#!/usr/bin/env bash
# Issue/renew the server identity without replacing an existing CA or server key.
# Usage: issue_server_certificate.sh <PKI directory> <canonical DNS hostname>
set -euo pipefail
umask 077
pki="${1:?PKI directory required}"
name="${2:?Canonical VPN DNS hostname required}"
[[ "$name" =~ ^([a-zA-Z0-9]([a-zA-Z0-9-]*[a-zA-Z0-9])?\.)+[a-zA-Z]{2,63}$ && ${#name} -le 253 ]] || { echo 'Invalid canonical VPN hostname' >&2; exit 2; }
mkdir -p "$pki/private" "$pki/issued" "$pki/reqs"
if [ ! -f "$pki/ca.crt" ] && [ ! -f "$pki/private/ca.key" ]; then
    [ ! -f "$pki/issued/server.crt" ] || { echo 'Existing server certificate has lost its CA. Restore the original CA before setup.' >&2; exit 3; }
    openssl req -new -x509 -newkey rsa:2048 -nodes -sha256 -days 3650 \
        -subj '/CN=SmartOLT VPN CA' -addext 'basicConstraints=critical,CA:TRUE' \
        -addext 'keyUsage=critical,keyCertSign,cRLSign' \
        -keyout "$pki/private/ca.key" -out "$pki/ca.crt"
fi
[ -s "$pki/ca.crt" ] && [ -s "$pki/private/ca.key" ] || { echo 'Existing VPN CA is incomplete. Restore its certificate/key; do not replace it.' >&2; exit 3; }
openssl x509 -in "$pki/ca.crt" -checkend 86400 -noout >/dev/null || { echo 'VPN CA is expired or near expiry; renew it before setup.' >&2; exit 3; }
if [ ! -s "$pki/private/server.key" ]; then openssl genrsa -out "$pki/private/server.key" 2048; fi
# A matching, valid certificate is reused on reruns.
if [ -s "$pki/issued/server.crt" ] \
    && openssl verify -CAfile "$pki/ca.crt" -purpose sslserver -verify_hostname "$name" "$pki/issued/server.crt" >/dev/null 2>&1 \
    && openssl x509 -in "$pki/issued/server.crt" -checkend 2592000 -noout >/dev/null \
    && [ "$(openssl x509 -in "$pki/issued/server.crt" -pubkey -noout | openssl sha256)" = "$(openssl pkey -in "$pki/private/server.key" -passin pass: -pubout | openssl sha256)" ]; then
    echo 'Existing VPN server certificate is valid'
    exit 0
fi
work=$(mktemp -d "$pki/.issue.XXXXXX")
trap 'rm -f "$work/extensions" "$work/server.csr" "$work/server.crt"; rmdir "$work"' EXIT
cat > "$work/extensions" <<EOF
basicConstraints=critical,CA:FALSE
keyUsage=critical,digitalSignature,keyEncipherment
extendedKeyUsage=serverAuth
subjectAltName=DNS:$name
EOF
openssl req -new -sha256 -key "$pki/private/server.key" -subj "/CN=$name" -out "$work/server.csr"
openssl x509 -req -in "$work/server.csr" -CA "$pki/ca.crt" -CAkey "$pki/private/ca.key" \
    -set_serial "0x$(openssl rand -hex 16)" -days 825 -sha256 -extfile "$work/extensions" -out "$work/server.crt"
openssl verify -CAfile "$pki/ca.crt" -purpose sslserver -verify_hostname "$name" "$work/server.crt"
mv "$work/server.crt" "$pki/issued/server.crt"
