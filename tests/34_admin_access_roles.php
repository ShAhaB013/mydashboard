<?php
declare(strict_types=1);

// ═══════════════════════════════════════════════════════════
// 34_admin_access_roles.php — access-role admin API (list / save / delete) and the
// user-side rules: every user needs a role, and a role with members can't be deleted.
// ═══════════════════════════════════════════════════════════

if (!isset($cfg)) $cfg = require __DIR__ . '/bootstrap.php';
$BASE = $cfg['test']['base_url'];
$ACC  = $cfg['test']['accounts'];

Assert::group('34_admin_access_roles');

Assert::test('save_access_role → دسته/ابزار/منو دقیقا با درخواست مطابقت دارند (بدون ردیف یتیم، دسته ناموجود فیلتر می‌شود)', function () use ($BASE, $ACC) {
    $http = admin_http($BASE, $ACC);
    // Category names are whitelisted against categories that some tool carries
    $realBadge = Fixtures::uniqCategory('badge');
    $tool1 = Fixtures::createTool(['badge' => $realBadge]);
    $tool2 = Fixtures::createTool(['badge' => $realBadge]);
    $name  = Fixtures::uniq('role');

    $res1 = $http->postJson('/admin.php?api=save_access_role', [
        'id' => 0, 'name' => $name, 'description' => 'x',
        'badges' => [$realBadge, 'nonexistent-badge-xyz'], 'tool_ids' => [$tool1, $tool2, 999999999],
        'hidden_menus' => ['profile', 'not-a-menu'],
    ]);
    Assert::jsonOk($res1, 'ساخت نقش باید موفق باشد');
    $roleId = (int) ($res1['json']['id'] ?? 0);

    Assert::eq(2, (int) DB::run('SELECT COUNT(*) FROM role_tool_access WHERE role_id=:r', [':r' => $roleId])->fetchColumn(), 'فقط ۲ ابزار موجود باید ذخیره شود (شناسه ناموجود فیلتر شود)');
    Assert::eq(1, (int) DB::run('SELECT COUNT(*) FROM role_category_access WHERE role_id=:r', [':r' => $roleId])->fetchColumn(), 'دسته ناموجود باید بی‌صدا فیلتر شود');
    Assert::eq(['profile'], array_column(DB::run('SELECT menu_key FROM role_hidden_menus WHERE role_id=:r', [':r' => $roleId])->fetchAll(), 'menu_key'), 'فقط کلید منوی معتبر باید ذخیره شود');

    // second save with a subset — removed grants must disappear
    $res2 = $http->postJson('/admin.php?api=save_access_role', [
        'id' => $roleId, 'name' => $name, 'description' => '', 'badges' => [], 'tool_ids' => [$tool1], 'hidden_menus' => [],
    ]);
    Assert::jsonOk($res2, 'ویرایش نقش باید موفق باشد');
    Assert::eq(1, (int) DB::run('SELECT COUNT(*) FROM role_tool_access WHERE role_id=:r', [':r' => $roleId])->fetchColumn(), 'بعد از کاهش، فقط ۱ ابزار باید بماند');
    Assert::eq(0, (int) DB::run('SELECT COUNT(*) FROM role_category_access WHERE role_id=:r', [':r' => $roleId])->fetchColumn(), 'دسته حذف‌شده نباید بماند');
    Assert::eq(0, (int) DB::run('SELECT COUNT(*) FROM role_hidden_menus WHERE role_id=:r', [':r' => $roleId])->fetchColumn(), 'منوی مخفی حذف‌شده نباید بماند');

    Fixtures::deleteRolesByPrefix();
    Fixtures::deleteToolsByPrefix();
});

Assert::test('save_access_role با نام خالی یا تکراری → خطا با field=name', function () use ($BASE, $ACC) {
    $http = admin_http($BASE, $ACC);
    $empty = $http->postJson('/admin.php?api=save_access_role', ['id' => 0, 'name' => '  ']);
    Assert::jsonFail($empty, 'نام خالی باید رد شود');
    Assert::eq('name', $empty['json']['field'] ?? null, 'خطای نام خالی باید field=name داشته باشد');

    $roleId = Fixtures::createRole();
    $name   = (string) DB::run('SELECT name FROM access_roles WHERE id=:id', [':id' => $roleId])->fetchColumn();
    $dup = $http->postJson('/admin.php?api=save_access_role', ['id' => 0, 'name' => $name]);
    Assert::jsonFail($dup, 'نام تکراری باید رد شود');
    Assert::eq('name', $dup['json']['field'] ?? null, 'خطای نام تکراری باید field=name داشته باشد');

    Fixtures::deleteRolesByPrefix();
});

