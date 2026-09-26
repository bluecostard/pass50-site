import { Image, Pressable, StyleSheet, Text, View } from 'react-native';
import { Link } from 'expo-router';

import { Pass50 } from '@/constants/Colors';
import { RankingRow } from '@/src/types';

function deltaLabel(delta?: number) {
  const n = Number(delta) || 0;
  if (n > 0) return { text: `+${n}`, style: styles.deltaUp };
  if (n < 0) return { text: String(n), style: styles.deltaDown };
  return { text: '=', style: styles.deltaFlat };
}

type Props = {
  row: RankingRow;
};

export function RankRow({ row }: Props) {
  const delta = deltaLabel(row.delta);
  const initials = row.initials ?? String(row.name || '?').slice(0, 2).toUpperCase();

  return (
    <Link href={`/influencer/${encodeURIComponent(row.id)}`} asChild>
      <Pressable style={styles.row}>
        <Text style={styles.rank}>{row.rank}</Text>
        <View style={styles.avatar}>
          {row.photoUrl ? (
            <Image source={{ uri: row.photoUrl }} style={styles.photo} />
          ) : (
            <Text style={styles.initials}>{initials}</Text>
          )}
        </View>
        <View style={styles.meta}>
          <Text style={styles.name} numberOfLines={1}>
            {row.name}
          </Text>
          <Text style={styles.handle} numberOfLines={1}>
            {row.handle || row.category || ''}
          </Text>
        </View>
        <View style={styles.scoreBlock}>
          <Text style={styles.score}>{Number(row.score || 0).toFixed(1)}</Text>
          <Text style={[styles.delta, delta.style]}>{delta.text}</Text>
        </View>
      </Pressable>
    </Link>
  );
}

const styles = StyleSheet.create({
  row: {
    flexDirection: 'row',
    alignItems: 'center',
    gap: 10,
    paddingVertical: 11,
    paddingHorizontal: 10,
    borderBottomWidth: StyleSheet.hairlineWidth,
    borderBottomColor: 'rgba(41,49,41,.85)',
  },
  rank: {
    width: 34,
    textAlign: 'center',
    fontWeight: '900',
    color: Pass50.lime,
  },
  avatar: {
    width: 46,
    height: 46,
    borderRadius: 14,
    overflow: 'hidden',
    backgroundColor: '#0b0e0b',
    alignItems: 'center',
    justifyContent: 'center',
  },
  photo: {
    width: '100%',
    height: '100%',
  },
  initials: {
    fontWeight: '900',
    fontSize: 12,
    color: '#d7dfd4',
  },
  meta: {
    flex: 1,
    minWidth: 0,
  },
  name: {
    color: Pass50.text,
    fontWeight: '800',
    fontSize: 15,
  },
  handle: {
    color: Pass50.muted,
    fontSize: 11,
    marginTop: 2,
  },
  scoreBlock: {
    alignItems: 'flex-end',
  },
  score: {
    color: Pass50.text,
    fontWeight: '900',
    fontSize: 15,
  },
  delta: {
    fontSize: 11,
    fontWeight: '900',
    marginTop: 2,
  },
  deltaUp: { color: Pass50.lime },
  deltaDown: { color: Pass50.red },
  deltaFlat: { color: '#c5cdc2' },
});
