'use strict';

// ═══════════════════════════════════════════════════════════
// State
// ═══════════════════════════════════════════════════════════
const State = {
  editId:      0,    // 0 = افزودن، >0 = ویرایش ابزار با این id
  deleteId:    0,
  selIcon:     'star',
  selDeco:     'generic',
  selColor:    '',
  selIconKey:  null,
  selDecoKey:  null,
};

// escape برای درج امن متن کاربر در HTML رشته‌ای
function esc(s) {
  return String(s ?? '').replace(/[&<>"']/g, c => (
    { '&':'&amp;', '<':'&lt;', '>':'&gt;', '"':'&quot;', "'":'&#39;' }[c]
  ));
}

// ═══════════════════════════════════════════════════════════
// API
// ═══════════════════════════════════════════════════════════
const Api = {
  async call(action, body) {
    try {
      const res = await fetch(`?api=${action}`, {
        method:  'POST',
        headers: {
          'Content-Type': 'application/json',
          'X-CSRF-Token': window.CSRF_TOKEN || '',
        },
        body: JSON.stringify(body),
      });
      return await res.json();
    } catch {
      return { ok: false, msg: 'خطا در ارتباط با سرور' };
    }
  },
};

// ═══════════════════════════════════════════════════════════
// Toast
// ═══════════════════════════════════════════════════════════
const Toast = {
  _timer: null,
  show(msg, type = 'success') {
    const el  = document.getElementById('toast');
    const ic  = document.getElementById('toastIcon');
    const txt = document.getElementById('toastMsg');
    txt.textContent = msg;
    el.className    = `toast ${type}`;
    ic.innerHTML    = type === 'success'
      ? '<polyline points="20 6 9 17 4 12"/>'
      : '<circle cx="12" cy="12" r="10"/><line x1="12" y1="8" x2="12" y2="12"/><line x1="12" y1="16" x2="12.01" y2="16"/>';
    clearTimeout(this._timer);
    el.classList.add('show');
    this._timer = setTimeout(() => el.classList.remove('show'), 2800);
  },
};

// ═══════════════════════════════════════════════════════════
// FieldErr — خطای inline برای فرم‌های کلیدی (قاب قرمز + پیام زیر فیلد)
// مارک‌آپ تغییر نمی‌کند؛ پیام داخلِ .field تزریق می‌شود. اگر فیلد در .field
// نبود، به Toast برمی‌گردد. همیشه false برمی‌گرداند تا در شرط‌ها return شود.
// ═══════════════════════════════════════════════════════════
const FieldErr = {
  ICON: '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"><circle cx="12" cy="12" r="9"/><line x1="12" y1="7.5" x2="12" y2="13"/><circle cx="12" cy="16.5" r=".6" fill="currentColor" stroke="none"/></svg>',
  set(inputId, msg) {
    const el = document.getElementById(inputId);
    const field = el && el.closest ? el.closest('.field') : null;
    if (!field) { Toast.show(msg, 'error'); return false; }
    field.classList.add('has-error');
    let m = field.querySelector('.field-error-msg');
    if (!m) { m = document.createElement('div'); m.className = 'field-error-msg'; field.appendChild(m); }
    m.innerHTML = this.ICON + '<span></span>';
    m.querySelector('span').textContent = msg;
    if (!el.__feBound) {
      el.__feBound = true;
      const clr = () => FieldErr.clear(inputId);
      el.addEventListener('input', clr);
      el.addEventListener('change', clr);
    }
    el.focus();
    return false;
  },
  clear(inputId) {
    const el = document.getElementById(inputId);
    const field = el && el.closest ? el.closest('.field') : null;
    if (field) field.classList.remove('has-error');
  },
};

// ═══════════════════════════════════════════════════════════
// Modal
// ═══════════════════════════════════════════════════════════
const Modal = {
  open(id)  { document.getElementById(id).classList.add('open');    document.body.style.overflow = 'hidden'; },
  close(id) {
    document.getElementById(id).classList.remove('open');
    // اگر مودال دیگری هنوز باز است (مثلا تایید روی فرم)، قفل اسکرول را نگه دار
    if (!document.querySelector('.modal-overlay.open')) document.body.style.overflow = '';
  },
};

// ═══════════════════════════════════════════════════════════
// Confirm
// ═══════════════════════════════════════════════════════════
const Confirm = {
  _callback: null,
  _defaultIcon:
    '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round">' +
      '<polyline points="3 6 5 6 21 6"/>' +
      '<path d="M19 6l-1 14a2 2 0 01-2 2H8a2 2 0 01-2-2L5 6"/>' +
      '<path d="M10 11v6M14 11v6M9 6V4a1 1 0 011-1h4a1 1 0 011 1v2"/>' +
    '</svg>',
  show({ title, heading, body, warn = null, type = 'danger', btnLabel = 'حذف', icon = null, onConfirm }) {
    this._callback = onConfirm;
    document.getElementById('confirmTitle').textContent   = title;
    document.getElementById('confirmHeading').textContent = heading;
    document.getElementById('confirmBody').innerHTML      = body;
    const warnEl = document.getElementById('confirmWarn');
    if (warn) { warnEl.innerHTML = `⚠️ ${warn}`; warnEl.classList.add('show'); }
    else       { warnEl.innerHTML = ''; warnEl.classList.remove('show'); }
    const iconEl = document.getElementById('confirmIcon');
    iconEl.className = `confirm-icon ${type}`;
    iconEl.innerHTML = icon || this._defaultIcon;
    const btn = document.getElementById('confirmActionBtn');
    btn.className   = `btn btn-sm ${type === 'warning' ? 'btn-warning' : 'btn-danger'}`;
    btn.textContent = btnLabel;
    btn.disabled    = false;
    Modal.open('confirmModal');
  },
  close() { Modal.close('confirmModal'); this._callback = null; },
  async run() {
    const btn = document.getElementById('confirmActionBtn');
    btn.disabled = true;
    try { if (this._callback) await this._callback(); }
    finally { btn.disabled = false; }
  },
};

// ═══════════════════════════════════════════════════════════
// Preview
// ═══════════════════════════════════════════════════════════
const Preview = {
  _hexToRgb(hex) {
    const r = /^#?([a-f\d]{2})([a-f\d]{2})([a-f\d]{2})$/i.exec(hex);
    return r ? `${parseInt(r[1],16)},${parseInt(r[2],16)},${parseInt(r[3],16)}` : null;
  },
  _lighten(hex, pct) {
    const r = /^#?([a-f\d]{2})([a-f\d]{2})([a-f\d]{2})$/i.exec(hex);
    if (!r) return hex;
    const l = v => Math.min(255, Math.round(parseInt(v,16) + (255 - parseInt(v,16)) * (pct / 100)));
    return `#${[r[1],r[2],r[3]].map(v => l(v).toString(16).padStart(2,'0')).join('')}`;
  },
  update() {
    const title = document.getElementById('f-title').value || 'عنوان ابزار';
    const desc  = document.getElementById('f-desc').value  || 'توضیح کوتاه درباره این ابزار';
    const badge = document.getElementById('f-badge').value || 'ابزار';
    const color = State.selColor || '#3e7de7';
    const rgb   = this._hexToRgb(color);
    document.getElementById('prevTitle').textContent = title;
    document.getElementById('prevDesc').textContent  = desc;
    document.getElementById('prevBadge').textContent = badge;
    if (rgb) {
      const card = document.getElementById('previewCard');
      card.style.setProperty('--card-color',   color);
      card.style.setProperty('--card-color-l', this._lighten(color, 20));
      card.style.setProperty('--card-bg',      `rgba(${rgb},.08)`);
      card.style.setProperty('--card-border',  `rgba(${rgb},.25)`);
    }
    const iconPath = ICONS_DATA[State.selIcon] || ICONS_DATA['star'] || '';
    document.getElementById('prevIcon').innerHTML =
      `<svg viewBox="0 0 24 24" width="20" height="20">${iconPath}</svg>`;
    const decoEl = document.getElementById('prevDeco');
    if (decoEl) {
      decoEl.innerHTML = DECOS_DATA[State.selDeco] || DECOS_DATA['generic'] || '';
      void decoEl.offsetWidth;
    }
  },
};

