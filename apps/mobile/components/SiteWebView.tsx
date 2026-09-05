import { useRouter } from 'expo-router';
import { useCallback, useEffect, useMemo, useRef, useState } from 'react';
import { ActivityIndicator, Platform, StyleSheet, Text, View } from 'react-native';
import { useSafeAreaInsets } from 'react-native-safe-area-context';
import { WebView } from 'react-native-webview';

import { Pass50 } from '@/constants/Colors';

const SITE_ORIGIN = 'https://pass50.store';

export type SiteTab = 'ranking' | 'feed' | 'prono' | 'account';

/** Pages site mobile Safari (pas le shell Capacitor). */
export const SITE_URLS: Record<SiteTab, string> = {
  ranking: `${SITE_ORIGIN}/?native=1`,
  feed: `${SITE_ORIGIN}/mon-fil.html?native=1`,
  prono: `${SITE_ORIGIN}/pronostics.html?v=83&native=1`,
  account: `${SITE_ORIGIN}/?native=1&open=account`,
};

const HIDE_SITE_DOCK_JS = `
(function () {
  try {
    var css = document.createElement('style');
    css.id = 'pass50-native-shell-css';
    css.textContent = [
      '.p50-bottom-nav{display:none!important;visibility:hidden!important;pointer-events:none!important;height:0!important;opacity:0!important}',
      'body{padding-bottom:calc(108px + env(safe-area-inset-bottom))!important}',
      'body .app{padding-bottom:calc(108px + env(safe-area-inset-bottom))!important}',
      'body .shell{padding-bottom:calc(108px + env(safe-area-inset-bottom))!important}',
      'html.pass50-native-app body{overscroll-behavior-y:contain}',
      '#pass50-native-login-gate{position:fixed;inset:0;z-index:99999;display:flex;align-items:center;justify-content:center;padding:24px;background:rgba(5,7,5,.92)}',
      '#pass50-native-login-gate .box{max-width:340px;width:100%;border:1px solid rgba(183,255,0,.28);border-radius:22px;background:#0d110d;padding:22px;text-align:center}',
      '#pass50-native-login-gate h2{margin:0 0 10px;color:#f6f8f4;font-size:22px}',
      '#pass50-native-login-gate p{margin:0 0 18px;color:#9da79b;line-height:1.45}',
      '#pass50-native-login-gate button{border:0;border-radius:999px;padding:12px 18px;background:#b7ff00;color:#050705;font-weight:900;font-size:15px}'
    ].join('');
    (document.head || document.documentElement).appendChild(css);
    document.documentElement.classList.add('pass50-native-app');
    document.documentElement.setAttribute('data-pass50-native', '1');
  } catch (e) {}
  true;
})();
`;

/**
 * Empêche mon-fil / pronostics de rediriger vers /?open=account (= Classement).
 * Affiche une porte de connexion locale à la place.
 */
const BLOCK_AUTH_REDIRECT_JS = `
(function () {
  function isAccountRedirect(url) {
    try {
      var u = new URL(String(url), location.href);
      if (u.searchParams.get('open') === 'account') return true;
      return /[?&]open=account(?:&|$)/i.test(String(url));
    } catch (e) {
      return /open=account/i.test(String(url || ''));
    }
  }
  function notify() {
    try {
      if (window.ReactNativeWebView) {
        window.ReactNativeWebView.postMessage(JSON.stringify({ type: 'need-auth', path: location.pathname }));
      }
    } catch (e) {}
  }
  function showLoginGate(label) {
    if (document.getElementById('pass50-native-login-gate')) return;
    var root = document.createElement('div');
    root.id = 'pass50-native-login-gate';
    root.innerHTML = '<div class="box"><h2>Connexion requise</h2><p>' + label + '</p><button type="button" id="pass50-native-login-btn">Se connecter</button></div>';
    (document.body || document.documentElement).appendChild(root);
    var btn = document.getElementById('pass50-native-login-btn');
    if (btn) btn.addEventListener('click', function () { notify(); });
  }
  try {
    var _replace = window.location.replace.bind(window.location);
    var _assign = window.location.assign.bind(window.location);
    window.location.replace = function (url) {
      if (isAccountRedirect(url)) {
        showLoginGate('Connecte-toi pour ouvrir cette section. Tu ne seras plus renvoyé sur le Classement.');
        notify();
        return;
      }
      return _replace(url);
    };
    window.location.assign = function (url) {
      if (isAccountRedirect(url)) {
        showLoginGate('Connecte-toi pour ouvrir cette section. Tu ne seras plus renvoyé sur le Classement.');
        notify();
        return;
      }
      return _assign(url);
    };
  } catch (e) {}
  true;
})();
`;

