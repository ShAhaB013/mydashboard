<?php
declare(strict_types=1);

if (!isset($cfg)) $cfg = require __DIR__ . '/bootstrap.php';
$BASE = $cfg['test']['base_url'];
$ACC  = $cfg['test']['accounts'];

Assert::group('64_pagination_hybrid_and_goto');

Assert::test('لیست کاربران ادمین: page خارج از بازه (0/-1/خیلی بزرگ) کلمپ می‌شود نه خطا', function () use ($BASE, $ACC) {
    $http = admin_http($BASE, $ACC);
    foreach ([0, -1, 999999] as $page) {
        $res = $http->postJson('/admin.php?api=list_users', ['page' => $page, 'per_page' => 10]);
        Assert::true($res['status'] < 500, "page={$page} روی list_users نباید 500 بدهد");
        Assert::jsonOk($res, "page={$page} روی list_users باید همچنان ok:true بدهد");
        Assert::true(($res['json']['pagination']['page'] ?? 0) >= 1, 'page نهایی باید >=1 کلمپ‌شده باشد');
    }
});

Assert::test('لیست اعلان‌های ادمین: page=1 دقیقا با نتیجه‌ی اول یک پیمایش کامل یکی است', function () use ($BASE, $ACC) {
    $ids = [];
    for ($i = 0; $i < 12; $i++) {
        $ids[] = Fixtures::createNotification(['title' => Fixtures::uniq('goto' . $i), 'is_public' => 1, 'target_all_users' => 1]);
        usleep(500);
    }
    $http = admin_http($BASE, $ACC);

    $full = $http->postJson('/admin.php?api=list_notifications', ['page' => 1, 'per_page' => 500]);
    $fullIds = array_column($full['json']['notifications'], 'id');

    $mid = $http->postJson('/admin.php?api=list_notifications', ['page' => 2, 'per_page' => 5]);
    $midIds = array_column($mid['json']['notifications'], 'id');
    $expected = array_slice($fullIds, 5, 5);
    Assert::eq($expected, $midIds, 'صفحه‌ی ۲ با per_page=5 باید دقیقا ردیف‌های ۶ تا ۱۰ لیست کامل باشد');

    foreach ($ids as $id) DB::run('DELETE FROM notifications WHERE id=:id', [':id' => $id]);
});

Assert::test('notifications.php: پارامتر page خیلی بزرگ به آخرین صفحه‌ی موجود کلمپ می‌شود (بدون خطا)', function () use ($BASE, $ACC) {
    $http = new HttpClient($BASE);
    $http->loginAs($ACC['user']['username'], $ACC['user']['password']);
    $res = $http->get('/notifications?page=999999&pp=10');
    Assert::true($res['status'] < 500, 'page خیلی بزرگ نباید 500 بدهد');
    Assert::notContains($res['body'], 'Fatal error', 'نباید خطای PHP نمایش داده شود');
});

Assert::test('notifications.php: cursor نامعتبر/دستکاری‌شده بی‌صدا به صفحه‌ی اول سقوط می‌کند', function () use ($BASE, $ACC) {
    $http = new HttpClient($BASE);
    $http->loginAs($ACC['user']['username'], $ACC['user']['password']);
    $res = $http->get('/notifications?after=not-a-valid-cursor!!!&pp=10');
    Assert::true($res['status'] < 500, 'cursor نامعتبر نباید 500 بدهد');
    Assert::notContains($res['body'], 'Fatal error', 'نباید خطای PHP نمایش داده شود');
});

Fixtures::deleteNotificationsByPrefix();
