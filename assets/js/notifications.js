    /* ── Theme: only prevents the initial flash (FOUC) ──
       Lag-free switching + cross-tab sync are handled in theme.js. */
    (function () {
      const saved = localStorage.getItem('theme');
      const dark  = window.matchMedia('(prefers-color-scheme: dark)').matches;
      if (saved === 'dark' || (!saved && dark))
        document.documentElement.setAttribute('data-theme', 'dark');
    })();

    /* sanitizeNotifHtml + NotifDetail (the notification detail modal) live in
       assets/js/notif-detail.js, shared with the dashboard's bell dropdown. */

    /* ── Notification Panel (row list + read state — the modal itself is shared) ── */
    const NP = {

      // Opens the shared detail modal, then marks the notification as read —
      // in that order, so the modal still shows the pre-read "edited" pill
      // (mirrors the bell dropdown's open-then-mark-read flow in script.js).
      open(id) {
        const n = NOTIFS[id];
        if (!n) return;

        NotifDetail.open(n);
        this._markRead(id, n);
      },

      _markRead(id, n) {
        if (n.is_read) return;
        n.is_read   = true;
        n.is_edited = false;

        const row = document.querySelector(`.notif-row[data-id="${id}"]`);
        if (row) {
          row.classList.remove('unread');
          const pill = row.querySelector('.npill-unread, .npill-edited');
          if (pill) pill.remove();
        }

        fetch('api.php?action=mark_read', {
          method:  'POST',
          headers: { 'Content-Type': 'application/json', 'X-CSRF-Token': window.CSRF_TOKEN || '' },
          body:    JSON.stringify({ notification_id: id }),
        }).catch(() => {});
      },
    };

    // This page has no other overlay to prioritize against, so Escape always
    // closes the detail modal directly (the bell dropdown's equivalent
    // priority-aware handler lives in script.js).
    document.addEventListener('keydown', e => {
      if (e.key === 'Escape' && document.getElementById('ndOverlay')?.classList.contains('open')) {
        NotifDetail.close();
      }
    });

    /* ── Actions (replaces on* handlers, for CSP) ── */
    if (window.Actions) {
      Actions.register({
        npOpen:     (el) => NP.open(parseInt(el.dataset.id, 10)),
        submitForm: (el) => el.form && el.form.submit(),
        notifGotoStep: (el) => {
          const dir = parseInt(el.dataset.dir, 10);
          const inp = document.getElementById('notifGotoInput');
          if (!inp) return;
          const max = parseInt(inp.max, 10) || 1;
          const cur = parseInt(inp.value, 10);
          const base = Number.isFinite(cur) ? cur : 1;
          inp.value = Math.min(Math.max(1, base + dir), max);
          const form = document.getElementById('notifGotoForm');
          if (form) form.requestSubmit ? form.requestSubmit() : form.submit();
        },
      });
    }

    // ── Hover preload: loads the image on row hover ──────
    // When the mouse enters a row, image loading starts so it
    // can be served from cache when the user clicks "view"
    document.querySelectorAll('.notif-row[data-id]').forEach(row => {
      let timer;
      row.addEventListener('mouseenter', () => {
        timer = setTimeout(() => {
          const n = NOTIFS[parseInt(row.dataset.id)];
          if (n?.image_path && !n._preloaded) {
            n._preloaded = true;
            // Preload both versions
            if (n.thumbnail_path) new Image().src = n.thumbnail_path;
            new Image().src = n.image_path;
          }
        }, 120); // short delay to avoid accidental hovers
      }, { passive: true });
      row.addEventListener('mouseleave', () => clearTimeout(timer), { passive: true });
    });

