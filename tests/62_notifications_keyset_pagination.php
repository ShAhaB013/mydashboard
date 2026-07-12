<?php
declare(strict_types=1);

if (!isset($cfg)) $cfg = require __DIR__ . '/bootstrap.php';
$BASE = $cfg['test']['base_url'];
$ACC  = $cfg['test']['accounts'];

Assert::group('62_notifications_keyset_pagination');

// 40 notifications with created_at values close together (a few seconds apart) to also exercise the id tie-breaker
$ids = [];
$base = time() - 1000;
for ($i = 0; $i < 40; $i++) {
    $ids[] = Fixtures::createNotification([
        'title' => Fixtures::uniq('ks' . $i),
        'is_public' => 1, 'target_all_users' => 1,
        'created_at' => date('Y-m-d H:i:s', $base + intdiv($i, 3)), // every 3 rows share the same created_at
    ]);
}

Assert::test('admin list_notifications: پیمایش کامل keyset جلو دقیقا با OFFSET یکی است (بدون تکرار/جاافتادگی)', function () use ($BASE, $ACC) {
    $http = admin_http($BASE, $ACC);
    // we get the reference list directly from the DB (not the API) because NotificationController::list()
    // clamps per_page to 50 and can't return the full reference set in one call.
    $refIds = array_map('intval', DB::run('SELECT id FROM notifications ORDER BY created_at DESC, id DESC')->fetchAll(PDO::FETCH_COLUMN));

    $walked = [];
    $cursor = null;
    $guard = 0;
    do {
        $body = ['per_page' => 7];
        if ($cursor) { $body['cursor'] = $cursor; $body['dir'] = 'next'; }
        $res = $http->postJson('/admin.php?api=list_notifications', $body);
        $walked = array_merge($walked, array_column($res['json']['notifications'], 'id'));
        $cursor = $res['json']['pagination']['next_cursor'] ?? null;
        $guard++;
    } while ($cursor !== null && $guard < 50);

    $prefix = array_slice($refIds, 0, count($walked));
    Assert::eq($prefix, $walked, 'دنباله‌ی keyset باید دقیقا با یک کوئری کامل ORDER BY یکی باشد');
    Assert::eq(count($walked), count(array_unique($walked)), 'نباید هیچ id تکراری در پیمایش وجود داشته باشد');
});

Assert::test('admin list_notifications: پیمایش عقب (prev) دقیقا صفحه‌ی جلو را بازتولید می‌کند', function () use ($BASE, $ACC) {
    $http = admin_http($BASE, $ACC);
    $p1 = $http->postJson('/admin.php?api=list_notifications', ['per_page' => 5]);
    $c1 = $p1['json']['pagination']['next_cursor'];
    $p2 = $http->postJson('/admin.php?api=list_notifications', ['per_page' => 5, 'cursor' => $c1, 'dir' => 'next']);
    $prevCursor = $p2['json']['pagination']['prev_cursor'];

    $back = $http->postJson('/admin.php?api=list_notifications', ['per_page' => 5, 'cursor' => $prevCursor, 'dir' => 'prev']);
    $backIds = array_column($back['json']['notifications'], 'id');
    $p1Ids = array_column($p1['json']['notifications'], 'id');

    Assert::eq($p1Ids, $backIds, 'پیمایش عقب از صفحه‌ی ۲ باید دقیقا صفحه‌ی ۱ را بازتولید کند');
});

Assert::test('صفحه‌ی اول admin list هیچ prev_cursor ندارد', function () use ($BASE, $ACC) {
    $http = admin_http($BASE, $ACC);
    $res = $http->postJson('/admin.php?api=list_notifications', ['per_page' => 5]);
    $hasKey = array_key_exists('prev_cursor', $res['json']['pagination'] ?? []);
    Assert::true($hasKey, 'کلید prev_cursor باید در پاسخ وجود داشته باشد');
    Assert::eq(null, $res['json']['pagination']['prev_cursor'], 'prev_cursor صفحه‌ی اول باید null باشد');
});

Assert::test('notifications.php (تاریخچه عمومی): after= دقیقا صفحه‌ی بعدی OFFSET را می‌دهد', function () use ($BASE) {
    $http = new HttpClient($BASE);
    $r1 = $http->get('/notifications?page=1&pp=10');
    preg_match('/href="\/notifications\?after=([^"&]+)/', $r1['body'], $m);
    Assert::true(isset($m[1]), 'باید لینک after= در صفحه‌ی اول وجود داشته باشد');
    if (!isset($m[1])) return;

    $r2page = $http->get('/notifications?page=2&pp=10');
    $r2cursor = $http->get('/notifications?after=' . $m[1] . '&pp=10');

    preg_match_all('/data-id="(\d+)"/', $r2page['body'], $mp);
    preg_match_all('/data-id="(\d+)"/', $r2cursor['body'], $mc);
    Assert::eq($mp[1], $mc[1], 'مسیر after=(keyset) باید همان آیتم‌های page=2 (OFFSET) را بدهد');
});

foreach ($ids as $id) DB::run('DELETE FROM notifications WHERE id=:id', [':id' => $id]);
Fixtures::deleteNotificationsByPrefix();
