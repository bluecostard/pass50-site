import { useCallback, useEffect, useMemo, useState } from 'react';
import {
  RefreshControl,
  ScrollView,
  StyleSheet,
  Text,
  View,
} from 'react-native';
import { useSafeAreaInsets } from 'react-native-safe-area-context';

import { BuzzHero } from '@/components/BuzzHero';
import { PeriodChips } from '@/components/PeriodChips';
import { RankCard } from '@/components/RankCard';
import { RegionChips, RankingRegion } from '@/components/RegionChips';
import { Pass50 } from '@/constants/Colors';
import { pass50Api } from '@/src/api/client';
import { PublicRanking, RankingPeriod, RankingRow } from '@/src/types';

function regionEligible(row: RankingRow, region: RankingRegion) {
  if (region === 'ALL') return true;
  return row.region === region || row.region === 'BOTH';
}

/** Classement — présentation site mobile (filtres + buzz + cartes photo). */
export default function RankingScreen() {
  const insets = useSafeAreaInsets();
  const [period, setPeriod] = useState<RankingPeriod>('24H');
  const [region, setRegion] = useState<RankingRegion>('ALL');
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

  const rows = useMemo(() => {
    const list = data?.periods?.[period] ?? [];
    return list.filter((row) => regionEligible(row, region));
  }, [data, period, region]);

  const top3 = rows.slice(0, 3);

  return (
    <View style={[styles.root, { paddingTop: insets.top + 8 }]}>
      <ScrollView
        contentContainerStyle={[styles.content, { paddingBottom: insets.bottom + 110 }]}
        refreshControl={
          <RefreshControl refreshing={loading} onRefresh={load} tintColor={Pass50.lime} />
        }
        showsVerticalScrollIndicator={false}>
        <PeriodChips value={period} onChange={setPeriod} />
        <RegionChips value={region} onChange={setRegion} />

        {error && !data ? <Text style={styles.error}>{error}</Text> : null}

        {top3.length ? <BuzzHero top3={top3} period={period} updatedAt={data?.publishedAt} /> : null}

        {rows.length ? (
          rows.map((row, index) => <RankCard key={row.id} row={row} index={index} />)
        ) : (
          <View style={styles.emptyBox}>
            <Text style={styles.empty}>
              {loading ? 'Chargement…' : `Aucun profil classé pour ${period}.`}
            </Text>
          </View>
        )}
      </ScrollView>
    </View>
  );
}

const styles = StyleSheet.create({
  root: {
    flex: 1,
    backgroundColor: Pass50.bg,
  },
  content: {
    paddingHorizontal: 12,
    gap: 10,
  },
  emptyBox: {
    borderWidth: 1,
    borderColor: Pass50.line,
    borderRadius: 18,
    backgroundColor: Pass50.panel,
    padding: 18,
  },
  empty: {
    color: Pass50.muted,
    lineHeight: 20,
  },
  error: {
    color: Pass50.danger,
    paddingVertical: 8,
  },
});
