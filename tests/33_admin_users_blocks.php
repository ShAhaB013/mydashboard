<?php
declare(strict_types=1);

if (!isset($cfg)) $cfg = require __DIR__ . '/bootstrap.php';
$BASE = $cfg['test']['base_url'];
$ACC  = $cfg['test']['accounts'];

Assert::group('33_admin_users_blocks');

Assert::test('add_user با رمز ضعیف → رد می‌شود', function () use ($BASE, $ACC) {
    $http = admin_http($BASE, $ACC);
    $res = $http->postJson('/admin.php?api=add_user', [
        'full_name' => 'کاربر تست', 'username' => Fixtures::uniq('u'), 'email' => 'zz@example.com', 'password' => 'weak',
    ]);
    Assert::jsonFail($res, 'رمز ضعیف باید رد شود');
});

Assert::test('add_user با username تکراری → رد می‌شود', function () use ($BASE, $ACC) {
    $http = admin_http($BASE, $ACC);
    $username = Fixtures::uniq('dup');
    $body = ['full_name' => 'کاربر تست', 'username' => $username, 'email' => $username . '@example.com', 'password' => 'ZzTest!Dup2026'];
    $res1 = $http->postJson('/admin.php?api=add_user', $body);
    Assert::jsonOk($res1, 'ساخت اول باید موفق باشد');
    $res2 = $http->postJson('/admin.php?api=add_user', $body);
    Assert::jsonFail($res2, 'username تکراری باید رد شود');
});

Assert::test('add_user معتبر → ردیف واقعا در DB ساخته می‌شود', function () use ($BASE, $ACC) {
    $http = admin_http($BASE, $ACC);
    $username = Fixtures::uniq('new');
    $res = $http->postJson('/admin.php?api=add_user', [
        'full_name' => 'کاربر جدید', 'username' => $username, 'email' => $username . '@example.com', 'password' => 'ZzTest!New2026',
    ]);
    Assert::jsonOk($res, 'add_user معتبر باید موفق باشد');
    $row = Fixtures::findUserByUsername($username);
    Assert::true($row !== null, 'کاربر باید در DB ساخته شده باشد');
});

Assert::test('edit_user گارد ضدقفل‌شدن: آخرین ادمین فعال نمی‌تواند تنزل داده شود', function () use ($BASE, $ACC) {
    $http = admin_http($BASE, $ACC);
    // Note: zztest_admin might not be the only active admin, so we can't just assume a third admin exists.
    // Instead, this test checks directly against the zztest_admin account itself: if it is the DB's only active admin, the guard must kick in.
    $activeAdmins = (int) DB::run("SELECT COUNT(*) c FROM users WHERE role='admin' AND is_active=1")->fetchColumn();
    $adminRow = Fixtures::findUserByUsername($ACC['admin']['username']);
    if ($activeAdmins > 1 || $adminRow === null) {
        Assert::warn('بیش از یک ادمین فعال در DB وجود دارد — گارد آخرین-ادمین در این محیط قابل مشاهده نیست، رد شد');
        return;
    }
    $res = $http->postJson('/admin.php?api=edit_user', [
        'id' => $adminRow['id'], 'full_name' => 'ادمین تست', 'email' => $adminRow['email'] ?: 'zzadmin@example.com', 'role' => 'user',
    ]);
    Assert::jsonFail($res, 'تنزل تنها ادمین فعال باید رد شود');
});

Assert::test('toggle_user و delete → روی کاربر تستی معمولی کار می‌کند و DB را واقعا تغییر می‌دهد', function () use ($BASE, $ACC) {
    $http = admin_http($BASE, $ACC);
    $uid = Fixtures::createUser();
    $res1 = $http->postJson('/admin.php?api=toggle_user', ['id' => $uid]);
    Assert::jsonOk($res1, 'toggle_user باید موفق باشد');
    $row = DB::run('SELECT is_active FROM users WHERE id=:id', [':id' => $uid])->fetch();
    Assert::eq('0', (string) $row['is_active'], 'is_active باید به 0 تغییر کند');

    $res2 = $http->postJson('/admin.php?api=delete_user', ['id' => $uid]);
    Assert::jsonOk($res2, 'delete_user باید موفق باشد');
    $row2 = DB::run('SELECT id FROM users WHERE id=:id', [':id' => $uid])->fetch();
    Assert::true($row2 === false, 'کاربر باید از DB حذف شده باشد');
});

Assert::test('list_blocks و unblock_ip → چرخه کامل با IP سینتتیک', function () use ($BASE, $ACC) {
    $syntheticIp = '203.0.113.' . random_int(1, 254);
    DB::run(
        'INSERT INTO login_rate_limit (ip, scope, attempts, last_attempt, blocked_until) VALUES (:ip,"user",10,:now,:blocked)',
        [':ip' => $syntheticIp, ':now' => time(), ':blocked' => time() + 900]
    );

    $http = admin_http($BASE, $ACC);
    $res1 = $http->get('/admin.php?api=list_blocks');
    Assert::jsonOk($res1, 'list_blocks باید ok:true بدهد');
    $ips = array_map(fn($b) => $b['ip'] ?? null, $res1['json']['blocks'] ?? []);
    Assert::true(in_array($syntheticIp, $ips, true), 'IP سینتتیک باید در لیست بلاک‌ها دیده شود');

    $res2 = $http->postJson('/admin.php?api=unblock_ip', ['ip' => $syntheticIp, 'scope' => 'user']);
    Assert::jsonOk($res2, 'unblock_ip باید موفق باشد');
    $row = DB::run('SELECT ip FROM login_rate_limit WHERE ip=:ip', [':ip' => $syntheticIp])->fetch();
    Assert::true($row === false, 'ردیف بلاک باید بعد از unblock حذف شده باشد');
});

Assert::test('unblock_ip با IP نامعتبر → رد می‌شود', function () use ($BASE, $ACC) {
    $http = admin_http($BASE, $ACC);
    $res = $http->postJson('/admin.php?api=unblock_ip', ['ip' => 'not-an-ip', 'scope' => 'user']);
    Assert::jsonFail($res, 'IP نامعتبر باید رد شود');
});

Fixtures::deleteUsersByPrefix(false);
Fixtures::deleteSyntheticRateLimits();
