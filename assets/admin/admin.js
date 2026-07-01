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
// مارک‌آپ تغییر نمی‌کند؛ پیام داخل .field تزریق می‌شود. اگر فیلد در .field
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
    if (warn) { warnEl.innerHTML = `<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="width:15px;height:15px;vertical-align:-3px;margin-left:5px;"><path d="M10.29 3.86 1.82 18a2 2 0 0 0 1.71 3h16.94a2 2 0 0 0 1.71-3L13.71 3.86a2 2 0 0 0-3.42 0z"/><line x1="12" y1="9" x2="12" y2="13"/><line x1="12" y1="17" x2="12.01" y2="17"/></svg>${warn}`; warnEl.classList.add('show'); }
    else       { warnEl.innerHTML = ''; warnEl.classList.remove('show'); }
    const iconEl = document.getElementById('confirmIcon');
    iconEl.className = `confirm-icon ${type}`;
    iconEl.innerHTML = icon || this._defaultIcon;
    const btn = document.getElementById('confirmActionBtn');
    btn.className   = `btn btn-sm ${type === 'warning' ? 'btn-warning' : type === 'save' ? 'btn-primary' : 'btn-danger'}`;
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
    if (!document.getElementById('f-title')) return;   // فرم ابزار حذف شده (به داشبورد منتقل شد)
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
  _dirty: false,
  _wiredDirty: false,
  _wireDirty() {
    if (this._wiredDirty) return;
    const m = document.getElementById('userModal');
    if (!m) return;
    m.addEventListener('input', () => { this._dirty = true; });
    m.addEventListener('change', () => { this._dirty = true; });
    this._wiredDirty = true;
  },
  _isAdd: false,
  close(force) {
    if (!force && this._dirty) {
      Confirm.show({
        title: 'تغییرات ذخیره نشده',
        heading: 'تغییرات ذخیره نشده دارید',
        body: 'آیا می‌خواهید تغییرات را ذخیره کنید؟',
        type: 'save',
        icon: '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"><path d="M10.29 3.86 1.82 18a2 2 0 0 0 1.71 3h16.94a2 2 0 0 0 1.71-3L13.71 3.86a2 2 0 0 0-3.42 0z"/><line x1="12" y1="9" x2="12" y2="13"/><line x1="12" y1="17" x2="12.01" y2="17"/></svg>',
        btnLabel: 'ذخیره تغییرات',
        onConfirm: () => { Confirm.close(); this.save(); },
      });
      return;
    }
    this._dirty = false;
    Modal.close('userModal');
  },
  openAdd() {
    this._wireDirty();
    this._isAdd = true;
    document.getElementById('userModalTitle').textContent = 'افزودن کاربر';
    document.getElementById('userModalSaveLabel').textContent = 'افزودن کاربر';
    document.getElementById('editPassLabel').innerHTML = 'رمز عبور <span class="req">*</span>';
    document.getElementById('editUserId').value = '';
    document.getElementById('editFullName').value = '';
    document.getElementById('editUsername').value = '';
    document.getElementById('editPhone').value = '';
    const editPass = document.getElementById('editUserPassword');
    editPass.value = ''; editPass.type = 'password';
    checkStrength('', 'editPassStrength', 'editPassStrengthLabel');
    const roleSel = document.getElementById('editUserRole');
    if (roleSel) { roleSel.value = 'user'; CustomSelect.refresh(roleSel); }
    Modal.open('userModal');
    this._dirty = false;
    setTimeout(() => document.getElementById('editFullName').focus(), 100);
  },
  openEdit(id, fullName, username, phone, role) {
    this._wireDirty();
    this._isAdd = false;
    document.getElementById('userModalTitle').textContent = 'ویرایش کاربر';
    document.getElementById('userModalSaveLabel').textContent = 'ذخیره';
    document.getElementById('editPassLabel').innerHTML = 'رمز عبور جدید <span style="color:var(--text-3);font-weight:400;">(خالی = بدون تغییر)</span>';
    document.getElementById('editUserId').value   = id;
    document.getElementById('editFullName').value = fullName;
    document.getElementById('editUsername').value = username;
    document.getElementById('editPhone').value    = phone;
    const editPass = document.getElementById('editUserPassword');
    editPass.value = ''; editPass.type = 'password';
    checkStrength('', 'editPassStrength', 'editPassStrengthLabel');
    const roleSel = document.getElementById('editUserRole');
    if (roleSel) { roleSel.value = (role === 'admin') ? 'admin' : 'user'; CustomSelect.refresh(roleSel); }
    Modal.open('userModal');
    this._dirty = false;
    setTimeout(() => document.getElementById('editFullName').focus(), 100);
  },

  async save() {
    const idVal    = document.getElementById('editUserId').value.trim();
    const isAdd    = !idVal;
    const fullName = document.getElementById('editFullName').value.trim();
    const username = document.getElementById('editUsername').value.trim();
    const phone    = document.getElementById('editPhone').value.trim();
    const password = document.getElementById('editUserPassword').value;
    const role     = document.getElementById('editUserRole')?.value || 'user';
    if (!fullName) return FieldErr.set('editFullName', 'نام و نام خانوادگی الزامی است');
    if (isAdd) {
      if (!username) return FieldErr.set('editUsername', 'نام‌کاربری الزامی است');
      if (!/^[a-zA-Z][a-zA-Z0-9_]{2,59}$/.test(username)) return FieldErr.set('editUsername', 'نام‌کاربری باید با حرف انگلیسی شروع شود و فقط شامل حروف/اعداد/underscore باشد');
    }
    if (phone && !/^09\d{9}$/.test(phone)) return FieldErr.set('editPhone', 'شماره موبایل باید ۱۱ رقم و با ۰۹ شروع شود');
    if (isAdd && !password) return FieldErr.set('editUserPassword', 'رمز عبور الزامی است');
    if (password && !pwMeetsPolicy(password)) return FieldErr.set('editUserPassword', PW_POLICY_MSG);

    const action = isAdd ? 'add_user' : 'edit_user';
    const body   = isAdd
      ? { full_name: fullName, username, phone, password, role }
      : { id: parseInt(idVal), full_name: fullName, phone, password, role };
    const res = await Api.call(action, body);
    if (res.ok) {
      this.close(true);
      Toast.show(isAdd ? 'کاربر اضافه شد' : 'کاربر ویرایش شد');
      setTimeout(() => location.reload(), 900);
    } else {
      Toast.show(res.msg || 'خطا', 'error');
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
  _dirty: false,
  _wiredDirty: false,
  _wireDirty() {
    if (this._wiredDirty) return;
    const m = document.getElementById('accessModal');
    if (!m) return;
    m.addEventListener('input', () => { this._dirty = true; });
    m.addEventListener('change', () => { this._dirty = true; });
    this._wiredDirty = true;
  },
  close(force) {
    if (!force && this._dirty) {
      Confirm.show({
        title: 'تغییرات ذخیره نشده',
        heading: 'تغییرات ذخیره نشده دارید',
        body: 'آیا می‌خواهید تغییرات را ذخیره کنید؟',
        type: 'save',
        icon: '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"><path d="M10.29 3.86 1.82 18a2 2 0 0 0 1.71 3h16.94a2 2 0 0 0 1.71-3L13.71 3.86a2 2 0 0 0-3.42 0z"/><line x1="12" y1="9" x2="12" y2="13"/><line x1="12" y1="17" x2="12.01" y2="17"/></svg>',
        btnLabel: 'ذخیره تغییرات',
        onConfirm: () => { Confirm.close(); this.save(); },
      });
      return;
    }
    this._dirty = false;
    Modal.close('accessModal');
  },

  async open(userId, userName) {
    this._wireDirty();
    this._currentUserId = userId;
    this._currentBadges = [];

    document.getElementById('accessModalTitle').textContent = `تنظیم دسترسی — ${userName}`;
    document.getElementById('accessUserId').value = userId;
    document.getElementById('accessBadgesGrid').innerHTML = '<div style="color:var(--text-3);font-size:13px;">در حال بارگذاری...</div>';
    document.getElementById('accessToolsList').innerHTML  = '<div style="color:var(--text-3);font-size:13px;">در حال بارگذاری...</div>';

    Modal.open('accessModal');
    this._dirty = false;

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
      if (isPublic)    statusBadge = '<span class="access-status-badge public"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"><rect x="3" y="11" width="18" height="11" rx="2"/><path d="M7 11V7a5 5 0 0 1 9.9-1"/></svg>عمومی</span>';
      else if (inBadge) statusBadge = '<span class="access-status-badge from-badge"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="3" stroke-linecap="round" stroke-linejoin="round"><polyline points="20 6 9 17 4 12"/></svg>از دسته</span>';

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
          statusBadge.innerHTML   = '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="3" stroke-linecap="round" stroke-linejoin="round"><polyline points="20 6 9 17 4 12"/></svg>از دسته';
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
      this.close(true);
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
    if (!grid) return;   // گرید فقط در مودال ابزار بود که حذف شده — مدیریت ابزار به داشبورد منتقل شد
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
    if (!grid) return;   // مدیریت ابزار به داشبورد منتقل شد — این گرید دیگر در پنل نیست
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
function closeConfirm()             { Confirm.close(); }
function runConfirm()               { Confirm.run(); }
function closeModal(id)             { Modal.close(id); }
function saveIconEdit()             { IconEditor.save(); }
function deleteIcon()               { IconEditor.delete(); }
function addNewIcon()               { IconEditor.add(); }
function saveDecoEdit()             { DecoEditor.save(); }
function deleteDeco()               { DecoEditor.delete(); }
function addNewDeco()               { DecoEditor.add(); }
function refreshDecoPreview()       { DecoEditor.refreshPreview(); }
function openEditUserModal(id,n,u,p,r){ UserManager.openEdit(id, n, u, p, r); }
function toggleUser(id, btn)        { UserManager.toggle(id, btn); }
function openDeleteUserModal(id, n) { UserManager.openDelete(id, n); }
function openAccessModal(id, name)  { AccessManager.open(id, name); }
function saveAccess()               { AccessManager.save(); }

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
    const last = r.last_attempt ? new Date(r.last_attempt * 1000).toLocaleString('en-GB') : '—';
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

// ═══════════════════════════════════════════════════════════
// SessionsManager — مدیریت نشست‌های فعال کاربران
// روی داشبورد (پنل کلی همه نشست‌ها) و صفحه کاربران (مودال هر کاربر).
// ═══════════════════════════════════════════════════════════
const SessionsManager = {
  _loaded: false,
  _curUser: 0,
  _curUserName: '',

  // ── پنل داشبورد (همه نشست‌ها) ──
  toggleBox() {
    const box = document.getElementById('sessionsBox');
    if (!box) return;
    box.classList.toggle('open');
    if (box.classList.contains('open') && !this._loaded) this.loadPanel();
  },

  async loadPanel() {
    const box = document.getElementById('sessionsPanel');
    if (!box) return;
    box.innerHTML = '<div class="blocks-loading">در حال بارگذاری…</div>';
    const res = await Api.call('list_sessions', {});
    if (!res.ok) { box.innerHTML = '<div class="blocks-empty">خطا در دریافت نشست‌ها</div>'; return; }
    this._loaded = true;
    const list = res.sessions || [];
    const badge = document.getElementById('sessionsCountBadge');
    if (badge) badge.textContent = list.length;
    box.innerHTML = list.length
      ? list.map(s => this._row(s, true)).join('')
      : '<div class="blocks-empty">هیچ نشست فعالی وجود ندارد.</div>';
  },

  // ── مودال یک کاربر ──
  openUser(uid, name) {
    this._curUser = uid;
    this._curUserName = name || '';
    const t = document.getElementById('sessionsUserTitle');
    if (t) t.textContent = 'نشست‌های فعال — ' + (name || '');
    Modal.open('sessionsUserModal');
    this.loadUser();
  },

  async loadUser() {
    const box = document.getElementById('sessionsUserList');
    if (!box) return;
    box.innerHTML = '<div class="blocks-loading">در حال بارگذاری…</div>';
    const res = await Api.call('list_sessions', { user_id: this._curUser });
    if (!res.ok) { box.innerHTML = '<div class="blocks-empty">خطا در دریافت نشست‌ها</div>'; return; }
    const list = res.sessions || [];
    box.innerHTML = list.length
      ? list.map(s => this._row(s, false)).join('')
      : '<div class="blocks-empty">این کاربر نشست فعالی ندارد.</div>';
  },

  _row(s, showName) {
    const when  = s.last_seen ? new Date(s.last_seen * 1000).toLocaleString('en-GB') : '—';
    const ip    = esc(s.ip || '—');
    const agent = esc(s.agent || 'نامشخص');
    let remaining = '';
    if (s.expires_at) {
      const diff = s.expires_at - Math.floor(Date.now() / 1000);
      if (diff > 0) {
        const h = Math.floor(diff / 3600);
        const m = Math.floor((diff % 3600) / 60);
        remaining = h > 0 ? `${h} ساعت و ${m} دقیقه` : `${m} دقیقه`;
      } else { remaining = 'منقضی‌شده'; }
    }
    const title = showName
      ? `${esc(s.name)}${s.is_admin ? ' <span class="blk-badge blk-watch">مدیر</span>' : ''}`
      : agent;
    const remStr = remaining ? ` · باقیمانده: ${remaining}` : '';
    const meta  = showName
      ? `${agent} · <span dir="ltr">${ip}</span> · آخرین فعالیت: ${when}${remStr}`
      : `<span dir="ltr">${ip}</span> · آخرین فعالیت: ${when}${remStr}`;
    const current = s.is_current ? '<span class="blk-badge blk-blocked">این دستگاه</span>' : '';
    const id = esc(s.id);
    return `
      <div class="blk-row">
        <div class="blk-info">
          <div class="blk-ip">${title}</div>
          <div class="blk-meta">${meta}</div>
        </div>
        <div class="blk-side">
          ${current}
          <button class="btn btn-danger btn-sm" onclick="SessionsManager.terminate('${id}', ${s.is_current ? 'true' : 'false'})">پایان</button>
        </div>
      </div>`;
  },

  // ── تنظیم مدت فعال‌بودن نشست (ساعت) ──
  async saveTtl() {
    const el = document.getElementById('sessTtlInput');
    const v  = parseInt(String(el ? el.value : '').replace(/[^\d]/g, ''), 10);
    if (!v || v < 1 || v > 720) { Toast.show('عددی بین ۱ تا ۷۲۰ وارد کنید', 'error'); return; }
    const res = await Api.call('save_session_ttl', { session_ttl_hours: v });
    if (res.ok) { if (el) el.value = res.hours; Toast.show(res.msg || 'ذخیره شد'); }
    else        { Toast.show(res.msg || 'خطا در ذخیره', 'error'); }
  },

  // ── عملیات ──
  terminate(id, isCurrent) {
    Confirm.show({
      title:    'پایان نشست',
      heading:  isCurrent ? 'پایان نشست همین دستگاه؟' : 'این نشست پایان یابد؟',
      body:     isCurrent
        ? 'این نشست <b>همین مرورگر</b> است؛ با پایان آن از پنل خارج می‌شوید.'
        : 'دستگاه مربوط به این نشست بلافاصله از حساب خارج می‌شود.',
      type:     'warning',
      btnLabel: 'پایان نشست',
      icon:     '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"><path d="M18.36 6.64A9 9 0 1 1 5.64 17.36"/><line x1="12" y1="2" x2="12" y2="12"/></svg>',
      onConfirm: async () => {
        Confirm.close();
        const res = await Api.call('terminate_session', { session_id: id });
        if (!res.ok) { Toast.show(res.msg || 'خطا', 'error'); return; }
        Toast.show('نشست پایان یافت');
        if (isCurrent) { location.href = '/'; return; }
        const um = document.getElementById('sessionsUserModal');
        if (um && um.classList.contains('open')) this.loadUser(); else this.loadPanel();
      },
    });
  },

  terminateOthers() {
    Confirm.show({
      title:    'پایان نشست‌های دیگر',
      heading:  'همه نشست‌های دیگر پایان یابد؟',
      body:     'همه نشست‌های فعال به‌جز نشست همین مرورگر بسته می‌شوند (همه کاربران از همه دستگاه‌ها خارج می‌شوند).',
      type:     'warning',
      btnLabel: 'پایان بقیه',
      icon:     '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"><path d="M18.36 6.64A9 9 0 1 1 5.64 17.36"/><line x1="12" y1="2" x2="12" y2="12"/></svg>',
      onConfirm: async () => {
        Confirm.close();
        const res = await Api.call('terminate_other_sessions', {});
        if (!res.ok) { Toast.show(res.msg || 'خطا', 'error'); return; }
        Toast.show(res.msg || 'انجام شد');
        this.loadPanel();
      },
    });
  },

  terminateUser() {
    if (!this._curUser) return;
    Confirm.show({
      title:    'خروج از همه دستگاه‌ها',
      heading:  `همه نشست‌های «${esc(this._curUserName)}» پایان یابد؟`,
      body:     'این کاربر از همه دستگاه‌ها خارج می‌شود و برای ادامه باید دوباره وارد شود.',
      type:     'warning',
      btnLabel: 'خروج اجباری',
      icon:     '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"><path d="M18.36 6.64A9 9 0 1 1 5.64 17.36"/><line x1="12" y1="2" x2="12" y2="12"/></svg>',
      onConfirm: async () => {
        Confirm.close();
        const res = await Api.call('terminate_user_sessions', { user_id: this._curUser });
        if (!res.ok) { Toast.show(res.msg || 'خطا', 'error'); return; }
        Toast.show(res.msg || 'انجام شد');
        this.loadUser();
      },
    });
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
  // مدیریت آیکون/انیمیشن (مدیریت ابزارها به داشبورد اصلی منتقل شده است)
  if (document.getElementById('iconAssetGrid'))  IconEditor.buildGrid();
  if (document.getElementById('decoAssetGrid'))  DecoEditor.buildGrid();
  Theme.init();
  CustomSelect.enhanceAll();   // ارتقای همه <select>های بومی به dropdown هماهنگ با تم

  // جستجوی کاربران (صفحه مدیریت کاربران)
  if (typeof UserSearch !== 'undefined' && document.getElementById('userSearchInput')) {
    UserSearch.init();
  }

  // بستن مودال با کلیک روی overlay
  document.querySelectorAll('.modal-overlay').forEach(o => {
    o.addEventListener('click', e => {
      if (e.target !== o) return;
      if (o.id === 'confirmModal') { Confirm.close(); }
      else                         { Modal.close(o.id); }
    });
  });

  // بستن مودال با Escape — فقط روی مودال بالایی (آخرین مودال باز)
  document.addEventListener('keydown', e => {
    if (e.key !== 'Escape') return;
    const open = document.querySelectorAll('.modal-overlay.open');
    if (!open.length) return;
    const top = open[open.length - 1];
    if (top.id === 'confirmModal') { Confirm.close(); }
    else                           { Modal.close(top.id); }
  });
});

// ═══════════════════════════════════════════════════════════
// افکت ripple (موج کلیک) روی دکمه‌های هدر و دکمه‌های عملیات — مشترک با theme.js
// ═══════════════════════════════════════════════════════════
(function () {
  const SEL = '.hdr-btn, .btn, .btn-icon, .cselect-option, .pg-btn,'
    + ' .access-tool-label, .deco-opt, .section-box-head, .modal-close';
  document.addEventListener('pointerdown', function (e) {
    const btn = e.target.closest(SEL);
    if (!btn || btn.disabled || btn.getAttribute('aria-disabled') === 'true') return;
    const rect = btn.getBoundingClientRect();
    const size = Math.max(rect.width, rect.height);
    const r = document.createElement('span');
    r.className = 'ripple';
    r.style.width = r.style.height = size + 'px';
    r.style.left = (e.clientX - rect.left - size / 2) + 'px';
    r.style.top  = (e.clientY - rect.top  - size / 2) + 'px';
    btn.appendChild(r);
    r.addEventListener('animationend', () => r.remove());
  });
  // ناوبری لینک‌های هدر را ~160ms نگه می‌داریم تا ریپل دیده شود (prerender فوری است)
  document.addEventListener('click', function (e) {
    const a = e.target.closest(SEL);
    if (!a || a.tagName !== 'A') return;
    const href = a.getAttribute('href');
    if (!href || href.charAt(0) === '#' || a.target === '_blank') return;
    if (e.defaultPrevented || e.metaKey || e.ctrlKey || e.shiftKey || e.button) return;
    e.preventDefault();
    setTimeout(function () { window.location.href = href; }, 160);
  });
})();

// هدر چسبان هنگام اسکرول (مشترک با theme.js): .is-stuck با اسکرول به پایین
(function () {
  const header = document.querySelector('.app-header');
  if (!header) return;
  let ticking = false;
  function update() {
    header.classList.toggle('is-stuck', window.scrollY > 4);
    ticking = false;
  }
  window.addEventListener('scroll', function () {
    if (!ticking) { requestAnimationFrame(update); ticking = true; }
  }, { passive: true });
  update();
})();