<?php
declare(strict_types=1);

// ═══════════════════════════════════════════════════════════
// 67_access_role_effects.php — end-to-end effects of access roles on what a user sees:
// editing a role changes every member's cards/menus, and moving a user to another role
// (edit_user) swaps their cards, menus and notification recipients immediately.
// ═══════════════════════════════════════════════════════════

if (!isset($cfg)) $cfg = require __DIR__ . '/bootstrap.php';
$BASE = $cfg['test']['base_url'];
$ACC  = $cfg['test']['accounts'];

Assert::group('67_access_role_effects');

/** Logs in as a fixture user and returns [tool ids, me payload] */
function roleView(string $base, int $uid): array
{
    $row  = DB::run('SELECT username FROM users WHERE id=:id', [':id' => $uid])->fetch();
    $http = new HttpClient($base);
    $http->loginAs($row['username'], 'ZzTest!Fixture2026');
    $tools = $http->get('/api.php?action=tools');
    $me    = $http->get('/api.php?action=me');
    return [array_map(fn($t) => (int) $t['id'], $tools['json']['tools'] ?? []), $me['json'] ?? []];
}

$catA   = Fixtures::uniqCategory('roleA');
$catB   = Fixtures::uniqCategory('roleB');
$toolA  = Fixtures::createTool(['badge' => $catA]);
$toolB  = Fixtures::createTool(['badge' => $catB]);

Assert::test('ویرایش نقش از API → کارت‌ها و منوهای همه اعضا یکجا تغییر می‌کند', function () use ($BASE, $ACC, $catA, $toolA, $toolB) {
    $admin  = admin_http($BASE, $ACC);
    $roleId = Fixtures::createRole([$catA]);
    $name   = (string) DB::run('SELECT name FROM access_roles WHERE id=:id', [':id' => $roleId])->fetchColumn();
    $u1 = Fixtures::createUser();
    $u2 = Fixtures::createUser();
    Fixtures::assignRole($u1, $roleId);
    Fixtures::assignRole($u2, $roleId);

    [$tools1, $me1] = roleView($BASE, $u1);
    Assert::true(in_array($toolA, $tools1, true) && !in_array($toolB, $tools1, true), 'قبل از ویرایش، عضو فقط ابزار دسته A را می‌بیند');
    Assert::true(($me1['can_view_profile'] ?? null) === true, 'قبل از ویرایش، منوی حساب کاربری نمایش داده می‌شود');

    $res = $admin->postJson('/admin.php?api=save_access_role', [
        'id' => $roleId, 'name' => $name, 'badges' => [], 'tool_ids' => [$toolB], 'hidden_menus' => ['profile'],
    ]);
    Assert::jsonOk($res, 'ویرایش نقش باید موفق باشد');

    foreach ([$u1, $u2] as $uid) {
        [$tools, $me] = roleView($BASE, $uid);
        Assert::true(!in_array($toolA, $tools, true) && in_array($toolB, $tools, true), 'بعد از ویرایش، هر عضو باید فقط ابزار جدید نقش را ببیند');
        Assert::true(($me['can_view_profile'] ?? null) === false, 'بعد از مخفی کردن منو در نقش، هر عضو نباید منوی حساب کاربری را ببیند');
        Assert::true(($me['can_view_notifications'] ?? null) === true, 'منوی اعلان‌ها که مخفی نشده باید نمایش داده شود');
    }

    Fixtures::deleteUsersByPrefix(false);
    Fixtures::deleteRolesByPrefix();
});

