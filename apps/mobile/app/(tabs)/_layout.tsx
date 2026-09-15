import { Tabs } from 'expo-router';

import { Pass50TabBar } from '@/components/Pass50TabBar';
import { Pass50 } from '@/constants/Colors';

export default function TabLayout() {
  return (
    <Tabs
      tabBar={(props) => <Pass50TabBar {...props} />}
      screenOptions={{
        headerShown: false,
        tabBarActiveTintColor: Pass50.lime,
        tabBarInactiveTintColor: Pass50.muted,
      }}>
      {/* Dock = site mobile : Mon fil · Pronos · Classement · Mon espace */}
      <Tabs.Screen name="feed" options={{ title: 'Mon fil' }} />
      <Tabs.Screen name="prono" options={{ title: 'Pronos' }} />
      <Tabs.Screen name="index" options={{ title: 'Classement' }} />
      <Tabs.Screen name="profile" options={{ title: 'Mon espace' }} />
      {/* Live reste natif, hors dock (accès depuis le fil / compte). */}
      <Tabs.Screen name="live" options={{ title: 'Live', href: null }} />
    </Tabs>
  );
}
