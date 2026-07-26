#!/usr/bin/env bash
set -Eeuo pipefail

APP_DIR="${MIRZA_APP_DIR:-/var/www/mirza}"
CONFIG_FILE="$APP_DIR/config.local.php"

if [[ ${EUID} -ne 0 ]]; then
  echo 'این ابزار باید با دسترسی root اجرا شود.' >&2
  exit 1
fi
if [[ ! $APP_DIR =~ ^/var/www/[A-Za-z0-9._-]+$ || ! -r $CONFIG_FILE ]]; then
  echo "نصب معتبر میرزا در مسیر $APP_DIR پیدا نشد." >&2
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
    if (!is_array($data)) { echo "پاسخ نامعتبر"; exit(2); }
    if (empty($data["ok"])) { echo $data["description"] ?? "خطای تلگرام"; exit(1); }
    echo "ok";
  '
}

validate_token() {
  local token="$1" result
  [[ $token =~ ^[0-9]{6,12}:[A-Za-z0-9_-]{30,}$ ]] || {
    echo 'ساختار توکن نامعتبر است.' >&2
    return 1
  }
  result="$(curl -fsS "https://api.telegram.org/bot${token}/getMe" | telegram_result)" || {
    echo "توکن توسط تلگرام رد شد: $result" >&2
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
  echo "دامنه: https://${domain}"
  echo "مسیر برنامه: ${APP_DIR}"
  echo "Apache: $(systemctl is-active apache2 2>/dev/null || true)"
  echo "MySQL: $(systemctl is-active mysql 2>/dev/null || true)"
  echo "Health HTTP: $(curl -sS -o /dev/null -w '%{http_code}' "https://${domain}/health.php" || echo unavailable)"
  curl -fsS "https://api.telegram.org/bot${token}/getWebhookInfo" | php -r '
    $data=json_decode(stream_get_contents(STDIN), true); $i=$data["result"] ?? [];
    echo "Webhook: ", ($i["url"] ?? "ثبت نشده"), PHP_EOL;
    echo "Pending: ", ($i["pending_update_count"] ?? "نامشخص"), PHP_EOL;
    echo "آخرین خطا: ", ($i["last_error_message"] ?? "ندارد"), PHP_EOL;
  ' || echo 'دریافت وضعیت تلگرام ناموفق بود.'
  echo
}

change_token() {
  local old_token new_token username config_backup
  old_token="$(get_config bot_token)"
  read -r -s -p 'توکن جدید BotFather: ' new_token </dev/tty; echo
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
    echo 'ثبت Webhook توکن جدید شکست خورد؛ تنظیم قبلی بازگردانده شد.' >&2
    return 1
  fi
  rm -f -- "$config_backup"
  if [[ $old_token != "$new_token" ]]; then
    curl -fsS -X POST "https://api.telegram.org/bot${old_token}/deleteWebhook" >/dev/null 2>&1 || true
  fi
  echo "توکن جدید برای @${username} ذخیره و Webhook ثبت شد."
}

repair_webhook() {
  if [[ -x "$APP_DIR/scripts/repair_webhook.sh" ]]; then
    "$APP_DIR/scripts/repair_webhook.sh"
  else
    register_webhook
    echo 'Webhook دوباره ثبت شد.'
  fi
}

change_admin_password() {
  local first second
  read -r -s -p 'رمز جدید پنل (حداقل ۱۲ کاراکتر): ' first </dev/tty; echo
  read -r -s -p 'تکرار رمز: ' second </dev/tty; echo
  [[ ${#first} -ge 12 ]] || { echo 'رمز کوتاه است.' >&2; return 1; }
  [[ $first == "$second" ]] || { echo 'دو رمز یکسان نیستند.' >&2; return 1; }
  PANEL_PASSWORD="$first" php -r '
    require $argv[1] . "/config.php";
    $hash=password_hash((string)getenv("PANEL_PASSWORD"), PASSWORD_BCRYPT, ["cost"=>12]);
    $stmt=$pdo->prepare("UPDATE admin SET password=? WHERE username=?");
    $stmt->execute([$hash, "admin"]);
    if ($stmt->rowCount() < 1) { fwrite(STDERR, "Admin not found\n"); exit(2); }
  ' "$APP_DIR"
  echo 'رمز پنل مدیریت تغییر کرد.'
}

create_backup() {
  local db_host db_name db_user db_pass stamp backup_dir cnf
  db_host="$(get_config db_host)"; db_name="$(get_config db_name)"
  db_user="$(get_config db_user)"; db_pass="$(get_config db_pass)"
  [[ $db_name =~ ^[A-Za-z0-9_]+$ ]] || { echo 'نام دیتابیس ناامن است.' >&2; return 1; }
  stamp="$(date +%Y%m%d-%H%M%S)"; backup_dir="/var/backups/mirza/${stamp}"
  install -d -m 0700 "$backup_dir"
  cnf="$(mktemp /tmp/mirza-mysql.XXXXXX)"; chmod 0600 "$cnf"
  printf '[client]\nhost=%s\nuser=%s\npassword=%s\n' "$db_host" "$db_user" "$db_pass" > "$cnf"
  if ! mysqldump --defaults-extra-file="$cnf" --single-transaction --routines --triggers "$db_name" | gzip -9 > "$backup_dir/database.sql.gz"; then
    rm -f -- "$cnf"
    echo 'Backup دیتابیس ناموفق بود.' >&2
    return 1
  fi
  cp -a "$CONFIG_FILE" "$backup_dir/config.local.php"
  chmod 0600 "$backup_dir"/*
  rm -f -- "$cnf"
  echo "Backup ساخته شد: $backup_dir"
}

uninstall_bot() {
  local confirm token domain db_name db_user
  echo 'هشدار: برنامه، دیتابیس، کاربر دیتابیس، Cron و VirtualHost حذف می‌شوند.'
  echo 'Backupهای /var/backups/mirza حذف نمی‌شوند.'
  read -r -p 'برای ادامه دقیقاً REMOVE sell-bot را بنویس: ' confirm </dev/tty
  [[ $confirm == 'REMOVE sell-bot' ]] || { echo 'لغو شد.'; return 0; }
  token="$(get_config bot_token)"; domain="$(get_config domain)"
  db_name="$(get_config db_name)"; db_user="$(get_config db_user)"
  [[ $db_name =~ ^[A-Za-z0-9_]+$ && $db_user =~ ^[A-Za-z0-9_]+$ ]] || {
    echo 'شناسه دیتابیس ناامن است؛ حذف متوقف شد.' >&2; return 1;
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
  echo 'ربات و اجزای نصب‌شده حذف شدند. Backupها حفظ شدند.'
  exit 0
}

while true; do
  echo '━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━'
  echo ' مدیریت Sell Bot Premium'
  echo '━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━'
  echo '1) وضعیت سرویس و Webhook'
  echo '2) تغییر توکن ربات تلگرام'
  echo '3) تعمیر و ثبت مجدد Webhook'
  echo '4) تغییر رمز پنل مدیریت'
  echo '5) تهیه Backup'
  echo '6) حذف کامل ربات از سرور'
  echo '0) خروج'
  read -r -p 'انتخاب: ' choice </dev/tty
  case "$choice" in
    1) show_status ;;
    2) change_token ;;
    3) repair_webhook ;;
    4) change_admin_password ;;
    5) create_backup ;;
    6) uninstall_bot ;;
    0) exit 0 ;;
    *) echo 'گزینه نامعتبر است.' ;;
  esac
done
