<?php
declare(strict_types=1);

// ═══════════════════════════════════════════════════════════
// IconController — هندل کردن API آیکون‌ها
// ═══════════════════════════════════════════════════════════

class IconController
{
    private IconModel $model;
    private ToolModel $toolModel;
    private Request   $request;

    public function __construct(IconModel $model, ToolModel $toolModel, Request $request)
    {
        $this->model     = $model;
        $this->toolModel = $toolModel;
        $this->request   = $request;
    }

    /** ذخیره (افزودن یا ویرایش) آیکون */
    public function save(): void
    {
        $key     = $this->request->input('key');
        $svgPath = $this->request->input('path');

        if (!Validator::isValidKey($key)) {
            Response::error('نام آیکون نامعتبر است (فقط حروف انگلیسی و عدد)');
            return;
        }

        if (empty($svgPath)) {
            Response::error('SVG path الزامی است');
            return;
        }

        $this->model->save($key, $svgPath)
            ? Response::ok()
            : Response::error('خطا در ذخیره آیکون');
    }

    /** حذف آیکون */
    public function delete(): void
    {
        $key = $this->request->input('key');

        if ($this->model->isProtected($key)) {
            Response::error('آیکون star قابل حذف نیست (fallback)');
            return;
        }

        // بررسی استفاده در ابزارها — فیلد DB: icon_key
        $usedIn = $this->findIconUsage($key);
        if (!empty($usedIn)) {
            Response::error('این آیکون در یک ابزار استفاده شده');
            return;
        }

        $this->model->delete($key)
            ? Response::ok()
            : Response::error('خطا در حذف آیکون');
    }

    // ── Private Helpers ──────────────────────────────────────

    /** پیدا کردن ابزارهایی که از این آیکون استفاده می‌کنند */
    private function findIconUsage(string $key): array
    {
        return array_filter(
            $this->toolModel->all(),
            // ستون DB: icon_key (نه iconKey)
            fn($tool) => ($tool['icon_key'] ?? '') === $key
        );
    }
}
