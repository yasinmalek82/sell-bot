#!/usr/bin/env bash
set -Eeuo pipefail

APP_DIR="${MIRZA_APP_DIR:-/var/www/mirza}"
CONFIG_FILE="$APP_DIR/config.local.php"

if [[ ${EUID} -ne 0 ]]; then
  echo 'This tool must be run as root.' >&2
  exit 1
fi
if [[ ! $APP_DIR =~ ^/var/www/[A-Za-z0-9._-]+$ || ! -r $CONFIG_FILE ]]; then
  echo "No valid Mirza installation was found at $APP_DIR." >&2
  exit 1
fi

get_config() {
  php -r '$c=require $argv[1]; echo (string)($c[$argv[2]] ?? "");' "$CONFIG_FILE" "$1"
}

set_config() {
  local key="$1" value="$2"
  php -r '
    $path=$argv[1]; $key=$argv[2]; $value=$argv[3];
    $config=require $path;
    if (!is_array($config)) { fwrite(STDERR, "Invalid config\n"); exit(2); }
    $config[$key]=$value;
    $temp=tempnam(dirname($path), ".config-");
    $php="<?php\nreturn " . var_export($config, true) . ";\n";
    if (file_put_contents($temp, $php, LOCK_EX) === false) { exit(3); }
    chmod($temp, 0640);
    if (!rename($temp, $path)) { @unlink($temp); exit(4); }
  ' "$CONFIG_FILE" "$key" "$value"
  chown root:www-data "$CONFIG_FILE"
  chmod 0640 "$CONFIG_FILE"
}

telegram_result() {
  php -r '
    $data=json_decode(stream_get_contents(STDIN), true);
    if (!is_array($data)) { echo "Invalid response"; exit(2); }
    if (empty($data["ok"])) { echo $data["description"] ?? "Telegram error"; exit(1); }
    echo "ok";
  '
}

validate_token() {
  local token="$1" result
  [[ $token =~ ^[0-9]{6,12}:[A-Za-z0-9_-]{30,}$ ]] || {
    echo 'The token format is invalid.' >&2
    return 1
  }
  result="$(curl -fsS "https://api.telegram.org/bot${token}/getMe" | telegram_result)" || {
    echo "Telegram rejected the token: $result" >&2
    return 1
  }
}

register_webhook() {
  local token domain secret response
  token="$(get_config bot_token)"
  domain="$(get_config domain)"
  secret="$(get_config webhook_secret)"
  response="$(curl -fsS -X POST "https://api.telegram.org/bot${token}/setWebhook" \
    --data-urlencode "url=https://${domain}/telegram/webhook" \
    --data-urlencode "secret_token=${secret}" \
    --data-urlencode 'drop_pending_updates=false')"
  printf '%s' "$response" | telegram_result >/dev/null
}

show_status() {
  local token domain
  token="$(get_config bot_token)"
  domain="$(get_config domain)"
  echo
  echo "Domain: https://${domain}"
  echo "Application path: ${APP_DIR}"
  echo "Apache: $(systemctl is-active apache2 2>/dev/null || true)"
  echo "MySQL: $(systemctl is-active mysql 2>/dev/null || true)"
  echo "Health HTTP: $(curl -sS -o /dev/null -w '%{http_code}' "https://${domain}/health.php" || echo unavailable)"
  curl -fsS "https://api.telegram.org/bot${token}/getWebhookInfo" | php -r '
    $data=json_decode(stream_get_contents(STDIN), true); $i=$data["result"] ?? [];
    echo "Webhook: ", ($i["url"] ?? "not registered"), PHP_EOL;
    echo "Pending updates: ", ($i["pending_update_count"] ?? "unknown"), PHP_EOL;
    echo "Last error: ", ($i["last_error_message"] ?? "none"), PHP_EOL;
  ' || echo 'Unable to retrieve Telegram status.'
  echo
}

change_token() {
  local old_token new_token username config_backup
  old_token="$(get_config bot_token)"
  read -r -s -p 'New BotFather token: ' new_token </dev/tty; echo
  validate_token "$new_token" || return 1
  username="$(curl -fsS "https://api.telegram.org/bot${new_token}/getMe" | php -r '
    $d=json_decode(stream_get_contents(STDIN), true); echo $d["result"]["username"] ?? "";
  ')"
  config_backup="$(mktemp /tmp/mirza-config-before-token.XXXXXX)"
  cp -a "$CONFIG_FILE" "$config_backup"
  chmod 0600 "$config_backup"
  set_config bot_token "$new_token"
  set_config bot_username "$username"
  # Repair the Apache route as well as registering the webhook. This keeps
  # servers installed with older releases working after a token change.
  if ! repair_webhook; then
    cp -a "$config_backup" "$CONFIG_FILE"
    chown root:www-data "$CONFIG_FILE"; chmod 0640 "$CONFIG_FILE"
    rm -f -- "$config_backup"
    echo 'Registering the new webhook failed; the previous configuration was restored.' >&2
    return 1
  fi
  rm -f -- "$config_backup"
  if [[ $old_token != "$new_token" ]]; then
    curl -fsS -X POST "https://api.telegram.org/bot${old_token}/deleteWebhook" >/dev/null 2>&1 || true
  fi
  echo "The new token for @${username} was saved and its webhook was registered."
}

