import { ReactNode } from 'react';
import {
  RefreshControl,
  ScrollView,
  StyleSheet,
  Text,
  View,
  ViewStyle,
} from 'react-native';
import { useSafeAreaInsets } from 'react-native-safe-area-context';

import { Pass50 } from '@/constants/Colors';

type Props = {
  title?: string;
  subtitle?: string;
  children: ReactNode;
  refreshing?: boolean;
  onRefresh?: () => void;
  contentStyle?: ViewStyle;
};

export function ScreenShell({
  title,
  subtitle,
  children,
  refreshing,
  onRefresh,
  contentStyle,
}: Props) {
  const insets = useSafeAreaInsets();

  return (
    <View style={[styles.root, { paddingTop: insets.top + 8 }]}>
      <View style={styles.header}>
        <Text style={styles.brand}>
          PASS<Text style={styles.brandAccent}>50</Text>
        </Text>
        {title ? <Text style={styles.title}>{title}</Text> : null}
        {subtitle ? <Text style={styles.subtitle}>{subtitle}</Text> : null}
      </View>
      <ScrollView
        contentContainerStyle={[styles.content, contentStyle, { paddingBottom: insets.bottom + 96 }]}
        refreshControl={
          onRefresh ? (
            <RefreshControl
              refreshing={Boolean(refreshing)}
              onRefresh={onRefresh}
              tintColor={Pass50.lime}
            />
          ) : undefined
        }
        showsVerticalScrollIndicator={false}>
        {children}
      </ScrollView>
    </View>
  );
}

const styles = StyleSheet.create({
  root: {
    flex: 1,
    backgroundColor: Pass50.bg,
  },
  header: {
    paddingHorizontal: 16,
    paddingBottom: 12,
  },
  brand: {
    fontSize: 34,
    fontWeight: '900',
    letterSpacing: -1.5,
    color: Pass50.text,
    marginBottom: 8,
  },
  brandAccent: {
    color: Pass50.lime,
  },
  title: {
    fontSize: 28,
    fontWeight: '900',
    letterSpacing: -0.8,
    color: Pass50.text,
  },
  subtitle: {
    marginTop: 4,
    fontSize: 13,
    lineHeight: 18,
    color: Pass50.muted,
  },
  content: {
    paddingHorizontal: 14,
    gap: 12,
  },
});
