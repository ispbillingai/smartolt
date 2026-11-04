#!/bin/bash

# install.sh
# Usage: sudo /root/install.sh <subdomain>
# Example: sudo /root/install.sh test13   ->   https://test13.smartolt.ispledger.com
#
# One-time setup on the SmartOLT box before the first install:
#   - /root/smartolt_fleet_secret   the fleet secret, one line, same value as $smartoltFleetSecret in the
#                                  billing dashboard (system/controllers/smartolt.php). Never in git.
#   - wildcard certificate for *.$DOMAIN in /etc/letsencrypt/live/$DOMAIN/
#   - git access to the private repo through /root/.ssh (ssh -T git@github.com)

# Ensure the script is run as root
if [ "$EUID" -ne 0 ]; then
  echo "Please run as root: sudo $0 <subdomain>"
  exit 1
fi

# Check we have exactly 1 argument: the subdomain
if [ $# -ne 1 ]; then
  echo "Usage: sudo $0 <subdomain>"
  exit 1
fi

SUBDOMAIN="$1"
LOG_FILE="/root/install.log"

# Log function
log() {
    local MESSAGE="$1"
    echo "$(date '+%Y-%m-%d %H:%M:%S') - $MESSAGE" >> "$LOG_FILE"
}

# Validate subdomain
if [[ ! "$SUBDOMAIN" =~ ^[a-z0-9-]+$ ]]; then
  log "Invalid subdomain. Use only lowercase letters, numbers, and hyphens."
  echo "Invalid subdomain. Use only lowercase letters, numbers, and hyphens."
  exit 1
fi

# Set key variables
GIT_REPO="git@github.com:ispbillingai/smartolt.git"
DOMAIN="smartolt.ispledger.com"
WEB_ROOT="/var/www/html"
CLONE_DIR="$WEB_ROOT/smartolt"
SITE_DIR="$WEB_ROOT/$SUBDOMAIN"
DB_USER="$SUBDOMAIN"
DB_PASS="$(openssl rand -hex 16)"
DB_NAME="$SUBDOMAIN"
SQL_PATH="$SITE_DIR/install.sql"
APP_URL="https://$SUBDOMAIN.$DOMAIN"
FLEET_SECRET_FILE="/root/smartolt_fleet_secret"

if [ ! -s "$FLEET_SECRET_FILE" ]; then
    log "Missing $FLEET_SECRET_FILE"
    echo "Missing $FLEET_SECRET_FILE (the fleet secret shared with the billing dashboard)"
    exit 1
fi
FLEET_SECRET="$(tr -d ' \r\n' < "$FLEET_SECRET_FILE")"
SECRET_KEY="$(openssl rand -base64 32)"

# Idempotent: if the site is already installed, treat as success (no-op) so a
# re-run / Re-check from the dashboard does not error.
if [ -d "$SITE_DIR" ]; then
    log "Site $SITE_DIR already exists, already installed, nothing to do."
    echo "Installation complete for subdomain $SUBDOMAIN"
    exit 0
fi

# Remove a leftover clone directory if it exists
if [ -d "$CLONE_DIR" ]; then
    log "Removing existing $CLONE_DIR directory"
    rm -rf "$CLONE_DIR"
fi

# Clone the repository (auth via the server's SSH key in /root/.ssh)
log "Cloning into: $CLONE_DIR"
CLONE_RESULT=$(git clone "$GIT_REPO" "$CLONE_DIR" 2>&1)
if [ $? -ne 0 ]; then
    log "Git clone failed: $CLONE_RESULT"
    echo "Git clone failed: $CLONE_RESULT"
    exit 1
fi
log "Clone successful"

log "Renaming $CLONE_DIR to $SITE_DIR"
mv "$CLONE_DIR" "$SITE_DIR"
if [ ! -d "$SITE_DIR" ]; then
    log "Failed to rename $CLONE_DIR to $SITE_DIR"
    echo "Failed to rename $CLONE_DIR to $SITE_DIR"
    exit 1
fi

cd "$SITE_DIR" || {
    log "Failed to enter $SITE_DIR"
    echo "Failed to enter $SITE_DIR"
    exit 1
}

mkdir -p backend/config

# Write backend/config/database.php (git ignored)
cat > backend/config/database.php <<EOL
<?php
class Database {
    private \$host = "localhost";
    private \$db_name = "$DB_NAME";
    private \$username = "$DB_USER";
    private \$password = "$DB_PASS";
    private \$app_url = "$APP_URL";
    private \$subdomain = "$SUBDOMAIN";
    private \$secret_key = "$SECRET_KEY";
    private \$fleet_secret = "$FLEET_SECRET";
    public \$conn;

    public function getConnection() {
        \$this->conn = null;
        try {
            \$this->conn = new PDO(
                "mysql:host=" . \$this->host . ";dbname=" . \$this->db_name . ";charset=utf8mb4",
                \$this->username,
                \$this->password
            );
            \$this->conn->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
            \$this->conn->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
            return \$this->conn;
        } catch(PDOException \$e) {
            error_log("Database Connection Error: " . \$e->getMessage());
            throw \$e;
        }
    }

    public function getAppUrl() { return \$this->app_url; }
    public function getSubdomain() { return \$this->subdomain; }
    public function getSecretKey() { return \$this->secret_key; }
    public function getFleetSecret() { return \$this->fleet_secret; }
}
EOL

if [ $? -ne 0 ]; then
    log "Failed to write to backend/config/database.php"
    echo "Failed to write to backend/config/database.php"
    exit 1
fi
log "Created backend/config/database.php for DB: $DB_NAME / $DB_USER"

# Permissions: readable by the web server, config readable only by it
chown -R www-data:www-data "$SITE_DIR"
find "$SITE_DIR" -type d -exec chmod 755 {} \;
find "$SITE_DIR" -type f -exec chmod 644 {} \;
chmod 640 "$SITE_DIR/backend/config/database.php"

# Create Apache virtual host configuration
CONF_FILE="/etc/apache2/sites-available/$SUBDOMAIN.conf"
cat > "$CONF_FILE" <<EOF
# Port 80: redirect to HTTPS
<VirtualHost *:80>
    ServerName $SUBDOMAIN.$DOMAIN
    Redirect permanent / https://$SUBDOMAIN.$DOMAIN/
</VirtualHost>

<IfModule mod_ssl.c>
<VirtualHost *:443>
    ServerName $SUBDOMAIN.$DOMAIN
    DocumentRoot $SITE_DIR

    <Directory $SITE_DIR>
        Options FollowSymLinks
        AllowOverride All
        Require all granted
    </Directory>

    # Only the pages are public: never the code behind them, the tests, the repo or setup files
    <LocationMatch "^/(backend|tests|vpn)(/|$)|/\.git|\.(sql|sh|md|log)$">
        Require all denied
    </LocationMatch>

    SSLCertificateFile /etc/letsencrypt/live/$DOMAIN/fullchain.pem
    SSLCertificateKeyFile /etc/letsencrypt/live/$DOMAIN/privkey.pem
</VirtualHost>
</IfModule>
EOF

if [ $? -ne 0 ]; then
    log "Failed to create Apache config file: $CONF_FILE"
    echo "Failed to create Apache config file: $CONF_FILE"
    exit 1
fi
log "Created Apache config file: $CONF_FILE"

# Create the MySQL user and database (before Apache reloads, so the site works the moment it answers)
log "Creating database and user: $DB_NAME"
MYSQL_RESULT=$(mysql -u root -e "CREATE DATABASE IF NOT EXISTS \`$DB_NAME\` CHARACTER SET utf8mb4; CREATE USER IF NOT EXISTS '$DB_USER'@'localhost' IDENTIFIED BY '$DB_PASS'; ALTER USER '$DB_USER'@'localhost' IDENTIFIED BY '$DB_PASS'; GRANT ALL PRIVILEGES ON \`$DB_NAME\`.* TO '$DB_USER'@'localhost'; FLUSH PRIVILEGES;" 2>&1)
if [ $? -ne 0 ]; then
    log "Failed to create the database: $MYSQL_RESULT"
    echo "Failed to create the database: $MYSQL_RESULT"
    exit 1
fi

if [ -f "$SQL_PATH" ]; then
    log "Importing $SQL_PATH into $DB_NAME..."
    IMPORT_RESULT=$(mysql -u "$DB_USER" -p"$DB_PASS" "$DB_NAME" < "$SQL_PATH" 2>&1)
    if [ $? -ne 0 ]; then
        log "Database import failed: $IMPORT_RESULT"
        echo "Database import failed: $IMPORT_RESULT"
        exit 1
    fi
    log "Database import complete."
fi

# Read the OLTs every 5 minutes, as the web server user
CRON_JOB="*/5 * * * * cd $SITE_DIR && flock -n /tmp/smartolt-$SUBDOMAIN.lock runuser -u www-data -- php cron.php >> /var/log/smartolt-$SUBDOMAIN.log 2>&1"
(crontab -l 2>/dev/null | grep -v "smartolt-$SUBDOMAIN.lock"; echo "$CRON_JOB") | crontab -
if [ $? -ne 0 ]; then
    log "Failed to set up the cron job"
    echo "Failed to set up the cron job"
    exit 1
fi
log "Set up the 5 minute OLT read"

# Enable the new site and reload Apache last: the reload can drop the request that started this install
log "Enabling Apache site: $SUBDOMAIN.conf"
ENABLE_RESULT=$(a2ensite "$SUBDOMAIN.conf" 2>&1)
if [ $? -ne 0 ]; then
    log "Failed to enable the new site: $ENABLE_RESULT"
    echo "Failed to enable the new site: $ENABLE_RESULT"
    exit 1
fi

log "Installation complete for subdomain $SUBDOMAIN"
echo "Installation complete for subdomain $SUBDOMAIN"
systemctl reload apache2 >> "$LOG_FILE" 2>&1
exit 0
