import { DarkTheme, ThemeProvider, Stack } from 'expo-router';
import * as SplashScreen from 'expo-splash-screen';
import { StatusBar } from 'expo-status-bar';
import { useEffect } from 'react';
import { SafeAreaProvider } from 'react-native-safe-area-context';
import 'react-native-reanimated';

import { Pass50 } from '@/constants/Colors';

export { ErrorBoundary } from 'expo-router';

SplashScreen.preventAutoHideAsync();

const pass50Theme = {
  ...DarkTheme,
  colors: {
    ...DarkTheme.colors,
    background: Pass50.bg,
    card: Pass50.panel,
    text: Pass50.text,
    border: Pass50.line,
    primary: Pass50.lime,
  },
};

export default function RootLayout() {
  useEffect(() => {
    // Splash is hidden when the WebView finishes loading (see app/index.tsx).
    const fallback = setTimeout(() => {
      SplashScreen.hideAsync().catch(() => {});
    }, 8000);
    return () => clearTimeout(fallback);
  }, []);

  return (
    <SafeAreaProvider>
      <ThemeProvider value={pass50Theme}>
        <StatusBar style="light" />
        <Stack screenOptions={{ headerShown: false, contentStyle: { backgroundColor: Pass50.bg } }}>
          <Stack.Screen name="index" />
        </Stack>
      </ThemeProvider>
    </SafeAreaProvider>
  );
}
