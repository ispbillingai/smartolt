#!/bin/bash
# manage_router_site.sh  (hotspot AP / .biz version)
# Usage: manage_router_site.sh <sub><routerId> <router_tunnel_ip>
#   e.g. manage_router_site.sh demo5 16.0.0.6
#
# Creates a per-router Apache reverse-proxy vhost for {name}.ispledger.click on
# port 80 + every port 200-400, each proxying to the router's OVPN tunnel IP,
# then creates the {name}.ispledger.click DNS A record on DigitalOcean.
#
# Requires on the .biz box: apache2 with mod_proxy + mod_proxy_http enabled,
# and `Listen 200` ... `Listen 400` in ports.conf. Deploy this at /root/.
set -e

NAME="$1"                       # e.g. demo5   ({sub}{routerId})
IP="$2"                         # router OVPN tunnel IP, e.g. 16.0.0.6
SERVER_IP="194.238.26.130"      # .biz web server public IP (DNS A record target)
PORT_START=200
PORT_END=400
API_KEY="YOUR_DIGITALOCEAN_API_TOKEN"  # DigitalOcean API token (must manage ispledger.click) — set the real token on the server's /root/ copy, keep it out of git
DOMAIN="ispledger.click"

# basic validation
if [[ ! "$NAME" =~ ^[a-z0-9-]+$ ]]; then echo "[ERROR] bad name"; exit 1; fi
if [[ ! "$IP" =~ ^[0-9.]+$ ]]; then echo "[ERROR] bad ip"; exit 1; fi

CONF="/etc/apache2/sites-available/${NAME}.conf"

{
  echo "###############################################################################"
  echo "# ${NAME}.${DOMAIN} — WebFig reverse-proxy + custom maintenance page"
  echo "###############################################################################"
  echo ""

  # Block HTTPS access (port 443)
  echo "################################  HTTPS (blocked)  ############################"
  echo "<VirtualHost *:443>"
  echo "    ServerName ${NAME}.${DOMAIN}"
  echo ""
  echo "    <Location />"
  echo "        Order deny,allow"
  echo "        Deny from all"
  echo "    </Location>"
  echo ""
  echo "    ErrorDocument 403 \"HTTPS is not supported for this subdomain. \\"
  echo "To access your MikroTik or your routers, remove the s on https:// so that the link \\"
  echo "starts with http://.\""
  echo "</VirtualHost>"
  echo ""

  # Port 80 VirtualHost
  echo "###############################  Port 80  #####################################"
  echo "<VirtualHost *:80>"
  echo "    ServerName ${NAME}.${DOMAIN}"
  echo ""
  echo "    ProxyPreserveHost On"
  echo "    ProxyPass /custom_503.html !"
  echo "    ProxyPass / http://${IP}:80/"
  echo "    ProxyPassReverse / http://${IP}:80/"
  echo ""
  echo "    ProxyErrorOverride On"
  echo "    ErrorDocument 503 /custom_503.html"
  echo "</VirtualHost>"
  echo ""

  # VirtualHost blocks for ports 200 to 400
  for p in $(seq $PORT_START $PORT_END); do
    echo "###############################  Port ${p}  ####################################"
    echo "<VirtualHost *:${p}>"
    echo "    ServerName ${NAME}.${DOMAIN}"
    echo ""
    echo "    ProxyPreserveHost On"
    echo "    ProxyPass /custom_503.html !"
    echo "    ProxyPass / http://${IP}:${p}/"
    echo "    ProxyPassReverse / http://${IP}:${p}/"
    echo ""
    echo "    ProxyErrorOverride On"
    echo "    ErrorDocument 503 /custom_503.html"
    echo "</VirtualHost>"
    echo ""
  done
} > "$CONF"

a2ensite "${NAME}.conf"
systemctl reload apache2
echo "[OK] Site ${NAME}.${DOMAIN} enabled and Apache reloaded."

# Create the subdomain using DigitalOcean API — idempotent: skip if it exists,
# so retries (site reconciliation) never fail or create duplicate records.
FQDN="${NAME}.${DOMAIN}"
EXISTING=$(curl -s \
  -H "Authorization: Bearer ${API_KEY}" \
  "https://api.digitalocean.com/v2/domains/${DOMAIN}/records?name=${FQDN}&type=A")

if echo "$EXISTING" | grep -q "\"name\":\"${NAME}\""; then
    echo "[OK] DNS record ${FQDN} already exists — skipping creation"
else
    curl -s -X POST \
      -H "Content-Type: application/json" \
      -H "Authorization: Bearer ${API_KEY}" \
      -d "{\"type\":\"A\",\"name\":\"${NAME}\",\"data\":\"${SERVER_IP}\",\"ttl\":3600}" \
      "https://api.digitalocean.com/v2/domains/${DOMAIN}/records" > /tmp/do_response.log
    if grep -q '"domain_record"' /tmp/do_response.log; then
        echo "[OK] Subdomain ${FQDN} created pointing to ${SERVER_IP}"
    else
        echo "[ERROR] Failed to create subdomain ${FQDN}: $(cat /tmp/do_response.log)"
        rm -f /tmp/do_response.log
        exit 1
    fi
    rm -f /tmp/do_response.log
fi
