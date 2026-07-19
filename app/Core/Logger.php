<?php
declare(strict_types=1);

// ═══════════════════════════════════════════════════════════
// Logger — persists application errors/warnings to the error_logs table
// (replaces error_log(), which wrote somewhere the app could never read back).
// Defensive by design: a failure while logging must never mask the original
// error or loop back into itself.
//
// The one case error_logs itself can't cover is a failure of the database
// layer — if the DB is down, we obviously can't INSERT into it. That case
// falls back to a small JSON file (FALLBACK_FILE) instead of vanishing into
// PHP's own error_log(), which this project has no way to read on the host
// (no SSH/terminal there). Any later successful log write — or simply
// opening the log viewer (LogController::list() calls flushFallback()) —
// drains that file back into error_logs, so entries surface in the normal
// admin UI as soon as the DB is reachable again, tagged as recovered.
// ═══════════════════════════════════════════════════════════

class Logger
{
    private const FALLBACK_FILE = __DIR__ . '/../../data/db-failures.json';
    private const FALLBACK_MAX  = 500; // caps the file while the DB stays down

    public static function error(string $message, array $context = []): void
    {
        self::log('error', $message, $context);
    }

    public static function warning(string $message, array $context = []): void
    {
        self::log('warning', $message, $context);
    }

    public static function info(string $message, array $context = []): void
    {
        self::log('info', $message, $context);
    }

    public static function debug(string $message, array $context = []): void
    {
        self::log('debug', $message, $context);
    }

    private static function log(string $level, string $message, array $context): void
    {
        $context += [
            'request_uri'    => $_SERVER['REQUEST_URI']    ?? null,
            'request_method' => $_SERVER['REQUEST_METHOD'] ?? null,
            'user_id'        => class_exists('UserSession') ? (UserSession::id() ?: null) : null,
            'ip'             => $_SERVER['REMOTE_ADDR']     ?? null,
        ];

        try {
            DB::run(
                'INSERT INTO error_logs (level, message, context, created_at) VALUES (:level, :message, :context, NOW())',
                [
                    ':level'   => $level,
                    ':message' => $message,
                    ':context' => json_encode($context, JSON_UNESCAPED_UNICODE),
                ]
            );
            // The DB just accepted a write — drain any entries stranded from a past outage.
            self::flushFallback();
        } catch (\Throwable $e) {
            self::writeFallback($level, $message, $context, $e);
        }
    }

    /** Appends one entry to the fallback file; error_log() is only the last-resort safety net. */
    private static function writeFallback(string $level, string $message, array $context, \Throwable $dbError): void
    {
        try {
            $store   = new JsonStore(self::FALLBACK_FILE);
            $entries = $store->all();
            $entries[] = [
                'level'           => $level,
                'message'         => $message,
                'context'         => $context,
                'created_at'      => date('Y-m-d H:i:s'),
                'fallback_reason' => $dbError->getMessage(),
            ];
            if (count($entries) > self::FALLBACK_MAX) {
                $entries = array_slice($entries, -self::FALLBACK_MAX);
            }
            if (!$store->save($entries)) {
                error_log("[Logger fallback] {$level}: {$message}");
            }
        } catch (\Throwable $e) {
            error_log("[Logger fallback] {$level}: {$message}");
        }
    }

    /**
     * Replays fallback-file entries into error_logs now that the DB is reachable.
     * Called opportunistically after every successful log write, and explicitly
     * when the log viewer is opened (LogController::list()) so recovered entries
     * show up even if no new error has been logged since the DB came back.
     */
    public static function flushFallback(): void
    {
        $store   = new JsonStore(self::FALLBACK_FILE);
        $entries = $store->all();
        if (!$entries) return;

        $remaining = $entries;
        try {
            foreach ($entries as $entry) {
                $context = is_array($entry['context'] ?? null) ? $entry['context'] : [];
                $context['recovered_from_fallback'] = true;
                if (isset($entry['fallback_reason'])) {
                    $context['fallback_reason'] = $entry['fallback_reason'];
                }
                DB::run(
                    'INSERT INTO error_logs (level, message, context, created_at) VALUES (:level, :message, :context, :created_at)',
                    [
                        ':level'      => $entry['level']      ?? 'error',
                        ':message'    => $entry['message']    ?? '',
                        ':context'    => json_encode($context, JSON_UNESCAPED_UNICODE),
                        ':created_at' => $entry['created_at'] ?? date('Y-m-d H:i:s'),
                    ]
                );
                array_shift($remaining);
            }
        } catch (\Throwable $e) {
            // DB dropped again mid-flush — leave whatever's left for the next attempt.
        }

        $store->save($remaining);
    }
}
