<?php
declare(strict_types=1);

// ═══════════════════════════════════════════════════════════
// ErrorHandler — single place that turns PHP errors/warnings/uncaught
// exceptions into a log entry (via Logger) plus a clean response, instead
// of a stack trace leaking to the browser or a warning silently vanishing.
// Replaces the try/catch + error_log() previously duplicated in admin.php
// and api.php.
//
// Verbosity of the response depends on SettingsModel's debug_mode (read live,
// not cached at registration time) — but the full detail is ALWAYS logged,
// regardless of debug mode; debug mode only controls what the browser sees.
// ═══════════════════════════════════════════════════════════

class ErrorHandler
{
    private const GENERIC_MESSAGE = 'خطای داخلی سرور رخ داد';

    public static function register(bool $isApi): void
    {
        set_error_handler(static function (int $severity, string $message, string $file, int $line) {
            if (!(error_reporting() & $severity)) {
                return false; // respects @-suppression
            }
            Logger::warning($message, ['file' => $file, 'line' => $line, 'severity' => $severity]);
            return true; // don't run PHP's internal handler too
        });

        set_exception_handler(static function (Throwable $e) use ($isApi) {
            self::handle($e, $isApi);
        });

        register_shutdown_function(static function () use ($isApi) {
            $err = error_get_last();
            if ($err === null) return;
            if (!in_array($err['type'], [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR], true)) return;
            if (headers_sent()) return;

            Logger::error($err['message'], ['file' => $err['file'], 'line' => $err['line'], 'fatal' => true]);
            http_response_code(500);
            self::respond($isApi, self::GENERIC_MESSAGE, self::debugPayload($err['message'], $err['file'], $err['line'], []));
        });
    }

    private static function handle(Throwable $e, bool $isApi): void
    {
        $file = $e->getFile();
        $line = $e->getLine();

        $context = [
            'file'  => $file,
            'line'  => $line,
            'trace' => $e->getTraceAsString(),
            'class' => get_class($e),
        ];

        // DbException carries the pieces a generic Throwable can't: the real
        // caller (not DB.php, which is where the exception is technically
        // constructed) and PDO's raw SQLSTATE/driver error, instead of only
        // the flattened message string — makes DB failures precisely
        // identifiable and searchable in the log viewer.
        if ($e instanceof DbException) {
            $context['category'] = 'database';
            if ($e->callerFile() !== null) {
                $file = $e->callerFile();
                $line = $e->callerLine() ?? 0;
                $context['file'] = $file;
                $context['line'] = $line;
            }
            if ($e->sqlstate()      !== null) $context['sqlstate']       = $e->sqlstate();
            if ($e->driverCode()    !== null) $context['driver_code']    = $e->driverCode();
            if ($e->driverMessage() !== null) $context['driver_message'] = $e->driverMessage();
            $context['sql'] = $e->sql();
        }

        Logger::error($e->getMessage(), $context);

        $status  = $e instanceof AppException ? $e->httpStatus() : 500;
        $visible = ($e instanceof AppException && $e->isUserFacing()) ? $e->getMessage() : self::GENERIC_MESSAGE;

        if (!headers_sent()) http_response_code($status);

        self::respond(
            $isApi,
            $visible,
            self::debugPayload($e->getMessage(), $file, $line, explode("\n", $e->getTraceAsString())),
            $e instanceof AppException ? $e->field() : null
        );
    }

    /** Extra detail included in the response only when debug_mode is on. */
    private static function debugPayload(string $message, string $file, int $line, array $trace): array
    {
        $debugOn = false;
        try {
            $debugOn = SettingsModel::get('debug_mode', '0') === '1';
        } catch (\Throwable $e) {
            // SettingsModel/DB unavailable — fall back to non-debug output
        }
        if (!$debugOn) return [];

        return ['debug' => ['message' => $message, 'file' => $file, 'line' => $line, 'trace' => $trace]];
    }

    private static function respond(bool $isApi, string $message, array $extra, ?string $field = null): void
    {
        if ($isApi) {
            if (!headers_sent()) header('Content-Type: application/json; charset=utf-8');
            $data = ['ok' => false, 'msg' => $message] + $extra;
            if ($field !== null) $data['field'] = $field;
            echo json_encode($data, JSON_UNESCAPED_UNICODE);
            return;
        }

        $status = http_response_code();
        $code = $status !== false ? $status : 500;

        $debugDetail = null;
        if (!empty($extra)) {
            $d = $extra['debug'];
            $debugDetail = "{$d['message']} in {$d['file']}:{$d['line']}\n" . implode("\n", $d['trace']);
        }

        ErrorPage::render($code, null, $message, $debugDetail);
    }
}
