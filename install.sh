#!/usr/bin/env bash
# Per-ISP install: sudo bash install.sh <subdomain>. Existing completed sites are preserved.
set -euo pipefail
umask 027
[[ "$EUID" -eq 0 && "$#" -eq 1 ]] || { echo "Usage: sudo bash install.sh <subdomain>" >&2; exit 2; }
SUBDOMAIN="$1"
[[ "$SUBDOMAIN" =~ ^[a-z0-9]([a-z0-9-]{0,61}[a-z0-9])?$ ]] || { echo 'Invalid DNS subdomain (1-63 lowercase letters, numbers, interior hyphens).' >&2; exit 2; }
case "$SUBDOMAIN" in mysql|sys|root|smartolt) echo 'This subdomain is reserved.' >&2; exit 2 ;; esac
GIT_REPO=git@github.com:ispbillingai/smartolt.git
# The SmartOLT panel lives on this branch until it is merged into main.
GIT_BRANCH="${SMARTOLT_GIT_BRANCH:-olt-build-v1}"
DOMAIN=ispledger.click
WEB_ROOT=/var/www/html
SITE_DIR="$WEB_ROOT/$SUBDOMAIN"
CONF_FILE="/etc/apache2/sites-available/$SUBDOMAIN.conf"
LOG_FILE=/var/log/smartolt-install.log
FLEET_SECRET_FILE=/root/smartolt_fleet_secret
log() { printf '%s - %s\n' "$(date '+%Y-%m-%d %H:%M:%S')" "$*" >> "$LOG_FILE"; }
die() { log "$*"; echo "$*" >&2; exit 1; }
trap 'echo "Installation did not complete. Existing data was preserved; inspect /var/log/smartolt-install.log and the reported staging directory." >&2' ERR

# Do not report a partially copied folder as a completed installation.
if [ -e "$SITE_DIR" ] || [ -L "$SITE_DIR" ]; then
    [ ! -L "$SITE_DIR" ] || die 'Refusing a symlink as the site directory.'
    if [ -s "$SITE_DIR/backend/config/database.php" ] && [ -f "$CONF_FILE" ] \
        && [ -L "/etc/apache2/sites-enabled/$SUBDOMAIN.conf" ] \
        && crontab -l 2>/dev/null | grep -Fq "smartolt-$SUBDOMAIN.lock"; then
        echo "Site already installed: $SITE_DIR. Existing code and configuration were preserved."
        exit 0
    fi
    die "Site directory already exists but installation is incomplete: $SITE_DIR. Repair it before retrying; no files were overwritten."
fi
for command in git openssl php mysql apache2ctl a2ensite runuser flock crontab; do
    command -v "$command" >/dev/null || die "Required command is missing: $command"
done
PHP_BIN=$(command -v php)
php -r 'foreach (["pdo_mysql","mbstring","openssl","curl"] as $m) { if (!extension_loaded($m)) { fwrite(STDERR,"Missing PHP extension: $m\n"); exit(1); } }'
[ -s "$FLEET_SECRET_FILE" ] || die "Missing $FLEET_SECRET_FILE (shared billing API secret)."
[ -s "/etc/letsencrypt/live/$DOMAIN/fullchain.pem" ] && [ -s "/etc/letsencrypt/live/$DOMAIN/privkey.pem" ] || die 'The SmartOLT wildcard HTTPS certificate is missing.'

# The FPM handler belongs to this machine's vhost, never a version-pinned repository .htaccess.
FPM_SOCKET="${PHP_FPM_SOCKET:-}"
if [ -z "$FPM_SOCKET" ]; then
    version=$(php -r 'echo PHP_MAJOR_VERSION,".",PHP_MINOR_VERSION;')
    [ ! -S "/run/php/php$version-fpm.sock" ] || FPM_SOCKET="/run/php/php$version-fpm.sock"
fi
if [ -n "$FPM_SOCKET" ]; then
    [[ "$FPM_SOCKET" =~ ^/run/php/[a-zA-Z0-9_.-]+\.sock$ ]] && [ -S "$FPM_SOCKET" ] || die 'PHP_FPM_SOCKET must name an active /run/php/*.sock socket.'
    apache2ctl -M 2>/dev/null | grep -q 'proxy_fcgi_module' || die 'Enable the Apache proxy_fcgi module for PHP-FPM.'
elif ! apache2ctl -M 2>/dev/null | grep -q 'php_module'; then
    die 'No PHP handler found. Start matching PHP-FPM or provide PHP_FPM_SOCKET=/run/php/phpX.Y-fpm.sock.'
fi

