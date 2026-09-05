import { Ionicons } from '@expo/vector-icons';
import { BottomTabBarProps } from '@react-navigation/bottom-tabs';
import { Pressable, StyleSheet, Text, View } from 'react-native';
import { useSafeAreaInsets } from 'react-native-safe-area-context';

import { Pass50 } from '@/constants/Colors';

type TabVisual = {
  label: string;
  icon: keyof typeof Ionicons.glyphMap;
};

/**
 * Dock = client mobile app.html (Classement · Fil · Live · Compte).
 * Pronos reste une vue native accessible hors dock (depuis Mon espace).
 */
const TAB_VISUAL: Record<string, TabVisual> = {
  index: { label: 'Classement', icon: 'stats-chart-outline' },
  feed: { label: 'Fil', icon: 'newspaper-outline' },
  live: { label: 'Live', icon: 'radio-outline' },
  profile: { label: 'Compte', icon: 'person-outline' },
};

const TAB_ORDER = ['index', 'feed', 'live', 'profile'] as const;

/** Dock flottant — même présentation que app.html, navigation 100 % native. */
export function Pass50TabBar({ state, descriptors, navigation }: BottomTabBarProps) {
  const insets = useSafeAreaInsets();

  return (
    <View style={[styles.wrap, { paddingBottom: Math.max(insets.bottom, 8) }]} pointerEvents="box-none">
      <View style={styles.dock}>
        {TAB_ORDER.map((name) => {
          const route = state.routes.find((r) => r.name === name);
          if (!route) return null;
          const visual = TAB_VISUAL[name];
          const routeIndex = state.routes.findIndex((r) => r.key === route.key);
          const focused = state.index === routeIndex;
          const { options } = descriptors[route.key];

          const onPress = () => {
            const event = navigation.emit({
              type: 'tabPress',
              target: route.key,
              canPreventDefault: true,
            });
            if (!focused && !event.defaultPrevented) {
              navigation.navigate(route.name, route.params);
            }
          };

          return (
            <Pressable
              key={route.key}
              accessibilityRole="button"
              accessibilityState={focused ? { selected: true } : {}}
              accessibilityLabel={options.tabBarAccessibilityLabel ?? visual.label}
              onPress={onPress}
              style={[styles.link, focused && styles.linkActive]}>
              <Ionicons name={visual.icon} size={22} color={focused ? Pass50.lime : '#98a295'} />
              <Text style={[styles.label, focused && styles.labelActive]}>{visual.label}</Text>
            </Pressable>
          );
        })}
      </View>
    </View>
  );
}

const styles = StyleSheet.create({
  wrap: {
    position: 'absolute',
    left: 0,
    right: 0,
    bottom: 0,
    alignItems: 'center',
    paddingHorizontal: 8,
  },
  dock: {
    width: '100%',
    maxWidth: 420,
    minHeight: 72,
    paddingHorizontal: 8,
    paddingVertical: 8,
    borderRadius: 22,
    borderWidth: 1,
    borderColor: 'rgba(183,255,0,.22)',
    backgroundColor: 'rgba(6,9,6,.98)',
    flexDirection: 'row',
    alignItems: 'center',
    justifyContent: 'space-between',
    shadowColor: '#000',
    shadowOpacity: 0.5,
    shadowRadius: 18,
    shadowOffset: { width: 0, height: 10 },
    elevation: 16,
  },
  link: {
    flex: 1,
    minHeight: 56,
    alignItems: 'center',
    justifyContent: 'center',
    gap: 4,
    paddingVertical: 8,
    borderRadius: 16,
  },
  linkActive: {
    backgroundColor: 'rgba(183,255,0,.08)',
  },
  label: {
    color: '#98a295',
    fontSize: 10,
    fontWeight: '900',
  },
  labelActive: {
    color: Pass50.lime,
  },
});
