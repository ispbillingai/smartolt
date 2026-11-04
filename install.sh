#!/bin/bash

# install.sh
# Usage: sudo /root/install.sh <subdomain>
# Example: sudo /root/install.sh test13

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
# The smartolt repo is private. Auth is via the server's SSH key (/root/.ssh),
# whose public key is registered on GitHub (repo deploy key or account key).
# No token or secret is stored in this script, and it survives redeploys.
# One-time check:  ssh -T git@github.com  -> "Hi ispbillingai! ... authenticated"
GIT_REPO="git@github.com:ispbillingai/smartolt.git"
WEB_ROOT="/var/www/html"
ACS_DIR="$WEB_ROOT/smartolt"
SITE_DIR="$WEB_ROOT/$SUBDOMAIN"
DB_USER="$SUBDOMAIN"
DB_PASS="$SUBDOMAIN"
DB_NAME="$SUBDOMAIN"
SQL_PATH="$SITE_DIR/install.sql"
APP_URL="https://$SUBDOMAIN.ispledger.click"

# Ensure /var/www/html is writable by www-data
log "Setting permissions for $WEB_ROOT"
chown -R www-data:www-data "$WEB_ROOT"
chmod -R 755 "$WEB_ROOT"

# Remove existing acs directory if it exists
if [ -d "$ACS_DIR" ]; then
    log "Removing existing $ACS_DIR directory"
    rm -rf "$ACS_DIR"
fi

# Clone the repository (auth via the server's SSH key in /root/.ssh)
log "Cloning into: $ACS_DIR"
CLONE_RESULT=$(git clone "$GIT_REPO" "$ACS_DIR" 2>&1)
if [ $? -ne 0 ]; then
    log "Git clone failed: $CLONE_RESULT"
    echo "Git clone failed: $CLONE_RESULT"
    exit 1
fi
log "Clone successful"

# Rename /var/www/html/acs to /var/www/html/<subdomain>
# Idempotent: if the site is already installed, treat as success (no-op) so a
# re-run / Re-check from the dashboard does not error.
if [ -d "$SITE_DIR" ]; then
    log "Site $SITE_DIR already exists — already installed, nothing to do."
    rm -rf "$ACS_DIR"
    echo "Installation complete for subdomain $SUBDOMAIN"
    exit 0
fi

log "Renaming $ACS_DIR to $SITE_DIR"
mv "$ACS_DIR" "$SITE_DIR"
if [ ! -d "$SITE_DIR" ]; then
    log "Failed to rename $ACS_DIR to $SITE_DIR"
    echo "Failed to rename $ACS_DIR to $SITE_DIR"
    exit 1
fi

# Change into the new folder
cd "$SITE_DIR" || {
    log "Failed to enter $SITE_DIR"
    echo "Failed to enter $SITE_DIR"
    exit 1
}

# Create backend/config directory if it doesn't exist
mkdir -p backend/config

# Create backend/config/database.php using touch
touch backend/config/database.php
if [ $? -ne 0 ]; then
    log "Failed to create backend/config/database.php using touch"
    echo "Failed to create backend/config/database.php using touch"
    exit 1
fi

# Write the updated database.php content
cat > backend/config/database.php <<EOL
<?php
class Database {
    private \$host = "localhost";
    private \$db_name = "$DB_NAME";
    private \$username = "$DB_USER";
    private \$password = "$DB_PASS";
    private \$app_url = "$APP_URL";
    public \$conn;

    public function getConnection() {
        \$this->conn = null;

        try {
            error_log("Attempting database connection to: {\$this->host}");
            \$this->conn = new PDO(
                "mysql:host=" . \$this->host . ";dbname=" . \$this->db_name,
                \$this->username,
                \$this->password
            );
            \$this->conn->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
            error_log("Database connection established successfully");
            return \$this->conn;
        } catch(PDOException \$e) {
            error_log("Database Connection Error: " . \$e->getMessage());
            error_log("Connection details: host={\$this->host}, db={\$this->db_name}, user={\$this->username}");
            throw \$e;
        }
    }

    public function getAppUrl() {
        return \$this->app_url;
    }

}
EOL

if [ $? -ne 0 ]; then
    log "Failed to write to backend/config/database.php"
    echo "Failed to write to backend/config/database.php"
    exit 1
fi
log "Created backend/config/database.php for DB: $DB_NAME / $DB_USER / $DB_PASS"

# Set correct permissions
log "Setting permissions for $SITE_DIR"
chown -R www-data:www-data "$SITE_DIR"
find "$SITE_DIR" -type d -exec chmod 777 {} \;
find "$SITE_DIR" -type f -exec chmod 777 {} \;

