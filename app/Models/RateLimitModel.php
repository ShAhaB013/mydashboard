<?php
declare(strict_types=1);

// ═══════════════════════════════════════════════════════════
// RateLimitModel — reads/manages login-attempt limit records
//   login_rate_limit table (composite key ip + scope)
//   scope: 'user' = user login (api.php), 'admin' = admin panel
// ═══════════════════════════════════════════════════════════

class RateLimitModel
{
    /**
     * All records along with block status and remaining time.
     * Sorting: active blocks first, then most recent attempt.
     */
    public function all(): array
    {
        $rows = DB::run(
            'SELECT ip, scope, attempts, last_attempt, blocked_until
             FROM login_rate_limit
             ORDER BY (blocked_until > UNIX_TIMESTAMP()) DESC, last_attempt DESC'
        )->fetchAll();

        $now = time();
        foreach ($rows as &$r) {
            $r['attempts']      = (int) $r['attempts'];
            $r['last_attempt']  = (int) $r['last_attempt'];
            $r['blocked_until'] = (int) $r['blocked_until'];
            $r['is_blocked']    = $r['blocked_until'] > $now;
            $r['remaining']     = $r['is_blocked'] ? ($r['blocked_until'] - $now) : 0;
        }
        unset($r);

        return $rows;
    }

    /** Manually unblocks an IP in a scope (fully deletes the record -> counter resets to zero) */
    public function unblock(string $ip, string $scope): bool
    {
        $stmt = DB::run(
            'DELETE FROM login_rate_limit WHERE ip = :ip AND scope = :scope',
            [':ip' => $ip, ':scope' => $scope]
        );
        return $stmt->rowCount() > 0;
    }

    /** Clears all expired records (no active block) */
    public function clearInactive(): int
    {
        $stmt = DB::run(
            'DELETE FROM login_rate_limit WHERE blocked_until <= UNIX_TIMESTAMP()'
        );
        return $stmt->rowCount();
    }
}
