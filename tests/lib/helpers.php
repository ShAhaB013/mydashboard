<?php
declare(strict_types=1);

// ═══════════════════════════════════════════════════════════
// helpers — shared helper functions used across test files
// ═══════════════════════════════════════════════════════════

if (!function_exists('admin_http')) {
    /** an HttpClient logged in as the test admin (zztest_admin) with a CSRF token ready */
    function admin_http(string $baseUrl, array $accounts): HttpClient
    {
        $http = new HttpClient($baseUrl);
        $http->loginAs($accounts['admin']['username'], $accounts['admin']['password'], '/admin.php');
        return $http;
    }
}

if (!function_exists('user_http')) {
    /** an HttpClient logged in as the test regular user (zztest_user) with a CSRF token ready */
    function user_http(string $baseUrl, array $accounts): HttpClient
    {
        $http = new HttpClient($baseUrl);
        $http->loginAs($accounts['user']['username'], $accounts['user']['password']);
        return $http;
    }
}
