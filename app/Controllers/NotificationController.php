<?php
declare(strict_types=1);

// ═══════════════════════════════════════════════════════════
// NotificationController — هندل کردن API اعلان‌ها
// ═══════════════════════════════════════════════════════════

class NotificationController
{
    private const MAX_BYTES       = 52_428_800; // 50 MB
    private const MAX_BODY_CHARS  = 20_000;      // سقف کاراکتر متن اعلان (بدون احتساب تگ‌ها)
    private const UPLOAD_DIR_NAME = 'uploads/notifications';
    private const ALLOWED_MIMES   = [
        'image/jpeg', 'image/png', 'image/gif', 'image/webp',
        'image/avif', 'image/svg+xml', 'image/bmp',
        'image/tiff', 'image/x-icon', 'image/heic', 'image/heif',
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

    public function list(): void
    {
        $page    = $this->request->inputInt('page', 1);
        $perPage = $this->request->inputInt('per_page', 10);
        $search  = $this->request->input('search');

        $page    = max(1, $page);
        $perPage = max(1, min(50, $perPage));

        // ── فیلترهای جستجوی پیشرفته (تاریخ + وضعیت) ──
        $status = $this->request->input('status');
        if (!in_array($status, ['active', 'expired'], true)) {
            $status = '';
        }
        $filters = [
            'date_from' => $this->request->input('date_from'),
            'date_to'   => $this->request->input('date_to'),
            'status'    => $status,
        ];

        $total = $this->model->countForAdmin($search, $filters);
        $rows  = $this->model->allForAdminPaginated($page, $perPage, $search, $filters);

        // دریافت همه badgeها در یک کوئری به‌جای N کوئری جداگانه
        $ids       = array_map(static fn($r) => (int) $r['id'], $rows);
        $badgesMap = $this->model->getBadgesForIds($ids);

        $result = [];
        foreach ($rows as $row) {
            $id       = (int) $row['id'];
            $result[] = NotificationModel::toFrontend($row, $badgesMap[$id] ?? []);
        }

        $pageCount = (int) max(1, (int) ceil($total / $perPage));

        Response::ok([
            'notifications' => $result,
            'pagination'    => [
                'page'       => $page,
                'per_page'   => $perPage,
                'total'      => $total,
                'page_count' => $pageCount,
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
        // اگر حجم کل درخواست از post_max_size سرور بیشتر باشد، PHP کل $_POST و $_FILES را
        // خالی می‌کند. این حالت را جدا تشخیص بده تا به‌جای پیام گمراه‌کننده «فایلی انتخاب نشده»،
        // علت واقعی (محدودیت حجم سرور) را نشان دهیم.
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

        $ext      = $this->safeExtension($file['name'], $realMime);
        $filename = $this->generateUuid() . '.' . $ext;
        $dest     = $this->uploadDir . '/' . $filename;

        if (!move_uploaded_file($file['tmp_name'], $dest)) {
            Response::error('خطا در ذخیره‌سازی فایل'); return;
        }

        // ── بهینه‌سازی و تولید thumbnail ────────────────────
        $processed = ImageProcessor::process($dest, $this->uploadDir, $this->uploadUrl);

        if ($processed['full'] !== null) {
            // پردازش موفق — فایل اصلی حذف، نسخه‌های WebP جایگزین می‌شوند
            @unlink($dest);
            Response::ok([
                'image_path'     => $processed['full'],
                'thumbnail_path' => $processed['thumb'],
            ]);
        } else {
            // GD در دسترس نیست یا فرمت پشتیبانی نمی‌شود — فایل اصلی نگه داشته می‌شود
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
            Response::error('عنوان اعلان الزامی است'); return null;
        }
        if (mb_strlen($title) > 200) {
            Response::error('عنوان اعلان نباید بیشتر از ۲۰۰ کاراکتر باشد'); return null;
        }

        // ── پاک‌سازی متن غنی (HTML) ──────────────────────────
        // محدودیت بر اساس طول متن قابل‌مشاهده (بدون تگ‌ها)
        $body      = $this->sanitizeBody((string) $body);
        $plainLen  = mb_strlen(trim(strip_tags($body)));
        if ($plainLen > self::MAX_BODY_CHARS) {
            Response::error('متن اعلان نباید بیشتر از ' . self::MAX_BODY_CHARS . ' کاراکتر باشد'); return null;
        }

        // ── تبدیل datetime-local به Unix timestamp ──────────
        $expiresAt = 0;
        if ($expiresRaw !== '') {
            $ts = $this->parseDatetimeLocal($expiresRaw);
            if ($ts === false) {
                Response::error('فرمت تاریخ انقضا نامعتبر است'); return null;
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
            'body'             => $body !== '' ? $body : null,
            'image_path'       => $imagePath !== '' ? $imagePath : null,
            'thumbnail_path'   => $thumbPath !== '' ? $thumbPath : null,
            'is_public'        => $isPublic,
            'target_all_users' => $targetAll,
            'expires_at'       => $expiresAt,
            'badges'           => array_values(array_filter(array_map('strval', $badges))),
        ];
    }

    /**
     * پاک‌سازی متن غنی اعلان.
     * فقط تگ‌ها و ویژگی‌های امن (bold/italic/underline/color/align/rtl-ltr/list)
     * نگه داشته می‌شوند تا از XSS جلوگیری شود.
     */
    private function sanitizeBody(string $html): string
    {
        $html = trim($html);
        if ($html === '') return '';

        // اگر DOM در دسترس نبود، fallback ساده: فقط تگ‌های مجاز
        if (!class_exists('DOMDocument')) {
            return trim(strip_tags($html, '<b><strong><i><em><u><br><p><div><span><ul><ol><li><a><font>'));
        }

        $allowedTags = ['b','strong','i','em','u','br','p','div','span','ul','ol','li','a','font'];
        $allowedAttr = ['style','dir','href','target','rel','color','align'];
        $allowedCss  = ['text-align','color','background-color','font-weight','font-style','text-decoration','direction'];

        $dom = new DOMDocument('1.0', 'UTF-8');
        libxml_use_internal_errors(true);
        // پیچیدن در wrapper با اعلان UTF-8 برای حفظ کاراکترهای فارسی
        $dom->loadHTML(
            '<?xml encoding="UTF-8"><div id="__root__">' . $html . '</div>',
            LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD
        );
        libxml_clear_errors();

        $root = $dom->getElementById('__root__');
        if (!$root) return trim(strip_tags($html, '<b><strong><i><em><u><br><p><div><span><ul><ol><li><a><font>'));

        $clean = function (\DOMNode $node) use (&$clean, $allowedTags, $allowedAttr, $allowedCss) {
            // پیمایش روی کپی فرزندان (چون ممکن است حذف/جایگزین شوند)
            foreach (iterator_to_array($node->childNodes) as $child) {
                if ($child->nodeType === XML_ELEMENT_NODE) {
                    /** @var \DOMElement $child */
                    $tag = strtolower($child->nodeName);
                    if (!in_array($tag, $allowedTags, true)) {
                        // تگ غیرمجاز: متن داخلی را جایگزین کن
                        $text = $child->ownerDocument->createTextNode($child->textContent);
                        $child->parentNode->replaceChild($text, $child);
                        continue;
                    }
                    // پاک‌سازی ویژگی‌ها
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
                    // کامنت و سایر گره‌ها حذف شوند
                    $node->removeChild($child);
                }
            }
        };
        $clean($root);

        // استخراج innerHTML از root
        $out = '';
        foreach ($root->childNodes as $c) {
            $out .= $dom->saveHTML($c);
        }
        return trim($out);
    }

    /**
     * پارس datetime-local با timezone صریح UTC
     * JS مقدار را به UTC تبدیل می‌کند، PHP هم با UTC می‌خواند
     *
     * @return int|false timestamp یا false در صورت خطا
     */
    private function parseDatetimeLocal(string $raw): int|false
    {
        $raw = trim($raw);
        if ($raw === '') return false;

        $utc = new DateTimeZone('UTC');

        // فرمت‌های پشتیبانی‌شده
        $formats = [
            'Y-m-d\TH:i',      // 2025-05-10T10:30     ← خروجی JS (UTC)
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

    private function safeExtension(string $originalName, string $mime): string
    {
        $ext = strtolower(pathinfo($originalName, PATHINFO_EXTENSION));
        $mimeMap = [
            'image/jpeg'    => 'jpg',  'image/png'     => 'png',
            'image/gif'     => 'gif',  'image/webp'    => 'webp',
            'image/avif'    => 'avif', 'image/svg+xml' => 'svg',
            'image/bmp'     => 'bmp',  'image/tiff'    => 'tiff',
            'image/x-icon'  => 'ico',  'image/heic'    => 'heic',
            'image/heif'    => 'heif',
        ];
        if (preg_match('/^[a-z0-9]{1,8}$/', $ext) && $ext !== '') return $ext;
        return $mimeMap[$mime] ?? 'bin';
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
        $htaccess = $this->uploadDir . '/.htaccess';
        if (!file_exists($htaccess)) {
            file_put_contents($htaccess,
                "Options -Indexes\n"
              . "<FilesMatch \"\\.ph(p|ar|tml)$\">\n    Require all denied\n</FilesMatch>\n"
            );
        }
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

    /** تبدیل مقدار کوتاه‌نوشت php.ini (مثل «8M» یا «512K») به بایت */
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