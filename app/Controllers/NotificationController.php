<?php
declare(strict_types=1);

// ═══════════════════════════════════════════════════════════
// NotificationController — handles the notifications API
// ═══════════════════════════════════════════════════════════

class NotificationController
{
    private const MAX_BYTES       = 52_428_800; // 50 MB
    private const MAX_BODY_CHARS  = 20_000;      // cap on notification body character count (tags not counted)
    private const UPLOAD_DIR_NAME = 'uploads/notifications';
    // Security note: image/svg+xml is deliberately excluded. SVG is an executable
    // document (XML + <script>/onload) and isn't processed by GD, so it would be stored
    // raw and served with Content-Type: image/svg+xml; opening its URL directly =
    // stored XSS on the site's own origin. Removing it from the allowlist closes this attack surface.
    private const ALLOWED_MIMES   = [
        'image/jpeg', 'image/png', 'image/gif', 'image/webp',
        'image/avif', 'image/bmp',
        'image/tiff', 'image/x-icon', 'image/heic', 'image/heif',
    ];

    // MIME → extension map: the single source of truth for the stored file's extension.
    // The user's filename never influences the extension (prevents .phtml/.php tricks).
    private const MIME_EXT_MAP    = [
        'image/jpeg'   => 'jpg',  'image/png'  => 'png',
        'image/gif'    => 'gif',  'image/webp' => 'webp',
        'image/avif'   => 'avif', 'image/bmp'  => 'bmp',
        'image/tiff'   => 'tiff', 'image/x-icon' => 'ico',
        'image/heic'   => 'heic', 'image/heif' => 'heif',
    ];

    private NotificationModel $model;
    private Request           $request;
    private string            $uploadDir;
    private string            $uploadUrl;

    public function __construct(NotificationModel $model, Request $request)
    {
        $this->model     = $model;
        $this->request   = $request;
        $this->uploadDir = rtrim($_SERVER['DOCUMENT_ROOT'] ?? dirname(__DIR__, 2), '/')
                         . '/' . self::UPLOAD_DIR_NAME;
        $this->uploadUrl = '/' . self::UPLOAD_DIR_NAME;
    }

    // ── Admin CRUD ──────────────────────────────────────────

    /**
     * Admin list. Two parallel input paths:
     *   - page=N (traditional OFFSET) → for clicking a page number or "go to page"
     *   - cursor=...&dir=next|prev (keyset) → for the adjacent Prev/Next arrows, fast at any depth
     * The response always returns both kinds of metadata so the UI can use whichever it needs.
     */
    public function list(): void
    {
        $perPage = max(1, min(50, $this->request->inputInt('per_page', 10)));
        $search  = $this->request->input('search');

        $status = $this->request->input('status');
        if (!in_array($status, ['active', 'expired'], true)) {
            $status = '';
        }
        $filters = [
            'date_from' => $this->request->input('date_from'),
            'date_to'   => $this->request->input('date_to'),
            'status'    => $status,
        ];

        $rawCursor = $this->request->input('cursor');
        $dir       = $this->request->input('dir') === 'prev' ? 'prev' : 'next';

        $total     = $this->model->countForAdmin($search, $filters);
        $pageCount = (int) max(1, (int) ceil($total / $perPage));

        if ($rawCursor !== '') {
            $cursor = Cursor::decode($rawCursor);
            $rows   = $this->model->allForAdminKeyset($cursor, $dir, $perPage, $search, $filters);
            // allForAdminKeyset returns up to perPage+1 rows (an internal has_more hint that's
            // no longer needed here since has_next/has_prev are computed with a separate peek).
            // The extra row is at the end of the array for dir=next; for dir=prev (which gets
            // reversed for display) it's at the start — so a direction-aware slice is needed.
            $rows = $dir === 'prev' ? array_slice($rows, -$perPage) : array_slice($rows, 0, $perPage);
            $page = null;
        } else {
            $page = max(1, $this->request->inputInt('page', 1));
            $rows = $this->model->allForAdminPaginated($page, $perPage, $search, $filters);
        }

        // Fetch all badges in one query instead of N separate queries
        $ids       = array_map(static fn($r) => (int) $r['id'], $rows);
        $badgesMap = $this->model->getBadgesForIds($ids);

        $result = [];
        foreach ($rows as $row) {
            $id       = (int) $row['id'];
            $result[] = NotificationModel::toFrontend($row, $badgesMap[$id] ?? []);
        }

        // has_next/has_prev are computed with a lightweight "peek" (LIMIT 1) in each direction
        // from the edges of the current page — clearer and lower-risk than inferring it from
        // the main query's result; this endpoint is admin-only/low-frequency, so the cost of
        // two extra small queries is negligible.
        $nextCursor = $prevCursor = null;
        if (!empty($rows)) {
            $first = $rows[0];
            $last  = $rows[count($rows) - 1];

            $prevCursorObj = Cursor::decode(Cursor::encode($first['created_at'], (int) $first['id']));
            $hasPrev = !empty($this->model->allForAdminKeyset($prevCursorObj, 'prev', 1, $search, $filters));
            if ($hasPrev) $prevCursor = Cursor::encode($first['created_at'], (int) $first['id']);

            $nextCursorObj = Cursor::decode(Cursor::encode($last['created_at'], (int) $last['id']));
            $hasNext = !empty($this->model->allForAdminKeyset($nextCursorObj, 'next', 1, $search, $filters));
            if ($hasNext) $nextCursor = Cursor::encode($last['created_at'], (int) $last['id']);
        }

        Response::ok([
            'notifications' => $result,
            'pagination'    => [
                'page'        => $page,
                'per_page'    => $perPage,
                'total'       => $total,
                'page_count'  => $pageCount,
                'has_next'    => $nextCursor !== null,
                'has_prev'    => $prevCursor !== null,
                'next_cursor' => $nextCursor,
                'prev_cursor' => $prevCursor,
            ],
        ]);
    }

