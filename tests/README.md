# مجموعه تست API — داشبورد

تست‌های PHP CLI + cURL برای کل سطح API (`api.php`, `admin.php`) از نظر عملکرد،
امنیت، یکپارچگی داده، بهینگی و رفتار rate-limit. هیچ وابستگی Composer ندارد.

## پیش‌نیاز

1. XAMPP (MySQL) روشن باشد و `config.php` یک سطح بالاتر از `das/` موجود باشد.
2. سرور dev را در یک ترمینال روشن نگه دارید:
   ```
   php -S 127.0.0.1:8080 -t . dev-router.php
   ```
3. حساب‌های تستی ثابت را یک‌بار seed کنید:
   ```
   php tests\seed\001_test_accounts.php
   ```

## اجرا

```
php tests\run_all.php
```

یا یک فایل مشخص حین توسعه:

```
php tests\30_admin_gate_csrf_authz.php
```

خروجی: جدول pass/fail در کنسول + گزارش JSON در `tests/results/run-*.json`.
Exit code غیرصفر یعنی حداقل یک FAIL واقعی وجود دارد (WARN های نرم پرفورمنس باعث exit≠0 نمی‌شوند).

## ایمنی

- همه‌ی داده‌های تولیدی این مجموعه با پیشوند `zztest_` مشخص و در پایان هر فایل/در پایان `run_all.php` پاک می‌شوند.
- حساب‌های ثابت `zztest_admin` / `zztest_user` / `zztest_locked` هرگز حذف نمی‌شوند؛ فقط رمز/نقش/وضعیت‌شان توسط seed ری‌ست می‌شود.
- تست‌های rate-limit روی IP واقعی کلاینت (127.0.0.1) کار می‌کنند و همیشه در `finally` ردیف `login_rate_limit` مربوطه را پاک می‌کنند تا لاگین واقعی توسعه‌دهنده قفل نشود.
- ارسال ایمیل واقعی (`test_email`, OTP واقعی `forgot_password`) به‌صورت پیش‌فرض **skip** می‌شود. برای فعال‌سازی آگاهانه:
  ```
  set TESTS_ALLOW_EMAIL=1
  php tests\run_all.php
  ```
- تست‌های تنزل نقش/TTL نشست فقط روی حساب‌های `zztest_*` عمل می‌کنند.

## ساختار

```
lib/           کلاینت HTTP، Assert، Fixtures (کمک‌کننده DB)، Reporter
seed/          seed یک‌باره‌ی حساب‌های تستی
00-60_*.php    فایل‌های تست به ترتیب فاز (اجرا با numeric prefix مرتب می‌شود)
run_all.php    اجراکننده کامل + گزارش + sweep نهایی
results/       خروجی JSON هر اجرا (gitignore شده)
```
