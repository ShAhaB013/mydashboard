<?php
declare(strict_types=1);

// ═══════════════════════════════════════════════════════════
// DbException — wraps a PDOException thrown by DB::run(). Extends
// \PDOException itself (not AppException) so every existing
// catch (\PDOException $e) elsewhere in the codebase — e.g.
// DbSessionHandler, which calls DB::run() directly — keeps working
// unchanged; DbException still is-a PDOException.
//
// The original PDOException stays reachable via getPrevious(), and this
// class additionally extracts the pieces ErrorHandler needs to log a
// precise entry:
//   - sqlstate/driverCode/driverMessage: PDO's raw errorInfo, instead of
//     only the flattened message string.
//   - callerFile/callerLine: the first stack frame outside DB.php — i.e.
//     the model/controller that actually issued the query, since the
//     exception's own getFile()/getLine() would otherwise always point
//     at DB.php.
//
// Never user-facing: ErrorHandler falls back to its generic 500 message
// for any Throwable that isn't an AppException, which is exactly what we
// want here — SQL/driver detail must never reach the browser.
// ═══════════════════════════════════════════════════════════

class DbException extends \PDOException
{
    private ?string $sqlstate;
    private ?int $driverCode;
    private ?string $driverMessage;
    private string $sql;
    private ?string $callerFile;
    private ?int $callerLine;

    private function __construct(
        string $message,
        \PDOException $previous,
        ?string $sqlstate,
        ?int $driverCode,
        ?string $driverMessage,
        string $sql,
        ?string $callerFile,
        ?int $callerLine
    ) {
        parent::__construct($message, 0, $previous);
        $this->errorInfo = [$sqlstate, $driverCode, $driverMessage];
        // PDOException::getCode() natively returns the SQLSTATE *string* (a long-
        // standing PDO quirk — the base Exception constructor only accepts an int,
        // so this is set directly, same as the PDO extension itself does). Code
        // like DbSessionHandler::ensureTable() compares getCode() against '42S02'
        // and must keep working the same for a DbException as for a raw PDOException.
        if ($sqlstate !== null) $this->code = $sqlstate;
        $this->sqlstate      = $sqlstate;
        $this->driverCode    = $driverCode;
        $this->driverMessage = $driverMessage;
        $this->sql           = $sql;
        $this->callerFile    = $callerFile;
        $this->callerLine    = $callerLine;
    }

    public static function fromPdo(\PDOException $e, string $sql): self
    {
        $info          = $e->errorInfo ?? null;
        $sqlstate      = is_array($info) ? ($info[0] ?? null) : (is_string($e->getCode()) ? $e->getCode() : null);
        $driverCode    = is_array($info) && isset($info[1]) ? (int) $info[1] : null;
        $driverMessage = is_array($info) && isset($info[2]) ? (string) $info[2] : null;

        [$callerFile, $callerLine] = self::findCaller($e->getTrace());

        return new self($e->getMessage(), $e, $sqlstate, $driverCode, $driverMessage, $sql, $callerFile, $callerLine);
    }

    /** First stack frame outside DB.php — the code that actually issued the query. */
    private static function findCaller(array $trace): array
    {
        $dbFile = realpath(__DIR__ . '/../DB.php');
        foreach ($trace as $frame) {
            $file = $frame['file'] ?? null;
            if ($file === null) continue;
            if ($dbFile !== false && $file === $dbFile) continue;
            return [$file, $frame['line'] ?? null];
        }
        return [null, null];
    }

    public function sqlstate(): ?string { return $this->sqlstate; }
    public function driverCode(): ?int { return $this->driverCode; }
    public function driverMessage(): ?string { return $this->driverMessage; }
    public function sql(): string { return $this->sql; }
    public function callerFile(): ?string { return $this->callerFile; }
    public function callerLine(): ?int { return $this->callerLine; }
}
