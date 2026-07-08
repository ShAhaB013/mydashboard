<?php
declare(strict_types=1);

// ═══════════════════════════════════════════════════════════
// helpers — توابع کمکی مشترک بین فایل‌های تست
// ═══════════════════════════════════════════════════════════

if (!function_exists('admin_http')) {
    /** یک HttpClient لاگین‌شده به‌عنوان ادمین تستی (zztest_admin) با CSRF token آماده */
    function admin_http(string $baseUrl, array $accounts): HttpClient
    {
        $http = new HttpClient($baseUrl);
        $http->loginAs($accounts['admin']['username'], $accounts['admin']['password'], '/admin.php');
        return $http;
    }
}

if (!function_exists('user_http')) {
    /** یک HttpClient لاگین‌شده به‌عنوان کاربر عادی تستی (zztest_user) با CSRF token آماده */
    function user_http(string $baseUrl, array $accounts): HttpClient
    {
        $http = new HttpClient($baseUrl);
        $http->loginAs($accounts['user']['username'], $accounts['user']['password']);
        return $http;
    }
}
