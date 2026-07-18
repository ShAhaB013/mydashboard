    const API_URL = 'api.php';

    /* ── Theme: prevents the initial flash (FOUC) ── */
    (function () {
      const saved      = localStorage.getItem('theme');
      const prefersDark = window.matchMedia('(prefers-color-scheme: dark)').matches;
      if (saved === 'dark' || (!saved && prefersDark)) {
        document.documentElement.setAttribute('data-theme', 'dark');
      }
    })();
    /* Lag-free theme switching + cross-tab sync are handled in theme.js. */

    /* While the user is editing first/last name, the periodic poll must not
       overwrite the still-unsaved typed value. */
    let nameFieldsDirty = false;
    /* Last value confirmed from the server — used to detect "no change" on submit. */
    let originalFirstName = '';
    let originalLastName  = '';

    /* ── Display user info ── */
    async function loadProfile() {
      try {
        const res  = await fetch(`${API_URL}?action=me`);
        const data = await res.json();

        if (!data.ok || !data.logged_in) {
          // If not logged in, go back to the main page
          window.location.href = 'index.php';
          return;
        }

        const display = data.display_name || data.username || '';

        document.getElementById('profileDisplayName').textContent = display;
        document.getElementById('profileEmail').textContent       = data.email || '—';
        document.getElementById('profileAdminBadge').hidden = !data.is_admin;
        originalFirstName = data.first_name || '';
        originalLastName  = data.last_name  || '';
        if (!nameFieldsDirty) {
          const fn = document.getElementById('firstName');
          const ln = document.getElementById('lastName');
          if (fn) fn.value = originalFirstName;
          if (ln) ln.value = originalLastName;
        }
        const head = document.getElementById('profileCardHead');
        if (head) head.classList.remove('is-loading');
        const nameTab = document.getElementById('tabPanel-name');
        if (nameTab) nameTab.classList.remove('is-loading');

      } catch {
        window.location.href = 'index.php';
      }
    }

    /* ── Show/hide password ── */
    function togglePass(inputId, btn) {
      const input = document.getElementById(inputId);
      const isPass = input.type === 'password';
      input.type = isPass ? 'text' : 'password';

      btn.innerHTML = isPass
        ? `<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round">
             <path d="M17.94 17.94A10.07 10.07 0 0112 20c-7 0-11-8-11-8a18.45 18.45 0 015.06-5.94"/>
             <path d="M9.9 4.24A9.12 9.12 0 0112 4c7 0 11 8 11 8a18.5 18.5 0 01-2.16 3.19"/>
             <line x1="1" y1="1" x2="23" y2="23"/>
           </svg>`
        : `<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round">
             <path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"/>
             <circle cx="12" cy="12" r="3"/>
           </svg>`;
    }

    /* ── Password rules + random password generation: from the shared password-policy.js module ── */
    function pwMeetsPolicy(val) { return PasswordPolicy.meets(val); }
    function updatePassRules(val) { PasswordPolicy.updateChecklist('passRules', val); }
    function genPassword(el) {
      PasswordPolicy.generate(el.dataset.target, el.dataset.confirm, 'passRules');
      const p = document.getElementById(el.dataset.target);
      if (p && window.Field) Field.set(p, 'success', 'رمز مناسب است');
      // The generated password is also copied into the confirm field, so it must turn green too
      // (otherwise it would stay red from the earlier "mismatch" error).
      const c = el.dataset.confirm && document.getElementById(el.dataset.confirm);
      if (c && window.Field) Field.set(c, 'success', 'یکسان است');
    }

    /* Validation error only below the field itself (not Toast) */
    function fieldErr(id, msg) {
      if (window.Field) Field.set(id, 'error', msg);
      else if (window.Toast) Toast.show(msg, 'error');
      const el = document.getElementById(id);
      if (el) el.focus();
    }

    /* ── Form submission ── */
    async function submitChangePassword() {
      const currentPassword = document.getElementById('currentPassword').value;
      const newPassword     = document.getElementById('newPassword').value;
      const confirmPassword = document.getElementById('confirmPassword').value;
      const btn             = document.getElementById('profileSubmitBtn');

      // Validation only below the field itself (field-msg) — final feedback via Toast
      if (!currentPassword) { fieldErr('currentPassword', 'رمز عبور فعلی الزامی است'); return; }
      if (!newPassword)     { fieldErr('newPassword', 'رمز عبور جدید الزامی است'); return; }
      if (!confirmPassword) { fieldErr('confirmPassword', 'تکرار رمز عبور الزامی است'); return; }
      if (!pwMeetsPolicy(newPassword)) { updatePassRules(newPassword); fieldErr('newPassword', 'رمز عبور همه‌ی قوانین زیر را رعایت نمی‌کند'); return; }
      if (newPassword !== confirmPassword) { fieldErr('confirmPassword', 'با رمز عبور یکسان نیست'); return; }

      btn.disabled    = true;
      btn.textContent = 'در حال ذخیره...';

      try {
        const res  = await fetch(`${API_URL}?action=change_password`, {
          method:  'POST',
          headers: { 'Content-Type': 'application/json', 'X-CSRF-Token': window.CSRF_TOKEN || '' },
          body:    JSON.stringify({ current_password: currentPassword, new_password: newPassword, confirm_password: confirmPassword }),
        });
        const data = await res.json();

        if (data.ok) {
          if (window.Toast) Toast.show('رمز عبور با موفقیت تغییر کرد', 'success', 'ویرایش موفق');
          document.getElementById('currentPassword').value = '';
          document.getElementById('newPassword').value     = '';
          document.getElementById('confirmPassword').value = '';
          const rulesPanel = document.getElementById('passRules');
          if (rulesPanel) rulesPanel.hidden = true;
          if (window.Field) { Field.clear('currentPassword'); Field.clear('newPassword'); Field.clear('confirmPassword'); }
        } else if (data.field) {
          const idByField = { current_password: 'currentPassword', new_password: 'newPassword', confirm_password: 'confirmPassword' };
          fieldErr(idByField[data.field] || 'currentPassword', data.msg || 'خطا در تغییر رمز');
        } else if (window.Toast) {
          Toast.show(data.msg || 'خطا در تغییر رمز', 'error');
        }
      } catch {
        if (window.Toast) Toast.show('خطا در ارتباط با سرور', 'error');
      }

      btn.disabled = false;
      btn.innerHTML = `
        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round">
          <polyline points="20 6 9 17 4 12"/>
        </svg>
        ذخیره رمز عبور جدید
      `;
    }

    /* ── Submit the first/last name form — feedback via the project's shared Toast ── */
    async function submitUpdateName() {
      const firstName = document.getElementById('firstName').value.trim();
      const lastName  = document.getElementById('lastName').value.trim();
      const btn       = document.getElementById('nameSubmitBtn');

      if (!firstName) { fieldErr('firstName', 'نام الزامی است'); return; }
      if (!lastName)  { fieldErr('lastName', 'نام‌خانوادگی الزامی است'); return; }

      // No change from the current value → no request is sent to the server.
      if (firstName === originalFirstName && lastName === originalLastName) {
        if (window.Toast) Toast.show('تغییری برای ذخیره وجود ندارد', 'info');
        return;
      }

      btn.disabled    = true;
      btn.textContent = 'در حال ویرایش...';

      try {
        const res  = await fetch(`${API_URL}?action=update_my_name`, {
          method:  'POST',
          headers: { 'Content-Type': 'application/json', 'X-CSRF-Token': window.CSRF_TOKEN || '' },
          body:    JSON.stringify({ first_name: firstName, last_name: lastName }),
        });
        const data = await res.json();

        if (data.ok) {
          if (window.Toast) Toast.show('ویرایش با موفقیت انجام شد', 'success', 'ویرایش موفق');
          nameFieldsDirty  = false;
          originalFirstName = firstName;
          originalLastName  = lastName;
          if (window.Field) { Field.clear('firstName'); Field.clear('lastName'); }
          document.getElementById('profileDisplayName').textContent = data.display_name || `${firstName} ${lastName}`;
        } else if (data.field) {
          fieldErr(data.field === 'first_name' ? 'firstName' : 'lastName', data.msg || 'خطا در ویرایش');
        } else if (window.Toast) {
          Toast.show(data.msg || 'خطا در ویرایش نام و نام‌خانوادگی', 'error');
        }
      } catch {
        if (window.Toast) Toast.show('خطا در ارتباط با سرور', 'error');
      }

      btn.disabled = false;
      btn.innerHTML = `
        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
          <path d="M11 4H4a2 2 0 00-2 2v14a2 2 0 002 2h14a2 2 0 002-2v-7"/>
          <path d="M18.5 2.5a2.121 2.121 0 013 3L12 15l-4 1 1-4 9.5-9.5z"/>
        </svg>
        ویرایش نام و نام‌خانوادگی
      `;
    }

    (function () {
      const fn = document.getElementById('firstName');
      const ln = document.getElementById('lastName');
      // As soon as typing resumes, the previous error state (red box) is cleared — same
      // pattern as the change-password tab's fields.
      const markDirty = (el) => {
        nameFieldsDirty = true;
        if (window.Field) Field.set(el, document.activeElement === el ? 'focus' : 'idle');
      };
      if (fn) fn.addEventListener('input', () => markDirty(fn));
      if (ln) ln.addEventListener('input', () => markDirty(ln));
    })();

    /* ── Tabs ── */
    const ProfileTabs = {
      _order: ['name', 'password', 'sessions'],
      init() {
        const bar = document.getElementById('profileTabs');
        if (!bar) return;
        bar.querySelectorAll('.profile-tab').forEach(btn => {
          btn.addEventListener('click', () => this.show(btn.dataset.tab));
        });
        this.show('name', false);
        window.addEventListener('resize', () => this._moveIndicator(this._current || 'name'));
      },
      show(tab, animate = true) {
        if (!this._order.includes(tab)) return;
        this._current = tab;
        this._order.forEach(t => {
          const btn   = document.getElementById(`tabBtn-${t}`);
          const panel = document.getElementById(`tabPanel-${t}`);
          const active = t === tab;
          if (btn)   { btn.classList.toggle('active', active); btn.setAttribute('aria-selected', String(active)); }
          if (panel) panel.hidden = !active;
        });
        this._moveIndicator(tab, animate);
      },
      _moveIndicator(tab, animate = true) {
        const bar = document.getElementById('profileTabs');
        const ind = document.getElementById('profileTabIndicator');
        const btn = document.getElementById(`tabBtn-${tab}`);
        if (!bar || !ind || !btn) return;
        const barRect = bar.getBoundingClientRect();
        const btnRect = btn.getBoundingClientRect();
        if (!animate) ind.style.transition = 'none';
        ind.style.width     = btnRect.width + 'px';
        ind.style.transform = `translateX(${btnRect.left - barRect.left}px)`;
        if (!animate) requestAnimationFrame(() => { ind.style.transition = ''; });
      },
    };
    ProfileTabs.init();

    /* ── Enter to submit (depends on the active field) ── */
    document.addEventListener('keydown', e => {
      if (e.key !== 'Enter') return;
      const id = document.activeElement && document.activeElement.id;
      if (id === 'currentPassword' || id === 'newPassword' || id === 'confirmPassword') submitChangePassword();
      if (id === 'firstName' || id === 'lastName') submitUpdateName();
    });

    /* ── Live field validation ── */
    if (window.Field) {
      const $ = (id) => document.getElementById(id);
      // Field.set moves to focus/idle state — this also clears the previous error
      // message (fixes a bug where the error stayed after typing resumed).
      const setFocusIdle = (el) => Field.set(el, document.activeElement === el ? 'focus' : 'idle');

      const curPass = $('currentPassword'), newPass = $('newPassword'), confPass = $('confirmPassword');

      // Any typing in "current password" clears its previous error (this field has no live validation).
      if (curPass) curPass.addEventListener('input', () => setFocusIdle(curPass));

      const syncConfirm = (onBlur) => {
        if (!confPass) return;
        const v = confPass.value;
        if (!v) return setFocusIdle(confPass);
        if (newPass && v === newPass.value) Field.set(confPass, 'success', 'یکسان است');
        else if (onBlur || document.activeElement !== confPass) Field.set(confPass, 'error', 'با رمز عبور یکسان نیست');
        else setFocusIdle(confPass);
      };
      if (newPass) {
        // Show the checklist as soon as it's focused (even if empty), so the user sees the rules.
        newPass.addEventListener('focus', () => updatePassRules(newPass.value));
        newPass.addEventListener('input', () => {
          const v = newPass.value;
          updatePassRules(v);
          if (!v) setFocusIdle(newPass);
          else if (pwMeetsPolicy(v)) Field.set(newPass, 'success', 'رمز مناسب است');
          else setFocusIdle(newPass);   // while typing is incomplete, clear the previous error
          syncConfirm(false);
        });
        newPass.addEventListener('blur', () => {
          const v = newPass.value;
          if (v && !pwMeetsPolicy(v)) Field.set(newPass, 'error', 'رمز عبور همه‌ی قوانین را رعایت نمی‌کند');
          else if (!v && document.getElementById('passRules')) document.getElementById('passRules').hidden = true;
        });
      }
      if (confPass) {
        confPass.addEventListener('input', () => syncConfirm(false));
        confPass.addEventListener('blur',  () => syncConfirm(true));
      }
    }

    /* ── Active sessions (devices) — Telegram-like ── */
    function _escHtml(s) {
      return String(s == null ? '' : s).replace(/[&<>"']/g, c =>
        ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]));
    }

    async function loadMySessions() {
      const box = document.getElementById('acctSessionsList');
      if (!box) return;
      try {
        const res  = await fetch(`${API_URL}?action=my_sessions`);
        const data = await res.json();
        if (!data.ok) { box.innerHTML = '<div class="acct-sessions-empty">خطا در دریافت نشست‌ها</div>'; return; }
        renderMySessions(data.sessions || []);
      } catch {
        box.innerHTML = '<div class="acct-sessions-empty">خطا در ارتباط با سرور</div>';
      }
    }

    function renderMySessions(list) {
      const box     = document.getElementById('acctSessionsList');
      const killBtn = document.getElementById('acctKillOthers');
      if (!list.length) {
        box.innerHTML = '<div class="acct-sessions-empty">نشست فعالی یافت نشد.</div>';
        if (killBtn) killBtn.style.display = 'none';
        return;
      }
      list.sort((a, b) => (b.is_current ? 1 : 0) - (a.is_current ? 1 : 0));
      box.innerHTML = list.map(s => {
        const when = s.last_seen ? DateFmt.dateTime(s.last_seen * 1000) : '—';
        const dev  = _escHtml(s.device || 'نامشخص');
        const ip   = _escHtml(s.ip || '—');
        const cur  = s.is_current;
        let remaining = '';
        if (s.expires_at) {
          const diff = s.expires_at - Math.floor(Date.now() / 1000);
          if (diff > 0) {
            const h = Math.floor(diff / 3600);
            const m = Math.floor((diff % 3600) / 60);
            remaining = h > 0 ? `${h} ساعت و ${m} دقیقه` : `${m} دقیقه`;
          } else {
            remaining = 'منقضی‌شده';
          }
        }
        return `
          <div class="acct-sess-row${cur ? ' is-current' : ''}">
            <div class="acct-sess-icon">
              <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><rect x="2" y="3" width="20" height="14" rx="2"/><path d="M8 21h8M12 17v4"/></svg>
            </div>
            <div class="acct-sess-info">
              <div class="acct-sess-dev">${dev}${cur ? ' <span class="acct-sess-cur">دستگاه فعلی</span>' : ''}</div>
              <div class="acct-sess-meta"><span dir="ltr">${ip}</span> · ${when}</div>
              ${remaining ? `<div class="acct-sess-meta">باقیمانده: ${remaining}</div>` : ''}
            </div>
            ${cur ? '' : `<button type="button" class="acct-sess-kill" data-act="terminateMySession" data-id="${_escHtml(s.id)}">پایان</button>`}
          </div>`;
      }).join('');
      const others = list.filter(s => !s.is_current).length;
      if (killBtn) killBtn.style.display = others > 0 ? '' : 'none';
    }

    async function terminateMySession(id) {
      try {
        const res  = await fetch(`${API_URL}?action=terminate_my_session`, {
          method: 'POST', headers: { 'Content-Type': 'application/json', 'X-CSRF-Token': window.CSRF_TOKEN || '' },
          body: JSON.stringify({ session_id: id }),
        });
        const data = await res.json();
        if (!data.ok) return;
        if (data.self) { window.location.href = 'index.php'; return; }
        loadMySessions();
      } catch {}
    }

    async function terminateMyOtherSessions() {
      const btn = document.getElementById('acctKillOthers');
      if (btn) btn.disabled = true;
      try {
        await fetch(`${API_URL}?action=terminate_my_other_sessions`, {
          method: 'POST',
          headers: { 'X-CSRF-Token': window.CSRF_TOKEN || '' },
        });
        loadMySessions();
      } catch {} finally { if (btn) btn.disabled = false; }
    }

    /* ── Actions (replaces on* handlers, for CSP) ── */
    if (window.Actions) {
      Actions.register({
        togglePass:               (el) => togglePass(el.dataset.target, el),
        genPassword:              (el) => genPassword(el),
        submitChangePassword:     () => submitChangePassword(),
        submitUpdateName:         () => submitUpdateName(),
        terminateMyOtherSessions: () => terminateMyOtherSessions(),
        terminateMySession:       (el) => terminateMySession(el.dataset.id),
      });
    }

    /* ── Init ── */
    loadProfile();
    loadMySessions();

    /* If an admin or the user themself (from another tab) edits the name/email, this
       page updates itself within ~30 seconds, without needing to log out/in again.
       The interval has random jitter (25 to 30 seconds) so different tabs' polls don't sync up. */
    (function pollTick() {
      setTimeout(() => {
        if (!document.hidden) loadProfile();
        pollTick();
      }, 25000 + Math.floor(Math.random() * 5000));
    })();
    document.addEventListener('visibilitychange', () => {
      if (!document.hidden) loadProfile();
    });
