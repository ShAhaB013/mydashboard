<?php
declare(strict_types=1);

// ═══════════════════════════════════════════════════════════
// ValidationException — invalid input from the client (422). Mirrors
// Response::error($msg, $field)'s field-focus convention.
// ═══════════════════════════════════════════════════════════

class ValidationException extends AppException
{
    protected int $httpStatus = 422;
    protected bool $userFacing = true;

    public function __construct(string $message, ?string $field = null)
    {
        parent::__construct($message);
        $this->field = $field;
    }
}