    public function create(): void
    {
        $data = $this->extractData();
        if ($data === null) return;
        $id = $this->model->create($data);
        Response::ok(['id' => $id]);
    }

    public function update(): void
    {
        $id = $this->request->inputInt('id');
        if ($id <= 0) { Response::error('شناسه اعلان نامعتبر است'); return; }
        if (!$this->model->findById($id)) { Response::error('اعلان یافت نشد'); return; }

        $data = $this->extractData();
        if ($data === null) return;

        $this->model->update($id, $data);
        Response::ok();
    }

    public function delete(): void
    {
        $id = $this->request->inputInt('id');
        if ($id <= 0) { Response::error('شناسه اعلان نامعتبر است'); return; }

        $row = $this->model->findById($id);
        if (!$row) { Response::error('اعلان یافت نشد'); return; }

        if (!empty($row['image_path'])) {
            ImageProcessor::deleteFiles(
                $this->uploadDir,
                $row['image_path'],
                $row['thumbnail_path'] ?? null
            );
        }

        $this->model->delete($id);
        Response::ok();
    }

    public function deleteImage(): void
    {
        $id = $this->request->inputInt('id');
        if ($id <= 0) { Response::error('شناسه اعلان نامعتبر است'); return; }

        $row = $this->model->findById($id);
        if (!$row) { Response::error('اعلان یافت نشد'); return; }

        if (!empty($row['image_path'])) {
            ImageProcessor::deleteFiles(
                $this->uploadDir,
                $row['image_path'],
                $row['thumbnail_path'] ?? null
            );
        }

        $this->model->clearImage($id);
        Response::ok();
    }

    // ── Image Upload ────────────────────────────────────────

    public function uploadImage(): void
    {
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            Response::error('Method Not Allowed'); return;
        }
        // If the total request size exceeds the server's post_max_size, PHP empties both
        // $_POST and $_FILES entirely. Detect this case separately so we show the real cause
        // (server size limit) instead of the misleading "no file selected" message.
        $contentLen = (int) ($_SERVER['CONTENT_LENGTH'] ?? 0);
        if ($contentLen > 0 && empty($_FILES) && empty($_POST)) {
            $postMax = $this->iniBytes((string) ini_get('post_max_size'));
            $limitMb = $postMax > 0 ? (int) round($postMax / 1048576) : 0;
            Response::error($limitMb > 0
                ? "حجم فایل از حد مجاز سرور ({$limitMb} مگابایت) بیشتر است"
                : 'حجم فایل از حد مجاز سرور بیشتر است');
            return;
        }
        if (empty($_FILES['image']) || $_FILES['image']['error'] === UPLOAD_ERR_NO_FILE) {
            Response::error('فایلی انتخاب نشده است'); return;
        }

