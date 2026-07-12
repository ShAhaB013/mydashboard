<?php
declare(strict_types=1);

class RateLimiter
{
    private const MAX_ATTEMPTS    = 10;   // max allowed attempts
    private const WINDOW_SECONDS  = 900;  // time window: 15 minutes
    private const BLOCK_SECONDS   = 900;  // block duration: 15 minutes
    private const CLEANUP_CHANCE  = 50;   // cleanup runs on 1 in X requests

    private string $ip;
    private string $scope;

    /**
     * @param string $scope separates counters: 'user' for api.php and 'admin' for admin.php,
     *                      so a lockout in one doesn't affect the other.
     *
     * Note: limiting is deliberately IP-based only (not per-account). An
     * account-based lock would let an attacker deliberately lock out a
     * victim's account; since this install isn't behind a proxy and
     * REMOTE_ADDR is the real IP, an atomic IP-based limiter is sufficient
     * and avoids that risk.
     */
    public function __construct(string $scope = 'user')
    {
        $this->ip    = $this->resolveIp();
        $this->scope = ($scope === 'admin') ? 'admin' : 'user';
    }

    public function isBanned(): bool
    {
        $row = $this->fetchRow();
        if (!$row) return false;

        if ($row['blocked_until'] > time()) return true;

        if ($row['blocked_until'] > 0 && $row['blocked_until'] <= time()) {
            $this->reset();
            return false;
        }

        return false;
    }

    /**
     * Records a failed attempt — atomically, in a single statement.
     *
     * The previous pattern (SELECT then UPDATE) had a race condition:
     * concurrent requests would read the same old value and under-count the
     * attempts, letting an attacker exceed the cap. Here, both the counting
     * and the block decision happen inside the database engine, in a single
     * `INSERT ... ON DUPLICATE KEY UPDATE` (no race window).
     *
     * The order of the SET clauses matters: all three expressions reference
     * the "old" values of attempts/last_attempt (last_attempt is set last).
     */
    public function recordFailure(): void
    {
        $now = time();
        // Unique placeholders: with EMULATE_PREPARES=false, a named
        // placeholder can't be repeated in the query, so each repetition
        // gets its own name with the same value.
        DB::run(
            'INSERT INTO login_rate_limit (ip, scope, attempts, last_attempt, blocked_until)
             VALUES (:ip, :scope, 1, :now0, 0)
             ON DUPLICATE KEY UPDATE
               blocked_until = IF(
                   IF(:now1 - last_attempt > :win1, 1, attempts + 1) >= :max,
                   :now2 + :block, 0),
               attempts      = IF(:now3 - last_attempt > :win2, 1, attempts + 1),
               last_attempt  = :now4',
            [
                ':ip'    => $this->ip,
                ':scope' => $this->scope,
                ':now0'  => $now, ':now1' => $now, ':now2' => $now, ':now3' => $now, ':now4' => $now,
                ':win1'  => self::WINDOW_SECONDS, ':win2' => self::WINDOW_SECONDS,
                ':max'   => self::MAX_ATTEMPTS,
                ':block' => self::BLOCK_SECONDS,
            ]
        );

        // Randomly clean up old records
        if (random_int(1, self::CLEANUP_CHANCE) === 1) {
            $this->cleanup();
        }
    }

    /**
     * Resets after a successful login
     */
    public function reset(): void
    {
        DB::run(
            'DELETE FROM login_rate_limit WHERE ip = :ip AND scope = :scope',
            [':ip' => $this->ip, ':scope' => $this->scope]
        );
    }

    /**
     * How many seconds remain until unblock
     */
    public function secondsUntilUnblock(): int
    {
        $row = $this->fetchRow();
        if (!$row || $row['blocked_until'] <= time()) return 0;
        return $row['blocked_until'] - time();
    }

    // ── Private ──────────────────────────────────────────────

    private function fetchRow(): ?array
    {
        $row = DB::run(
            'SELECT * FROM login_rate_limit WHERE ip = :ip AND scope = :scope',
            [':ip' => $this->ip, ':scope' => $this->scope]
        )->fetch();

        return $row ?: null;
    }

    private function cleanup(): void
    {
        DB::run(
            'DELETE FROM login_rate_limit WHERE last_attempt < :cutoff',
            [':cutoff' => time() - 86400]
        );
    }

    private function resolveIp(): string
    {
        $ip = $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';

        // Only enable this if the server is behind a known proxy —
        // otherwise X-Forwarded-For can be spoofed
        // if (!empty($_SERVER['HTTP_X_FORWARDED_FOR'])) {
        //     $forwarded = explode(',', $_SERVER['HTTP_X_FORWARDED_FOR']);
        //     $ip = trim($forwarded[0]);
        // }

        if (filter_var($ip, FILTER_VALIDATE_IP)) {
            return $ip;
        }

        return '0.0.0.0';
    }
}