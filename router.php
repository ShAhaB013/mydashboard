<?php
/**
 * router.php — فقط برای سرورِ توسعهٔ محلی (php -S) استفاده می‌شود.
 *
 * چون `php -S` فایل‌های .htaccess را نادیده می‌گیرد، این روتر همان رفتارِ
 * «URLهای تمیزِ بدونِ .php» را که در .htaccessِ هاست تعریف شده، شبیه‌سازی می‌کند:
 *   - /            → index.php
 *   - /login       → login.php   (اگر فایل موجود باشد)
 *   - /foo.php     → 301 به /foo  (هماهنگ با هاست؛ api.php مستثناست)
 *   - فایل‌های واقعی (css/js/تصویر/api.php) دست‌نخورده سرو می‌شوند.
 *
 * اجرا:
 *   php.exe -S 127.0.0.1:8080 -t <webroot> <webroot>/router.php
 */

$uri  = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
$path = ltrim(rawurldecode($uri), '/');
$root = __DIR__;

// api.php و admin.php همیشه دست‌نخورده می‌مانند: هر دو به‌صورت برنامه‌ای
// صدا زده می‌شوند (api.php با ?action= ، admin.php با ?api= برای POSTهای JSON
// و با ?page= برای صفحات). ریدایرکتِ 301 این درخواست‌ها را خراب می‌کند
// (POST→GET، حذف بدنه، و افتادنِ کوئری) و باعث «خطا در ارتباط با سرور» می‌شود.
if ($path === 'api.php' || $path === 'admin.php') {
    return false; // php -S خودش این فایل را اجرا می‌کند
}

// ۳۰۱: index.php → /
if ($path === 'index.php') {
    header('Location: /', true, 301);
    exit;
}

// ۳۰۱: foo.php → /foo  (هماهنگ با .htaccessِ هاست)
if (preg_match('#^([^/]+)\.php$#', $path, $m)) {
    header('Location: /' . $m[1], true, 301);
    exit;
}

// فایل‌های واقعیِ موجود (css/js/woff/تصویر/...) را php -S مستقیم سرو کند
$full = $root . '/' . $path;
if ($path !== '' && is_file($full)) {
    return false;
}

// ریشه → index.php
if ($path === '') {
    require $root . '/index.php';
    return true;
}

// بدونِ پسوند → نسخهٔ .php اگر وجود داشته باشد
if (is_file($root . '/' . $path . '.php')) {
    require $root . '/' . $path . '.php';
    return true;
}

// در غیر این صورت بگذار php -S تصمیم بگیرد (۴۰۴ یا فایلِ استاتیک)
return false;
