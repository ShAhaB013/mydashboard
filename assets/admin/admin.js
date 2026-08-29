'use strict';

// ═══════════════════════════════════════════════════════════
// State
// ═══════════════════════════════════════════════════════════
const State = {
  editId:      0,    // 0 = add mode, >0 = editing the tool with this id
  deleteId:    0,
  selIcon:     'star',
  selDeco:     'generic',
  selColor:    '',
  selIconKey:  null,
  selDecoKey:  null,
  iconAddOpen: false,
  decoAddOpen: false,
};

// ═══════════════════════════════════════════════════════════
// Skeleton — loading placeholders (same structure as the user panel in style.css)
// ═══════════════════════════════════════════════════════════
const SKELETON_TABLE_ROW =
  '<div class="sk-table-row" aria-hidden="true">'
  + '<div class="sk sk-avatar"></div>'
  + '<div class="sk-lines"><div class="sk sk-line sk-line--title"></div><div class="sk sk-line sk-line--sub"></div></div>'
  + '</div>';

const SKELETON_GRID_TILE = '<div class="sk sk-grid-tile" aria-hidden="true"></div>';
const SKELETON_BADGE_CHIP = '<div class="sk sk-badge-chip" aria-hidden="true"></div>';

// escape for safely inserting user text into string-based HTML
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
// Toast — colored icon + title + description + close button (same structure as dashboard/login)
// Toast.show(message, type, title?) — type: success | error | warning | info
// Only one toast is ever shown at a time; a new toast replaces the previous
// one instead of stacking on top of it (this page has a single fixed #toast holder).
// ═══════════════════════════════════════════════════════════
const Toast = {
  _timer: null,
  _ICON: {
    success: '<path d="M22 11.08V12a10 10 0 1 1-5.93-9.14"/><path d="M22 4L12 14.01l-3-3"/>',
    error:   '<circle cx="12" cy="12" r="10"/><line x1="12" y1="8" x2="12" y2="12"/><line x1="12" y1="16" x2="12.01" y2="16"/>',
    warning: '<path d="M10.29 3.86L1.82 18a2 2 0 0 0 1.71 3h16.94a2 2 0 0 0 1.71-3L13.71 3.86a2 2 0 0 0-3.42 0z"/><line x1="12" y1="9" x2="12" y2="13"/><line x1="12" y1="17" x2="12.01" y2="17"/>',
    info:    '<circle cx="12" cy="12" r="10"/><line x1="12" y1="16" x2="12" y2="12"/><line x1="12" y1="8" x2="12.01" y2="8"/>',
  },
  _TITLE: { success: 'موفقیت', error: 'خطا', warning: 'هشدار', info: 'اطلاع‌رسانی' },
  _DURATION: 4500,
  show(msg, type, title) {
    type = (type && this._ICON[type]) ? type : 'success';
    const el = document.getElementById('toast');
    if (!el) return;
    clearTimeout(this._timer);
    el.className = `toast ${type}`;
    el.innerHTML =
      `<span class="toast-icon"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round">${this._ICON[type]}</svg></span>`
      + '<div class="toast-body"><strong class="toast-title"></strong><span class="toast-text"></span></div>'
      + '<button type="button" class="toast-close" aria-label="بستن"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round"><line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/></svg></button>'
      + `<span class="toast-progress" style="animation-duration:${this._DURATION}ms"></span>`;
    el.querySelector('.toast-title').textContent = title || this._TITLE[type];
    el.querySelector('.toast-text').textContent  = msg;
    el.querySelector('.toast-close').addEventListener('click', () => { clearTimeout(this._timer); el.classList.remove('show'); });
    requestAnimationFrame(() => el.classList.add('show'));
    this._timer = setTimeout(() => el.classList.remove('show'), this._DURATION);
  },
};

// ═══════════════════════════════════════════════════════════
// FieldErr — inline error for key forms (red border + message below the field)
// The markup itself is not changed; the message is injected inside .field. If the
// field isn't wrapped in .field, it falls back to Toast. Always returns false so
// callers can `return FieldErr.set(...)` directly from validation checks.
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
// Counter — live character counter shown inside a .field-input-wrap (a flex sibling
// next to the input, never an overlay on top of it — see feedback_char_counter_pattern).
// ═══════════════════════════════════════════════════════════
const Counter = {
  update(inputId, max) {
    const input = document.getElementById(inputId);
    const cEl   = document.getElementById(inputId + 'Count');
    const wrap  = document.getElementById(inputId + 'Counter');
    if (!input) return;
    const len = input.value.length;
    if (cEl)  cEl.textContent = len.toLocaleString('en-US');
    if (wrap) wrap.classList.toggle('over', len >= max);
  },
};

// ═══════════════════════════════════════════════════════════
// Modal
// ═══════════════════════════════════════════════════════════
const Modal = {
  open(id)  { document.getElementById(id).classList.add('open');    document.body.style.overflow = 'hidden'; },
  close(id) {
    document.getElementById(id).classList.remove('open');
    // if another modal is still open (e.g. a confirm on top of a form), keep the scroll lock
    if (!document.querySelector('.modal-overlay.open')) document.body.style.overflow = '';
  },
};

