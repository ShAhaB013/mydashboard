<?php
declare(strict_types=1);

/* ═══════════════════════════════════════════════════════════
   version.php — the single source of truth for the project version
   ───────────────────────────────────────────────────────────
   Like real-world projects: change the version only here.
   After each release, just bump APP_VERSION.
   Semantic Versioning format: MAJOR.MINOR.PATCH
   ═══════════════════════════════════════════════════════════ */

if (!defined('APP_VERSION')) {
    define('APP_VERSION', '1.3.0');
}

/* Build date — optional, for display/debugging only */
if (!defined('APP_BUILD')) {
    define('APP_BUILD', '2026-07');
}

/**
 * Asset versioning for deterministic cache-busting.
 * Combines the app version + file mtime: the cache breaks on every
 * release, and also refreshes if a file is edited individually.
 *
 * @param string $absPath absolute path of the file on disk
 */
function asset_v(string $absPath): string
{
    $m = @filemtime($absPath) ?: 0;
    return APP_VERSION . '.' . $m;
}

/** Return the version as a display string (e.g. for the footer) */
function app_version_label(): string
{
    return 'v' . APP_VERSION;
}