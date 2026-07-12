<?php
declare(strict_types=1);

// ═══════════════════════════════════════════════════════════
// IconController — handles the icons API
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

    /** Save (add or edit) an icon */
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

    /** Delete an icon */
    public function delete(): void
    {
        $key = $this->request->input('key');

        if ($this->model->isProtected($key)) {
            Response::error('آیکون star قابل حذف نیست (fallback)');
            return;
        }

        // Check usage in tools — DB field: icon_key
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

    /** Find tools that use this icon */
    private function findIconUsage(string $key): array
    {
        return array_filter(
            $this->toolModel->all(),
            // DB column: icon_key (not iconKey)
            fn($tool) => ($tool['icon_key'] ?? '') === $key
        );
    }
}
