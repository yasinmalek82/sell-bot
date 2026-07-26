# Mirza Premium Bot

نسخه بهینه‌شده ربات فروش اشتراک با پشتیبانی از پنل **3x-ui / Sanaei**، منوی اختصاصی نمایندگان در همان ربات اصلی، قیمت همکاری مستقل و محدودیت IP برای محصولات نامحدود.

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

برای لینک نصب یک‌مرحله‌ای، این پوشه را در یک مخزن عمومی GitHub قرار دهید. فایل‌های خصوصی و لاگ‌ها توسط `.gitignore` حذف شده‌اند. سپس روی سرور اجرا کنید:

```bash
curl -fsSL https://raw.githubusercontent.com/OWNER/REPO/main/install.sh \
  | sudo MIRZA_REPO=OWNER/REPO bash
```

برای اجرای کاملاً غیرتعاملی:

```bash
curl -fsSL https://raw.githubusercontent.com/OWNER/REPO/main/install.sh | sudo env \
  MIRZA_REPO=OWNER/REPO \
  BOT_DOMAIN=bot.example.com \
  LETSENCRYPT_EMAIL=admin@example.com \
  BOT_TOKEN='NEW_TELEGRAM_TOKEN' \
  BOT_ADMIN_ID='123456789' \
  BOT_USERNAME='example_bot' \
  PANEL_ADMIN_PASSWORD='A-Strong-Panel-Password' \
  bash
```

رمزهای نصب در `/root/mirza-install.txt` با سطح دسترسی `600` نگه‌داری می‌شوند. تنظیمات خصوصی برنامه در `/var/www/mirza/config.local.php` هستند و داخل Git قرار نمی‌گیرند.

## آدرس‌های مهم

- پنل مدیریت: `https://YOUR-DOMAIN/panel/`
- وب‌هوک تلگرام: `https://YOUR-DOMAIN/telegram/webhook`
- سلامت سرویس: `https://YOUR-DOMAIN/health.php`

مسیرهای `config`، `migrations`، `scripts`، `tests`، `cronbot`، `vendor` و `vpnbot` مستقیماً از وب قابل دسترسی نیستند.

## انتشار امن در GitHub

قبل از اولین Push:

```bash
cp config.local.example.php config.local.php
git init
git add .
git status
git commit -m "Initial Mirza Premium release"
git branch -M main
git remote add origin git@github.com:OWNER/REPO.git
git push -u origin main
```

حتماً در خروجی `git status` بررسی کنید که `config.local.php`، لاگ‌ها و پوشه ربات‌های ساخته‌شده وجود نداشته باشند. پروژه تحت AGPL-3.0 است؛ فایل `LICENSE` و اطلاع‌رسانی کد منبع باید حفظ شوند. برای مخزن خصوصی، ابتدا مخزن را با SSH یا GitHub CLI روی سرور Clone کنید و از ریشه پروژه اجرا کنید:

```bash
sudo MIRZA_SOURCE_DIR="$PWD" bash install.sh
```

لینک خام عمومی بدون احراز هویت برای مخزن خصوصی کار نمی‌کند.

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
