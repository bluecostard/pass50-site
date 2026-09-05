import { useCallback, useEffect, useState } from 'react';
import { Pressable, ScrollView, StyleSheet, Text, View } from 'react-native';

import { FeedCard } from '@/components/FeedCard';
import { ScreenShell } from '@/components/ScreenShell';
import { Pass50 } from '@/constants/Colors';
import { pass50Api } from '@/src/api/client';
import { PublicFeed } from '@/src/types';

const FEED_PERIODS = [
  { key: '2h', label: '2H' },
  { key: '24h', label: '24H' },
  { key: '48h', label: '48H' },
  { key: '7d', label: '7J' },
  { key: '15d', label: '15J' },
];

export default function FeedScreen() {
  const [feedPeriod, setFeedPeriod] = useState('24h');
  const [data, setData] = useState<PublicFeed | null>(null);
  const [loading, setLoading] = useState(true);
  const [error, setError] = useState('');

  const load = useCallback(async () => {
    setLoading(true);
    setError('');
    try {
      setData(await pass50Api.feed(feedPeriod));
    } catch (err) {
      setError(err instanceof Error ? err.message : 'Fil indisponible');
    } finally {
      setLoading(false);
    }
  }, [feedPeriod]);

  useEffect(() => {
    load();
  }, [load]);

  const news = data?.news ?? [];
  const trends = data?.trends ?? [];
  const items = news.length ? news : trends;

  return (
    <ScreenShell
      eyebrow="Fil public"
      title="Ce qui circule"
      subtitle="Tendances et publications"
      status={loading ? 'Maj…' : 'À jour'}
      refreshing={loading}
      onRefresh={load}>
      <ScrollView horizontal showsHorizontalScrollIndicator={false} contentContainerStyle={styles.chips}>
        {FEED_PERIODS.map((period) => {
          const active = period.key === feedPeriod;
          return (
            <Pressable
              key={period.key}
              onPress={() => setFeedPeriod(period.key)}
              style={[styles.chip, active && styles.chipActive]}>
              <Text style={[styles.chipText, active && styles.chipTextActive]}>{period.label}</Text>
            </Pressable>
          );
        })}
      </ScrollView>
      {error && !data ? (
        <View style={styles.panel}>
          <Text style={styles.error}>{error}</Text>
        </View>
      ) : null}
      {items.length ? (
        items.map((item, index) => <FeedCard key={`${item.url}-${index}`} item={item} />)
      ) : (
        <View style={styles.panel}>
          <Text style={styles.empty}>
            {loading ? 'Chargement…' : 'Rien de frais sur cette période.'}
          </Text>
        </View>
      )}
    </ScreenShell>
  );
}

const styles = StyleSheet.create({
  chips: { gap: 8, paddingVertical: 2 },
  chip: {
    borderWidth: 1,
    borderColor: Pass50.line,
    borderRadius: 999,
    paddingHorizontal: 13,
    paddingVertical: 9,
    backgroundColor: '#0a0d0a',
  },
  chipActive: { backgroundColor: Pass50.lime, borderColor: Pass50.lime },
  chipText: { color: Pass50.text, fontWeight: '900', fontSize: 13 },
  chipTextActive: { color: Pass50.bg },
  panel: {
    borderWidth: 1,
    borderColor: Pass50.line,
    borderRadius: 18,
    backgroundColor: Pass50.panel,
    padding: 16,
  },
  empty: { color: Pass50.muted, lineHeight: 20 },
  error: { color: Pass50.danger },
});
