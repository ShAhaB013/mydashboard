<?php
// ═══════════════════════════════════════════════════════════
// DecoController — هندل کردن API انیمیشن‌های کارت
// ═══════════════════════════════════════════════════════════

class DecoController
{
    private DecoModel $model;
    private ToolModel $toolModel;
    private Request   $request;

    public function __construct(DecoModel $model, ToolModel $toolModel, Request $request)
    {
        $this->model     = $model;
        $this->toolModel = $toolModel;
        $this->request   = $request;
    }

    /** ذخیره (افزودن یا ویرایش) انیمیشن */
    public function save(): void
    {
        $key = $this->request->input('key');
        $svg = $this->request->input('svg');

        if (!Validator::isValidKey($key)) {
            Response::error('نام انیمیشن نامعتبر است');
            return;
        }

        if (empty($svg)) {
            Response::error('SVG الزامی است');
            return;
        }

        $this->model->save($key, $svg)
            ? Response::ok()
            : Response::error('خطا در ذخیره انیمیشن');
    }

    /** حذف انیمیشن */
    public function delete(): void
    {
        $key = $this->request->input('key');

        if (!Validator::isValidKey($key)) {
            Response::error('نام انیمیشن نامعتبر است');
            return;
        }

        if ($this->model->isProtected($key)) {
            Response::error('انیمیشن پیش‌فرض قابل حذف نیست');
            return;
        }

        $affected = $this->model->delete($key, $this->toolModel);
        $hasFallback = !empty($affected);

        Response::ok(['fallback' => $hasFallback]);
    }
}