// ═══════════════════════════════════════════════════════════
// ToolForm — با dirty tracking برای هشدار تغییرات ذخیره‌نشده
// ═══════════════════════════════════════════════════════════
const ToolForm = {
  _dirty: false,

  _markDirty() { this._dirty = true; },
  _clearDirty() { this._dirty = false; },

  // بستن فرم — اگر تغییر ذخیره‌نشده باشد، با مودال سفارشی تایید می‌گیرد
  requestClose() {
    if (!this._dirty) { Modal.close('formModal'); return; }
    Confirm.show({
      title:    'بستن فرم',
      heading:  'تغییرات ذخیره‌نشده دارید',
      body:     'تغییرات را ذخیره نکرده‌اید، آیا از بستن فرم اطمینان دارید؟',
      type:     'warning',
      btnLabel: 'بستن بدون ذخیره',
      icon:     '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M10.29 3.86L1.82 18a2 2 0 0 0 1.71 3h16.94a2 2 0 0 0 1.71-3L13.71 3.86a2 2 0 0 0-3.42 0z"/><line x1="12" y1="9" x2="12" y2="13"/><line x1="12" y1="17" x2="12.01" y2="17"/></svg>',
      onConfirm: () => {
        this._clearDirty();
        Confirm.close();
        Modal.close('formModal');
      },
    });
  },

  reset() {
    ['f-title','f-desc','f-path','f-badge'].forEach(id => document.getElementById(id).value = '');
    State.selIcon  = 'star';
    State.selDeco  = 'generic';
    State.selColor = '';
    document.querySelectorAll('.color-preset').forEach((b, i) => b.classList.toggle('active', i === 0));
    IconPicker.build();
    DecoPicker.build();
    Preview.update();
    this._clearDirty();
  },

  openAdd() {
    State.editId = 0;
    document.getElementById('modalTitle').textContent = 'ابزار جدید';
    this.reset();
    Modal.open('formModal');
    setTimeout(() => document.getElementById('f-title').focus(), 100);
  },

  openEdit(id) {
    // ردیفِ کاملِ ابزار از کشِ صفحهٔ جاریِ ToolsView (دکمهٔ ویرایش فقط روی
    // ردیف‌های همان صفحه است). fallback به آرایهٔ سبک اگر یافت نشد.
    const t = (Array.isArray(ToolsView._pageRows) ? ToolsView._pageRows : [])
                .find(x => Number(x.id) === Number(id))
           || (Array.isArray(tools) ? tools : []).find(x => Number(x.id) === Number(id));
    if (!t) { Toast.show('ابزار یافت نشد', 'error'); return; }
    State.editId = Number(id);
    document.getElementById('modalTitle').textContent = 'ویرایش ابزار';
    document.getElementById('f-title').value = t.title       || '';
    document.getElementById('f-desc').value  = t.description || '';
    document.getElementById('f-path').value  = t.path        || '';
    document.getElementById('f-badge').value = t.badge       || '';
    State.selIcon  = t.iconKey     || 'star';
    State.selDeco  = t.deco        || 'generic';
    State.selColor = t.accentColor || '';
    const presets = document.querySelectorAll('.color-preset');
    let found = false;
    presets.forEach(b => {
      const match = b.dataset.color === State.selColor;
      b.classList.toggle('active', match);
      if (match) found = true;
    });
    if (State.selColor && !found) document.getElementById('customColor').value = State.selColor;
    IconPicker.build();
    DecoPicker.build();
    Preview.update();
    this._clearDirty();
    Modal.open('formModal');
    setTimeout(() => document.getElementById('f-title').focus(), 100);
  },

  async save() {
    const title = document.getElementById('f-title').value.trim();
    const path  = document.getElementById('f-path').value.trim();
    if (!title) return FieldErr.set('f-title', 'عنوان الزامی است');
    if (!path)  return FieldErr.set('f-path', 'مسیر الزامی است');
    const btn = document.getElementById('saveBtn');
    btn.disabled = true;
    const isEdit  = State.editId > 0;
    const payload = {
      title,
      description: document.getElementById('f-desc').value.trim(),
      path,
      badge:       document.getElementById('f-badge').value.trim(),
      iconKey:     State.selIcon,
      deco:        State.selDeco,
      accentColor: State.selColor || '',
    };
    if (isEdit) payload.id = State.editId;
    const res = await Api.call(isEdit ? 'edit' : 'add', payload);
    if (res.ok) {
      this._clearDirty();
      Modal.close('formModal');
      Toast.show(isEdit ? 'ابزار ویرایش شد' : 'ابزار اضافه شد');
      setTimeout(() => location.reload(), 900);
    } else {
      Toast.show(res.msg || 'خطا در ذخیره', 'error');
    }
    btn.disabled = false;
  },

  openDelete(id, name) {
    State.deleteId = Number(id);
    Confirm.show({
      title:    'حذف ابزار',
      heading:  'آیا از حذف این ابزار اطمینان دارید؟',
      body:     `ابزار <span class="item-name">${esc(name)}</span> به‌طور دائم حذف خواهد شد.`,
      type:     'danger',
      btnLabel: 'حذف ابزار',
      onConfirm: async () => {
        const res = await Api.call('delete', { id: State.deleteId });
        if (res.ok) {
          Confirm.close();
          Toast.show('ابزار با موفقیت حذف شد');
          setTimeout(() => location.reload(), 900);
        } else {
          Toast.show(res.msg || 'خطا در حذف', 'error');
        }
      },
    });
  },
};

// ═══════════════════════════════════════════════════════════
// TogglePublic
// ═══════════════════════════════════════════════════════════
const TogglePublic = {
  async toggle(id, btn) {
    btn.disabled = true;
    const res = await Api.call('toggle_public', { id });
    if (!res.ok) {
      Toast.show(res.msg || 'خطا', 'error');
      btn.disabled = false;
      return;
    }
    const row      = btn.closest('.tool-row');
    const isNowPublic = row.dataset.public === '0';
    row.dataset.public = isNowPublic ? '1' : '0';

    // به‌روزرسانی داده‌های در حافظه تا مودال دسترسی بدون رفرش بروز بماند
    const newPublic = isNowPublic ? 1 : 0;
    const raw = Array.isArray(TOOLS_RAW) ? TOOLS_RAW.find(t => Number(t.id) === Number(id)) : null;
    if (raw) raw.is_public = newPublic;
    if (Array.isArray(tools)) {
      const t = tools.find(t => Number(t.id) === Number(id));
      if (t) t.is_public = newPublic;
    }

    btn.classList.toggle('is-public', isNowPublic);
    btn.title = isNowPublic ? 'خصوصی کردن' : 'عمومی کردن';
    btn.innerHTML = isNowPublic
      ? `<svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect width="18" height="11" x="3" y="11" rx="2" ry="2"></rect><path d="M7 11V7a5 5 0 0 1 9.9-1"></path></svg>`
      : `<svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect width="18" height="11" x="3" y="11" rx="2" ry="2"></rect><path d="M7 11V7a5 5 0 0 1 10 0v4"></path></svg>`;

    const meta = row.querySelector('.tool-row-meta');
    let pill = meta.querySelector('.public-pill');
    if (isNowPublic) {
      if (!pill) {
        pill = document.createElement('span');
        pill.className = 'public-pill';
        meta.insertBefore(pill, meta.firstChild);
      }
      pill.textContent = '🔓 عمومی';
    } else {
      pill?.remove();
    }

    btn.disabled = false;
    Toast.show(isNowPublic ? 'ابزار عمومی شد' : 'ابزار خصوصی شد');
  },
};

// ═══════════════════════════════════════════════════════════
// UserSearch — جستجوی سمت کلاینت در لیست کاربران
// ═══════════════════════════════════════════════════════════
const UserSearch = {
  init() {
    const input = document.getElementById('userSearchInput');
    const clear = document.getElementById('userSearchClear');
    if (!input) return;
    input.addEventListener('input', () => this.filter(input.value));
    clear?.addEventListener('click', () => {
      input.value = '';
      this.filter('');
      input.focus();
    });
  },

  filter(q) {
    q = (q || '').trim().toLowerCase();
    const wrap = document.querySelector('.user-search');
    if (wrap) wrap.classList.toggle('has-value', q !== '');

    const rows = document.querySelectorAll('#userList .user-row');
    let shown = 0;
    rows.forEach(row => {
      const name = (row.dataset.search || '').toLowerCase();
      const match = !q || name.includes(q);
      row.style.display = match ? '' : 'none';
      if (match) shown++;
    });

    // نمایش پیام «نتیجه‌ای یافت نشد»
    let empty = document.getElementById('userSearchEmpty');
    if (shown === 0 && rows.length > 0) {
      if (!empty) {
        empty = document.createElement('div');
        empty.id = 'userSearchEmpty';
        empty.className = 'empty-tools';
        empty.innerHTML = '<p>کاربری با این مشخصات یافت نشد</p>';
        document.getElementById('userList').appendChild(empty);
      }
      empty.style.display = '';
    } else if (empty) {
      empty.style.display = 'none';
    }
  },
};

