<?php
/**
 * dev-router.php — used only for the local development server (php -S).
 *
 * Since `php -S` ignores .htaccess files, this router simulates the same
 * "clean URLs without .php" behavior defined in the host's .htaccess:
 *   - /            → index.php
 *   - /login       → login.php   (if the file exists)
 *   - /foo.php     → 301 to /foo  (matches the host; api.php is excluded)
 *   - real files (css/js/image/api.php) are served untouched.
 *
 * Run with:
 *   php.exe -S 127.0.0.1:8080 -t <webroot> <webroot>/dev-router.php
 */

$uri  = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
$path = ltrim(rawurldecode($uri), '/');
$root = __DIR__;

// api.php and admin.php always stay untouched: both are called
// programmatically (api.php with ?action=, admin.php with ?api= for JSON
// POSTs and ?page= for pages). A 301 redirect would break these requests
// (POST→GET, dropping the body, and losing the query string) causing a
// "server communication error".
if ($path === 'api.php' || $path === 'admin.php') {
    return false; // let php -S run this file itself
}

// 301: index.php → /
if ($path === 'index.php') {
    header('Location: /', true, 301);
    exit;
}

// 301: foo.php → /foo  (matches the host's .htaccess)
if (preg_match('#^([^/]+)\.php$#', $path, $m)) {
    header('Location: /' . $m[1], true, 301);
    exit;
}

// Let php -S serve existing real files (css/js/woff/image/...) directly
$full = $root . '/' . $path;
if ($path !== '' && is_file($full)) {
    return false;
}

// Root → index.php
if ($path === '') {
    require $root . '/index.php';
    return true;
}

// No extension → .php version if it exists
if (is_file($root . '/' . $path . '.php')) {
    require $root . '/' . $path . '.php';
    return true;
}

// Otherwise let php -S decide (404 or static file)
return false;
