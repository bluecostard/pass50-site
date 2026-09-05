import { useCallback, useMemo, useRef, useState } from 'react';
import {
  ActivityIndicator,
  Platform,
  RefreshControl,
  ScrollView,
  StyleSheet,
  Text,
  View,
} from 'react-native';
import { useSafeAreaInsets } from 'react-native-safe-area-context';
import { WebView } from 'react-native-webview';

import { Pass50 } from '@/constants/Colors';

const SITE_ORIGIN = 'https://pass50.store';

/** Masque le dock web du site — le dock natif Expo reste visible. */
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
      'html.pass50-native-app body{overscroll-behavior-y:contain}'
    ].join('');
    (document.head || document.documentElement).appendChild(css);
    document.documentElement.classList.add('pass50-native-app');
    document.documentElement.setAttribute('data-pass50-native', '1');
  } catch (e) {}
  true;
})();
`;

const MOBILE_UA =
  Platform.OS === 'ios'
    ? 'Mozilla/5.0 (iPhone; CPU iPhone OS 17_0 like Mac OS X) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/17.0 Mobile/15E148 Safari/604.1 Pass50Native/1'
    : 'Mozilla/5.0 (Linux; Android 14; Pixel 7) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/120.0.0.0 Mobile Safari/537.36 Pass50Native/1';

/** Pages du site mobile Safari (accueil / mon-fil / pronostics — pas le shell Capacitor). */
export const SITE_URLS = {
  ranking: `${SITE_ORIGIN}/?source=native&native=1`,
  feed: `${SITE_ORIGIN}/mon-fil.html?source=native&native=1`,
  prono: `${SITE_ORIGIN}/pronostics.html?v=83&source=native&native=1`,
  account: `${SITE_ORIGIN}/?source=native&native=1&open=account`,
} as const;

type Props = {
  url: string;
  title?: string;
};

/**
 * Coque WebView = rendu exact Safari mobile de pass50.store.
 * Classement / Mon fil / Pronos / Mon espace chargent les vraies pages du site.
 */
export function SiteWebView({ url, title = 'PASS50' }: Props) {
  const insets = useSafeAreaInsets();
  const webRef = useRef<WebView>(null);
  const [loading, setLoading] = useState(true);
  const [error, setError] = useState('');
  const [refreshing, setRefreshing] = useState(false);

  const source = useMemo(() => ({ uri: url }), [url]);

  const onRefresh = useCallback(() => {
    setRefreshing(true);
    setError('');
    webRef.current?.reload();
  }, []);

  if (error) {
    return (
      <View style={[styles.root, { paddingTop: insets.top }]}>
        <ScrollView
          contentContainerStyle={styles.errorBox}
          refreshControl={
            <RefreshControl refreshing={refreshing} onRefresh={onRefresh} tintColor={Pass50.lime} />
          }>
          <Text style={styles.errorTitle}>Connexion impossible</Text>
          <Text style={styles.errorBody}>{error}</Text>
          <Text style={styles.errorHint}>Tire pour réessayer — {title}</Text>
        </ScrollView>
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
        startInLoadingState
        setSupportMultipleWindows={false}
        allowsInlineMediaPlayback
        mediaPlaybackRequiresUserAction={false}
        pullToRefreshEnabled
        injectedJavaScriptBeforeContentLoaded={HIDE_SITE_DOCK_JS}
        injectedJavaScript={HIDE_SITE_DOCK_JS}
        onLoadStart={() => {
          setLoading(true);
          setError('');
        }}
        onLoadEnd={() => {
          setLoading(false);
          setRefreshing(false);
          webRef.current?.injectJavaScript(HIDE_SITE_DOCK_JS);
        }}
        onError={(event) => {
          setLoading(false);
          setRefreshing(false);
          setError(event.nativeEvent.description || 'Erreur de chargement');
        }}
        onHttpError={(event) => {
          if (event.nativeEvent.statusCode >= 400) {
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
    flexGrow: 1,
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
  errorHint: {
    color: Pass50.muted,
  },
});