// ═══════════════════════════════════════════════════════════
// UserManager
// ═══════════════════════════════════════════════════════════
const UserManager = {
  async add() {
    const fullName = document.getElementById('newFullName').value.trim();
    const email    = document.getElementById('newEmail').value.trim();
    const password = document.getElementById('newUserPassword').value;
    const role     = document.getElementById('newUserRole')?.value || 'user';
    if (!fullName) return FieldErr.set('newFullName', 'نام و نام خانوادگی الزامی است');
    if (!email)    return FieldErr.set('newEmail', 'ایمیل الزامی است');
    if (!/^[A-Za-z0-9._%+-]+@(?:[A-Za-z0-9-]+\.)+[A-Za-z]{2,}$/.test(email)) return FieldErr.set('newEmail', 'قالب ایمیل نامعتبر است');
    if (!password) return FieldErr.set('newUserPassword', 'رمز عبور الزامی است');
    if (!pwMeetsPolicy(password)) return FieldErr.set('newUserPassword', PW_POLICY_MSG);

    const res = await Api.call('add_user', { full_name: fullName, email, password, role });
    if (res.ok) {
      Toast.show('کاربر اضافه شد');
      setTimeout(() => location.reload(), 900);
    } else {
      Toast.show(res.msg || 'خطا در ثبت کاربر', 'error');
    }
  },

  openEdit(id, fullName, email, role) {
    document.getElementById('editUserId').value   = id;
    document.getElementById('editFullName').value = fullName;
    document.getElementById('editEmail').value    = email;
    const editPass = document.getElementById('editUserPassword');
    editPass.value = '';
    editPass.type  = 'password';            // در صورت باز ماندن از دفعه قبل
    checkStrength('', 'editPassStrength', 'editPassStrengthLabel'); // ریست نوار قدرت
    const roleSel = document.getElementById('editUserRole');
    if (roleSel) { roleSel.value = (role === 'admin') ? 'admin' : 'user'; CustomSelect.refresh(roleSel); }
    Modal.open('userModal');
    setTimeout(() => document.getElementById('editFullName').focus(), 100);
  },

  async saveEdit() {
    const id       = parseInt(document.getElementById('editUserId').value);
    const fullName = document.getElementById('editFullName').value.trim();
    const email    = document.getElementById('editEmail').value.trim();
    const password = document.getElementById('editUserPassword').value;
    const role     = document.getElementById('editUserRole')?.value || 'user';
    if (!fullName) return FieldErr.set('editFullName', 'نام و نام خانوادگی الزامی است');
    if (!email)    return FieldErr.set('editEmail', 'ایمیل الزامی است');
    if (!/^[A-Za-z0-9._%+-]+@(?:[A-Za-z0-9-]+\.)+[A-Za-z]{2,}$/.test(email)) return FieldErr.set('editEmail', 'قالب ایمیل نامعتبر است');
    if (password && !pwMeetsPolicy(password)) return FieldErr.set('editUserPassword', PW_POLICY_MSG);

    const res = await Api.call('edit_user', { id, full_name: fullName, email, password, role });
    if (res.ok) {
      Modal.close('userModal');
      Toast.show('کاربر ویرایش شد');
      setTimeout(() => location.reload(), 900);
    } else {
      Toast.show(res.msg || 'خطا در ویرایش', 'error');
    }
  },

  async toggle(id, btn) {
    btn.disabled = true;
    const res = await Api.call('toggle_user', { id });
    if (!res.ok) {
      Toast.show(res.msg || 'خطا', 'error');
      btn.disabled = false;
      return;
    }
    const row         = btn.closest('.user-row');
    const pill        = row.querySelector('.user-status-pill');
    const isNowActive = btn.classList.contains('is-inactive');

    btn.classList.toggle('is-inactive', !isNowActive);
    btn.title = isNowActive ? 'غیرفعال کردن' : 'فعال کردن';

    if (pill) {
      pill.textContent = isNowActive ? 'فعال' : 'غیرفعال';
      pill.className   = `user-status-pill ${isNowActive ? 'active' : 'inactive'}`;
    }
    btn.disabled = false;
    Toast.show(isNowActive ? 'کاربر فعال شد' : 'کاربر غیرفعال شد');
  },

  openDelete(id, name) {
    Confirm.show({
      title:    'حذف کاربر',
      heading:  'آیا از حذف این کاربر اطمینان دارید؟',
      body:     `کاربر <span class="item-name">${name}</span> به‌طور دائم حذف خواهد شد.`,
      warn:     'تمام دسترسی‌های این کاربر نیز حذف خواهد شد.',
      type:     'danger',
      btnLabel: 'حذف کاربر',
      onConfirm: async () => {
        const res = await Api.call('delete_user', { id });
        if (res.ok) {
          Confirm.close();
          Toast.show('کاربر حذف شد');
          setTimeout(() => location.reload(), 900);
        } else {
          Toast.show(res.msg || 'خطا در حذف', 'error');
        }
      },
    });
  },
};

// ═══════════════════════════════════════════════════════════
// AccessManager
// ═══════════════════════════════════════════════════════════
const AccessManager = {
  _currentUserId: null,
  _currentBadges: [],

  async open(userId, userName) {
    this._currentUserId = userId;
    this._currentBadges = [];

    document.getElementById('accessModalTitle').textContent = `تنظیم دسترسی — ${userName}`;
    document.getElementById('accessUserId').value = userId;
    document.getElementById('accessBadgesGrid').innerHTML = '<div style="color:var(--text-3);font-size:13px;">در حال بارگذاری...</div>';
    document.getElementById('accessToolsList').innerHTML  = '<div style="color:var(--text-3);font-size:13px;">در حال بارگذاری...</div>';

    Modal.open('accessModal');

    const [badgesRes, accessRes] = await Promise.all([
      Api.call('badges', {}),
      Api.call('get_access', { user_id: userId }),
    ]);

    if (!badgesRes.ok || !accessRes.ok) {
      Toast.show('خطا در بارگذاری اطلاعات', 'error');
      return;
    }

    this._currentBadges = accessRes.badges || [];
    this._render(badgesRes.badges || [], accessRes.tool_ids || [], accessRes.badges || []);
  },

  _render(availableBadges, selectedToolIds, selectedBadges) {
    const badgesGrid = document.getElementById('accessBadgesGrid');
    if (!availableBadges.length) {
      badgesGrid.innerHTML = '<div style="color:var(--text-3);font-size:13px;">هیچ دسته‌بندی‌ای وجود ندارد</div>';
    } else {
      badgesGrid.innerHTML = '';
      availableBadges.forEach(badge => {
        const checked = selectedBadges.includes(badge);
        const label = document.createElement('label');
        label.className = 'access-badge-label';
        label.innerHTML = `
          <input type="checkbox" class="access-badge-cb" value="${badge}" ${checked ? 'checked' : ''}>
          <span>${badge}</span>
        `;
        label.querySelector('input').addEventListener('change', () => {
          this._currentBadges = [...document.querySelectorAll('.access-badge-cb:checked')].map(c => c.value);
          this._updateToolsHighlight();
        });
        badgesGrid.appendChild(label);
      });
    }

    this._renderTools(selectedToolIds, selectedBadges);
  },

  _renderTools(selectedToolIds, selectedBadges) {
    const list = document.getElementById('accessToolsList');
    list.innerHTML = '';

    if (!TOOLS_RAW.length) {
      list.innerHTML = '<div style="color:var(--text-3);font-size:13px;">هیچ ابزاری وجود ندارد</div>';
      return;
    }

    TOOLS_RAW.forEach(tool => {
      const isPublic   = !!tool.is_public;
      const inBadge    = selectedBadges.includes(tool.badge || '');
      const isChecked  = selectedToolIds.includes(tool.id) || isPublic || inBadge;
      const isDisabled = isPublic || inBadge;

      const row = document.createElement('div');
      row.className = 'access-tool-row';
      row.dataset.badge = tool.badge || '';

      let statusBadge = '';
      if (isPublic)    statusBadge = '<span class="access-status-badge public">🔓 عمومی</span>';
      else if (inBadge) statusBadge = '<span class="access-status-badge from-badge">✓ از دسته</span>';

      row.innerHTML = `
        <label class="access-tool-label ${isDisabled ? 'disabled' : ''}">
          <input type="checkbox"
            class="access-tool-cb"
            value="${tool.id}"
            ${isChecked   ? 'checked'  : ''}
            ${isDisabled  ? 'disabled' : ''}
          >
          <span class="access-tool-info">
            <span class="access-tool-title">${tool.title || ''}</span>
            ${tool.badge ? `<span class="access-tool-badge">${tool.badge}</span>` : ''}
          </span>
          ${statusBadge}
        </label>
      `;
      list.appendChild(row);
    });
  },

  _updateToolsHighlight() {
    const selectedBadges = this._currentBadges;
    document.querySelectorAll('.access-tool-row').forEach(row => {
      const badge    = row.dataset.badge;
      const inBadge  = badge && selectedBadges.includes(badge);
      const cb       = row.querySelector('.access-tool-cb');
      const label    = row.querySelector('.access-tool-label');
      const isPublic = cb.disabled && row.querySelector('.access-status-badge.public');

      if (!isPublic) {
        cb.disabled = !!inBadge;
        if (inBadge) cb.checked = true;
        label.classList.toggle('disabled', !!inBadge);

        let statusBadge = row.querySelector('.access-status-badge');
        if (inBadge) {
          if (!statusBadge) {
            statusBadge = document.createElement('span');
            row.querySelector('.access-tool-label').appendChild(statusBadge);
          }
          statusBadge.className   = 'access-status-badge from-badge';
          statusBadge.textContent = '✓ از دسته';
        } else {
          statusBadge?.remove();
        }
      }
    });
  },

  async save() {
    const userId  = parseInt(document.getElementById('accessUserId').value);
    const toolIds = [...document.querySelectorAll('.access-tool-cb:checked:not(:disabled)')]
                      .map(cb => parseInt(cb.value));
    const badges  = [...document.querySelectorAll('.access-badge-cb:checked')]
                      .map(cb => cb.value);

    const btn = document.getElementById('saveAccessBtn');
    btn.disabled = true;

    const res = await Api.call('set_access', { user_id: userId, tool_ids: toolIds, badges });
    if (res.ok) {
      Modal.close('accessModal');
      Toast.show('دسترسی‌ها ذخیره شد');
    } else {
      Toast.show(res.msg || 'خطا در ذخیره', 'error');
    }
    btn.disabled = false;
  },
};

