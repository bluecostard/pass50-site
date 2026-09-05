import { useRouter } from 'expo-router';
import { useCallback, useEffect, useState } from 'react';
import { Pressable, StyleSheet, Text, View } from 'react-native';

import { PeriodChips } from '@/components/PeriodChips';
import { RankRow } from '@/components/RankRow';
import { ScreenShell } from '@/components/ScreenShell';
import { Pass50 } from '@/constants/Colors';
import { pass50Api } from '@/src/api/client';
import { PublicRanking, RankingPeriod } from '@/src/types';

export default function RankingScreen() {
  const router = useRouter();
  const [period, setPeriod] = useState<RankingPeriod>('24H');
  const [data, setData] = useState<PublicRanking | null>(null);
  const [loading, setLoading] = useState(true);
  const [error, setError] = useState('');

  const load = useCallback(async () => {
    setLoading(true);
    setError('');
    try {
      setData(await pass50Api.ranking());
    } catch (err) {
      setError(err instanceof Error ? err.message : 'Classement indisponible');
    } finally {
      setLoading(false);
    }
  }, []);

  useEffect(() => {
    load();
  }, [load]);

  const rows = data?.periods?.[period] ?? [];

  return (
    <ScreenShell
      eyebrow="Classement public"
      title="Qui monte maintenant"
      subtitle={`Top ${rows.length || 50} · période ${period}`}
      status={loading ? 'Maj…' : 'À jour'}
      refreshing={loading}
      onRefresh={load}>
      <PeriodChips value={period} onChange={setPeriod} />
      <Pressable
        style={styles.liveLink}
        onPress={() => router.push('/(tabs)/live')}
        accessibilityRole="button"
        accessibilityLabel="Voir les lives">
        <Text style={styles.liveLinkText}>Lives en cours →</Text>
      </Pressable>
      {error && !data ? (
        <View style={styles.panel}>
          <Text style={styles.error}>{error}</Text>
        </View>
      ) : null}
      <View style={styles.panel}>
        {rows.length ? (
          rows.map((row) => <RankRow key={row.id} row={row} />)
        ) : (
          <Text style={styles.empty}>
            {loading ? 'Chargement…' : `Aucun profil classé pour ${period}.`}
          </Text>
        )}
      </View>
    </ScreenShell>
  );
}

const styles = StyleSheet.create({
  liveLink: {
    alignSelf: 'flex-start',
    paddingVertical: 6,
    paddingHorizontal: 2,
  },
  liveLinkText: {
    color: Pass50.lime,
    fontSize: 13,
    fontWeight: '900',
  },
  panel: {
    borderWidth: 1,
    borderColor: Pass50.line,
    borderRadius: 18,
    backgroundColor: Pass50.panel,
    overflow: 'hidden',
  },
  empty: {
    color: Pass50.muted,
    padding: 16,
    lineHeight: 20,
  },
  error: {
    color: Pass50.danger,
    padding: 16,
  },
});
