<?php
declare(strict_types=1);

// ═══════════════════════════════════════════════════════════
// UserController — handles the users API (including the role permission level)
// Anti-lockout guards: the last active admin cannot be deleted/deactivated/demoted.
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

    /** List of users with server-side pagination + search/filter (for the user management panel) */
    public function list(): void
    {
        $page    = $this->request->inputInt('page', 1);
        $perPage = $this->request->inputInt('per_page', 15);
        $search  = trim((string) $this->request->input('search'));

        $page    = max(1, $page);
        $perPage = max(1, min(100, $perPage));

        $role = $this->request->input('role');
        if (!in_array($role, UserModel::ROLES, true)) {
            $role = '';
        }
        $status = $this->request->input('status');
        if (!in_array($status, ['active', 'inactive'], true)) {
            $status = '';
        }
        $filters = ['role' => $role, 'status' => $status];

        $total = $this->model->countAll($search, $filters);
        $rows  = $this->model->allPaginated($page, $perPage, $search, $filters);

        $sessionCounts = SessionModel::countsByUser();

        $result = [];
        foreach ($rows as $u) {
            $result[] = [
                'id'           => (int) $u['id'],
                'username'     => $u['username'] ?? '',
                'display_name' => $u['display_name'] ?: trim(($u['first_name'] ?? '') . ' ' . ($u['last_name'] ?? '')),
                'phone'        => $u['phone'] ?? '',
                'email'        => $u['email'] ?? '',
                'role'         => $u['role'] ?? 'user',
                'is_active'    => (bool) $u['is_active'],
                'session_count' => $sessionCounts[(int) $u['id']] ?? 0,
            ];
        }

        $pageCount = (int) max(1, (int) ceil($total / $perPage));

        Response::ok([
            'users'      => $result,
            'pagination' => [
                'page'       => $page,
                'per_page'   => $perPage,
                'total'      => $total,
                'page_count' => $pageCount,
            ],
        ]);
    }

    /** Add a new user (username and mobile number are set by the admin) */
    public function create(): void
    {
        $fullName = trim((string) $this->request->input('full_name'));
        $username = trim((string) $this->request->input('username'));
        $phone    = trim((string) $this->request->input('phone'));
        $email    = trim((string) $this->request->input('email'));
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
        // Mobile number is optional; only validated if entered
        if ($phone !== '' && ($err = Validator::phone($phone)) !== '') {
            Response::error($err);
            return;
        }
        if ($email === '') {
            Response::error('ایمیل الزامی است');
            return;
        }
        if (($err = Validator::email($email)) !== '') {
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
        if ($phone !== '' && $this->model->phoneExists($phone)) {
            Response::error('این شماره موبایل قبلا ثبت شده است');
            return;
        }
        if ($this->model->emailExists($email)) {
            Response::error('این ایمیل قبلا ثبت شده است');
            return;
        }

        $id = $this->model->create($firstName, $lastName, $username, $phone, $email, $password, $role);
        Response::ok(['id' => $id]);
    }

    /** Edit a user */
    public function update(): void
    {
        $id       = $this->request->inputInt('id');
        $fullName = trim((string) $this->request->input('full_name'));
        $phone    = trim((string) $this->request->input('phone'));
        $email    = trim((string) $this->request->input('email'));
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

        // Mobile number is optional; only validated if entered
        if ($phone !== '' && ($err = Validator::phone($phone)) !== '') {
            Response::error($err);
            return;
        }
        if ($email === '') {
            Response::error('ایمیل الزامی است');
            return;
        }
        if (($err = Validator::email($email)) !== '') {
            Response::error($err);
            return;
        }

        $existing = $this->model->findById($id);
        if (!$existing) {
            Response::error('کاربر یافت نشد');
            return;
        }

        if ($phone !== '' && $this->model->phoneExists($phone, $id)) {
            Response::error('این شماره موبایل قبلا ثبت شده است');
            return;
        }
        if ($this->model->emailExists($email, $id)) {
            Response::error('این ایمیل قبلا ثبت شده است');
            return;
        }

        // Guard: demoting the last active admin to a regular user is not allowed
        if (($existing['role'] ?? 'user') === 'admin'
            && $role !== 'admin'
            && $this->model->isLastActiveAdmin($id)) {
            Response::error('این تنها ادمین فعال است؛ ابتدا یک ادمین دیگر تعریف کنید.');
            return;
        }

        $this->model->update($id, $firstName, $lastName, $phone, $email, $role);

        // Password change is optional
        if ($password !== '') {
            if (!PasswordPolicy::isAcceptable($password)) {
                Response::error(PasswordPolicy::errorMessage());
                return;
            }
            $this->model->changePassword($id, $password);
        }

        Response::ok();
    }

    /** Activate/deactivate a user */
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

        // Guard: deactivating the last active admin is not allowed
        if ($this->model->isLastActiveAdmin($id)) {
            Response::error('این تنها ادمین فعال است و نمی‌توان غیرفعالش کرد.');
            return;
        }

        $this->model->toggleActive($id);
        Response::ok();
    }

    /** Delete a user */
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

        // Guard: deleting the last active admin is not allowed
        if ($this->model->isLastActiveAdmin($id)) {
            Response::error('این تنها ادمین فعال است و نمی‌توان حذفش کرد.');
            return;
        }

        $this->model->delete($id);
        Response::ok();
    }

    // ── login lockout (rate limit) ─────────────────────────────

    /** List of IPs restricted/blocked due to failed login attempts + log */
    public function listBlocks(): void
    {
        $rows = (new RateLimitModel())->all();
        Response::ok(['blocks' => $rows]);
    }

    /** Manually unblock an IP within a given scope */
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