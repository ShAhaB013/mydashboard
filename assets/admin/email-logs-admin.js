'use strict';

// ═══════════════════════════════════════════════════════════
// MailLogsManager — outgoing email log (status chips + search + table + detail modal), page=email_logs
// Same building blocks and markup as LogsManager (logs-admin.js).
// ═══════════════════════════════════════════════════════════
const MailLogsManager = {
  _logs:         [],
  _page:         1,
  _perPage:      20,
  _status:       '',
  _search:       '',
  _dateFrom:     '',
  _dateTo:       '',
  _sortDir:      'desc',
  _total:        0,
  _pageCount:    1,
  _statusCounts: { sent: 0, failed: 0 },
  _totalLogs:    0,
  _loading:      false,
  _searchTimer:  null,

  _STATUS_LABEL: { sent: 'ارسال شده', failed: 'ناموفق' },
  _STATUS_ORDER: ['sent', 'failed'],
  _PURPOSE_LABEL: {
    reset:             'بازیابی رمز عبور',
    register:          'تایید ثبت‌نام',
    email_change:      'تایید تغییر ایمیل',
    credentials_new:   'اطلاعات ورود (کاربر جدید)',
    credentials_reset: 'اطلاعات ورود (بازنشانی)',
    credentials:       'اطلاعات ورود',
    test:              'ایمیل آزمایشی',
    test_credentials:  'نمونه اطلاعات ورود',
    other:             'سایر',
  },

  _purpose(p) { return this._PURPOSE_LABEL[p] || p || this._PURPOSE_LABEL.other; },

  async load(page = this._page) {
    if (this._loading) return;
    this._loading = true;
    this._page    = Math.max(1, page);
    const list = document.getElementById('logList');
    list.innerHTML = SKELETON_TABLE_ROW.repeat(6);
    Skeleton.mark(list);

    const res = await Api.call('list_email_logs', {
      page:      this._page,
      per_page:  this._perPage,
      status:    this._status,
      search:    this._search,
      date_from: this._dateFrom,
      date_to:   this._dateTo,
      sort_dir:  this._sortDir,
    });

    this._loading = false;
    await Skeleton.wait(list);
    if (!res.ok) {
      list.innerHTML = `<div class="log-empty">${esc(res.msg || 'خطا در بارگذاری')}</div>`;
      return;
    }

    this._logs         = res.logs || [];
    this._statusCounts = res.status_counts || this._statusCounts;
    this._totalLogs    = res.total_logs ?? this._totalLogs;
    const pg = res.pagination || {};
    this._total     = pg.total      ?? this._logs.length;
    this._pageCount = pg.page_count ?? 1;
    this._page      = pg.page       ?? this._page;

    if (!this._logs.length && this._page > 1) {
      return this.load(this._page - 1);
    }

    this._renderChips();
    this._render();
    this._syncEmptyState();
  },

  // ── while there are zero rows in the whole table, freeze every control ──
  _syncEmptyState() {
    const empty = this._totalLogs === 0;

    document.querySelectorAll('#logChips .log-chip').forEach(btn => {
      btn.disabled = empty;
      btn.setAttribute('aria-disabled', empty ? 'true' : 'false');
    });
    ['logSearchInput', 'logSearchClear', 'log-df', 'log-dt', 'logApplyBtn', 'logResetBtn'].forEach(id => {
      const el = document.getElementById(id);
      if (el) el.disabled = empty;
    });

    const advToggle = document.getElementById('logAdvToggle');
    if (advToggle) {
      advToggle.disabled = empty;
      advToggle.setAttribute('aria-disabled', empty ? 'true' : 'false');
      if (empty) {
        document.getElementById('logAdvPanel')?.classList.remove('open');
        advToggle.classList.remove('active');
        advToggle.setAttribute('aria-expanded', 'false');
      }
    }
  },

  // ── status filter chips (with live counts) ─────────────────
  _renderChips() {
    const wrap = document.getElementById('logChips');
    if (!wrap) return;
    const total = this._STATUS_ORDER.reduce((s, k) => s + (this._statusCounts[k] || 0), 0);

    const allChip = `<button type="button" class="log-chip all ${this._status === '' ? 'active' : ''}" data-act="mailLogChipClick" data-status="">
        <span class="log-chip-label">همه</span><span class="log-chip-count">${total.toLocaleString('en-US')}</span>
      </button>`;

    const chips = this._STATUS_ORDER.map(status => {
      const count = this._statusCounts[status] || 0;
      return `<button type="button" class="log-chip log-level ${status} ${this._status === status ? 'active' : ''}" data-act="mailLogChipClick" data-status="${status}">
          <span class="log-chip-label">${esc(this._STATUS_LABEL[status])}</span><span class="log-chip-count">${count.toLocaleString('en-US')}</span>
        </button>`;
    }).join('');

    wrap.innerHTML = allChip + chips;
  },

  // ── list rendering (table rows) ────────────────────────────
  _render() {
    const list     = document.getElementById('logList');
    const badge    = document.getElementById('logCountBadge');
    const clearBtn = document.getElementById('logClearBtn');
    const sortBtn  = document.getElementById('logSortTime');
    if (badge) badge.textContent = this._total;
    [clearBtn, sortBtn].forEach(btn => {
      if (!btn) return;
      btn.disabled = this._total === 0;
      btn.setAttribute('aria-disabled', this._total === 0 ? 'true' : 'false');
    });

    if (!this._logs.length) {
      const filtered = this._status || this._search || this._dateFrom || this._dateTo;
      list.innerHTML = `<div class="log-empty">${filtered ? 'ایمیلی با این مشخصات یافت نشد' : 'هنوز هیچ ایمیلی ارسال نشده است'}</div>`;
      this._renderPagination();
      return;
    }

    list.innerHTML = this._logs.map(l => this._row(l)).join('');
    this._renderPagination();
  },

  _row(l) {
    const failed = l.status === 'failed';
    const sub    = failed && l.error ? l.error : l.subject;
    return `
      <div class="log-table-row" data-act="mailLogOpenDetail" data-id="${l.id}">
        <span class="log-level ${esc(l.status)}">${esc(this._STATUS_LABEL[l.status] || l.status)}</span>
        <span class="log-table-time">${esc(DateFmt.dateTime(l.created_at))}</span>
        <span class="mail-log-purpose">${esc(this._purpose(l.purpose))}</span>
        <span class="mail-log-main">
          <span class="mail-log-to">${esc(l.recipient)}</span>
          <span class="mail-log-sub ${failed ? 'is-error' : ''}" title="${esc(sub)}">${esc(sub)}</span>
        </span>
        <button type="button" class="log-table-del" title="حذف" data-act="mailLogOpenDelete" data-id="${l.id}">
          <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
            <polyline points="3 6 5 6 21 6"/>
            <path d="M19 6l-1 14a2 2 0 01-2 2H8a2 2 0 01-2-2L5 6"/>
            <path d="M10 11v6M14 11v6M9 6V4a1 1 0 011-1h4a1 1 0 011 1v2"/>
          </svg>
        </button>
      </div>`;
  },

  // ── pagination (page-number based — same building blocks as LogsManager) ──
  _renderPagination() {
    const pag  = document.getElementById('logPagination');
    const info = document.getElementById('logPageInfo');
    if (!pag || !info) return;
    const total     = this._total;
    const pageCount = this._pageCount;
    const cur       = this._page;
    const shown     = this._logs.length;

    if (total === 0) {
      pag.style.display = 'none';
      pag.innerHTML = '';
      info.textContent = '';
      return;
    }

    const start = (cur - 1) * this._perPage;
    info.textContent = `نمایش ${start + 1} تا ${start + shown} از ${total} ایمیل`;

    if (pageCount <= 1) {
      pag.style.display = 'none';
      pag.innerHTML = '';
      return;
    }

    const items = [];
    items.push(`<button class="pagination-btn" ${cur === 1 ? 'aria-disabled="true"' : ''} data-act="mailLogGoToPage" data-page="${cur - 1}" aria-label="قبلی"><svg viewBox="0 0 24 24" width="14" height="14" fill="none" stroke="currentColor" stroke-width="2.5"><polyline points="9 18 15 12 9 6"/></svg></button>`);
    this._pageRange(cur, pageCount).forEach(p => {
      if (p === '...') {
        items.push(`<span class="pagination-ellipsis">…</span>`);
      } else {
        items.push(`<button class="pagination-btn ${p === cur ? 'active' : ''}" data-act="mailLogGoToPage" data-page="${p}">${p}</button>`);
      }
    });
    items.push(`<button class="pagination-btn" ${cur === pageCount ? 'aria-disabled="true"' : ''} data-act="mailLogGoToPage" data-page="${cur + 1}" aria-label="بعدی"><svg viewBox="0 0 24 24" width="14" height="14" fill="none" stroke="currentColor" stroke-width="2.5"><polyline points="15 18 9 12 15 6"/></svg></button>`);
    items.push(`
      <span class="pagination-goto">
        <label class="pagination-goto-label" for="logGotoInput">برو به صفحه</label>
        <span class="pagination-goto-field">
          <input type="number" id="logGotoInput" class="pagination-goto-input" min="1" max="${pageCount}"
            value="${cur}" aria-label="شماره صفحه" data-keydown="mailLogGoToInputKey">
          <span class="pagination-goto-stepper">
            <button type="button" class="pagination-goto-spin" tabindex="-1" aria-label="افزایش شماره صفحه"
              data-act="mailLogGoToStep" data-dir="1">
              <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="3"><polyline points="18 15 12 9 6 15"/></svg>
            </button>
            <button type="button" class="pagination-goto-spin" tabindex="-1" aria-label="کاهش شماره صفحه"
              data-act="mailLogGoToStep" data-dir="-1">
              <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="3"><polyline points="6 9 12 15 18 9"/></svg>
            </button>
          </span>
        </span>
      </span>`);

    pag.innerHTML = items.join('');
    pag.style.display = 'flex';
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

  goToInputKey(e) {
    if (e.key !== 'Enter') return;
    e.preventDefault();
    const n = parseInt(document.getElementById('logGotoInput')?.value, 10);
    if (Number.isFinite(n) && n >= 1) this.goToPage(n);
  },

  goToStep(dir) {
    const inp = document.getElementById('logGotoInput');
    if (!inp) return;
    const cur  = parseInt(inp.value, 10);
    const base = Number.isFinite(cur) ? cur : this._page;
    inp.value = Math.min(Math.max(1, base + dir), this._pageCount);
    this.goToPage(parseInt(inp.value, 10));
  },

  // ── status chips / sorting ────────────────────────────────
  chipClick(status) {
    if (status === this._status) return;
    this._status = status;
    this.load(1);
  },

  toggleSort() {
    this._sortDir = this._sortDir === 'asc' ? 'desc' : 'asc';
    const btn = document.getElementById('logSortTime');
    if (btn) {
      btn.classList.toggle('dir-asc', this._sortDir === 'asc');
      btn.classList.toggle('dir-desc', this._sortDir === 'desc');
    }
    this.load(1);
  },

  // ── advanced filter (date range) ──────────────────────────
  toggleAdvanced() {
    const panel = document.getElementById('logAdvPanel');
    const btn   = document.getElementById('logAdvToggle');
    if (!panel) return;
    const open = panel.classList.toggle('open');
    if (btn) { btn.classList.toggle('active', open); btn.setAttribute('aria-expanded', open ? 'true' : 'false'); }
  },

  applyFilters() {
    this._dateFrom = document.getElementById('log-df').value || '';
    this._dateTo   = document.getElementById('log-dt').value || '';
    this._syncAdvBtn();
    this.load(1);
  },

  resetFilters() {
    ['log-df', 'log-dt'].forEach(id => {
      const el = document.getElementById(id);
      el.value = '';
      if (window.ThemedDatePicker) ThemedDatePicker.refresh(el);
    });
    this._dateFrom = this._dateTo = '';
    this._syncAdvBtn();
    this.onDateFieldChange();
    this.load(1);
  },

  _syncAdvBtn() {
    document.getElementById('logAdvToggle')?.classList.toggle('has-filters', !!(this._dateFrom || this._dateTo));
  },

  /** Apply/Reset only make sense when at least one date field actually has a value. */
  onDateFieldChange() {
    const has = !!(document.getElementById('log-df')?.value || document.getElementById('log-dt')?.value);
    ['logApplyBtn', 'logResetBtn'].forEach(id => {
      const btn = document.getElementById(id);
      if (!btn) return;
      btn.disabled = !has;
      btn.setAttribute('aria-disabled', !has ? 'true' : 'false');
    });
  },

  // ── search (debounced) ───────────────────────────────────
  onSearchInput(value) {
    document.querySelector('.log-search')?.classList.toggle('has-value', value.trim() !== '');
    clearTimeout(this._searchTimer);
    this._searchTimer = setTimeout(() => {
      const v = value.trim();
      if (v === this._search) return;
      this._search = v;
      this.load(1);
    }, 350);
  },

  clearSearch() {
    const inp = document.getElementById('logSearchInput');
    if (inp) inp.value = '';
    document.querySelector('.log-search')?.classList.remove('has-value');
    if (this._search === '') return;
    this._search = '';
    this.load(1);
  },

  // ── detail (read-only) ───────────────────────────────────
  openDetail(id) {
    const log = this._logs.find(l => l.id === id);
    if (!log) return;
    const failed = log.status === 'failed';

    const badge = document.getElementById('logDetailLevel');
    badge.className   = `log-level ${esc(log.status)}`;
    badge.textContent = this._STATUS_LABEL[log.status] || log.status;
    document.getElementById('logDetailTime').textContent = DateFmt.dateTime(log.created_at);

    const msg = document.getElementById('logDetailMsg');
    msg.textContent = failed
      ? (log.error || 'ارسال ناموفق بود')
      : 'پیام با موفقیت به سرور ایمیل تحویل داده شد.';
    msg.classList.toggle('is-error', failed);

    const meta = [
      ['گیرنده',       log.recipient],
      ['موضوع',        log.subject],
      ['نوع ایمیل',    this._purpose(log.purpose)],
      ['سرور SMTP',    log.smtp_host],
      ['پاسخ سرور',    log.smtp_response],
      ['شناسه پیام',   log.message_id],
      ['مدت ارسال',    log.duration_ms != null ? `${log.duration_ms.toLocaleString('en-US')} میلی‌ثانیه` : ''],
      ['درخواست‌دهنده', log.user_id ? (log.username ? `${log.username} (#${log.user_id})` : `#${log.user_id}`) : 'مهمان'],
      ['آدرس IP',      log.ip],
    ].filter(([, v]) => v);

    document.getElementById('logDetailMeta').innerHTML = meta.map(([label, value]) => `
      <div class="log-detail-meta-item">
        <span class="log-detail-meta-label">${esc(label)}</span>
        <span class="log-detail-meta-value">${esc(value)}</span>
      </div>`).join('');

    document.getElementById('logDetailHint').hidden = failed;
    Modal.open('logDetailModal');
  },

  // ── delete / clear (shared Confirm modal) ─────────────────
  openDelete(id) {
    const log = this._logs.find(l => l.id === id);
    Confirm.show({
      title:    'حذف گزارش',
      heading:  'آیا از حذف این گزارش اطمینان دارید؟',
      body:     `گزارش ارسال ایمیل به <span class="item-name">${esc(log?.recipient || '')}</span> به‌طور دائم حذف خواهد شد.`,
      btnLabel: 'حذف گزارش',
      onConfirm: async () => {
        const res = await Api.call('delete_email_log', { id });
        if (!res.ok) { Toast.show(res.msg || 'خطا در حذف', 'error'); return; }
        Confirm.close();
        Toast.show('گزارش حذف شد', 'success', 'حذف موفق');
        this.load();
      },
    });
  },

  openClear() {
    // Clearing is scoped by status only (not search/date), so count what will really be deleted
    const count = this._status
      ? (this._statusCounts[this._status] || 0)
      : this._totalLogs;
    const scopeMsg = this._status
      ? `تمام گزارش‌های «<span class="item-name">${esc(this._STATUS_LABEL[this._status])}</span>» (${count} مورد)`
      : `تمام گزارش‌های ایمیل (<span class="item-name">${count} مورد</span>)`;
    Confirm.show({
      title:    'پاک‌سازی گزارش‌ها',
      heading:  'این گزارش‌ها حذف شوند؟',
      body:     `${scopeMsg} برای همیشه حذف خواهد شد.`,
      warn:     'این عملیات قابل بازگشت نیست.',
      btnLabel: 'پاک‌سازی',
      onConfirm: async () => {
        const res = await Api.call('clear_email_logs', { confirm: 1, status: this._status });
        if (!res.ok) { Toast.show(res.msg || 'خطا در پاک‌سازی', 'error'); return; }
        Confirm.close();
        Toast.show(`${res.deleted ?? 0} گزارش حذف شد`, 'success', 'پاک‌سازی موفق');
        this.load(1);
      },
    });
  },
};

if (window.Actions) {
  Actions.register({
    mailLogChipClick:       (el) => MailLogsManager.chipClick(el.dataset.status),
    mailLogSearch:          (el) => MailLogsManager.onSearchInput(el.value),
    mailLogClearSearch:     () => MailLogsManager.clearSearch(),
    mailLogGoToPage:        (el) => MailLogsManager.goToPage(parseInt(el.dataset.page, 10)),
    mailLogGoToInputKey:    (el, e) => MailLogsManager.goToInputKey(e),
    mailLogGoToStep:        (el) => MailLogsManager.goToStep(parseInt(el.dataset.dir, 10)),
    mailLogToggleSort:      () => MailLogsManager.toggleSort(),
    mailLogToggleAdvanced:  () => MailLogsManager.toggleAdvanced(),
    mailLogApplyFilters:    () => MailLogsManager.applyFilters(),
    mailLogResetFilters:    () => MailLogsManager.resetFilters(),
    mailLogDateFieldChange: () => MailLogsManager.onDateFieldChange(),
    mailLogOpenDetail:      (el) => MailLogsManager.openDetail(parseInt(el.dataset.id, 10)),
    mailLogOpenDelete:      (el, e) => { e.stopPropagation(); MailLogsManager.openDelete(parseInt(el.dataset.id, 10)); },
    mailLogOpenClear:       () => MailLogsManager.openClear(),
  });
}

document.addEventListener('DOMContentLoaded', () => {
  if (document.getElementById('logList')) MailLogsManager.load();
});
