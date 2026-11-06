#!/bin/bash
# One time on the SmartOLT box, as root: the installer that billing dashboards call when an ISP
# presses "Enable SmartOLT" (https://install.<domain>/install.php). Safe to run again; rerun it after
# pulling a new install.sh so the root copy is updated.
#   sudo bash provisioner/setup.sh
# Needs: /root/smartolt_fleet_secret (shared with the billing dashboards), a DigitalOcean API token for
# the DNS zone in /etc/letsencrypt/digitalocean.ini, and the wildcard certificate for the domain:
#   certbot certonly --dns-digitalocean --dns-digitalocean-credentials /etc/letsencrypt/digitalocean.ini \
#       --cert-name <domain> -d <domain> -d '*.<domain>'
# DNS: install.<domain> must point at this server; each site's record is made by install.php.
set -euo pipefail
[ "$EUID" -eq 0 ] || { echo "Run as root" >&2; exit 1; }
HERE="$(cd "$(dirname "$0")" && pwd)"
DOMAIN="${SMARTOLT_DOMAIN:-ispledger.click}"
IP="${SMARTOLT_IP:-$(ip -4 -o addr show scope global | awk '{print $4}' | cut -d/ -f1 | head -1)}"
HOST="install.$DOMAIN"
WEB=/var/www/smartolt-install
CERT="/etc/letsencrypt/live/$DOMAIN"

[[ "$IP" =~ ^[0-9]{1,3}(\.[0-9]{1,3}){3}$ ]] || { echo "Could not tell this server's public IPv4; set SMARTOLT_IP." >&2; exit 1; }
[ -s /root/smartolt_fleet_secret ] || { echo "Missing /root/smartolt_fleet_secret" >&2; exit 1; }
[ -s "$CERT/fullchain.pem" ] && [ -s "$CERT/privkey.pem" ] || { echo "Missing the wildcard certificate in $CERT (see the certbot line above)." >&2; exit 1; }
DO_TOKEN=$(grep -o 'dop_v1_[0-9a-f]\{64\}' /etc/letsencrypt/digitalocean.ini 2>/dev/null | head -1 || true)
[ -n "$DO_TOKEN" ] || { echo "No DigitalOcean token in /etc/letsencrypt/digitalocean.ini" >&2; exit 1; }

# Root-owned copies: www-data can run the helper but never change what it runs.
install -d -m 755 /usr/local/lib/smartolt
install -m 700 "$HERE/../install.sh" /usr/local/lib/smartolt/install.sh
install -m 750 "$HERE/smartolt-provision" /usr/local/sbin/smartolt-provision
echo 'www-data ALL=(root) NOPASSWD: /usr/local/sbin/smartolt-provision' > /etc/sudoers.d/smartolt-provision
chmod 440 /etc/sudoers.d/smartolt-provision
visudo -cf /etc/sudoers.d/smartolt-provision >/dev/null

# Settings for install.php, readable by the web server only.
umask 027
D="$DOMAIN" A="$IP" T="$DO_TOKEN" S="$(tr -d '\r\n' < /root/smartolt_fleet_secret)" php -r '
    $c = ["domain" => getenv("D"), "ip" => getenv("A"), "do_token" => getenv("T"), "fleet_secret" => getenv("S")];
    file_put_contents("/etc/smartolt-provisioner.php", "<?php\n// Written by provisioner/setup.sh. Not in git.\nreturn " . var_export($c, true) . ";\n");'
chown root:www-data /etc/smartolt-provisioner.php
chmod 640 /etc/smartolt-provisioner.php
umask 022

install -d -o root -g www-data -m 750 "$WEB"
install -o root -g www-data -m 640 "$HERE/install.php" "$WEB/install.php"
install -d -o www-data -g adm -m 750 /var/log/smartolt-provision

FPM_SOCKET=""
version=$(php -r 'echo PHP_MAJOR_VERSION,".",PHP_MINOR_VERSION;')
[ ! -S "/run/php/php$version-fpm.sock" ] || FPM_SOCKET="/run/php/php$version-fpm.sock"
cat > /etc/apache2/sites-available/smartolt-install.conf <<EOF
# SmartOLT installer. Written by provisioner/setup.sh in the smartolt repo.
<VirtualHost *:80>
    ServerName $HOST
    Redirect permanent / https://$HOST/
</VirtualHost>
<VirtualHost *:443>
    ServerName $HOST
    DocumentRoot $WEB
    <Directory $WEB>
        Options -Indexes
        AllowOverride None
        Require all denied
        <Files "install.php">
            Require all granted
        </Files>
    </Directory>
    SSLEngine on
    SSLCertificateFile $CERT/fullchain.pem
    SSLCertificateKeyFile $CERT/privkey.pem
EOF
if [ -n "$FPM_SOCKET" ]; then
    cat >> /etc/apache2/sites-available/smartolt-install.conf <<EOF
    <FilesMatch "\\.php$">
        SetHandler "proxy:unix:$FPM_SOCKET|fcgi://localhost/"
    </FilesMatch>
EOF
fi
cat >> /etc/apache2/sites-available/smartolt-install.conf <<'EOF'
    ErrorLog ${APACHE_LOG_DIR}/smartolt-install-error.log
    CustomLog ${APACHE_LOG_DIR}/smartolt-install-access.log combined
</VirtualHost>
EOF
a2ensite smartolt-install.conf >/dev/null
apache2ctl configtest
systemctl reload apache2

# The wildcard certificate renews through the same DigitalOcean DNS check.
cat > /etc/cron.d/smartolt-certs <<EOF
# Renew the SmartOLT wildcard certificate; Apache reloads only when it was renewed. Written by provisioner/setup.sh.
17 3,15 * * * root certbot renew --cert-name $DOMAIN --quiet --deploy-hook "systemctl reload apache2"
EOF
chmod 644 /etc/cron.d/smartolt-certs

echo "SmartOLT installer ready: https://$HOST/install.php (sites: <sub>.$DOMAIN -> $IP)"