// ═══════════════════════════════════════════════════════════
// IconPicker
// ═══════════════════════════════════════════════════════════
const IconPicker = {
  build() {
    const grid = document.getElementById('iconGrid');
    grid.innerHTML = '';
    for (const [key, path] of Object.entries(ICONS_DATA)) {
      const btn = document.createElement('button');
      btn.type = 'button';
      btn.className = 'icon-opt' + (key === State.selIcon ? ' active' : '');
      btn.title = key;
      btn.dataset.key = key;
      btn.innerHTML = `<svg viewBox="0 0 24 24" width="17" height="17">${path}</svg>`;
      btn.onclick = () => this.select(key);
      grid.appendChild(btn);
    }
  },
  select(key) {
    State.selIcon = key;
    ToolForm._markDirty();
    document.querySelectorAll('#iconGrid .icon-opt').forEach(b =>
      b.classList.toggle('active', b.dataset.key === key)
    );
    Preview.update();
  },
};

// ═══════════════════════════════════════════════════════════
// DecoPicker
// ═══════════════════════════════════════════════════════════
const DecoPicker = {
  build() {
    const grid = document.getElementById('decoGrid');
    grid.innerHTML = '';
    for (const key of Object.keys(DECOS_DATA)) {
      const btn = document.createElement('button');
      btn.type = 'button';
      btn.className = 'deco-opt' + (key === State.selDeco ? ' active' : '');
      btn.dataset.deco = key;
      btn.textContent  = key;
      btn.onclick = () => this.select(key);
      grid.appendChild(btn);
    }
  },
  select(key) {
    State.selDeco = key;
    ToolForm._markDirty();
    document.querySelectorAll('#decoGrid .deco-opt').forEach(b =>
      b.classList.toggle('active', b.dataset.deco === key)
    );
    Preview.update();
  },
};

// ═══════════════════════════════════════════════════════════
// IconEditor
// ═══════════════════════════════════════════════════════════
const IconEditor = {
  buildGrid() {
    const grid = document.getElementById('iconAssetGrid');
    grid.innerHTML = '';
    document.getElementById('iconCountBadge').textContent = Object.keys(ICONS_DATA).length;
    for (const [key, path] of Object.entries(ICONS_DATA)) {
      const btn = document.createElement('button');
      btn.type = 'button';
      btn.className = 'asset-opt' + (key === State.selIconKey ? ' active' : '');
      btn.title = key;
      btn.dataset.key = key;
      btn.innerHTML = `<svg viewBox="0 0 24 24" width="17" height="17">${path}</svg>`;
      btn.onclick = () => this.open(key);
      grid.appendChild(btn);
    }
  },
  open(key) {
    State.selIconKey = key;
    this.buildGrid();
    document.getElementById('iconEditor').style.display    = 'block';
    document.getElementById('iconEditorKey').textContent   = key;
    document.getElementById('iconEditorPath').value        = ICONS_DATA[key] || '';
    document.getElementById('iconDeleteBtn').disabled      = (key === 'star');
  },
  async save() {
    const path = document.getElementById('iconEditorPath').value.trim();
    if (!path) { Toast.show('SVG path نمی‌تواند خالی باشد', 'error'); return; }
    const res = await Api.call('save_icon', { key: State.selIconKey, path });
    if (res.ok) {
      ICONS_DATA[State.selIconKey] = path;
      this.buildGrid();
      IconPicker.build();
      Toast.show('آیکون ذخیره شد');
    } else {
      Toast.show(res.msg, 'error');
    }
  },
  async delete() {
    const key = State.selIconKey;
    if (!key || key === 'star') { Toast.show('آیکون star قابل حذف نیست', 'error'); return; }
    const usedBy = (window.tools || []).filter(t => t.iconKey === key).map(t => t.title);
    Confirm.show({
      title:    'حذف آیکون',
      heading:  'آیا از حذف این آیکون اطمینان دارید؟',
      body:     `آیکون <span class="item-name">${key}</span> به‌طور دائم حذف خواهد شد.`,
      warn:     usedBy.length ? `این آیکون در ابزار «${usedBy.join('، ')}» استفاده شده است.` : null,
      type:     usedBy.length ? 'warning' : 'danger',
      btnLabel: 'حذف آیکون',
      onConfirm: async () => {
        const res = await Api.call('delete_icon', { key });
        if (res.ok) {
          delete ICONS_DATA[key];
          State.selIconKey = null;
          document.getElementById('iconEditor').style.display = 'none';
          this.buildGrid();
          IconPicker.build();
          Confirm.close();
          Toast.show('آیکون با موفقیت حذف شد');
        } else {
          Toast.show(res.msg, 'error');
        }
      },
    });
  },
  async add() {
    const key  = document.getElementById('newIconKey').value.trim();
    const path = document.getElementById('newIconPath').value.trim();
    if (!key)  { Toast.show('نام آیکون الزامی است', 'error'); return; }
    if (!path) { Toast.show('SVG path الزامی است', 'error');   return; }
    const res = await Api.call('save_icon', { key, path });
    if (res.ok) {
      ICONS_DATA[key] = path;
      document.getElementById('newIconKey').value  = '';
      document.getElementById('newIconPath').value = '';
      this.buildGrid();
      IconPicker.build();
      Toast.show('آیکون اضافه شد');
    } else {
      Toast.show(res.msg, 'error');
    }
  },
};

