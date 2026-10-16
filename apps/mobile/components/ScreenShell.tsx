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
  /** Eyebrow lime uppercase — comme `.eyebrow` sur app.html */
  eyebrow?: string;
  title?: string;
  subtitle?: string;
  /** Status à droite du brand — comme `.status` sur app.html */
  status?: string;
  children: ReactNode;
  refreshing?: boolean;
  onRefresh?: () => void;
  contentStyle?: ViewStyle;
};

export function ScreenShell({
  eyebrow,
  title,
  subtitle,
  status,
  children,
  refreshing,
  onRefresh,
  contentStyle,
}: Props) {
  const insets = useSafeAreaInsets();

  return (
    <View style={[styles.root, { paddingTop: insets.top + 12 }]}>
      <View style={styles.top}>
        <Text style={styles.brand}>
          PASS<Text style={styles.brandAccent}>50</Text>
        </Text>
        {status ? <Text style={styles.status}>{status}</Text> : null}
      </View>
      {(eyebrow || title || subtitle) && (
        <View style={styles.hero}>
          {eyebrow ? <Text style={styles.eyebrow}>{eyebrow}</Text> : null}
          {title ? <Text style={styles.title}>{title}</Text> : null}
          {subtitle ? <Text style={styles.subtitle}>{subtitle}</Text> : null}
        </View>
      )}
      <ScrollView
        contentContainerStyle={[styles.content, contentStyle, { paddingBottom: insets.bottom + 110 }]}
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
  top: {
    paddingHorizontal: 14,
    marginBottom: 8,
    flexDirection: 'row',
    alignItems: 'flex-end',
    justifyContent: 'space-between',
    gap: 12,
  },
  brand: {
    fontSize: 38,
    fontWeight: '900',
    letterSpacing: -2.2,
    color: Pass50.text,
    lineHeight: 40,
  },
  brandAccent: {
    color: Pass50.lime,
  },
  status: {
    flexShrink: 1,
    maxWidth: '52%',
    fontSize: 11,
    fontWeight: '800',
    color: Pass50.muted,
    textAlign: 'right',
  },
  hero: {
    paddingHorizontal: 14,
    marginBottom: 10,
    gap: 4,
  },
  eyebrow: {
    color: Pass50.lime,
    fontSize: 11,
    fontWeight: '900',
    letterSpacing: 0.8,
    textTransform: 'uppercase',
  },
  title: {
    fontSize: 32,
    fontWeight: '900',
    letterSpacing: -1.4,
    color: Pass50.text,
    lineHeight: 34,
  },
  subtitle: {
    marginTop: 2,
    fontSize: 13,
    lineHeight: 18,
    color: Pass50.muted,
  },
  content: {
    paddingHorizontal: 14,
    gap: 12,
  },
});