/**
 * Mon espace = coque compte plein écran.
 * Production : open=account n’ouvre rien tout seul → on force openAuth/openUser.
 * On masque le classement, on affiche un fallback visible (jamais page blanche),
 * et on notifie React Native quand l’UI compte est prête.
 */
const ACCOUNT_SHELL_CSS = `
html.pass50-native-account,
html.pass50-native-account body{
  background:#050705!important;
  overflow:hidden!important;
}
html.pass50-native-account .app,
html.pass50-native-account .p50-bottom-nav,
html.pass50-native-account #liveModal,
html.pass50-native-account #profileModal,
html.pass50-native-account #top50Modal,
html.pass50-native-account #notificationModal,
html.pass50-native-account #voteShareModal,
html.pass50-native-account #fiPhotoLightbox,
html.pass50-native-account .demo-banner,
html.pass50-native-account #pass50-onboarding-root{
  display:none!important;
  visibility:hidden!important;
  pointer-events:none!important;
}
html.pass50-native-account #authModal.show,
html.pass50-native-account #userModal.show,
html.pass50-native-account #adminModal.show,
html.pass50-native-account #toolModal.show{
  display:grid!important;
  place-items:stretch!important;
  align-items:stretch!important;
  padding:0!important;
  background:#050705!important;
  backdrop-filter:none!important;
  -webkit-backdrop-filter:none!important;
  z-index:200000!important;
}
html.pass50-native-account #authModal.show .modal-box,
html.pass50-native-account #userModal.show .modal-box,
html.pass50-native-account #adminModal.show .modal-box,
html.pass50-native-account #toolModal.show .modal-box{
  width:100vw!important;
  max-width:100vw!important;
  min-height:100dvh!important;
  max-height:100dvh!important;
  height:100dvh!important;
  border-radius:0!important;
  margin:0!important;
}
html.pass50-native-account #authModal .close,
html.pass50-native-account #userModal .close{
  display:none!important;
}
#pass50-native-account-placeholder{
  position:fixed;inset:0;z-index:150000;
  display:flex;flex-direction:column;align-items:center;justify-content:center;gap:14px;
  padding:24px;text-align:center;
  background:#050705;color:#b7ff00;font-weight:900;letter-spacing:1px;
}
#pass50-native-account-placeholder .sub{
  color:#9da79b;font-weight:700;letter-spacing:0;max-width:280px;line-height:1.45;
}
#pass50-native-account-placeholder button{
  border:0;border-radius:999px;padding:12px 18px;background:#b7ff00;color:#050705;font-weight:900;font-size:15px;
}
`;