// ═══════════════════════════════════════════════════════════
// Confirm
// ═══════════════════════════════════════════════════════════
const Confirm = {
  _callback: null,
  _cancelCallback: null,
  _defaultIcon:
    '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round">' +
      '<polyline points="3 6 5 6 21 6"/>' +
      '<path d="M19 6l-1 14a2 2 0 01-2 2H8a2 2 0 01-2-2L5 6"/>' +
      '<path d="M10 11v6M14 11v6M9 6V4a1 1 0 011-1h4a1 1 0 011 1v2"/>' +
    '</svg>',
  show({ title, heading, body, warn = null, type = 'danger', btnLabel = 'حذف', btnClass = null, cancelLabel = 'انصراف', icon = null, onConfirm, onCancel = null }) {
    this._callback = onConfirm;
    this._cancelCallback = onCancel;
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
    btn.className   = `btn btn-sm ${btnClass || (type === 'warning' ? 'btn-warning' : type === 'save' ? 'btn-primary' : 'btn-danger')}`;
    btn.textContent = btnLabel;
    btn.disabled    = false;
    const cancelBtn = document.getElementById('confirmCancelBtn');
    if (cancelBtn) cancelBtn.textContent = cancelLabel;
    Modal.open('confirmModal');
  },
  close() { Modal.close('confirmModal'); this._callback = null; this._cancelCallback = null; },
  cancel() {
    const cb = this._cancelCallback;
    this.close();
    if (cb) cb();
  },
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
    if (!document.getElementById('f-title')) return;   // tool form removed (moved to the dashboard)
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
    document.getElementById('editFullName')?.addEventListener('input', () => Counter.update('editFullName', 60));
    document.getElementById('editUsername')?.addEventListener('input', () => Counter.update('editUsername', 60));
    document.getElementById('editPhone')?.addEventListener('input', () => Counter.update('editPhone', 11));
    document.getElementById('editEmail')?.addEventListener('input', () => Counter.update('editEmail', 190));
    this._wiredDirty = true;
  },
  _isAdd: false,

  // ── list (server-side, AJAX — same structure as notification management) ─────
  _users:       [],
  _page:        1,
  _perPage:     10,
  _search:      '',
  _fRole:       '',
  _fStatus:     '',
  _total:       0,
  _pageCount:   1,
  _loading:     false,
  _searchTimer: null,

  _renderSkeleton(n = 5) {
    const list = document.getElementById('userList');
    if (!list) return;
    list.innerHTML = SKELETON_TABLE_ROW.repeat(n);
    Skeleton.mark(list);
  },

  async load(page = this._page) {
    if (this._loading) return;
    this._loading = true;
    this._page    = Math.max(1, page);
    this._renderSkeleton();

    const res = await Api.call('list_users', {
      page:      this._page,
      per_page:  this._perPage,
      search:    this._search,
      role:      this._fRole,
      status:    this._fStatus,
    });

    this._loading = false;
    if (!res.ok) { Toast.show(res.msg || 'خطا در بارگذاری', 'error'); return; }

    this._users = res.users || [];
    const pg = res.pagination || {};
    this._total     = pg.total      ?? this._users.length;
    this._pageCount = pg.page_count ?? 1;
    this._page      = pg.page       ?? this._page;

    // if the current page ended up empty (e.g. after deleting the page's last item), go back a page
    if (!this._users.length && this._page > 1) {
      return this.load(this._page - 1);
    }

    await Skeleton.wait(document.getElementById('userList'));
    this._render();
  },

  _render() {
    const list  = document.getElementById('userList');
    const badge = document.getElementById('userCountBadge');
    if (badge) badge.textContent = this._total;

    if (!this._users.length) {
      const emptyMsg = this._search || this._fRole || this._fStatus
        ? 'کاربری با این مشخصات یافت نشد'
        : 'هنوز هیچ کاربری ثبت نشده';
      list.innerHTML = `
        <div class="user-empty">
          <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5">
            <path d="M17 21v-2a4 4 0 00-4-4H5a4 4 0 00-4 4v2"/>
            <circle cx="9" cy="7" r="4"/>
          </svg>
          <p>${esc(emptyMsg)}</p>
        </div>`;
      this._renderPagination();
      return;
    }

    list.innerHTML = '';
    const frag = document.createDocumentFragment();
    this._users.forEach(u => frag.appendChild(this._makeRow(u)));
    list.appendChild(frag);

    this._renderPagination();
  },

  _makeRow(u) {
    const row = document.createElement('div');
    row.className = 'user-row';
    row.dataset.uid = u.id;

    const name = u.display_name || u.username || '';

    const adminBadge = u.role === 'admin'
      ? `<span class="user-admin-badge" title="مدیر سیستم">
           <svg viewBox="0 0 24 24" fill="currentColor" aria-hidden="true"><path d="M12 1.5l2.6 1.9 3.2-.3 1 3 2.8 1.6-1 3.1 1 3.1-2.8 1.6-1 3-3.2-.3L12 21l-2.6-1.9-3.2.3-1-3-2.8-1.6 1-3.1-1-3.1L5.2 6l1-3 3.2.3z"/><path d="M9 12.2l2 2 4-4.4" fill="none" stroke="#fff" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round"/></svg>
         </span>`
      : '';
    const statusPill = `<span class="user-status-pill ${u.is_active ? 'active' : 'inactive'}">${u.is_active ? 'فعال' : 'غیرفعال'}</span>`;
    const sessDot = u.session_count ? `<span class="sess-count-dot">${(u.session_count).toLocaleString('en-US')}</span>` : '';

    row.innerHTML = `
      <div class="user-row-avatar">
        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M20 21v-2a4 4 0 0 0-4-4H8a4 4 0 0 0-4 4v2"/><circle cx="12" cy="7" r="4"/></svg>
      </div>
      <div class="user-row-info">
        <h3><span class="user-row-name">${esc(name)}</span>${adminBadge}</h3>
        <p style="direction:ltr;text-align:right;">${esc(u.email || u.phone || '—')}</p>
      </div>
      <div class="user-row-meta">
        ${statusPill}
      </div>
      <div class="user-row-actions">
        <button class="btn btn-secondary btn-icon btn-sm" title="تنظیم دسترسی"
          data-act="accessOpen" data-id="${u.id}" data-name="${esc(name)}" data-role="${esc(u.role || 'user')}">
          <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
            <circle cx="12" cy="12" r="3"/>
            <path d="M19.4 15a1.65 1.65 0 00.33 1.82l.06.06a2 2 0 010 2.83 2 2 0 01-2.83 0l-.06-.06a1.65 1.65 0 00-1.82-.33 1.65 1.65 0 00-1 1.51V21a2 2 0 01-4 0v-.09A1.65 1.65 0 009 19.4a1.65 1.65 0 00-1.82.33l-.06.06a2 2 0 01-2.83-2.83l.06-.06A1.65 1.65 0 004.68 15a1.65 1.65 0 00-1.51-1H3a2 2 0 010-4h.09A1.65 1.65 0 004.6 9a1.65 1.65 0 00-.33-1.82l-.06-.06a2 2 0 012.83-2.83l.06.06A1.65 1.65 0 009 4.68a1.65 1.65 0 001-1.51V3a2 2 0 014 0v.09a1.65 1.65 0 001 1.51 1.65 1.65 0 001.82-.33l.06-.06a2 2 0 012.83 2.83l-.06.06A1.65 1.65 0 0019.4 9a1.65 1.65 0 001.51 1H21a2 2 0 010 4h-.09a1.65 1.65 0 00-1.51 1z"/>
          </svg>
        </button>
        <span class="sess-user-wrap">
          <button class="btn btn-secondary btn-icon btn-sm" title="نشست‌های فعال"
            data-act="sessOpenUser" data-id="${u.id}" data-name="${esc(name)}">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
              <rect x="2" y="3" width="20" height="14" rx="2"/><path d="M8 21h8M12 17v4"/>
            </svg>
          </button>
          ${sessDot}
        </span>
        <button class="btn btn-secondary btn-icon btn-sm" title="ویرایش"
          data-act="userEdit"
          data-id="${u.id}"
          data-name="${esc(name)}"
          data-username="${esc(u.username || '')}"
          data-phone="${esc(u.phone || '')}"
          data-email="${esc(u.email || '')}"
          data-role="${esc(u.role || 'user')}"
          data-can-view-profile="${u.can_view_profile === false ? 0 : 1}"
          data-can-view-notifications="${u.can_view_notifications === false ? 0 : 1}">
          <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
            <path d="M11 4H4a2 2 0 00-2 2v14a2 2 0 002 2h14a2 2 0 002-2v-7"/>
            <path d="M18.5 2.5a2.121 2.121 0 013 3L12 15l-4 1 1-4 9.5-9.5z"/>
          </svg>
        </button>
        <button class="btn btn-secondary btn-icon btn-sm" title="ریست رمز و ارسال ایمیل"
          data-act="userResetSend" data-id="${u.id}" data-name="${esc(name)}" data-email="${esc(u.email || '')}">
          <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
            <rect x="2" y="4" width="20" height="16" rx="2"/>
            <path d="M22 6l-10 7L2 6"/>
          </svg>
        </button>
        <button class="btn btn-secondary btn-icon btn-sm toggle-user-btn ${!u.is_active ? 'is-inactive' : ''}"
          title="${u.is_active ? 'غیرفعال کردن' : 'فعال کردن'}"
          data-act="userToggle" data-id="${u.id}">
          <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
            <path d="M18.36 6.64A9 9 0 1 1 5.64 17.36"/>
            <line x1="12" y1="2" x2="12" y2="12"/>
          </svg>
        </button>
        <button class="btn btn-danger btn-icon btn-sm" title="حذف"
          data-act="userDelete" data-id="${u.id}" data-name="${esc(name)}">
          <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
            <polyline points="3 6 5 6 21 6"/>
            <path d="M19 6l-1 14a2 2 0 01-2 2H8a2 2 0 01-2-2L5 6"/>
            <path d="M10 11v6M14 11v6M9 6V4a1 1 0 011-1h4a1 1 0 011 1v2"/>
          </svg>
        </button>
      </div>`;
    return row;
  },

  // ── Pagination (server-side) ─────────────────────────────
  _renderPagination() {
    const pag  = document.getElementById('userPagination');
    const info = document.getElementById('userPageInfo');
    if (!pag || !info) return;
    const total     = this._total;
    const pageCount = this._pageCount;
    const cur       = this._page;
    const shown     = this._users.length;

    if (total === 0) {
      pag.classList.add('hidden');
      pag.innerHTML = '';
      info.textContent = '';
      return;
    }

    const start = (cur - 1) * this._perPage;
    info.textContent = `نمایش ${start + 1} تا ${start + shown} از ${total} کاربر`;

    if (pageCount <= 1) {
      pag.classList.add('hidden');
      pag.innerHTML = '';
      return;
    }

    const items = [];
    items.push(`<button class="pagination-btn" ${cur === 1 ? 'aria-disabled="true"' : ''} data-act="userGoToPage" data-page="${cur - 1}" aria-label="قبلی"><svg viewBox="0 0 24 24" width="14" height="14" fill="none" stroke="currentColor" stroke-width="2.5"><polyline points="9 18 15 12 9 6"/></svg></button>`);
    this._pageRange(cur, pageCount).forEach(p => {
      if (p === '...') {
        items.push(`<span class="pagination-ellipsis">…</span>`);
      } else {
        items.push(`<button class="pagination-btn ${p === cur ? 'active' : ''}" data-act="userGoToPage" data-page="${p}">${p}</button>`);
      }
    });
    items.push(`<button class="pagination-btn" ${cur === pageCount ? 'aria-disabled="true"' : ''} data-act="userGoToPage" data-page="${cur + 1}" aria-label="بعدی"><svg viewBox="0 0 24 24" width="14" height="14" fill="none" stroke="currentColor" stroke-width="2.5"><polyline points="15 18 9 12 15 6"/></svg></button>`);
    items.push(`
      <span class="pagination-goto">
        <label class="pagination-goto-label" for="userGotoInput">برو به صفحه</label>
        <span class="pagination-goto-field">
          <input type="number" id="userGotoInput" class="pagination-goto-input" min="1" max="${pageCount}"
            value="${cur}" aria-label="شماره صفحه" data-keydown="userGoToInputKey">
          <span class="pagination-goto-stepper">
            <button type="button" class="pagination-goto-spin" tabindex="-1" aria-label="افزایش شماره صفحه"
              data-act="userGoToStep" data-dir="1">
              <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="3"><polyline points="18 15 12 9 6 15"/></svg>
            </button>
            <button type="button" class="pagination-goto-spin" tabindex="-1" aria-label="کاهش شماره صفحه"
              data-act="userGoToStep" data-dir="-1">
              <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="3"><polyline points="6 9 12 15 18 9"/></svg>
            </button>
          </span>
        </span>
      </span>`);

    pag.innerHTML = items.join('');
    pag.classList.remove('hidden');
  },

  _pageRange(cur, count) {
    if (count <= 7) return Array.from({ length: count }, (_, i) => i + 1);
    const range = [1];
    const left  = Math.max(2, cur - 1);
    const right = Math.min(count - 1, cur + 1);
    if (left > 2) range.push('...');
    for (let i = left; i <= right; i++) range.push(i);
    if (right < count - 1) range.push('...');
    range.push(count);
    return range;
  },

  goToPage(p) {
    p = Math.min(Math.max(1, p), this._pageCount);
    if (p === this._page) return;
    this.load(p).then(() => window.scrollTo({ top: 0, behavior: 'smooth' }));
  },

  goToInputValue() {
    const inp = document.getElementById('userGotoInput');
    if (!inp) return;
    const n = parseInt(inp.value, 10);
    if (!Number.isFinite(n) || n < 1) return;
    this.goToPage(n);
  },

  goToInputKey(e) {
    if (e.key !== 'Enter') return;
    e.preventDefault();
    this.goToInputValue();
  },

  goToStep(dir) {
    const inp = document.getElementById('userGotoInput');
    if (!inp) return;
    const cur = parseInt(inp.value, 10);
    const base = Number.isFinite(cur) ? cur : this._page;
    const n = base + dir;
    inp.value = Math.min(Math.max(1, n), this._pageCount);
    this.goToInputValue();
  },

  // ── search (debounced) ───────────────────────────────
  onSearchInput(value) {
    const wrap = document.querySelector('.user-search');
    if (wrap) wrap.classList.toggle('has-value', value.trim() !== '');
    clearTimeout(this._searchTimer);
    this._searchTimer = setTimeout(() => {
      const v = value.trim();
      if (v === this._search) return;
      this._search = v;
      this.load(1);
    }, 350);
  },

  clearSearch() {
    const inp  = document.getElementById('userSearchInput');
    const wrap = document.querySelector('.user-search');
    if (inp)  inp.value = '';
    if (wrap) wrap.classList.remove('has-value');
    if (this._search === '') return;
    this._search = '';
    this.load(1);
  },

  // ── advanced search (role/status) ─────────────────────────
  toggleAdvanced() {
    const panel = document.getElementById('userAdvPanel');
    const btn   = document.getElementById('userAdvToggle');
    if (!panel) return;
    const open = panel.classList.toggle('open');
    if (btn) { btn.classList.toggle('active', open); btn.setAttribute('aria-expanded', open ? 'true' : 'false'); }
  },

  applyFilters() {
    this._fRole   = document.getElementById('user-f-role').value   || '';
    this._fStatus = document.getElementById('user-f-status').value || '';
    this._syncAdvBtn();
    this.load(1);
  },

  resetFilters() {
    document.getElementById('user-f-role').value   = '';
    document.getElementById('user-f-status').value = '';
    CustomSelect.refresh(document.getElementById('user-f-role'));
    CustomSelect.refresh(document.getElementById('user-f-status'));
    this._fRole = this._fStatus = '';
    this._syncAdvBtn();
    this.load(1);
  },

  _syncAdvBtn() {
    const has = !!(this._fRole || this._fStatus);
    const btn = document.getElementById('userAdvToggle');
    if (btn) btn.classList.toggle('has-filters', has);
  },

  // ── items per page (configurable + persisted) ─────────────
  setPerPage(val) {
    const allowed = [10, 20, 50];
    let n = parseInt(val, 10);
    if (!allowed.includes(n)) n = 10;
    this._perPage = n;
    try { localStorage.setItem('user_admin_perpage', String(n)); } catch (e) {}
    this.load(1);
  },
  _initPerPage() {
    let n = 10;
    try {
      const saved = parseInt(localStorage.getItem('user_admin_perpage'), 10);
      if ([10, 20, 50].includes(saved)) n = saved;
    } catch (e) {}
    this._perPage = n;
    const sel = document.getElementById('userPerPage');
    if (sel) { sel.value = String(n); CustomSelect.refresh(sel); }
  },

  close(force) {
    if (!force && this._dirty) {
      Confirm.show({
        title: this._isAdd ? 'افزودن کاربر' : 'ویرایش کاربر',
        heading: 'تغییرات ذخیره نشده دارید',
        body: 'آیا تغییرات ذخیره شوند؟',
        type: 'warning',
        icon: '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"><path d="M10.29 3.86 1.82 18a2 2 0 0 0 1.71 3h16.94a2 2 0 0 0 1.71-3L13.71 3.86a2 2 0 0 0-3.42 0z"/><line x1="12" y1="9" x2="12" y2="13"/><line x1="12" y1="17" x2="12.01" y2="17"/></svg>',
        cancelLabel: 'خیر',
        btnLabel: 'بله',
        btnClass: 'btn-primary',
        onConfirm: () => { Confirm.close(); this.save(); },
        onCancel: () => { this.close(true); },
      });
      return;
    }
    this._dirty = false;
    Modal.close('userModal');
  },
  /* hide the checklist when the modal opens + wire up focus/input once */
  _resetPassRules() {
    const panel = document.getElementById('editPassRules');
    if (panel) panel.hidden = true;
    const el = document.getElementById('editUserPassword');
    if (el && !el.__pwWired) {
      el.__pwWired = true;
      el.addEventListener('focus', () => updatePassRules(el.value));
      el.addEventListener('input', () => { updatePassRules(el.value); Counter.update('editUserPassword', 64); });
    }
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
    document.getElementById('editEmail').value = '';
    const editPass = document.getElementById('editUserPassword');
    editPass.value = ''; editPass.type = 'password';
    this._resetPassRules();
    const roleSel = document.getElementById('editUserRole');
    if (roleSel) { roleSel.value = 'user'; CustomSelect.refresh(roleSel); }
    document.getElementById('editCanViewProfile').checked = true;
    document.getElementById('editCanViewNotifications').checked = true;
    const sendCredsField = document.getElementById('sendCredsField');
    if (sendCredsField) sendCredsField.hidden = false;
    const sendCredsBox = document.getElementById('editSendCredentials');
    if (sendCredsBox) sendCredsBox.checked = false;
    Counter.update('editFullName', 60);
    Counter.update('editUsername', 60);
    Counter.update('editPhone', 11);
    Counter.update('editEmail', 190);
    Counter.update('editUserPassword', 64);
    Modal.open('userModal');
    this._dirty = false;
    setTimeout(() => document.getElementById('editFullName').focus(), 100);
  },
  openEdit(id, fullName, username, phone, email, role, canViewProfile = true, canViewNotifications = true) {
    this._wireDirty();
    this._isAdd = false;
    document.getElementById('userModalTitle').textContent = 'ویرایش کاربر';
    document.getElementById('userModalSaveLabel').textContent = 'ذخیره';
    document.getElementById('editPassLabel').innerHTML = 'رمز عبور جدید <span style="color:var(--text-3);font-weight:400;">(خالی = بدون تغییر)</span>';
    document.getElementById('editUserId').value   = id;
    document.getElementById('editFullName').value = fullName;
    document.getElementById('editUsername').value = username;
    document.getElementById('editPhone').value    = phone;
    document.getElementById('editEmail').value    = email;
    const editPass = document.getElementById('editUserPassword');
    editPass.value = ''; editPass.type = 'password';
    this._resetPassRules();
    const roleSel = document.getElementById('editUserRole');
    if (roleSel) { roleSel.value = (role === 'admin') ? 'admin' : 'user'; CustomSelect.refresh(roleSel); }
    document.getElementById('editCanViewProfile').checked = !!canViewProfile;
    document.getElementById('editCanViewNotifications').checked = !!canViewNotifications;
    const sendCredsField = document.getElementById('sendCredsField');
    if (sendCredsField) sendCredsField.hidden = true;
    Counter.update('editFullName', 60);
    Counter.update('editUsername', 60);
    Counter.update('editPhone', 11);
    Counter.update('editEmail', 190);
    Counter.update('editUserPassword', 64);
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
    const email    = document.getElementById('editEmail').value.trim();
    const password = document.getElementById('editUserPassword').value;
    const role     = document.getElementById('editUserRole')?.value || 'user';
    const canViewProfile       = document.getElementById('editCanViewProfile').checked;
    const canViewNotifications = document.getElementById('editCanViewNotifications').checked;
    const sendCredentials      = isAdd && (document.getElementById('editSendCredentials')?.checked || false);
    if (!fullName) return FieldErr.set('editFullName', 'نام و نام خانوادگی الزامی است');
    if (!username) return FieldErr.set('editUsername', 'نام‌کاربری الزامی است');
    if (!/^[a-zA-Z][a-zA-Z0-9_]{2,59}$/.test(username)) return FieldErr.set('editUsername', 'نام‌کاربری باید با حرف انگلیسی شروع شود و فقط شامل حروف/اعداد/underscore باشد');
    if (phone && !/^09\d{9}$/.test(phone)) return FieldErr.set('editPhone', 'شماره موبایل باید ۱۱ رقم و با ۰۹ شروع شود');
    if (!email) return FieldErr.set('editEmail', 'ایمیل الزامی است');
    if (!/^[A-Za-z0-9._%+-]+@(?:[A-Za-z0-9-]+\.)+[A-Za-z]{2,}$/.test(email)) return FieldErr.set('editEmail', 'قالب ایمیل نامعتبر است');
    if (isAdd && !password) return FieldErr.set('editUserPassword', 'رمز عبور الزامی است');
    if (password && !pwMeetsPolicy(password)) return FieldErr.set('editUserPassword', PW_POLICY_MSG);

    const action = isAdd ? 'add_user' : 'edit_user';
    const body   = isAdd
      ? { full_name: fullName, username, phone, email, password, role, can_view_profile: canViewProfile, can_view_notifications: canViewNotifications, send_credentials: sendCredentials }
      : { id: parseInt(idVal), full_name: fullName, username, phone, email, password, role, can_view_profile: canViewProfile, can_view_notifications: canViewNotifications };
    const res = await Api.call(action, body);
    if (res.ok) {
      this.close(true);
      Toast.show(isAdd ? 'کاربر اضافه شد' : 'کاربر ویرایش شد', 'success', isAdd ? 'افزودن موفق' : 'ویرایش موفق');
      if (isAdd && sendCredentials) {
        if (res.mail_sent) {
          Toast.show('اطلاعات ورود برای کاربر ایمیل شد', 'success');
        } else {
          Toast.show(res.mail_error || 'ارسال ایمیل اطلاعات ورود ناموفق بود', 'error');
        }
      }
      if (isAdd) this._page = 1;
      this.load();
    } else {
      const fieldId = {
        full_name: 'editFullName', username: 'editUsername', phone: 'editPhone',
        email: 'editEmail', password: 'editUserPassword',
      }[res.field];
      if (fieldId) FieldErr.set(fieldId, res.msg || 'خطا');
      else Toast.show(res.msg || 'خطا', 'error');
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
    Toast.show(isNowActive ? 'کاربر فعال شد' : 'کاربر غیرفعال شد', 'success', isNowActive ? 'فعال‌سازی موفق' : 'غیرفعال‌سازی موفق');
  },

  openDelete(id, name) {
    Confirm.show({
      title:    'حذف کاربر',
      heading:  'آیا از حذف این کاربر اطمینان دارید؟',
      body:     `کاربر <span class="item-name">${esc(name)}</span> به‌طور دائم حذف خواهد شد.`,
      warn:     'تمام دسترسی‌های این کاربر نیز حذف خواهد شد.',
      type:     'danger',
      btnLabel: 'حذف کاربر',
      onConfirm: async () => {
        const res = await Api.call('delete_user', { id });
        if (res.ok) {
          Confirm.close();
          Toast.show('کاربر حذف شد', 'success', 'حذف موفق');
          this.load();
        } else {
          Toast.show(res.msg || 'خطا در حذف', 'error');
        }
      },
    });
  },

  openResetSend(id, name, email) {
    if (!email) { Toast.show('این کاربر ایمیل ثبت‌شده ندارد', 'error'); return; }
    Confirm.show({
      title:    'ریست رمز عبور و ارسال ایمیل',
      heading:  'رمز عبور این کاربر بازنشانی شود؟',
      body:     `برای کاربر <span class="item-name">${esc(name)}</span> یک رمز عبور تصادفی جدید ساخته می‌شود و اطلاعات ورود به ایمیل او ارسال خواهد شد.`,
      warn:     'نشست‌های فعال این کاربر خارج (logout) خواهند شد.',
      type:     'warning',
      icon:     '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="2" y="4" width="20" height="16" rx="2"/><path d="M22 6l-10 7L2 6"/></svg>',
      btnLabel: 'ریست و ارسال',
      btnClass: 'btn-primary',
      onConfirm: async () => {
        const res = await Api.call('reset_send_user', { id });
        if (res.ok) {
          Confirm.close();
          if (res.mail_sent) {
            Toast.show(`رمز عبور «${name}» بازنشانی شد و به آدرس ${email} ارسال شد`, 'success', 'انجام شد');
          } else {
            Toast.show(`رمز عبور «${name}» بازنشانی شد اما ارسال ایمیل ناموفق بود: ${res.mail_error || ''}`, 'error');
          }
        } else {
          Toast.show(res.msg || 'خطا در بازنشانی رمز عبور', 'error');
        }
      },
    });
  },

  async testCredsEmail() {
    const input = document.getElementById('credsTestEmail');
    const to = input ? input.value.trim() : '';
    if (!to) { Toast.show('ایمیل مقصد را وارد کنید', 'error'); input?.focus(); return; }
    if (!EMAIL_RE.test(to)) { Toast.show('قالب ایمیل نامعتبر است', 'error'); input?.focus(); return; }
    const btn = document.getElementById('credsTestBtn');
    if (btn) { btn.classList.add('loading'); btn.disabled = true; }
    const res = await Api.call('test_credentials_email', { test_email: to });
    if (btn) { btn.classList.remove('loading'); btn.disabled = false; }
    if (res.ok) {
      Toast.show(res.msg || 'نمونه ایمیل اطلاعات ورود ارسال شد', 'success', 'ارسال موفق');
    } else if (res.field === 'test_email') {
      Toast.show(res.msg || 'ایمیل نامعتبر است', 'error');
      input?.focus();
    } else {
      Toast.show(res.msg || 'ارسال ناموفق بود', 'error');
    }
  },
};

