import { Ionicons } from '@expo/vector-icons';
import { BottomTabBarProps } from '@react-navigation/bottom-tabs';
import { Pressable, StyleSheet, Text, View } from 'react-native';
import { useSafeAreaInsets } from 'react-native-safe-area-context';

import { Pass50 } from '@/constants/Colors';

type TabVisual = {
  label: string;
  icon: keyof typeof Ionicons.glyphMap;
  elevated?: boolean;
};

/** Dock = site mobile (mobile-bottom-nav-v1) : Mon fil · Pronos · Classement · Mon espace */
const TAB_VISUAL: Record<string, TabVisual> = {
  feed: { label: 'Mon fil', icon: 'calendar-outline' },
  prono: { label: 'Pronos', icon: 'star-outline' },
  index: { label: 'Classement', icon: 'trophy', elevated: true },
  profile: { label: 'Mon espace', icon: 'person-outline' },
};

const TAB_ORDER = ['feed', 'prono', 'index', 'profile'] as const;

/** Dock flottant — même présentation que pass50.store mobile, navigation native. */
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

          if (visual.elevated) {
            return (
              <Pressable
                key={route.key}
                accessibilityRole="button"
                accessibilityState={focused ? { selected: true } : {}}
                accessibilityLabel={options.tabBarAccessibilityLabel ?? visual.label}
                onPress={onPress}
                style={[styles.elevated, focused && styles.elevatedActive]}>
                <Ionicons name={visual.icon} size={22} color={focused ? '#050705' : '#dce5d8'} />
                <Text style={[styles.elevatedLabel, focused && styles.elevatedLabelActive]}>
                  {visual.label}
                </Text>
              </Pressable>
            );
          }

          return (
            <Pressable
              key={route.key}
              accessibilityRole="button"
              accessibilityState={focused ? { selected: true } : {}}
              accessibilityLabel={options.tabBarAccessibilityLabel ?? visual.label}
              onPress={onPress}
              style={[styles.link, focused && styles.linkActive]}>
              <Ionicons name={visual.icon} size={22} color={focused ? Pass50.lime : '#c8d0c6'} />
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
    paddingHorizontal: 6,
    paddingVertical: 6,
    borderRadius: 24,
    borderWidth: 1,
    borderColor: 'rgba(183,255,0,.22)',
    backgroundColor: 'rgba(6,9,6,.99)',
    flexDirection: 'row',
    alignItems: 'flex-end',
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
    backgroundColor: 'rgba(183,255,0,.075)',
  },
  label: {
    color: '#c8d0c6',
    fontSize: 9,
    fontWeight: '900',
  },
  labelActive: {
    color: Pass50.lime,
  },
  elevated: {
    flex: 1.15,
    minHeight: 64,
    marginTop: -10,
    marginBottom: 2,
    paddingVertical: 10,
    paddingHorizontal: 6,
    borderRadius: 18,
    borderWidth: 1,
    borderColor: 'rgba(255,255,255,.12)',
    backgroundColor: '#101610',
    alignItems: 'center',
    justifyContent: 'center',
    gap: 3,
    transform: [{ translateY: -2 }],
  },
  elevatedActive: {
    backgroundColor: Pass50.lime,
    borderColor: Pass50.lime,
  },
  elevatedLabel: {
    color: '#dce5d8',
    fontSize: 9,
    fontWeight: '900',
  },
  elevatedLabelActive: {
    color: '#050705',
  },
});
