<?php
declare(strict_types=1);

if (!isset($cfg)) $cfg = require __DIR__ . '/bootstrap.php';
$BASE = $cfg['test']['base_url'];
$ACC  = $cfg['test']['accounts'];

Assert::group('40_security_probes');

$sqli = ["' OR '1'='1", "1; DROP TABLE users--", "' UNION SELECT NULL--", "1' OR '1'='1' -- -"];
$traversal = ['../../../etc/passwd', '..\\..\\config.php', '..%00', '/uploads/notifications/../../../config.php'];

Assert::test('SQLi payloads روی list_tools?search → بدون 500 و بدون افشای داده', function () use ($BASE, $ACC, $sqli) {
    $http = admin_http($BASE, $ACC);
    foreach ($sqli as $p) {
        $res = $http->get('/admin.php?api=list_tools&search=' . urlencode($p));
        Assert::true($res['status'] < 500, "SQLi payload «{$p}» روی list_tools نباید 500 بدهد");
        Assert::jsonOk($res, "SQLi payload «{$p}» باید نتیجه‌ی خالی/تمیز بدهد نه خطا (prepared statements)");
    }
});

Assert::test('SQLi payloads روی list_users?search → بدون 500', function () use ($BASE, $ACC, $sqli) {
    $http = admin_http($BASE, $ACC);
    foreach ($sqli as $p) {
        $res = $http->get('/admin.php?api=list_users&search=' . urlencode($p));
        Assert::true($res['status'] < 500, "SQLi payload «{$p}» روی list_users نباید 500 بدهد");
    }
});

Assert::test('SQLi payload در username لاگین → پیام خطای عمومی، بدون 500', function () use ($BASE, $sqli) {
    foreach ($sqli as $p) {
        $http = new HttpClient($BASE);
        $res = $http->postJson('/api.php?action=login', ['username' => $p, 'password' => 'x'], [], false);
        Assert::true($res['status'] < 500, "SQLi payload «{$p}» در لاگین نباید 500 بدهد");
        Assert::jsonFail($res, "SQLi payload «{$p}» در لاگین نباید موفق شود");
    }
    Fixtures::deleteRateLimitByIp('127.0.0.1', 'user');
});

Assert::test('Path traversal روی path ابزار (add) → رد می‌شود', function () use ($BASE, $ACC, $traversal) {
    $http = admin_http($BASE, $ACC);
    foreach ($traversal as $p) {
        $res = $http->postJson('/admin.php?api=add', ['title' => Fixtures::uniq('trav'), 'path' => $p]);
        Assert::jsonFail($res, "path حاوی «..» باید رد شود: {$p}");
    }
});

Assert::test('Path traversal در image_path اعلان → رد می‌شود', function () use ($BASE, $ACC) {
    $http = admin_http($BASE, $ACC);
    $title = Fixtures::uniq('travimg');
    $res = $http->postJson('/admin.php?api=create_notification', [
        'title' => $title, 'body' => 'x', 'image_path' => '/uploads/notifications/../../../config.php',
    ]);
    Assert::jsonFail($res, 'image_path با .. باید رد شود');
});

Assert::test('CSRF replay: توکن یک نشست منقضی/logout-شده دیگر قابل استفاده نیست', function () use ($BASE, $ACC) {
    $http = new HttpClient($BASE);
    $http->loginAs($ACC['user']['username'], $ACC['user']['password']);
    $token = $http->csrfToken();
    $http->get('/api.php?action=logout');

    // try the same old cookie/token again after logout
    $http->setCsrfToken($token);
    $res = $http->postJson('/api.php?action=change_password', ['current_password' => 'x', 'new_password' => 'y', 'confirm_password' => 'y']);
    Assert::statusEq($res, 401, 'بعد از logout، درخواست حالت‌تغییردهنده باید 401 بدهد (نشست باطل شده)');
});

Assert::test('name() Unicode validator: تزریق کاراکترهای کنترلی/اسکریپت در full_name → رد می‌شود', function () use ($BASE, $ACC) {
    $http = admin_http($BASE, $ACC);
    $res = $http->postJson('/admin.php?api=add_user', [
        'full_name' => '<script>alert(1)</script>', 'username' => Fixtures::uniq('xssname'),
        'email' => Fixtures::uniq('x') . '@example.com', 'password' => 'ZzTest!Xss2026',
    ]);
    Assert::jsonFail($res, 'full_name با تگ HTML باید رد شود (فقط حروف مجاز است)');
});

Fixtures::deleteToolsByPrefix();
Fixtures::deleteNotificationsByPrefix();
Fixtures::deleteUsersByPrefix(false);
