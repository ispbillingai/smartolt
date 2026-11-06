#!/usr/bin/env bash
# Execute installer control flow against temporary files and mocked system commands.
set -euo pipefail
ROOT=$(cd "$(dirname "$0")/.." && pwd)
WORK=$(mktemp -d)
trap 'rm -rf "$WORK"' EXIT
mkdir -p "$WORK/bin" "$WORK/var/www/html" "$WORK/var/log" "$WORK/etc/apache2/sites-available" "$WORK/etc/apache2/sites-enabled" "$WORK/root" "$WORK/etc/letsencrypt/live/ispledger.click"
printf '%s\n' 'test"secret$with\quotes' > "$WORK/root/smartolt_fleet_secret"
touch "$WORK/etc/letsencrypt/live/ispledger.click/fullchain.pem" "$WORK/etc/letsencrypt/live/ispledger.click/privkey.pem"
printf 'fixture\n' | tee "$WORK/etc/letsencrypt/live/ispledger.click/fullchain.pem" > "$WORK/etc/letsencrypt/live/ispledger.click/privkey.pem"
sed -e 's/\[\[ "$EUID" -eq 0 \&\& "$#" -eq 1 \]\]/[ "$#" -eq 1 ]/' \
    -e "s|/var/www/html|$WORK/var/www/html|g" -e "s|/var/lib/smartolt-installs|$WORK/var/lib/smartolt-installs|g" \
    -e "s|/etc/apache2|$WORK/etc/apache2|g" -e "s|/etc/letsencrypt|$WORK/etc/letsencrypt|g" \
    -e "s|/var/log/|$WORK/var/log/|g" -e "s|/root/|$WORK/root/|g" "$ROOT/install.sh" > "$WORK/install.sh"
export INSTALL_TEST_ROOT="$ROOT" INSTALL_TEST_WORK="$WORK"
cat > "$WORK/bin/git" <<'EOF'
#!/usr/bin/env bash
set -eu
[[ "$1" = clone ]]
# The installer must clone the SmartOLT branch, not the repository's default branch.
[[ " $* " = *" --branch olt-build-v1 "* ]] || { echo 'git clone without --branch olt-build-v1' >&2; exit 1; }
dest="${!#}"
mkdir -p "$dest/backend/config"
cp "$INSTALL_TEST_ROOT/.htaccess" "$dest/.htaccess"
printf 'SELECT 1;\n' > "$dest/install.sql"
EOF
cat > "$WORK/bin/mysql" <<'EOF'
#!/usr/bin/env bash
if [[ "$*" = *'SELECT COUNT'* ]]; then echo "${INSTALL_TEST_EXISTS:-0}"; else cat >/dev/null; fi
EOF
cat > "$WORK/bin/apache2ctl" <<'EOF'
#!/usr/bin/env bash
if [ "${1:-}" = '-M' ]; then echo 'php_module (shared)'; else echo 'Syntax OK'; fi
EOF
cat > "$WORK/bin/crontab" <<'EOF'
#!/usr/bin/env bash
if [ "${1:-}" = '-l' ]; then cat "$INSTALL_TEST_WORK/cron" 2>/dev/null; else cat > "$INSTALL_TEST_WORK/cron"; fi
EOF
for command in a2ensite runuser flock chown systemctl; do printf '#!/bin/sh\nexit 0\n' > "$WORK/bin/$command"; done
REAL_PHP=$(command -v php || true)
[ -n "$REAL_PHP" ] || REAL_PHP=/c/PHP/php.exe
cat > "$WORK/bin/php" <<EOF
#!/usr/bin/env bash
exec "$REAL_PHP" -d extension=pdo_mysql "\$@"
EOF
chmod +x "$WORK/bin/"*
export PATH="$WORK/bin:$PATH"
bash -n "$ROOT/install.sh"
if bash "$WORK/install.sh" ../escape >"$WORK/result" 2>&1; then exit 1; fi
if bash "$WORK/install.sh" --bad >"$WORK/result" 2>&1; then exit 1; fi
if bash "$WORK/install.sh" mysql >"$WORK/result" 2>&1; then exit 1; fi
echo 'PASS installer rejects unsafe paths and reserved database names'
mkdir -p "$WORK/var/www/html/incomplete"
if bash "$WORK/install.sh" incomplete >"$WORK/result" 2>&1; then exit 1; fi
grep -q 'installation is incomplete' "$WORK/result"
echo 'PASS partial installation is not reported as success'
if INSTALL_TEST_EXISTS=1 bash "$WORK/install.sh" existing >"$WORK/result" 2>&1; then exit 1; fi
grep -q 'already exists. No data was changed' "$WORK/result"
echo 'PASS existing database is never overwritten or adopted'
bash "$WORK/install.sh" fixture >"$WORK/result" 2>&1 || { cat "$WORK/result"; exit 1; }
[ -s "$WORK/var/www/html/fixture/backend/config/database.php" ]
[ ! -e "$WORK/var/www/html/smartolt" ]
echo 'PASS new installation uses private unique staging and completes'
if grep -q 'SetHandler' "$WORK/var/www/html/fixture/.htaccess"; then echo 'FAIL mod_php install retained FPM override'; exit 1; fi
echo 'PASS mod_php installation removes incompatible FPM handler'
export FIXTURE_CONFIG="$WORK/var/www/html/fixture/backend/config/database.php"
actual=$(php -r 'require getenv("FIXTURE_CONFIG"); echo (new Database())->getFleetSecret();')
[ "$actual" = 'test"secret$with\quotes' ]
echo 'PASS fleet secret special characters survive generated PHP safely'
grep -q 'SSLEngine on' "$WORK/etc/apache2/sites-available/fixture.conf"
grep -q 'smartolt-fixture.lock' "$WORK/cron"
echo 'PASS HTTPS and scheduled polling are installed'
