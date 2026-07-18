<?php
declare(strict_types=1);

// ═══════════════════════════════════════════════════════════
// AppException — base for exceptions the app throws deliberately.
// Additive: existing controllers keep calling Response::error() directly;
// new/refactored code may throw this instead and let ErrorHandler catch it.
// ═══════════════════════════════════════════════════════════

class AppException extends RuntimeException
{
    protected int $httpStatus = 500;
    protected ?string $field = null;
    // If false, the browser only ever sees the generic Persian message
    // (ErrorHandler::genericMessage()); the real message still always reaches the log.
    protected bool $userFacing = true;

    public function httpStatus(): int
    {
        return $this->httpStatus;
    }

    public function field(): ?string
    {
        return $this->field;
    }

    public function isUserFacing(): bool
    {
        return $this->userFacing;
    }
}