// ═══════════════════════════════════════════════════════════
// DecoEditor
// ═══════════════════════════════════════════════════════════
const DecoEditor = {
  buildGrid() {
    const grid = document.getElementById('decoAssetGrid');
    grid.innerHTML = '';
    document.getElementById('decoCountBadge').textContent = Object.keys(DECOS_DATA).length;
    for (const key of Object.keys(DECOS_DATA)) {
      const btn = document.createElement('button');
      btn.type = 'button';
      btn.className = 'asset-opt deco-item' + (key === State.selDecoKey ? ' active' : '');
      btn.dataset.key = key;
      btn.textContent = key;
      btn.onclick = () => this.open(key);
      grid.appendChild(btn);
    }
  },
  open(key) {
    State.selDecoKey = key;
    this.buildGrid();
    document.getElementById('decoEditor').style.display  = 'block';
    document.getElementById('decoEditorKey').textContent = key;
    document.getElementById('decoEditorSVG').value       = DECOS_DATA[key] || '';
    const delBtn = document.getElementById('decoDeleteBtn');
    delBtn.disabled = (key === 'generic');
    delBtn.title    = (key === 'generic') ? 'انیمیشن پیش‌فرض قابل حذف نیست' : 'حذف انیمیشن';
    setTimeout(() => this.refreshPreview(), 30);
  },
  refreshPreview() {
    const svg = document.getElementById('decoEditorSVG').value.trim();
    const old = document.getElementById('decoEditorPreview');
    const fresh = old.cloneNode(false);
    fresh.style.setProperty('--card-color', '#58a6ff');
    fresh.innerHTML = svg;
    old.parentNode.replaceChild(fresh, old);
  },
  async save() {
    const svg = document.getElementById('decoEditorSVG').value.trim();
    if (!svg) { Toast.show('SVG نمی‌تواند خالی باشد', 'error'); return; }
    const res = await Api.call('save_deco', { key: State.selDecoKey, svg });
    if (res.ok) {
      DECOS_DATA[State.selDecoKey] = svg;
      DecoPicker.build();
      Toast.show('انیمیشن ذخیره شد');
      Preview.update();
    } else {
      Toast.show(res.msg, 'error');
    }
  },
  async delete() {
    const key = State.selDecoKey;
    if (!key || key === 'generic') { Toast.show('انیمیشن پیش‌فرض قابل حذف نیست', 'error'); return; }
    const usedBy = (window.tools || []).filter(t => (t.deco || 'generic') === key).map(t => t.title);
    Confirm.show({
      title:    'حذف انیمیشن',
      heading:  'آیا از حذف این انیمیشن اطمینان دارید؟',
      body:     `انیمیشن <span class="item-name">${key}</span> به‌طور دائم حذف خواهد شد.`,
      warn:     usedBy.length ? `ابزار «${usedBy.join('، ')}» از این انیمیشن استفاده می‌کنند.` : null,
      type:     usedBy.length ? 'warning' : 'danger',
      btnLabel: 'حذف انیمیشن',
      onConfirm: async () => {
        const res = await Api.call('delete_deco', { key });
        if (res.ok) {
          delete DECOS_DATA[key];
          State.selDecoKey = null;
          document.getElementById('decoEditor').style.display = 'none';
          this.buildGrid();
          DecoPicker.build();
          Confirm.close();
          Toast.show(res.fallback ? 'انیمیشن حذف شد و ابزارهای مرتبط به پیش‌فرض بازگشتند' : 'انیمیشن حذف شد');
        } else {
          Toast.show(res.msg, 'error');
        }
      },
    });
  },
  async add() {
    const key = document.getElementById('newDecoKey').value.trim();
    const svg = document.getElementById('newDecoSVG').value.trim();
    if (!key) { Toast.show('نام انیمیشن الزامی است', 'error'); return; }
    if (!svg) { Toast.show('SVG الزامی است', 'error');          return; }
    const res = await Api.call('save_deco', { key, svg });
    if (res.ok) {
      DECOS_DATA[key] = svg;
      document.getElementById('newDecoKey').value = '';
      document.getElementById('newDecoSVG').value = '';
      this.buildGrid();
      DecoPicker.build();
      Toast.show('انیمیشن اضافه شد');
    } else {
      Toast.show(res.msg, 'error');
    }
  },
};

