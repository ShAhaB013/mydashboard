<?php
declare(strict_types=1);

// ═══════════════════════════════════════════════════════════
// NotFoundException — a requested resource doesn't exist (404).
// ═══════════════════════════════════════════════════════════

class NotFoundException extends AppException
{
    protected int $httpStatus = 404;
    protected bool $userFacing = true;
}