Assert::test('تغییر نقش کاربر با edit_user → کارت‌ها و recipients اعلان بلافاصله جابه‌جا می‌شوند', function () use ($BASE, $ACC, $catA, $catB, $toolA, $toolB) {
    $admin = admin_http($BASE, $ACC);
    $roleA = Fixtures::createRole([$catA]);
    $roleB = Fixtures::createRole([$catB]);

    $notifRes = $admin->postJson('/admin.php?api=create_notification', [
        'title' => Fixtures::uniq('rolenotif'), 'body' => 'x', 'target_all_users' => 0, 'badges' => [$catA],
    ]);
    Assert::jsonOk($notifRes, 'ایجاد اعلان دسته A باید موفق باشد');
    $notifId = (int) ($notifRes['json']['id'] ?? 0);
    $isRecipient = fn(int $uid): bool => DB::run(
        'SELECT 1 FROM notification_recipients WHERE notification_id=:n AND user_id=:u', [':n' => $notifId, ':u' => $uid]
    )->fetchColumn() !== false;

    $uid = Fixtures::createUser(['email' => Fixtures::uniq('rolemove') . '@example.com']);
    Fixtures::assignRole($uid, $roleA);
    Assert::true($isRecipient($uid), 'در نقش A کاربر باید اعلان دسته A را داشته باشد');

    $row = DB::run('SELECT username, email FROM users WHERE id=:id', [':id' => $uid])->fetch();
    $res = $admin->postJson('/admin.php?api=edit_user', [
        'id' => $uid, 'full_name' => 'کاربر تست', 'username' => $row['username'], 'phone' => '',
        'email' => $row['email'], 'password' => '', 'role' => 'user', 'access_role_id' => $roleB,
    ]);
    Assert::jsonOk($res, 'تغییر نقش کاربر باید موفق باشد');
    Assert::eq($roleB, (int) DB::run('SELECT access_role_id FROM users WHERE id=:id', [':id' => $uid])->fetchColumn(), 'نقش جدید باید ذخیره شود');

    [$tools] = roleView($BASE, $uid);
    Assert::true(!in_array($toolA, $tools, true) && in_array($toolB, $tools, true), 'بعد از تغییر نقش، کارت‌ها باید مطابق نقش B باشند');
    Assert::true(!$isRecipient($uid), 'بعد از تغییر نقش، recipient اعلان دسته A باید بلافاصله حذف شده باشد');

    DB::run('DELETE FROM notifications WHERE id=:id', [':id' => $notifId]);
    Fixtures::deleteUsersByPrefix(false);
    Fixtures::deleteRolesByPrefix();
});

Assert::test('حذف ابزاری که مستقیم در نقش است → recipients اعضا بعد از حذف بازمحاسبه می‌شود (نه قبل از آن)', function () use ($BASE, $ACC) {
    $admin = admin_http($BASE, $ACC);
    $cat   = Fixtures::uniqCategory('roledel');
    $tool  = Fixtures::createTool(['badge' => $cat]);
    Fixtures::createTool(['badge' => $cat]); // keeps the category tool-linked so the notification can target it

    $notifRes = $admin->postJson('/admin.php?api=create_notification', [
        'title' => Fixtures::uniq('tooldelnotif'), 'body' => 'x', 'target_all_users' => 0, 'badges' => [$cat],
    ]);
    $notifId = (int) ($notifRes['json']['id'] ?? 0);

    $uid = Fixtures::createUser();
    Fixtures::assignRole($uid, Fixtures::createRole([], [$tool]));
    $has = fn(): bool => DB::run(
        'SELECT 1 FROM notification_recipients WHERE notification_id=:n AND user_id=:u', [':n' => $notifId, ':u' => $uid]
    )->fetchColumn() !== false;
    Assert::true($has(), 'قبل از حذف ابزار، عضو از طریق ابزار باید اعلان را ببیند');

    $del = $admin->postJson('/admin.php?api=delete', ['id' => $tool]);
    Assert::jsonOk($del, 'حذف ابزار باید موفق باشد');
    Assert::true(!$has(), 'بعد از حذف ابزار، recipient نباید باقی بماند');

    DB::run('DELETE FROM notifications WHERE id=:id', [':id' => $notifId]);
    Fixtures::deleteUsersByPrefix(false);
    Fixtures::deleteRolesByPrefix();
});

Fixtures::deleteUsersByPrefix(false);
Fixtures::deleteRolesByPrefix();
Fixtures::deleteToolsByPrefix();
