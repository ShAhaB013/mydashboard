    const API_URL = 'api.php';

    /* ── Theme: جلوگیری از فلش اولیه (FOUC) ── */
    (function () {
      const saved      = localStorage.getItem('theme');
      const prefersDark = window.matchMedia('(prefers-color-scheme: dark)').matches;
      if (saved === 'dark' || (!saved && prefersDark)) {
        document.documentElement.setAttribute('data-theme', 'dark');
      }
    })();
    /* سوییچ تم بدون لگ + همگام بین تب‌ها در theme.js انجام می‌شود. */

    /* ── نمایش اطلاعات کاربر ── */
    async function loadProfile() {
      try {
        const res  = await fetch(`${API_URL}?action=me`);
        const data = await res.json();

        if (!data.ok || !data.logged_in) {
          // اگه لاگین نیست برگرد به صفحه اصلی
          window.location.href = 'index.php';
          return;
        }

        const display = data.display_name || data.username || '';

        document.getElementById('profileDisplayName').textContent = display;
        document.getElementById('profileEmail').textContent       = data.email || '—';
        document.getElementById('profileAvatar').textContent      =
          display ? [...display][0] : '؟';

        // بازگرداندن مرحله تأیید تغییر ایمیل پس از ریلود — کول‌داون از سرور می‌آید
        // (پس با رفرش صفحه، محدودیت ارسال مجدد دور زده نمی‌شود)
        if (data.email_change && data.email_change.email) {
          document.getElementById('newEmail').value = data.email_change.email;
          _revealEmailCodeStep(data.email_change.email);
          _runEmailCooldown(data.email_change.retry_after || 0);
        }

      } catch {
        window.location.href = 'index.php';
      }
    }

    /* ── نمایش/مخفی کردن رمز ── */
    function togglePass(inputId, btn) {
      const input = document.getElementById(inputId);
      const isPass = input.type === 'password';
      input.type = isPass ? 'text' : 'password';

      // تغییر آیکون
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

    /* ── قدرت رمز ── */
    function checkStrength(val) {
      const bar   = document.getElementById('passStrength');
      const label = document.getElementById('passStrengthLabel');

      if (!val) {
        bar.style.display   = 'none';
        label.textContent   = '';
        bar.className       = 'pass-strength';
        return;
      }

      bar.style.display = 'flex';

      let score = 0;
      if (val.length >= 8)              score++;
      if (/[A-Z]/.test(val))            score++;
      if (/[0-9]/.test(val))            score++;
      if (/[^A-Za-z0-9]/.test(val))    score++;

      const levels = ['', 'ضعیف', 'متوسط', 'خوب', 'قوی'];
      bar.className   = `pass-strength strength-${score || 1}`;
      label.textContent = val.length < 6 ? 'خیلی کوتاه' : levels[score] || 'ضعیف';
    }

    /* ── سیاست رمز: حداقل «متوسط» (هم‌راستا با PasswordPolicy سمت سرور) ── */
    function pwMeetsPolicy(val) {
      if (!val || val.length < 6) return false;
      let score = 0;
      if (val.length >= 8)          score++;
      if (/[A-Z]/.test(val))        score++;
      if (/[0-9]/.test(val))        score++;
      if (/[^A-Za-z0-9]/.test(val)) score++;
      return score >= 2;
    }

    /* ── نمایش پیام ── */
    function showMsg(text, type = 'error') {
      const wrap = document.getElementById('profileMsg');
      const icon = document.getElementById('profileMsgIcon');
      const txt  = document.getElementById('profileMsgText');

      txt.textContent  = text;
      wrap.className   = `profile-msg show ${type}`;
      icon.innerHTML   = type === 'success'
        ? '<polyline points="20 6 9 17 4 12"/>'
        : '<circle cx="12" cy="12" r="10"/><line x1="12" y1="8" x2="12" y2="12"/><line x1="12" y1="16" x2="12.01" y2="16"/>';
    }

    function hideMsg() {
      document.getElementById('profileMsg').className = 'profile-msg';
    }

    /* خطای اعتبارسنجی فقط زیر باکس همان فیلد (نه profile-msg) */
    function fieldErr(id, msg) {
      if (window.Field) Field.set(id, 'error', msg);
      else showMsg(msg);
    }

    /* ── ارسال فرم ── */
    async function submitChangePassword() {
      hideMsg();

      const currentPassword = document.getElementById('currentPassword').value;
      const newPassword     = document.getElementById('newPassword').value;
      const confirmPassword = document.getElementById('confirmPassword').value;
      const btn             = document.getElementById('profileSubmitBtn');

      // اعتبارسنجی فقط زیر باکس (field-msg) — بدون profile-msg
      if (!currentPassword) { fieldErr('currentPassword', 'رمز عبور فعلی الزامی است'); return; }
      if (!newPassword)     { fieldErr('newPassword', 'رمز عبور جدید الزامی است'); return; }
      if (!confirmPassword) { fieldErr('confirmPassword', 'تکرار رمز عبور الزامی است'); return; }
      if (!pwMeetsPolicy(newPassword)) { fieldErr('newPassword', 'رمز ضعیف است؛ حداقل ۶ کاراکتر همراه حروف بزرگ، عدد یا نماد'); return; }
      if (newPassword !== confirmPassword) { fieldErr('confirmPassword', 'با رمز عبور یکسان نیست'); return; }

      btn.disabled    = true;
      btn.textContent = 'در حال ذخیره...';

      try {
        const res  = await fetch(`${API_URL}?action=change_password`, {
          method:  'POST',
          headers: { 'Content-Type': 'application/json' },
          body:    JSON.stringify({ current_password: currentPassword, new_password: newPassword, confirm_password: confirmPassword }),
        });
        const data = await res.json();

        if (data.ok) {
          showMsg('رمز عبور با موفقیت تغییر کرد', 'success');
          // پاک کردن فیلدها
          document.getElementById('currentPassword').value = '';
          document.getElementById('newPassword').value     = '';
          document.getElementById('confirmPassword').value = '';
          checkStrength('');
        } else {
          showMsg(data.msg || 'خطا در تغییر رمز');
        }
      } catch {
        showMsg('خطا در ارتباط با سرور');
      }

      btn.disabled = false;
      btn.innerHTML = `
        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round">
          <polyline points="20 6 9 17 4 12"/>
        </svg>
        ذخیره رمز عبور جدید
      `;
    }

    /* ══════════ تغییر ایمیل (با کد تایید) ══════════ */
    let _emailStep   = 'request';   // 'request' | 'verify'
    let _emailTarget = '';          // ایمیل جدید در انتظار تایید
    let _emailTimer  = null;

    function showEmailMsg(text, type = 'error') {
      const wrap = document.getElementById('emailMsg');
      const icon = document.getElementById('emailMsgIcon');
      const txt  = document.getElementById('emailMsgText');
      txt.textContent = text;
      wrap.className  = `profile-msg show ${type}`;
      icon.innerHTML  = type === 'success'
        ? '<polyline points="20 6 9 17 4 12"/>'
        : '<circle cx="12" cy="12" r="10"/><line x1="12" y1="8" x2="12" y2="12"/><line x1="12" y1="16" x2="12.01" y2="16"/>';
    }
    function hideEmailMsg() {
      document.getElementById('emailMsg').className = 'profile-msg';
    }

    function _setEmailLabel(t) {
      document.getElementById('emailSubmitLabel').textContent = t;
    }

    function _toFa(n) { return String(n).replace(/\d/g, d => '۰۱۲۳۴۵۶۷۸۹'[d]); }

    /* حالت لودینگ روی دکمه اصلی (اسپینر، مثل صفحه ورود) */
    function _setEmailLoading(on) {
      const b = document.getElementById('emailSubmitBtn');
      b.classList.toggle('loading', on);
      b.disabled = on;
    }

    /* حالت «در حال ارسال…» روی دکمه ارسال مجدد تا تاخیر SMTP خرابی به نظر نرسد */
    function _setResendSending(on) {
      const btn = document.getElementById('emailResendBtn');
      btn.classList.toggle('sending', on);
      btn.disabled = on;
      const lbl = btn.querySelector('.profile-resend-label');
      if (lbl) lbl.textContent = on ? 'در حال ارسال…' : 'ارسال مجدد';
    }

    /* کول‌داون «ارسال مجدد» — مقدار همیشه از سرور می‌آید (retry_after) تا با ریلود دور زده نشود */
    function _runEmailCooldown(seconds) {
      const btn = document.getElementById('emailResendBtn');
      const lbl = btn.querySelector('.profile-resend-label');
      let s = Math.round(seconds || 0);
      clearInterval(_emailTimer);
      btn.disabled = true;
      const tick = () => {
        if (lbl) lbl.textContent = s > 0 ? `ارسال مجدد (${_toFa(s)})` : 'ارسال مجدد';
        if (s <= 0) { clearInterval(_emailTimer); btn.disabled = false; return; }
        s--;
      };
      tick();
      _emailTimer = setInterval(tick, 1000);
    }

    /* نمایش مرحله کد + یادداشت کد آزمایشی */
    function _revealEmailCodeStep(target) {
      _emailStep   = 'verify';
      _emailTarget = target;
      document.getElementById('emailCodeField').style.display = '';
      document.getElementById('emailCodeTarget').textContent  = target;
      // فیلد ایمیل قفل می‌شود → حالت disabled (تا با رنگ success تداخل نکند)
      if (window.Field) Field.set('newEmail', 'disabled');
      else document.getElementById('newEmail').setAttribute('disabled', 'disabled');
      _setEmailLabel('تایید و تغییر ایمیل');
    }
    function _showEmailDevNote(code) {
      const n = document.getElementById('emailDevNote');
      if (code) { n.textContent = `کد آزمایشی (محیط محلی): ${code}`; n.style.display = 'block'; }
      else      { n.style.display = 'none'; }
    }

    /* دکمه اصلی: بسته به مرحله، کد می‌فرستد یا کد را تایید می‌کند */
    function submitEmailChange() {
      if (_emailStep === 'verify') verifyEmailCodeStep();
      else                          requestEmailCode();
    }

    async function requestEmailCode(isResend = false) {
      hideEmailMsg();
      const email = document.getElementById('newEmail').value.trim();

      if (!email) { fieldErr('newEmail', 'ایمیل جدید را وارد کنید'); return; }
      if (!/^[A-Za-z0-9._%+-]+@(?:[A-Za-z0-9-]+\.)+[A-Za-z]{2,}$/.test(email)) { fieldErr('newEmail', 'قالب ایمیل نامعتبر است'); return; }

      if (isResend) _setResendSending(true); else _setEmailLoading(true);
      try {
        const res  = await fetch(`${API_URL}?action=request_email_change`, {
          method:  'POST',
          headers: { 'Content-Type': 'application/json' },
          body:    JSON.stringify({ email }),
        });
        const data = await res.json();
        if (isResend) _setResendSending(false); else _setEmailLoading(false);

        if (data.ok) {
          _revealEmailCodeStep(data.email || email);
          _showEmailDevNote(data.dev_code);
          _runEmailCooldown(data.retry_after || data.resend_cooldown || 30);
          showEmailMsg(isResend ? 'کد جدید ارسال شد' : (data.msg || 'کد تایید ارسال شد'), 'success');
          document.getElementById('emailCode').focus();
        } else if (data.retry_after) {
          // محدودیت سمت سرور (حتی پس از ریلود صفحه دور زده نمی‌شود) — مرحله کد را نشان بده و شمارش سرور را اعمال کن
          _revealEmailCodeStep(data.email || email);
          _runEmailCooldown(data.retry_after);
          showEmailMsg(data.msg || 'برای ارسال مجدد کد کمی صبر کنید');
        } else if (data.field === 'email') {
          // خطای ایمیل سرور (نامعتبر/تکراری/یکسان با فعلی) → زیر همان فیلد، یک پیام
          fieldErr('newEmail', data.msg || 'ایمیل معتبر نیست');
        } else {
          showEmailMsg(data.msg || 'ارسال کد ناموفق بود');
        }
      } catch {
        if (isResend) _setResendSending(false); else _setEmailLoading(false);
        showEmailMsg('خطا در ارتباط با سرور');
      }
    }

    function resendEmailCode() {
      if (document.getElementById('emailResendBtn').disabled) return;
      requestEmailCode(true);
    }

    async function verifyEmailCodeStep() {
      hideEmailMsg();
      const code = document.getElementById('emailCode').value.trim();

      if (!/^\d{6}$/.test(code)) { showEmailMsg('کد ۶ رقمی را وارد کنید'); return; }

      _setEmailLoading(true);
      try {
        const res  = await fetch(`${API_URL}?action=verify_email_change`, {
          method:  'POST',
          headers: { 'Content-Type': 'application/json' },
          body:    JSON.stringify({ code }),
        });
        const data = await res.json();
        _setEmailLoading(false);

        if (data.ok) {
          document.getElementById('profileEmail').textContent = data.email || _emailTarget;
          showEmailMsg('ایمیل با موفقیت تغییر کرد', 'success');
          _resetEmailSection(true);
        } else {
          showEmailMsg(data.msg || 'کد تایید نادرست است');
          if (data.expired) _resetEmailSection(false);
        }
      } catch {
        _setEmailLoading(false);
        showEmailMsg('خطا در ارتباط با سرور');
      }
    }

    function cancelEmailChange() {
      hideEmailMsg();
      fetch(`${API_URL}?action=cancel_email_change`, { method: 'POST' }).catch(() => {});
      _resetEmailSection(true);
    }

    function _resetEmailSection(clearInput) {
      clearInterval(_emailTimer);
      _emailStep   = 'request';
      _emailTarget = '';
      document.getElementById('emailCodeField').style.display = 'none';
      document.getElementById('emailCode').value              = '';
      document.getElementById('emailDevNote').style.display   = 'none';
      document.getElementById('newEmail').removeAttribute('disabled');
      if (clearInput) document.getElementById('newEmail').value = '';
      // ریست کامل وضعیت فیلد (idle) — رنگ success/disabled پاک شود
      if (window.Field) Field.clear('newEmail');
      const rb = document.getElementById('emailResendBtn');
      rb.disabled = true; rb.classList.remove('sending');
      const rl = rb.querySelector('.profile-resend-label');
      if (rl) rl.textContent = 'ارسال مجدد';
      _setEmailLabel('ارسال کد تایید');
    }

    /* ── Enter برای submit (وابسته به فیلد فعال) ── */
    document.addEventListener('keydown', e => {
      if (e.key !== 'Enter') return;
      const id = document.activeElement && document.activeElement.id;
      if (id === 'newEmail' || id === 'emailCode')                                     submitEmailChange();
      else if (id === 'currentPassword' || id === 'newPassword' || id === 'confirmPassword') submitChangePassword();
    });

    /* ── اعتبارسنجی زنده فیلدها (مثل فرم ثبت‌نام) ── */
    if (window.Field) {
      const $ = (id) => document.getElementById(id);
      const emailValid = (v) => /^[A-Za-z0-9._%+-]+@(?:[A-Za-z0-9-]+\.)+[A-Za-z]{2,}$/.test(v);
      const setFocusIdle = (el) => {
        const f = el.closest('.field');
        if (f) f.setAttribute('data-state', document.activeElement === el ? 'focus' : 'idle');
      };
      const newEmail = $('newEmail'), newPass = $('newPassword'), confPass = $('confirmPassword');
      if (newEmail) {
        // ایمیل: تایید نهایی با سرور است؛ سبز زودهنگام نده تا با رد سرور تداخل نکند.
        newEmail.addEventListener('input', () => setFocusIdle(newEmail));
        newEmail.addEventListener('blur', () => {
          const v = newEmail.value.trim();
          if (v && !emailValid(v)) Field.set(newEmail, 'error', 'قالب ایمیل نامعتبر است');
        });
      }
      const syncConfirm = (onBlur) => {
        if (!confPass) return;
        const v = confPass.value;
        if (!v) return setFocusIdle(confPass);
        if (newPass && v === newPass.value) Field.set(confPass, 'success', 'یکسان است');
        else if (onBlur || document.activeElement !== confPass) Field.set(confPass, 'error', 'با رمز عبور یکسان نیست');
        else setFocusIdle(confPass);
      };
      if (newPass) {
        newPass.addEventListener('input', () => {
          const v = newPass.value;
          if (!v) setFocusIdle(newPass);
          else if (pwMeetsPolicy(v)) Field.set(newPass, 'success', 'رمز مناسب است');
          else setFocusIdle(newPass);
          syncConfirm(false);
        });
        newPass.addEventListener('blur', () => {
          const v = newPass.value;
          if (v && !pwMeetsPolicy(v)) Field.set(newPass, 'error', 'رمز ضعیف است؛ حداقل ۶ کاراکتر همراه حروف بزرگ، عدد یا نماد');
        });
      }
      if (confPass) {
        confPass.addEventListener('input', () => syncConfirm(false));
        confPass.addEventListener('blur',  () => syncConfirm(true));
      }
    }

    /* ── نشست‌های فعال (دستگاه‌ها) — مانند تلگرام ── */
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
        const when = s.last_seen ? new Date(s.last_seen * 1000).toLocaleString('fa-IR') : '—';
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
            ${cur ? '' : `<button type="button" class="acct-sess-kill" onclick="terminateMySession('${_escHtml(s.id)}')">پایان</button>`}
          </div>`;
      }).join('');
      const others = list.filter(s => !s.is_current).length;
      if (killBtn) killBtn.style.display = others > 0 ? '' : 'none';
    }

    async function terminateMySession(id) {
      try {
        const res  = await fetch(`${API_URL}?action=terminate_my_session`, {
          method: 'POST', headers: { 'Content-Type': 'application/json' },
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
        await fetch(`${API_URL}?action=terminate_my_other_sessions`, { method: 'POST' });
        loadMySessions();
      } catch {} finally { if (btn) btn.disabled = false; }
    }

    /* ── init ── */
    loadProfile();
    loadMySessions();
