import * as SplashScreen from 'expo-splash-screen';
import * as WebBrowser from 'expo-web-browser';
import { useCallback, useRef, useState } from 'react';
import {
  ActivityIndicator,
  Pressable,
  StyleSheet,
  Text,
  View,
} from 'react-native';
import { WebView, type WebViewNavigation } from 'react-native-webview';

import { Pass50 } from '@/constants/Colors';
import { isPass50Host, PASS50_NATIVE_APP_URL } from '@/src/shell/url';

export default function NativeShellScreen() {
  const webRef = useRef<WebView>(null);
  const [loading, setLoading] = useState(true);
  const [error, setError] = useState<string | null>(null);

  const hideSplash = useCallback(() => {
    SplashScreen.hideAsync().catch(() => {});
  }, []);

  const openExternal = useCallback(async (url: string) => {
    try {
      await WebBrowser.openBrowserAsync(url);
    } catch {
      // ignore — user can retry from the page
    }
  }, []);

  const onShouldStartLoadWithRequest = useCallback(
    (request: { url: string }) => {
      const raw = request.url || '';
      if (
        raw.startsWith('about:') ||
        raw.startsWith('blob:') ||
        raw.startsWith('data:')
      ) {
        return true;
      }
      try {
        const parsed = new URL(raw);
        if (parsed.protocol === 'http:' || parsed.protocol === 'https:') {
          if (isPass50Host(parsed.hostname)) {
            return true;
          }
          void openExternal(raw);
          return false;
        }
      } catch {
        return false;
      }
      return false;
    },
    [openExternal]
  );

  const onNavChange = useCallback((nav: WebViewNavigation) => {
    if (!nav.loading) {
      setLoading(false);
      hideSplash();
    }
  }, [hideSplash]);

  const reload = useCallback(() => {
    setError(null);
    setLoading(true);
    webRef.current?.reload();
  }, []);

  return (
    <View style={styles.root}>
      <WebView
        ref={webRef}
        source={{ uri: PASS50_NATIVE_APP_URL }}
        style={styles.webview}
        originWhitelist={['https://*', 'http://*']}
        allowsBackForwardNavigationGestures
        allowsInlineMediaPlayback
        mediaPlaybackRequiresUserAction={false}
        javaScriptEnabled
        domStorageEnabled
        sharedCookiesEnabled
        thirdPartyCookiesEnabled
        setSupportMultipleWindows={false}
        applicationNameForUserAgent="PASS50Native/1.0"
        onShouldStartLoadWithRequest={onShouldStartLoadWithRequest}
        onNavigationStateChange={onNavChange}
        onLoadEnd={() => {
          setLoading(false);
          hideSplash();
        }}
        onError={() => {
          setLoading(false);
          hideSplash();
          setError('Impossible de charger PASS50. Vérifie ta connexion.');
        }}
        onHttpError={(event) => {
          if (event.nativeEvent.statusCode >= 500) {
            setLoading(false);
            hideSplash();
            setError('Le site PASS50 est temporairement indisponible.');
          }
        }}
      />

      {loading && !error ? (
        <View style={styles.overlay} pointerEvents="none">
          <ActivityIndicator color={Pass50.lime} size="large" />
        </View>
      ) : null}

      {error ? (
        <View style={styles.overlay}>
          <Text style={styles.errorTitle}>PASS50</Text>
          <Text style={styles.errorText}>{error}</Text>
          <Pressable style={styles.retry} onPress={reload} accessibilityRole="button">
            <Text style={styles.retryLabel}>Réessayer</Text>
          </Pressable>
        </View>
      ) : null}
    </View>
  );
}

const styles = StyleSheet.create({
  root: {
    flex: 1,
    backgroundColor: Pass50.bg,
  },
  webview: {
    flex: 1,
    backgroundColor: Pass50.bg,
  },
  overlay: {
    ...StyleSheet.absoluteFillObject,
    backgroundColor: Pass50.bg,
    alignItems: 'center',
    justifyContent: 'center',
    paddingHorizontal: 28,
    gap: 14,
  },
  errorTitle: {
    color: Pass50.lime,
    fontSize: 28,
    fontWeight: '800',
    letterSpacing: 1,
  },
  errorText: {
    color: Pass50.text,
    fontSize: 15,
    textAlign: 'center',
    lineHeight: 22,
  },
  retry: {
    marginTop: 8,
    paddingHorizontal: 22,
    paddingVertical: 12,
    backgroundColor: Pass50.lime,
  },
  retryLabel: {
    color: '#050705',
    fontWeight: '700',
    fontSize: 15,
  },
});
