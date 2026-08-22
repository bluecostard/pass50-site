'use strict';

(() => {
  const SESSION_USER_KEY = 'pass50_session_user';
  const LEGACY_SESSION_KEY = 'pass50_session';
  const TOKEN_KEY = 'pass50_api_token';
  const DEVICE_KEY = 'pass50_device_id';

  function migrateLegacySession() {
    try {
      const legacy = sessionStorage.getItem(LEGACY_SESSION_KEY);
      if (legacy && !localStorage.getItem(SESSION_USER_KEY)) {
        localStorage.setItem(SESSION_USER_KEY, legacy);
      }
      if (legacy) sessionStorage.removeItem(LEGACY_SESSION_KEY);
    } catch (_) {}
  }

  function getDeviceId() {
    try {
      let id = localStorage.getItem(DEVICE_KEY);
      if (!id) {
        id =
          (typeof crypto !== 'undefined' && crypto.randomUUID && crypto.randomUUID()) ||
          `p50_${Date.now()}_${Math.random().toString(36).slice(2, 12)}`;
        localStorage.setItem(DEVICE_KEY, id);
      }
      return id;
    } catch (_) {
      return 'anonymous';
    }
  }

  function getSessionUserId() {
    migrateLegacySession();
    try {
      return localStorage.getItem(SESSION_USER_KEY) || null;
    } catch (_) {
      return null;
    }
  }

  function setSessionUserId(userId) {
    try {
      if (userId) localStorage.setItem(SESSION_USER_KEY, String(userId));
      else localStorage.removeItem(SESSION_USER_KEY);
      sessionStorage.removeItem(LEGACY_SESSION_KEY);
    } catch (_) {}
  }

  function getToken() {
    migrateLegacySession();
    try {
      return localStorage.getItem(TOKEN_KEY) || '';
    } catch (_) {
      return '';
    }
  }

  function setToken(token) {
    try {
      if (token) localStorage.setItem(TOKEN_KEY, String(token));
      else localStorage.removeItem(TOKEN_KEY);
    } catch (_) {}
  }

  function clearAuth() {
    setToken('');
    setSessionUserId(null);
  }

  function hasStoredAuth() {
    return Boolean(getToken() || getSessionUserId());
  }

  function isAuthExpiredError(err) {
    if (!err) return false;
    if (Number(err.status) === 401) return true;
    return /session expir|connexion requise/i.test(String(err.message || ''));
  }

  migrateLegacySession();

  window.P50Auth = Object.freeze({
    SESSION_USER_KEY,
    TOKEN_KEY,
    DEVICE_KEY,
    getDeviceId,
    getSessionUserId,
    setSessionUserId,
    getToken,
    setToken,
    clearAuth,
    hasStoredAuth,
    isAuthExpiredError,
  });
})();
