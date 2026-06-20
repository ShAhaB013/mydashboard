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
├── index.php                نقطه ورود داشبورد عمومی
├── login.php  profile.php  notifications.php   صفحات کاربر
├── admin.php                نقطه ورود پنل مدیریت (?page= صفحه، ?api= JSON)
├── api.php                  API عمومی (?action=…)
├── bootstrap.php            راه‌اندازی مشترک: autoload + config + DB + session
├── router.php               روتر مخصوص سرور توسعه (php -S) — شبیه‌ساز .htaccess
├── version.php              تنها منبع نسخه + asset versioning
├── admin/                   بک‌اند مشترک (هم api.php و هم admin.php)
│   ├── Core/                DB, Router, UserSession, Mailer, Validator, …
│   ├── Models/              لایه دیتابیس
│   ├── Controllers/         منطق ادمین + کنترلرهای عمومی (App/Auth/Feed)
│   ├── Views/               صفحات پنل مدیریت
│   └── assets/              CSS/JS پنل مدیریت
├── assets/
│   ├── css/                 استایل صفحات عمومی (style, profile, notifications, datepicker)
│   └── js/                  اسکریپت صفحات عمومی (script, theme, login, field, …)
├── data/                    ذخیره JSON آیکون‌ها/دکوراتورها (فقط فایل‌سیستم)
└── fonts/                   فونت‌های Vazir / IRANSans
```

## راه‌اندازی

1. **پیکربندی:** فایل `dash_config.example.php` را به `dash_config.php` کپی کنید و
   مقادیر دیتابیس را پر کنید. روی هاستِ واقعی، `dash_config.php` باید **یک سطح
   بالاتر از webroot** قرار گیرد (`bootstrap.php` آن را با `dirname(__DIR__)` می‌خواند).

2. **دیتابیس:** یک دیتابیس MySQL بسازید و اسکیمای جداول را وارد کنید
   (`users`, `tools`, `tool_access`, `category_access`, `notifications`,
   `notification_badges`, `notification_reads`, `login_rate_limit`).

3. **اجرا (تولید):** پوشه را در `public_html` قرار دهید؛ `.htaccess` بقیه را
   مدیریت می‌کند (URLهای تمیز، deny فایل‌های حساس، کش).

   **اجرا (توسعه‌ی محلی):** چون `php -S` فایل `.htaccess` را نادیده می‌گیرد،
   با روتر اجرا کنید:
   ```
   php -S 127.0.0.1:8080 router.php
   ```

## نکات امنیتی

- `dash_config.php` (کریدنشال DB) و دامپ‌های `*.sql` و آرشیوها در `.gitignore`
  هستند و **نباید** کامیت شوند.
- روی production، فایل‌های حساس (config، دامپ، بکاپ) باید **بیرون از webroot**
  قرار گیرند؛ `.htaccess` به‌عنوان لایه‌ی دوم دسترسی مستقیم به آن‌ها را می‌بندد.
