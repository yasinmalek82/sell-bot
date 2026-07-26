#!/usr/bin/env bash
set -Eeuo pipefail

# Mirza Premium server installer (Ubuntu 22.04/24.04).
# Usage from a GitHub repository:
#   curl -fsSL https://raw.githubusercontent.com/OWNER/REPO/main/install.sh |
#     sudo MIRZA_REPO=OWNER/REPO bash

APP_DIR="${MIRZA_APP_DIR:-/var/www/mirza}"
REPO="${MIRZA_REPO:-}"
BRANCH="${MIRZA_BRANCH:-main}"
DB_NAME="${DB_NAME:-mirza_bot}"
DB_USER="${DB_USER:-mirza_bot}"
LOCAL_SOURCE="${MIRZA_SOURCE_DIR:-}"

SCRIPT_SOURCE="${BASH_SOURCE[0]-}"
SCRIPT_DIR=""
if [[ -n $SCRIPT_SOURCE ]]; then
  SCRIPT_DIR="$(cd "$(dirname "$SCRIPT_SOURCE")" 2>/dev/null && pwd || true)"
fi
if [[ -z $LOCAL_SOURCE && -n $SCRIPT_DIR && -f $SCRIPT_DIR/config.php && -f $SCRIPT_DIR/table.php ]]; then
  LOCAL_SOURCE="$SCRIPT_DIR"
fi

[[ $APP_DIR =~ ^/[A-Za-z0-9._/-]+$ && $APP_DIR != / ]] || {
  echo 'MIRZA_APP_DIR must be a safe absolute path.' >&2
  exit 1
}

if [[ ${EUID} -ne 0 ]]; then
  echo 'Run this installer as root (sudo).' >&2
  exit 1
fi

if [[ ! -r /etc/os-release ]]; then
  echo 'Unsupported operating system.' >&2
  exit 1
fi
. /etc/os-release
if [[ ${ID:-} != ubuntu || ! ${VERSION_ID:-} =~ ^(22\.04|24\.04)$ ]]; then
  echo 'This installer supports clean Ubuntu 22.04 and 24.04 servers.' >&2
  exit 1
fi

ask() {
  local variable="$1" prompt="$2" secret="${3:-0}" value=""
  if declare -p "$variable" >/dev/null 2>&1; then
    value="${!variable}"
  fi
  if [[ -z $value ]]; then
    if [[ ! -r /dev/tty ]]; then
      echo "Missing required environment variable: $variable" >&2
      exit 1
    fi
    if [[ $secret == 1 ]]; then
      read -r -s -p "$prompt: " value </dev/tty; echo >/dev/tty
    else
      read -r -p "$prompt: " value </dev/tty
    fi
    printf -v "$variable" '%s' "$value"
  fi
}

if [[ -z $LOCAL_SOURCE ]]; then
  ask REPO 'GitHub repository (OWNER/REPO)'
fi
ask BOT_DOMAIN 'Bot domain (bot.example.com)'
ask LETSENCRYPT_EMAIL "Let's Encrypt email"
ask BOT_TOKEN 'Telegram bot token' 1
ask BOT_ADMIN_ID 'Telegram numeric admin ID'
ask BOT_USERNAME 'Bot username without @'
ask PANEL_ADMIN_PASSWORD 'Web admin password (minimum 12 characters)' 1

BOT_USERNAME="${BOT_USERNAME#@}"
if [[ -z $LOCAL_SOURCE ]]; then
  [[ $REPO =~ ^[A-Za-z0-9_.-]+/[A-Za-z0-9_.-]+$ ]] || { echo 'Invalid MIRZA_REPO.' >&2; exit 1; }
else
  [[ $LOCAL_SOURCE =~ ^/[A-Za-z0-9._/-]+$ && -f $LOCAL_SOURCE/config.php ]] || { echo 'Invalid MIRZA_SOURCE_DIR.' >&2; exit 1; }
  [[ $LOCAL_SOURCE != "$APP_DIR" ]] || { echo 'Source and install target must be different.' >&2; exit 1; }
