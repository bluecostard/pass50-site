import { useRouter } from 'expo-router';
import { useCallback, useEffect, useMemo, useRef, useState } from 'react';
import { ActivityIndicator, Platform, StyleSheet, Text, View } from 'react-native';
import { useSafeAreaInsets } from 'react-native-safe-area-context';
import { WebView } from 'react-native-webview';

import { Pass50 } from '@/constants/Colors';
import { MON_ESPACE_BUNDLED_HTML } from '@/constants/monEspaceHtml';

const SITE_ORIGIN = 'https://pass50.store';

export type SiteTab = 'ranking' | 'feed' | 'prono' | 'account';

/**
 * Pages site mobile Safari (pas le shell Capacitor).
 * Mon espace = HTML embarqué (évite le 404 tant que mon-espace.html n’est pas déployé IONOS)
 * + baseUrl pass50.store pour que ./api et cookies fonctionnent.
 */
export const SITE_URLS: Record<SiteTab, string> = {
  ranking: `${SITE_ORIGIN}/?native=1`,
  feed: `${SITE_ORIGIN}/mon-fil.html?native=1`,
  prono: `${SITE_ORIGIN}/pronostics.html?v=83&native=1`,
  account: `${SITE_ORIGIN}/mon-espace.html?native=1`,
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
 * Empêche mon-fil / pronostics de quitter leur onglet vers Mon espace / Classement.
 * Affiche une porte de connexion locale et bascule l’onglet natif Mon espace.
 */
const BLOCK_AUTH_REDIRECT_JS = `
(function () {
  function isAccountRedirect(url) {
    try {
      var u = new URL(String(url), location.href);
      if (u.searchParams.get('open') === 'account') return true;
      if (/mon-espace\\.html$/i.test(u.pathname)) return true;
      return /[?&]open=account(?:&|$)/i.test(String(url));
    } catch (e) {
      return /open=account|mon-espace\\.html/i.test(String(url || ''));
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
    const u = new URL(url);
    if (u.searchParams.get('open') === 'account') return true;
    return /mon-espace\.html$/i.test(u.pathname);
  } catch {
    return /open=account|mon-espace\.html/i.test(url);
  }
}

function pathAllowedForTab(tab: SiteTab, url: string): boolean {
  try {
    const u = new URL(url);
    if (!u.origin.includes('pass50.store')) return true;
    const path = u.pathname.replace(/\/+$/, '') || '/';
    if (tab === 'feed') return /mon-fil\.html$/i.test(path);
    if (tab === 'prono') return /pronostics\.html$/i.test(path);
    // Compte = HTML embarqué avec baseUrl `/` (mon-espace.html remote = 404 Apache).
    if (tab === 'account') return path === '/' || /mon-espace\.html$/i.test(path);
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
 * Chaque onglet = une vraie page (Classement, Mon fil, Pronos, Mon espace).
 */
export function SiteWebView({ tab, title = 'PASS50' }: Props) {
  const insets = useSafeAreaInsets();
  const router = useRouter();
  const webRef = useRef<WebView>(null);
  const [loading, setLoading] = useState(true);
  const [error, setError] = useState('');

  const url = SITE_URLS[tab];
  const source = useMemo(() => {
    // Bundle local : TestFlight n’attend pas le deploy IONOS de mon-espace.html.
    // IMPORTANT: baseUrl doit exister (200). Pointer mon-espace.html (404) fait
    // afficher la page Apache « Not Found » dans WKWebView.
    if (tab === 'account') {
      return {
        html: MON_ESPACE_BUNDLED_HTML,
        baseUrl: `${SITE_ORIGIN}/`,
      };
    }
    return { uri: url };
  }, [tab, url]);

  useEffect(() => {
    if (!loading) return;
    const timer = setTimeout(() => setLoading(false), 3500);
    return () => clearTimeout(timer);
  }, [loading, tab]);

  const beforeLoadJs = useMemo(() => {
    const parts = [HIDE_SITE_DOCK_JS];
    if (tab === 'account') {
      // Marque l’URL logique sans fetch réseau (évite le 404 Apache).
      parts.push(`(function(){try{history.replaceState(null,'','/mon-espace.html?native=1');}catch(e){}true;})();`);
    }
    if (tab === 'feed' || tab === 'prono') parts.push(BLOCK_AUTH_REDIRECT_JS);
    return parts.join('\n');
  }, [tab]);

  const afterLoadJs = useMemo(() => {
    const parts = [HIDE_SITE_DOCK_JS];
    if (tab === 'account') {
      parts.push(`(function(){try{history.replaceState(null,'','/mon-espace.html?native=1');}catch(e){}true;})();`);
    }
    if (tab === 'feed' || tab === 'prono') parts.push(BLOCK_AUTH_REDIRECT_JS);
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

      // Compte embarqué : ne jamais naviguer vers mon-espace.html remote (404 Apache).
      if (tab === 'account' && isAccountUrl(next)) {
        return false;
      }

      if ((tab === 'feed' || tab === 'prono') && isAccountUrl(next)) {
        goAccountTab();
        return false;
      }

      if (!pathAllowedForTab(tab, next)) {
        if (isAccountUrl(next)) {
          goAccountTab();
          return false;
        }
        // Sur compte, rester sur le HTML embarqué — ne pas location.replace vers l’URL 404.
        if (tab === 'account') return false;
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
          // Compte = HTML embarqué : ignorer les 404 réseau (page absente en prod).
          if (tab === 'account') return;
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
