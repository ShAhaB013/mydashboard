<?php
declare(strict_types=1);

if (!isset($cfg)) $cfg = require __DIR__ . '/bootstrap.php';
$BASE = $cfg['test']['base_url'];

Assert::group('60_rate_limit_concurrency');

// این فایل عمدا آخر از همه اجرا می‌شود چون login_rate_limit واقعی روی 127.0.0.1
// (کلاینت تست) را دستکاری می‌کند. finally همیشه ردیف را پاک می‌کند تا دولوپر
// از لاگین واقعی خودش روی همین ماشین قفل نشود.

function concurrentFailedLogins(string $base, int $n): void
{
    $mh = curl_multi_init();
    $handles = [];
    for ($i = 0; $i < $n; $i++) {
        $ch = curl_init($base . '/api.php?action=login');
        curl_setopt_array($ch, [
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => json_encode(['username' => 'zztest_nouser_concurrency', 'password' => 'wrong-' . $i]),
            CURLOPT_HTTPHEADER     => ['Content-Type: application/json'],
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => 15,
        ]);
        curl_multi_add_handle($mh, $ch);
        $handles[] = $ch;
    }
    $running = null;
    do {
        curl_multi_exec($mh, $running);
        curl_multi_select($mh);
    } while ($running > 0);
    foreach ($handles as $ch) {
        curl_multi_remove_handle($mh, $ch);
        curl_close($ch);
    }
    curl_multi_close($mh);
}

Fixtures::deleteRateLimitByIp('127.0.0.1', 'user');

Assert::test('11 لاگین غلط پیاپی → یازدهمین تلاش 429 می‌گیرد', function () use ($BASE) {
    $http = new HttpClient($BASE);
    $lastStatus = null;
    $lastJson = null;
    for ($i = 0; $i < 11; $i++) {
        $res = $http->postJson('/api.php?action=login', ['username' => 'zztest_nouser_sequential', 'password' => 'wrong'], [], false);
        $lastStatus = $res['status'];
        $lastJson = $res['json'];
    }
    Assert::eq(429, $lastStatus, 'تلاش یازدهم باید 429 بگیرد', ['json' => $lastJson]);
});

Fixtures::deleteRateLimitByIp('127.0.0.1', 'user');

Assert::test('concurrency: ۸ لاگین غلط هم‌زمان (زیر آستانه بلاک) → شمارنده attempts دقیقا 8 است (بدون lost update)', function () use ($BASE) {
    // عمدا کمتر از MAX_ATTEMPTS(=10) تا isBanned() کوتاه‌مدار نشود و recordFailure() برای همه‌ی
    // درخواست‌ها واقعا اجرا شود؛ اینجا فقط اتمیک‌بودن شمارنده زیر سوال است.
    concurrentFailedLogins($BASE, 8);
    $row = DB::run("SELECT attempts FROM login_rate_limit WHERE ip='127.0.0.1' AND scope='user'")->fetch();
    Assert::true($row !== false, 'باید یک ردیف login_rate_limit برای 127.0.0.1 ساخته شده باشد');
    if ($row === false) return;
    Assert::eq(8, (int) $row['attempts'], 'attempts باید دقیقا برابر تعداد درخواست‌های هم‌زمان باشد (اتمیک بودن INSERT...ON DUPLICATE KEY)');
});

Fixtures::deleteRateLimitByIp('127.0.0.1', 'user');

Assert::test('concurrency: 20 درخواست هم‌زمان با MAX_ATTEMPTS=10 → attempts روی ۱۰ قفل می‌ماند (isBanned کوتاه‌مدار می‌کند، نه lost update)', function () use ($BASE) {
    concurrentFailedLogins($BASE, 20);
    $row = DB::run("SELECT attempts, blocked_until FROM login_rate_limit WHERE ip='127.0.0.1' AND scope='user'")->fetch();
    Assert::true($row !== false, 'باید یک ردیف login_rate_limit وجود داشته باشد');
    if ($row === false) return;
    Assert::eq(10, (int) $row['attempts'], 'attempts باید دقیقا روی سقف MAX_ATTEMPTS=10 بایستد (بعد از بلاک، isBanned() مانع افزایش بیشتر می‌شود)');
    Assert::true((int) $row['blocked_until'] > time(), 'blocked_until باید در آینده باشد');
});

Assert::test('بعد از تلاش‌های زیاد، حساب واقعی هم مسدود است (سطح IP، نه فقط username)', function () use ($BASE, $cfg) {
    $acc = $cfg['test']['accounts']['user'];
    $http = new HttpClient($BASE);
    $res = $http->postJson('/api.php?action=login', ['username' => $acc['username'], 'password' => 'wrong-on-purpose'], [], false);
    Assert::statusEq($res, 429, 'محدودیت روی IP است، پس username واقعی هم باید مسدود باشد');
});

// ── پاک‌سازی نهایی: هرگز دولوپر را از لاگین واقعی خودش قفل نکن ──
Fixtures::deleteRateLimitByIp('127.0.0.1', 'user');
