<?php
declare(strict_types=1);

// ═══════════════════════════════════════════════════════════
// migrate.php — database migration runner (CLI only)
//
//   php migrations/migrate.php              apply pending migrations in order
//   php migrations/migrate.php --status     show status (applied / pending)
//   php migrations/migrate.php --dry-run    show SQL only, without running it
//   php migrations/migrate.php --baseline   mark all as applied without running
//                                           (for existing DBs whose schema comes
//                                            from the full vdupegut_dasmsh.sql dump)
//
// File naming convention: NNN_description.sql (three digits + underscore) in
// this folder — example: 001_add_index_notifications_created.sql
// Each file is run only once and recorded in the schema_migrations table.
//
// Note: DDL is not transactional in MySQL (implicit commit); each statement
// runs separately, and on error an incomplete migration is not recorded — fix
// the error and re-run (idempotent statements like IF NOT EXISTS are preferred).
// ═══════════════════════════════════════════════════════════

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit('CLI only');
}

$APP_ROOT = dirname(__DIR__);
require $APP_ROOT . '/app/Core/DB.php';

// config is one level above webroot (same convention as bootstrap.php)
$config = require dirname($APP_ROOT) . '/config.php';
DB::connect($config['db']);

$mode = $argv[1] ?? '';

// ── Tracking table ─────────────────────────────────────────
DB::run(
    'CREATE TABLE IF NOT EXISTS schema_migrations (
        filename   VARCHAR(191) NOT NULL PRIMARY KEY,
        applied_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
     ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'
);

// ── File listing and status ───────────────────────────────
$files = glob(__DIR__ . '/[0-9][0-9][0-9]_*.sql') ?: [];
sort($files);

$applied = array_column(
    DB::run('SELECT filename FROM schema_migrations')->fetchAll(),
    'filename'
);

$pending = array_filter($files, fn($f) => !in_array(basename($f), $applied, true));

if ($mode === '--status') {
    echo "اعمال‌شده (" . count($applied) . "):\n";
    foreach ($applied as $f) echo "  ✓ {$f}\n";
    echo "در انتظار (" . count($pending) . "):\n";
    foreach ($pending as $f) echo "  • " . basename($f) . "\n";
    exit(0);
}

if ($mode === '--baseline') {
    foreach ($pending as $f) {
        DB::run('INSERT INTO schema_migrations (filename) VALUES (:f)', [':f' => basename($f)]);
        echo "baseline: " . basename($f) . "\n";
    }
    echo count($pending) . " فایل بدون اجرا ثبت شد.\n";
    exit(0);
}

if ($mode !== '' && $mode !== '--dry-run') {
    fwrite(STDERR, "سوییچ ناشناخته: {$mode}\n");
    exit(1);
}

if (empty($pending)) {
    echo "مهاجرت در انتظاری وجود ندارد.\n";
    exit(0);
}

/**
 * Split SQL file content into separate statements —
 * delimiter is a trailing ";"; comment lines (-- and #) are ignored.
 * (Sufficient for ordinary DDL/DML; custom DELIMITER/procedures aren't supported)
 */
function splitSqlStatements(string $sql): array
{
    // Strip BOM — files saved on Windows (Notepad/PowerShell) often have a BOM,
    // and without stripping it, the first line (usually a comment) reaches the server as SQL
    if (str_starts_with($sql, "\xEF\xBB\xBF")) {
        $sql = substr($sql, 3);
    }
    // Only \r\n, \n, and \r — not \R, which without the /u flag mistakes byte
    // 0x85 (the second byte of letters like "م" in UTF-8) for NEL and splits
    // Persian text in the middle of a character
    $lines = preg_split("/\r\n|\n|\r/", $sql) ?: [];
    $stmts = [];
    $buf   = '';
    foreach ($lines as $line) {
        $trim = trim($line);
        if ($trim === '' || str_starts_with($trim, '--') || str_starts_with($trim, '#')) {
            continue;
        }
        $buf .= $line . "\n";
        if (str_ends_with(rtrim($trim), ';')) {
            $stmts[] = trim($buf);
            $buf     = '';
        }
    }
    if (trim($buf) !== '') $stmts[] = trim($buf);
    return $stmts;
}

foreach ($pending as $path) {
    $name  = basename($path);
    $stmts = splitSqlStatements((string) file_get_contents($path));

    if ($mode === '--dry-run') {
        echo "── {$name} (" . count($stmts) . " statement) ──\n";
        foreach ($stmts as $s) echo $s . "\n\n";
        continue;
    }

    echo "▶ {$name}\n";
    foreach ($stmts as $i => $s) {
        try {
            DB::get()->exec($s);
        } catch (Throwable $e) {
            fwrite(STDERR, "خطا در statement " . ($i + 1) . " از {$name}:\n{$e->getMessage()}\n");
            fwrite(STDERR, "مهاجرت ثبت نشد؛ پس از رفع خطا دوباره اجرا کنید.\n");
            exit(1);
        }
    }
    DB::run('INSERT INTO schema_migrations (filename) VALUES (:f)', [':f' => $name]);
    echo "  ✓ اعمال شد (" . count($stmts) . " statement)\n";
}

echo "تمام شد.\n";
