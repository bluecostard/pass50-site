/**
 * PASS50 mobile bridge (Capacitor iOS V1)
 * - Détection app native
 * - Liens externes → Browser plugin
 * - Push APNs → api/push-devices.php
 * - Deep links ?profile= / ?live= / ?section=
 */
(function () {
  'use strict';

  var BRIDGE_VERSION = '1.0.0';
  var DEVICE_KEY = 'pass50_mobile_device_id';

  function isNative() {
    try {
      return !!(window.Capacitor && window.Capacitor.isNativePlatform && window.Capacitor.isNativePlatform());
    } catch (_) {
      return false;
    }
  }

  function plugin(name) {
    try {
      return window.Capacitor && window.Capacitor.Plugins && window.Capacitor.Plugins[name];
    } catch (_) {
      return null;
    }
  }

  function deviceId() {
    try {
      var id = localStorage.getItem(DEVICE_KEY);
      if (id) return id;
      id = 'ios-' + Math.random().toString(36).slice(2) + Date.now().toString(36);
      localStorage.setItem(DEVICE_KEY, id);
      return id;
    } catch (_) {
      return 'ios-anon';
    }
  }

  function apiBase() {
    var cfg = window.PASS50_API || {};
    return String(cfg.baseUrl || './api').replace(/\/$/, '');
  }

  function authHeader() {
    try {
      var token = localStorage.getItem('pass50_api_token') || '';
      return token ? { Authorization: 'Bearer ' + token } : {};
    } catch (_) {
      return {};
    }
  }

  async function registerPushToken(token) {
    var body = {
      action: 'register',
      deviceId: deviceId(),
      platform: 'ios',
      token: token,
      appVersion: BRIDGE_VERSION,
      locale: (navigator.language || 'fr').slice(0, 16),
      topics: { lives: true, ranking: true, coules: false },
    };
    var res = await fetch(apiBase() + '/push-devices.php', {
      method: 'POST',
      headers: Object.assign({ 'Content-Type': 'application/json' }, authHeader()),
      body: JSON.stringify(body),
      cache: 'no-store',
    });
    var data = {};
    try { data = await res.json(); } catch (_) {}
    if (!res.ok) throw new Error(data.error || 'push register failed');
    return data;
  }

  async function setupPush() {
    var Push = plugin('PushNotifications');
    if (!Push) return;
    var perm = await Push.requestPermissions();
    if ((perm && perm.receive) !== 'granted') return;
    await Push.register();
    Push.addListener('registration', function (ev) {
      if (ev && ev.value) {
        registerPushToken(ev.value).catch(function (err) {
          console.warn('PASS50 push register', err);
        });
      }
    });
    Push.addListener('registrationError', function (err) {
      console.warn('PASS50 push registrationError', err);
    });
    Push.addListener('pushNotificationActionPerformed', function (ev) {
      try {
        var data = (ev && ev.notification && ev.notification.data) || {};
        var pass50 = data.pass50 || data;
        if (pass50.profileId) {
          location.search = '?profile=' + encodeURIComponent(pass50.profileId);
        } else if (pass50.liveUrl) {
          openExternal(String(pass50.liveUrl));
        } else if (pass50.section) {
          location.search = '?section=' + encodeURIComponent(pass50.section);
        }
      } catch (e) {
        console.warn('PASS50 push tap', e);
      }
    });
  }

  async function openExternal(url) {
    if (!url) return;
    var Browser = plugin('Browser');
    if (Browser && Browser.open) {
      try {
        await Browser.open({ url: url });
        return;
      } catch (_) {}
    }
    window.open(url, '_blank', 'noopener');
  }

  function interceptExternalLinks() {
    document.addEventListener('click', function (e) {
      if (!isNative()) return;
      var a = e.target && e.target.closest ? e.target.closest('a[href]') : null;
      if (!a) return;
      var href = a.getAttribute('href') || '';
      if (!/^https?:/i.test(href)) return;
      try {
        var u = new URL(href, location.href);
        if (u.hostname === 'pass50.store' || u.hostname === 'www.pass50.store') return;
        e.preventDefault();
        openExternal(u.toString());
      } catch (_) {}
    }, true);
  }

  function markNativeUi() {
    document.documentElement.classList.add('pass50-native-ios');
    document.documentElement.dataset.pass50Native = 'ios';
  }

  async function setupStatusBar() {
    var StatusBar = plugin('StatusBar');
    if (!StatusBar) return;
    try {
      await StatusBar.setStyle({ style: 'DARK' });
      if (StatusBar.setBackgroundColor) await StatusBar.setBackgroundColor({ color: '#050705' });
    } catch (_) {}
  }

  function boot() {
    window.PASS50_MOBILE = {
      version: BRIDGE_VERSION,
      isNative: isNative,
      openExternal: openExternal,
      deviceId: deviceId,
    };
    if (!isNative()) return;
    markNativeUi();
    interceptExternalLinks();
    setupStatusBar();
    setupPush();
  }

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', boot);
  } else {
    boot();
  }
})();
