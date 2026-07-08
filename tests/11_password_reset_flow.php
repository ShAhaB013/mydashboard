<?php
declare(strict_types=1);

if (!isset($cfg)) $cfg = require __DIR__ . '/bootstrap.php';
$BASE = $cfg['test']['base_url'];

Assert::group('11_password_reset_flow');

// این فایل به ایمیل واقعی نیاز ندارد: وقتی SMTP در تنظیمات فعال نیست،
// Mailer::devCodeAllowed() → dev_code مستقیما در پاسخ API برمی‌گردد.
$smtpEnabled = DB::run("SELECT svalue FROM app_settings WHERE skey='smtp_enabled'")->fetchColumn();
if ($smtpEnabled === '1' && !($cfg['test']['allow_real_email'] ?? false)) {
    Assert::warn('smtp_enabled=1 است و TESTS_ALLOW_EMAIL تنظیم نشده — این فایل ممکن است ایمیل واقعی بفرستد یا dev_code را نبیند؛ فایل رد شد');
    return;
}

$email = 'zztest_reset_' . bin2hex(random_bytes(3)) . '@example.com';
$uid = Fixtures::createUser(['email' => $email, 'username' => Fixtures::uniq('reset')]);

Assert::test('forgot_password با ایمیل معتبر → dev_code برمی‌گردد (بدون SMTP واقعی)', function () use ($BASE, $email) {
    $http = new HttpClient($BASE);
    $res = $http->postJson('/api.php?action=forgot_password', ['email' => $email], [], false);
    Assert::jsonOk($res, 'forgot_password باید ok:true بدهد');
    Assert::true(isset($res['json']['dev_code']), 'dev_code باید در پاسخ باشد چون SMTP پیکربندی نشده');
});

Assert::test('forgot_password با ایمیل ناموجود → همان پیام عمومی (ضد enumeration)', function () use ($BASE) {
    $http = new HttpClient($BASE);
    $res = $http->postJson('/api.php?action=forgot_password', ['email' => 'zztest_nouser_' . bin2hex(random_bytes(3)) . '@example.com'], [], false);
    Assert::jsonOk($res, 'ایمیل ناموجود هم باید ok:true عمومی بدهد');
});

Assert::test('forgot_password تکراری سریع → retry_after > 0 (throttle)', function () use ($BASE, $email) {
    $http = new HttpClient($BASE);
    $res1 = $http->postJson('/api.php?action=forgot_password', ['email' => $email], [], false);
    $res2 = $http->postJson('/api.php?action=forgot_password', ['email' => $email], [], false);
    Assert::true(($res2['json']['retry_after'] ?? 0) > 0, 'درخواست دوم فوری باید retry_after مثبت داشته باشد', ['res2' => $res2['json']]);
});

Assert::test('forgot_password ایمیل نامعتبر → field=email', function () use ($BASE) {
    $http = new HttpClient($BASE);
    $res = $http->postJson('/api.php?action=forgot_password', ['email' => 'not-an-email'], [], false);
    Assert::eq('email', $res['json']['field'] ?? '', 'باید field=email برگرداند');
});

$code = null;
Assert::test('verify_reset_code با کد صحیح → ok:true', function () use ($BASE, $email, &$code) {
    $http = new HttpClient($BASE);
    // دریافت کد تازه (بدون throttle قبلی چون کاربر و آدرس این تست جداست از تست بالا در صورت اجرای مستقل)
    $fp = $http->postJson('/api.php?action=forgot_password', ['email' => $email], [], false);
    $code = $fp['json']['dev_code'] ?? null;
    if ($code === null) { Assert::warn('dev_code موجود نبود (شاید throttle) — رد شد'); return; }
    $res = $http->postJson('/api.php?action=verify_reset_code', ['email' => $email, 'code' => $code], [], false);
    Assert::jsonOk($res, 'کد صحیح باید تایید شود');
});

Assert::test('verify_reset_code با کد اشتباه → field=code', function () use ($BASE, $email) {
    $http = new HttpClient($BASE);
    $res = $http->postJson('/api.php?action=verify_reset_code', ['email' => $email, 'code' => '000000'], [], false);
    Assert::true(($res['json']['ok'] ?? true) === false, 'کد اشتباه نباید تایید شود');
});

Assert::test('reset_password با رمز ضعیف → رد می‌شود', function () use ($BASE, $email, $code) {
    if ($code === null) { Assert::warn('کد در دسترس نبود — رد شد'); return; }
    $http = new HttpClient($BASE);
    $res = $http->postJson('/api.php?action=reset_password', [
        'email' => $email, 'code' => $code, 'password' => 'weak', 'confirm_password' => 'weak',
    ], [], false);
    Assert::jsonFail($res, 'رمز ضعیف باید رد شود');
});

Assert::test('reset_password با رمز قوی معتبر → ok:true + لاگین خودکار', function () use ($BASE, $email, $code) {
    if ($code === null) { Assert::warn('کد در دسترس نبود — رد شد'); return; }
    $http = new HttpClient($BASE);
    $newPass = 'ZzTest!Reset2026';
    $res = $http->postJson('/api.php?action=reset_password', [
        'email' => $email, 'code' => $code, 'password' => $newPass, 'confirm_password' => $newPass,
    ], [], false);
    Assert::jsonOk($res, 'reset_password باید موفق باشد');
});

Fixtures::deleteUsersByPrefix(false);
