    'use strict';

    /* ══ پیام‌های اعتبارسنجی مشترک فرم فراموشی رمز ══ */
    const MSG = {
      emailInvalid:   'ایمیل واردشده معتبر نیست',
      pwWeak:         'رمز عبور باید بین ۱۰ تا ۶۴ کاراکتر و شامل حروف کوچک و بزرگ انگلیسی، عدد و نماد باشد',
      pwMismatch:     'رمز عبور و تکرارش یکسان نیستند',
      codeIncomplete: 'کد ۶ رقمی را کامل وارد کنید',
    };

    /* ══ Toast صفحه (پیاده‌سازی مشترک در theme.js) + خطای روی فیلد (قاب قرمز) + ریست با تایپ ══ */
    function showToast(msg, type, title) { Toast.show(msg, type || 'error', title); }
    function _fieldComp(id) { const el = document.getElementById(id); return (el && el.closest) ? el.closest('.field') : null; }
    function _fieldWrap(id) { const el = document.getElementById(id); return el ? (el.closest('.login-input-wrap') || el) : null; }
    function markFieldError(id) { const w = _fieldWrap(id); if (w) w.classList.add('has-error'); }
    function clearFieldError(id) {
      // فیلدهای کامپوننت جدید (.field) → پاکسازی با helper مشترک
      if (_fieldComp(id) && window.Field) { window.Field.clear(id); return; }
      const w = _fieldWrap(id); if (w) w.classList.remove('has-error');
    }
    function clearAllErrors(form) {
      if (!form) return;
      form.querySelectorAll('.has-error').forEach(e => e.classList.remove('has-error'));
      if (window.Field) form.querySelectorAll('.field[data-state="error"]').forEach(f => {
        const inp = f.querySelector('.field-input'); if (inp) window.Field.clear(inp);
      });
    }
    /* علامت‌گذاری فیلد + پیام + فوکوس؛ همیشه false برمی‌گرداند تا در شرط‌ها به‌راحتی return شود. */
    function failField(id, msg) {
      if (_fieldComp(id) && window.Field) {
        window.Field.set(id, 'error', msg);
      } else {
        markFieldError(id);
        showToast(msg, 'error');
      }
      const el = document.getElementById(id);
      if (el) el.focus();
      return false;
    }

    // ریست خطای هر باکس به‌محض تایپ کاربر در همان باکس
    ['loginUsername', 'loginPassword', 'fpEmail', 'fpCode', 'fpPassword', 'fpConfirm'].forEach(id => {
      const el = document.getElementById(id);
      if (el) el.addEventListener('input', () => clearFieldError(id));
    });

    /* ── آیکن‌های چشم (نمایش/مخفی) ── */
    const EYE_SVG     = '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"><path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"/><circle cx="12" cy="12" r="3"/></svg>';
    const EYE_OFF_SVG = '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"><path d="M17.94 17.94A10.07 10.07 0 0112 20c-7 0-11-8-11-8a18.45 18.45 0 015.06-5.94"/><path d="M9.9 4.24A9.12 9.12 0 0112 4c7 0 11 8 11 8a18.5 18.5 0 01-2.16 3.19"/><line x1="1" y1="1" x2="23" y2="23"/></svg>';

    /* ── نمایش/مخفی کردن رمز ── */
    function togglePass(inputId, btn) {
      const input = document.getElementById(inputId);
      if (!input) return;
      const willShow = input.type === 'password';
      input.type = willShow ? 'text' : 'password';
      btn.innerHTML = willShow ? EYE_OFF_SVG : EYE_SVG;
    }

    /* ── سیاست رمز عبور (هم‌راستا با PasswordPolicy سرور: ۱۰-۶۴ کاراکتر + کوچک/بزرگ/عدد/نماد) ── */
    function pwMeetsPolicy(val) {
      return !!val && val.length >= 10 && val.length <= 64
        && /[a-z]/.test(val) && /[A-Z]/.test(val) && /[0-9]/.test(val) && /[^A-Za-z0-9]/.test(val);
    }

    /* ── تولید رمز تصادفی، قوی و یکتا (Web Crypto) ── */
    function generatePassword(passId, confirmId) {
      const U = 'ABCDEFGHJKLMNPQRSTUVWXYZ';   // بدون I,O مبهم
      const L = 'abcdefghijkmnopqrstuvwxyz';   // بدون l مبهم
      const D = '23456789';                    // بدون 0,1 مبهم
      const S = '!@#$%^&*-_=+?';
      const ALL = U + L + D + S;
      const rnd = (n) => { const a = new Uint32Array(1); crypto.getRandomValues(a); return a[0] % n; };

      const len = 14 + rnd(5); // طول ۱۴ تا ۱۸
      const out = [U[rnd(U.length)], L[rnd(L.length)], D[rnd(D.length)], S[rnd(S.length)]]; // حداقل یکی از هر دسته
      while (out.length < len) out.push(ALL[rnd(ALL.length)]);
      for (let i = out.length - 1; i > 0; i--) { const j = rnd(i + 1); [out[i], out[j]] = [out[j], out[i]]; } // درهم‌ریزی
      const pwd = out.join('');

      const p = document.getElementById(passId);
      const c = confirmId ? document.getElementById(confirmId) : null;
      p.value = pwd; if (c) c.value = pwd;
      p.type = 'text'; // نمایش رمز تولیدشده تا کاربر ببیند/کپی کند
      const eye = p.parentElement.querySelector('.login-pass-toggle');
      if (eye) eye.innerHTML = EYE_OFF_SVG;
      if (window.Field) Field.set(p, 'success', 'رمز مناسب است');
      p.focus();
    }

    /* ══ ارسال فرم ورود ══ */
    function setLoading(btn, on, idleLabel) {
      btn.classList.toggle('loading', on);
      btn.disabled = on;
      const lbl = btn.querySelector('.login-btn-label');
      if (lbl && idleLabel && !on) lbl.textContent = idleLabel;
    }

    async function submitAuth(action, payload, btn, idleLabel, errFields) {
      setLoading(btn, true);
      try {
        const res  = await fetch('api.php?action=' + action, {
          method:  'POST',
          headers: { 'Content-Type': 'application/json' },
          body:    JSON.stringify(payload),
        });
        const data = await res.json();
        if (data.ok) {
          btn.classList.remove('loading');
          btn.classList.add('success'); // همان دکمه: سبز می‌شود و تیک می‌خورد
          setTimeout(() => window.location.replace('index.php'), 700);
          return;
        }
        (errFields || []).forEach(markFieldError);
        showToast(data.msg || 'خطایی رخ داد', 'error');
      } catch (err) {
        showToast('خطا در ارتباط با سرور', 'error');
      }
      setLoading(btn, false, idleLabel);
    }

    /* ورود */
    const loginForm = document.getElementById('loginForm');
    loginForm.addEventListener('submit', (e) => {
      e.preventDefault();
      const username = document.getElementById('loginUsername').value.trim();
      const password = document.getElementById('loginPassword').value;
      if (!username) return failField('loginUsername', 'نام کاربری الزامی است');
      if (!password) return failField('loginPassword', 'رمز عبور الزامی است');
      submitAuth('login', { username, password },
                 document.getElementById('loginSubmitBtn'), 'ورود', ['loginUsername', 'loginPassword']);
    });

    /* ══════════ فراموشی رمز عبور (سه‌مرحله‌ای) ══════════ */
    function toFa(n) { return String(n).replace(/\d/g, d => '۰۱۲۳۴۵۶۷۸۹'[d]); }
    let RESEND_COOLDOWN = 30;

    const forgotForm   = document.getElementById('forgotForm');
    const fpSubmitBtn  = document.getElementById('fpSubmitBtn');
    const fpBack       = document.getElementById('fpBack');
    const fpResendBtn  = document.getElementById('fpResend');
    const fpResendTime = document.getElementById('fpResendTimer');
    const FP_LABELS    = { 1: 'ارسال کد', 2: 'تایید کد', 3: 'تغییر رمز و ورود' };

    function showFpStep(n) {
      forgotForm.dataset.step = n;
      forgotForm.querySelectorAll('.reg-step').forEach(el => { el.hidden = (Number(el.dataset.step) !== n); });
      forgotForm.querySelectorAll('.reg-seg').forEach((s, i) => s.classList.toggle('active', i < n));
      document.getElementById('fpStepNum').textContent = toFa(n);
      forgotForm.querySelector('#fpSubmitBtn .login-btn-label').textContent = FP_LABELS[n];
      clearAllErrors(forgotForm);
      const step = forgotForm.querySelector('.reg-step[data-step="' + n + '"]');
      const first = step && step.querySelector('input');
      if (first && !forgotForm.hidden) setTimeout(() => first.focus(), 70);
    }

    const loginCardHead = document.querySelector('.login-card-head');
    function showForgot() {
      loginForm.hidden = true;
      forgotForm.hidden = false;
      if (loginCardHead) loginCardHead.hidden = true;
      forgotForm.dataset.step = 1;
      showFpStep(1);
      forgotForm.classList.remove('anim-in'); void forgotForm.offsetWidth; forgotForm.classList.add('anim-in');
      history.replaceState(null, '', '#forgot');
    }
    function hideForgot() {
      forgotForm.hidden = true;
      loginForm.hidden = false;
      if (loginCardHead) loginCardHead.hidden = false;
      clearAllErrors(loginForm);
      history.replaceState(null, '', location.pathname);
      setTimeout(() => document.getElementById('loginUsername').focus(), 60);
    }
    function fpGoBack() {
      const step = Number(forgotForm.dataset.step || 1);
      if (step > 1) showFpStep(step - 1); else hideForgot();
    }

    document.getElementById('forgotLink').addEventListener('click', showForgot);
    fpBack.addEventListener('click', fpGoBack);
    if (location.hash === '#forgot') showForgot();

    // فقط رقم در فیلد کد
    document.getElementById('fpCode').addEventListener('input', function () {
      this.value = this.value.replace(/\D/g, '').slice(0, 6);
    });

    const regEmailValid = (v) => /^[A-Za-z0-9._%+-]+@(?:[A-Za-z0-9-]+\.)+[A-Za-z]{2,}$/.test(v);

    /* مرحله ۱ → ۲: ارسال کد بازیابی به ایمیل */
    async function sendForgotCode() {
      const em = document.getElementById('fpEmail').value.trim();
      if (!em) return failField('fpEmail', 'ایمیل الزامی است');
      if (!regEmailValid(em)) return failField('fpEmail', MSG.emailInvalid);
      setLoading(fpSubmitBtn, true);
      try {
        const res = await fetch('api.php?action=forgot_password', {
          method: 'POST', headers: { 'Content-Type': 'application/json' },
          body: JSON.stringify({ email: em }),
        });
        const data = await res.json();
        setLoading(fpSubmitBtn, false, 'ارسال کد');
        if (!data.ok) { failField('fpEmail', data.msg || MSG.emailInvalid); return; }
        document.getElementById('fpEmailEcho').textContent = em;
        if (data.resend_cooldown) RESEND_COOLDOWN = data.resend_cooldown;
        const note = document.getElementById('fpDevNote');
        if (data.dev_code) { note.hidden = false; note.textContent = 'کد تست (محیط محلی): ' + data.dev_code; }
        else { note.hidden = true; note.textContent = ''; }
        showFpStep(2);
        runCooldown(fpResendBtn, fpResendTime, data.retry_after || nextCooldown(fpResendBtn, true));
      } catch (e) {
        setLoading(fpSubmitBtn, false, 'ارسال کد');
        showToast('خطا در ارتباط با سرور', 'error');
      }
    }

    /* مرحله ۲ → ۳: تایید کد (بدون مصرف کد) */
    async function verifyForgotCode() {
      const code = document.getElementById('fpCode').value.trim();
      if (!/^\d{6}$/.test(code)) return failField('fpCode', MSG.codeIncomplete);
      setLoading(fpSubmitBtn, true);
      try {
        const res = await fetch('api.php?action=verify_reset_code', {
          method: 'POST', headers: { 'Content-Type': 'application/json' },
          body: JSON.stringify({ email: document.getElementById('fpEmail').value.trim(), code }),
        });
        const data = await res.json();
        setLoading(fpSubmitBtn, false, 'تایید کد');
        if (!data.ok) { failField('fpCode', data.msg || 'کد نادرست است'); return; }
        showFpStep(3);
      } catch (e) {
        setLoading(fpSubmitBtn, false, 'تایید کد');
        showToast('خطا در ارتباط با سرور', 'error');
      }
    }

    /* مرحله ۳: تنظیم رمز جدید + ورود خودکار */
    async function submitNewPassword() {
      const code = document.getElementById('fpCode').value.trim();
      const p    = document.getElementById('fpPassword').value;
      const c    = document.getElementById('fpConfirm').value;
      if (!pwMeetsPolicy(p)) return failField('fpPassword', MSG.pwWeak);
      if (p !== c) return failField('fpConfirm', MSG.pwMismatch);
      setLoading(fpSubmitBtn, true);
      try {
        const res = await fetch('api.php?action=reset_password', {
          method: 'POST', headers: { 'Content-Type': 'application/json' },
          body: JSON.stringify({ email: document.getElementById('fpEmail').value.trim(), code, password: p, confirm_password: c }),
        });
        const data = await res.json();
        if (data.ok) {
          fpSubmitBtn.classList.remove('loading');
          fpSubmitBtn.classList.add('success');
          setTimeout(() => window.location.replace('index.php'), 700);
          return;
        }
        setLoading(fpSubmitBtn, false, 'تغییر رمز و ورود');
        if (data.field === 'password') failField('fpPassword', data.msg || 'رمز عبور معتبر نیست');
        else failField('fpCode', data.msg || 'کد نادرست است');
      } catch (e) {
        setLoading(fpSubmitBtn, false, 'تغییر رمز و ورود');
        showToast('خطا در ارتباط با سرور', 'error');
      }
    }

    forgotForm.addEventListener('submit', async (e) => {
      e.preventDefault();
      const step = Number(forgotForm.dataset.step || 1);
      if (step === 1) await sendForgotCode();
      else if (step === 2) await verifyForgotCode();
      else await submitNewPassword();
    });

    /* ── ارسال مجدد: شمارش معکوس پلکانی + حالت لودینگ ── */
    const RESEND_MAX = 300; // سقف ۵ دقیقه
    function nextCooldown(btn, reset) {
      btn._cdStep = reset ? 0 : (btn._cdStep || 0) + 1;
      return Math.min(Math.round(RESEND_COOLDOWN * Math.pow(2, btn._cdStep)), RESEND_MAX);
    }
    function runCooldown(btn, timerEl, seconds) {
      let s = Math.round(seconds || RESEND_COOLDOWN);
      btn.disabled = true;
      if (btn._cdTimer) clearInterval(btn._cdTimer);
      const tick = () => {
        timerEl.textContent = s > 0 ? '(' + toFa(s) + ')' : '';
        if (s <= 0) { clearInterval(btn._cdTimer); btn.disabled = false; return; }
        s--;
      };
      tick();
      btn._cdTimer = setInterval(tick, 1000);
    }
    function setResendSending(btn, on) {
      btn.classList.toggle('sending', on);
      btn.disabled = on;
      const lbl = btn.querySelector('.reg-resend-label');
      if (lbl) lbl.textContent = on ? 'در حال ارسال…' : 'ارسال مجدد کد';
    }
    fpResendBtn.addEventListener('click', async () => {
      if (fpResendBtn.disabled) return;
      setResendSending(fpResendBtn, true);
      try {
        const res = await fetch('api.php?action=forgot_password', {
          method: 'POST', headers: { 'Content-Type': 'application/json' },
          body: JSON.stringify({ email: document.getElementById('fpEmail').value.trim() }),
        });
        const data = await res.json();
        setResendSending(fpResendBtn, false);
        if (data.ok) {
          if (data.resend_cooldown) RESEND_COOLDOWN = data.resend_cooldown;
          const note = document.getElementById('fpDevNote');
          if (data.dev_code) { note.hidden = false; note.textContent = 'کد تست (محیط محلی): ' + data.dev_code; }
          if (data.retry_after) runCooldown(fpResendBtn, fpResendTime, data.retry_after);
          else runCooldown(fpResendBtn, fpResendTime, nextCooldown(fpResendBtn, false));
          showToast('کد جدید ارسال شد', 'success', 'ارسال موفق');
        } else if (data.retry_after) {
          if (data.resend_cooldown) RESEND_COOLDOWN = data.resend_cooldown;
          runCooldown(fpResendBtn, fpResendTime, data.retry_after);
          showToast(data.msg || 'برای ارسال مجدد کد کمی صبر کنید', 'error');
        } else showToast(data.msg || 'خطا در ارسال مجدد کد', 'error');
      } catch (e) { setResendSending(fpResendBtn, false); showToast('خطا در ارتباط با سرور', 'error'); }
    });

    /* ── اکشن‌ها (جایگزین on* برای CSP) ── */
    if (window.Actions) {
      Actions.register({
        togglePass:  (el) => togglePass(el.dataset.target, el),
        genPassword: (el) => generatePassword(el.dataset.target, el.dataset.confirm),
      });
    }
