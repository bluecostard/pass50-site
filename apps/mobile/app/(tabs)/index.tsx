import { useCallback, useEffect, useState } from 'react';
import { StyleSheet, Text, View } from 'react-native';

import { PeriodChips } from '@/components/PeriodChips';
import { RankRow } from '@/components/RankRow';
import { ScreenShell } from '@/components/ScreenShell';
import { Pass50 } from '@/constants/Colors';
import { pass50Api } from '@/src/api/client';
import { PublicRanking, RankingPeriod } from '@/src/types';

export default function RankingScreen() {
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
      title="Qui monte maintenant"
      subtitle={`Top ${rows.length || 50} · période ${period}`}
      refreshing={loading}
      onRefresh={load}>
      <Text style={styles.eyebrow}>CLASSEMENT PUBLIC</Text>
      <PeriodChips value={period} onChange={setPeriod} />
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
  eyebrow: {
    color: Pass50.lime,
    fontSize: 11,
    fontWeight: '900',
    letterSpacing: 0.8,
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
