#!/usr/bin/env bash
# Local fixtures only. No real VPN, firewall, router, root paths or services are touched.
set -euo pipefail
ROOT=$(cd "$(dirname "$0")/.." && pwd)
WORK=$(mktemp -d)
trap 'rm -rf "$WORK"' EXIT
mkdir -p "$WORK/data/ccd" "$WORK/bin"
touch "$WORK/data/users"
printf '10.253\n' > "$WORK/data/network"
for file in "$ROOT"/vpn/*.sh "$ROOT"/vpn/smartolt-vpn; do bash -n "$file"; done
echo 'PASS VPN shell syntax'
# The production helper keeps fixed trusted paths. Rewrite a private test copy, never the real helper.
sed -e "s|^DATA=.*|DATA=\"$WORK/data\"|" \
    -e "s|^STATUS=.*|STATUS=\"$WORK/status\"|" \
    -e "s|^PARSER=.*|PARSER=\"$ROOT/vpn/smartolt-vpn-status.awk\"|" \
    -e "s|/run/smartolt-vpn.lock|$WORK/lock|" \
    -e "s|/run/smartolt-vpn-mgmt.sock|$WORK/no-socket|g" "$ROOT/vpn/smartolt-vpn" > "$WORK/helper"
printf '#!/bin/sh\nexit "${TEST_SERVICE_EXIT:-0}"\n' > "$WORK/bin/systemctl"
printf '#!/bin/sh\nexit 0\n' > "$WORK/bin/flock"
chmod +x "$WORK/bin/"*
export PATH="$WORK/bin:$PATH"
if bash "$WORK/helper" trust >/dev/null 2>&1; then exit 1; fi
printf 'vpn.example.com\n' > "$WORK/data/server-name"
cp "$ROOT/tests/fixtures/vpn-ca.pem" "$WORK/data/ca.crt"
trust=$(bash "$WORK/helper" trust)
[[ "$trust" = vpn.example.com* && "$trust" = *'BEGIN CERTIFICATE'* && "$trust" != *'PRIVATE KEY'* ]]
echo 'PASS trust helper exposes only canonical hostname and public CA'
hash=$(printf 'test-password' | sha256sum | cut -d' ' -f1)
[ "$(bash "$WORK/helper" add demo-olt1 "$hash")" = 10.253.1.1 ]
[ "$(bash "$WORK/helper" add demo-olt2 "$hash")" = 10.253.1.2 ]
[ "$(bash "$WORK/helper" add demo-olt1 "$hash")" = 10.253.1.1 ]
[ "$(wc -l < "$WORK/data/users")" -eq 2 ]
echo 'PASS allocation is private, unique and stable on repeated provisioning'
if bash "$WORK/helper" add bad/name "$hash" >/dev/null 2>&1; then exit 1; fi
if bash "$WORK/helper" add demo-olt3 badhash >/dev/null 2>&1; then exit 1; fi
echo 'PASS invalid account input is rejected'
cat > "$WORK/status" <<'EOF'
TITLE,OpenVPN 2.6
HEADER,CLIENT_LIST,Common Name,Real Address,Virtual Address,Virtual IPv6 Address,Bytes Received,Bytes Sent,Connected Since,Connected Since (time_t),Username
CLIENT_LIST,demo-olt1,198.51.100.7:12345,10.253.1.1,,100,200,2026-11-05 12:34:56,1793882096,demo-olt1
END
EOF
[ "$(bash "$WORK/helper" status demo-olt1)" = 'connected 198.51.100.7 2026-11-05 12:34:56' ]
[ "$(bash "$WORK/helper" status demo-olt2)" = offline ]
echo 'PASS connected router and absent router status'
if TEST_SERVICE_EXIT=3 bash "$WORK/helper" status demo-olt1 >/dev/null 2>&1; then exit 1; fi
echo 'PASS stopped service cannot report connected from an old log'
touch -t 202001010000 "$WORK/status"
if bash "$WORK/helper" status demo-olt1 >/dev/null 2>&1; then exit 1; fi
echo 'PASS stale log cannot report connected'
cat > "$WORK/status" <<'EOF'
HEADER,CLIENT_LIST,Common Name,Real Address,Virtual Address,Bytes Received,Bytes Sent,Connected Since,Connected Since (time_t)
CLIENT_LIST,demo-olt1,[2001:db8::abcd]:12345,10.253.1.1,100,200,2026-11-05 12:34:56,1793882096
END
EOF
[ "$(bash "$WORK/helper" status demo-olt1)" = 'connected 2001:db8::abcd 2026-11-05 12:34:56' ]
echo 'PASS older status layout and IPv6 source address'
sed -i '/^END/d' "$WORK/status"
if bash "$WORK/helper" status demo-olt1 >/dev/null 2>&1; then exit 1; fi
echo 'PASS partial status write is not mistaken for offline'
sed "s|/var/lib/smartolt-vpn/users|$WORK/data/users|" "$ROOT/vpn/smartolt-auth.sh" > "$WORK/auth"
printf 'demo-olt1\ntest-password\n' > "$WORK/credentials"
bash "$WORK/auth" "$WORK/credentials"
printf 'demo-olt1\nwrong-password\n' > "$WORK/credentials"
if bash "$WORK/auth" "$WORK/credentials"; then exit 1; fi
echo 'PASS OpenVPN authentication accepts the matching password only'
bash "$WORK/helper" remove demo-olt1 >/dev/null
[ ! -e "$WORK/data/ccd/demo-olt1" ]
if grep -q '^demo-olt1 ' "$WORK/data/users"; then exit 1; fi
[ "$(bash "$WORK/helper" add demo-olt3 "$hash")" = 10.253.1.1 ]
echo 'PASS account removal and freed address reuse'
rm "$WORK/data/network"
[ "$(bash "$WORK/helper" add demo-olt4 "$hash")" = 17.0.1.1 ]
echo 'PASS existing deployments without network metadata retain legacy addressing'