/* ══ Advanced search panel + custom selects (former second block) ══ */
    /* Open/close the advanced search panel */
    (function () {
      const btn   = document.getElementById('notifAdvToggle');
      const panel = document.getElementById('notifAdvPanel');
      if (!btn || !panel) return;
      btn.addEventListener('click', () => {
        const open = panel.classList.toggle('open');
        btn.classList.toggle('active', open);
        btn.setAttribute('aria-expanded', open ? 'true' : 'false');
      });
    })();

    /* Prevents searching when the search box is empty (and no filter is active either) */
    (function () {
      const form = document.querySelector('.notif-search-form');
      if (!form) return;
      const q = document.getElementById('notif-q');
      const val = el => (el && el.value ? el.value.trim() : '');
      form.addEventListener('submit', e => {
        // We only guard the search button (and Enter inside the form);
        // the "apply filter" button and per-page count change are left untouched.
        const submitter = e.submitter;
        const isFilterApply = submitter && submitter.classList.contains('notif-adv-apply');
        if (isFilterApply) return;
        const hasText    = val(q) !== '';
        const hasFilters = val(document.getElementById('adv-df')) !== '' ||
                           val(document.getElementById('adv-dt')) !== '' ||
                           val(document.getElementById('adv-st')) !== '';
        if (!hasText && !hasFilters) {
          e.preventDefault();
          if (q) q.focus();
        }
      });
    })();

    /* Custom dropdown: replaces the native select's list with one matching the project theme
       (the original select stays in the DOM for form submission and accessibility) */
    (function () {
      const CHEV = '<svg class="cselect-chev" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><polyline points="6 9 12 15 18 9"/></svg>';

      function init(select) {
        const wrap = document.createElement('div');
        wrap.className = 'cselect';
        select.parentNode.insertBefore(wrap, select);
        wrap.appendChild(select);
        select.classList.add('cselect-native');
        select.tabIndex = -1;
        select.setAttribute('aria-hidden', 'true');

        const btn = document.createElement('button');
        btn.type = 'button';
        btn.className = 'cselect-btn';
        btn.setAttribute('aria-haspopup', 'listbox');
        btn.setAttribute('aria-expanded', 'false');
        if (select.getAttribute('aria-label')) btn.setAttribute('aria-label', select.getAttribute('aria-label'));
        btn.innerHTML = '<span class="cselect-label"></span>' + CHEV;
        const label = btn.querySelector('.cselect-label');

        const menu = document.createElement('div');
        menu.className = 'cselect-menu';
        menu.setAttribute('role', 'listbox');

        Array.from(select.options).forEach((opt, i) => {
          const item = document.createElement('div');
          item.className = 'cselect-opt';
          item.setAttribute('role', 'option');
          item.textContent = opt.textContent;
          item.setAttribute('aria-selected', opt.selected ? 'true' : 'false');
          if (opt.selected) label.textContent = opt.textContent;
          item.addEventListener('click', () => choose(i));
          menu.appendChild(item);
        });
        if (!label.textContent) label.textContent = (select.options[select.selectedIndex] || {}).textContent || '';

        wrap.appendChild(btn);
        wrap.appendChild(menu);

        function open()  { closeAll(); wrap.classList.add('open');  btn.setAttribute('aria-expanded', 'true'); }
        function close() { wrap.classList.remove('open'); btn.setAttribute('aria-expanded', 'false'); }
        function choose(i) {
          select.selectedIndex = i;
          label.textContent = select.options[i].textContent;
          menu.querySelectorAll('.cselect-opt').forEach((el, k) => el.setAttribute('aria-selected', k === i ? 'true' : 'false'));
          close();
          // change → triggers the native onchange (e.g. auto-submitting "items per page")
          select.dispatchEvent(new Event('change', { bubbles: true }));
        }

        btn.addEventListener('click', (e) => { e.preventDefault(); wrap.classList.contains('open') ? close() : open(); });
        btn.addEventListener('keydown', (e) => {
          if (e.key === 'ArrowDown' || e.key === 'Enter' || e.key === ' ') { e.preventDefault(); open(); }
          else if (e.key === 'Escape') close();
        });
        wrap._close = close;
      }

      function closeAll() {
        document.querySelectorAll('.cselect.open').forEach((w) => w._close && w._close());
      }

      document.querySelectorAll('select[data-cselect]').forEach(init);
      document.addEventListener('click', (e) => { if (!e.target.closest('.cselect')) closeAll(); });
      document.addEventListener('keydown', (e) => { if (e.key === 'Escape') closeAll(); });
    })();