const OPEN_ACCOUNT_JS = `
(function () {
  function post(type, extra) {
    try {
      if (window.ReactNativeWebView) {
        window.ReactNativeWebView.postMessage(JSON.stringify(Object.assign({ type: type }, extra || {})));
      }
    } catch (e) {}
  }
  function ensureCss() {
    var existing = document.getElementById('pass50-native-account-css');
    if (existing) return;
    var css = document.createElement('style');
    css.id = 'pass50-native-account-css';
    css.textContent = ${JSON.stringify(ACCOUNT_SHELL_CSS)};
    (document.head || document.documentElement).appendChild(css);
  }
  function ensurePlaceholder() {
    var el = document.getElementById('pass50-native-account-placeholder');
    if (el) return el;
    el = document.createElement('div');
    el.id = 'pass50-native-account-placeholder';
    el.innerHTML = '<div>MON ESPACE</div><div class="sub">Ouverture du compte…</div><button type="button" id="pass50-native-account-retry">Afficher la connexion</button>';
    (document.body || document.documentElement).appendChild(el);
    var retry = document.getElementById('pass50-native-account-retry');
    if (retry) {
      retry.addEventListener('click', function () {
        forceOpen(true);
      });
    }
    return el;
  }
  function accountOpen() {
    var ids = ['authModal', 'userModal', 'adminModal', 'toolModal'];
    for (var i = 0; i < ids.length; i++) {
      var node = document.getElementById(ids[i]);
      if (node && node.classList.contains('show')) return true;
    }
    return false;
  }
  function syncPlaceholder() {
    var ph = document.getElementById('pass50-native-account-placeholder');
    if (!ph) return;
    var open = accountOpen();
    ph.style.display = open ? 'none' : 'flex';
    if (open) post('account-ready');
  }
  function dismissSiteOnboarding() {
    try { localStorage.setItem('pass50_onboarding_seen_v1', '1'); } catch (e) {}
    try {
      if (window.PASS50Onboarding && typeof window.PASS50Onboarding.close === 'function') {
        window.PASS50Onboarding.close();
      }
    } catch (e) {}
    try {
      var root = document.getElementById('pass50-onboarding-root');
      if (root) root.remove();
    } catch (e) {}
  }
  function forceOpen(fromUser) {
    ensureCss();
    document.documentElement.classList.add('pass50-native-account');
    dismissSiteOnboarding();
    ensurePlaceholder();
    try {
      var loggedIn = typeof window.currentUser === 'function' && window.currentUser();
      if (loggedIn) {
        if (typeof window.openUser === 'function') window.openUser();
      } else if (typeof window.openAuth === 'function') {
        // Ne pas rester bloqué sur authPending (session fantôme → écran vide)
        window.openAuth('login');
      } else if (fromUser) {
        var ph = ensurePlaceholder();
        var sub = ph.querySelector('.sub');
        if (sub) sub.textContent = 'Connexion indisponible pour le moment. Réessaie.';
      }
    } catch (e) {
      post('account-error', { message: String(e && e.message || e) });
    }
    syncPlaceholder();
  }
  ensureCss();
  document.documentElement.classList.add('pass50-native-account');
  dismissSiteOnboarding();
  ensurePlaceholder();
  forceOpen(false);
  post('account-boot');
  if (!window.__pass50NativeAccountWatch) {
    window.__pass50NativeAccountWatch = true;
    var n = 0;
    var t = setInterval(function () {
      n += 1;
      if (accountOpen()) {
        syncPlaceholder();
        clearInterval(t);
        return;
      }
      forceOpen(false);
      if (n >= 40) {
        clearInterval(t);
        var ph = ensurePlaceholder();
        var sub = ph.querySelector('.sub');
        if (sub) sub.textContent = 'Appuie pour afficher la connexion.';
        post('account-timeout');
      }
    }, 300);
    document.addEventListener('click', function (ev) {
      var target = ev.target;
      if (!target || !target.closest) return;
      var closeBtn = target.closest('#authModal .close, #userModal .close, [data-close="authModal"], [data-close="userModal"]');
      if (closeBtn) {
        ev.preventDefault();
        ev.stopPropagation();
        setTimeout(function () { forceOpen(true); }, 0);
      }
    }, true);
  }
  true;
})();
`;