        $file  = $_FILES['image'];
        $error = $file['error'] ?? UPLOAD_ERR_NO_FILE;

        if ($error !== UPLOAD_ERR_OK) {
            Response::error($this->uploadErrorMessage($error)); return;
        }
        if ($file['size'] > self::MAX_BYTES) {
            Response::error('حجم فایل بیشتر از ۵۰ مگابایت مجاز است'); return;
        }

        $realMime = $this->detectMime($file['tmp_name']);
        if (!in_array($realMime, self::ALLOWED_MIMES, true)) {
            Response::error('فقط فایل‌های تصویری مجاز هستند'); return;
        }
        if (!$this->ensureUploadDir()) {
            Response::error('خطا در ایجاد پوشه آپلود'); return;
        }

        $ext      = $this->safeExtension($realMime);
        $filename = $this->generateUuid() . '.' . $ext;
        $dest     = $this->uploadDir . '/' . $filename;

        if (!move_uploaded_file($file['tmp_name'], $dest)) {
            Response::error('خطا در ذخیره‌سازی فایل'); return;
        }

        // ── optimization and thumbnail generation ────────────────────
        $processed = ImageProcessor::process($dest, $this->uploadDir, $this->uploadUrl);

        if ($processed['full'] !== null) {
            // Processing succeeded — original file removed, replaced by WebP versions
            @unlink($dest);
            Response::ok([
                'image_path'     => $processed['full'],
                'thumbnail_path' => $processed['thumb'],
            ]);
        } else {
            // GD isn't available or the format isn't supported — the original file is kept
            Response::ok([
                'image_path'     => $this->uploadUrl . '/' . $filename,
                'thumbnail_path' => null,
            ]);
        }
    }

    // ── Private Helpers ─────────────────────────────────────

    private function extractData(): ?array
    {
        $title     = $this->request->input('title');
        $body      = $this->request->input('body');
        $imagePath = $this->request->input('image_path');
        $thumbPath = $this->request->input('thumbnail_path');
        $isPublic  = (int) $this->request->input('is_public');
        $targetAll = (int) $this->request->input('target_all_users');
        $expiresRaw = $this->request->input('expires_at');
        $badges    = $this->request->inputArray('badges');

        if (empty($title)) {
            Response::error('عنوان اعلان الزامی است', 'title'); return null;
        }
        if (mb_strlen($title) > 200) {
            Response::error('عنوان اعلان نباید بیشتر از ۲۰۰ کاراکتر باشد', 'title'); return null;
        }

        // ── sanitize rich text (HTML) ──────────────────────────
        // Limit is based on the visible text length (tags excluded)
        $body      = $this->sanitizeBody((string) $body);
        $plainLen  = mb_strlen(trim(strip_tags($body)));
        if ($plainLen === 0) {
            Response::error('متن اعلان الزامی است', 'body'); return null;
        }
        if ($plainLen > self::MAX_BODY_CHARS) {
            Response::error('متن اعلان نباید بیشتر از ' . self::MAX_BODY_CHARS . ' کاراکتر باشد', 'body'); return null;
        }

        // ── convert datetime-local to Unix timestamp ──────────
        $expiresAt = 0;
        if ($expiresRaw !== '') {
            $ts = $this->parseDatetimeLocal($expiresRaw);
            if ($ts === false) {
                Response::error('فرمت تاریخ انقضا نامعتبر است', 'expires_at'); return null;
            }
            $expiresAt = $ts;
        }

        if ($imagePath !== '' && !$this->isValidImagePath($imagePath)) {
            Response::error('مسیر تصویر نامعتبر است'); return null;
        }
        if ($thumbPath !== '' && !$this->isValidImagePath($thumbPath)) {
            Response::error('مسیر تصویر بند انگشتی نامعتبر است'); return null;
        }

        return [
            'title'            => $title,
            'body'             => $body,
            'image_path'       => $imagePath !== '' ? $imagePath : null,
            'thumbnail_path'   => $thumbPath !== '' ? $thumbPath : null,
            'is_public'        => $isPublic,
            'target_all_users' => $targetAll,
            'expires_at'       => $expiresAt,
            'badges'           => array_values(array_filter(array_map('strval', $badges))),
        ];
    }

    /**
     * Sanitize the notification's rich text.
     * Only safe tags and attributes (bold/italic/underline/color/align/rtl-ltr/list)
     * are kept to prevent XSS.
     */
    private function sanitizeBody(string $html): string
    {
        $html = trim($html);
        if ($html === '') return '';

        // If DOM isn't available, simple fallback: only allowed tags
        if (!class_exists('DOMDocument')) {
            return trim(strip_tags($html, '<b><strong><i><em><u><br><p><div><span><ul><ol><li><a><font>'));
        }

        $allowedTags = ['b','strong','i','em','u','br','p','div','span','ul','ol','li','a','font'];
        $allowedAttr = ['style','dir','href','target','rel','color','align'];
        $allowedCss  = ['text-align','color','background-color','font-weight','font-style','text-decoration','direction'];

        $dom = new DOMDocument('1.0', 'UTF-8');
        libxml_use_internal_errors(true);
        // Wrapped with a UTF-8 declaration to preserve Persian characters
        $dom->loadHTML(
            '<?xml encoding="UTF-8"><div id="__root__">' . $html . '</div>',
            LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD
        );
        libxml_clear_errors();

        $root = $dom->getElementById('__root__');
        if (!$root) return trim(strip_tags($html, '<b><strong><i><em><u><br><p><div><span><ul><ol><li><a><font>'));

        $clean = function (\DOMNode $node) use (&$clean, $allowedTags, $allowedAttr, $allowedCss) {
            // Iterate over a copy of the children (since they may be removed/replaced)
            foreach (iterator_to_array($node->childNodes) as $child) {
                if ($child->nodeType === XML_ELEMENT_NODE) {
                    /** @var \DOMElement $child */
                    $tag = strtolower($child->nodeName);
                    if (!in_array($tag, $allowedTags, true)) {
                        // Disallowed tag: replace it with its inner text
                        $text = $child->ownerDocument->createTextNode($child->textContent);
                        $child->parentNode->replaceChild($text, $child);
                        continue;
                    }
                    // Sanitize attributes
                    foreach (iterator_to_array($child->attributes) as $attr) {
                        $name = strtolower($attr->name);
                        if (!in_array($name, $allowedAttr, true)) {
                            $child->removeAttribute($attr->name);
                            continue;
                        }
                        if ($name === 'style') {
                            $safe = [];
                            foreach (explode(';', $attr->value) as $decl) {
                                $parts = explode(':', $decl, 2);
                                if (count($parts) !== 2) continue;
                                $k = strtolower(trim($parts[0]));
                                $v = trim($parts[1]);
                                if ($k === '' || $v === '') continue;
                                if (preg_match('/url\(|expression|javascript:/i', $v)) continue;
                                if (in_array($k, $allowedCss, true)) $safe[] = $k . ':' . $v;
                            }
                            if ($safe) $child->setAttribute('style', implode(';', $safe));
                            else       $child->removeAttribute('style');
                        }
                        if ($name === 'href') {
                            $v = trim($attr->value);
                            if (!preg_match('#^(https?:|mailto:|/)#i', $v)) {
                                $child->removeAttribute('href');
                            }
                        }
                    }
                    if (strtolower($child->nodeName) === 'a') {
                        $child->setAttribute('target', '_blank');
                        $child->setAttribute('rel', 'noopener noreferrer');
                    }
                    $clean($child);
                } elseif ($child->nodeType !== XML_TEXT_NODE) {
                    // Remove comments and other node types
                    $node->removeChild($child);
                }
            }
        };
        $clean($root);

        // Extract innerHTML from root
        $out = '';
        foreach ($root->childNodes as $c) {
            $out .= $dom->saveHTML($c);
        }
        return trim($out);
    }

    /**
     * Parse datetime-local with an explicit UTC timezone
     * JS converts the value to UTC, and PHP reads it as UTC too
     *
     * @return int|false timestamp, or false on error
     */
    private function parseDatetimeLocal(string $raw): int|false
    {
        $raw = trim($raw);
        if ($raw === '') return false;

        $utc = new DateTimeZone('UTC');

        // Supported formats
        $formats = [
            'Y-m-d\TH:i',      // 2025-05-10T10:30     ← JS output (UTC)
            'Y-m-d\TH:i:s',    // 2025-05-10T10:30:00
            'Y-m-d H:i',       // 2025-05-10 10:30
            'Y-m-d H:i:s',     // 2025-05-10 10:30:00
            'Y-m-d',           // 2025-05-10  (fallback)
        ];

        foreach ($formats as $fmt) {
            $dt = DateTime::createFromFormat($fmt, $raw, $utc);
            if ($dt === false) continue;

            $errors = DateTime::getLastErrors();
            if ($errors && ($errors['error_count'] > 0 || $errors['warning_count'] > 0)) {
                continue;
            }

            return $dt->getTimestamp();
        }

        return false;
    }

    private function isValidImagePath(string $path): bool
    {
        $prefix = '/' . self::UPLOAD_DIR_NAME . '/';
        if (!str_starts_with($path, $prefix)) return false;
        if (strpos($path, '..') !== false)    return false;
        $filename = basename($path);
        return (bool) preg_match('/^[a-zA-Z0-9_\-]+\.[a-zA-Z0-9]+$/', $filename);
    }

    private function detectMime(string $tmpPath): string
    {
        if (function_exists('finfo_open')) {
            $fi   = finfo_open(FILEINFO_MIME_TYPE);
            $mime = finfo_file($fi, $tmpPath);
            finfo_close($fi);
            return (string) $mime;
        }
        return (string) mime_content_type($tmpPath);
    }

    /**
     * Safe extension for the stored file — always derived from the detected
     * (content-based) MIME type, never from the user's filename. This prevents
     * polyglot uploads with an executable extension (.php/.phtml) and decouples
     * security from relying on .htaccess.
     */
    private function safeExtension(string $mime): string
    {
        return self::MIME_EXT_MAP[$mime] ?? 'bin';
    }

    private function generateUuid(): string
    {
        $data    = random_bytes(16);
        $data[6] = chr((ord($data[6]) & 0x0f) | 0x40);
        $data[8] = chr((ord($data[8]) & 0x3f) | 0x80);
        return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($data), 4));
    }

    private function ensureUploadDir(): bool
    {
        if (!is_dir($this->uploadDir)) {
            if (!mkdir($this->uploadDir, 0755, true)) return false;
        }
        // Idempotent write of a safe .htaccess (single source of truth in ImageProcessor;
        // rewritten only if missing or stale, so older versions get upgraded too).
        ImageProcessor::writeUploadHtaccess($this->uploadDir);
        return true;
    }

    private function deleteImageFile(string $imagePath): void
    {
        if (!$this->isValidImagePath($imagePath)) return;
        $fullPath = $this->uploadDir . '/' . basename($imagePath);
        if (is_file($fullPath)) @unlink($fullPath);
    }

    private function uploadErrorMessage(int $code): string
    {
        return match ($code) {
            UPLOAD_ERR_INI_SIZE, UPLOAD_ERR_FORM_SIZE => 'حجم فایل از حد مجاز بیشتر است',
            UPLOAD_ERR_PARTIAL    => 'آپلود فایل ناقص انجام شد',
            UPLOAD_ERR_NO_TMP_DIR => 'پوشه موقت یافت نشد',
            UPLOAD_ERR_CANT_WRITE => 'خطا در نوشتن فایل روی دیسک',
            UPLOAD_ERR_EXTENSION  => 'آپلود توسط یک افزونه PHP متوقف شد',
            default               => 'خطای ناشناخته در آپلود فایل',
        };
    }

    /** Convert a php.ini shorthand value (like "8M" or "512K") to bytes */
    private function iniBytes(string $val): int
    {
        $val = trim($val);
        if ($val === '') return 0;
        $num  = (int) $val;
        $unit = strtolower($val[strlen($val) - 1]);
        return match ($unit) {
            'g'     => $num * 1024 * 1024 * 1024,
            'm'     => $num * 1024 * 1024,
            'k'     => $num * 1024,
            default => $num,
        };
    }
}