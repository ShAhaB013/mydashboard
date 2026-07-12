<?php
declare(strict_types=1);

// ═══════════════════════════════════════════════════════════
// ResendThrottle — server-side throttling for sending codes/recovery codes
// ───────────────────────────────────────────────────────────
// Why: the client-side cooldown could be bypassed by reloading/reopening the
// page. This layer is session-based (the session cookie survives a reload),
// so reopening the "forgot password" page no longer resets the limit to zero.
// The cooldown is stepped (base*2^n with a cap) and mirrors the client-side
// counting. Also anti-enumeration: behaves identically regardless of whether
// the user exists.
// ═══════════════════════════════════════════════════════════
class ResendThrottle
{
    private const CAP         = 300;   // cooldown cap: 5 minutes
    private const RESET_AFTER = 1800;  // if the gap exceeds 30 minutes, the sequence starts over

    /** Unique session key per "purpose + email" */
    private static function key(string $purpose, string $email): string
    {
        return '_rt_' . $purpose . '_' . md5(strtolower(trim($email)));
    }

    /**
     * Seconds remaining until the next send is allowed (0 = allowed right now).
     * Read-only; doesn't change anything.
     */
    public static function retryAfter(string $purpose, string $email, int $base): int
    {
        $st = $_SESSION[self::key($purpose, $email)] ?? null;
        if (!$st) {
            return 0;
        }
        $gap = time() - (int) ($st['t'] ?? 0);
        if ($gap >= self::RESET_AFTER) {
            return 0; // the old sequence has expired
        }
        $sends = (int) ($st['n'] ?? 0);
        if ($sends <= 0) {
            return 0;
        }
        // Stepped cooldown: 30 -> 60 -> 120 -> ... up to the cap
        $required = (int) min(round($base * (2 ** ($sends - 1))), self::CAP);
        $remain   = $required - $gap;
        return $remain > 0 ? $remain : 0;
    }

    /** Records a completed send (increments counter + timestamp) */
    public static function record(string $purpose, string $email): void
    {
        $k     = self::key($purpose, $email);
        $st    = $_SESSION[$k] ?? null;
        $now   = time();
        $sends = ($st && ($now - (int) ($st['t'] ?? 0)) < self::RESET_AFTER) ? (int) ($st['n'] ?? 0) : 0;
        $_SESSION[$k] = ['t' => $now, 'n' => $sends + 1];
    }
}
