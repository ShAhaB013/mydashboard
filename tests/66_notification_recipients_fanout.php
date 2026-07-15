<?php
declare(strict_types=1);

// ═══════════════════════════════════════════════════════════
// 66_notification_recipients_fanout.php — the notification_recipients materialized
// table (fan-out-on-write; see NotificationModel::refreshRecipientsForNotification/
// refreshRecipientsForUser) must stay in sync with every mutation that can change
// notification visibility: new users, access grants/revokes, tool re-categorization,
// and category deletion. A drift here is a correctness/security regression (a user
// seeing — or failing to see — notifications they shouldn't/should), so this is
// checked directly against the DB, not just via API responses.
// ═══════════════════════════════════════════════════════════

if (!isset($cfg)) $cfg = require __DIR__ . '/bootstrap.php';
$BASE = $cfg['test']['base_url'];
$ACC  = $cfg['test']['accounts'];

Assert::group('66_notification_recipients_fanout');

function recipientRow(int $notifId, int $userId): bool
{
    return DB::run(
        'SELECT 1 FROM notification_recipients WHERE notification_id=:n AND user_id=:u',
        [':n' => $notifId, ':u' => $userId]
    )->fetchColumn() !== false;
}

Assert::test('کاربر تازه‌ساز، اعلان‌های target_all_users موجود را بلافاصله در recipients دارد', function () use ($BASE, $ACC) {
    $admin = admin_http($BASE, $ACC);
    $broadcastId = Fixtures::createNotification(['title' => Fixtures::uniq('broadcast'), 'target_all_users' => 1]);

    $username = Fixtures::uniq('newuser');
    $res = $admin->postJson('/admin.php?api=add_user', [
        'full_name' => 'کاربر تست', 'username' => $username, 'phone' => '',
        'email' => $username . '@example.com', 'password' => 'NewUser!Pass2026', 'role' => 'user',
    ]);
    Assert::jsonOk($res, 'ایجاد کاربر باید موفق باشد');
    $newUserId = (int) DB::run('SELECT id FROM users WHERE username=:u', [':u' => $username])->fetchColumn();

    Assert::true(recipientRow($broadcastId, $newUserId), 'کاربر تازه‌ساز باید از ابتدا رکورد recipient برای اعلان همگانی موجود داشته باشد');

    DB::run('DELETE FROM notifications WHERE id=:id', [':id' => $broadcastId]);
    DB::run('DELETE FROM users WHERE id=:id', [':id' => $newUserId]);
});

Assert::test('اعطای category_access → دسترسی به اعلان‌های موجود آن دسته بلافاصله اضافه می‌شود (recipients)', function () use ($BASE, $ACC) {
    $categoryName = Fixtures::uniq('cat');
    Fixtures::createTool(['badge' => $categoryName]);
    $admin = admin_http($BASE, $ACC);

    $notifRes = $admin->postJson('/admin.php?api=create_notification', [
        'title' => Fixtures::uniq('catnotif'), 'body' => 'x', 'target_all_users' => 0, 'badges' => [$categoryName],
    ]);
    Assert::jsonOk($notifRes, 'ایجاد اعلان دسته‌بندی‌شده باید موفق باشد');
    $notifId = (int) ($notifRes['json']['id'] ?? 0);

    $uid = Fixtures::createUser();
    Assert::true(!recipientRow($notifId, $uid), 'قبل از دسترسی، کاربر نباید رکورد recipient داشته باشد');

    $setRes = $admin->postJson('/admin.php?api=set_access', ['user_id' => $uid, 'tool_ids' => [], 'badges' => [$categoryName]]);
    Assert::jsonOk($setRes, 'set_access باید موفق باشد');
    Assert::true(recipientRow($notifId, $uid), 'بعد از اعطای category_access، رکورد recipient باید بلافاصله اضافه شده باشد');

    // revoke → must disappear immediately too (access-control correctness, not just addition)
    $revokeRes = $admin->postJson('/admin.php?api=set_access', ['user_id' => $uid, 'tool_ids' => [], 'badges' => []]);
    Assert::jsonOk($revokeRes, 'لغو دسترسی باید موفق باشد');
    Assert::true(!recipientRow($notifId, $uid), 'بعد از لغو category_access، رکورد recipient باید بلافاصله حذف شده باشد');

    DB::run('DELETE FROM notifications WHERE id=:id', [':id' => $notifId]);
    Fixtures::deleteUsersByPrefix(false);
    Fixtures::deleteToolsByPrefix();
});