repair_webhook() {
  if [[ -x "$APP_DIR/scripts/repair_webhook.sh" ]]; then
    "$APP_DIR/scripts/repair_webhook.sh"
  else
    register_webhook
    echo 'The webhook was registered again.'
  fi
}

change_admin_password() {
  local first second
  read -r -s -p 'New admin password (at least 12 characters): ' first </dev/tty; echo
  read -r -s -p 'Repeat the new password: ' second </dev/tty; echo
  [[ ${#first} -ge 12 ]] || { echo 'The password is too short.' >&2; return 1; }
  [[ $first == "$second" ]] || { echo 'The passwords do not match.' >&2; return 1; }
  PANEL_PASSWORD="$first" php -r '
    require $argv[1] . "/config.php";
    $hash=password_hash((string)getenv("PANEL_PASSWORD"), PASSWORD_BCRYPT, ["cost"=>12]);
    $stmt=$pdo->prepare("UPDATE admin SET password=? WHERE username=?");
    $stmt->execute([$hash, "admin"]);
    if ($stmt->rowCount() < 1) { fwrite(STDERR, "Admin not found\n"); exit(2); }
  ' "$APP_DIR"
  echo 'The admin panel password was changed.'
}

create_backup() {
  local db_host db_name db_user db_pass stamp backup_dir cnf
  db_host="$(get_config db_host)"; db_name="$(get_config db_name)"
  db_user="$(get_config db_user)"; db_pass="$(get_config db_pass)"
  [[ $db_name =~ ^[A-Za-z0-9_]+$ ]] || { echo 'The database name is unsafe.' >&2; return 1; }
  stamp="$(date +%Y%m%d-%H%M%S)"; backup_dir="/var/backups/mirza/${stamp}"
  install -d -m 0700 "$backup_dir"
  cnf="$(mktemp /tmp/mirza-mysql.XXXXXX)"; chmod 0600 "$cnf"
  printf '[client]\nhost=%s\nuser=%s\npassword=%s\n' "$db_host" "$db_user" "$db_pass" > "$cnf"
  if ! mysqldump --defaults-extra-file="$cnf" --single-transaction --routines --triggers "$db_name" | gzip -9 > "$backup_dir/database.sql.gz"; then
    rm -f -- "$cnf"
    echo 'The database backup failed.' >&2
    return 1
  fi
  cp -a "$CONFIG_FILE" "$backup_dir/config.local.php"
  chmod 0600 "$backup_dir"/*
  rm -f -- "$cnf"
  echo "Backup created: $backup_dir"
}

uninstall_bot() {
  local confirm token domain db_name db_user
  echo 'WARNING: The application, database, database user, cron job, and VirtualHost will be removed.'
  echo 'Backups in /var/backups/mirza will be preserved.'
  read -r -p 'Type REMOVE sell-bot exactly to continue: ' confirm </dev/tty
  [[ $confirm == 'REMOVE sell-bot' ]] || { echo 'Cancelled.'; return 0; }
  token="$(get_config bot_token)"; domain="$(get_config domain)"
  db_name="$(get_config db_name)"; db_user="$(get_config db_user)"
  [[ $db_name =~ ^[A-Za-z0-9_]+$ && $db_user =~ ^[A-Za-z0-9_]+$ ]] || {
    echo 'The database identifier is unsafe; uninstall stopped.' >&2; return 1;
  }
  curl -fsS -X POST "https://api.telegram.org/bot${token}/deleteWebhook" >/dev/null 2>&1 || true
  rm -f -- /etc/cron.d/mirza
  for site in "/etc/apache2/sites-available/${domain}.conf" "/etc/apache2/sites-available/${domain}-le-ssl.conf"; do
    if [[ -f $site ]]; then a2dissite "$(basename "$site")" >/dev/null 2>&1 || true; rm -f -- "$site"; fi
  done
  apache2ctl configtest && systemctl reload apache2
  mysql --protocol=socket -e "DROP DATABASE IF EXISTS \`${db_name}\`; DROP USER IF EXISTS '${db_user}'@'localhost'; FLUSH PRIVILEGES;"
  certbot delete --cert-name "$domain" --non-interactive >/dev/null 2>&1 || true
  rm -rf -- "$APP_DIR"
  rm -f -- /usr/local/bin/sell-bot
  echo 'The bot and installed components were removed. Backups were preserved.'
  exit 0
}

while true; do
  echo '━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━'
  echo ' Sell Bot Premium Management'
  echo '━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━'
  echo '1) Service and webhook status'
  echo '2) Change Telegram bot token'
  echo '3) Repair and re-register webhook'
  echo '4) Change admin panel password'
  echo '5) Create backup'
  echo '6) Completely uninstall the bot'
  echo '0) Exit'
  read -r -p 'Select an option: ' choice </dev/tty
  case "$choice" in
    1) show_status ;;
    2) change_token ;;
    3) repair_webhook ;;
    4) change_admin_password ;;
    5) create_backup ;;
    6) uninstall_bot ;;
    0) exit 0 ;;
    *) echo 'Invalid option.' ;;
  esac
done
