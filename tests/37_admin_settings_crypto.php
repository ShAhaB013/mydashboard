<?php
declare(strict_types=1);

if (!isset($cfg)) $cfg = require __DIR__ . '/bootstrap.php';
$BASE = $cfg['test']['base_url'];
$ACC  = $cfg['test']['accounts'];

Assert::group('37_admin_settings_crypto');

// ── Crypto white-box: تشخیص حالت واقعی نصب فعلی (کلید تنظیم شده یا passthrough) ──
$hasKey = trim((string) ($cfg['app']['crypto']['key'] ?? '')) !== '';

Assert::test('Crypto: نصب فعلی passthrough است یا کلید واقعی دارد (تشخیص خودکار)', function () use ($hasKey) {
    Assert::true(true, $hasKey ? 'کلید رمزنگاری در config.php تنظیم شده' : 'کلید رمزنگاری تنظیم نشده — passthrough فعال است');
});

Assert::test('Crypto: متن بدون پیشوند v1: همیشه دست‌نخورده برمی‌گردد (سازگاری با داده قدیمی)', function () {
    $plain = 'legacy-plaintext-value';
    Assert::eq($plain, Crypto::decrypt($plain), 'مقدار بدون پیشوند v1: باید بدون تغییر برگردد');
});

Assert::test('Crypto: با کلید معتبر → round-trip encrypt/decrypt صحیح است', function () {
    Crypto::init(base64_encode(random_bytes(32)));
    $plain = 'super-secret-smtp-pass-Ω';
    $enc = Crypto::encrypt($plain);
    Assert::true(str_starts_with($enc, 'v1:'), 'خروجی رمزشده باید پیشوند v1: داشته باشد');
    Assert::eq($plain, Crypto::decrypt($enc), 'decrypt باید متن اصلی را برگرداند');
});

Assert::test('Crypto: decrypt بدون کلید (حالت passthrough) → رشته خالی fail-safe', function () {
    Crypto::init(base64_encode(random_bytes(32)));
    $enc = Crypto::encrypt('secret-value');
    Crypto::init(''); // شبیه‌سازی نبود کلید
    Assert::eq('', Crypto::decrypt($enc), 'بدون کلید، decrypt یک مقدار v1: باید رشته خالی (fail-safe) برگرداند');
});

Assert::test('Crypto: دستکاری ۱ بایت از payload رمزشده → GCM tag رد می‌کند (رشته خالی)', function () {
    $key = base64_encode(random_bytes(32));
    Crypto::init($key);
    $enc = Crypto::encrypt('tamper-me');
    $b64 = substr($enc, 3);
    $raw = base64_decode($b64, true);
    $raw[strlen($raw) - 1] = chr(ord($raw[strlen($raw) - 1]) ^ 0xFF);
    $tampered = 'v1:' . base64_encode($raw);
    Assert::eq('', Crypto::decrypt($tampered), 'داده دستکاری‌شده باید توسط GCM tag رد شود');
});

// ── بازگرداندن Crypto به کلید واقعی نصب برای بقیه‌ی تست‌ها ──
Crypto::init((string) ($cfg['app']['crypto']['key'] ?? ''));

// ── save_settings از طریق API (بدون فعال‌سازی SMTP واقعی) ──
$originalSettings = DB::run("SELECT skey, svalue FROM app_settings")->fetchAll(PDO::FETCH_KEY_PAIR);

Assert::test('save_settings با پورت نامعتبر → رد می‌شود', function () use ($BASE, $ACC) {
    $http = admin_http($BASE, $ACC);
    $res = $http->postJson('/admin.php?api=save_settings', ['smtp_port' => '99999', 'smtp_secure' => 'tls', 'resend_cooldown' => '30', 'code_ttl' => '600']);
    Assert::jsonFail($res, 'پورت خارج از بازه باید رد شود');
});

Assert::test('save_settings smtp_enabled=1 بدون host → رد می‌شود', function () use ($BASE, $ACC) {
    $http = admin_http($BASE, $ACC);
    $res = $http->postJson('/admin.php?api=save_settings', ['smtp_enabled' => '1', 'smtp_host' => '', 'smtp_port' => '587', 'smtp_secure' => 'tls', 'resend_cooldown' => '30', 'code_ttl' => '600']);
    Assert::jsonFail($res, 'فعال‌سازی SMTP بدون host باید رد شود');
});

Assert::test('save_settings smtp_pass خالی → رمز قبلی حفظ می‌شود (semantics: خالی=بدون تغییر)', function () use ($BASE, $ACC, $originalSettings) {
    $http = admin_http($BASE, $ACC);
    $before = DB::run("SELECT svalue FROM app_settings WHERE skey='smtp_pass'")->fetchColumn();
    $res = $http->postJson('/admin.php?api=save_settings', [
        'smtp_enabled' => $originalSettings['smtp_enabled'] ?? '0',
        'smtp_host'    => $originalSettings['smtp_host'] ?? '',
        'smtp_port'    => $originalSettings['smtp_port'] ?? '587',
        'smtp_secure'  => $originalSettings['smtp_secure'] ?? 'tls',
        'smtp_user'    => $originalSettings['smtp_user'] ?? '',
        'smtp_pass'    => '', // عمدا خالی
        'smtp_from_email' => $originalSettings['smtp_from_email'] ?? '',
        'smtp_from_name'  => $originalSettings['smtp_from_name'] ?? '',
        'resend_cooldown' => $originalSettings['resend_cooldown'] ?? '30',
        'code_ttl'        => $originalSettings['code_ttl'] ?? '600',
    ]);
    Assert::jsonOk($res, 'save_settings معتبر باید موفق باشد');
    $after = DB::run("SELECT svalue FROM app_settings WHERE skey='smtp_pass'")->fetchColumn();
    Assert::eq($before, $after, 'smtp_pass با ورودی خالی نباید تغییر کند');
});

Assert::test('test_email بدون SMTP پیکربندی‌شده → رد می‌شود (بدون تلاش برای ارسال واقعی)', function () use ($BASE, $ACC, $cfg) {
    $enabled = DB::run("SELECT svalue FROM app_settings WHERE skey='smtp_enabled'")->fetchColumn();
    if ($enabled === '1' && !($cfg['test']['allow_real_email'] ?? false)) {
        Assert::warn('SMTP در حال حاضر فعال است و TESTS_ALLOW_EMAIL تنظیم نشده — این تست رد شد تا ایمیل واقعی نرود');
        return;
    }
    $http = admin_http($BASE, $ACC);
    $res = $http->postJson('/admin.php?api=test_email', ['test_email' => 'zztest-nobody@example.com']);
    Assert::jsonFail($res, 'بدون SMTP پیکربندی‌شده، test_email باید رد شود (نه تلاش برای ارسال واقعی)');
});

// ── بازگرداندن تنظیمات اصلی (احتیاط، حتی اگر تست‌های بالا موفق بودند) ──
foreach ($originalSettings as $k => $v) {
    DB::run('UPDATE app_settings SET svalue=:v WHERE skey=:k', [':v' => $v, ':k' => $k]);
}
