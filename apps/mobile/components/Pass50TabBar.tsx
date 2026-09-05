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

/** Ordre + libellés = dock mobile du site (mobile-bottom-nav-v1). */
const TAB_VISUAL: Record<string, TabVisual> = {
  feed: { label: 'Mon fil', icon: 'newspaper-outline' },
  prono: { label: 'Pronos', icon: 'flash-outline' },
  index: { label: 'Classement', icon: 'trophy-outline', elevated: true },
  profile: { label: 'Mon espace', icon: 'person-outline' },
};

const TAB_ORDER = ['feed', 'prono', 'index', 'profile'] as const;

/** Dock flottant — même présentation que le site mobile, navigation 100 % native. */
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
                <View style={[styles.elevatedIcon, focused && styles.elevatedIconActive]}>
                  <Ionicons name={visual.icon} size={24} color={focused ? '#050705' : '#dce5d8'} />
                </View>
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
    maxWidth: 400,
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
    color: '#98a295',
    fontSize: 9,
    fontWeight: '900',
    letterSpacing: 0.2,
  },
  labelActive: {
    color: Pass50.lime,
  },
  elevated: {
    flex: 1,
    minHeight: 78,
    marginTop: -18,
    marginBottom: 2,
    paddingTop: 7,
    paddingBottom: 6,
    paddingHorizontal: 6,
    borderRadius: 21,
    borderWidth: 1,
    borderColor: 'rgba(255,255,255,.12)',
    backgroundColor: '#0c110c',
    alignItems: 'center',
    justifyContent: 'center',
    gap: 4,
    transform: [{ translateY: -3 }],
    shadowColor: '#000',
    shadowOpacity: 0.45,
    shadowRadius: 14,
    shadowOffset: { width: 0, height: 10 },
    elevation: 12,
  },
  elevatedActive: {
    backgroundColor: Pass50.lime,
    borderColor: Pass50.lime,
  },
  elevatedIcon: {
    width: 44,
    height: 44,
    borderRadius: 15,
    borderWidth: 1,
    borderColor: 'rgba(255,255,255,.14)',
    backgroundColor: 'rgba(255,255,255,.04)',
    alignItems: 'center',
    justifyContent: 'center',
  },
  elevatedIconActive: {
    borderColor: 'rgba(5,7,5,.2)',
    backgroundColor: 'rgba(5,7,5,.12)',
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
