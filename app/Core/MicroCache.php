<?php
declare(strict_types=1);

// ═══════════════════════════════════════════════════════════
// MicroCache — file-based micro-cache for shared responses (guest API branch)
//
// Why: guest responses (bootstrap/tools/notifications) are byte-for-byte
// identical across all visitors, but without a server-side cache, every
// request independently runs the full query + serialize. This class
// collapses N concurrent computations into 1 under high traffic. Use it
// only for responses with no per-user data.
//
// Anti-stampede mechanism:
//   - TTL jitter: real expiry = ttl + random(0..ttl/5) — keys don't expire in sync
//   - single-flight: on expiry, only the request that gets the lock rebuilds;
//     the rest immediately get the existing stale version (stale-while-revalidate)
//   - an orphaned lock (crash mid-rebuild) is reclaimed after LOCK_TIMEOUT seconds
//
// Storage: sys_get_temp_dir()/das-cache/{md5(key)}.cache
//   first line = expiry timestamp, rest = response body (atomic tmp write +
//   rename, same pattern as JsonStore::save)
// ═══════════════════════════════════════════════════════════

class MicroCache
{
    /** Timeout for reclaiming an orphaned lock (seconds) */
    private const LOCK_TIMEOUT = 10;

    /**
     * Returns the cached body; if expired, only one request rebuilds it and
     * the rest get the stale version. On a cold cache (no version exists),
     * builds directly without a lock.
     *
     * @param callable():string $builder builds the fresh body (called only when needed)
     */
    public static function remember(string $key, int $ttl, callable $builder): string
    {
        $file  = self::path($key);
        $now   = time();
        $stale = null;

        $raw = @file_get_contents($file);
        if ($raw !== false) {
            $nl = strpos($raw, "\n");
            if ($nl !== false) {
                $expires = (int) substr($raw, 0, $nl);
                $body    = substr($raw, $nl + 1);
                if ($expires > $now) {
                    return $body;          // fresh — the hottest path
                }
                $stale = $body;            // expired but servable until the rebuild finishes
            }
        }

        // Expired or missing — only one request (the lock winner) rebuilds it
        if (self::acquireLock($file)) {
            try {
                $body = $builder();
                self::store($file, $body, $ttl);
                return $body;
            } finally {
                @unlink($file . '.lock');
            }
        }

        // The lock is held by another request: if we have a stale version, serve it immediately
        if ($stale !== null) {
            return $stale;
        }

        // Cold cache and the lock wasn't freed either — compute directly without
        // writing (rare: only the first few requests after a full cache clear)
        return $builder();
    }

    /** Immediately removes a key (after an admin write, so the change is seen right away) */
    public static function forget(string $key): void
    {
        @unlink(self::path($key));
    }

    // ── internals ────────────────────────────────────────────

    /** Atomic write of "expiry\nbody" with jittered expiry */
    private static function store(string $file, string $body, int $ttl): void
    {
        $jitter  = $ttl >= 5 ? random_int(0, intdiv($ttl, 5)) : 0;
        $expires = time() + $ttl + $jitter;
        $tmp     = $file . '.' . getmypid() . '.tmp';
        if (@file_put_contents($tmp, $expires . "\n" . $body, LOCK_EX) !== false) {
            @rename($tmp, $file);
        }
    }

    /** Acquires the rebuild lock (fopen mode 'x' is atomic); reclaims an orphaned lock */
    private static function acquireLock(string $file): bool
    {
        $lock = $file . '.lock';
        $fh   = @fopen($lock, 'x');
        if ($fh !== false) {
            fclose($fh);
            return true;
        }
        // Lock exists: reclaim it if it's older than LOCK_TIMEOUT (crash mid-rebuild)
        $mtime = @filemtime($lock);
        if ($mtime !== false && (time() - $mtime) > self::LOCK_TIMEOUT) {
            @unlink($lock);
            $fh = @fopen($lock, 'x');
            if ($fh !== false) {
                fclose($fh);
                return true;
            }
        }
        return false;
    }

    private static function path(string $key): string
    {
        $dir = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'das-cache';
        if (!is_dir($dir)) {
            @mkdir($dir, 0700, true);
        }
        return $dir . DIRECTORY_SEPARATOR . md5($key) . '.cache';
    }
}