Assert::test('تغییر دسته‌بندی یک ابزار → recipients کاربران دارای tool_access به آن ابزار بازمحاسبه می‌شود', function () use ($BASE, $ACC) {
    $catA = Fixtures::uniq('catA');
    $catB = Fixtures::uniq('catB');
    $toolId = Fixtures::createTool(['badge' => $catA]);
    // category_id/name resolution is a whitelist of "currently carried by some tool"
    // (CategoryModel::findIdByName) — catB needs a tool before create_notification can
    // target it; this second tool exists only to make catB a valid, tool-linked category.
    $seedToolId = Fixtures::createTool(['badge' => $catB]);

    $admin = admin_http($BASE, $ACC);
    $notifA = $admin->postJson('/admin.php?api=create_notification', [
        'title' => Fixtures::uniq('nA'), 'body' => 'x', 'target_all_users' => 0, 'badges' => [$catA],
    ]);
    Assert::jsonOk($notifA, 'ایجاد اعلان دسته A باید موفق باشد');
    $notifAId = (int) ($notifA['json']['id'] ?? 0);

    $notifB = $admin->postJson('/admin.php?api=create_notification', [
        'title' => Fixtures::uniq('nB'), 'body' => 'x', 'target_all_users' => 0, 'badges' => [$catB],
    ]);
    Assert::jsonOk($notifB, 'ایجاد اعلان دسته B باید موفق باشد');
    $notifBId = (int) ($notifB['json']['id'] ?? 0);

    $uid = Fixtures::createUser();
    $setRes = $admin->postJson('/admin.php?api=set_access', ['user_id' => $uid, 'tool_ids' => [$toolId], 'badges' => []]);
    Assert::jsonOk($setRes, 'set_access (tool_access) باید موفق باشد');

    Assert::true(recipientRow($notifAId, $uid), 'قبل از تغییر دسته، کاربر باید اعلان دسته A را ببیند (از طریق tool_access)');
    Assert::true(!recipientRow($notifBId, $uid), 'قبل از تغییر دسته، کاربر نباید اعلان دسته B را ببیند');

    // re-categorize the tool from catA to catB
    $tool = DB::run('SELECT * FROM tools WHERE id=:id', [':id' => $toolId])->fetch();
    $editRes = $admin->postJson('/admin.php?api=edit', [
        'id' => $toolId, 'title' => $tool['title'], 'description' => $tool['description'],
        'path' => $tool['path'], 'badge' => $catB, 'iconKey' => $tool['icon_key'], 'deco' => $tool['deco'],
        'accentColor' => $tool['accent_color'],
    ]);
    Assert::jsonOk($editRes, 'ویرایش ابزار (تغییر دسته) باید موفق باشد');

    Assert::true(!recipientRow($notifAId, $uid), 'بعد از تغییر دسته ابزار، دسترسی به اعلان دسته قدیمی (A) باید حذف شده باشد');
    Assert::true(recipientRow($notifBId, $uid), 'بعد از تغییر دسته ابزار، دسترسی به اعلان دسته جدید (B) باید اضافه شده باشد');

    DB::run('DELETE FROM notifications WHERE id IN (:a,:b)', [':a' => $notifAId, ':b' => $notifBId]);
    Fixtures::deleteUsersByPrefix(false);
    Fixtures::deleteToolsByPrefix();
});

Assert::test('حذف یک دسته‌بندی (بدون ابزار متصل) → recipients اعلان‌هایی که آن را به‌عنوان badge داشتند بازمحاسبه می‌شود', function () use ($BASE, $ACC) {
    $categoryName = Fixtures::uniq('catdel');
    $toolId = Fixtures::createTool(['badge' => $categoryName]);
    $categoryId = (int) DB::run('SELECT category_id FROM tools WHERE id=:id', [':id' => $toolId])->fetchColumn();

    $admin = admin_http($BASE, $ACC);
    $notifRes = $admin->postJson('/admin.php?api=create_notification', [
        'title' => Fixtures::uniq('catdelnotif'), 'body' => 'x', 'target_all_users' => 0, 'badges' => [$categoryName],
    ]);
    Assert::jsonOk($notifRes, 'ایجاد اعلان باید موفق باشد');
    $notifId = (int) ($notifRes['json']['id'] ?? 0);

    $uid = Fixtures::createUser();
    $admin->postJson('/admin.php?api=set_access', ['user_id' => $uid, 'tool_ids' => [], 'badges' => [$categoryName]]);
    Assert::true(recipientRow($notifId, $uid), 'قبل از حذف دسته، کاربر باید اعلان را ببیند');

    // the tool must be deleted/recategorized first — a category still in use can't be deleted
    Fixtures::deleteToolsByPrefix();
    $delRes = $admin->postJson('/admin.php?api=delete_category', ['id' => $categoryId]);
    Assert::jsonOk($delRes, 'حذف دسته‌بندی (بدون ابزار متصل) باید موفق باشد');

    Assert::true(!recipientRow($notifId, $uid), 'بعد از حذف دسته‌بندی، رکورد recipient باید حذف شده باشد (badge دیگر وجود ندارد)');

    DB::run('DELETE FROM notifications WHERE id=:id', [':id' => $notifId]);
    Fixtures::deleteUsersByPrefix(false);
});

Assert::test('rebuild_notification_recipients.php --check هیچ drift ای گزارش نمی‌دهد', function () {
    $out = shell_exec('C:\\xampp\\php\\php.exe ' . escapeshellarg(dirname(__DIR__) . '/migrations/rebuild_notification_recipients.php') . ' --check 2>&1');
    Assert::true(str_contains((string) $out, 'missing (would insert): 0') && str_contains((string) $out, 'extra (would delete):   0'),
        'جدول recipients نباید هیچ drift ای نسبت به قواعد دسترسی فعلی داشته باشد', ['output' => $out]);
});
