<?php
declare(strict_types=1);

if (!isset($cfg)) $cfg = require __DIR__ . '/bootstrap.php';
$BASE = $cfg['test']['base_url'];
$ACC  = $cfg['test']['accounts'];

Assert::group('61_notifications_bell_cap');

// 120 synthetic public notifications (more than one page — default page size is 20) —
// under the notification_recipients architecture the bell has no overall cap at all;
// this only exercises pagination depth, not a "does it get cut off at N" limit.
$passU = $ACC['user']['password'];
$uid   = (int) DB::run('SELECT id FROM users WHERE username=:u', [':u' => $ACC['user']['username']])->fetchColumn();

$ids = [];
$now = time();
for ($i = 0; $i < 120; $i++) {
    $id = Fixtures::createNotification([
        'title' => Fixtures::uniq('bell' . $i),
        'target_all_users' => 1,
        'created_at' => date('Y-m-d H:i:s', $now - $i), // descending, newest first
    ]);
    $ids[] = $id;
    // mark half of them as already read
    if ($i % 2 === 0) {
        DB::run('INSERT INTO notification_reads (user_id, notification_id, read_at) VALUES (:u,:n,NOW())', [':u' => $uid, ':n' => $id]);
    }
}

Assert::test('notifications بدون لاگین → 401', function () use ($BASE) {
    $http = new HttpClient($BASE);
    $res = $http->get('/api.php?action=notifications');
    Assert::statusEq($res, 401, 'notifications بدون لاگین باید 401 بدهد');
});

Assert::test('فید زنگوله: صفحه اول حداکثر به اندازه limit درخواستی است و کل ۱۲۰ مورد از طریق صفحه‌بندی (cursor) در دسترس‌اند — بدون سقف مصنوعی', function () use ($BASE, $ACC, $ids) {
    $http = new HttpClient($BASE);
    $http->loginAs($ACC['user']['username'], $ACC['user']['password']);

    $res = $http->get('/api.php?action=notifications&limit=20');
    Assert::jsonOk($res, 'notifications باید ok:true بدهد');
    $items = $res['json']['notifications'] ?? [];
    Assert::true(count($items) <= 20, 'اندازه صفحه نباید از limit درخواستی بیشتر شود', ['count' => count($items)]);

    // walk every page via next_cursor and collect all ids belonging to this test's fixtures
    $seen = [];
    $cursor = $res['json']['next_cursor'] ?? null;
    $hasMore = $res['json']['has_more'] ?? false;
    foreach ($items as $it) $seen[(int) $it['id']] = true;
    $safety = 0;
    while ($hasMore && $cursor && $safety++ < 30) {
        $res = $http->get('/api.php?action=notifications&limit=20&before=' . urlencode($cursor));
        Assert::jsonOk($res, 'صفحه بعدی notifications باید ok:true بدهد');
        foreach (($res['json']['notifications'] ?? []) as $it) $seen[(int) $it['id']] = true;
        $cursor  = $res['json']['next_cursor'] ?? null;
        $hasMore = $res['json']['has_more'] ?? false;
    }

    $missing = array_filter($ids, fn($id) => !isset($seen[$id]));
    Assert::true(empty($missing), 'همه ۱۲۰ اعلان تستی باید از طریق صفحه‌بندی بدون سقف در دسترس باشند', ['missing_count' => count($missing)]);
});

Assert::test('فید زنگوله: ترتیب کاملا زمانی (created_at,id) نزولی است — بدون اولویت‌دهی ناخوانده/خوانده', function () use ($BASE, $ACC) {
    $http = new HttpClient($BASE);
    $http->loginAs($ACC['user']['username'], $ACC['user']['password']);
    $res = $http->get('/api.php?action=notifications&limit=50');
    Assert::jsonOk($res, 'notifications باید ok:true بدهد');
    $items = $res['json']['notifications'] ?? [];

    $ordered = true;
    $prev = null;
    foreach ($items as $it) {
        $key = [$it['created_at'], (int) $it['id']];
        if ($prev !== null && ($key[0] > $prev[0] || ($key[0] === $prev[0] && $key[1] > $prev[1]))) {
            $ordered = false;
        }
        $prev = $key;
    }
    Assert::true($ordered, 'آیتم‌ها باید دقیقا بر اساس (created_at,id) نزولی مرتب باشند، صرف‌نظر از وضعیت خوانده/ناخوانده');
});

// deleting notifications automatically clears the dependent notification_reads/
// notification_recipients rows too (ON DELETE CASCADE)
foreach ($ids as $id) DB::run('DELETE FROM notifications WHERE id=:id', [':id' => $id]);
Fixtures::deleteNotificationsByPrefix();

Assert::test('فید زنگوله: منقضی+خوانده حذف می‌شود، منقضی+ناخوانده و فعال+خوانده باقی می‌مانند', function () use ($BASE, $ACC, $uid) {
    $past = time() - 3600; // expired

    $expiredRead   = Fixtures::createNotification(['title' => Fixtures::uniq('exp_read'), 'target_all_users' => 1, 'expires_at' => $past]);
    $expiredUnread = Fixtures::createNotification(['title' => Fixtures::uniq('exp_unread'), 'target_all_users' => 1, 'expires_at' => $past]);
    $activeRead    = Fixtures::createNotification(['title' => Fixtures::uniq('act_read'), 'target_all_users' => 1, 'expires_at' => 0]);

    DB::run('INSERT INTO notification_reads (user_id, notification_id, read_at) VALUES (:u,:n,NOW())', [':u' => $uid, ':n' => $expiredRead]);
    DB::run('INSERT INTO notification_reads (user_id, notification_id, read_at) VALUES (:u,:n,NOW())', [':u' => $uid, ':n' => $activeRead]);

    $http = new HttpClient($BASE);
    $http->loginAs($ACC['user']['username'], $ACC['user']['password']);
    $res = $http->get('/api.php?action=notifications&limit=100');
    Assert::jsonOk($res, 'notifications باید ok:true بدهد');
    $ids = array_column($res['json']['notifications'] ?? [], 'id');

    Assert::true(!in_array($expiredRead, $ids, true), 'اعلان منقضی+خوانده نباید در فید زنگوله باشد');
    Assert::true(in_array($expiredUnread, $ids, true), 'اعلان منقضی+ناخوانده باید در فید زنگوله باقی بماند');
    Assert::true(in_array($activeRead, $ids, true), 'اعلان فعال+خوانده (غیرمنقضی) باید در فید زنگوله باقی بماند');

    foreach ([$expiredRead, $expiredUnread, $activeRead] as $id) DB::run('DELETE FROM notifications WHERE id=:id', [':id' => $id]);
    Fixtures::deleteNotificationsByPrefix();
});