const MOBILE_UA =
  Platform.OS === 'ios'
    ? 'Mozilla/5.0 (iPhone; CPU iPhone OS 17_0 like Mac OS X) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/17.0 Mobile/15E148 Safari/604.1 Pass50Native/1'
    : 'Mozilla/5.0 (Linux; Android 14; Pixel 7) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/120.0.0.0 Mobile Safari/537.36 Pass50Native/1';

type Props = {
  tab: SiteTab;
  title?: string;
};

function isAccountUrl(url: string): boolean {
  try {
    return new URL(url).searchParams.get('open') === 'account';
  } catch {
    return /[?&]open=account(?:&|$)/i.test(url);
  }
}

function pathAllowedForTab(tab: SiteTab, url: string): boolean {
  try {
    const u = new URL(url);
    if (!u.origin.includes('pass50.store')) return true;
    const path = u.pathname.replace(/\/+$/, '') || '/';
    if (tab === 'feed') return /mon-fil\.html$/i.test(path);
    if (tab === 'prono') return /pronostics\.html$/i.test(path);
    // Mon espace : rester sur l’origine, éviter les boucles de reload
    if (tab === 'account') return true;
    if (tab === 'ranking') {
      if (isAccountUrl(url)) return false;
      return path === '/' || /^\/fi\//i.test(path);
    }
    return true;
  } catch {
    return true;
  }
}

/**
 * Coque WebView du site mobile Safari.
 * Chaque onglet garde sa page et refuse mon-fil/pronos → /?open=account
 * (qui affichait le Classement sur tous les onglets).
 */
