# migrations — مهاجرت‌های دیتابیس

تغییرات schema بعد از dump پایه (`vdupegut_dasmsh.sql`) این‌جا به‌صورت فایل‌های SQL شماره‌دار نگهداری و با `migrate.php` به ترتیب اعمال می‌شوند. هر فایل فقط یک‌بار اجرا و در جدول `schema_migrations` ثبت می‌شود.

## قرارداد نام‌گذاری

```
NNN_شرح-کوتاه-انگلیسی.sql        (سه رقم + underscore)
001_add_index_notifications_created.sql
002_add_avatar_column_users.sql
```

- شماره‌ها ترتیب اجرا را تعیین می‌کنند؛ هرگز فایل اعمال‌شده را ویرایش نکنید — مهاجرت جدید بسازید.
- statementهای idempotent ترجیح دارند (`CREATE TABLE IF NOT EXISTS`, `DROP INDEX IF EXISTS`) چون DDL در MySQL تراکنش‌پذیر نیست و در صورت خطای وسط کار، اجرای مجدد باید امن باشد.
- کامنت با `--` در ابتدای خط؛ جداکننده statement «;» در پایان خط. (DELIMITER سفارشی/stored procedure پشتیبانی نمی‌شود.)

## دستورها

```bash
php migrations/migrate.php              # اعمال مهاجرت‌های در انتظار
php migrations/migrate.php --status     # چه چیزی اعمال شده / در انتظار است
php migrations/migrate.php --dry-run    # فقط نمایش SQL بدون اجرا
php migrations/migrate.php --baseline   # ثبت همه به‌عنوان اعمال‌شده بدون اجرا
```

## سناریوها

**نصب تازه (dev یا سرور جدید):** ابتدا dump کامل را import کنید، سپس مهاجرت‌ها را اجرا کنید:

```bash
mysql -u root vdupegut_dasmsh < vdupegut_dasmsh.sql
php migrations/migrate.php
```

**دیتابیس موجود که تغییرات را از قبل دارد** (مثلا تغییر دستی روی production اعمال شده یا dump جدیدتر است): با `--baseline` فایل‌ها بدون اجرا ثبت می‌شوند تا دوباره اجرا نشوند.

**production (cPanel):** از Terminal cPanel یا SSH داخل webroot:

```bash
php migrations/migrate.php --status   # اول همیشه وضعیت را ببینید
php migrations/migrate.php
```

دسترسی وب به این پوشه با `.htaccess` (`Require all denied`) و گارد `PHP_SAPI !== 'cli'` در خود runner بسته است.

## بعد از تغییر schema

پس از هر مهاجرت روی production، بهتر است dump پایه (`vdupegut_dasmsh.sql`) هم با نسخه جدید phpMyAdmin جایگزین شود تا نصب تازه همیشه از schema روز شروع کند (مهاجرت‌های قدیمی برای نصب تازه با `--baseline` ثبت می‌شوند).
