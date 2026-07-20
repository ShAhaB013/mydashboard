'use strict';

// Toast — colored icon + title + description + close button (same structure as dashboard/login/admin panel)
// Toast.show(message, type, title?) — type: success | error | warning | info
const Toast = {
  _t: null,
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
    clearTimeout(this._t);
    el.className = `toast ${type}`;
    el.innerHTML =
      `<span class="toast-icon"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round">${this._ICON[type]}</svg></span>`
      + '<div class="toast-body"><strong class="toast-title"></strong><span class="toast-text"></span></div>'
      + '<button type="button" class="toast-close" aria-label="بستن"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round"><line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/></svg></button>'
      + `<span class="toast-progress" style="animation-duration:${this._DURATION}ms"></span>`;
    el.querySelector('.toast-title').textContent = title || this._TITLE[type];
    el.querySelector('.toast-text').textContent  = msg;
    el.querySelector('.toast-close').addEventListener('click', () => { clearTimeout(this._t); el.classList.remove('show'); });
    requestAnimationFrame(() => el.classList.add('show'));
    this._t = setTimeout(() => el.classList.remove('show'), this._DURATION);
  },
};

async function apiCall(action, body = {}) {
  try {
    const res = await fetch(`admin.php?api=${action}`, {
      method:  'POST',
      headers: { 'Content-Type': 'application/json', 'X-CSRF-Token': CSRF_TOKEN },
      body:    JSON.stringify(body),
    });
    return await res.json();
  } catch {
    return { ok: false, msg: 'خطا در ارتباط با سرور' };
  }
}

