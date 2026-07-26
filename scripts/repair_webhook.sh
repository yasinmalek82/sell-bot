#!/usr/bin/env bash
set -Eeuo pipefail

APP_DIR="${MIRZA_APP_DIR:-/var/www/mirza}"

if [[ ${EUID} -ne 0 ]]; then
  echo 'Run this repair script as root.' >&2
  exit 1
fi
if [[ ! -r "$APP_DIR/config.local.php" ]]; then
  echo "Private configuration was not found: $APP_DIR/config.local.php" >&2
  exit 1
fi

mapfile -t PRIVATE_CONFIG < <(php -r '
    $c = require $argv[1];
    foreach (["domain", "bot_token", "webhook_secret"] as $key) {
        $value = (string) ($c[$key] ?? "");
        if ($value === "" || str_contains($value, "\n")) { exit(2); }
        echo $value, PHP_EOL;
    }
' "$APP_DIR/config.local.php")

if [[ ${#PRIVATE_CONFIG[@]} -ne 3 ]]; then
  echo 'Domain, bot token, or webhook secret is missing from config.local.php.' >&2
  exit 1
fi

BOT_DOMAIN="${PRIVATE_CONFIG[0]}"
BOT_TOKEN="${PRIVATE_CONFIG[1]}"
WEBHOOK_SECRET="${PRIVATE_CONFIG[2]}"
[[ $BOT_DOMAIN =~ ^[A-Za-z0-9.-]+$ && $BOT_DOMAIN == *.* ]] || {
  echo 'Configured domain is invalid.' >&2
  exit 1
}

mapfile -t VHOSTS < <(grep -l -E "^[[:space:]]*ServerName[[:space:]]+${BOT_DOMAIN//./\\.}[[:space:]]*$" /etc/apache2/sites-available/*.conf 2>/dev/null || true)
if [[ ${#VHOSTS[@]} -eq 0 ]]; then
  echo "No Apache VirtualHost found for $BOT_DOMAIN." >&2
  exit 1
fi

for vhost in "${VHOSTS[@]}"; do
  if ! grep -Fq "DocumentRoot $APP_DIR" "$vhost"; then
    echo "Refusing unrelated VirtualHost: $vhost" >&2
    exit 1
  fi
  cp -a "$vhost" "${vhost}.before-webhook-repair"
  php -r '
      $path = $argv[1];
      $text = file_get_contents($path);
      if ($text === false) { exit(2); }
      $fixed = str_replace("|local-webhook-router\\.php", "", $text);
      if (!str_contains($fixed, "MIRZA_LOG_PROTECTION")) {
          $protection = "    # MIRZA_LOG_PROTECTION\n"
              . "    <FilesMatch \"^(?:error_log|log\\.txt|.*\\.log)$\">\n"
              . "        Require all denied\n"
              . "    </FilesMatch>\n"
              . "    <LocationMatch \"^/(?:logs)(?:/|$)\">\n"
              . "        Require all denied\n"
              . "    </LocationMatch>\n\n";
          $fixed = str_replace("</VirtualHost>", $protection . "</VirtualHost>", $fixed);
      }
      if (file_put_contents($path, $fixed, LOCK_EX) === false) { exit(3); }
  ' "$vhost"
  echo "Repaired: $vhost"
done

apache2ctl configtest
systemctl reload apache2

WEBHOOK_RESPONSE="$(curl -fsS -X POST "https://api.telegram.org/bot${BOT_TOKEN}/setWebhook" \
  --data-urlencode "url=https://${BOT_DOMAIN}/telegram/webhook" \
  --data-urlencode "secret_token=${WEBHOOK_SECRET}" \
  --data-urlencode 'drop_pending_updates=false')"
if [[ $WEBHOOK_RESPONSE != *'"ok":true'* ]]; then
  echo "Telegram rejected the webhook: $WEBHOOK_RESPONSE" >&2
  exit 1
fi

ROUTE_STATUS="$(curl -sS -o /tmp/mirza-webhook-check.out -w '%{http_code}' \
  -X POST "https://${BOT_DOMAIN}/telegram/webhook" \
  -H "X-Telegram-Bot-Api-Secret-Token: ${WEBHOOK_SECRET}" \
  -H 'Content-Type: application/json' \
  --data '{"update_id":0}')"
if [[ $ROUTE_STATUS != 200 ]]; then
  echo "Webhook route returned HTTP $ROUTE_STATUS." >&2
  tail -c 500 /tmp/mirza-webhook-check.out >&2 || true
  exit 1
fi
rm -f /tmp/mirza-webhook-check.out

curl -fsS "https://api.telegram.org/bot${BOT_TOKEN}/getWebhookInfo" | php -r '
    $data = json_decode(stream_get_contents(STDIN), true);
    $info = $data["result"] ?? [];
    echo "Webhook URL: ", ($info["url"] ?? "missing"), PHP_EOL;
    echo "Pending updates: ", ($info["pending_update_count"] ?? "unknown"), PHP_EOL;
    echo "Last error: ", ($info["last_error_message"] ?? "none"), PHP_EOL;
'

echo 'Webhook repair completed successfully.'
