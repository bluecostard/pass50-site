import { Redirect } from 'expo-router';
import { useEffect, useState } from 'react';
import { ActivityIndicator, StyleSheet, View } from 'react-native';

import { Pass50 } from '@/constants/Colors';
import { hasCompletedOnboarding } from '@/src/onboarding/storage';

export default function EntryScreen() {
  const [target, setTarget] = useState<'loading' | 'onboarding' | 'tabs'>('loading');

  useEffect(() => {
    hasCompletedOnboarding().then((done) => setTarget(done ? 'tabs' : 'onboarding'));
  }, []);

  if (target === 'loading') {
    return (
      <View style={styles.loading}>
        <ActivityIndicator color={Pass50.lime} size="large" />
      </View>
    );
  }

  if (target === 'onboarding') {
    return <Redirect href="/onboarding" />;
  }

  return <Redirect href="/(tabs)" />;
}

const styles = StyleSheet.create({
  loading: {
    flex: 1,
    backgroundColor: Pass50.bg,
    alignItems: 'center',
    justifyContent: 'center',
  },
});