// ═══════════════════════════════════════════════════════════
// RTE — rich text editor for the notification body
// ═══════════════════════════════════════════════════════════
const RTE = {
  MAX_CHARS: 20000,
  _el: null,
  _savedRange: null,
  _lastColor: '#e11d48',
  _colorMarker: null,

  init() {
    this._el = document.getElementById('nf-body');
    if (!this._el) return;

    // command buttons (execCommand)
    document.querySelectorAll('#rteToolbar .rte-btn[data-cmd]').forEach(btn => {
      btn.addEventListener('mousedown', e => e.preventDefault()); // preserve the selection
      btn.addEventListener('click', () => {
        this._el.focus();
        // make structural commands (bold/italic/underline/lists/...) produce clean tags
        try { document.execCommand('styleWithCSS', false, false); } catch {}
        document.execCommand(btn.dataset.cmd, false, null);
        this._sync();
      });
    });

    // direction buttons (RTL/LTR)
    document.querySelectorAll('#rteToolbar .rte-btn[data-dir]').forEach(btn => {
      btn.addEventListener('mousedown', e => e.preventDefault());
      btn.addEventListener('click', () => {
        this._el.focus();
        this._applyDir(btn.dataset.dir);
        this._sync();
      });
    });

    // ── native color picker ──
    this._initColorInput();

    // save the selection on every change inside the editor
    this._el.addEventListener('keyup',   () => this._saveSelection());
    this._el.addEventListener('mouseup', () => this._saveSelection());
    // the most reliable way: save on any page selection change that falls inside the editor
    document.addEventListener('selectionchange', () => {
      const sel = window.getSelection();
      if (sel && sel.rangeCount && this._el &&
          this._el.contains(sel.getRangeAt(0).commonAncestorContainer) &&
          !sel.getRangeAt(0).collapsed) {
        this._savedRange = sel.getRangeAt(0).cloneRange();
      }
    });

    // input events
    this._el.addEventListener('input',  () => this._sync());
    this._el.addEventListener('keyup',  () => this._updateActive());
    this._el.addEventListener('mouseup',() => this._updateActive());

    // shortcuts
    this._el.addEventListener('keydown', e => {
      if (e.ctrlKey || e.metaKey) {
        const k = e.key.toLowerCase();
        if (k === 'b' || k === 'i' || k === 'u') setTimeout(() => this._sync(), 0);
      }
    });

    // starting any new selection in the editor → finalize a leftover unfinalized color marker
    // (otherwise, if the color dialog was closed without confirming, the next color lands on the old text)
    this._el.addEventListener('mousedown', () => { if (this._colorMarker) this._finalizeColorTarget(); });
    this._el.addEventListener('keydown',   () => { if (this._colorMarker) this._finalizeColorTarget(); });

    // paste as plain text so unwanted markup doesn't get pulled in
    this._el.addEventListener('paste', e => {
      e.preventDefault();
      const text = (e.clipboardData || window.clipboardData).getData('text/plain');
      document.execCommand('insertText', false, text);
    });

    this._sync();
  },

  // ── simple native color picker (with correct text-selection preservation) ──
  _initColorInput() {
    const input  = document.getElementById('rteColor');
    const swatch = document.getElementById('rteColorSwatch');
    if (!input) return;

    this._lastColor = this._lastColor || '#e11d48';
    input.value = this._lastColor;
    if (swatch) swatch.style.background = this._lastColor;

    // right before the color dialog opens: wrap the text selection in a temporary span
    // both events are covered; the anti-duplication guard lives in _markColorTarget
    const startMark = () => { this._saveSelection(); this._markColorTarget(); };
    input.addEventListener('pointerdown', startMark);
    input.addEventListener('mousedown',  startMark);
    input.addEventListener('click',      startMark);

    // while dragging in the dialog, the color is applied to that same marked range
    const apply = () => {
      if (swatch) swatch.style.background = input.value;
      this._lastColor = input.value;
      this._colorMarkedTarget(input.value);
      this._sync();
    };
    input.addEventListener('input',  apply);
    input.addEventListener('change', () => {
      apply();
      // finalize the color span (with a slight delay so blur doesn't interfere)
      setTimeout(() => this._finalizeColorTarget(), 0);
    });
  },

  /* marks the selected range with a span so it survives the color change */
  _markColorTarget() {
    // if a marker already exists (e.g. the event fired twice), don't recreate it
    if (this._colorMarker && this._colorMarker.isConnected) return;
    this._colorMarker = null;

    let range = this._savedRange;
    if (!range) {
      const sel = window.getSelection();
      if (sel && sel.rangeCount) range = sel.getRangeAt(0);
    }
    if (!range || range.collapsed) return;
    if (!this._el.contains(range.commonAncestorContainer)) return;

    const span = document.createElement('span');
    span.setAttribute('data-color-marker', '1');
    try {
      range.surroundContents(span);
      this._colorMarker = span;
    } catch {
      try {
        const frag = range.extractContents();
        span.appendChild(frag);
        range.insertNode(span);
        this._colorMarker = span;
      } catch { this._colorMarker = null; }
    }
  },

  /* applies the color to the marked span's content (no selection required) */
  _colorMarkedTarget(hex) {
    if (this._colorMarker && this._colorMarker.isConnected) {
      this._colorMarker.style.color = hex;
    }
  },

  /* finalizes the color span (removes the temporary marker, the color stays) */
  _finalizeColorTarget() {
    const span = this._colorMarker;
    this._colorMarker = null;
    if (!span || !span.isConnected) return;
    if (span.style.color) {
      span.removeAttribute('data-color-marker');
    } else {
      // no color was applied → unwrap the span
      const parent = span.parentNode;
      while (span.firstChild) parent.insertBefore(span.firstChild, span);
      parent.removeChild(span);
      if (parent.normalize) parent.normalize();
    }
  },

  _applyDir(dir) {
    // apply the direction to the current block
    let node = window.getSelection().anchorNode;
    if (!node) return;
    if (node.nodeType === 3) node = node.parentNode;
    // the nearest block inside the editor
    let block = node;
    while (block && block !== this._el && !/^(P|DIV|LI|UL|OL|H[1-6])$/.test(block.tagName)) {
      block = block.parentNode;
    }
    if (!block || block === this._el) {
      // if there's no specific block, set the whole editor
      this._el.setAttribute('dir', dir);
      this._el.style.textAlign = dir === 'rtl' ? 'right' : 'left';
    } else {
      block.setAttribute('dir', dir);
    }
  },

  _sync() {
    this._updateCounter();
    this._updateActive();
  },

  // ── save/restore text selection (for the color picker, which steals focus) ──
  _saveSelection() {
    const sel = window.getSelection();
    if (!sel || sel.rangeCount === 0) return;
    const range = sel.getRangeAt(0);
    // only save if the selection is inside the editor
    if (this._el && this._el.contains(range.commonAncestorContainer)) {
      this._savedRange = range.cloneRange();
    }
  },

  _restoreSelection() {
    if (!this._savedRange) return;
    const sel = window.getSelection();
    sel.removeAllRanges();
    sel.addRange(this._savedRange);
  },

  _updateActive() {
    document.querySelectorAll('#rteToolbar .rte-btn[data-cmd]').forEach(btn => {
      const cmd = btn.dataset.cmd;
      let on = false;
      try { on = document.queryCommandState(cmd); } catch {}
      btn.classList.toggle('active', !!on);
    });
  },

  _updateCounter() {
    const len  = this.plainLength();
    const cEl  = document.getElementById('rteCount');
    const wrap = document.getElementById('rteCounter');
    if (cEl)  cEl.textContent = len.toLocaleString('en-US');
    if (wrap) wrap.classList.toggle('over', len > this.MAX_CHARS);
  },

  plainLength() {
    if (!this._el) return 0;
    // visible text length (without tags)
    return (this._el.innerText || this._el.textContent || '').trim().length;
  },

  setHTML(html) {
    if (!this._el) this._el = document.getElementById('nf-body');
    this._el.innerHTML = RTE.sanitize(html || '');
    this._sync();
  },

  getHTML() {
    if (!this._el) return '';
    // if it's only whitespace, return an empty string
    if (this.plainLength() === 0 && !/<(img|br|hr)/i.test(this._el.innerHTML)) return '';
    return RTE.sanitize(this._el.innerHTML);
  },

  // sanitize HTML (kept in sync with the server side)
  sanitize(html) {
    const ALLOWED_TAGS  = ['B','STRONG','I','EM','U','BR','P','DIV','SPAN','UL','OL','LI','A','FONT'];
    const ALLOWED_ATTRS = ['style','dir','href','target','rel','color','align'];
    const ALLOWED_CSS   = ['text-align','color','background-color','font-weight','font-style','text-decoration','direction'];
    const tpl = document.createElement('template');
    tpl.innerHTML = String(html ?? '');

    const walk = node => {
      [...node.childNodes].forEach(child => {
        if (child.nodeType === 1) {
          if (!ALLOWED_TAGS.includes(child.tagName)) {
            child.replaceWith(document.createTextNode(child.textContent || ''));
            return;
          }
          [...child.attributes].forEach(attr => {
            const name = attr.name.toLowerCase();
            if (!ALLOWED_ATTRS.includes(name)) { child.removeAttribute(attr.name); return; }
            if (name === 'style') {
              const safe = [];
              (child.getAttribute('style') || '').split(';').forEach(decl => {
                const idx = decl.indexOf(':');
                if (idx < 0) return;
                const k = decl.slice(0, idx).trim().toLowerCase();
                const v = decl.slice(idx + 1).trim();
                if (!k || !v) return;
                if (/url\(|expression|javascript:/i.test(v)) return;
                if (ALLOWED_CSS.includes(k)) safe.push(`${k}:${v}`);
              });
              if (safe.length) child.setAttribute('style', safe.join(';'));
              else child.removeAttribute('style');
            }
            if (name === 'href') {
              const v = (child.getAttribute('href') || '').trim();
              if (!/^(https?:|mailto:|\/)/i.test(v)) child.removeAttribute('href');
            }
          });
          walk(child);
        } else if (child.nodeType !== 3) {
          child.remove();
        }
      });
    };
    walk(tpl.content);
    return tpl.innerHTML;
  },
};

const NM = {
  _notifications: [],
  _dirty:         false,
  _editId:        null,
  _pendingImage:  null,
  _pendingThumb:  null,
  _existingImage: null,
  _existingThumb: null,
  _xhr:           null,
  _previewURL:    null,
  _deleteId:      null,
  _page:          1,
  _perPage:       10,
  _search:        '',
  _fDateFrom:     '',
  _fDateTo:       '',
  _fStatus:       '',
  _total:         0,
  _pageCount:     1,
  _nextCursor:    null,
  _prevCursor:    null,
  _loading:       false,
  _searchTimer:   null,

  _renderSkeleton(n = 3) {
    const list = document.getElementById('notifList');
    if (!list) return;
    const row = `
      <div class="notif-row notif-skeleton" aria-hidden="true">
        <div class="notif-row-num"><div class="sk sk-num"></div></div>
        <div class="notif-row-body">
          <div class="sk sk-line sk-line--title"></div>
          <div class="sk sk-line sk-line--text"></div>
          <div class="notif-row-meta">
            <div class="sk sk-pill"></div>
            <div class="sk sk-pill"></div>
            <div class="sk sk-pill sk-pill--wide"></div>
          </div>
        </div>
        <div class="notif-row-actions">
          <div class="sk sk-action"></div>
          <div class="sk sk-action"></div>
        </div>
      </div>`;
    list.innerHTML = row.repeat(n);
  },

  async load(page = this._page) {
    if (this._loading) return;
    this._loading = true;
    this._setPagLoading(true);
    this._page    = Math.max(1, page);
    this._renderSkeleton();

    const res = await apiCall('list_notifications', {
      page:      this._page,
      per_page:  this._perPage,
      search:    this._search,
      date_from: this._fDateFrom,
      date_to:   this._fDateTo,
      status:    this._fStatus,
    });

    this._loading = false;
    if (!res.ok) { this._setPagLoading(false); Toast.show(res.msg || 'خطا در بارگذاری', 'error'); return; }

    this._notifications = res.notifications || [];
    const pg = res.pagination || {};
    this._total       = pg.total       ?? this._notifications.length;
    this._pageCount    = pg.page_count  ?? 1;
    this._page         = pg.page        ?? this._page;
    this._nextCursor   = pg.next_cursor ?? null;
    this._prevCursor    = pg.prev_cursor ?? null;

    // if the current page ended up empty (e.g. after deleting the page's last item), go back a page
    if (!this._notifications.length && this._page > 1) {
      return this.load(this._page - 1);
    }

    this._render();
  },

  /** adjacent Prev/Next arrow navigation via cursor (keyset) — fast at any depth, unlike load(page) which uses OFFSET */
  async loadCursor(cursor, dir) {
    if (this._loading || !cursor) return;
    this._loading = true;
    this._setPagLoading(true);
    this._renderSkeleton();

    // cursor nav always moves exactly one page, so the page number can be
    // tracked locally for display (goto-field / active state) without an OFFSET query
    const nextPage = this._page ? this._page + (dir === 'next' ? 1 : -1) : null;

    const res = await apiCall('list_notifications', {
      cursor,
      dir,
      per_page:  this._perPage,
      search:    this._search,
      date_from: this._fDateFrom,
      date_to:   this._fDateTo,
      status:    this._fStatus,
    });

    this._loading = false;
    if (!res.ok) { this._setPagLoading(false); Toast.show(res.msg || 'خطا در بارگذاری', 'error'); return; }

    this._notifications = res.notifications || [];
    const pg = res.pagination || {};
    this._total       = pg.total       ?? this._notifications.length;
    this._pageCount    = pg.page_count  ?? 1;
    this._page         = nextPage;
    this._nextCursor   = pg.next_cursor ?? null;
    this._prevCursor    = pg.prev_cursor ?? null;

    this._render();
    window.scrollTo({ top: 0, behavior: 'smooth' });
  },

  _render() {
    const list  = document.getElementById('notifList');
    const badge = document.getElementById('notifCountBadge');
    badge.textContent = this._total;

    if (!this._notifications.length) {
      const emptyMsg = this._search
        ? 'نتیجه‌ای برای جستجوی شما یافت نشد'
        : 'هیچ اعلانی ثبت نشده است';
      list.innerHTML = `
        <div class="notif-empty">
          <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5">
            <path d="M18 8A6 6 0 0 0 6 8c0 7-3 9-3 9h18s-3-2-3-9"/>
            <path d="M13.73 21a2 2 0 0 1-3.46 0"/>
          </svg>
          <p>${this._esc(emptyMsg)}</p>
        </div>`;
      this._renderPagination();
      return;
    }

    list.innerHTML = '';
    const frag = document.createDocumentFragment();
    this._notifications.forEach((n, i) => frag.appendChild(this._makeRow(n, i)));
    list.appendChild(frag);

    this._renderPagination();
  },

  // marks the pagination controls as busy/inert while a page or cursor fetch is in
  // flight, so a click during that window is visibly ignored instead of silently
  // dropped by the _loading guard (which otherwise reads as pagination "randomly" jumping)
  _setPagLoading(on) {
    document.getElementById('notifPagination')?.classList.toggle('pagination-loading', on);
  },

  // ── Pagination (server-side, hybrid cursor+OFFSET) ────────
  // Adjacent Prev/Next arrows use the cursor (fast at any depth); the page number and
  // "go to page" use page=N (OFFSET) — per the project's hybrid design decision.
  _renderPagination() {
    this._setPagLoading(false);
    const pag  = document.getElementById('notifPagination');
    const info = document.getElementById('notifPageInfo');
    const total     = this._total;
    const pageCount = this._pageCount;
    const cur       = this._page;
    const shown     = this._notifications.length;

    if (total === 0) {
      pag.classList.add('hidden');
      pag.innerHTML = '';
      info.textContent = '';
      return;
    }

    info.textContent = cur
      ? `نمایش ${(cur - 1) * this._perPage + 1} تا ${(cur - 1) * this._perPage + shown} از ${total} اعلان`
      : `${shown} از ${total} اعلان`;

    if (pageCount <= 1 && !this._nextCursor && !this._prevCursor) {
      pag.classList.add('hidden');
      pag.innerHTML = '';
      return;
    }

    const items = [];

    items.push(this._prevCursor
      ? `<button class="pagination-btn" data-act="nmGoToCursor" data-cursor="${this._escAttr(this._prevCursor)}" data-dir="prev" aria-label="قبلی">
           <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><path d="M9 18l6-6-6-6"/></svg>
         </button>`
      : `<span class="pagination-btn" aria-disabled="true"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><path d="M9 18l6-6-6-6"/></svg></span>`);

    this._pageRange(cur || 1, pageCount).forEach(p => {
      if (p === '...') {
        items.push(`<span class="pagination-ellipsis">…</span>`);
      } else {
        items.push(`<button class="pagination-btn ${p === cur ? 'active' : ''}" data-act="nmGoToPage" data-page="${p}">${p}</button>`);
      }
    });

    items.push(this._nextCursor
      ? `<button class="pagination-btn" data-act="nmGoToCursor" data-cursor="${this._escAttr(this._nextCursor)}" data-dir="next" aria-label="بعدی">
           <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><path d="M15 18l-6-6 6-6"/></svg>
         </button>`
      : `<span class="pagination-btn" aria-disabled="true"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><path d="M15 18l-6-6 6-6"/></svg></span>`);

    items.push(`
      <span class="pagination-goto">
        <label class="pagination-goto-label" for="notifGotoInput">برو به صفحه</label>
        <span class="pagination-goto-field">
          <input type="number" id="notifGotoInput" class="pagination-goto-input" min="1" max="${pageCount}"
            value="${cur || ''}" aria-label="شماره صفحه" data-keydown="nmGoToInputKey">
          <span class="pagination-goto-stepper">
            <button type="button" class="pagination-goto-spin" tabindex="-1" aria-label="افزایش شماره صفحه"
              data-act="nmGoToStep" data-dir="1">
              <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="3"><polyline points="18 15 12 9 6 15"/></svg>
            </button>
            <button type="button" class="pagination-goto-spin" tabindex="-1" aria-label="کاهش شماره صفحه"
              data-act="nmGoToStep" data-dir="-1">
              <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="3"><polyline points="6 9 12 15 18 9"/></svg>
            </button>
          </span>
        </span>
      </span>`);

    pag.innerHTML = items.join('');
    pag.classList.remove('hidden');
  },

  /** builds the page-number range with ... when there are many pages */
  _pageRange(cur, count) {
    if (count <= 7) {
      return Array.from({ length: count }, (_, i) => i + 1);
    }
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
    if (p === this._page) return Promise.resolve();
    return this.load(p).then(() => {
      window.scrollTo({ top: 0, behavior: 'smooth' });
    });
  },

  goToCursor(cursor, dir) {
    this.loadCursor(cursor, dir);
  },

  goToInputValue() {
    const inp = document.getElementById('notifGotoInput');
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
    const inp = document.getElementById('notifGotoInput');
    if (!inp) return;
    const cur = parseInt(inp.value, 10);
    const base = Number.isFinite(cur) ? cur : (this._page || 1);
    const n = base + dir;
    inp.value = Math.min(Math.max(1, n), this._pageCount);
    this.goToInputValue();
  },

  // ── search (debounced) ───────────────────────────────
  onSearchInput(value) {
    const wrap = document.querySelector('.notif-search');
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
    const inp  = document.getElementById('notifSearchInput');
    const wrap = document.querySelector('.notif-search');
    if (inp)  inp.value = '';
    if (wrap) wrap.classList.remove('has-value');
    if (this._search === '') return;
    this._search = '';
    this.load(1);
  },

  // ── advanced search ─────────────────────────────────────
  toggleAdvanced() {
    const panel = document.getElementById('nmAdvPanel');
    const btn   = document.getElementById('nmAdvToggle');
    if (!panel) return;
    const open = panel.classList.toggle('open');
    if (btn) { btn.classList.toggle('active', open); btn.setAttribute('aria-expanded', open ? 'true' : 'false'); }
  },

  applyFilters() {
    this._fDateFrom = document.getElementById('nm-df').value || '';
    this._fDateTo   = document.getElementById('nm-dt').value || '';
    this._fStatus   = document.getElementById('nm-st').value || '';
    this._syncAdvBtn();
    this.load(1);
  },

  resetFilters() {
    document.getElementById('nm-df').value = '';
    document.getElementById('nm-dt').value = '';
    document.getElementById('nm-st').value = '';
    if (window.CSelect) CSelect.refresh(document.getElementById('nm-st'));
    if (window.ThemedDatePicker) {
      ThemedDatePicker.refresh(document.getElementById('nm-df'));
      ThemedDatePicker.refresh(document.getElementById('nm-dt'));
    }
    this._fDateFrom = this._fDateTo = this._fStatus = '';
    this._syncAdvBtn();
    this.load(1);
  },

  _syncAdvBtn() {
    const has = !!(this._fDateFrom || this._fDateTo || this._fStatus);
    const btn = document.getElementById('nmAdvToggle');
    if (btn) btn.classList.toggle('has-filters', has);
  },

  _makeRow(n, idx = 0) {
    const row = document.createElement('div');
    row.className = `notif-row${n.is_expired ? ' is-expired' : ''}`;
    row.dataset.id = n.id;

    // row number accounting for pagination
    const rowNum = (this._page - 1) * this._perPage + idx + 1;

    const pills = [];
    if (n.is_expired) pills.push(`<span class="pill pill-expired">منقضی‌شده</span>`);
    else              pills.push(`<span class="pill pill-active">فعال</span>`);
    if (n.target_all_users) pills.push(`<span class="pill pill-all">همه کاربران</span>`);
    (n.badges || []).forEach(b => pills.push(`<span class="pill pill-badge">${this._esc(b)}</span>`));
    // publish and expiry date/time — labeled and side by side
    const _fmtDT = ms => DateFmt.dateTime(ms);
    pills.push(`<span class="pill pill-created" title="تاریخ و ساعت انتشار">انتشار: ${_fmtDT(n.created_at)}</span>`);
    if (n.expires_at) {
      pills.push(`<span class="pill pill-expiry" title="تاریخ و ساعت انقضا">انقضا: ${_fmtDT(n.expires_at * 1000)}</span>`);
    } else {
      pills.push(`<span class="pill pill-noexp" title="بدون تاریخ انقضا">بدون انقضا</span>`);
    }
    pills.push(`<span class="pill pill-reads" title="تعداد کاربرانی که این اعلان را خوانده‌اند">خوانده‌شده: ${(n.read_count || 0).toLocaleString('en-GB')}</span>`);

    row.innerHTML = `
      <div class="notif-row-num" aria-hidden="true">${rowNum.toLocaleString('en-GB')}</div>
      <div class="notif-row-body">
        <div class="notif-row-title">${this._esc(n.title)}</div>
        ${n.body ? `<div class="notif-row-text">${this._esc(this._stripTags(n.body))}</div>` : ''}
        <div class="notif-row-meta">
          ${pills.join('')}
        </div>
      </div>
      <div class="notif-row-actions">
        <button class="btn btn-secondary btn-icon btn-sm" title="مشاهده‌کنندگان" data-act="nmOpenReaders" data-id="${n.id}" data-title="${this._escAttr(n.title)}">
          <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round">
            <path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"/>
            <circle cx="12" cy="12" r="3"/>
          </svg>
        </button>
        <button class="btn btn-secondary btn-icon btn-sm" title="ویرایش" data-act="nmOpenEdit" data-id="${n.id}">
          <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
            <path d="M11 4H4a2 2 0 00-2 2v14a2 2 0 002 2h14a2 2 0 002-2v-7"/>
            <path d="M18.5 2.5a2.121 2.121 0 013 3L12 15l-4 1 1-4 9.5-9.5z"/>
          </svg>
        </button>
        <button class="btn btn-danger btn-icon btn-sm" title="حذف" data-act="nmOpenDelete" data-id="${n.id}" data-title="${this._escAttr(n.title)}">
          <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
            <polyline points="3 6 5 6 21 6"/>
            <path d="M19 6l-1 14a2 2 0 01-2 2H8a2 2 0 01-2-2L5 6"/>
            <path d="M10 11v6M14 11v6M9 6V4a1 1 0 011-1h4a1 1 0 011 1v2"/>
          </svg>
        </button>
      </div>`;
    return row;
  },

  // ── date formatting helpers ───────────────────────────
  /**
   * converts a Unix timestamp into the unified date+time picker's value format
   * (in the browser's local time): "YYYY-MM-DDTHH:MM"
   */
  _tsToDateTimeValue(ts) {
    if (!ts) return '';
    const d   = new Date(ts * 1000);
    const pad = v => String(v).padStart(2, '0');
    return `${d.getFullYear()}-${pad(d.getMonth()+1)}-${pad(d.getDate())}T${pad(d.getHours())}:${pad(d.getMinutes())}`;
  },

  /** displays a readable date below the input */
  _showExpiryDisplay(ts) {
    const wrap = document.getElementById('expiryDisplay');
    const txt  = document.getElementById('expiryDisplayText');
    if (ts) {
      const d = new Date(ts * 1000);
      txt.textContent = `${DateFmt.date(d)} — ساعت ${DateFmt.time(d)}`;
      wrap.classList.add('show');
    } else {
      wrap.classList.remove('show');
      txt.textContent = '';
    }
  },

  /** fires when the user changes the expiry date/time */
  onExpiryInput() {
    const value = document.getElementById('nf-expires-at').value;
    if (value) {
      const localDt = new Date(`${value}:00`);
      if (!isNaN(localDt.getTime())) {
        this._showExpiryDisplay(Math.floor(localDt.getTime() / 1000));
      }
    } else {
      this._showExpiryDisplay(0);
    }
  },

  // ── Form ──────────────────────────────────────────────
  _resetForm() {
    document.getElementById('nf-title').value        = '';
    this._updateTitleCounter();
    RTE.setHTML('');
    document.getElementById('nf-expires-at').value = '';
    if (window.ThemedDateTimePicker) ThemedDateTimePicker.refresh(document.getElementById('nf-expires-at'));
    document.getElementById('nf-all-users').checked  = false;
    document.querySelectorAll('.badge-check-cb').forEach(cb => cb.checked = false);
    this._syncAudienceUI();
    this._showExpiryDisplay(0);
    if (this._xhr) { try { this._xhr.abort(); } catch (e) {} this._xhr = null; }
    this._pendingImage  = null;
    this._pendingThumb  = null;
    this._existingImage = null;
    this._existingThumb = null;
    this._resetFileUI();
  },

  openAdd() {
    this._editId = null;
    document.getElementById('notifModalTitle').textContent = 'اعلان جدید';
    this._buildBadgeGrid([]);
    this._resetForm();
    this._openModal('notifFormModal');
    this._dirty = false;
    setTimeout(() => document.getElementById('nf-title').focus(), 100);
  },

  openEdit(id) {
    const n = this._notifications.find(x => x.id === id);
    if (!n) return;
    this._editId = id;
    document.getElementById('notifModalTitle').textContent = 'ویرایش اعلان';
    this._buildBadgeGrid(n.badges || []);
    this._resetForm();

    document.getElementById('nf-title').value       = n.title   || '';
    this._updateTitleCounter();
    RTE.setHTML(n.body || '');
    document.getElementById('nf-all-users').checked = !!n.target_all_users;

    (n.badges || []).forEach(b => {
      const cb = document.querySelector(`.badge-check-cb[value="${CSS.escape(b)}"]`);
      if (cb) cb.checked = true;
    });
    this._syncAudienceUI();

    // ── set the expiry date and time ────────────────────────
    if (n.expires_at) {
      document.getElementById('nf-expires-at').value = this._tsToDateTimeValue(n.expires_at);
      if (window.ThemedDateTimePicker) ThemedDateTimePicker.refresh(document.getElementById('nf-expires-at'));
      this._showExpiryDisplay(n.expires_at);
    }

    if (n.image_path) {
      this._existingImage = n.image_path;
      this._existingThumb = n.thumbnail_path || null;
      this._showExistingImage(n.image_path, n.thumbnail_path);
    }

    this._openModal('notifFormModal');
    this._dirty = false;
    setTimeout(() => document.getElementById('nf-title').focus(), 100);
  },

  // ── items per page (configurable + persisted) ─────────────
  setPerPage(val) {
    const allowed = [10, 20, 50];
    let n = parseInt(val, 10);
    if (!allowed.includes(n)) n = 10;
    this._perPage = n;
    try { localStorage.setItem('notif_admin_perpage', String(n)); } catch (e) {}
    this.load(1);
  },
  _initPerPage() {
    let n = 10;
    try {
      const saved = parseInt(localStorage.getItem('notif_admin_perpage'), 10);
      if ([10, 20, 50].includes(saved)) n = saved;
    } catch (e) {}
    this._perPage = n;
    const sel = document.getElementById('notifPerPage');
    if (sel) sel.value = String(n);
  },

  // ── unsaved-changes tracking (same pattern as the tool cards form) ──────────
  _markDirty() { this._dirty = true; },
  _initDirty() {
    const modal = document.getElementById('notifFormModal');
    if (!modal) return;
    // a single listener: any user change inside the form (text, date, checkbox,
    // editor content, upload) marks the form as "dirty".
    const mark = () => this._markDirty();
    modal.addEventListener('input',  mark);
    modal.addEventListener('change', mark);

    document.getElementById('nf-all-users').addEventListener('change', () => this._syncAudienceUI());
  },

  // ── live character counter for the notification title (same pattern as RTE._updateCounter) ──
  _updateTitleCounter() {
    const input = document.getElementById('nf-title');
    const cEl   = document.getElementById('titleCount');
    const wrap  = document.getElementById('titleCounter');
    if (!input) return;
    const len = input.value.length;
    if (cEl)  cEl.textContent = len.toLocaleString('en-US');
    if (wrap) wrap.classList.toggle('over', len > 200);
  },
  _initTitleCounter() {
    const input = document.getElementById('nf-title');
    if (!input) return;
    input.addEventListener('input', () => this._updateTitleCounter());
  },

  closeForm(force = false) {
    // if there are unsaved changes, confirm with the custom modal
    if (!force && this._dirty) {
      this._ask({
        title:    this._editId ? 'ویرایش اعلان' : 'افزودن اعلان',
        heading:  'تغییرات ذخیره‌ نشده دارید',
        desc:     'آیا تغییرات ذخیره شوند؟',
        type:     'warning',
        icon:     '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M10.29 3.86L1.82 18a2 2 0 0 0 1.71 3h16.94a2 2 0 0 0 1.71-3L13.71 3.86a2 2 0 0 0-3.42 0z"/><line x1="12" y1="9" x2="12" y2="13"/><line x1="12" y1="17" x2="12.01" y2="17"/></svg>',
        cancelLabel: 'خیر',
        btnLabel: 'بله',
        btnClass: 'btn-primary',
        onConfirm: () => { this.closeConfirm(); this.save(); },
        onCancel: () => { this.closeForm(true); },
      });
      return;
    }
    this._dirty = false;
    this._closeModal('notifFormModal');
    this._pendingImage  = null;
    this._existingImage = null;
  },

  _buildBadgeGrid(selected) {
    const grid = document.getElementById('badgeCheckGrid');
    if (!AVAIL_BADGES.length) {
      grid.innerHTML = '<span class="badge-check-empty">هیچ دسته‌بندی‌ای موجود نیست</span>';
      return;
    }
    grid.innerHTML = '';
    AVAIL_BADGES.forEach(badge => {
      const label = document.createElement('label');
      label.className = 'badge-check-label';
      const cb = document.createElement('input');
      cb.type = 'checkbox'; cb.className = 'badge-check-cb'; cb.value = badge;
      cb.checked = selected.includes(badge);
      label.appendChild(cb);
      label.appendChild(document.createTextNode(badge));
      grid.appendChild(label);
    });
  },

  // When "all users" is on, the category grid is irrelevant — disable it, but leave
  // prior selections checked so they reappear if the toggle is switched off again.
  _syncAudienceUI() {
    const allOn = document.getElementById('nf-all-users').checked;
    document.getElementById('badgesRow').classList.toggle('is-disabled', allOn);
    document.querySelectorAll('.badge-check-cb').forEach(cb => cb.disabled = allOn);
  },

  // ── image upload (single file, with a live progress bar) ────────
  handleFileSelect(file) {
    if (!file) return;
    if (!file.type.startsWith('image/')) { Toast.show('فقط فایل‌های تصویری مجاز هستند', 'error'); return; }
    if (file.size > 52_428_800)          { Toast.show('حجم فایل بیشتر از ۵۰ مگابایت است', 'error'); return; }

    // show the file row immediately + build a small preview off the main thread
    // (we don't feed the full-size image directly to <img>, so heavy photos don't freeze the UI)
    this._showFileItem({ name: file.name });
    this._setPreviewFromFile(file);
    this._setFileState('uploading');
    this._setFileProgress(0, 0, file.size);

    const formData = new FormData();
    formData.append('image', file);

    const xhr = new XMLHttpRequest();
    this._xhr = xhr;
    xhr.open('POST', 'admin.php?api=upload_notification_image');
    xhr.setRequestHeader('X-CSRF-Token', CSRF_TOKEN);
    xhr.timeout = 300_000; // 5 minutes, for large images

    xhr.upload.onprogress = e => {
      if (e.lengthComputable) {
        this._setFileProgress(Math.round((e.loaded / e.total) * 100), e.loaded, e.total);
      }
    };
    xhr.onload = () => {
      this._xhr = null;
      let data = {};
      try { data = JSON.parse(xhr.responseText); } catch (e) {}
      if (xhr.status >= 200 && xhr.status < 300 && data.ok) {
        this._pendingImage = data.image_path;
        this._pendingThumb = data.thumbnail_path || null;
        this._setFileProgress(100, file.size, file.size);
        this._setFileState('done');
        // switch the preview to the final server version and free the objectURL
        document.getElementById('imgPreview').src = data.thumbnail_path || data.image_path;
        this._revokePreview();
        this._markDirty();
        Toast.show('تصویر آپلود شد', 'success', 'آپلود موفق');
      } else {
        this._revokePreview();
        this._fileError(data.msg || 'خطا در آپلود');
      }
    };
    xhr.onerror   = () => { this._xhr = null; this._revokePreview(); this._fileError('خطا در ارتباط با سرور'); };
    xhr.ontimeout = () => { this._xhr = null; this._revokePreview(); this._fileError('آپلود به دلیل طولانی شدن لغو شد'); };
    xhr.send(formData);
  },

  _fmtBytes(n) {
    n = Number(n) || 0;
    if (n < 1024)    return n + ' B';
    if (n < 1048576) return Math.round(n / 1024) + ' KB';
    return (n / 1048576).toFixed(1) + ' MB';
  },
  _basename(p) { return String(p || '').split('/').pop().split('\\').pop() || 'تصویر'; },

  // builds a small preview without freezing: createImageBitmap decodes and resizes the
  // image off the main thread, then a lightweight thumbnail (~200px) is placed on the <img>.
  async _setPreviewFromFile(file) {
    const img      = document.getElementById('imgPreview');
    const thumbBox = document.getElementById('imgFileThumb');
    this._revokePreview();
    if (window.createImageBitmap) {
      try {
        const bitmap = await createImageBitmap(file, { resizeWidth: 200, resizeQuality: 'low' });
        const canvas = document.createElement('canvas');
        canvas.width  = bitmap.width;
        canvas.height = bitmap.height;
        canvas.getContext('2d').drawImage(bitmap, 0, 0);
        if (bitmap.close) bitmap.close();
        const blob = await new Promise(res => canvas.toBlob(res, 'image/webp', 0.8));
        if (blob) {
          this._previewURL = URL.createObjectURL(blob);
          img.src = this._previewURL;
          thumbBox.classList.add('has-img');
          return;
        }
      } catch (e) { /* fall through to the fallback path */ }
    }
    // fallback (older browsers): a direct object URL + async decode
    this._previewURL = URL.createObjectURL(file);
    img.src = this._previewURL;
    thumbBox.classList.add('has-img');
    if (img.decode) img.decode().catch(() => {});
  },
  _revokePreview() {
    if (this._previewURL) { URL.revokeObjectURL(this._previewURL); this._previewURL = null; }
  },

  _showFileItem({ name, thumb }) {
    document.getElementById('imgFileName').textContent = name || 'تصویر';
    document.getElementById('imgFileSize').textContent = '';
    const thumbBox = document.getElementById('imgFileThumb');
    const img      = document.getElementById('imgPreview');
    if (thumb) { img.src = thumb; thumbBox.classList.add('has-img'); }
    else       { img.removeAttribute('src'); thumbBox.classList.remove('has-img'); }
    document.getElementById('imgFileItem').hidden = false;
    document.getElementById('imgUploadZone').style.display = 'none';
  },
  _setFileProgress(pct, loaded, total) {
    document.getElementById('imgFileBar').style.width  = pct + '%';
    document.getElementById('imgFilePct').textContent  = pct + '%';
    if (total) {
      document.getElementById('imgFileSize').textContent =
        `${this._fmtBytes(loaded)} / ${this._fmtBytes(total)}`;
    }
  },
  _setFileState(state) {
    const item = document.getElementById('imgFileItem');
    item.classList.remove('is-uploading', 'is-done', 'is-error');
    item.classList.add('is-' + state);
    if (state === 'done') {
      // once done, show only the final size (not "loaded/total")
      const sub = document.getElementById('imgFileSize');
      if (sub.textContent.includes('/')) sub.textContent = sub.textContent.split('/').pop().trim();
    }
  },
  _fileError(msg) {
    this._setFileState('error');
    document.getElementById('imgFileSize').textContent = msg;
    Toast.show(msg, 'error');
  },
  _showExistingImage(path, thumb) {
    this._showFileItem({ name: this._basename(path), thumb: thumb || path });
    document.getElementById('imgFileSize').textContent = 'تصویر فعلی';
    this._setFileState('done');
  },
  _resetFileUI() {
    this._revokePreview();
    const item = document.getElementById('imgFileItem');
    item.hidden = true;
    item.classList.remove('is-uploading', 'is-done', 'is-error');
    document.getElementById('imgFileBar').style.width = '0';
    document.getElementById('imgFilePct').textContent = '0%';
    document.getElementById('imgFileSize').textContent = '';
    document.getElementById('imgFileName').textContent = '';
    const img = document.getElementById('imgPreview');
    img.removeAttribute('src');
    document.getElementById('imgFileThumb').classList.remove('has-img');
    document.getElementById('imgUploadZone').style.display = '';
    document.getElementById('imgFileInput').value = '';
  },
  removeImage() {
    if (this._xhr) { try { this._xhr.abort(); } catch (e) {} this._xhr = null; }
    this._markDirty();
    this._pendingImage  = null;
    this._pendingThumb  = null;
    this._existingImage = null;
    this._existingThumb = null;
    this._resetFileUI();
  },

  // ── Save ─────────────────────────────────────────────
  async save() {
    const title       = document.getElementById('nf-title').value.trim();
    const body        = RTE.getHTML();
    const allUsersChk = document.getElementById('nf-all-users').checked;
    const allUsers    = allUsersChk ? '1' : '0';
    // categories are disabled (and irrelevant) once "all users" is on — never submit them
    const badges      = allUsersChk ? [] : [...document.querySelectorAll('.badge-check-cb:checked')].map(c => c.value);

    // convert the local date+time to UTC for correct storage regardless of server timezone
    const expiresValue = document.getElementById('nf-expires-at').value;
    let expires = '';
    let expiresLocalDt = null;
    if (expiresValue) {
      expiresLocalDt = new Date(`${expiresValue}:00`);
      if (!isNaN(expiresLocalDt.getTime())) {
        expires = expiresLocalDt.toISOString().slice(0, 16); // "YYYY-MM-DDTHH:MM" in UTC
      }
    }

    if (!title) return this._failField('nf-title', 'عنوان الزامی است');
    if (!body)  return this._failField('nf-body', 'متن اعلان الزامی است');
    if (RTE.plainLength() > RTE.MAX_CHARS) {
      return this._failField('nf-body', `متن اعلان نباید بیشتر از ${RTE.MAX_CHARS.toLocaleString('en-GB')} کاراکتر باشد`);
    }
    if (!allUsersChk && !badges.length) {
      return this._failField('nf-all-users', 'مخاطبان اعلان را مشخص کنید');
    }
    if (!expiresValue) {
      return this._failField('nf-expires-at', 'تاریخ و ساعت انقضا را مشخص کنید');
    }
    if (expiresLocalDt && expiresLocalDt.getTime() < Date.now()) {
      return this._failField('nf-expires-at', 'تاریخ و ساعت انقضا نباید قبل از زمان حال باشد');
    }

    let imagePath = '';
    let thumbPath = '';
    if (this._pendingImage) {
      imagePath = this._pendingImage;
      thumbPath = this._pendingThumb || '';
    } else if (this._editId && this._existingImage) {
      imagePath = this._existingImage;
      thumbPath = this._existingThumb || '';
    }

    const btn = document.getElementById('notifSaveBtn');
    btn.disabled = true;

    const payload = { title, body, image_path: imagePath, thumbnail_path: thumbPath,
                      target_all_users: allUsers,
                      expires_at: expires, badges };
    const action  = this._editId ? 'update_notification' : 'create_notification';
    if (this._editId) payload.id = this._editId;

    const res = await apiCall(action, payload);
    btn.disabled = false;

    if (res.ok) {
      const wasCreate = !this._editId;
      this._dirty = false;
      this.closeForm(true);
      Toast.show(this._editId ? 'اعلان ویرایش شد' : 'اعلان ایجاد شد', 'success', this._editId ? 'ویرایش موفق' : 'افزودن موفق');
      if (wasCreate) this._page = 1;
      await this.load();
    } else {
      const fieldId = { title: 'nf-title', body: 'nf-body', expires_at: 'nf-expires-at', target_all_users: 'nf-all-users' }[res.field];
      if (fieldId) this._failField(fieldId, res.msg || 'خطا در ذخیره');
      else Toast.show(res.msg || 'خطا در ذخیره', 'error');
    }
  },

  // Shows the error as a toast and moves focus to the offending field (or the RTE body editor).
  _failField(fieldId, msg) {
    Toast.show(msg, 'error');
    const el = document.getElementById(fieldId);
    if (el) { if (el._tdpFocus) el._tdpFocus(); else el.focus(); }
  },

  // ── Confirm dialog (generic: delete / close form) ─────────────
  _askCb: null,
  _defaultConfirmIcon:
    '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round">' +
      '<polyline points="3 6 5 6 21 6"/>' +
      '<path d="M19 6l-1 14a2 2 0 01-2 2H8a2 2 0 01-2-2L5 6"/>' +
      '<path d="M10 11v6M14 11v6M9 6V4a1 1 0 011-1h4a1 1 0 011 1v2"/>' +
    '</svg>',
  _ask({ title, heading, desc, icon = null, type = 'danger', btnLabel = 'تایید', btnClass = null, cancelLabel = 'انصراف', onConfirm, onCancel = null }) {
    this._askCb = onConfirm || null;
    this._askCancelCb = onCancel || null;
    document.getElementById('notifConfirmTitle').textContent   = title;
    document.getElementById('notifConfirmHeading').textContent = heading;
    document.getElementById('notifConfirmDesc').innerHTML      = desc;
    const ic = document.getElementById('notifConfirmIcon');
    ic.className = `confirm-icon ${type}`;
    ic.innerHTML = icon || this._defaultConfirmIcon;
    const btn = document.getElementById('notifConfirmBtn');
    btn.className   = `btn btn-sm ${btnClass || (type === 'warning' ? 'btn-warning' : 'btn-danger')}`;
    btn.textContent = btnLabel;
    btn.disabled    = false;
    const cancelBtn = document.getElementById('notifConfirmCancelBtn');
    if (cancelBtn) cancelBtn.textContent = cancelLabel;
    this._openModal('notifConfirmModal');
  },
  async _runAsk() {
    const btn = document.getElementById('notifConfirmBtn');
    btn.disabled = true;
    try { if (this._askCb) await this._askCb(); }
    finally { btn.disabled = false; }
  },

  // ── Delete ────────────────────────────────────────────
  openDelete(id, name) {
    this._deleteId = id;
    this._ask({
      title:    'حذف اعلان',
      heading:  'آیا از حذف این اعلان اطمینان دارید؟',
      desc:     `اعلان «<span class="item-name">${this._esc(name)}</span>» برای همه کاربران به‌طور دائم حذف می‌شود و قابل بازگردانی نیست.`,
      type:     'danger',
      btnLabel: 'حذف اعلان',
      onConfirm: () => this.confirmDelete(),
    });
  },
  closeConfirm() { this._closeModal('notifConfirmModal'); this._askCb = null; this._askCancelCb = null; this._deleteId = null; },
  cancelConfirm() {
    const cb = this._askCancelCb;
    this.closeConfirm();
    if (cb) cb();
  },
  async confirmDelete() {
    if (!this._deleteId) return;
    const res = await apiCall('delete_notification', { id: this._deleteId });
    if (res.ok) { this.closeConfirm(); Toast.show('اعلان حذف شد', 'success', 'حذف موفق'); await this.load(); }
    else        { Toast.show(res.msg || 'خطا در حذف', 'error'); }
  },

  // ── Readers ("who read this") ──────────────────────────
  // Same loading-skeleton markup as the admin panel's SKELETON_TABLE_ROW (admin.js),
  // reproduced here since this page doesn't load admin.js/admin.css.
  _SKELETON_TABLE_ROW:
    '<div class="sk-table-row" aria-hidden="true">'
    + '<div class="sk sk-avatar"></div>'
    + '<div class="sk-lines"><div class="sk sk-line sk-line--title"></div><div class="sk sk-line sk-line--sub"></div></div>'
    + '</div>',

  _readersId:      0,
  _readersOffset:  0,
  _readersLoading: false,
  _readersHasMore: false,
  _readersReqSeq:  0,   // bumped on each open/close so a stale in-flight fetch can't write into a reused modal
  _readersScrollHandler: null,

  async openReaders(id, title) {
    document.getElementById('notifReadersTitle').textContent = `مشاهده‌کنندگان «${this._esc(title)}»`;
    document.getElementById('notifReadersList').innerHTML    = this._SKELETON_TABLE_ROW.repeat(3);
    this._readersId      = id;
    this._readersOffset  = 0;
    this._readersHasMore = false;
    this._readersReqSeq++;
    this._openModal('notifReadersModal');

    // Infinite scroll: fetch the next page automatically once the user scrolls
    // near the bottom, instead of a manual "load more" button.
    const list = document.getElementById('notifReadersList');
    if (this._readersScrollHandler) list.removeEventListener('scroll', this._readersScrollHandler);
    this._readersScrollHandler = () => {
      if (!this._readersHasMore || this._readersLoading) return;
      if (list.scrollTop + list.clientHeight >= list.scrollHeight - 80) {
        this._loadReadersPage(false);
      }
    };
    list.addEventListener('scroll', this._readersScrollHandler);

    await this._loadReadersPage(true);
  },

  async _loadReadersPage(isFirstPage) {
    if (this._readersLoading) return;
    this._readersLoading = true;
    const reqSeq = this._readersReqSeq;

    const list = document.getElementById('notifReadersList');
    let sentinel = null;
    if (!isFirstPage) {
      sentinel = document.createElement('div');
      sentinel.innerHTML = this._SKELETON_TABLE_ROW;
      list.appendChild(sentinel.firstElementChild);
    }

    const res = await apiCall('notification_readers', { id: this._readersId, offset: this._readersOffset });
    if (reqSeq !== this._readersReqSeq) return; // the modal was closed/reopened while this was in flight

    const lastSkeleton = list.querySelector('.sk-table-row:last-child');
    if (!isFirstPage && lastSkeleton) lastSkeleton.remove();

    if (!res.ok) {
      list.innerHTML = `<div class="readers-empty">${this._esc(res.msg || 'خطا در دریافت اطلاعات')}</div>`;
      this._readersLoading = false;
      return;
    }

    const readers  = res.readers || [];
    const rowsHtml = readers.map(r => this._readerRow(r)).join('');

    if (isFirstPage) {
      list.innerHTML = rowsHtml || '<div class="readers-empty">هنوز هیچ کاربری این اعلان را نخوانده است</div>';
    } else {
      list.insertAdjacentHTML('beforeend', rowsHtml);
    }

    this._readersOffset += readers.length;
    this._readersHasMore = !!res.has_more;
    this._readersLoading = false;
  },

  _readerRow(r) {
    const when    = DateFmt.dateTime(r.read_at);
    const name    = (r.display_name || r.username || '؟').trim();
    const initial = name.charAt(0).toUpperCase();
    return `
      <div class="blk-row">
        <div class="blk-info">
          <div class="reader-avatar" aria-hidden="true">${this._esc(initial)}</div>
          <div class="blk-info-text">
            <div class="blk-ip">${this._esc(r.display_name)}</div>
            <div class="blk-meta">${this._esc(r.username)}</div>
          </div>
        </div>
        <div class="blk-side">
          <span class="pill pill-date">${when}</span>
        </div>
      </div>`;
  },

  closeReaders() {
    this._closeModal('notifReadersModal');
    this._readersReqSeq++; // invalidate any in-flight fetch
    this._readersLoading = false;
    const list = document.getElementById('notifReadersList');
    if (this._readersScrollHandler) list.removeEventListener('scroll', this._readersScrollHandler);
    // Clear the (possibly hundreds-of-rows-deep) list immediately instead of leaving it in the DOM
    // during the close transition — with a masked, scrollable subtree that large, animating the
    // modal's fade/scale-out while it's still fully rendered visibly stutters.
    list.innerHTML = '';
  },

  _openModal(id)  { document.getElementById(id).classList.add('open');    document.body.style.overflow = 'hidden'; },
  _closeModal(id) {
    document.getElementById(id).classList.remove('open');
    // if another modal is still open (a confirm on top of the form), keep the scroll lock
    if (!document.querySelector('.modal-overlay.open')) document.body.style.overflow = '';
  },
  _esc(str)     { return String(str ?? '').replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;'); },
  _stripTags(html) {
    const tmp = document.createElement('div');
    tmp.innerHTML = String(html ?? '');
    return (tmp.textContent || tmp.innerText || '').replace(/\s+/g, ' ').trim();
  },
  _escAttr(str) { return String(str ?? '').replace(/[&<>"']/g, c => ({ '&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;' }[c])); },
};

// ═══════════════════════════════════════════════════════════
// actions (replaces on* for CSP) — called by the actions.js dispatcher.
// ═══════════════════════════════════════════════════════════
if (window.Actions) {
  Actions.register({
    nmOpenAdd:        () => NM.openAdd(),
    nmSearch:         (el) => NM.onSearchInput(el.value),
    nmClearSearch:    () => NM.clearSearch(),
    nmSetPerPage:     (el) => NM.setPerPage(el.value),
    nmToggleAdvanced: () => NM.toggleAdvanced(),
    nmApplyFilters:   () => NM.applyFilters(),
    nmResetFilters:   () => NM.resetFilters(),
    nmCloseForm:      () => NM.closeForm(),
    nmFileSelect:     (el) => NM.handleFileSelect(el.files[0]),
    nmRemoveImage:    () => NM.removeImage(),
    nmExpiryInput:    () => NM.onExpiryInput(),
    nmSave:           () => NM.save(),
    nmCloseConfirm:   () => NM.cancelConfirm(),
    nmRunAsk:         () => NM._runAsk(),
    nmGoToPage:       (el) => NM.goToPage(parseInt(el.dataset.page, 10)),
    nmGoToCursor:     (el) => NM.goToCursor(el.dataset.cursor, el.dataset.dir),
    nmGoToStep:       (el) => NM.goToStep(parseInt(el.dataset.dir, 10)),
    nmGoToInputKey:   (el, e) => NM.goToInputKey(e),
    nmOpenEdit:       (el) => NM.openEdit(parseInt(el.dataset.id, 10)),
    nmOpenDelete:     (el) => NM.openDelete(parseInt(el.dataset.id, 10), el.dataset.title),
    nmOpenReaders:    (el) => NM.openReaders(parseInt(el.dataset.id, 10), el.dataset.title),
    nmCloseReaders:   () => NM.closeReaders(),
  });
}

// Drag & Drop
(function initDragDrop() {
  const zone = document.getElementById('imgUploadZone');
  zone.addEventListener('dragover',  e => { e.preventDefault(); zone.classList.add('drag-over'); });
  zone.addEventListener('dragleave', () => zone.classList.remove('drag-over'));
  zone.addEventListener('drop', e => {
    e.preventDefault(); zone.classList.remove('drag-over');
    const file = e.dataTransfer?.files?.[0];
    if (file) NM.handleFileSelect(file);
  });
})();

document.querySelectorAll('.modal-overlay').forEach(o => {
  o.addEventListener('click', e => {
    if (e.target !== o) return;
    if (o.id === 'notifFormModal')    NM.closeForm();
    if (o.id === 'notifConfirmModal') NM.cancelConfirm();
    if (o.id === 'notifReadersModal') NM.closeReaders();
  });
});
document.addEventListener('keydown', e => {
  if (e.key !== 'Escape') return;
  const open = document.querySelectorAll('.modal-overlay.open');
  if (!open.length) return;
  const top = open[open.length - 1];   // last = topmost
  if (top.id === 'notifConfirmModal')   NM.cancelConfirm();
  else if (top.id === 'notifFormModal') NM.closeForm();
  else if (top.id === 'notifReadersModal') NM.closeReaders();
});

// ── CustomSelect: upgrades native <select>s into theme-matching dropdowns ──
// This page doesn't load admin.js, so the same enhancer class is duplicated here
// so its dropdowns match the rest of the panel (settings/users) — radius/spacing/hover/selection.
const CSelect = {
  enhanceAll(root = document) { root.querySelectorAll('select:not([data-cs])').forEach(sel => this.enhance(sel)); },
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
        wrap.classList.remove('open');
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
  refresh(sel) { if (sel && sel._csWrap) this._sync(sel); },
  _sync(sel) {
    const wrap = sel._csWrap; if (!wrap) return;
    const label = sel.options[sel.selectedIndex] ? sel.options[sel.selectedIndex].textContent : '';
    wrap.querySelector('.cselect-value').textContent = label;
    wrap.querySelectorAll('.cselect-option').forEach(o => o.classList.toggle('selected', o.dataset.value === sel.value));
  },
};
document.addEventListener('click', () => document.querySelectorAll('.cselect.open').forEach(w => w.classList.remove('open')));

document.addEventListener('DOMContentLoaded', () => { RTE.init(); NM._initDirty(); NM._initTitleCounter(); NM._initPerPage(); CSelect.enhanceAll(); NM.load(); });

// ── ripple (click wave) effect — this page doesn't load admin.js, so the handler is duplicated here ──
(function () {
  const SEL = '.btn, .hdr-btn, .btn-icon, .nm-adv-toggle,'
    + ' .cselect-option, .pg-btn, .nm-pag-btn, .pagination-btn, .pagination-goto-spin,'
    + ' .modal-close, .notif-search-clear, .toast-close';
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
