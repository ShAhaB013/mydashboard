<?php
declare(strict_types=1);

if (!isset($cfg)) $cfg = require __DIR__ . '/bootstrap.php';
$BASE = $cfg['test']['base_url'];

Assert::group('21_sessions_self_service');

$passA = 'ZzTest!UserA2026!';
$passB = 'ZzTest!UserB2026!';
$userA = Fixtures::uniq('sessA');
$userB = Fixtures::uniq('sessB');
Fixtures::createUser(['username' => $userA, 'password_hash' => password_hash($passA, PASSWORD_BCRYPT)]);
Fixtures::createUser(['username' => $userB, 'password_hash' => password_hash($passB, PASSWORD_BCRYPT)]);

Assert::test('my_sessions بدون لاگین → 401', function () use ($BASE) {
    $http = new HttpClient($BASE);
    $res = $http->get('/api.php?action=my_sessions');
    Assert::statusEq($res, 401, 'my_sessions بدون لاگین باید 401 بدهد');
});

Assert::test('my_sessions بعد از لاگین → شامل نشست جاری', function () use ($BASE, $userA, $passA) {
    $http = new HttpClient($BASE);
    $http->loginAs($userA, $passA);
    $res = $http->get('/api.php?action=my_sessions');
    Assert::jsonOk($res, 'my_sessions باید ok:true بدهد');
    $rows = $res['json']['sessions'] ?? [];
    $hasCurrent = false;
    foreach ($rows as $r) if (($r['is_current'] ?? false) === true) $hasCurrent = true;
    Assert::true($hasCurrent, 'باید یک نشست is_current:true وجود داشته باشد');
});

Assert::test('کاربر A نمی‌تواند نشست کاربر B را با شناسه جعلی ترمینیت کند', function () use ($BASE, $userA, $passA, $userB, $passB) {
    $httpB = new HttpClient($BASE);
    $httpB->loginAs($userB, $passB);
    $resB = $httpB->get('/api.php?action=my_sessions');
    $sessionIdB = $resB['json']['sessions'][0]['id'] ?? null;
    Assert::true($sessionIdB !== null, 'باید نشست فعال B پیدا شود');
    if ($sessionIdB === null) return;

    $httpA = new HttpClient($BASE);
    $httpA->loginAs($userA, $passA);
    $httpA->postJson('/api.php?action=terminate_my_session', ['session_id' => $sessionIdB]);

    // نشست B هنوز باید فعال باشد (terminateOwned فقط اگر user_id مالک باشد حذف می‌کند)
    $resB2 = $httpB->get('/api.php?action=my_sessions');
    Assert::jsonOk($resB2, 'نشست B باید بعد از تلاش A هنوز معتبر باشد (my_sessions موفق)');
});

Assert::test('terminate_my_session با session_id خودش → موفق و self:true', function () use ($BASE, $userA, $passA) {
    $http = new HttpClient($BASE);
    $http->loginAs($userA, $passA);
    $res1 = $http->get('/api.php?action=my_sessions');
    $sid = $res1['json']['sessions'][0]['id'] ?? null;
    Assert::true($sid !== null, 'باید نشست فعال یافت شود');
    if ($sid === null) return;
    $res2 = $http->postJson('/api.php?action=terminate_my_session', ['session_id' => $sid]);
    Assert::jsonOk($res2, 'ترمینیت نشست خود باید موفق باشد');
    Assert::eq(true, $res2['json']['self'] ?? null, 'self باید true باشد');
});

Fixtures::deleteUsersByPrefix(false);
