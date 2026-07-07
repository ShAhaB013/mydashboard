<?php

// ═══════════════════════════════════════════════════════════
// config.example.php — template for config.php
// Copy this file to config.php one level ABOVE the webroot
// on the host and fill in the real values. config.php is
// gitignored and must never be committed.
// ═══════════════════════════════════════════════════════════

return [

    'db' => [
        'host' => 'localhost',
        'name' => 'your_db_name',
        'user' => 'your_db_user',
        'pass' => 'your_db_password',
    ],

    // کلید رمزنگاری مقادیر حساسِ قابل‌بازیابی در DB (مثل smtp_pass).
    // تولید: php -r "echo base64_encode(random_bytes(32)), PHP_EOL;"
    // خالی گذاشتنِ این مقدار رمزنگاری را غیرفعال می‌کند (بدون خطا)؛ اما تا
    // وقتی تنظیم نشود، smtp_pass به‌صورت متن‌ساده در DB ذخیره می‌ماند.
    'crypto' => [
        'key' => '',
    ],

    'files' => [
        'icons' => __DIR__ . '/public_html/data/icons.json',
        'decos' => __DIR__ . '/public_html/data/decos.json',
    ],

    'protected_icons' => ['star'],
    'protected_decos' => ['generic'],

];
