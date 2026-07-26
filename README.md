# Mirza Premium Bot

نسخه بهینه‌شده ربات فروش اشتراک با پشتیبانی از پنل **3x-ui / Sanaei**، منوی اختصاصی نمایندگان در همان ربات اصلی، قیمت همکاری مستقل و محدودیت IP برای محصولات نامحدود.

## نصب سریع روی سرور

روی Ubuntu 22.04 یا 24.04 با کاربر `root` اجرا کنید:

```bash
curl -fL https://raw.githubusercontent.com/yasinmalek82/sell-bot/v1.0.1/install.sh \
  -o /tmp/sell-bot-install.sh
sudo MIRZA_REPO=yasinmalek82/sell-bot bash /tmp/sell-bot-install.sh
```

نصب‌کننده دامنه، ایمیل SSL، توکن تازه ربات، آیدی عددی ادمین، نام کاربری ربات و رمز پنل مدیریت را می‌پرسد؛ سپس Apache، MySQL، PHP، SSL، دیتابیس، Cron و Webhook را خودکار آماده می‌کند.

> قبل از اجرا، `A Record` دامنه را روی IP سرور تنظیم کنید و توکن قبلی ربات را در BotFather تعویض کنید.

## امکانات این نسخه

- اتصال مستقیم به 3x-ui از طریق `x-ui_single.php`
- محدودیت مستقل IP (`ip_limit`) حتی برای حجم یا زمان نامحدود
- قیمت پایه، قیمت پلن نمایندگی و قیمت اختصاصی هر نماینده
- کیف پول با دفتر کل و کلید idempotency
- منوی متفاوت کاربران عادی و نمایندگان در همان ربات
- ثبت نام مشتری در زمان فروش حضوری
- پنل مدیریت وب با ورود رمزنگاری‌شده و CSRF
- وب‌هوک تلگرام با Secret Token
- نصب SSL معتبر Let's Encrypt
- اجرای Cron از سیستم‌عامل و مسدودبودن مسیرهای داخلی از وب

## پیش‌نیاز سرور

- Ubuntu 22.04 یا 24.04 تمیز
- حداقل 1GB RAM
- دامنه یا زیردامنه متصل به IP سرور (`A Record`)
- بازبودن پورت‌های 80 و 443
- توکن تازه ربات از BotFather
- شناسه عددی ادمین تلگرام

پنل 3x-ui می‌تواند روی همین سرور یا سرور دیگری باشد، ولی باید SSL معتبر و دسترسی API داشته باشد.

## نصب مستقیم از GitHub

فرمان نصب تعاملی:

```bash
curl -fL https://raw.githubusercontent.com/yasinmalek82/sell-bot/v1.0.1/install.sh \
  -o /tmp/sell-bot-install.sh
sudo MIRZA_REPO=yasinmalek82/sell-bot bash /tmp/sell-bot-install.sh
```

برای اجرای کاملاً غیرتعاملی:

```bash
curl -fL https://raw.githubusercontent.com/yasinmalek82/sell-bot/v1.0.1/install.sh \
  -o /tmp/sell-bot-install.sh
sudo env MIRZA_REPO=yasinmalek82/sell-bot \
  BOT_DOMAIN=bot.example.com \
  LETSENCRYPT_EMAIL=admin@example.com \
  BOT_TOKEN='NEW_TELEGRAM_TOKEN' \
  BOT_ADMIN_ID='123456789' \
  BOT_USERNAME='example_bot' \
  PANEL_ADMIN_PASSWORD='A-Strong-Panel-Password' \
  bash /tmp/sell-bot-install.sh
```

رمزهای نصب در `/root/mirza-install.txt` با سطح دسترسی `600` نگه‌داری می‌شوند. تنظیمات خصوصی برنامه در `/var/www/mirza/config.local.php` هستند و داخل Git قرار نمی‌گیرند.

## آدرس‌های مهم

- پنل مدیریت: `https://YOUR-DOMAIN/panel/`
- وب‌هوک تلگرام: `https://YOUR-DOMAIN/telegram/webhook`
- سلامت سرویس: `https://YOUR-DOMAIN/health.php`

مسیرهای `config`، `migrations`، `scripts`، `tests`، `cronbot`، `vendor` و `vpnbot` مستقیماً از وب قابل دسترسی نیستند.

سورس این نسخه در [yasinmalek82/sell-bot](https://github.com/yasinmalek82/sell-bot) نگه‌داری می‌شود و تحت مجوز AGPL-3.0 است.

## نصب دیتابیس بدون نصب کامل سرور

پس از ساخت `config.local.php` و دیتابیس خالی:

```bash
PANEL_ADMIN_PASSWORD='A-Strong-Password' \
SKIP_TELEGRAM_WEBHOOK=1 \
php scripts/install_database.php
```

این فرمان جداول پایه و تمام migrationهای نسخه Premium را به‌ترتیب اجرا می‌کند.

## آزمایش‌ها

```bash
php tests/test_blue_bank_sms_parser.php
php tests/test_telegram_keyboard.php
php tests/test_build_xui_payload.php
php tests/test_xui_payload_match_docs.php
```

تست‌های قیمت‌گذاری و کیف پول به یک دیتابیس نصب‌شده نیاز دارند.

## نکات امنیتی

- توکن رباتی که قبلاً در پیام یا لاگ دیده شده را قبل از نصب واقعی در BotFather تعویض کنید.
- هیچ‌وقت `config.local.php` یا خروجی دیتابیس را Commit نکنید.
- پنل 3x-ui را با SSL معتبر اجرا کنید و API آن را فقط برای IP سرور ربات باز بگذارید.
- از دیتابیس و فایل تنظیمات به‌صورت منظم نسخه پشتیبان رمزنگاری‌شده بگیرید.
