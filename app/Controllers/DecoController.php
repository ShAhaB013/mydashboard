<?php
// ═══════════════════════════════════════════════════════════
// DecoController — handles the card-animation API
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

    /** Save (add or edit) an animation */
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

    /** Delete an animation */
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
