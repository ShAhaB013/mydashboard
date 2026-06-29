# das — داشبورد ابزارهای کمکی

داشبورد فارسی (RTL) برای دسترسی به مجموعه‌ای از ابزارهای کمکی، با پنل مدیریت،
احراز هویت (ثبت‌نام + تایید ایمیل + فراموشی رمز)، و سیستم اعلان‌ها.
نوشته‌شده با PHP خام (بدون فریم‌ورک) روی معماری MVC سبک.

## نیازمندی‌ها

- **PHP 8.0+** (کد از `match` و union type استفاده می‌کند؛ PHP 7.4 کار نمی‌کند)
- **MySQL 8** (یا MariaDB سازگار)
- وب‌سرور با پشتیبانی از `.htaccess` (Apache/LiteSpeed) برای URLهای تمیز و هاردنینگ

## ساختار پروژه

```
das/                         ← همین پوشه = webroot (روی هاست: public_html)
│
│   ── نقاط ورود (entry points؛ تنها فایل‌های PHP که مستقیم سرو می‌شوند) ──
├── index.php                داشبورد عمومی
├── login.php                ورود / ثبت‌نام / فراموشی رمز
├── profile.php              تنظیمات حساب کاربر
├── notifications.php        تاریخچه و جستجوی اعلان‌ها
├── admin.php                پنل مدیریت (?page= صفحه، ?api= JSON)
├── api.php                  API عمومی (?action=…)
│
│   ── فایل‌های مشترک (include؛ مستقیم سرو نمی‌شوند، با .htaccess deny شده‌اند) ──
├── bootstrap.php            راه‌اندازی مشترک: autoload + config + DB + session
├── version.php              تنها منبع نسخه + نسخه‌گذاری asset
├── dev-router.php           روتر مخصوص سرور توسعه (php -S) — شبیه‌ساز .htaccess
│
│   ── بک‌اند MVC (پوشه‌ی app/ کاملا deny؛ فقط از طریق include بارگذاری می‌شود) ──
├── app/
│   ├── Core/                زیرساخت: DB, Router, PublicRouter, Request, Response,
│   │                        UserSession, Mailer, Validator, PasswordPolicy, …
│   ├── Models/              لایه دیتابیس (User, Tool, Notification, Settings, …)
│   ├── Controllers/         ادمین (Tool/User/Access/…) + عمومی (App/Auth/Feed)
│   └── Views/               قالب‌های پنل مدیریت (dashboard, users, settings, …)
│
│   ── دارایی‌های ثابت (یک ریشه‌ی واحد) ──
├── assets/
│   ├── css/                 استایل صفحات عمومی (style, profile, notifications, datepicker)
│   ├── js/                  اسکریپت صفحات عمومی (script, theme, login, field, …)
│   └── admin/               CSS/JS پنل مدیریت (admin.*, notifications-admin.*)
│
├── data/                    ذخیره JSON آیکون‌ها/دکوراتورها (فقط فایل‌سیستم)
└── fonts/                   فونت‌های Vazir / IRANSans
```

### معماری مسیریابی (دو جریان جدا)

- **api.php** → `PublicRouter` → کنترلرهای عمومی (`AppController` / `AuthController`
  / `FeedController`). بدون CSRF؛ با `?action=…`.
- **admin.php** → `Router` → کنترلرهای ادمین. ابتدا گیت احراز هویت + نقش admin
  (مرجع: DB، نه سشن)، سپس CSRF، سپس `?api=…` برای JSON یا `?page=…` برای قالب‌ها.

هر دو نقطه‌ی ورود از `bootstrap.php` مشترک استفاده می‌کنند (یک نقشه‌ی autoload یگانه،
بارگذاری config، اتصال DB، شروع نشست).

### رابط کاربری (طراحی یکپارچه، سبک تلگرام)

طراحی فرانت‌اند روی **دو فایل CSS موازی** بنا شده که توکن‌هایشان هم‌ارز است و باید
هم‌زمان ویرایش شوند: `assets/css/style.css` (صفحات عمومی) و
`assets/admin/admin.css` (پنل مدیریت).

- **هدر واحد `.app-header`** در همه‌ی صفحات: نوار چسبان شیشه‌ای ۵۶px با دکمه‌های
  آیکونی دایره‌ای `.hdr-btn`؛ دکمه‌ی بازگشت سمت چپ، عنوان + نقطه‌ی وضعیت سمت راست.
- **توکن‌های مشترک**: یک پالت رنگ یکسان (روشن/تاریک) + مقیاس radius چهارتایی
  (`--radius-xs` ۸ / `--radius-sm` ۱۲ / `--radius-lg` ۲۲ / `--radius-pill` ۹۹۹).
- **افکت ripple** روی همه‌ی آیتم‌های تعاملی (به‌جز کارت‌های ابزار).
- **تغییر تم روشن/تاریک** با `theme.js` و View Transitions API (بدون FOUC).
- **جستجوی سبک تلگرام**: آیکون → نوار جستجوی تمام‌عرض که کارت‌ها را زنده فیلتر می‌کند.

## راه‌اندازی

1. **پیکربندی:** فایل `dash_config.example.php` را به `dash_config.php` کپی کنید و
   مقادیر دیتابیس را پر کنید. روی هاست واقعی، `dash_config.php` باید **یک سطح
   بالاتر از webroot** قرار گیرد (`bootstrap.php` آن را با `dirname(__DIR__)` می‌خواند).

2. **دیتابیس:** یک دیتابیس MySQL بسازید و اسکیمای جداول را وارد کنید
   (`users`, `tools`, `tool_access`, `category_access`, `notifications`,
   `notification_badges`, `notification_reads`, `login_rate_limit`).

3. **اجرا (تولید):** پوشه را در `public_html` قرار دهید؛ `.htaccess` بقیه را
   مدیریت می‌کند (URLهای تمیز، deny فایل‌های حساس، کش).

   **اجرا (توسعه‌ی محلی):** چون `php -S` فایل `.htaccess` را نادیده می‌گیرد،
   با روتر اجرا کنید:
   ```
   php -S 127.0.0.1:8080 dev-router.php
   ```

## نکات امنیتی

- `dash_config.php` (کریدنشال DB) و دامپ‌های `*.sql` و آرشیوها در `.gitignore`
  هستند و **نباید** کامیت شوند. دامپ دیتابیس (که شامل هش پسورد کاربرهاست)
  عمدا در این ریپو نیست و باید جداگانه و بیرون از webroot نگهداری شود.
- فایل‌های حساس (config، دامپ، بکاپ) باید **بیرون از webroot** قرار گیرند:
  `dash_config.php` یک سطح بالاتر از webroot (همان‌جا که `bootstrap.php` با
  `dirname(__DIR__)` می‌خواند)، و دامپ/بکاپ کلا خارج از پوشه‌ی منتشرشده.
  `.htaccess` به‌عنوان لایه‌ی دوم دسترسی مستقیم به آن‌ها را هم می‌بندد.
- این پوشه (webroot) عمدا **هیچ فایل حساسی ندارد** — فقط
  `dash_config.example.php` به‌عنوان قالب موجود است.
