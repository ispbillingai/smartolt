#!/bin/sh
# OpenVPN auth-user-pass-verify (via-file): $1 holds the username on line 1 and the password on line 2.
# Logins live in /var/lib/smartolt-vpn/users as "<username> <sha256 of the password>", written by smartolt-vpn.
f="$1"
user=$(sed -n 1p "$f")
pass=$(sed -n 2p "$f")
case "$user" in *[!a-z0-9-]*|'') exit 1 ;; esac
want=$(awk -v u="$user" '$1 == u { print $2 }' /var/lib/smartolt-vpn/users)
[ -n "$want" ] || exit 1
got=$(printf '%s' "$pass" | sha256sum | cut -d' ' -f1)
[ "$got" = "$want" ]
