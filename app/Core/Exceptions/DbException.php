<?php
declare(strict_types=1);

// ═══════════════════════════════════════════════════════════
// DbException — wraps a database-layer failure. Never user-facing (SQL/driver
// detail must never reach the browser); the original PDOException stays
// reachable via getPrevious() so the logger records the real cause.
// ═══════════════════════════════════════════════════════════

class DbException extends AppException
{
    protected int $httpStatus = 500;
    protected bool $userFacing = false;

    public function __construct(string $message, ?Throwable $previous = null)
    {
        parent::__construct($message, 0, $previous);
    }
}
