<?php

// ═══════════════════════════════════════════════════════════
// dash_config.example.php — template for dash_config.php
// Copy this file to dash_config.php (one level ABOVE the webroot
// on the host) and fill in the real values. dash_config.php is
// gitignored and must never be committed.
// ═══════════════════════════════════════════════════════════

return [

    'db' => [
        'host' => 'localhost',
        'name' => 'your_db_name',
        'user' => 'your_db_user',
        'pass' => 'your_db_password',
    ],

    'files' => [
        'icons' => __DIR__ . '/public_html/data/icons.json',
        'decos' => __DIR__ . '/public_html/data/decos.json',
    ],

    'protected_icons' => ['star'],
    'protected_decos' => ['generic'],

];
