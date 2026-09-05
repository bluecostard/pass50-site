import { useCallback, useEffect, useMemo, useRef, useState } from 'react';
import {
  LayoutChangeEvent,
  Pressable,
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
import { RankRow } from '@/components/RankRow';
import { RegionChips, RankingRegion } from '@/components/RegionChips';
import { Top10Strip } from '@/components/Top10Strip';
import { TrendContentCard } from '@/components/TrendContentCard';
import { Pass50 } from '@/constants/Colors';
import { pass50Api } from '@/src/api/client';
import { FeedItem, PublicFeed, PublicRanking, RankingPeriod, RankingRow } from '@/src/types';

const TREND_PERIODS: { key: string; label: string; ranking: RankingPeriod }[] = [
  { key: '2h', label: '2 H', ranking: '2H' },
  { key: '24h', label: '24 H', ranking: '24H' },
  { key: '48h', label: '48 H', ranking: '48H' },
  { key: '7d', label: '7 J', ranking: '7J' },
  { key: '15d', label: '15 J', ranking: '15J' },
];

function regionEligible(row: RankingRow, region: RankingRegion) {
  if (region === 'ALL') return true;
  return row.region === region || row.region === 'BOTH';
}

function rankingToFeedPeriod(period: RankingPeriod): string {
  return TREND_PERIODS.find((item) => item.ranking === period)?.key ?? '24h';
}

/** Classement — Top 3 → Top 10 → Top 50 → Top 5 contenus tendance (site mobile). */
export default function RankingScreen() {
  const insets = useSafeAreaInsets();
  const scrollRef = useRef<ScrollView>(null);
  const top50Y = useRef(0);

  const [period, setPeriod] = useState<RankingPeriod>('24H');
  const [region, setRegion] = useState<RankingRegion>('ALL');
  const [trendPeriod, setTrendPeriod] = useState('24h');
  const [data, setData] = useState<PublicRanking | null>(null);
  const [feed, setFeed] = useState<PublicFeed | null>(null);
  const [loading, setLoading] = useState(true);
  const [error, setError] = useState('');

  const loadRanking = useCallback(async () => {
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

  const loadTrends = useCallback(async (feedPeriod: string) => {
    try {
      setFeed(await pass50Api.feed(feedPeriod));
    } catch {
      setFeed({ trends: [] });
    }
  }, []);

  useEffect(() => {
    void loadRanking();
  }, [loadRanking]);

  useEffect(() => {
    void loadTrends(trendPeriod);
  }, [loadTrends, trendPeriod]);

  useEffect(() => {
    setTrendPeriod(rankingToFeedPeriod(period));
  }, [period]);

  const onRefresh = useCallback(async () => {
    await Promise.all([loadRanking(), loadTrends(trendPeriod)]);
  }, [loadRanking, loadTrends, trendPeriod]);

  const rows = useMemo(() => {
    const list = data?.periods?.[period] ?? [];
    return list.filter((row) => regionEligible(row, region));
  }, [data, period, region]);

  const top3 = rows.slice(0, 3);
  const top10Tail = rows.slice(3, 10);
  const trends: FeedItem[] = (feed?.trends ?? []).slice(0, 5);

  const onTop50Layout = (event: LayoutChangeEvent) => {
    top50Y.current = event.nativeEvent.layout.y;
  };

  const scrollToTop50 = () => {
    scrollRef.current?.scrollTo({ y: Math.max(0, top50Y.current - 8), animated: true });
  };

  return (
    <View style={[styles.root, { paddingTop: insets.top + 8 }]}>
      <ScrollView
        ref={scrollRef}
        contentContainerStyle={[styles.content, { paddingBottom: insets.bottom + 110 }]}
        refreshControl={
          <RefreshControl refreshing={loading} onRefresh={onRefresh} tintColor={Pass50.lime} />
        }
        showsVerticalScrollIndicator={false}>
        <PeriodChips value={period} onChange={setPeriod} />
        <RegionChips value={region} onChange={setRegion} />

        {error && !data ? <Text style={styles.error}>{error}</Text> : null}

        {/* 1. Top 3 — buzz + cartes */}
        {top3.length ? (
          <BuzzHero top3={top3} period={period} updatedAt={data?.publishedAt} />
        ) : null}
        {top3.map((row, index) => (
          <RankCard key={`top3-${row.id}`} row={row} index={index} />
        ))}

        {/* 2. Top 10 — rangs 4–10 + CTA Top 50 */}
        {top10Tail.length ? (
          <View style={styles.section}>
            <View style={styles.sectionHead}>
              <Text style={styles.sectionTitle}>📈 TOP 10</Text>
              <Pressable style={styles.top50Cta} onPress={scrollToTop50} accessibilityRole="button">
                <Text style={styles.top50CtaText}>TOP 50 →</Text>
              </Pressable>
            </View>
            <Top10Strip rows={top10Tail} />
          </View>
        ) : null}

        {/* 3. Top 50 */}
        {rows.length ? (
          <View style={styles.section} onLayout={onTop50Layout}>
            <View style={styles.sectionHead}>
              <Text style={styles.sectionTitle}>🏆 TOP 50</Text>
              <Text style={styles.sectionHint}>{rows.length} profils</Text>
            </View>
            <View style={styles.top50Box}>
              {rows.map((row) => (
                <RankRow key={`top50-${row.id}`} row={row} />
              ))}
            </View>
          </View>
        ) : (
          <View style={styles.emptyBox}>
            <Text style={styles.empty}>
              {loading ? 'Chargement…' : `Aucun profil classé pour ${period}.`}
            </Text>
          </View>
        )}

        {/* 4. Top 5 contenus tendance */}
        <View style={styles.section}>
          <View style={styles.sectionHead}>
            <Text style={styles.sectionTitle}>🔥 TOP 5 CONTENUS TENDANCE</Text>
          </View>
          <ScrollView
            horizontal
            showsHorizontalScrollIndicator={false}
            contentContainerStyle={styles.trendChips}>
            {TREND_PERIODS.map((item) => {
              const active = item.key === trendPeriod;
              return (
                <Pressable
                  key={item.key}
                  onPress={() => setTrendPeriod(item.key)}
                  style={[styles.trendChip, active && styles.trendChipActive]}>
                  <Text style={[styles.trendChipText, active && styles.trendChipTextActive]}>
                    {item.label}
                  </Text>
                </Pressable>
              );
            })}
          </ScrollView>
          {trends.length ? (
            trends.map((item, index) => (
              <TrendContentCard
                key={`${item.url || item.title || 'trend'}-${index}`}
                item={item}
                index={index}
              />
            ))
          ) : (
            <View style={styles.emptyBox}>
              <Text style={styles.empty}>Aucun contenu tendance sur cette période.</Text>
            </View>
          )}
        </View>
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
    gap: 12,
  },
  section: {
    gap: 10,
    marginTop: 4,
  },
  sectionHead: {
    flexDirection: 'row',
    alignItems: 'center',
    justifyContent: 'space-between',
    gap: 10,
  },
  sectionTitle: {
    color: Pass50.text,
    fontWeight: '900',
    fontSize: 16,
    letterSpacing: 0.2,
    flexShrink: 1,
  },
  sectionHint: {
    color: Pass50.muted,
    fontWeight: '700',
    fontSize: 12,
  },
  top50Cta: {
    borderWidth: 1,
    borderColor: Pass50.lime,
    borderRadius: 999,
    paddingHorizontal: 12,
    paddingVertical: 7,
    backgroundColor: 'rgba(183,255,0,.08)',
  },
  top50CtaText: {
    color: Pass50.lime,
    fontWeight: '900',
    fontSize: 12,
    letterSpacing: 0.3,
  },
  top50Box: {
    borderWidth: 1,
    borderColor: Pass50.line,
    borderRadius: 18,
    backgroundColor: Pass50.panel,
    overflow: 'hidden',
  },
  trendChips: {
    gap: 8,
    paddingVertical: 2,
  },
  trendChip: {
    borderWidth: 1,
    borderColor: Pass50.line,
    borderRadius: 999,
    paddingHorizontal: 12,
    paddingVertical: 8,
    backgroundColor: '#0a0d0a',
  },
  trendChipActive: {
    backgroundColor: Pass50.lime,
    borderColor: Pass50.lime,
  },
  trendChipText: {
    color: Pass50.text,
    fontWeight: '900',
    fontSize: 12,
  },
  trendChipTextActive: {
    color: '#050705',
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
