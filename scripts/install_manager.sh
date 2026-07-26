#!/usr/bin/env bash
set -Eeuo pipefail

APP_DIR="${MIRZA_APP_DIR:-/var/www/mirza}"
RELEASE="${MIRZA_MANAGER_RELEASE:-v1.0.4}"
BASE_URL="https://raw.githubusercontent.com/yasinmalek82/sell-bot/${RELEASE}/scripts"

if [[ ${EUID} -ne 0 ]]; then
  echo 'Run this installer as root.' >&2
  exit 1
fi
if [[ ! $APP_DIR =~ ^/var/www/[A-Za-z0-9._-]+$ || ! -r $APP_DIR/config.local.php ]]; then
  echo "A valid installation was not found at $APP_DIR." >&2
  exit 1
fi

TEMP_DIR="$(mktemp -d /tmp/sell-bot-manager.XXXXXX)"
trap 'rm -rf -- "$TEMP_DIR"' EXIT

curl -fL "$BASE_URL/manage.sh" -o "$TEMP_DIR/manage.sh"
curl -fL "$BASE_URL/repair_webhook.sh" -o "$TEMP_DIR/repair_webhook.sh"
bash -n "$TEMP_DIR/manage.sh"
bash -n "$TEMP_DIR/repair_webhook.sh"

install -o root -g www-data -m 0750 "$TEMP_DIR/manage.sh" "$APP_DIR/scripts/manage.sh"
install -o root -g www-data -m 0750 "$TEMP_DIR/repair_webhook.sh" "$APP_DIR/scripts/repair_webhook.sh"
ln -sf "$APP_DIR/scripts/manage.sh" /usr/local/bin/sell-bot

echo 'Management menu installed. Run: sell-bot'
