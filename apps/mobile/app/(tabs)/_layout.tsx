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
      {/* Dock = app.html : Classement · Fil · Live · Compte */}
      <Tabs.Screen name="index" options={{ title: 'Classement' }} />
      <Tabs.Screen name="feed" options={{ title: 'Fil' }} />
      <Tabs.Screen name="live" options={{ title: 'Live' }} />
      <Tabs.Screen name="profile" options={{ title: 'Compte' }} />
      {/* Pronos = vue native hors dock (lien depuis Compte). */}
      <Tabs.Screen name="prono" options={{ title: 'Pronos', href: null }} />
    </Tabs>
  );
}