DB_NAME="$SUBDOMAIN"
DB_USER="$SUBDOMAIN"
if [ ${#DB_USER} -gt 32 ]; then DB_USER="olt_$(printf '%s' "$SUBDOMAIN" | sha256sum | cut -c1-24)"; fi
# New sites must never adopt or reset an unrelated existing database/account.
existing=$(mysql -N -B -u root -e "SELECT COUNT(*) FROM INFORMATION_SCHEMA.SCHEMATA WHERE SCHEMA_NAME='$DB_NAME';")
[ "$existing" = 0 ] || die "Database $DB_NAME already exists. No data was changed."
existing=$(mysql -N -B -u root -e "SELECT COUNT(*) FROM mysql.user WHERE User='$DB_USER' AND Host='localhost';")
[ "$existing" = 0 ] || die "Database account $DB_USER already exists. No password was changed."
DB_PASS=$(openssl rand -hex 16)
SECRET_KEY=$(openssl rand -base64 32)
# Encoding prevents quotes, dollars and backslashes in the shared secret from becoming PHP code.
FLEET_SECRET_B64=$(tr -d '\r\n' < "$FLEET_SECRET_FILE" | base64 -w0)
[ -n "$FLEET_SECRET_B64" ] || die 'The fleet API secret is empty.'
APP_URL="https://$SUBDOMAIN.$DOMAIN"
mkdir -p /var/lib/smartolt-installs "$WEB_ROOT"
chmod 700 /var/lib/smartolt-installs
STAGE=$(mktemp -d "/var/lib/smartolt-installs/$SUBDOMAIN.XXXXXX")
log "Installing $SUBDOMAIN from $GIT_REPO ($GIT_BRANCH); private staging: $STAGE"
echo "Preparing installation in $STAGE"
git clone --branch "$GIT_BRANCH" --single-branch "$GIT_REPO" "$STAGE/site"
if [ -n "$FPM_SOCKET" ]; then
    sed -i "s|/run/php/php8.4-fpm.sock|$FPM_SOCKET|g" "$STAGE/site/.htaccess"
else
    # mod_php deployment: remove only the legacy PHP handler block, retain access restrictions.
    sed -i '/^[[:space:]]*<FilesMatch "\\\.php\$">$/,/^[[:space:]]*<\/FilesMatch>$/d' "$STAGE/site/.htaccess"
fi
mkdir -p "$STAGE/site/backend/config"
cat > "$STAGE/site/backend/config/database.php" <<EOF
<?php
class Database {
    public function getConnection() {
        return new PDO('mysql:host=localhost;dbname=$DB_NAME;charset=utf8mb4', '$DB_USER', '$DB_PASS', [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC
        ]);
    }
    public function getAppUrl() { return '$APP_URL'; }
    public function getSubdomain() { return '$SUBDOMAIN'; }
    public function getSecretKey() { return '$SECRET_KEY'; }
    public function getFleetSecret() { return base64_decode('$FLEET_SECRET_B64', true); }
}
EOF
php -l "$STAGE/site/backend/config/database.php" >/dev/null
[ -s "$STAGE/site/install.sql" ] || die 'The repository does not contain install.sql.'
mysql -u root -e "CREATE DATABASE \`$DB_NAME\` CHARACTER SET utf8mb4; CREATE USER '$DB_USER'@'localhost' IDENTIFIED BY '$DB_PASS'; GRANT ALL PRIVILEGES ON \`$DB_NAME\`.* TO '$DB_USER'@'localhost';"
mysql -u root "$DB_NAME" < "$STAGE/site/install.sql"
# Runtime writes are kept in the database, so PHP needs read access rather than source ownership.
chown -R root:www-data "$STAGE/site"
find "$STAGE/site" -type d -exec chmod 750 {} \;
find "$STAGE/site" -type f -exec chmod 640 {} \;
mv "$STAGE/site" "$SITE_DIR"
rmdir "$STAGE"

cat > "$CONF_FILE" <<EOF
<VirtualHost *:80>
    ServerName $SUBDOMAIN.$DOMAIN
    Redirect permanent / https://$SUBDOMAIN.$DOMAIN/
</VirtualHost>
<VirtualHost *:443>
    ServerName $SUBDOMAIN.$DOMAIN
    DocumentRoot $SITE_DIR
    <Directory $SITE_DIR>
        Options -Indexes +FollowSymLinks
        AllowOverride All
        Require all granted
    </Directory>
    <LocationMatch "(?i)^/(backend|tests|vpn|provisioner|\\.git|\\.test-runtime)(/|$)|\\.(sql|sh|md|log|ps1|awk)$">
        Require all denied
    </LocationMatch>
    SSLEngine on
    SSLCertificateFile /etc/letsencrypt/live/$DOMAIN/fullchain.pem
    SSLCertificateKeyFile /etc/letsencrypt/live/$DOMAIN/privkey.pem
EOF
if [ -n "$FPM_SOCKET" ]; then
    cat >> "$CONF_FILE" <<EOF
    <FilesMatch "\\.php$">
        SetHandler "proxy:unix:$FPM_SOCKET|fcgi://localhost/"
    </FilesMatch>
EOF
fi
echo '</VirtualHost>' >> "$CONF_FILE"
CRON_JOB="*/5 * * * * cd $SITE_DIR && flock -n /tmp/smartolt-$SUBDOMAIN.lock runuser -u www-data -- $PHP_BIN cron.php >> /var/log/smartolt-$SUBDOMAIN.log 2>&1"
{ { crontab -l 2>/dev/null || true; } | { grep -Fv "smartolt-$SUBDOMAIN.lock" || true; }; echo "$CRON_JOB"; } | crontab -
a2ensite "$SUBDOMAIN.conf"
apache2ctl configtest
systemctl reload apache2
log "Installation complete for $SUBDOMAIN"
echo "Installation complete: $APP_URL"