Assert::test('list_access_roles → نقش‌ها همراه با اعضا و دسترسی‌ها برمی‌گردند', function () use ($BASE, $ACC) {
    $roleId = Fixtures::createRole([], [], ['notifications']);
    $uid    = Fixtures::createUser();
    Fixtures::assignRole($uid, $roleId);

    $res = admin_http($BASE, $ACC)->get('/admin.php?api=list_access_roles');
    Assert::jsonOk($res, 'list_access_roles باید ok:true بدهد');
    $role = null;
    foreach ($res['json']['roles'] ?? [] as $r) if ((int) $r['id'] === $roleId) $role = $r;
    Assert::true($role !== null, 'نقش ساخته‌شده باید در لیست باشد');
    Assert::eq([$uid], array_map(fn($m) => (int) $m['id'], $role['members'] ?? []), 'اعضای نقش باید برگردند');
    Assert::eq(['notifications'], $role['hidden_menus'] ?? null, 'منوهای مخفی نقش باید برگردند');
    Assert::true(is_array($res['json']['badges'] ?? null), 'لیست دسته‌های قابل انتخاب باید برگردد');

    Fixtures::deleteUsersByPrefix(false);
    Fixtures::deleteRolesByPrefix();
});

Assert::test('delete_access_role → نقش دارای عضو حذف نمی‌شود؛ نقش بدون عضو حذف می‌شود', function () use ($BASE, $ACC) {
    $http   = admin_http($BASE, $ACC);
    $roleId = Fixtures::createRole();
    $uid    = Fixtures::createUser();
    Fixtures::assignRole($uid, $roleId);

    $blocked = $http->postJson('/admin.php?api=delete_access_role', ['id' => $roleId]);
    Assert::jsonFail($blocked, 'نقشی که عضو دارد نباید حذف شود');
    Assert::true(DB::run('SELECT 1 FROM access_roles WHERE id=:id', [':id' => $roleId])->fetchColumn() !== false, 'نقش باید هنوز وجود داشته باشد');

    Fixtures::assignRole($uid, null);
    $ok = $http->postJson('/admin.php?api=delete_access_role', ['id' => $roleId]);
    Assert::jsonOk($ok, 'نقش بدون عضو باید حذف شود');

    Fixtures::deleteUsersByPrefix(false);
    Fixtures::deleteRolesByPrefix();
});

Assert::test('add_user / edit_user بدون نقش دسترسی معتبر → خطا با field=access_role_id', function () use ($BASE, $ACC) {
    $http = admin_http($BASE, $ACC);
    $username = Fixtures::uniq('norole');
    $add = $http->postJson('/admin.php?api=add_user', [
        'full_name' => 'کاربر تست', 'username' => $username, 'phone' => '',
        'email' => $username . '@example.com', 'password' => 'NewUser!Pass2026', 'role' => 'user',
    ]);
    Assert::jsonFail($add, 'ساخت کاربر بدون نقش باید رد شود');
    Assert::eq('access_role_id', $add['json']['field'] ?? null, 'خطا باید field=access_role_id داشته باشد');

    $uid  = Fixtures::createUser();
    $row  = DB::run('SELECT username FROM users WHERE id=:id', [':id' => $uid])->fetch();
    $edit = $http->postJson('/admin.php?api=edit_user', [
        'id' => $uid, 'full_name' => 'کاربر تست', 'username' => $row['username'], 'phone' => '',
        'email' => $row['username'] . '@example.com', 'password' => '', 'role' => 'user', 'access_role_id' => 999999999,
    ]);
    Assert::jsonFail($edit, 'ویرایش با نقش ناموجود باید رد شود');
    Assert::eq('access_role_id', $edit['json']['field'] ?? null, 'خطا باید field=access_role_id داشته باشد');

    Fixtures::deleteUsersByPrefix(false);
});

Fixtures::deleteUsersByPrefix(false);
Fixtures::deleteRolesByPrefix();
Fixtures::deleteToolsByPrefix();
