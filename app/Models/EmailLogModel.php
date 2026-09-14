<?php
declare(strict_types=1);

// ═══════════════════════════════════════════════════════════
// EmailLogModel — one row per outgoing email attempt (email_logs table)
// Written by Mailer::send(); read/deleted by the admin email log page.
// ═══════════════════════════════════════════════════════════

class EmailLogModel
{
    private const STATUSES = ['sent', 'failed'];

    /** Inserts one attempt. Column lengths are clamped so an oversized SMTP reply never breaks the insert. */
    public static function record(array $row): void
    {
        $cut = static fn(?string $s, int $max): ?string => $s === null ? null : mb_substr($s, 0, $max);

        DB::run(
            'INSERT INTO email_logs
               (purpose, recipient, subject, status, error, smtp_response, message_id, smtp_host, duration_ms, user_id, ip, created_at)
             VALUES
               (:purpose, :recipient, :subject, :status, :error, :smtp_response, :message_id, :smtp_host, :duration_ms, :user_id, :ip, NOW())',
            [
                ':purpose'       => $cut((string) ($row['purpose'] ?? 'other'), 32),
                ':recipient'     => $cut((string) ($row['recipient'] ?? ''), 254),
                ':subject'       => $cut((string) ($row['subject'] ?? ''), 255),
                ':status'        => ($row['status'] ?? '') === 'sent' ? 'sent' : 'failed',
                ':error'         => $row['error'] ?? null,
                ':smtp_response' => $cut($row['smtp_response'] ?? null, 512),
                ':message_id'    => $cut($row['message_id'] ?? null, 255),
                ':smtp_host'     => $cut($row['smtp_host'] ?? null, 255),
                ':duration_ms'   => isset($row['duration_ms']) ? max(0, (int) $row['duration_ms']) : null,
                ':user_id'       => !empty($row['user_id']) ? (int) $row['user_id'] : null,
                ':ip'            => $cut($row['ip'] ?? null, 45),
            ]
        );
    }

    /** Builds the "AND status = ... AND (recipient/subject LIKE ...) AND created_at BETWEEN ..." clause. */
    private function buildFilter(?string $status, string $search, string $dateFrom, string $dateTo, array &$params): string
    {
        $sql = '';
        if ($status !== null && in_array($status, self::STATUSES, true)) {
            $sql .= ' AND e.status = :status';
            $params[':status'] = $status;
        }
        $search = trim($search);
        if ($search !== '') {
            $sql .= ' AND (e.recipient LIKE :s1 OR e.subject LIKE :s2)';
            $params[':s1'] = $params[':s2'] = '%' . $search . '%';
        }
        $dateFrom = trim($dateFrom);
        $dateTo   = trim($dateTo);
        if ($dateFrom !== '' && preg_match('/^\d{4}-\d{2}-\d{2}$/', $dateFrom)) {
            $sql .= ' AND e.created_at >= :date_from';
            $params[':date_from'] = $dateFrom . ' 00:00:00';
        }
        if ($dateTo !== '' && preg_match('/^\d{4}-\d{2}-\d{2}$/', $dateTo)) {
            $sql .= ' AND e.created_at <= :date_to';
            $params[':date_to'] = $dateTo . ' 23:59:59';
        }
        return $sql;
    }

    public function countAll(?string $status = null, string $search = '', string $dateFrom = '', string $dateTo = ''): int
    {
        $params = [];
        $filter = $this->buildFilter($status, $search, $dateFrom, $dateTo, $params);
        return (int) DB::run("SELECT COUNT(*) FROM email_logs e WHERE 1=1{$filter}", $params)->fetchColumn();
    }

    /** Row count per status (ignores the status filter itself, but respects search/date range) — feeds the filter chips. */
    public function countByStatus(string $search = '', string $dateFrom = '', string $dateTo = ''): array
    {
        $params = [];
        $filter = $this->buildFilter(null, $search, $dateFrom, $dateTo, $params);
        $rows = DB::run(
            "SELECT e.status, COUNT(*) AS c FROM email_logs e WHERE 1=1{$filter} GROUP BY e.status",
            $params
        )->fetchAll();

        $counts = array_fill_keys(self::STATUSES, 0);
        foreach ($rows as $r) {
            $counts[$r['status']] = (int) $r['c'];
        }
        return $counts;
    }

    /** Admin list, page-number pagination, newest first by default. Joins the triggering user's username. */
    public function allPaginated(
        int $page,
        int $perPage,
        ?string $status = null,
        string $search = '',
        string $dateFrom = '',
        string $dateTo = '',
        string $sortDir = 'desc'
    ): array {
        $page    = max(1, $page);
        $perPage = max(1, min(100, $perPage));
        $offset  = ($page - 1) * $perPage;
        $dir     = strtolower($sortDir) === 'asc' ? 'ASC' : 'DESC';

        $params = [];
        $filter = $this->buildFilter($status, $search, $dateFrom, $dateTo, $params);

        return DB::run(
            "SELECT e.*, u.username
               FROM email_logs e
               LEFT JOIN users u ON u.id = e.user_id
              WHERE 1=1{$filter}
              ORDER BY e.created_at {$dir}, e.id {$dir}
              LIMIT {$perPage} OFFSET {$offset}",
            $params
        )->fetchAll();
    }

    public function findById(int $id): ?array
    {
        $row = DB::run('SELECT * FROM email_logs WHERE id = :id', [':id' => $id])->fetch();
        return $row ?: null;
    }

    public function delete(int $id): void
    {
        DB::run('DELETE FROM email_logs WHERE id = :id', [':id' => $id]);
    }

    /** Deletes all rows, or all rows of one status if given. Returns rows affected. */
    public function clearAll(?string $status = null): int
    {
        if ($status !== null && in_array($status, self::STATUSES, true)) {
            return DB::run('DELETE FROM email_logs WHERE status = :status', [':status' => $status])->rowCount();
        }
        return DB::run('DELETE FROM email_logs')->rowCount();
    }

    public static function toFrontend(array $row): array
    {
        return [
            'id'            => (int) $row['id'],
            'purpose'       => $row['purpose'],
            'recipient'     => $row['recipient'],
            'subject'       => $row['subject'],
            'status'        => $row['status'],
            'error'         => $row['error'],
            'smtp_response' => $row['smtp_response'],
            'message_id'    => $row['message_id'],
            'smtp_host'     => $row['smtp_host'],
            'duration_ms'   => $row['duration_ms'] !== null ? (int) $row['duration_ms'] : null,
            'user_id'       => $row['user_id'] !== null ? (int) $row['user_id'] : null,
            'username'      => $row['username'] ?? null,
            'ip'            => $row['ip'],
            'created_at'    => $row['created_at'],
        ];
    }
}
