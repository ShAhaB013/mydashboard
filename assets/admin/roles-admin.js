'use strict';

// ═══════════════════════════════════════════════════════════
// RoleManager — access roles page (page=roles): role cards + add/edit modal + delete
// A role = categories (all their tools) + specific tools + hidden menus.
// ═══════════════════════════════════════════════════════════
const RoleManager = {
  _roles:  [],
  _badges: [],
  _query:  '',
  _dirty:  false,
  _wiredDirty: false,

  _MENU_LABEL: { profile: 'حساب کاربری', notifications: 'اعلان‌ها' },
  // Chips shown per card section before collapsing the rest behind "+N"
  _CHIP_LIMIT: { members: 6, badges: 8, tools: 6 },

  _ICON: {
    role:    '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M12 22s8-4 8-10V5l-8-3-8 3v7c0 6 8 10 8 10z"/><polyline points="9 12 11 14 15 10"/></svg>',
    members: '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"><path d="M17 21v-2a4 4 0 00-4-4H5a4 4 0 00-4 4v2"/><circle cx="9" cy="7" r="4"/><path d="M23 21v-2a4 4 0 00-3-3.87M16 3.13a4 4 0 010 7.75"/></svg>',
    badges:  '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M20.59 13.41l-7.17 7.17a2 2 0 01-2.83 0L2 12V2h10l8.59 8.59a2 2 0 010 2.82z"/><line x1="7" y1="7" x2="7.01" y2="7"/></svg>',
    tools:   '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="2" y="3" width="20" height="14" rx="2"/><line x1="8" y1="21" x2="16" y2="21"/><line x1="12" y1="17" x2="12" y2="21"/></svg>',
    on:      '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><polyline points="20 6 9 17 4 12"/></svg>',
    off:     '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round"><line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/></svg>',
    empty:   '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round"><path d="M12 22s8-4 8-10V5l-8-3-8 3v7c0 6 8 10 8 10z"/></svg>',
  },

  async load() {
    const list = document.getElementById('roleList');
    list.innerHTML = SKELETON_TABLE_ROW.repeat(4);
    Skeleton.mark(list);

    const res = await Api.call('list_access_roles', {});
    await Skeleton.wait(list);
    if (!res.ok) {
      list.innerHTML = `<div class="role-empty">${esc(res.msg || 'خطا در بارگذاری')}</div>`;
      return;
    }
    this._roles  = res.roles  || [];
    this._badges = res.badges || [];
    this._render();
  },

  // ── list ──────────────────────────────────────────────────
  _toolTitles() {
    return Object.fromEntries(TOOLS_RAW.map(t => [t.id, t.title || '']));
  },

  _matches(r, q, toolTitle) {
    if (!q) return true;
    const hay = [
      r.name, r.description,
      ...r.members.flatMap(m => [m.name, m.username]),
      ...r.badges,
      ...r.tool_ids.map(id => toolTitle[id] || ''),
    ].join('\n').toLowerCase();
    return hay.includes(q);
  },

  _render() {
    const list = document.getElementById('roleList');
    document.getElementById('roleCountBadge').textContent = this._roles.length;
    if (!this._roles.length) {
      list.innerHTML = `<div class="role-empty">${this._ICON.empty}<span>هنوز نقشی تعریف نشده است. با «نقش جدید» اولین نقش را بسازید.</span></div>`;
      return;
    }
    const toolTitle = this._toolTitles();
    const q = this._query.trim().toLowerCase();
    const shown = this._roles.filter(r => this._matches(r, q, toolTitle));
    list.innerHTML = shown.length
      ? shown.map(r => this._card(r, toolTitle)).join('')
      : `<div class="role-empty">${this._ICON.empty}<span>نقشی با عبارت «${esc(this._query.trim())}» پیدا نشد.</span></div>`;
  },

  /** Tools a member of this role can see: explicit tools ∪ tools of the role's categories */
  _reachedTools(r) {
    return TOOLS_RAW.filter(t => r.tool_ids.includes(t.id) || (t.badge && r.badges.includes(t.badge))).length;
  },

  /** Chip list collapsed after `limit` items; the rest is revealed by a "+N" toggle */
  _chips(items, limit, emptyText) {
    if (!items.length) return `<span class="role-card-none">${emptyText}</span>`;
    const rest = items.length - limit;
    return items.map((html, i) => i < limit ? html : html.replace('class="role-chip', 'class="role-chip is-extra')).join('')
      + (rest > 0
        ? `<button type="button" class="role-chip-more" data-act="roleToggleChips" data-rest="${rest}">نمایش ${rest} مورد دیگر</button>`
        : '');
  },

  _section(icon, title, count, body) {
    return `
      <div class="role-sec">
        <div class="role-sec-title">${icon}${title}${count !== null ? ` <span class="role-sec-count">(${count})</span>` : ''}</div>
        <div class="role-card-chips">${body}</div>
      </div>`;
  },

  _card(r, toolTitle) {
    const L = this._CHIP_LIMIT;

    const members = this._chips(r.members.map(m => {
      const initial = (m.name || m.username || '?').trim().charAt(0);
      const title   = `${m.username}${m.is_active ? '' : ' (غیرفعال)'}`;
      return `<span class="role-chip is-member ${m.is_active ? '' : 'is-inactive'}" title="${esc(title)}"><span class="role-member-av">${esc(initial)}</span><span>${esc(m.name)}</span></span>`;
    }), L.members, 'هنوز عضوی ندارد');

    const badges = this._chips(r.badges.map(b => `<span class="role-chip is-cat"><span>${esc(b)}</span></span>`),
      L.badges, 'دسته‌ای انتخاب نشده');

    const toolNames = r.tool_ids.map(id => toolTitle[id]).filter(Boolean);
    const tools = this._chips(toolNames.map(t => `<span class="role-chip"><span>${esc(t)}</span></span>`),
      L.tools, 'ابزار خاصی انتخاب نشده');

    const menus = Object.keys(this._MENU_LABEL).map(k => {
      const off = r.hidden_menus.includes(k);
      return `<span class="role-menu-flag ${off ? 'is-off' : ''}" title="${off ? 'مخفی' : 'نمایش داده می‌شود'}">${off ? this._ICON.off : this._ICON.on}<span>${esc(this._MENU_LABEL[k])}</span></span>`;
    }).join('');

    const reached  = this._reachedTools(r);
    const total    = TOOLS_RAW.length;
    const pct      = total ? Math.round(reached / total * 100) : 0;
    const canDelete = r.members.length === 0;
    const delTitle  = canDelete ? 'حذف نقش' : `این نقش ${r.members.length} عضو دارد؛ ابتدا نقش آن‌ها را تغییر دهید`;

    return `
      <article class="role-card">
        <div class="role-card-head">
          <div class="role-card-ic">${this._ICON.role}</div>
          <div class="role-card-title">
            <h3 title="${esc(r.name)}">${esc(r.name)}</h3>
            <p>${r.description ? esc(r.description) : 'بدون توضیحات'}</p>
          </div>
          <div class="role-card-actions">
            <button type="button" class="btn btn-secondary btn-icon btn-sm" title="ویرایش نقش" data-act="roleOpenEdit" data-id="${r.id}">
              <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                <path d="M11 4H4a2 2 0 00-2 2v14a2 2 0 002 2h14a2 2 0 002-2v-7"/>
                <path d="M18.5 2.5a2.121 2.121 0 013 3L12 15l-4 1 1-4 9.5-9.5z"/>
              </svg>
            </button>
            <button type="button" class="btn btn-danger btn-icon btn-sm" title="${esc(delTitle)}" data-act="roleOpenDelete" data-id="${r.id}"
              ${canDelete ? '' : 'disabled aria-disabled="true"'}>
              <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                <polyline points="3 6 5 6 21 6"/>
                <path d="M19 6l-1 14a2 2 0 01-2 2H8a2 2 0 01-2-2L5 6"/>
              </svg>
            </button>
          </div>
        </div>

        <div class="role-stats">
          <div class="role-stat"><span class="role-stat-val">${r.members.length}</span><span class="role-stat-lbl">عضو</span></div>
          <div class="role-stat"><span class="role-stat-val">${r.badges.length}</span><span class="role-stat-lbl">دسته</span></div>
          <div class="role-stat" title="ابزارهایی که اعضا می‌بینند (از دسته‌ها و ابزارهای خاص)">
            <span class="role-stat-val">${reached} <small>از ${total}</small></span>
            <span class="role-stat-lbl">ابزار قابل مشاهده</span>
            <span class="role-stat-bar"><span style="width:${pct}%"></span></span>
          </div>
        </div>

        ${this._section(this._ICON.members, 'اعضا', r.members.length, members)}
        ${this._section(this._ICON.badges, 'دسته‌ها', r.badges.length, badges)}
        ${this._section(this._ICON.tools, 'ابزارهای خاص', toolNames.length, tools)}

        <div class="role-card-foot">
          <span class="role-card-foot-lbl">منوها:</span>
          ${menus}
        </div>
      </article>`;
  },

  toggleChips(btn) {
    const box      = btn.closest('.role-card-chips');
    const expanded = box.classList.toggle('is-expanded');
    btn.textContent = expanded ? 'نمایش کمتر' : `نمایش ${btn.dataset.rest} مورد دیگر`;
  },

  search(value) {
    this._query = value;
    document.getElementById('roleSearchWrap').classList.toggle('has-value', value.trim() !== '');
    this._render();
  },

  clearSearch() {
    const input = document.getElementById('roleSearchInput');
    input.value = '';
    this.search('');
    input.focus();
  },

  // ── modal ─────────────────────────────────────────────────
  _wireDirty() {
    if (this._wiredDirty) return;
    const m = document.getElementById('roleModal');
    // The picker filter doesn't change the role itself
    const mark = e => { if (e.target.id !== 'roleFilter') this._dirty = true; };
    m.addEventListener('input',  mark);
    m.addEventListener('change', mark);
    this._wiredDirty = true;
  },

  openAdd() { this._open(null); },

  openEdit(id) {
    const role = this._roles.find(r => r.id === id);
    if (role) this._open(role);
  },

  _open(role) {
    this._wireDirty();
    document.getElementById('roleModalTitle').textContent = role ? `ویرایش نقش — ${role.name}` : 'نقش جدید';
    document.getElementById('roleId').value          = role ? role.id : '';
    document.getElementById('roleName').value        = role ? role.name : '';
    document.getElementById('roleDescription').value = role ? role.description : '';
    document.getElementById('roleShowProfile').checked       = !(role && role.hidden_menus.includes('profile'));
    document.getElementById('roleShowNotifications').checked = !(role && role.hidden_menus.includes('notifications'));
    FieldErr.clear('roleName');
    FieldErr.clear('roleDescription');
    this.updateCounters();

    this._renderBadges(role ? role.badges : []);
    this._renderTools(role ? role.tool_ids : []);
    document.getElementById('roleFilter').value = '';
    this.filterPickers('');

    Modal.open('roleModal');
    this._dirty = false;
    setTimeout(() => document.getElementById('roleName').focus(), 100);
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
        onCancel:  () => { this.close(true); },
      });
      return;
    }
    this._dirty = false;
    Modal.close('roleModal');
  },

  updateCounters() {
    Counter.update('roleName', 60);
    Counter.update('roleDescription', 255);
  },

  /** Filters the category chips and tool rows in the modal (does not touch selections) */
  filterPickers(value) {
    const q = value.trim().toLowerCase();
    const input = document.getElementById('roleFilter');
    input.closest('.role-search').classList.toggle('has-value', q !== '');

    const apply = (sel, textOf, emptyHost) => {
      let visible = 0;
      document.querySelectorAll(sel).forEach(el => {
        const hit = !q || textOf(el).toLowerCase().includes(q);
        el.classList.toggle('is-filtered-out', !hit);
        if (hit) visible++;
      });
      let empty = emptyHost.querySelector('.role-picker-empty');
      if (q && !visible && document.querySelector(sel)) {
        if (!empty) {
          empty = document.createElement('div');
          empty.className = 'role-picker-empty';
          emptyHost.appendChild(empty);
        }
        empty.textContent = 'موردی پیدا نشد';
      } else if (empty) {
        empty.remove();
      }
    };
    apply('#roleBadgesGrid .access-badge-label', el => el.textContent, document.getElementById('roleBadgesGrid'));
    apply('#roleToolsList .access-tool-row', el => `${el.querySelector('.access-tool-title').textContent} ${el.dataset.badge}`,
      document.getElementById('roleToolsList'));
  },

  clearFilter() {
    const input = document.getElementById('roleFilter');
    input.value = '';
    this.filterPickers('');
    input.focus();
  },

  _selectedBadges() {
    return [...document.querySelectorAll('#roleBadgesGrid .access-badge-cb:checked')].map(c => c.value);
  },

  _renderBadges(selected) {
    const grid = document.getElementById('roleBadgesGrid');
    if (!this._badges.length) {
      grid.innerHTML = '<div class="role-card-none">هیچ دسته‌بندی‌ای وجود ندارد</div>';
      return;
    }
    grid.innerHTML = this._badges.map(b => `
      <label class="access-badge-label">
        <input type="checkbox" class="access-badge-cb" value="${esc(b)}" ${selected.includes(b) ? 'checked' : ''} data-change="roleBadgeChange">
        <span>${esc(b)}</span>
      </label>`).join('');
  },

  /** Tools list — a tool whose category is selected is locked as checked ("from category") */
  _renderTools(selectedIds) {
    const list = document.getElementById('roleToolsList');
    if (!TOOLS_RAW.length) {
      list.innerHTML = '<div class="role-card-none">هیچ ابزاری وجود ندارد</div>';
      this._updateSummaries();
      return;
    }
    list.innerHTML = TOOLS_RAW.map(t => `
      <div class="access-tool-row" data-badge="${esc(t.badge || '')}">
        <label class="access-tool-label">
          <input type="checkbox" class="access-tool-cb" value="${t.id}" ${selectedIds.includes(t.id) ? 'checked' : ''} data-change="roleToolChange">
          <span class="access-tool-info">
            <span class="access-tool-title">${esc(t.title || '')}</span>
            ${t.badge ? `<span class="access-tool-badge">${esc(t.badge)}</span>` : ''}
          </span>
        </label>
      </div>`).join('');
    this.syncToolLocks();
  },

  syncToolLocks() {
    const badges = this._selectedBadges();
    document.querySelectorAll('#roleToolsList .access-tool-row').forEach(row => {
      const inBadge = !!row.dataset.badge && badges.includes(row.dataset.badge);
      const cb      = row.querySelector('.access-tool-cb');
      const label   = row.querySelector('.access-tool-label');
      // Remember the explicit choice so unticking the category restores it
      if (inBadge && !cb.disabled) cb.dataset.explicit = cb.checked ? '1' : '0';
      if (!inBadge && cb.disabled)  cb.checked = cb.dataset.explicit === '1';
      if (inBadge) cb.checked = true;
      cb.disabled = inBadge;
      label.classList.toggle('disabled', inBadge);

      let status = row.querySelector('.access-status-badge');
      if (inBadge && !status) {
        status = document.createElement('span');
        status.className = 'access-status-badge from-badge';
        status.innerHTML = '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="3" stroke-linecap="round" stroke-linejoin="round"><polyline points="20 6 9 17 4 12"/></svg>از دسته';
        label.appendChild(status);
      } else if (!inBadge && status) {
        status.remove();
      }
    });
    this._updateSummaries();
  },

  _updateSummaries() {
    const total   = TOOLS_RAW.length;
    const reached = document.querySelectorAll('#roleToolsList .access-tool-cb:checked').length;
    document.getElementById('roleToolsSummary').textContent = `(اعضا ${reached} از ${total} ابزار را می‌بینند)`;
    document.getElementById('roleBadgesSummary').textContent =
      `(${this._selectedBadges().length} انتخاب شده — شامل ابزارهای بعدی هر دسته)`;
  },

  async save() {
    const id          = parseInt(document.getElementById('roleId').value, 10) || 0;
    const name        = document.getElementById('roleName').value.trim();
    const description = document.getElementById('roleDescription').value.trim();
    if (!name) return FieldErr.set('roleName', 'نام نقش الزامی است');

    const hiddenMenus = [];
    if (!document.getElementById('roleShowProfile').checked)       hiddenMenus.push('profile');
    if (!document.getElementById('roleShowNotifications').checked) hiddenMenus.push('notifications');

    const body = {
      id, name, description,
      badges:       this._selectedBadges(),
      // Tools locked "from category" are covered by the category grant — only explicit picks are stored
      tool_ids:     [...document.querySelectorAll('#roleToolsList .access-tool-cb:checked:not(:disabled)')].map(cb => parseInt(cb.value, 10)),
      hidden_menus: hiddenMenus,
    };

    const btn = document.getElementById('roleSaveBtn');
    btn.disabled = true;
    const res = await Api.call('save_access_role', body);
    btn.disabled = false;

    if (!res.ok) {
      const fieldId = { name: 'roleName', description: 'roleDescription' }[res.field];
      if (fieldId) FieldErr.set(fieldId, res.msg || 'خطا');
      else Toast.show(res.msg || 'خطا در ذخیره', 'error');
      return;
    }
    this.close(true);
    Toast.show(id ? 'نقش ویرایش شد' : 'نقش ساخته شد', 'success', 'ذخیره موفق');
    this.load();
  },

  // ── delete ────────────────────────────────────────────────
  openDelete(id) {
    const role = this._roles.find(r => r.id === id);
    if (!role || role.members.length) return;
    Confirm.show({
      title:    'حذف نقش',
      heading:  'آیا از حذف این نقش اطمینان دارید؟',
      body:     `نقش <span class="item-name">${esc(role.name)}</span> به‌طور دائم حذف خواهد شد.`,
      btnLabel: 'حذف نقش',
      onConfirm: async () => {
        const res = await Api.call('delete_access_role', { id });
        if (!res.ok) { Toast.show(res.msg || 'خطا در حذف', 'error'); return; }
        Confirm.close();
        Toast.show('نقش حذف شد', 'success', 'حذف موفق');
        this.load();
      },
    });
  },
};
window.RoleManager = RoleManager; // admin.js routes Escape / overlay clicks on #roleModal here

if (window.Actions) {
  Actions.register({
    roleOpenAdd:      () => RoleManager.openAdd(),
    roleOpenEdit:     (el) => RoleManager.openEdit(parseInt(el.dataset.id, 10)),
    roleOpenDelete:   (el) => RoleManager.openDelete(parseInt(el.dataset.id, 10)),
    roleClose:        () => RoleManager.close(),
    roleSave:         () => RoleManager.save(),
    roleCount:        () => RoleManager.updateCounters(),
    roleBadgeChange:  () => RoleManager.syncToolLocks(),
    roleToolChange:   () => RoleManager.syncToolLocks(),
    roleToggleChips:  (el) => RoleManager.toggleChips(el),
    roleSearch:       (el) => RoleManager.search(el.value),
    roleClearSearch:  () => RoleManager.clearSearch(),
    roleFilter:       (el) => RoleManager.filterPickers(el.value),
    roleClearFilter:  () => RoleManager.clearFilter(),
  });
}

document.addEventListener('DOMContentLoaded', () => {
  if (document.getElementById('roleList')) RoleManager.load();
});
