import { ImageBackground, StyleSheet, Text, View } from 'react-native';

import { Pass50 } from '@/constants/Colors';
import { RankingPeriod, RankingRow } from '@/src/types';

const PERIOD_LABEL: Record<RankingPeriod, string> = {
  '2H': '2 h',
  '24H': '24 h',
  '48H': '48 h',
  '7J': '7 j',
  '15J': '15 j',
};

type Props = {
  top3: RankingRow[];
  period: RankingPeriod;
  updatedAt?: string;
};

/** Carte buzz — score cumulé du Top 3 / 300, comme le hero site. */
export function BuzzHero({ top3, period, updatedAt }: Props) {
  const total = Math.round(top3.reduce((sum, row) => sum + Number(row.score || 0), 0));
  const backdrop = top3.map((row) => row.photoUrl).filter(Boolean) as string[];
  const clock =
    updatedAt && !Number.isNaN(Date.parse(updatedAt))
      ? new Date(updatedAt).toLocaleTimeString('fr-FR', { hour: '2-digit', minute: '2-digit' })
      : new Date().toLocaleTimeString('fr-FR', { hour: '2-digit', minute: '2-digit' });

  return (
    <View style={styles.card}>
      {backdrop[0] ? (
        <ImageBackground source={{ uri: backdrop[0] }} style={styles.backdrop} imageStyle={styles.backdropImg}>
          <View style={styles.veil} />
          <HeroCopy total={total} period={period} clock={clock} />
        </ImageBackground>
      ) : (
        <View style={[styles.backdrop, styles.fallback]}>
          <HeroCopy total={total} period={period} clock={clock} />
        </View>
      )}
    </View>
  );
}

function HeroCopy({
  total,
  period,
  clock,
}: {
  total: number;
  period: RankingPeriod;
  clock: string;
}) {
  return (
    <View style={styles.copy}>
      <Text style={styles.eyebrow}>⚡ BUZZ NOW · {PERIOD_LABEL[period]}</Text>
      <Text style={styles.title}>
        LE BUZZ <Text style={styles.titleAccent}>MAINTENANT</Text>
      </Text>
      <Text style={styles.metric}>{total}/300</Text>
      <Text style={styles.metricLabel}>SCORE CUMULÉ DU TOP 3</Text>
      <Text style={styles.foot}>↗ Classement actualisé · {clock}</Text>
    </View>
  );
}

const styles = StyleSheet.create({
  card: {
    borderRadius: 19,
    overflow: 'hidden',
    borderWidth: 1,
    borderColor: Pass50.line,
    minHeight: 280,
    backgroundColor: Pass50.panel,
  },
  backdrop: {
    flex: 1,
    minHeight: 280,
    justifyContent: 'flex-end',
  },
  backdropImg: {
    resizeMode: 'cover',
  },
  fallback: {
    backgroundColor: '#0a100a',
  },
  veil: {
    ...StyleSheet.absoluteFillObject,
    backgroundColor: 'rgba(5,7,5,.72)',
  },
  copy: {
    padding: 20,
    gap: 4,
    zIndex: 1,
  },
  eyebrow: {
    color: Pass50.lime,
    fontWeight: '900',
    fontSize: 12,
    letterSpacing: 0.4,
  },
  title: {
    color: 'rgba(255,255,255,.82)',
    fontSize: 42,
    lineHeight: 44,
    fontWeight: '900',
    letterSpacing: -2.2,
    marginTop: 6,
  },
  titleAccent: {
    color: 'rgba(183,255,0,.9)',
  },
  metric: {
    marginTop: 10,
    color: Pass50.lime,
    fontSize: 40,
    fontWeight: '900',
    letterSpacing: -1.4,
  },
  metricLabel: {
    color: Pass50.muted,
    fontSize: 11,
    fontWeight: '900',
    letterSpacing: 0.8,
  },
  foot: {
    marginTop: 14,
    color: Pass50.muted,
    fontSize: 12,
    fontWeight: '700',
  },
});
