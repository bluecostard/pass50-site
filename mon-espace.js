'use strict';

(() => {
  const API_BASE = './api';
  const params = new URLSearchParams(location.search);
  const isNative =
    params.get('native') === '1' || /Pass50Native/i.test(navigator.userAgent || '');

  if (isNative) {
    document.documentElement.classList.add('pass50-native-app');
    document.body.dataset.pass50Native = '1';
    try {
      localStorage.setItem('pass50_onboarding_seen_v1', '1');
    } catch (_) {}
  }

  const authPanel = document.getElementById('authPanel');
  const userPanel = document.getElementById('userPanel');
  const authForm = document.getElementById('authForm');
  const authStatus = document.getElementById('authStatus');
  const authSubmit = document.getElementById('authSubmit');
  const displayNameField = document.getElementById('displayNameField');
  const passwordField = document.getElementById('passwordField');
  const forgotBtn = document.getElementById('forgotBtn');
  const toastEl = document.getElementById('toast');

  let mode = 'login';

  function toast(message) {
    if (!toastEl) return;
    toastEl.textContent = message;
    toastEl.classList.add('show');
    clearTimeout(toastEl._t);
    toastEl._t = setTimeout(() => toastEl.classList.remove('show'), 2600);
  }

  function setStatus(message, ok) {
    if (!authStatus) return;
    authStatus.textContent = message || '';
    authStatus.classList.toggle('ok', Boolean(ok));
  }

  function getToken() {
    try {
      if (window.P50Auth && typeof P50Auth.getToken === 'function') return P50Auth.getToken() || '';
      return localStorage.getItem('pass50_api_token') || '';
    } catch (_) {
      return '';
    }
  }

  function setToken(value) {
    if (window.P50Auth && typeof P50Auth.setToken === 'function') P50Auth.setToken(value);
    else {
      try {
        if (value) localStorage.setItem('pass50_api_token', value);
        else localStorage.removeItem('pass50_api_token');
      } catch (_) {}
    }
  }

  function setSessionUserId(userId) {
    if (window.P50Auth && typeof P50Auth.setSessionUserId === 'function') {
      P50Auth.setSessionUserId(userId);
    }
  }

  function clearAuth() {
    if (window.P50Auth && typeof P50Auth.clearAuth === 'function') P50Auth.clearAuth();
    else {
      try {
        localStorage.removeItem('pass50_api_token');
        localStorage.removeItem('pass50_session_user');
      } catch (_) {}
    }
  }

  function deviceId() {
    if (window.P50Auth && typeof P50Auth.getDeviceId === 'function') return P50Auth.getDeviceId();
    return '';
  }

  async function api(path, { method = 'GET', body = null, auth = true } = {}) {
    const headers = {};
    if (auth && getToken()) headers.Authorization = 'Bearer ' + getToken();
    if (body !== null) headers['Content-Type'] = 'application/json';
    const res = await fetch(API_BASE + '/' + String(path).replace(/^\//, ''), {
      method,
      headers,
      body: body !== null ? JSON.stringify(body) : undefined,
      cache: 'no-store',
    });
    let data = null;
    try {
      data = await res.json();
    } catch (_) {
      data = null;
    }
    if (!res.ok) {
      const err = new Error((data && (data.error || data.message)) || 'HTTP ' + res.status);
      err.status = res.status;
      throw err;
    }
    return data || {};
  }

  function setMode(next) {
    mode = next;
    authForm.dataset.mode = next;
    document.querySelectorAll('[data-auth-tab]').forEach((btn) => {
      btn.classList.toggle('active', btn.getAttribute('data-auth-tab') === next);
    });
    const isForgot = next === 'forgot';
    const isSignup = next === 'signup';
    displayNameField.hidden = !isSignup;
    passwordField.hidden = isForgot;
    document.getElementById('password').required = !isForgot;
    document.getElementById('displayName').required = isSignup;
    document.getElementById('password').autocomplete = isSignup ? 'new-password' : 'current-password';
    authSubmit.textContent = isForgot
      ? 'Recevoir le lien'
      : isSignup
        ? 'Créer mon compte'
        : 'Se connecter';
    forgotBtn.classList.toggle('hidden', isForgot || isSignup);
    setStatus('');
  }

  function showAuth() {
    authPanel.classList.remove('hidden');
    userPanel.classList.add('hidden');
  }

  function initials(name) {
    const parts = String(name || 'M')
      .trim()
      .split(/\s+/)
      .map((x) => x[0])
      .join('');
    return (parts || 'M').slice(0, 2).toUpperCase();
  }

  function showUser(user) {
    authPanel.classList.add('hidden');
    userPanel.classList.remove('hidden');
    const name = user.displayName || user.name || 'Membre';
    const email = user.email || '';
    const avatarUrl = String(user.avatarUrl || user.avatar || '').trim();
    document.getElementById('userName').textContent = name;
    document.getElementById('userEmail').textContent = email;
    const avatar = document.getElementById('userAvatar');
    if (avatarUrl) {
      avatar.innerHTML = '<img src="' + avatarUrl.replace(/"/g, '&quot;') + '" alt="">';
    } else {
      avatar.textContent = initials(name);
    }
  }

  async function refreshMe() {
    if (!getToken()) {
      showAuth();
      return null;
    }
    try {
      const data = await api('me.php');
      const user = data.user || data;
      if (user && user.id) setSessionUserId(user.id);
      showUser(user);
      return user;
    } catch (err) {
      if (
        (window.P50Auth && P50Auth.isAuthExpiredError && P50Auth.isAuthExpiredError(err)) ||
        Number(err.status) === 401
      ) {
        clearAuth();
      }
      showAuth();
      return null;
    }
  }

  async function onSubmit(event) {
    event.preventDefault();
    setStatus('');
    authSubmit.disabled = true;
    const fd = new FormData(authForm);
    const email = String(fd.get('email') || '')
      .trim()
      .toLowerCase();
    const password = String(fd.get('password') || '');
    const displayName = String(fd.get('displayName') || '').trim();
    try {
      if (mode === 'forgot') {
        await api('forgot-password.php', { method: 'POST', body: { email }, auth: false });
        setStatus('Si un compte existe, un e-mail a été envoyé.', true);
        toast('Vérifie ta boîte mail');
        return;
      }
      if (mode === 'signup') {
        await api('register.php', {
          method: 'POST',
          body: { email, password, displayName },
          auth: false,
        });
        setStatus('Compte créé. Confirme ton e-mail puis connecte-toi.', true);
        toast('E-mail de confirmation envoyé');
        setMode('login');
        return;
      }
      const data = await api('login.php', {
        method: 'POST',
        body: { email, password, deviceId: deviceId() },
        auth: false,
      });
      setToken(data.token || '');
      if (data.user && data.user.id) setSessionUserId(data.user.id);
      showUser(data.user || { email, displayName: email });
      toast('Connexion réussie');
      await refreshMe();
    } catch (err) {
      setStatus(err.message || 'Connexion impossible');
    } finally {
      authSubmit.disabled = false;
    }
  }

  async function onLogout() {
    try {
      if (getToken()) await api('logout.php', { method: 'POST', body: {} });
    } catch (_) {}
    clearAuth();
    setMode('login');
    showAuth();
    toast('Déconnecté');
  }

  document.querySelectorAll('[data-auth-tab]').forEach((btn) => {
    btn.addEventListener('click', () => setMode(btn.getAttribute('data-auth-tab')));
  });
  forgotBtn.addEventListener('click', () => setMode('forgot'));
  authForm.addEventListener('submit', onSubmit);
  document.getElementById('logoutBtn').addEventListener('click', onLogout);

  setMode('login');
  refreshMe();
})();