fi
[[ $BRANCH =~ ^[A-Za-z0-9._/-]+$ ]] || { echo 'Invalid MIRZA_BRANCH.' >&2; exit 1; }
[[ $BOT_DOMAIN =~ ^[A-Za-z0-9.-]+$ && $BOT_DOMAIN == *.* ]] || { echo 'Invalid BOT_DOMAIN.' >&2; exit 1; }
[[ $LETSENCRYPT_EMAIL == *@*.* ]] || { echo 'Invalid LETSENCRYPT_EMAIL.' >&2; exit 1; }
[[ $BOT_TOKEN =~ ^[0-9]{6,12}:[A-Za-z0-9_-]{30,}$ ]] || { echo 'Invalid BOT_TOKEN.' >&2; exit 1; }
[[ $BOT_ADMIN_ID =~ ^[0-9]{5,20}$ ]] || { echo 'Invalid BOT_ADMIN_ID.' >&2; exit 1; }
[[ $BOT_USERNAME =~ ^[A-Za-z0-9_]{5,32}$ ]] || { echo 'Invalid BOT_USERNAME.' >&2; exit 1; }
[[ ${#PANEL_ADMIN_PASSWORD} -ge 12 ]] || { echo 'PANEL_ADMIN_PASSWORD must have at least 12 characters.' >&2; exit 1; }
[[ $DB_NAME =~ ^[A-Za-z0-9_]+$ && $DB_USER =~ ^[A-Za-z0-9_]+$ ]] || { echo 'Invalid database identifier.' >&2; exit 1; }

if [[ -e $APP_DIR ]]; then
  echo "Install target already exists: $APP_DIR" >&2
  echo 'Back it up and remove it, or set MIRZA_APP_DIR to an empty path.' >&2
  exit 1
fi

TEMP_DIR="$(mktemp -d /tmp/mirza-install.XXXXXX)"
trap 'rm -rf -- "$TEMP_DIR"' EXIT
DB_PASS="$(openssl rand -hex 24)"
WEBHOOK_SECRET="$(openssl rand -hex 32)"
ARCHIVE_URL="https://codeload.github.com/${REPO}/zip/refs/heads/${BRANCH}"

echo '[1/8] Installing server packages...'
export DEBIAN_FRONTEND=noninteractive
apt-get update -y
apt-get install -y apache2 mysql-server curl unzip rsync certbot python3-certbot-apache \
  php php-cli libapache2-mod-php php-mysql php-curl php-mbstring php-xml php-gd php-zip php-bcmath php-intl
a2enmod rewrite headers ssl
systemctl enable --now apache2 mysql

echo '[2/8] Downloading application...'
if [[ -n $LOCAL_SOURCE ]]; then
  SOURCE_DIR="$LOCAL_SOURCE"
else
  curl -fL --retry 3 --connect-timeout 15 "$ARCHIVE_URL" -o "$TEMP_DIR/source.zip"
  unzip -q "$TEMP_DIR/source.zip" -d "$TEMP_DIR/source"
  SOURCE_DIR="$(find "$TEMP_DIR/source" -mindepth 1 -maxdepth 1 -type d | head -n 1)"
fi
[[ -n $SOURCE_DIR && -f $SOURCE_DIR/config.php && -f $SOURCE_DIR/table.php ]] || {
  echo 'Downloaded archive is not a valid Mirza source tree.' >&2
  exit 1
}
install -d -m 0750 "$APP_DIR"
rsync -a "$SOURCE_DIR/" "$APP_DIR/" \
  --exclude='.git/' --exclude='.DS_Store' --exclude='config.local.php' \
  --exclude='error_log*' --exclude='log.txt' --exclude='logs/*.log' \
  --exclude='panel/log.txt' --exclude='cronbot/log.txt' --exclude='cronbot/error_log' \
  --exclude='vpnbot/[0-9]*/'

echo '[3/8] Creating database...'
mysql --protocol=socket <<SQL
CREATE DATABASE \`${DB_NAME}\` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
CREATE USER '${DB_USER}'@'localhost' IDENTIFIED BY '${DB_PASS}';
GRANT ALL PRIVILEGES ON \`${DB_NAME}\`.* TO '${DB_USER}'@'localhost';
FLUSH PRIVILEGES;
SQL

echo '[4/8] Writing private configuration...'
cat > "$APP_DIR/config.local.php" <<PHP
<?php
return [
    'db_host' => '127.0.0.1',
    'db_name' => '${DB_NAME}',
    'db_user' => '${DB_USER}',
    'db_pass' => '${DB_PASS}',
    'bot_token' => '${BOT_TOKEN}',
    'admin_id' => '${BOT_ADMIN_ID}',
    'domain' => '${BOT_DOMAIN}',
    'bot_username' => '${BOT_USERNAME}',
    'webhook_secret' => '${WEBHOOK_SECRET}',
    'disable_self_cron' => true,
];
PHP

echo '[5/8] Initializing schema...'
(
  cd "$APP_DIR"
  PANEL_ADMIN_PASSWORD="$PANEL_ADMIN_PASSWORD" SKIP_TELEGRAM_WEBHOOK=1 php scripts/install_database.php
)

echo '[6/8] Configuring Apache and scheduled jobs...'
cat > "/etc/apache2/sites-available/${BOT_DOMAIN}.conf" <<APACHE
<VirtualHost *:80>
    ServerName ${BOT_DOMAIN}
    DocumentRoot ${APP_DIR}
    ErrorLog \${APACHE_LOG_DIR}/mirza-error.log
    CustomLog \${APACHE_LOG_DIR}/mirza-access.log combined

    <Directory ${APP_DIR}>
        Options -Indexes
        AllowOverride None
        Require all granted
        RewriteEngine On
        RewriteRule ^telegram(?:/.*)?$ local-webhook-router.php [L,QSA]
    </Directory>

    <FilesMatch "^(?:config(?:\.local(?:\.example)?)?\.php|table\.php|reseller-webhook\.php|composer\.(?:json|lock)|\.env)$">
        Require all denied
    </FilesMatch>
    <LocationMatch "^/(?:migrations|scripts|tests|cronbot|vendor|vpnbot)(?:/|$)">
        Require all denied
    </LocationMatch>

    Header always set X-Content-Type-Options "nosniff"
    Header always set Referrer-Policy "same-origin"
    Header always set X-Frame-Options "SAMEORIGIN"
</VirtualHost>
APACHE
a2dissite 000-default.conf >/dev/null 2>&1 || true
a2ensite "${BOT_DOMAIN}.conf" >/dev/null
apache2ctl configtest
systemctl reload apache2

cat > /etc/cron.d/mirza <<CRON
SHELL=/bin/sh
PATH=/usr/local/sbin:/usr/local/bin:/sbin:/bin:/usr/sbin:/usr/bin
*/15 * * * * www-data cd ${APP_DIR} && php cronbot/statusday.php >/dev/null 2>&1
* * * * * www-data cd ${APP_DIR} && php cronbot/croncard.php >/dev/null 2>&1
* * * * * www-data cd ${APP_DIR} && php cronbot/NoticationsService.php >/dev/null 2>&1
*/5 * * * * www-data cd ${APP_DIR} && php cronbot/payment_expire.php >/dev/null 2>&1
* * * * * www-data cd ${APP_DIR} && php cronbot/sendmessage.php >/dev/null 2>&1
* * * * * www-data cd ${APP_DIR} && php cronbot/activeconfig.php >/dev/null 2>&1
* * * * * www-data cd ${APP_DIR} && php cronbot/disableconfig.php >/dev/null 2>&1
*/30 * * * * www-data cd ${APP_DIR} && php cronbot/expireagent.php >/dev/null 2>&1
*/15 * * * * www-data cd ${APP_DIR} && php cronbot/on_hold.php >/dev/null 2>&1
*/2 * * * * www-data cd ${APP_DIR} && php cronbot/configtest.php >/dev/null 2>&1
*/15 * * * * www-data cd ${APP_DIR} && php cronbot/uptime_node.php >/dev/null 2>&1
*/15 * * * * www-data cd ${APP_DIR} && php cronbot/uptime_panel.php >/dev/null 2>&1
CRON
chmod 0644 /etc/cron.d/mirza

chown -R root:www-data "$APP_DIR"
find "$APP_DIR" -type d -exec chmod 0750 {} +
find "$APP_DIR" -type f -exec chmod 0640 {} +
chmod 0640 "$APP_DIR/config.local.php"
chmod 0750 "$APP_DIR/vpnbot"
touch "$APP_DIR/error_log" "$APP_DIR/log.txt" "$APP_DIR/panel/log.txt"
chown www-data:www-data "$APP_DIR/error_log" "$APP_DIR/log.txt" "$APP_DIR/panel/log.txt" "$APP_DIR/vpnbot"

echo '[7/8] Issuing SSL certificate...'
certbot --apache --non-interactive --agree-tos --redirect \
  --email "$LETSENCRYPT_EMAIL" -d "$BOT_DOMAIN"

echo '[8/8] Registering Telegram webhook...'
WEBHOOK_RESPONSE="$(curl -fsS -X POST "https://api.telegram.org/bot${BOT_TOKEN}/setWebhook" \
  --data-urlencode "url=https://${BOT_DOMAIN}/telegram/webhook" \
  --data-urlencode "secret_token=${WEBHOOK_SECRET}" \
  --data-urlencode 'drop_pending_updates=true')"
if [[ $WEBHOOK_RESPONSE != *'"ok":true'* ]]; then
  echo "Telegram rejected the webhook: $WEBHOOK_RESPONSE" >&2
  exit 1
fi

cat > /root/mirza-install.txt <<INFO
URL: https://${BOT_DOMAIN}/panel/
Panel username: admin
Panel password: ${PANEL_ADMIN_PASSWORD}
Application: ${APP_DIR}
Database: ${DB_NAME}
Database user: ${DB_USER}
Database password: ${DB_PASS}
Source: ${REPO:-local directory} (${BRANCH})
INFO
chmod 0600 /root/mirza-install.txt

echo
echo 'Mirza installation completed successfully.'
echo "Admin panel: https://${BOT_DOMAIN}/panel/"
echo 'Credentials were saved to /root/mirza-install.txt (root-only).'
