'use strict';

// ═══════════════════════════════════════════════════════════
// LogsManager — error log viewer (chips + search + table + detail modal), page=logs
// ═══════════════════════════════════════════════════════════
const LogsManager = {
  _logs:        [],
  _page:        1,
  _perPage:     20,
  _level:       '',
  _search:      '',
  _dateFrom:    '',
  _dateTo:      '',
  _sortBy:      'created_at',
  _sortDir:     'desc',
  _total:       0,
  _pageCount:   1,
  _levelCounts: { error: 0, warning: 0, info: 0, debug: 0 },
  _totalLogs:   0,
  _loading:     false,
  _searchTimer: null,

  _LEVEL_LABEL: { error: 'خطا', warning: 'هشدار', info: 'اطلاع', debug: 'دیباگ' },
  _LEVEL_ORDER: ['error', 'warning', 'info', 'debug'],

  async load(page = this._page) {
    if (this._loading) return;
    this._loading = true;
    this._page    = Math.max(1, page);
    const list = document.getElementById('logList');
    list.innerHTML = SKELETON_TABLE_ROW.repeat(6);
    Skeleton.mark(list);

    const res = await Api.call('list_logs', {
      page:      this._page,
      per_page:  this._perPage,
      level:     this._level,
      search:    this._search,
      date_from: this._dateFrom,
      date_to:   this._dateTo,
      sort_by:   this._sortBy,
      sort_dir:  this._sortDir,
    });

    this._loading = false;
    await Skeleton.wait(list);
    if (!res.ok) {
      list.innerHTML = `<div class="log-empty">${esc(res.msg || 'خطا در بارگذاری')}</div>`;
      return;
    }

    this._logs = res.logs || [];
    this._levelCounts = res.level_counts || this._levelCounts;
    this._totalLogs = res.total_logs ?? this._totalLogs;
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

  // ── while there are zero logs in the whole system, freeze every control ──
  _syncEmptyState() {
    const empty = this._totalLogs === 0;

    document.querySelectorAll('#logChips .log-chip').forEach(btn => {
      btn.disabled = empty;
      btn.setAttribute('aria-disabled', empty ? 'true' : 'false');
    });

    const searchInput = document.getElementById('logSearchInput');
    if (searchInput) searchInput.disabled = empty;
    const searchClear = document.getElementById('logSearchClear');
    if (searchClear) searchClear.disabled = empty;

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

    ['log-df', 'log-dt', 'logApplyBtn', 'logResetBtn'].forEach(id => {
      const el = document.getElementById(id);
      if (el) el.disabled = empty;
    });
  },

  // ── level filter chips (with live counts) ─────────────────
  _renderChips() {
    const wrap = document.getElementById('logChips');
    if (!wrap) return;
    const total = this._LEVEL_ORDER.reduce((s, l) => s + (this._levelCounts[l] || 0), 0);

    const allChip = `<button type="button" class="log-chip all ${this._level === '' ? 'active' : ''}" data-act="logChipClick" data-level="">
        <span class="log-chip-label">همه</span><span class="log-chip-count">${total.toLocaleString('en-US')}</span>
      </button>`;

    const chips = this._LEVEL_ORDER.map(level => {
      const count = this._levelCounts[level] || 0;
      return `<button type="button" class="log-chip log-level ${level} ${this._level === level ? 'active' : ''}" data-act="logChipClick" data-level="${level}">
          <span class="log-chip-label">${esc(this._LEVEL_LABEL[level])}</span><span class="log-chip-count">${count.toLocaleString('en-US')}</span>
        </button>`;
    }).join('');

    wrap.innerHTML = allChip + chips;
  },

  // ── list rendering (table rows) ────────────────────────────
  _render() {
    const list     = document.getElementById('logList');
    const badge    = document.getElementById('logCountBadge');
    const clearBtn = document.getElementById('logClearBtn');
    if (badge) badge.textContent = this._total;
    // Nothing to clear for the current filter/search scope — matches what
    // openClear() would actually delete, so it never opens an empty-op confirm.
    if (clearBtn) {
      clearBtn.disabled = this._total === 0;
      clearBtn.setAttribute('aria-disabled', this._total === 0 ? 'true' : 'false');
    }
    // Nothing to sort when the current result set is empty
    ['logSortLevel', 'logSortTime'].forEach(id => {
      const btn = document.getElementById(id);
      if (!btn) return;
      btn.disabled = this._total === 0;
      btn.setAttribute('aria-disabled', this._total === 0 ? 'true' : 'false');
    });

    if (!this._logs.length) {
      const msg = (this._level || this._search) ? 'لاگی با این مشخصات یافت نشد' : 'هیچ لاگی ثبت نشده';
      list.innerHTML = `<div class="log-empty">${esc(msg)}</div>`;
      this._renderPagination();
      return;
    }

    list.innerHTML = this._logs.map(l => this._row(l)).join('');
    this._renderPagination();
  },

  _row(l) {
    const label = this._LEVEL_LABEL[l.level] || l.level;
    return `
      <div class="log-table-row" data-act="logOpenDetail" data-id="${l.id}">
        <span class="log-level ${esc(l.level)}">${esc(label)}</span>
        <span class="log-table-time">${esc(DateFmt.dateTime(l.created_at))}</span>
        <span class="log-table-msg" title="${esc(l.message)}">${esc(l.message)}</span>
        <button type="button" class="log-table-del" title="حذف" data-act="logOpenDelete" data-id="${l.id}">
          <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round">
            <polyline points="3 6 5 6 21 6"/>
            <path d="M19 6l-1 14a2 2 0 01-2 2H8a2 2 0 01-2-2L5 6"/>
          </svg>
        </button>
      </div>`;
  },

  // ── pagination (page-number based — same building blocks as UserManager) ──
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
    info.textContent = `نمایش ${start + 1} تا ${start + shown} از ${total} لاگ`;

    if (pageCount <= 1) {
      pag.style.display = 'none';
      pag.innerHTML = '';
      return;
    }

    const items = [];
    items.push(`<button class="pagination-btn" ${cur === 1 ? 'aria-disabled="true"' : ''} data-act="logGoToPage" data-page="${cur - 1}" aria-label="قبلی"><svg viewBox="0 0 24 24" width="14" height="14" fill="none" stroke="currentColor" stroke-width="2.5"><polyline points="9 18 15 12 9 6"/></svg></button>`);
    this._pageRange(cur, pageCount).forEach(p => {
      if (p === '...') {
        items.push(`<span class="pagination-ellipsis">…</span>`);
      } else {
        items.push(`<button class="pagination-btn ${p === cur ? 'active' : ''}" data-act="logGoToPage" data-page="${p}">${p}</button>`);
      }
    });
    items.push(`<button class="pagination-btn" ${cur === pageCount ? 'aria-disabled="true"' : ''} data-act="logGoToPage" data-page="${cur + 1}" aria-label="بعدی"><svg viewBox="0 0 24 24" width="14" height="14" fill="none" stroke="currentColor" stroke-width="2.5"><polyline points="15 18 9 12 15 6"/></svg></button>`);
    items.push(`
      <span class="pagination-goto">
        <label class="pagination-goto-label" for="logGotoInput">برو به صفحه</label>
        <span class="pagination-goto-field">
          <input type="number" id="logGotoInput" class="pagination-goto-input" min="1" max="${pageCount}"
            value="${cur}" aria-label="شماره صفحه" data-keydown="logGoToInputKey">
          <span class="pagination-goto-stepper">
            <button type="button" class="pagination-goto-spin" tabindex="-1" aria-label="افزایش شماره صفحه"
              data-act="logGoToStep" data-dir="1">
              <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="3"><polyline points="18 15 12 9 6 15"/></svg>
            </button>
            <button type="button" class="pagination-goto-spin" tabindex="-1" aria-label="کاهش شماره صفحه"
              data-act="logGoToStep" data-dir="-1">
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

  goToInputValue() {
    const inp = document.getElementById('logGotoInput');
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
    const inp = document.getElementById('logGotoInput');
    if (!inp) return;
    const cur = parseInt(inp.value, 10);
    const base = Number.isFinite(cur) ? cur : this._page;
    const n = base + dir;
    inp.value = Math.min(Math.max(1, n), this._pageCount);
    this.goToInputValue();
  },

  // ── level chips ─────────────────────────────────────────
  chipClick(level) {
    if (level === this._level) return;
    this._level = level;
    this.load(1);
  },

  // ── sorting (table header) ────────────────────────────────
  sortBy(key) {
    if (key === this._sortBy) {
      this._sortDir = this._sortDir === 'asc' ? 'desc' : 'asc';
    } else {
      this._sortBy  = key;
      this._sortDir = 'desc';
    }
    this._syncSortButtons();
    this.load(1);
  },

  _syncSortButtons() {
    const map = { created_at: 'logSortTime', level: 'logSortLevel' };
    Object.entries(map).forEach(([key, id]) => {
      const btn = document.getElementById(id);
      if (!btn) return;
      const active = key === this._sortBy;
      btn.classList.toggle('active', active);
      btn.classList.toggle('dir-asc', active && this._sortDir === 'asc');
      btn.classList.toggle('dir-desc', active && this._sortDir === 'desc');
    });
  },

  // ── advanced filter (date range — same pattern as the notifications page) ──
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
    document.getElementById('log-df').value = '';
    document.getElementById('log-dt').value = '';
    if (window.ThemedDatePicker) {
      ThemedDatePicker.refresh(document.getElementById('log-df'));
      ThemedDatePicker.refresh(document.getElementById('log-dt'));
    }
    this._dateFrom = this._dateTo = '';
    this._syncAdvBtn();
    this._syncDateButtons();
    this.load(1);
  },

  _syncAdvBtn() {
    const has = !!(this._dateFrom || this._dateTo);
    const btn = document.getElementById('logAdvToggle');
    if (btn) btn.classList.toggle('has-filters', has);
  },

  /** Apply/Reset only make sense when at least one date field actually has a value. */
  onDateFieldChange() {
    this._syncDateButtons();
  },

  _syncDateButtons() {
    const df = document.getElementById('log-df')?.value || '';
    const dt = document.getElementById('log-dt')?.value || '';
    const has = !!(df || dt);
    const applyBtn = document.getElementById('logApplyBtn');
    const resetBtn = document.getElementById('logResetBtn');
    [applyBtn, resetBtn].forEach(btn => {
      if (!btn) return;
      btn.disabled = !has;
      btn.setAttribute('aria-disabled', !has ? 'true' : 'false');
    });
  },

  // ── search (debounced) ───────────────────────────────────
  onSearchInput(value) {
    const wrap = document.querySelector('.log-search');
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
    const inp  = document.getElementById('logSearchInput');
    const wrap = document.querySelector('.log-search');
    if (inp)  inp.value = '';
    if (wrap) wrap.classList.remove('has-value');
    if (this._search === '') return;
    this._search = '';
    this.load(1);
  },

  // ── debug mode toggle ────────────────────────────────────
  async debugModeChange(checked) {
    const res = await Api.call('save_debug_mode', { debug_mode: checked ? 1 : 0 });
    if (!res.ok) {
      Toast.show(res.msg || 'خطا در ذخیره', 'error');
      document.getElementById('setDebugMode').checked = !checked; // revert on failure
      return;
    }
    Toast.show(checked ? 'حالت دیباگ فعال شد' : 'حالت دیباگ غیرفعال شد', 'success');
  },

  // ── detail (read-only) ───────────────────────────────────
  _META_LABELS: {
    file:            'فایل',
    line:            'خط',
    request:         'درخواست',
    user_id:         'شناسه کاربر',
    ip:              'آدرس IP',
    category:        'دسته',
    sqlstate:        'کد SQLSTATE',
    driver_code:     'کد خطای درایور',
    driver_message:  'پیام درایور',
    sql:             'کوئری',
    recovered:       'وضعیت ثبت',
  },

  openDetail(id) {
    const log = this._logs.find(l => l.id === id);
    if (!log) return;
    const label = this._LEVEL_LABEL[log.level] || log.level;
    const levelEl = document.getElementById('logDetailLevel');
    levelEl.className   = `log-level ${esc(log.level)}`;
    levelEl.textContent = label;
    document.getElementById('logDetailTime').textContent = DateFmt.dateTime(log.created_at);
    document.getElementById('logDetailMsg').textContent  = log.message;

    const ctx  = log.context || {};
    const meta = [];
    if (ctx.category) meta.push(['category', ctx.category === 'database' ? 'پایگاه‌داده' : ctx.category]);
    if (ctx.file) meta.push(['file', ctx.file + (ctx.line ? ':' + ctx.line : '')]);
    if (ctx.sqlstate) meta.push(['sqlstate', ctx.sqlstate]);
    if (ctx.driver_code) meta.push(['driver_code', ctx.driver_code]);
    if (ctx.driver_message) meta.push(['driver_message', ctx.driver_message]);
    if (ctx.sql) meta.push(['sql', ctx.sql]);
    if (ctx.recovered_from_fallback) {
      meta.push(['recovered', 'با تاخیر ثبت شد — به‌دلیل قطعی موقت پایگاه‌داده' + (ctx.fallback_reason ? ' (' + ctx.fallback_reason + ')' : '')]);
    }
    if (ctx.request_method || ctx.request_uri) meta.push(['request', [ctx.request_method, ctx.request_uri].filter(Boolean).join(' ')]);
    if (ctx.user_id) meta.push(['user_id', '#' + ctx.user_id]);
    if (ctx.ip) meta.push(['ip', ctx.ip]);

    const metaWrap = document.getElementById('logDetailMeta');
    metaWrap.innerHTML = meta.map(([key, value]) => `
      <div class="log-detail-meta-item">
        <span class="log-detail-meta-label">${esc(this._META_LABELS[key] || key)}</span>
        <span class="log-detail-meta-value">${esc(value)}</span>
      </div>`).join('');

    const traceBlock = document.getElementById('logDetailTraceBlock');
    const traceBtn    = document.querySelector('#logDetailTraceBlock .log-detail-trace-btn');
    const tracePre    = document.getElementById('logDetailTrace');
    if (ctx.trace) {
      traceBlock.hidden = false;
      tracePre.textContent = Array.isArray(ctx.trace) ? ctx.trace.join('\n') : String(ctx.trace);
      tracePre.hidden = true;
      traceBtn.classList.remove('open');
    } else {
      traceBlock.hidden = true;
    }

    Modal.open('logDetailModal');
  },

  toggleTrace() {
    const btn = document.querySelector('#logDetailTraceBlock .log-detail-trace-btn');
    const pre = document.getElementById('logDetailTrace');
    const open = pre.hidden;
    pre.hidden = !open;
    btn.classList.toggle('open', open);
  },

  // ── delete / clear (shared Confirm modal) ─────────────────
  openDelete(id) {
    Confirm.show({
      title:    'حذف لاگ',
      heading:  'آیا از حذف این لاگ اطمینان دارید؟',
      body:     'این لاگ به‌طور دائم حذف خواهد شد.',
      btnLabel: 'حذف لاگ',
      onConfirm: async () => {
        const res = await Api.call('delete_log', { id });
        if (!res.ok) { Toast.show(res.msg || 'خطا در حذف', 'error'); return; }
        Confirm.close();
        Toast.show('لاگ حذف شد', 'success', 'حذف موفق');
        this.load();
      },
    });
  },

  openClear() {
    const scopeMsg = this._level
      ? `تمام لاگ‌های سطح «<span class="item-name">${esc(this._LEVEL_LABEL[this._level] || this._level)}</span>» (${this._total} مورد)`
      : `تمام لاگ‌ها (<span class="item-name">${this._total} مورد</span>)`;
    Confirm.show({
      title:    'پاک‌سازی لاگ‌ها',
      heading:  'این لاگ‌ها حذف شوند؟',
      body:     `${scopeMsg} برای همیشه حذف خواهد شد.`,
      warn:     'این عملیات قابل بازگشت نیست.',
      btnLabel: 'پاک‌سازی',
      onConfirm: async () => {
        const res = await Api.call('clear_logs', { confirm: 1, level: this._level });
        if (!res.ok) { Toast.show(res.msg || 'خطا در پاک‌سازی', 'error'); return; }
        Confirm.close();
        Toast.show(`${res.deleted ?? 0} لاگ حذف شد`, 'success', 'پاک‌سازی موفق');
        this.load(1);
      },
    });
  },
};

if (window.Actions) {
  Actions.register({
    logChipClick:        (el) => LogsManager.chipClick(el.dataset.level),
    logSearch:            (el) => LogsManager.onSearchInput(el.value),
    logClearSearch:       () => LogsManager.clearSearch(),
    logGoToPage:          (el) => LogsManager.goToPage(parseInt(el.dataset.page, 10)),
    logGoToInputKey:      (el, e) => LogsManager.goToInputKey(e),
    logGoToStep:          (el) => LogsManager.goToStep(parseInt(el.dataset.dir, 10)),
    logSortBy:            (el) => LogsManager.sortBy(el.dataset.key),
    logToggleAdvanced:    () => LogsManager.toggleAdvanced(),
    logApplyFilters:      () => LogsManager.applyFilters(),
    logResetFilters:      () => LogsManager.resetFilters(),
    logDateFieldChange:   () => LogsManager.onDateFieldChange(),
    logOpenDetail:        (el) => LogsManager.openDetail(parseInt(el.dataset.id, 10)),
    logOpenDelete:        (el, e) => { e.stopPropagation(); LogsManager.openDelete(parseInt(el.dataset.id, 10)); },
    logOpenClear:         () => LogsManager.openClear(),
    logDebugModeChange:   (el) => LogsManager.debugModeChange(el.checked),
    logToggleTrace:       () => LogsManager.toggleTrace(),
  });
}

document.addEventListener('DOMContentLoaded', () => {
  if (document.getElementById('logList')) LogsManager.load();
});