// ═══════════════════════════════════════════════════════════
// AccessManager
// ═══════════════════════════════════════════════════════════
const AccessManager = {
  _currentUserId: null,
  _currentBadges: [],
  _isAdminTarget: false,
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
        body: 'آیا تغییرات ذخیره شوند؟',
        type: 'warning',
        icon: '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"><path d="M10.29 3.86 1.82 18a2 2 0 0 0 1.71 3h16.94a2 2 0 0 0 1.71-3L13.71 3.86a2 2 0 0 0-3.42 0z"/><line x1="12" y1="9" x2="12" y2="13"/><line x1="12" y1="17" x2="12.01" y2="17"/></svg>',
        cancelLabel: 'خیر',
        btnLabel: 'بله',
        btnClass: 'btn-primary',
        onConfirm: () => { Confirm.close(); this.save(); },
        onCancel: () => { this.close(true); },
      });
      return;
    }
    this._dirty = false;
    Modal.close('accessModal');
  },

  async open(userId, userName, role) {
    this._wireDirty();
    this._currentUserId = userId;
    this._currentBadges = [];
    this._isAdminTarget = role === 'admin';

    document.getElementById('accessModalTitle').textContent = `تنظیم دسترسی — ${userName}`;
    document.getElementById('accessUserId').value = userId;
    const badgesGrid = document.getElementById('accessBadgesGrid');
    const toolsList  = document.getElementById('accessToolsList');
    const adminHint  = document.getElementById('accessAdminHint');
    const saveBtn    = document.getElementById('saveAccessBtn');
    if (adminHint) adminHint.classList.toggle('show', this._isAdminTarget);
    if (saveBtn) {
      saveBtn.disabled = false;
      saveBtn.removeAttribute('data-tip');
      saveBtn.title = '';
    }
    badgesGrid.innerHTML = SKELETON_BADGE_CHIP.repeat(4);
    toolsList.innerHTML  = SKELETON_TABLE_ROW.repeat(4);
    Skeleton.mark(badgesGrid);

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

    const availableBadges = badgesRes.badges || [];
    // Admins are no longer a special case: their dashboard cards come from the same
    // tool_access/category_access rows as everyone else (AppController::allForUser),
    // so the stored rows are the truth for every role.
    const selectedToolIds = accessRes.tool_ids || [];
    const selectedBadges  = accessRes.badges   || [];

    this._currentBadges = selectedBadges;
    await Skeleton.wait(badgesGrid);
    this._render(availableBadges, selectedToolIds, selectedBadges);
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
          <input type="checkbox" class="access-badge-cb" value="${esc(badge)}" ${checked ? 'checked' : ''}>
          <span>${esc(badge)}</span>
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
      const inBadge    = selectedBadges.includes(tool.badge || '');
      const isChecked  = selectedToolIds.includes(tool.id) || inBadge;
      const isDisabled = inBadge;

      const row = document.createElement('div');
      row.className = 'access-tool-row';
      row.dataset.badge = tool.badge || '';

      let statusBadge = '';
      if (inBadge) statusBadge = '<span class="access-status-badge from-badge"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="3" stroke-linecap="round" stroke-linejoin="round"><polyline points="20 6 9 17 4 12"/></svg>از دسته</span>';

      row.innerHTML = `
        <label class="access-tool-label ${isDisabled ? 'disabled' : ''}">
          <input type="checkbox"
            class="access-tool-cb"
            value="${tool.id}"
            ${isChecked   ? 'checked'  : ''}
            ${isDisabled  ? 'disabled' : ''}
          >
          <span class="access-tool-info">
            <span class="access-tool-title">${esc(tool.title || '')}</span>
            ${tool.badge ? `<span class="access-tool-badge">${esc(tool.badge)}</span>` : ''}
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
      Toast.show('دسترسی‌ها ذخیره شد', 'success', 'ذخیره موفق');
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
    if (!grid) return;   // the grid only existed in the (now-removed) tool modal — tool management moved to the dashboard
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
    if (!grid) return;   // tool management moved to the dashboard — this grid is no longer in the panel
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
    const addBtn = document.createElement('button');
    addBtn.type = 'button';
    addBtn.className = 'asset-opt asset-opt-add' + (State.iconAddOpen ? ' active' : '');
    addBtn.title = 'آیکون جدید';
    addBtn.innerHTML = '<svg viewBox="0 0 24 24" width="17" height="17" fill="none" stroke="currentColor" stroke-width="2.5"><line x1="12" y1="5" x2="12" y2="19"/><line x1="5" y1="12" x2="19" y2="12"/></svg>';
    addBtn.onclick = () => this.toggleAdd();
    grid.appendChild(addBtn);
  },
  toggleAdd() {
    State.iconAddOpen = !State.iconAddOpen;
    if (State.iconAddOpen) {
      State.selIconKey = null;
      document.getElementById('iconEditor').style.display = 'none';
    }
    document.getElementById('iconAddForm').style.display = State.iconAddOpen ? 'block' : 'none';
    this.buildGrid();
    if (State.iconAddOpen) setTimeout(() => document.getElementById('newIconKey').focus(), 30);
  },
  open(key) {
    State.selIconKey = key;
    State.iconAddOpen = false;
    document.getElementById('iconAddForm').style.display = 'none';
    this.buildGrid();
    document.getElementById('iconEditor').style.display    = 'block';
    document.getElementById('iconEditorKey').textContent   = key;
    document.getElementById('iconEditorPath').value        = ICONS_DATA[key] || '';
    document.getElementById('iconDeleteBtn').disabled      = (key === 'star');
  },
  async save() {
    const path = document.getElementById('iconEditorPath').value.trim();
    if (!path) return FieldErr.set('iconEditorPath', 'SVG path نمی‌تواند خالی باشد');
    const res = await Api.call('save_icon', { key: State.selIconKey, path });
    if (res.ok) {
      ICONS_DATA[State.selIconKey] = path;
      this.buildGrid();
      IconPicker.build();
      Toast.show('آیکون ذخیره شد', 'success', 'ویرایش موفق');
    } else if (res.field === 'path') {
      FieldErr.set('iconEditorPath', res.msg);
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
      body:     `آیکون <span class="item-name">${esc(key)}</span> به‌طور دائم حذف خواهد شد.`,
      warn:     usedBy.length ? `این آیکون در ابزار «${usedBy.map(esc).join('، ')}» استفاده شده است.` : null,
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
          Toast.show('آیکون حذف شد', 'success', 'حذف موفق');
        } else {
          Toast.show(res.msg, 'error');
        }
      },
    });
  },
  async add() {
    const key  = document.getElementById('newIconKey').value.trim();
    const path = document.getElementById('newIconPath').value.trim();
    if (!key)  return FieldErr.set('newIconKey', 'نام آیکون الزامی است');
    if (!path) return FieldErr.set('newIconPath', 'SVG path الزامی است');
    const res = await Api.call('save_icon', { key, path });
    if (res.ok) {
      ICONS_DATA[key] = path;
      document.getElementById('newIconKey').value  = '';
      document.getElementById('newIconPath').value = '';
      Counter.update('newIconKey', 40);
      this.buildGrid();
      IconPicker.build();
      Toast.show('آیکون اضافه شد', 'success', 'افزودن موفق');
    } else {
      const fieldId = { key: 'newIconKey', path: 'newIconPath' }[res.field];
      if (fieldId) FieldErr.set(fieldId, res.msg);
      else Toast.show(res.msg, 'error');
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
    const addBtn = document.createElement('button');
    addBtn.type = 'button';
    addBtn.className = 'asset-opt deco-item asset-opt-add' + (State.decoAddOpen ? ' active' : '');
    addBtn.title = 'انیمیشن جدید';
    addBtn.innerHTML = '<svg viewBox="0 0 24 24" width="14" height="14" fill="none" stroke="currentColor" stroke-width="3"><line x1="12" y1="5" x2="12" y2="19"/><line x1="5" y1="12" x2="19" y2="12"/></svg>';
    addBtn.onclick = () => this.toggleAdd();
    grid.appendChild(addBtn);
  },
  toggleAdd() {
    State.decoAddOpen = !State.decoAddOpen;
    if (State.decoAddOpen) {
      State.selDecoKey = null;
      document.getElementById('decoEditor').style.display = 'none';
    }
    document.getElementById('decoAddForm').style.display = State.decoAddOpen ? 'block' : 'none';
    this.buildGrid();
    if (State.decoAddOpen) setTimeout(() => document.getElementById('newDecoKey').focus(), 30);
  },
  open(key) {
    State.selDecoKey = key;
    State.decoAddOpen = false;
    document.getElementById('decoAddForm').style.display = 'none';
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
    if (!svg) return FieldErr.set('decoEditorSVG', 'SVG نمی‌تواند خالی باشد');
    const res = await Api.call('save_deco', { key: State.selDecoKey, svg });
    if (res.ok) {
      DECOS_DATA[State.selDecoKey] = svg;
      DecoPicker.build();
      Toast.show('انیمیشن ذخیره شد', 'success', 'ویرایش موفق');
      Preview.update();
    } else if (res.field === 'svg') {
      FieldErr.set('decoEditorSVG', res.msg);
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
      body:     `انیمیشن <span class="item-name">${esc(key)}</span> به‌طور دائم حذف خواهد شد.`,
      warn:     usedBy.length ? `ابزار «${usedBy.map(esc).join('، ')}» از این انیمیشن استفاده می‌کنند.` : null,
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
          Toast.show(res.fallback ? 'انیمیشن حذف شد و ابزارهای مرتبط به پیش‌فرض بازگشتند' : 'انیمیشن حذف شد', 'success', 'حذف موفق');
        } else {
          Toast.show(res.msg, 'error');
        }
      },
    });
  },
  async add() {
    const key = document.getElementById('newDecoKey').value.trim();
    const svg = document.getElementById('newDecoSVG').value.trim();
    if (!key) return FieldErr.set('newDecoKey', 'نام انیمیشن الزامی است');
    if (!svg) return FieldErr.set('newDecoSVG', 'SVG الزامی است');
    const res = await Api.call('save_deco', { key, svg });
    if (res.ok) {
      DECOS_DATA[key] = svg;
      document.getElementById('newDecoKey').value = '';
      document.getElementById('newDecoSVG').value = '';
      Counter.update('newDecoKey', 40);
      this.buildGrid();
      DecoPicker.build();
      Toast.show('انیمیشن اضافه شد', 'success', 'افزودن موفق');
    } else {
      const fieldId = { key: 'newDecoKey', svg: 'newDecoSVG' }[res.field];
      if (fieldId) FieldErr.set(fieldId, res.msg);
      else Toast.show(res.msg, 'error');
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

  // applies the theme without lag: transitions on all elements are disabled for one frame
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
    // sync between tabs
    window.addEventListener('storage', e => {
      if (e.key === 'theme' && (e.newValue === 'dark' || e.newValue === 'light')) {
        this.apply(e.newValue, false);
      }
    });
    // follow the system theme when the user hasn't chosen manually
    window.matchMedia('(prefers-color-scheme: dark)').addEventListener('change', e => {
      if (!localStorage.getItem('theme')) this.apply(e.matches ? 'dark' : 'light', false);
    });
  },
};

// ═══════════════════════════════════════════════════════════
// Public functions — called from HTML
// ═══════════════════════════════════════════════════════════
// admin section grid: clicking a tile opens its panel (accordion-style — only one at a time).
function togglePanel(id, tile) {
  const panel = document.getElementById(id);
  if (!panel) return;
  const willOpen = !panel.classList.contains('open');
  document.querySelectorAll('.section-panel.open').forEach(p => p.classList.remove('open'));
  document.querySelectorAll('.admin-tile.active').forEach(t => t.classList.remove('active'));
  if (willOpen) {
    panel.classList.add('open');
    if (tile) tile.classList.add('active');
    panel.scrollIntoView({ behavior: 'smooth', block: 'nearest' });
  }
}
function toggleTheme()              { Theme.toggle(); }
function closeConfirm()             { Confirm.cancel(); }
function runConfirm()               { Confirm.run(); }
function closeModal(id)             { Modal.close(id); }
function saveIconEdit()             { IconEditor.save(); }
function deleteIcon()               { IconEditor.delete(); }
function addNewIcon()               { IconEditor.add(); }
function saveDecoEdit()             { DecoEditor.save(); }
function deleteDeco()               { DecoEditor.delete(); }
function addNewDeco()               { DecoEditor.add(); }
function refreshDecoPreview()       { DecoEditor.refreshPreview(); }
function openEditUserModal(id,n,u,p,e,r,cvp,cvn){ UserManager.openEdit(id, n, u, p, e, r, cvp, cvn); }
function toggleUser(id, btn)        { UserManager.toggle(id, btn); }
function openDeleteUserModal(id, n) { UserManager.openDelete(id, n); }
function openAccessModal(id, name, role) { AccessManager.open(id, name, role); }
function saveAccess()               { AccessManager.save(); }

/* ── show/hide password ── */
function togglePass(inputId, btn) {
  const input = document.getElementById(inputId);
  if (!input) return;
  const isPass = input.type === 'password';
  input.type = isPass ? 'text' : 'password';
  btn.innerHTML = isPass
    ? '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"><path d="M17.94 17.94A10.07 10.07 0 0112 20c-7 0-11-8-11-8a18.45 18.45 0 015.06-5.94"/><path d="M9.9 4.24A9.12 9.12 0 0112 4c7 0 11 8 11 8a18.5 18.5 0 01-2.16 3.19"/><line x1="1" y1="1" x2="23" y2="23"/></svg>'
    : '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"><path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"/><circle cx="12" cy="12" r="3"/></svg>';
}

/* ── password rules + random password generation: from the shared password-policy.js module ── */
const PW_POLICY_MSG = 'رمز عبور باید بین ۱۰ تا ۶۴ کاراکتر و شامل حروف کوچک و بزرگ انگلیسی، عدد و نماد باشد.';
function pwMeetsPolicy(val) { return PasswordPolicy.meets(val); }
function updatePassRules(val) { PasswordPolicy.updateChecklist('editPassRules', val); }
function genUserPassword(el) {
  PasswordPolicy.generate(el.dataset.target, null, 'editPassRules');
  Counter.update(el.dataset.target, 64);
}

// ═══════════════════════════════════════════════════════════
// SecurityManager — login blocks (rate limit): view log and clear blocks
// ═══════════════════════════════════════════════════════════
const SecurityManager = {
  open() { Modal.open('blocksModal'); this.refresh(); },

  async refresh() {
    const box = document.getElementById('blocksList');
    box.innerHTML = SKELETON_TABLE_ROW.repeat(3);
    Skeleton.mark(box);
    const res = await Api.call('list_blocks', {});
    await Skeleton.wait(box);
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
    const last = r.last_attempt ? DateFmt.dateTime(r.last_attempt * 1000) : '—';
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
          <button class="btn btn-danger btn-sm" data-act="securityUnblock" data-ip="${ip}" data-scope="${sc}">رفع انسداد</button>
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
        if (res.ok) { Toast.show('انسداد رفع شد', 'success', 'رفع انسداد موفق'); this.refresh(); }
        else        { Toast.show(res.msg || 'خطا در رفع انسداد', 'error'); }
      },
    });
  },
};
function openBlocksModal() { SecurityManager.open(); }

// ═══════════════════════════════════════════════════════════
// SessionsManager — manages a user's active sessions
// Used on the users page via each user's modal (sessionsUserModal).
// ═══════════════════════════════════════════════════════════
const SessionsManager = {
  _curUser: 0,
  _curUserName: '',

  // Infinite-scroll state (same approach as NM's readers modal: fetch by offset,
  // load the next page once the scroll container nears its bottom, guard stale
  // in-flight fetches with a bumped request sequence).
  _sessOffset:  0,
  _sessLoading: false,
  _sessHasMore: false,
  _sessReqSeq:  0,
  _sessScrollHandler: null,

  // ── single-user modal ──
  openUser(uid, name) {
    this._curUser = uid;
    this._curUserName = name || '';
    const t = document.getElementById('sessionsUserTitle');
    if (t) t.textContent = 'نشست‌های فعال — ' + (name || '');
    Modal.open('sessionsUserModal');

    const body = document.getElementById('sessionsUserBody');
    if (this._sessScrollHandler) body.removeEventListener('scroll', this._sessScrollHandler);
    this._sessScrollHandler = () => {
      if (!this._sessHasMore || this._sessLoading) return;
      if (body.scrollTop + body.clientHeight >= body.scrollHeight - 80) {
        this._loadUserPage(false);
      }
    };
    body.addEventListener('scroll', this._sessScrollHandler);

    this.loadUser();
  },

  /** (Re)loads from the first page — also used to refresh after a terminate action. */
  async loadUser() {
    this._sessOffset  = 0;
    this._sessHasMore = false;
    this._sessReqSeq++;
    await this._loadUserPage(true);
  },

  async _loadUserPage(isFirstPage) {
    if (this._sessLoading) return;
    this._sessLoading = true;
    const reqSeq = this._sessReqSeq;

    const box = document.getElementById('sessionsUserList');
    if (!box) { this._sessLoading = false; return; }
    const killBtn = document.getElementById('sessTerminateUserBtn');

    let sentinel = null;
    if (isFirstPage) {
      box.innerHTML = SKELETON_TABLE_ROW.repeat(2);
      Skeleton.mark(box);
      if (killBtn) killBtn.disabled = true;
    } else {
      sentinel = document.createElement('div');
      sentinel.innerHTML = SKELETON_TABLE_ROW;
      box.appendChild(sentinel.firstElementChild);
    }

    const res = await Api.call('list_sessions', { user_id: this._curUser, offset: this._sessOffset });
    if (reqSeq !== this._sessReqSeq) return; // modal closed/reopened while this was in flight
    if (isFirstPage) await Skeleton.wait(box);

    const lastSkeleton = box.querySelector('.sk-table-row:last-child');
    if (!isFirstPage && lastSkeleton) lastSkeleton.remove();

    if (!res.ok) {
      box.innerHTML = '<div class="blocks-empty">خطا در دریافت نشست‌ها</div>';
      this._sessLoading = false;
      return;
    }

    const list     = res.sessions || [];
    const rowsHtml = list.map(s => this._row(s, false)).join('');

    if (isFirstPage) {
      box.innerHTML = rowsHtml || '<div class="blocks-empty">این کاربر نشست فعالی ندارد.</div>';
    } else {
      box.insertAdjacentHTML('beforeend', rowsHtml);
    }

    this._sessOffset += list.length;
    this._sessHasMore = !!res.has_more;
    this._sessLoading = false;

    // If the only remaining session (across ALL pages, not just this one) is the admin's own
    // current one, "log out of all devices" has nothing to terminate (the server excludes it
    // so the admin doesn't get kicked out of the panel). Only decided once fully loaded, since
    // more pages may still hold other sessions.
    if (killBtn && !this._sessHasMore) {
      const rows = box.querySelectorAll('.blk-row');
      const onlyOwnCurrent = rows.length === 1 && !!box.querySelector('.blk-current');
      killBtn.disabled = rows.length === 0 || onlyOwnCurrent;
    }
  },

  _row(s, showName) {
    const when  = s.last_seen ? DateFmt.dateTime(s.last_seen * 1000) : '—';
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
    const current = s.is_current ? '<span class="blk-badge blk-current">دستگاه فعلی</span>' : '';
    const id = esc(s.id);
    const killBtn = s.is_current
      ? ''
      : `<button class="btn btn-danger btn-sm" data-act="sessTerminate" data-id="${id}" data-current="false">پایان</button>`;
    return `
      <div class="blk-row">
        <div class="blk-info">
          <div class="blk-ip">${title}</div>
          <div class="blk-meta">${meta}</div>
        </div>
        <div class="blk-side">
          ${current}
          ${killBtn}
        </div>
      </div>`;
  },

  // ── set session lifetime (hours) — users page ──
  async saveTtl() {
    const el = document.getElementById('sessTtlInput');
    const v  = parseInt(String(el ? el.value : '').replace(/[^\d]/g, ''), 10);
    if (!v || v < 1 || v > 720) { Toast.show('عددی بین ۱ تا ۷۲۰ وارد کنید', 'error'); return; }
    const res = await Api.call('save_session_ttl', { session_ttl_hours: v });
    if (res.ok) { if (el) el.value = res.hours; Toast.show(res.msg || 'ذخیره شد', 'success', 'ذخیره موفق'); }
    else        { Toast.show(res.msg || 'خطا در ذخیره', 'error'); }
  },

  // ── actions ──
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
        Toast.show('نشست پایان یافت', 'success', 'پایان نشست موفق');
        if (isCurrent) { location.href = '/'; return; }
        this.loadUser();
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
        Toast.show(res.msg || 'انجام شد', 'success', 'خروج اجباری موفق');
        this.loadUser();
      },
    });
  },
};

// ═══════════════════════════════════════════════════════════
// CategoriesManager — category management page (rename / delete)
// A category can only be deleted once no tool carries it anymore (enforced server-side
// too); this page exists mainly to clean up "orphan" categories — ones a tool no longer
// references but that still have stray category_access/notification_badges rows pointing
// at them, since nothing else in the admin UI can see or reach those.
// ═══════════════════════════════════════════════════════════
const CategoriesManager = {
  _categories: [],

  async load() {
    const list = document.getElementById('categoryList');
    list.innerHTML = SKELETON_TABLE_ROW.repeat(3);
    Skeleton.mark(list);
    const res = await Api.call('list_categories', {});
    await Skeleton.wait(list);
    if (!res.ok) {
      list.innerHTML = `<div class="category-list-empty">${esc(res.msg || 'خطا در دریافت دسته‌بندی‌ها')}</div>`;
      return;
    }
    this._categories = res.categories || [];
    document.getElementById('categoryCountBadge').textContent = this._categories.length;
    this._render();
  },

  _render() {
    const list = document.getElementById('categoryList');
    if (!this._categories.length) {
      list.innerHTML = '<div class="category-list-empty">هیچ دسته‌بندی‌ای ثبت نشده است</div>';
      return;
    }
    list.className = 'category-list';
    list.innerHTML = this._categories.map(c => this._row(c)).join('');
  },

  _row(c) {
    const orphan = c.tool_count === 0;
    const toolChip = `<span class="cat-count${orphan ? ' cat-count-warn' : ''}">${c.tool_count} ابزار</span>`;
    const accessChip = c.access_count > 0 ? `<span class="cat-count">${c.access_count} دسترسی کاربر</span>` : '';
    const deleteTitle = orphan ? 'حذف دسته‌بندی' : 'برای حذف، ابتدا دسته‌بندی ابزارهای این دسته را تغییر دهید';
    return `
      <div class="blk-row">
        <div class="blk-info">
          <div class="blk-ip">${esc(c.name)}</div>
          <div class="blk-meta">${toolChip}${accessChip}</div>
        </div>
        <div class="blk-side">
          <button class="btn btn-secondary btn-icon btn-sm" title="تغییر نام" data-act="catOpenRename" data-id="${c.id}" data-name="${esc(c.name)}">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
              <path d="M11 4H4a2 2 0 00-2 2v14a2 2 0 002 2h14a2 2 0 002-2v-7"/>
              <path d="M18.5 2.5a2.121 2.121 0 013 3L12 15l-4 1 1-4 9.5-9.5z"/>
            </svg>
          </button>
          <button class="btn btn-danger btn-icon btn-sm" title="${esc(deleteTitle)}" data-act="catOpenDelete" data-id="${c.id}" data-name="${esc(c.name)}" ${orphan ? '' : 'disabled aria-disabled="true"'}>
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
              <polyline points="3 6 5 6 21 6"/>
              <path d="M19 6l-1 14a2 2 0 01-2 2H8a2 2 0 01-2-2L5 6"/>
              <path d="M10 11v6M14 11v6M9 6V4a1 1 0 011-1h4a1 1 0 011 1v2"/>
            </svg>
          </button>
        </div>
      </div>`;
  },

  openRename(id, name) {
    document.getElementById('catRenameId').value = id;
    document.getElementById('catRenameName').value = name;
    this._updateRenameCounter();
    Modal.open('categoryRenameModal');
    setTimeout(() => document.getElementById('catRenameName').focus(), 100);
  },

  closeRename() { Modal.close('categoryRenameModal'); },

  renameKey(e) {
    if (e.key === 'Enter') { e.preventDefault(); this.saveRename(); }
  },

  // live character counter for the category name field (max 20)
  _updateRenameCounter() {
    const input = document.getElementById('catRenameName');
    const cEl   = document.getElementById('catRenameCount');
    const wrap  = document.getElementById('catRenameCounter');
    if (!input) return;
    const len = input.value.length;
    if (cEl)  cEl.textContent = len.toLocaleString('en-US');
    if (wrap) wrap.classList.toggle('over', len >= 20);
  },
  _initRenameCounter() {
    const input = document.getElementById('catRenameName');
    if (!input || input.__counterBound) return;
    input.__counterBound = true;
    input.addEventListener('input', () => this._updateRenameCounter());
  },

  async saveRename() {
    const id   = parseInt(document.getElementById('catRenameId').value, 10);
    const name = document.getElementById('catRenameName').value.trim();
    if (!name) return FieldErr.set('catRenameName', 'نام دسته‌بندی الزامی است');
    if (!/^[\p{L}_]+$/u.test(name)) {
      return FieldErr.set('catRenameName', 'نام دسته‌بندی فقط می‌تواند شامل حروف و underscore باشد');
    }

    const res = await Api.call('rename_category', { id, name });
    if (res.ok) {
      Toast.show('نام دسته‌بندی تغییر کرد', 'success', 'ذخیره موفق');
      this.closeRename();
      this.load();
    } else {
      FieldErr.set('catRenameName', res.msg || 'خطا در تغییر نام');
    }
  },

  openDelete(id, name) {
    Confirm.show({
      title: 'حذف دسته‌بندی',
      heading: 'این دسته‌بندی حذف شود؟',
      body: `دسته‌بندی «<span class="item-name">${esc(name)}</span>» برای همیشه حذف می‌شود.`,
      warn: 'این عملیات قابل بازگشت نیست.',
      btnLabel: 'حذف',
      onConfirm: async () => {
        const res = await Api.call('delete_category', { id });
        if (!res.ok) { Toast.show(res.msg || 'خطا در حذف', 'error'); return; }
        Confirm.close();
        Toast.show('دسته‌بندی حذف شد', 'success', 'حذف موفق');
        this.load();
      },
    });
  },
};

// ═══════════════════════════════════════════════════════════
// SettingsManager — saves email/SMTP settings + sends a test email
// ═══════════════════════════════════════════════════════════
const EMAIL_RE = /^[A-Za-z0-9._%+-]+@(?:[A-Za-z0-9-]+\.)+[A-Za-z]{2,}$/;

const SettingsManager = {
  _v(id) { const el = document.getElementById(id); return el ? el.value.trim() : ''; },

  async save() {
    const fromEmail = this._v('setSmtpFromEmail');
    if (fromEmail && !EMAIL_RE.test(fromEmail)) return FieldErr.set('setSmtpFromEmail', 'قالب ایمیل نامعتبر است');

    const payload = {
      smtp_enabled:    document.getElementById('setSmtpEnabled').checked ? 1 : 0,
      smtp_host:       this._v('setSmtpHost'),
      smtp_port:       this._v('setSmtpPort'),
      smtp_secure:     document.getElementById('setSmtpSecure').value,
      smtp_user:       this._v('setSmtpUser'),
      smtp_pass:       document.getElementById('setSmtpPass').value, // no trim, to keep the password intact
      smtp_from_email: fromEmail,
      smtp_from_name:  this._v('setSmtpFromName'),
      resend_cooldown: this._v('setResendCooldown'),
      code_ttl:        this._v('setCodeTtl'),
    };
    const btn = document.getElementById('saveSettingsBtn');
    if (btn) { btn.classList.add('loading'); btn.disabled = true; }
    const res = await Api.call('save_settings', payload);
    if (btn) { btn.classList.remove('loading'); btn.disabled = false; }
    if (res.ok) {
      Toast.show('تنظیمات ذخیره شد', 'success', 'ذخیره موفق');
      document.getElementById('setSmtpPass').value = ''; // clear the password field after saving
    } else {
      const fieldId = {
        smtp_port: 'setSmtpPort', resend_cooldown: 'setResendCooldown', code_ttl: 'setCodeTtl',
        smtp_from_email: 'setSmtpFromEmail', smtp_host: 'setSmtpHost',
      }[res.field];
      if (fieldId) FieldErr.set(fieldId, res.msg || 'خطا در ذخیره تنظیمات');
      else Toast.show(res.msg || 'خطا در ذخیره تنظیمات', 'error');
    }
  },

  async test() {
    const to = this._v('setTestEmail');
    if (!to) return FieldErr.set('setTestEmail', 'ایمیل مقصد را وارد کنید');
    if (!EMAIL_RE.test(to)) return FieldErr.set('setTestEmail', 'قالب ایمیل نامعتبر است');
    const btn = document.getElementById('testEmailBtn');
    if (btn) { btn.classList.add('loading'); btn.disabled = true; }
    const res = await Api.call('test_email', { test_email: to });
    if (btn) { btn.classList.remove('loading'); btn.disabled = false; }
    if (res.ok) Toast.show(res.msg || 'ایمیل آزمایشی ارسال شد', 'success', 'ارسال موفق');
    else if (res.field === 'test_email') FieldErr.set('setTestEmail', res.msg || 'ارسال ناموفق بود');
    else Toast.show(res.msg || 'ارسال ناموفق بود', 'error');
  },
};

// ═══════════════════════════════════════════════════════════
// CustomSelect — upgrades a native <select> into a theme-matching dropdown
// Note: the original <select> stays the source of truth for the value (it's hidden) so
// existing code that reads .value keeps working unchanged; selections write the value
// back onto that same select and fire a change event.
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

  /** syncs the custom display with the <select>'s current value (after a programmatic change) */
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
// close on outside click
document.addEventListener('click', () =>
  document.querySelectorAll('.cselect.open').forEach(w => w.classList.remove('open')));

// ═══════════════════════════════════════════════════════════
// SessionWatch — watches for session TTL expiry in the background
// Unlike the main dashboard, the admin panel had no periodic poll; meaning an active admin
// who kept a tab open would stay in the panel even after the TTL ended, until the first
// failed action (401/403). Here, a lightweight poll every 25s detects the exact moment
// of expiry and redirects the user to the login page.
// ═══════════════════════════════════════════════════════════
const SessionWatch = {
  _timer: null,
  _POLL_MS: 25000,

  start() {
    this._timer = setInterval(() => {
      if (!document.hidden) this._check();
    }, this._POLL_MS);
  },

  async _check() {
    try {
      const res = await fetch('/api.php?action=me', { cache: 'no-cache' });
      // Refresh this same page (instead of redirecting to /login) — the server gate in
      // admin.php itself redirects an unauthenticated/unauthorized user to the right destination.
      if (res.status === 401) {
        location.reload();
      }
    } catch { /* silent — a temporary network error, re-checked on the next poll */ }
  },
};

document.addEventListener('DOMContentLoaded', () => {
  SessionWatch.start();

  // icon/deco management (tool management has moved to the main dashboard)
  if (document.getElementById('iconAssetGrid'))  IconEditor.buildGrid();
  if (document.getElementById('decoAssetGrid'))  DecoEditor.buildGrid();
  document.getElementById('newIconKey')?.addEventListener('input', () => Counter.update('newIconKey', 40));
  document.getElementById('newDecoKey')?.addEventListener('input', () => Counter.update('newDecoKey', 40));
  Theme.init();
  CustomSelect.enhanceAll();   // upgrade all native <select>s into theme-matching dropdowns

  // user list via AJAX (user management page)
  if (document.getElementById('userList')) {
    UserManager._initPerPage();
    UserManager.load();
  }

  // category management page
  if (document.getElementById('categoryList')) {
    CategoriesManager.load();
    CategoriesManager._initRenameCounter();
  }

  // test email (settings page): the send button stays disabled until the email format is valid
  const testEmailInput = document.getElementById('setTestEmail');
  const testEmailBtn   = document.querySelector('[data-act="testSettings"]');
  if (testEmailInput && testEmailBtn) {
    const syncTestEmailBtn = () => { testEmailBtn.disabled = !EMAIL_RE.test(testEmailInput.value.trim()); };
    testEmailInput.addEventListener('input', syncTestEmailBtn);
    testEmailInput.addEventListener('blur', () => {
      const v = testEmailInput.value.trim();
      if (v && !EMAIL_RE.test(v)) FieldErr.set('setTestEmail', 'قالب ایمیل نامعتبر است');
    });
    syncTestEmailBtn();
  }

  // close the modal on overlay click
  document.querySelectorAll('.modal-overlay').forEach(o => {
    o.addEventListener('click', e => {
      if (e.target !== o) return;
      if (o.id === 'confirmModal')   { Confirm.cancel(); }
      else if (o.id === 'userModal') { UserManager.close(); }
      else                            { Modal.close(o.id); }
    });
  });

  // close the modal with Escape — only the topmost modal (last one opened)
  document.addEventListener('keydown', e => {
    if (e.key !== 'Escape') return;
    const open = document.querySelectorAll('.modal-overlay.open');
    if (!open.length) return;
    const top = open[open.length - 1];
    if (top.id === 'confirmModal')   { Confirm.cancel(); }
    else if (top.id === 'userModal') { UserManager.close(); }
    else                              { Modal.close(top.id); }
  });
});

// ═══════════════════════════════════════════════════════════
// ripple (click wave) effect on header buttons and action buttons — shared with theme.js
// ═══════════════════════════════════════════════════════════
(function () {
  const SEL = '.hdr-btn, .btn, .btn-icon, .cselect-option, .pg-btn,'
    + ' .pagination-btn, .pagination-goto-spin,'
    + ' .access-tool-label, .deco-opt, .section-box-head, .modal-close,'
    + ' .user-adv-toggle, .user-search-clear, .toast-close, .pass-gen,'
    + ' .log-chip, .log-table-row, .log-table-del, .log-detail-trace-btn,'
    + ' .log-adv-toggle, .log-sort-btn';
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
  // delay header link navigation by ~160ms so the ripple is visible (prerender is instant)
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

// sticky header on scroll (shared with theme.js): .is-stuck toggles on scrolling down
(function () {
  const header = document.querySelector('.app-header');
  if (!header) return;
  let ticking = false;
  function update() {
    const y = window.scrollY;
    // dual threshold (hysteresis) so a few pixels of scroll jitter (e.g. the padding-top
    // change from is-stuck itself) doesn't repeatedly toggle the class and make the header shake.
    if (y > 24) header.classList.add('is-stuck');
    else if (y < 8) header.classList.remove('is-stuck');
    ticking = false;
  }
  window.addEventListener('scroll', function () {
    if (!ticking) { requestAnimationFrame(update); ticking = true; }
  }, { passive: true });
  update();
})();

// ═══════════════════════════════════════════════════════════
// actions (replaces on* for CSP) — the actions.js dispatcher calls these based on
// data-act/data-change attributes on elements (static or dynamic).
// Manager logic is untouched; this is just the wiring layer.
// ═══════════════════════════════════════════════════════════
if (window.Actions) {
  Actions.register({
    // dashboard panels
    togglePanel:        (el) => togglePanel(el.dataset.panel, el),
    // icons
    saveIconEdit:       () => saveIconEdit(),
    deleteIcon:         () => deleteIcon(),
    addNewIcon:         () => addNewIcon(),
    // animations (deco)
    saveDecoEdit:       () => saveDecoEdit(),
    refreshDecoPreview: () => refreshDecoPreview(),
    deleteDeco:         () => deleteDeco(),
    addNewDeco:         () => addNewDeco(),
    // confirm modal
    closeConfirm:       () => closeConfirm(),
    runConfirm:         () => runConfirm(),
    // users
    userAdd:            () => UserManager.openAdd(),
    userClose:          () => UserManager.close(),
    userSave:           () => UserManager.save(),
    userEdit:           (el) => { const d = el.dataset; openEditUserModal(+d.id, d.name, d.username, d.phone, d.email, d.role, d.canViewProfile !== '0', d.canViewNotifications !== '0'); },
    userToggle:         (el) => toggleUser(+el.dataset.id, el),
    userDelete:         (el) => openDeleteUserModal(+el.dataset.id, el.dataset.name),
    userResetSend:      (el) => UserManager.openResetSend(+el.dataset.id, el.dataset.name, el.dataset.email),
    userTestCredsEmail: () => UserManager.testCredsEmail(),
    userSearch:         (el) => UserManager.onSearchInput(el.value),
    userClearSearch:    () => UserManager.clearSearch(),
    userSetPerPage:     (el) => UserManager.setPerPage(el.value),
    userToggleAdvanced: () => UserManager.toggleAdvanced(),
    userApplyFilters:   () => UserManager.applyFilters(),
    userResetFilters:   () => UserManager.resetFilters(),
    userGoToPage:       (el) => UserManager.goToPage(parseInt(el.dataset.page, 10)),
    userGoToStep:       (el) => UserManager.goToStep(parseInt(el.dataset.dir, 10)),
    userGoToInputKey:   (el, e) => UserManager.goToInputKey(e),
    togglePass:         (el) => togglePass(el.dataset.target, el),
    genUserPassword:    (el) => genUserPassword(el),
    closeModal:         (el) => closeModal(el.dataset.modal),
    // access
    accessOpen:         (el) => openAccessModal(+el.dataset.id, el.dataset.name, el.dataset.role),
    accessClose:        () => AccessManager.close(),
    saveAccess:         () => saveAccess(),
    // sessions
    saveTtl:            () => SessionsManager.saveTtl(),
    sessOpenUser:       (el) => SessionsManager.openUser(+el.dataset.id, el.dataset.name),
    sessTerminateUser:  () => SessionsManager.terminateUser(),
    sessTerminate:      (el) => SessionsManager.terminate(el.dataset.id, el.dataset.current === 'true'),
    // security (login blocks)
    openBlocks:         () => openBlocksModal(),
    securityRefresh:    () => SecurityManager.refresh(),
    securityUnblock:    (el) => SecurityManager.unblock(el.dataset.ip, el.dataset.scope),
    // email/SMTP settings
    saveSettings:       () => SettingsManager.save(),
    testSettings:       () => SettingsManager.test(),
    // categories
    catOpenRename:      (el) => CategoriesManager.openRename(parseInt(el.dataset.id, 10), el.dataset.name),
    catCloseRename:     () => CategoriesManager.closeRename(),
    catSaveRename:      () => CategoriesManager.saveRename(),
    catRenameKey:       (el, e) => CategoriesManager.renameKey(e),
    catOpenDelete:      (el) => CategoriesManager.openDelete(parseInt(el.dataset.id, 10), el.dataset.name),
  });
}