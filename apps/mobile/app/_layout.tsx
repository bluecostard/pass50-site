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
    SplashScreen.hideAsync();
  }, []);

  return (
    <SafeAreaProvider>
      <ThemeProvider value={pass50Theme}>
        <StatusBar style="light" />
        <Stack>
          <Stack.Screen name="index" options={{ headerShown: false }} />
          <Stack.Screen name="onboarding/index" options={{ headerShown: false, animation: 'fade' }} />
          <Stack.Screen name="(tabs)" options={{ headerShown: false }} />
          <Stack.Screen
            name="influencer/[id]"
            options={{
              title: 'Influenceur',
              presentation: 'modal',
              headerStyle: { backgroundColor: Pass50.bg },
              headerTintColor: Pass50.lime,
              contentStyle: { backgroundColor: Pass50.bg },
            }}
          />
        </Stack>
      </ThemeProvider>
    </SafeAreaProvider>
  );
}
