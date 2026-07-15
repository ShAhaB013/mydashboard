<?php
declare(strict_types=1);

if (!isset($cfg)) $cfg = require __DIR__ . '/bootstrap.php';
$BASE = $cfg['test']['base_url'];
$ACC  = $cfg['test']['accounts'];

Assert::group('63_notifications_old_record_reachability');

$oldTitle = Fixtures::uniq('ancient');
$threeYearsAgo = date('Y-m-d H:i:s', time() - 3 * 365 * 24 * 3600);
$oldId = Fixtures::createNotification([
    'title' => $oldTitle, 'target_all_users' => 1,
    'created_at' => $threeYearsAgo,
]);

// also 105 new notifications to push the old record off the bell's first page (default
// page size 20) — the bell has no overall cap under the notification_recipients
// architecture, but the FIRST page is still just "the most recent N", so a record this
// old still won't appear without paginating deep via next_cursor.
$fillerIds = [];
for ($i = 0; $i < 105; $i++) {
    $fillerIds[] = Fixtures::createNotification([
        'title' => Fixtures::uniq('filler' . $i), 'target_all_users' => 1,
    ]);
}

Assert::test('اعلان خیلی قدیمی از صفحه اول فید زنگوله (bell) غایب است', function () use ($BASE, $ACC, $oldId) {
    $http = new HttpClient($BASE);
    $http->loginAs($ACC['user']['username'], $ACC['user']['password']);
    $res = $http->get('/api.php?action=notifications');
    $ids = array_map(fn($n) => (int) $n['id'], $res['json']['notifications'] ?? []);
    Assert::true(!in_array($oldId, $ids, true), 'اعلان سه‌سال‌قبل نباید در صفحه اول فید زنگوله (جدیدترین‌ها) دیده شود');
});

Assert::test('اعلان خیلی قدیمی از صفحه‌ی تاریخچه با جستجو قابل‌دسترسی است', function () use ($BASE, $ACC, $oldTitle) {
    $http = new HttpClient($BASE);
    $http->loginAs($ACC['user']['username'], $ACC['user']['password']);
    $res = $http->get('/notifications?q=' . urlencode($oldTitle));
    Assert::true($res['status'] < 500, 'جستجوی رکورد قدیمی نباید 500 بدهد');
    Assert::contains($res['body'], htmlspecialchars($oldTitle), 'عنوان اعلان قدیمی باید در نتیجه‌ی جستجوی تاریخچه دیده شود — یعنی رکوردهای قدیمی برای همیشه در دسترس می‌مانند');
});

foreach ($fillerIds as $id) DB::run('DELETE FROM notifications WHERE id=:id', [':id' => $id]);
DB::run('DELETE FROM notifications WHERE id=:id', [':id' => $oldId]);
Fixtures::deleteNotificationsByPrefix();