// ═══════════════════════════════════════════════════════════
// ToolsView — رندر سمت کلاینت + صفحه‌بندی + جستجو + حالت مرتب‌سازی
// (گلوگاه واقعی رندر صدها کارت در DOM است؛ هر بار فقط یک صفحه رندر می‌شود)
// ═══════════════════════════════════════════════════════════
const ToolsView = {
  perPage: 20,
  page:    1,
  query:   '',
  mode:    'normal',   // 'normal' | 'reorder'
  total:     0,        // کل ابزارهای مطابق جستجو (از سرور)
  pageCount: 1,
  _pageRows: [],       // ردیف‌های کاملِ صفحهٔ جاری (برای ویرایش)
  _loading:  false,

  init() {
    this._list  = document.getElementById('toolList');
    if (!this._list) return;
    this._pager = document.getElementById('toolPagination');
    this._badge = document.getElementById('toolCountBadge');
    this._search = document.getElementById('toolSearchInput');
    this._searchWrap = document.getElementById('toolSearchWrap');
    this._reorderBtn = document.getElementById('reorderModeBtn');

    if (this._search) {
      let t = null;
      this._search.addEventListener('input', () => {
        clearTimeout(t);
        t = setTimeout(() => { this.query = this._search.value.trim(); this.page = 1; this.render(); }, 250);
      });
      const clr = document.getElementById('toolSearchClear');
      const syncClear = () => this._searchWrap?.classList.toggle('has-value', this._search.value !== '');
      this._search.addEventListener('input', syncClear);
      clr?.addEventListener('click', () => { this._search.value=''; this.query=''; this.page=1; syncClear(); this.render(); this._search.focus(); });
    }

    DragDrop.init(this._list);
    this.render();
  },

  // نسخهٔ سبکِ «همهٔ ابزارها» (برای مرتب‌سازی و شمارش) — از سرور تزریق شده
  _all() { return Array.isArray(tools) ? tools : []; },

  _iconSvg(key) {
    const inner = (typeof ICONS_DATA !== 'undefined' && (ICONS_DATA[key] || ICONS_DATA['star'])) || '';
    return `<svg viewBox="0 0 24 24" width="18" height="18">${inner}</svg>`;
  },

  _isExternal(path) { return /^https?:\/\//i.test(path || ''); },

  // کارت کامل (نمای عادی)
  _cardRow(t) {
    const isPub = Number(t.is_public) === 1;
    const ext = this._isExternal(t.path)
      ? `<svg viewBox="0 0 24 24" width="11" height="11" fill="none" stroke="currentColor" stroke-width="2" style="flex-shrink:0;opacity:.6"><path d="M18 13v6a2 2 0 01-2 2H5a2 2 0 01-2-2V8a2 2 0 012-2h6"/><polyline points="15 3 21 3 21 9"/><line x1="10" y1="14" x2="21" y2="3"/></svg>`
      : '';
    const lockOpen  = `<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="3" y="11" width="18" height="11" rx="2" ry="2"/><path d="M7 11V7a5 5 0 0 1 9.9-1"/></svg>`;
    const lockClose = `<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="3" y="11" width="18" height="11" rx="2" ry="2"/><path d="M7 11V7a5 5 0 0 1 10 0v4"/></svg>`;
    return `
      <div class="tool-row" data-id="${Number(t.id)}" data-public="${isPub?1:0}">
        <div class="tool-row-handle">
          <svg viewBox="0 0 24 24" fill="currentColor"><circle cx="9" cy="5" r="1.5"/><circle cx="15" cy="5" r="1.5"/><circle cx="9" cy="12" r="1.5"/><circle cx="15" cy="12" r="1.5"/><circle cx="9" cy="19" r="1.5"/><circle cx="15" cy="19" r="1.5"/></svg>
        </div>
        <div class="tool-row-icon">${this._iconSvg(t.iconKey || 'star')}</div>
        <div class="tool-row-info">
          <h3>${esc(t.title)}</h3>
          <p>${esc(t.description)}</p>
        </div>
        <div class="tool-row-meta">
          ${isPub ? '<span class="public-pill">🔓 عمومی</span>' : ''}
          ${t.badge ? `<span class="badge-pill">${esc(t.badge)}</span>` : ''}
          <span class="tool-row-path">${ext}<span class="tool-row-path-txt">${esc(t.path)}</span></span>
        </div>
        <div class="tool-row-actions">
          <button class="btn btn-secondary btn-icon btn-sm toggle-public-btn ${isPub?'is-public':''}" onclick="togglePublic(${Number(t.id)}, this)" title="${isPub?'خصوصی کردن':'عمومی کردن'}">${isPub?lockOpen:lockClose}</button>
          <button class="btn btn-secondary btn-icon btn-sm" onclick="openEditModal(${Number(t.id)})" title="ویرایش"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M11 4H4a2 2 0 00-2 2v14a2 2 0 002 2h14a2 2 0 002-2v-7"/><path d="M18.5 2.5a2.121 2.121 0 013 3L12 15l-4 1 1-4 9.5-9.5z"/></svg></button>
          <button class="btn btn-danger btn-icon btn-sm" onclick="openDeleteModal(${Number(t.id)},'${esc(t.title).replace(/'/g,"\\'")}')" title="حذف"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polyline points="3 6 5 6 21 6"/><path d="M19 6l-1 14a2 2 0 01-2 2H8a2 2 0 01-2-2L5 6"/><path d="M10 11v6M14 11v6M9 6V4a1 1 0 011-1h4a1 1 0 011 1v2"/></svg></button>
        </div>
      </div>`;
  },

  // ردیف سبک (نمای مرتب‌سازی) — برای جابه‌جایی صدها مورد بدون افت کارایی
  _reorderRow(t) {
    return `
      <div class="tool-row reorder-row" data-id="${Number(t.id)}" draggable="true">
        <div class="tool-row-handle">
          <svg viewBox="0 0 24 24" fill="currentColor"><circle cx="9" cy="5" r="1.5"/><circle cx="15" cy="5" r="1.5"/><circle cx="9" cy="12" r="1.5"/><circle cx="15" cy="12" r="1.5"/><circle cx="9" cy="19" r="1.5"/><circle cx="15" cy="19" r="1.5"/></svg>
        </div>
        <div class="tool-row-icon">${this._iconSvg(t.iconKey || 'star')}</div>
        <div class="tool-row-info"><h3>${esc(t.title)}</h3></div>
        <div class="tool-row-meta">${t.badge ? `<span class="badge-pill">${esc(t.badge)}</span>` : ''}</div>
      </div>`;
  },

  // صفحه‌بندیِ سمت سرور: فقط ردیف‌های صفحهٔ جاری از DB می‌آیند
  async render() {
    if (this._badge) this._badge.textContent = this._all().length;
    if (this._loading) return;
    this._loading = true;
    const res = await Api.call('list_tools', { page: this.page, per_page: this.perPage, search: this.query });
    this._loading = false;

    if (!res || !res.ok) { Toast.show((res && res.msg) || 'خطا در بارگذاری ابزارها', 'error'); return; }

    this._pageRows = res.tools || [];
    const pg = res.pagination || {};
    this.total     = pg.total      ?? this._pageRows.length;
    this.pageCount = pg.page_count ?? 1;
    this.page      = pg.page       ?? this.page;

    // اگر صفحهٔ جاری خالی شد (مثلاً پس از حذف/جستجو) یک صفحه عقب برو
    if (this._pageRows.length === 0 && this.page > 1) { this.page = this.pageCount; return this.render(); }

    if (this._pageRows.length === 0) {
      this._list.innerHTML = `<div class="empty-tools"><svg viewBox="0 0 24 24"><path d="M12 2L2 7l10 5 10-5-10-5zM2 17l10 5 10-5M2 12l10 5 10-5"/></svg><p>${this.query ? 'موردی یافت نشد' : 'هنوز هیچ ابزاری اضافه نشده'}</p></div>`;
      if (this._pager) this._pager.hidden = true;
      return;
    }

    this._list.classList.remove('reordering');
    this._list.innerHTML = this._pageRows.map(t => this._cardRow(t)).join('');
    this._renderPager(this.pageCount, this.total);
  },

  _renderPager(totalPages, totalItems) {
    if (!this._pager) return;
    if (totalPages <= 1) { this._pager.hidden = true; this._pager.innerHTML=''; return; }
    this._pager.hidden = false;
    const btn = (label, page, opts={}) =>
      `<button class="pg-btn ${opts.active?'active':''}" ${opts.disabled?'disabled':''} onclick="ToolsView.goto(${page})">${label}</button>`;
    let nums = '';
    const win = 2;
    for (let p = 1; p <= totalPages; p++) {
      if (p === 1 || p === totalPages || (p >= this.page - win && p <= this.page + win)) {
        nums += btn(p, p, { active: p === this.page });
      } else if (p === this.page - win - 1 || p === this.page + win + 1) {
        nums += `<span class="pg-ellipsis">…</span>`;
      }
    }
    this._pager.innerHTML =
      `<span class="pg-info">${totalItems} ابزار · صفحه ${this.page} از ${totalPages}</span>
       <div class="pg-controls">
         ${btn('« قبلی', this.page - 1, { disabled: this.page <= 1 })}
         ${nums}
         ${btn('بعدی »', this.page + 1, { disabled: this.page >= totalPages })}
       </div>`;
  },

  async goto(p) {
    if (this.mode !== 'normal') return;
    this.page = Math.max(1, p);
    await this.render();
    this._list.scrollIntoView({ behavior: 'smooth', block: 'start' });
  },

  // ── حالت مرتب‌سازی (همه کارت‌ها با هم) ──────────────────
  enterReorder() {
    if (this._all().length < 2) return;
    this.mode = 'reorder';
    this._list.classList.add('reordering');
    this._list.innerHTML = this._all().map(t => this._reorderRow(t)).join('');
    if (this._pager) this._pager.hidden = true;
    if (this._searchWrap) this._searchWrap.style.display = 'none';
    if (this._reorderBtn) this._reorderBtn.style.display = 'none';
    const bar = document.getElementById('reorderBar');
    if (bar) bar.hidden = false;
    window.scrollTo({ top: 0, behavior: 'smooth' });
  },

  exitReorder() {
    this.mode = 'normal';
    const bar = document.getElementById('reorderBar');
    if (bar) bar.hidden = true;
    if (this._searchWrap) this._searchWrap.style.display = '';
    if (this._reorderBtn) this._reorderBtn.style.display = '';
    this.render(); // بازرندر از ترتیب اصلی در حافظه → تغییرات نمایشی دور ریخته می‌شوند
  },

  // ذخیره ترتیب: یک ریکوئست با آرایه کامل id ها
  async save() {
    const ids = [...this._list.querySelectorAll('.tool-row')].map(r => Number(r.dataset.id));
    const btn = document.getElementById('reorderSaveBtn');
    if (btn) btn.disabled = true;
    const res = await Api.call('reorder', { ids });
    if (res.ok) {
      Toast.show('ترتیب ذخیره شد');
      setTimeout(() => location.reload(), 700);
    } else {
      if (btn) btn.disabled = false;
      Toast.show(res.msg || 'خطا در ذخیره ترتیب', 'error');
    }
  },
};

// ═══════════════════════════════════════════════════════════
// DragDrop — فقط در حالت مرتب‌سازی فعال است
// ═══════════════════════════════════════════════════════════
const DragDrop = {
  init(list) {
    this._list = list || document.getElementById('toolList');
    if (!this._list) return;
    this._initDesktop(this._list);
    this._initMobile(this._list);
  },
  _active() { return ToolsView.mode === 'reorder'; },
  _insertAt(list, dragEl, clientY) {
    const rows = [...list.querySelectorAll('.tool-row')].filter(r => r !== dragEl);
    let inserted = false;
    for (const row of rows) {
      const rect = row.getBoundingClientRect();
      if (clientY < rect.top + rect.height / 2) {
        list.insertBefore(dragEl, row);
        inserted = true;
        break;
      }
    }
    if (!inserted) list.appendChild(dragEl);
  },
  _initDesktop(list) {
    let drag = null;
    list.addEventListener('dragstart', e => {
      if (!this._active()) return;
      drag = e.target.closest('.tool-row');
      if (drag) { drag.style.opacity = '.4'; e.dataTransfer.effectAllowed = 'move'; }
    });
    list.addEventListener('dragend', () => { if (drag) { drag.style.opacity = ''; drag = null; } });
    list.addEventListener('dragover', e => {
      if (!this._active() || !drag) return;
      e.preventDefault();
      const t = e.target.closest('.tool-row');
      if (t && t !== drag) this._insertAt(list, drag, e.clientY);
    });
    list.addEventListener('drop', e => { if (this._active()) e.preventDefault(); });
  },
  _initMobile(list) {
    let touchDrag = null, touchClone = null, touchOffsetY = 0;
    list.addEventListener('touchstart', e => {
      if (!this._active()) return;
      const handle = e.target.closest('.tool-row-handle');
      if (!handle) return;
      const row = handle.closest('.tool-row');
      if (!row) return;
      e.preventDefault();
      touchDrag = row;
      const rect = row.getBoundingClientRect();
      touchOffsetY = e.touches[0].clientY - rect.top;
      touchClone = row.cloneNode(true);
      Object.assign(touchClone.style, {
        position:'fixed', zIndex:'999', left:`${rect.left}px`, top:`${rect.top}px`,
        width:`${rect.width}px`, opacity:'.85', pointerEvents:'none',
        boxShadow:'0 8px 24px rgba(0,0,0,.4)', borderRadius:'var(--radius-lg)',
      });
      document.body.appendChild(touchClone);
      row.style.opacity = '.3';
    }, { passive: false });
    list.addEventListener('touchmove', e => {
      if (!touchDrag) return;
      e.preventDefault();
      const y = e.touches[0].clientY;
      touchClone.style.top = (y - touchOffsetY) + 'px';
      this._insertAt(list, touchDrag, y);
    }, { passive: false });
    const endTouch = () => {
      if (touchDrag) { touchDrag.style.opacity = ''; }
      touchClone?.remove();
      touchDrag = touchClone = null;
    };
    list.addEventListener('touchend',    endTouch);
    list.addEventListener('touchcancel', endTouch);
  },
};

// ═══════════════════════════════════════════════════════════
// Theme
// ═══════════════════════════════════════════════════════════
const Theme = {
  META_COLOR: { light: '#3e7de7', dark: '#0d1117' },

  _meta(theme) {
    let m = document.querySelector('meta[name="theme-color"]');
    if (!m) { m = document.createElement('meta'); m.setAttribute('name','theme-color'); document.head.appendChild(m); }
    m.setAttribute('content', this.META_COLOR[theme] || this.META_COLOR.light);
  },

  // اعمال تم بدون لگ: transition همه عناصر برای یک فریم خاموش می‌شود
  apply(theme, persist = true) {
    const root = document.documentElement;
    root.classList.add('theme-switching');
    if (theme === 'dark') root.setAttribute('data-theme','dark');
    else root.removeAttribute('data-theme');
    this._meta(theme);
    if (persist) { try { localStorage.setItem('theme', theme); } catch (e) {} }
    requestAnimationFrame(() => {
      requestAnimationFrame(() => root.classList.remove('theme-switching'));
    });
  },

  toggle() {
    this.apply(
      document.documentElement.getAttribute('data-theme') === 'dark' ? 'light' : 'dark'
    );
  },

  init() {
    this._meta(document.documentElement.getAttribute('data-theme') === 'dark' ? 'dark' : 'light');
    // همگام بین تب‌ها
    window.addEventListener('storage', e => {
      if (e.key === 'theme' && (e.newValue === 'dark' || e.newValue === 'light')) {
        this.apply(e.newValue, false);
      }
    });
    // تغییر تم سیستم وقتی کاربر انتخاب دستی نکرده
    window.matchMedia('(prefers-color-scheme: dark)').addEventListener('change', e => {
      if (!localStorage.getItem('theme')) this.apply(e.matches ? 'dark' : 'light', false);
    });
  },
};

// ═══════════════════════════════════════════════════════════
// توابع عمومی — صدا زده شده از HTML
// ═══════════════════════════════════════════════════════════
function toggleBox(id)              { document.getElementById(id).classList.toggle('open'); }
function toggleTheme()              { Theme.toggle(); }
function updatePreview()            { ToolForm._markDirty(); Preview.update(); }
function openAddModal()             { ToolForm.openAdd(); }
function openEditModal(id)          { ToolForm.openEdit(id); }
function openDeleteModal(i, n)      { ToolForm.openDelete(i, n); }
function saveForm()                 { ToolForm.save(); }
function closeConfirm()             { Confirm.close(); }
function runConfirm()               { Confirm.run(); }
function closeModal(id)             { if (id === 'formModal') { ToolForm.requestClose(); return; } Modal.close(id); }
function saveIconEdit()             { IconEditor.save(); }
function deleteIcon()               { IconEditor.delete(); }
function addNewIcon()               { IconEditor.add(); }
function saveDecoEdit()             { DecoEditor.save(); }
function deleteDeco()               { DecoEditor.delete(); }
function addNewDeco()               { DecoEditor.add(); }
function refreshDecoPreview()       { DecoEditor.refreshPreview(); }
function togglePublic(id, btn)      { TogglePublic.toggle(id, btn); }
function addNewUser()               { UserManager.add(); }
function openEditUserModal(id,n,e,r){ UserManager.openEdit(id, n, e, r); }
function saveUserEdit()             { UserManager.saveEdit(); }
function toggleUser(id, btn)        { UserManager.toggle(id, btn); }
function openDeleteUserModal(id, n) { UserManager.openDelete(id, n); }
function openAccessModal(id, name)  { AccessManager.open(id, name); }
function saveAccess()               { AccessManager.save(); }
function saveReorder()              { ToolsView.save(); }
function cancelReorder()            { ToolsView.exitReorder(); }
function enterReorderMode()         { ToolsView.enterReorder(); }

/* ── نمایش/مخفی کردن رمز عبور ── */
function togglePass(inputId, btn) {
  const input = document.getElementById(inputId);
  if (!input) return;
  const isPass = input.type === 'password';
  input.type = isPass ? 'text' : 'password';
  btn.innerHTML = isPass
    ? '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"><path d="M17.94 17.94A10.07 10.07 0 0112 20c-7 0-11-8-11-8a18.45 18.45 0 015.06-5.94"/><path d="M9.9 4.24A9.12 9.12 0 0112 4c7 0 11 8 11 8a18.5 18.5 0 01-2.16 3.19"/><line x1="1" y1="1" x2="23" y2="23"/></svg>'
    : '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"><path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"/><circle cx="12" cy="12" r="3"/></svg>';
}

/* ── سنجش قدرت رمز (نوار + برچسب) ── */
function checkStrength(val, barId, labelId) {
  const bar   = document.getElementById(barId);
  const label = document.getElementById(labelId);
  if (!bar) return;
  if (!val) {
    bar.style.display = 'none';
    if (label) label.textContent = '';
    bar.className = 'pass-strength';
    return;
  }
  bar.style.display = 'flex';
  let score = 0;
  if (val.length >= 8)           score++;
  if (/[A-Z]/.test(val))         score++;
  if (/[0-9]/.test(val))         score++;
  if (/[^A-Za-z0-9]/.test(val))  score++;
  const levels = ['', 'ضعیف', 'متوسط', 'خوب', 'قوی'];
  bar.className = 'pass-strength strength-' + (score || 1);
  if (label) label.textContent = val.length < 6 ? 'خیلی کوتاه' : (levels[score] || 'ضعیف');
}

/* ── سیاست رمز: حداقل «متوسط» (هم‌راستا با PasswordPolicy سمت سرور) ── */
const PW_POLICY_MSG = 'رمز عبور باید حداقل در سطح «متوسط» باشد: دست‌کم ۶ کاراکتر همراه با ترکیبی از حروف بزرگ، عدد یا نماد.';
function pwMeetsPolicy(val) {
  if (!val || val.length < 6) return false;
  let score = 0;
  if (val.length >= 8)          score++;
  if (/[A-Z]/.test(val))        score++;
  if (/[0-9]/.test(val))        score++;
  if (/[^A-Za-z0-9]/.test(val)) score++;
  return score >= 2;
}

// ═══════════════════════════════════════════════════════════
// SecurityManager — انسداد ورود (Rate limit): مشاهده لاگ و رفع انسداد
// ═══════════════════════════════════════════════════════════
const SecurityManager = {
  open() { Modal.open('blocksModal'); this.refresh(); },

  async refresh() {
    const box = document.getElementById('blocksList');
    box.innerHTML = '<div class="blocks-loading">در حال بارگذاری…</div>';
    const res = await Api.call('list_blocks', {});
    if (!res.ok) { box.innerHTML = '<div class="blocks-empty">خطا در دریافت اطلاعات</div>'; return; }
    this.render(res.blocks || []);
  },

  render(rows) {
    const box = document.getElementById('blocksList');
    if (!rows.length) {
      box.innerHTML = '<div class="blocks-empty">موردی برای نمایش نیست — هیچ IP محدود یا بلاک‌شده‌ای وجود ندارد.</div>';
      return;
    }
    box.innerHTML = rows.map(r => this._row(r)).join('');
  },

  _row(r) {
    const scopeLabel = r.scope === 'admin' ? 'پنل مدیریت' : 'ورود کاربر';
    const last = r.last_attempt ? new Date(r.last_attempt * 1000).toLocaleString('fa-IR') : '—';
    const status = r.is_blocked
      ? `<span class="blk-badge blk-blocked">بلاک · ${this._remain(r.remaining)} باقی‌مانده</span>`
      : `<span class="blk-badge blk-watch">در حال پایش</span>`;
    const ip = esc(r.ip), sc = esc(r.scope);
    return `
      <div class="blk-row${r.is_blocked ? ' is-blocked' : ''}">
        <div class="blk-info">
          <div class="blk-ip" dir="ltr">${ip}</div>
          <div class="blk-meta">${scopeLabel} · ${r.attempts} تلاش ناموفق · آخرین تلاش: ${last}</div>
        </div>
        <div class="blk-side">
          ${status}
          <button class="btn btn-danger btn-sm" onclick="SecurityManager.unblock('${ip}','${sc}')">رفع انسداد</button>
        </div>
      </div>`;
  },

  _remain(sec) { const m = Math.ceil(sec / 60); return m > 1 ? `${m} دقیقه` : 'کمتر از ۱ دقیقه'; },

  unblock(ip, scope) {
    Confirm.show({
      title:    'رفع انسداد',
      heading:  'رفع انسداد این IP؟',
      body:     `شمارنده تلاش‌های ناموفق <b dir="ltr">${esc(ip)}</b> صفر می‌شود و امکان ورود دوباره فراهم می‌گردد.`,
      type:     'warning',
      btnLabel: 'رفع انسداد',
      icon:     '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="3" y="11" width="18" height="11" rx="2"/><path d="M7 11V7a5 5 0 0 1 9.9-1"/></svg>',
      onConfirm: async () => {
        Confirm.close();
        const res = await Api.call('unblock_ip', { ip, scope });
        if (res.ok) { Toast.show('انسداد رفع شد'); this.refresh(); }
        else        { Toast.show(res.msg || 'خطا در رفع انسداد', 'error'); }
      },
    });
  },
};
function openBlocksModal() { SecurityManager.open(); }

function selectColor(btn, color) {
  State.selColor = color;
  ToolForm._markDirty();
  document.querySelectorAll('.color-preset').forEach(b => b.classList.remove('active'));
  btn.classList.add('active');
  if (color) document.getElementById('customColor').value = color;
  Preview.update();
}
function onCustomColor(input) {
  State.selColor = input.value;
  ToolForm._markDirty();
  document.querySelectorAll('.color-preset').forEach(b => b.classList.remove('active'));
  Preview.update();
}

// ═══════════════════════════════════════════════════════════
// Bootstrap
// ═══════════════════════════════════════════════════════════
// ═══════════════════════════════════════════════════════════
// SettingsManager — ذخیره تنظیمات ایمیل/SMTP + ارسال آزمایشی
// ═══════════════════════════════════════════════════════════
const SettingsManager = {
  _v(id) { const el = document.getElementById(id); return el ? el.value.trim() : ''; },

  async save() {
    const payload = {
      smtp_enabled:    document.getElementById('setSmtpEnabled').checked ? 1 : 0,
      smtp_host:       this._v('setSmtpHost'),
      smtp_port:       this._v('setSmtpPort'),
      smtp_secure:     document.getElementById('setSmtpSecure').value,
      smtp_user:       this._v('setSmtpUser'),
      smtp_pass:       document.getElementById('setSmtpPass').value, // بدون trim تا رمز دست‌نخورده بماند
      smtp_from_email: this._v('setSmtpFromEmail'),
      smtp_from_name:  this._v('setSmtpFromName'),
      resend_cooldown: this._v('setResendCooldown'),
      code_ttl:        this._v('setCodeTtl'),
    };
    const res = await Api.call('save_settings', payload);
    if (res.ok) {
      Toast.show('تنظیمات ذخیره شد');
      document.getElementById('setSmtpPass').value = ''; // پاک‌سازی فیلد رمز پس از ذخیره
    } else {
      Toast.show(res.msg || 'خطا در ذخیره تنظیمات', 'error');
    }
  },

  async test() {
    const to = this._v('setTestEmail');
    if (!to) return FieldErr.set('setTestEmail', 'ایمیل مقصد را وارد کنید');
    if (!/^[A-Za-z0-9._%+-]+@(?:[A-Za-z0-9-]+\.)+[A-Za-z]{2,}$/.test(to)) return FieldErr.set('setTestEmail', 'قالب ایمیل نامعتبر است');
    Toast.show('در حال ارسال…');
    const res = await Api.call('test_email', { test_email: to });
    if (res.ok) Toast.show(res.msg || 'ایمیل آزمایشی ارسال شد');
    else Toast.show(res.msg || 'ارسال ناموفق بود', 'error');
  },
};

// ═══════════════════════════════════════════════════════════
// CustomSelect — ارتقای <select> بومی به dropdown هماهنگ با تم
// نکته: <select> اصلی منبع حقیقت مقدار می‌ماند (هیدن می‌شود) تا
// کد موجود که .value را می‌خواند دست‌نخورده کار کند؛ انتخاب‌ها مقدار
// را روی همان select می‌نویسند و رویداد change را شلیک می‌کنند.
// ═══════════════════════════════════════════════════════════
const CustomSelect = {
  enhanceAll(root = document) {
    root.querySelectorAll('select:not([data-cs])').forEach(sel => this.enhance(sel));
  },

  enhance(sel) {
    sel.dataset.cs = '1';
    sel.style.display = 'none';

    const wrap = document.createElement('div');
    wrap.className = 'cselect';

    const trigger = document.createElement('button');
    trigger.type = 'button';
    trigger.className = 'cselect-trigger';
    trigger.innerHTML = '<span class="cselect-value"></span>'
      + '<svg class="cselect-chevron" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M6 9l6 6 6-6"/></svg>';

    const menu = document.createElement('div');
    menu.className = 'cselect-menu';
    menu.setAttribute('role', 'listbox');

    Array.from(sel.options).forEach(opt => {
      const item = document.createElement('div');
      item.className = 'cselect-option';
      item.setAttribute('role', 'option');
      item.dataset.value = opt.value;
      item.textContent = opt.textContent;
      item.addEventListener('click', () => {
        sel.value = opt.value;
        this._sync(sel);
        sel.dispatchEvent(new Event('change', { bubbles: true }));
        this._close(wrap);
      });
      menu.appendChild(item);
    });

    trigger.addEventListener('click', e => {
      e.stopPropagation();
      const isOpen = wrap.classList.contains('open');
      document.querySelectorAll('.cselect.open').forEach(w => w.classList.remove('open'));
      if (!isOpen) wrap.classList.add('open');
    });

    wrap.appendChild(trigger);
    wrap.appendChild(menu);
    sel.parentNode.insertBefore(wrap, sel.nextSibling);
    sel._csWrap = wrap;
    this._sync(sel);
  },

  /** همگام‌سازی نمایش سفارشی با مقدار فعلی <select> (پس از تغییر برنامه‌ای) */
  refresh(sel) {
    if (sel && sel._csWrap) this._sync(sel);
  },

  _sync(sel) {
    const wrap = sel._csWrap;
    if (!wrap) return;
    const label = sel.options[sel.selectedIndex] ? sel.options[sel.selectedIndex].textContent : '';
    wrap.querySelector('.cselect-value').textContent = label;
    wrap.querySelectorAll('.cselect-option').forEach(o =>
      o.classList.toggle('selected', o.dataset.value === sel.value));
  },

  _close(wrap) { wrap.classList.remove('open'); },
};
// بستن با کلیک بیرون
document.addEventListener('click', () =>
  document.querySelectorAll('.cselect.open').forEach(w => w.classList.remove('open')));

document.addEventListener('DOMContentLoaded', () => {
  // مقداردهی‌های مخصوص داشبورد ابزارها فقط وقتی عناصرش موجود است
  if (document.getElementById('iconGrid'))       IconPicker.build();
  if (document.getElementById('decoGrid'))       DecoPicker.build();
  if (document.getElementById('iconAssetGrid'))  IconEditor.buildGrid();
  if (document.getElementById('decoAssetGrid'))  DecoEditor.buildGrid();
  if (document.getElementById('toolList'))        ToolsView.init();
  Theme.init();
  CustomSelect.enhanceAll();   // ارتقای همه <select>های بومی به dropdown هماهنگ با تم

  // dirty tracking روی input های فرم
  ['f-title', 'f-desc', 'f-path', 'f-badge'].forEach(id => {
    document.getElementById(id)?.addEventListener('input', () => ToolForm._markDirty());
  });

  // جستجوی کاربران (صفحه مدیریت کاربران)
  if (typeof UserSearch !== 'undefined' && document.getElementById('userSearchInput')) {
    UserSearch.init();
  }

  // بستن مودال با کلیک روی overlay
  document.querySelectorAll('.modal-overlay').forEach(o => {
    o.addEventListener('click', e => {
      if (e.target !== o) return;
      if (o.id === 'confirmModal') {
        Confirm.close();
      } else if (o.id === 'formModal') {
        ToolForm.requestClose();
      } else {
        Modal.close(o.id);
      }
    });
  });

  // بستن مودال با Escape — فقط روی مودال بالایی (آخرین مودال باز)
  document.addEventListener('keydown', e => {
    if (e.key !== 'Escape') return;
    const open = document.querySelectorAll('.modal-overlay.open');
    if (!open.length) return;
    const top = open[open.length - 1];
    if (top.id === 'confirmModal')   { Confirm.close(); }
    else if (top.id === 'formModal') { ToolForm.requestClose(); }
    else                             { Modal.close(top.id); }
  });
});