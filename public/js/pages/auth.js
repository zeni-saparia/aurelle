// Handles login, register, forgot-password and reset-password pages (selected by <body data-page>).
import { api, session } from '../api.js';
import { mountLayout } from '../layout.js';
import { mergeGuestCart } from '../store.js';
import { qs, qsa, icon, buttonBusy, esc } from '../ui.js';

mountLayout('');
const page = document.body.dataset.page, kind = location.pathname.split('/').pop().replace('.html', '');
const form = qs('#form'), msg = qs('#msg');
const show = (text, type = 'error') => { msg.innerHTML = `<div class="alert ${type}">${esc(text)}</div>`; msg.scrollIntoView({ block: 'nearest' }); };

// Show / hide password toggles
qsa('[data-toggle]').forEach(b => {
  b.innerHTML = icon('eye');
  b.addEventListener('click', () => { const i = qs('#' + b.dataset.toggle), on = i.type === 'password'; i.type = on ? 'text' : 'password'; b.innerHTML = icon(on ? 'eyeOff' : 'eye'); b.setAttribute('aria-label', on ? 'Hide password' : 'Show password'); });
});

// Password strength indicator (register)
if (qs('#meter')) qs('#password').addEventListener('input', e => {
  const v = e.target.value; let s = 0;
  if (v.length >= 8) s++; if (/[A-Z]/.test(v) && /[a-z]/.test(v)) s++; if (/\d/.test(v)) s++; if (/[^A-Za-z0-9]/.test(v) || v.length >= 12) s++;
  const m = qs('#meter'); m.style.width = s * 25 + '%'; m.style.background = ['var(--danger)', 'var(--danger)', 'var(--gold)', 'var(--ok)', 'var(--ok)'][s];
  qs('#meter-text').textContent = v ? ['Too weak', 'Weak', 'Fair', 'Good', 'Strong'][s] : 'At least 8 characters with a letter and a number.';
});

const valid = (o, fields) => {
  if (fields.includes('email') && !/^\S+@\S+\.\S+$/.test(o.email || '')) return 'Enter a valid email address.';
  if (fields.includes('full_name') && (o.full_name || '').trim().length < 2) return 'Enter your full name.';
  if (fields.includes('password') && !(o.password?.length >= 8 && /[A-Za-z]/.test(o.password) && /\d/.test(o.password))) return 'Password must be at least 8 characters with a letter and a number.';
  if (fields.includes('confirm_password') && o.password !== o.confirm_password) return 'Passwords do not match.';
};

form.addEventListener('submit', async e => {
  e.preventDefault(); msg.innerHTML = '';
  const o = Object.fromEntries(new FormData(form)); const btn = form.querySelector('button[type=submit],button.btn');
  const fields = { login: ['email'], register: ['full_name', 'email', 'password', 'confirm_password'], 'forgot-password': ['email'], 'reset-password': ['password', 'confirm_password'] }[kind];
  const bad = valid(o, fields); if (bad) return show(bad);
  buttonBusy(btn, true, 'One moment…');
  try {
    if (kind === 'login' || kind === 'register') {
      const r = await api(kind === 'login' ? '/auth/login' : '/auth/register', { method: 'POST', body: o });
      if (!r.session) { form.reset(); return show(r.message, 'ok'); }
      session.set(r.session, kind === 'login' ? qs('#remember').checked : true);
      await mergeGuestCart();
      const me = await api('/me'); const next = sessionStorage.getItem('aurelle.next'); sessionStorage.removeItem('aurelle.next');
      location.href = next || (me.role === 'admin' ? '/admin/index.html' : '/account.html');
    } else if (kind === 'forgot-password') {
      const r = await api('/auth/forgot', { method: 'POST', body: o }); show(r.message, 'ok'); form.reset();
    } else {
      const hash = new URLSearchParams(location.hash.slice(1)); const token = hash.get('access_token');
      if (!token) throw new Error('This reset link is invalid. Request a new one from the sign-in page.');
      const r = await api('/auth/reset', { method: 'POST', body: { ...o, access_token: token } }); show(r.message, 'ok'); form.reset(); history.replaceState(null, '', location.pathname);
    }
  } catch (err) { show(err.message); }
  buttonBusy(btn, false);
});
