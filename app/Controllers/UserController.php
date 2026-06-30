<?php
declare(strict_types=1);

// ═══════════════════════════════════════════════════════════
// UserController — هندل کردن API کاربران (شامل سطح دسترسی role)
// گاردهای ضدقفل‌شدن: نمی‌توان آخرین ادمین فعال را حذف/غیرفعال/تنزل داد.
// ═══════════════════════════════════════════════════════════

class UserController
{
    private UserModel $model;
    private Request   $request;

    public function __construct(UserModel $model, Request $request)
    {
        $this->model   = $model;
        $this->request = $request;
    }

    /** افزودن کاربر جدید (نام‌کاربری و شماره موبایل توسط ادمین تعیین می‌شوند) */
    public function create(): void
    {
        $fullName = trim((string) $this->request->input('full_name'));
        $username = trim((string) $this->request->input('username'));
        $phone    = trim((string) $this->request->input('phone'));
        $password = $this->request->input('password');
        $role     = UserModel::normalizeRole($this->request->input('role', 'user'));

        if ($fullName === '') {
            Response::error('نام و نام خانوادگی الزامی است');
            return;
        }
        if (($err = Validator::name($fullName, 'نام و نام خانوادگی')) !== '') {
            Response::error($err);
            return;
        }
        [$firstName, $lastName] = UserModel::splitName($fullName);

        if (($err = Validator::username($username)) !== '') {
            Response::error($err);
            return;
        }
        if (($err = Validator::phone($phone)) !== '') {
            Response::error($err);
            return;
        }

        if (!PasswordPolicy::isAcceptable($password)) {
            Response::error(PasswordPolicy::errorMessage());
            return;
        }

        if ($this->model->usernameExists($username)) {
            Response::error('این نام‌کاربری قبلا ثبت شده است');
            return;
        }
        if ($this->model->phoneExists($phone)) {
            Response::error('این شماره موبایل قبلا ثبت شده است');
            return;
        }

        $id = $this->model->create($firstName, $lastName, $username, $phone, $password, $role);
        Response::ok(['id' => $id]);
    }

    /** ویرایش کاربر */
    public function update(): void
    {
        $id       = $this->request->inputInt('id');
        $fullName = trim((string) $this->request->input('full_name'));
        $phone    = trim((string) $this->request->input('phone'));
        $password = $this->request->input('password');
        $role     = UserModel::normalizeRole($this->request->input('role', 'user'));

        if ($id <= 0) {
            Response::error('شناسه کاربر نامعتبر است');
            return;
        }

        if ($fullName === '') {
            Response::error('نام و نام خانوادگی الزامی است');
            return;
        }
        if (($err = Validator::name($fullName, 'نام و نام خانوادگی')) !== '') {
            Response::error($err);
            return;
        }
        [$firstName, $lastName] = UserModel::splitName($fullName);

        if (($err = Validator::phone($phone)) !== '') {
            Response::error($err);
            return;
        }

        $existing = $this->model->findById($id);
        if (!$existing) {
            Response::error('کاربر یافت نشد');
            return;
        }

        if ($this->model->phoneExists($phone, $id)) {
            Response::error('این شماره موبایل قبلا ثبت شده است');
            return;
        }

        // گارد: تنزل آخرین ادمین فعال به کاربر عادی ممنوع است
        if (($existing['role'] ?? 'user') === 'admin'
            && $role !== 'admin'
            && $this->model->isLastActiveAdmin($id)) {
            Response::error('این تنها ادمین فعال است؛ ابتدا یک ادمین دیگر تعریف کنید.');
            return;
        }

        $this->model->update($id, $firstName, $lastName, $phone, $role);

        // تغییر رمز اختیاری است
        if ($password !== '') {
            if (!PasswordPolicy::isAcceptable($password)) {
                Response::error(PasswordPolicy::errorMessage());
                return;
            }
            $this->model->changePassword($id, $password);
        }

        Response::ok();
    }

    /** فعال/غیرفعال کردن کاربر */
    public function toggleActive(): void
    {
        $id = $this->request->inputInt('id');

        if ($id <= 0) {
            Response::error('شناسه کاربر نامعتبر است');
            return;
        }

        if (!$this->model->findById($id)) {
            Response::error('کاربر یافت نشد');
            return;
        }

        // گارد: غیرفعال‌کردن آخرین ادمین فعال ممنوع است
        if ($this->model->isLastActiveAdmin($id)) {
            Response::error('این تنها ادمین فعال است و نمی‌توان غیرفعالش کرد.');
            return;
        }

        $this->model->toggleActive($id);
        Response::ok();
    }

    /** حذف کاربر */
    public function delete(): void
    {
        $id = $this->request->inputInt('id');

        if ($id <= 0) {
            Response::error('شناسه کاربر نامعتبر است');
            return;
        }

        if (!$this->model->findById($id)) {
            Response::error('کاربر یافت نشد');
            return;
        }

        // گارد: حذف آخرین ادمین فعال ممنوع است
        if ($this->model->isLastActiveAdmin($id)) {
            Response::error('این تنها ادمین فعال است و نمی‌توان حذفش کرد.');
            return;
        }

        $this->model->delete($id);
        Response::ok();
    }

    // ── انسداد ورود (Rate limit) ─────────────────────────────

    /** فهرست IPهای محدود/بلاک‌شده در اثر تلاش‌های ناموفق ورود + لاگ */
    public function listBlocks(): void
    {
        $rows = (new RateLimitModel())->all();
        Response::ok(['blocks' => $rows]);
    }

    /** رفع انسداد دستی یک IP در یک scope مشخص */
    public function unblockIp(): void
    {
        $ip    = trim((string) $this->request->input('ip'));
        $scope = trim((string) $this->request->input('scope'));

        if ($ip === '' || !filter_var($ip, FILTER_VALIDATE_IP)) {
            Response::error('آدرس IP نامعتبر است');
            return;
        }
        $scope = ($scope === 'admin') ? 'admin' : 'user';

        (new RateLimitModel())->unblock($ip, $scope);
        Response::ok();
    }
}