# Create Apache virtual host configuration
CONF_FILE="/etc/apache2/sites-available/$SUBDOMAIN.conf"
cat > "$CONF_FILE" <<EOF
# Port 80 - Main Site and phpMyAdmin
<VirtualHost *:80>
    ServerName $SUBDOMAIN.ispledger.click

    # Main site document root
    DocumentRoot $SITE_DIR

    <Directory $SITE_DIR>
        Options Indexes FollowSymLinks MultiViews
        AllowOverride All
        Require all granted
    </Directory>




</VirtualHost>



# Port 443 - SSL (HTTPS) for Main Site
<IfModule mod_ssl.c>
<VirtualHost *:443>
    ServerName $SUBDOMAIN.ispledger.click
    DocumentRoot $SITE_DIR

    <Directory $SITE_DIR>
        Options Indexes FollowSymLinks MultiViews
        AllowOverride All
        Require all granted
    </Directory>


    SSLCertificateFile /etc/letsencrypt/live/ispledger.click/fullchain.pem
    SSLCertificateKeyFile /etc/letsencrypt/live/ispledger.click/privkey.pem
</VirtualHost>
</IfModule>
EOF

if [ $? -ne 0 ]; then
    log "Failed to create Apache config file: $CONF_FILE"
    echo "Failed to create Apache config file: $CONF_FILE"
    exit 1
fi
log "Created Apache config file: $CONF_FILE"

# Enable the new site
log "Enabling Apache site: $SUBDOMAIN.conf"
ENABLE_RESULT=$(a2ensite "$SUBDOMAIN.conf" 2>&1)
if [ $? -ne 0 ]; then
    log "Failed to enable the new site: $ENABLE_RESULT"
    echo "Failed to enable the new site: $ENABLE_RESULT"
    exit 1
fi
log "Enabled Apache site"


# Reload Apache
log "Reloading Apache"
RELOAD_RESULT=$(systemctl reload apache2 2>&1)
if [ $? -ne 0 ]; then
    log "Failed to reload Apache: $RELOAD_RESULT"
    echo "Failed to reload Apache: $RELOAD_RESULT"
    exit 1
fi
log "Reloaded Apache"

# Create the MySQL user and database
log "Creating MySQL user: $DB_USER"
MYSQL_USER_RESULT=$(mysql -u root -e "CREATE USER IF NOT EXISTS '$DB_USER'@'localhost' IDENTIFIED BY '$DB_PASS'; GRANT ALL PRIVILEGES ON \`$DB_NAME\`.* TO '$DB_USER'@'localhost'; FLUSH PRIVILEGES;" 2>&1)
if [ $? -ne 0 ]; then
    log "Failed to create MySQL user $DB_USER: $MYSQL_USER_RESULT"
    echo "Failed to create MySQL user $DB_USER: $MYSQL_USER_RESULT"
    exit 1
fi
log "Created MySQL user"

log "Creating database: $DB_NAME"
MYSQL_DB_RESULT=$(mysql -u root -e "CREATE DATABASE IF NOT EXISTS \`$DB_NAME\`" 2>&1)
if [ $? -ne 0 ]; then
    log "Failed to create database $DB_NAME: $MYSQL_DB_RESULT"
    echo "Failed to create database $DB_NAME: $MYSQL_DB_RESULT"
    exit 1
fi
log "Created database"

# Import install.sql if present
if [ -f "$SQL_PATH" ]; then
    log "Importing $SQL_PATH into $DB_NAME..."
    IMPORT_RESULT=$(mysql -u "$DB_USER" -p"$DB_PASS" "$DB_NAME" < "$SQL_PATH" 2>&1)
    if [ $? -ne 0 ]; then
        log "Database import failed: $IMPORT_RESULT"
        echo "Database import failed: $IMPORT_RESULT"
        exit 1
    fi
    log "Database import complete."
else
    log "No install.sql found at $SQL_PATH, skipping import."
fi

# Set final permissions
log "Setting final permissions for $SITE_DIR"
chown -R www-data:www-data "$SITE_DIR"
chmod -R 777 "$SITE_DIR"

# Set up cron job to run at 3 AM UTC every day
CRON_URL="$APP_URL/cron.php"
CRON_JOB="0 3 * * * /usr/bin/wget -q -O /dev/null $CRON_URL"
(crontab -l 2>/dev/null | grep -v "$CRON_URL"; echo "$CRON_JOB") | crontab -
if [ $? -ne 0 ]; then
    log "Failed to set up cron job for $CRON_URL"
    echo "Failed to set up cron job for $CRON_URL"
    exit 1
fi
log "Set up cron job for $CRON_URL at 3 AM UTC daily"

log "Installation complete for subdomain $SUBDOMAIN"
echo "Installation complete for subdomain $SUBDOMAIN"
exit 0