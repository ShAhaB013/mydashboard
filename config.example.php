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

    // Encryption key for recoverable sensitive values in the DB (e.g. smtp_pass).
    // Generate with: php -r "echo base64_encode(random_bytes(32)), PHP_EOL;"
    // Leaving this empty disables encryption (no error); but until it's
    // set, smtp_pass is stored as plain text in the DB.
    'crypto' => [
        'key' => '',
    ],

    'files' => [
        'icons' => __DIR__ . '/public_html/data/icons.json',
        'decos' => __DIR__ . '/public_html/data/decos.json',
    ],

    'protected_icons' => ['star'],
    'protected_decos' => ['generic'],

    // TEMPORARY: while no real user accounts exist, set to true to let
    // everyone through without logging in (dashboard + tools only — the
    // admin panel still requires a real admin login). Flip back to false
    // once real accounts are created.
    'auth' => [
        'bypass' => false,
    ],

];
