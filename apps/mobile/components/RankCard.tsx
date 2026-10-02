import { Ionicons } from '@expo/vector-icons';
import { Link } from 'expo-router';
import { Image, Pressable, Share, StyleSheet, Text, View } from 'react-native';

import { Pass50 } from '@/constants/Colors';
import { RankingRow } from '@/src/types';

type Props = {
  row: RankingRow;
  index: number;
};

function deltaLabel(delta?: number) {
  const n = Number(delta) || 0;
  if (n > 0) return { text: `+${n}`, style: styles.deltaUp };
  if (n < 0) return { text: String(n), style: styles.deltaDown };
  return { text: '=', style: styles.deltaFlat };
}

/** Carte classement photo — style site mobile (.rank-card). */
export function RankCard({ row, index }: Props) {
  const delta = deltaLabel(row.delta);
  const initials = row.initials ?? String(row.name || '?').slice(0, 2).toUpperCase();
  const tier = index === 0 ? 'first' : index === 1 ? 'second' : index === 2 ? 'third' : 'rest';
  const score = Number(row.score || 0);

  const onShare = async () => {
    try {
      await Share.share({
        message: `${row.name} est #${row.rank} sur PASS50 — https://pass50.store/fi/${encodeURIComponent(row.id)}`,
        url: `https://pass50.store/fi/${encodeURIComponent(row.id)}`,
      });
    } catch {
      // ignore cancel
    }
  };

  return (
    <View style={[styles.card, styles[tier]]}>
      <Text style={[styles.rankNum, tier === 'third' && styles.rankNumThird]}>{row.rank}</Text>
      <Link href={`/influencer/${encodeURIComponent(row.id)}`} asChild>
        <Pressable>
          <View style={styles.avatar}>
            {row.photoUrl ? (
              <Image source={{ uri: row.photoUrl }} style={styles.photo} />
            ) : (
              <Text style={styles.initials}>{initials}</Text>
            )}
          </View>
          <Text style={styles.name} numberOfLines={1}>
            {String(row.name || '').toUpperCase()}
          </Text>
          <Text style={styles.handle} numberOfLines={1}>
            {row.handle || ''}
          </Text>
          <View style={styles.scoreRow}>
            <View>
              <Text style={styles.scoreLabel}>TREND SCORE</Text>
              <Text style={styles.score}>{Math.round(Number(score))}/100</Text>
            </View>
            <Text style={[styles.delta, delta.style]}>{delta.text}</Text>
          </View>
        </Pressable>
      </Link>
      <View style={styles.actions}>
        <Pressable style={styles.actionBtn} accessibilityRole="button" accessibilityLabel="Favori">
          <Text style={styles.actionText}>☆ Favori</Text>
        </Pressable>
        <Pressable style={styles.actionBtn} accessibilityRole="button" accessibilityLabel="J'aime">
          <Text style={styles.actionText}>♥ J'aime</Text>
        </Pressable>
        <Pressable style={styles.actionBtn} accessibilityRole="button" accessibilityLabel="Suivre">
          <Text style={styles.actionText}>+ Suivre</Text>
        </Pressable>
      </View>
      <Pressable
        style={styles.shareFab}
        onPress={onShare}
        accessibilityRole="button"
        accessibilityLabel={`Partager ${row.name}`}>
        <Ionicons name="share-outline" size={20} color="#050705" />
      </Pressable>
    </View>
  );
}

const styles = StyleSheet.create({
  card: {
    borderRadius: 19,
    borderWidth: 1,
    borderColor: Pass50.line,
    backgroundColor: Pass50.panel,
    padding: 12,
    marginBottom: 12,
    position: 'relative',
    overflow: 'hidden',
  },
  first: {
    borderColor: Pass50.lime,
    shadowColor: Pass50.lime,
    shadowOpacity: 0.22,
    shadowRadius: 18,
    shadowOffset: { width: 0, height: 0 },
  },
  second: {
    borderColor: '#a8b0ba',
  },
  third: {
    borderColor: '#ff9d1d',
  },
  rest: {},
  rankNum: {
    position: 'absolute',
    top: 6,
    left: 14,
    zIndex: 3,
    fontSize: 51,
    fontWeight: '900',
    color: Pass50.lime,
    textShadowColor: 'rgba(5,7,5,.55)',
    textShadowOffset: { width: 0, height: 2 },
    textShadowRadius: 6,
  },
  rankNumThird: {
    color: '#ff9d1d',
  },
  avatar: {
    height: 250,
    borderRadius: 18,
    overflow: 'hidden',
    backgroundColor: '#0c0f0c',
    alignItems: 'center',
    justifyContent: 'center',
    marginBottom: 12,
  },
  photo: {
    width: '100%',
    height: '100%',
  },
  initials: {
    color: '#d7dfd4',
    fontSize: 42,
    fontWeight: '900',
  },
  name: {
    color: Pass50.text,
    fontSize: 22,
    fontWeight: '900',
    letterSpacing: -0.6,
  },
  handle: {
    color: Pass50.muted,
    fontSize: 12,
    marginTop: 2,
  },
  scoreRow: {
    marginTop: 14,
    flexDirection: 'row',
    justifyContent: 'space-between',
    alignItems: 'flex-end',
  },
  scoreLabel: {
    color: Pass50.muted,
    fontSize: 9,
    fontWeight: '900',
    letterSpacing: 1.1,
    marginBottom: 2,
  },
  score: {
    color: Pass50.text,
    fontSize: 30,
    fontWeight: '900',
  },
  delta: {
    fontSize: 16,
    fontWeight: '900',
  },
  deltaUp: { color: Pass50.lime },
  deltaDown: { color: Pass50.red },
  deltaFlat: { color: '#c5cdc2' },
  actions: {
    marginTop: 12,
    flexDirection: 'row',
    flexWrap: 'wrap',
    gap: 8,
  },
  actionBtn: {
    borderWidth: 1,
    borderColor: 'rgba(255,157,29,.55)',
    borderRadius: 999,
    paddingHorizontal: 12,
    paddingVertical: 8,
    backgroundColor: '#050705',
  },
  actionText: {
    color: Pass50.text,
    fontWeight: '800',
    fontSize: 12,
  },
  shareFab: {
    position: 'absolute',
    right: 14,
    top: 210,
    width: 44,
    height: 44,
    borderRadius: 22,
    backgroundColor: Pass50.lime,
    alignItems: 'center',
    justifyContent: 'center',
    zIndex: 4,
    shadowColor: '#000',
    shadowOpacity: 0.35,
    shadowRadius: 8,
    shadowOffset: { width: 0, height: 4 },
    elevation: 6,
  },
});
