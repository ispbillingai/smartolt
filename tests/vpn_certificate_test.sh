#!/usr/bin/env bash
# Exercise actual OpenSSL issuance/verification through the deployed issuer script.
set -euo pipefail
ROOT=$(cd "$(dirname "$0")/.." && pwd)
WORK=$(mktemp -d)
trap 'rm -rf "$WORK"' EXIT
export MSYS2_ARG_CONV_EXCL='/CN='
bash "$ROOT/vpn/issue_server_certificate.sh" "$WORK/pki" vpn.example.com >/dev/null 2>"$WORK/openssl.log"
openssl verify -CAfile "$WORK/pki/ca.crt" -purpose sslserver -verify_hostname vpn.example.com "$WORK/pki/issued/server.crt" >/dev/null
echo 'PASS issued certificate has a trusted chain, server purpose and canonical hostname'
if openssl verify -CAfile "$WORK/pki/ca.crt" -verify_hostname attacker.example.com "$WORK/pki/issued/server.crt" >/dev/null 2>&1; then exit 1; fi
echo 'PASS certificate rejects the wrong VPN hostname'
before=$(sha256sum "$WORK/pki/ca.crt" "$WORK/pki/private/ca.key" "$WORK/pki/private/server.key" "$WORK/pki/issued/server.crt")
bash "$ROOT/vpn/issue_server_certificate.sh" "$WORK/pki" vpn.example.com >/dev/null 2>>"$WORK/openssl.log"
after=$(sha256sum "$WORK/pki/ca.crt" "$WORK/pki/private/ca.key" "$WORK/pki/private/server.key" "$WORK/pki/issued/server.crt")
[ "$before" = "$after" ]
echo 'PASS rerunning setup preserves existing CA, server key and valid certificate'
ca_before=$(sha256sum "$WORK/pki/ca.crt" "$WORK/pki/private/ca.key" "$WORK/pki/private/server.key")
bash "$ROOT/vpn/issue_server_certificate.sh" "$WORK/pki" renamed.example.com >/dev/null 2>>"$WORK/openssl.log"
[ "$ca_before" = "$(sha256sum "$WORK/pki/ca.crt" "$WORK/pki/private/ca.key" "$WORK/pki/private/server.key")" ]
openssl verify -CAfile "$WORK/pki/ca.crt" -verify_hostname renamed.example.com "$WORK/pki/issued/server.crt" >/dev/null
echo 'PASS changing hostname renews leaf without replacing established CA or key'
openssl genrsa -out "$WORK/pki/private/server.key" 2048 >/dev/null 2>>"$WORK/openssl.log"
bash "$ROOT/vpn/issue_server_certificate.sh" "$WORK/pki" renamed.example.com >/dev/null 2>>"$WORK/openssl.log"
[ "$(openssl x509 -in "$WORK/pki/issued/server.crt" -pubkey -noout | openssl sha256)" = "$(openssl pkey -in "$WORK/pki/private/server.key" -pubout | openssl sha256)" ]
echo 'PASS mismatched server key triggers certificate reissue'
rm "$WORK/pki/private/ca.key"
if bash "$ROOT/vpn/issue_server_certificate.sh" "$WORK/pki" vpn.example.com >/dev/null 2>&1; then exit 1; fi
echo 'PASS incomplete CA is refused instead of silently replacing customer trust'
rm "$WORK/pki/ca.crt"
if bash "$ROOT/vpn/issue_server_certificate.sh" "$WORK/pki" vpn.example.com >/dev/null 2>&1; then exit 1; fi
echo 'PASS lost CA cannot silently replace an established installation'