export function SiteWebView({ tab, title = 'PASS50' }: Props) {
  const insets = useSafeAreaInsets();
  const router = useRouter();
  const webRef = useRef<WebView>(null);
  const [loading, setLoading] = useState(true);
  const [error, setError] = useState('');

  const url = SITE_URLS[tab];
  const source = useMemo(() => ({ uri: url }), [url]);

  // Ne jamais laisser le loader tourner indéfiniment (Mon espace page blanche)
  useEffect(() => {
    if (!loading) return;
    const timer = setTimeout(() => setLoading(false), 3500);
    return () => clearTimeout(timer);
  }, [loading, tab]);

  const beforeLoadJs = useMemo(() => {
    const parts = [HIDE_SITE_DOCK_JS];
    if (tab === 'feed' || tab === 'prono') parts.push(BLOCK_AUTH_REDIRECT_JS);
    if (tab === 'account') {
      parts.push(`
(function(){
  try {
    try { localStorage.setItem('pass50_onboarding_seen_v1', '1'); } catch (e) {}
    document.documentElement.classList.add('pass50-native-account');
    var css = document.createElement('style');
    css.id = 'pass50-native-account-css';
    css.textContent = ${JSON.stringify(ACCOUNT_SHELL_CSS)};
    (document.head || document.documentElement).appendChild(css);
  } catch (e) {}
  true;
})();`);
    }
    return parts.join('\n');
  }, [tab]);

  const afterLoadJs = useMemo(() => {
    const parts = [HIDE_SITE_DOCK_JS];
    if (tab === 'feed' || tab === 'prono') parts.push(BLOCK_AUTH_REDIRECT_JS);
    if (tab === 'account') parts.push(OPEN_ACCOUNT_JS);
    return parts.join('\n');
  }, [tab]);

  const goAccountTab = useCallback(() => {
    try {
      router.push('/(tabs)/profile');
    } catch {
      // ignore
    }
  }, [router]);

  const onMessage = useCallback(
    (event: { nativeEvent: { data: string } }) => {
      try {
        const data = JSON.parse(event.nativeEvent.data);
        if (data?.type === 'need-auth') goAccountTab();
        if (data?.type === 'account-ready' || data?.type === 'account-boot' || data?.type === 'account-timeout') {
          setLoading(false);
        }
      } catch {
        // ignore
      }
    },
    [goAccountTab],
  );

  const onShouldStart = useCallback(
    (request: { url: string }) => {
      const next = request.url || '';
      if (!next || next.startsWith('about:') || next.startsWith('blob:')) return true;
      if (!/pass50\.store/i.test(next) && /^https?:/i.test(next)) return true;

      if ((tab === 'feed' || tab === 'prono') && isAccountUrl(next)) {
        goAccountTab();
        return false;
      }

      if (!pathAllowedForTab(tab, next)) {
        if (isAccountUrl(next)) {
          goAccountTab();
          return false;
        }
        webRef.current?.injectJavaScript(`window.location.replace(${JSON.stringify(url)}); true;`);
        return false;
      }
      return true;
    },
    [tab, url, goAccountTab],
  );

  if (error) {
    return (
      <View style={[styles.root, { paddingTop: insets.top }]}>
        <View style={styles.errorBox}>
          <Text style={styles.errorTitle}>Connexion impossible</Text>
          <Text style={styles.errorBody}>{error}</Text>
          <Text
            style={styles.retry}
            onPress={() => {
              setError('');
              setLoading(true);
              webRef.current?.reload();
            }}>
            Réessayer — {title}
          </Text>
        </View>
      </View>
    );
  }

  return (
    <View style={[styles.root, { paddingTop: insets.top }]}>
      {loading ? (
        <View style={styles.loader} pointerEvents="none">
          <ActivityIndicator color={Pass50.lime} size="large" />
          <Text style={styles.loaderText}>{title}</Text>
        </View>
      ) : null}

      <WebView
        key={`pass50-webview-${tab}`}
        ref={webRef}
        source={source}
        style={styles.web}
        originWhitelist={['https://*', 'http://*']}
        applicationNameForUserAgent="Pass50Native/1"
        userAgent={MOBILE_UA}
        sharedCookiesEnabled
        thirdPartyCookiesEnabled
        domStorageEnabled
        javaScriptEnabled
        allowsBackForwardNavigationGestures
        startInLoadingState={false}
        setSupportMultipleWindows={false}
        allowsInlineMediaPlayback
        mediaPlaybackRequiresUserAction={false}
        pullToRefreshEnabled
        injectedJavaScriptBeforeContentLoaded={beforeLoadJs}
        injectedJavaScript={afterLoadJs}
        onShouldStartLoadWithRequest={onShouldStart}
        onMessage={onMessage}
        onLoadStart={() => {
          setLoading(true);
          setError('');
        }}
        onLoadEnd={() => {
          setLoading(false);
          webRef.current?.injectJavaScript(`${afterLoadJs}; true;`);
        }}
        onError={(event) => {
          setLoading(false);
          setError(event.nativeEvent.description || 'Erreur de chargement');
        }}
        onHttpError={(event) => {
          if (event.nativeEvent.statusCode >= 400) {
            setLoading(false);
            setError(`HTTP ${event.nativeEvent.statusCode}`);
          }
        }}
      />
    </View>
  );
}

const styles = StyleSheet.create({
  root: {
    flex: 1,
    backgroundColor: Pass50.bg,
  },
  web: {
    flex: 1,
    backgroundColor: Pass50.bg,
  },
  loader: {
    ...StyleSheet.absoluteFillObject,
    zIndex: 2,
    alignItems: 'center',
    justifyContent: 'center',
    gap: 12,
    backgroundColor: 'rgba(5,7,5,.72)',
  },
  loaderText: {
    color: Pass50.lime,
    fontWeight: '900',
    letterSpacing: 1.2,
  },
  errorBox: {
    flex: 1,
    padding: 24,
    justifyContent: 'center',
    gap: 10,
  },
  errorTitle: {
    color: Pass50.text,
    fontWeight: '900',
    fontSize: 22,
  },
  errorBody: {
    color: Pass50.danger,
    fontWeight: '700',
  },
  retry: {
    marginTop: 8,
    color: Pass50.lime,
    fontWeight: '900',
    fontSize: 16,
  },
});
