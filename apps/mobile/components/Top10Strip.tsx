import { Link } from 'expo-router';
import { Image, Pressable, ScrollView, StyleSheet, Text, View } from 'react-native';

import { Pass50 } from '@/constants/Colors';
import { RankingRow } from '@/src/types';

type Props = {
  rows: RankingRow[];
};

function arrowFor(delta?: number) {
  const n = Number(delta) || 0;
  if (n > 0) return { glyph: '↑', tone: styles.arrowUp };
  if (n < 0) return { glyph: '↓', tone: styles.arrowDown };
  return { glyph: '—', tone: styles.arrowFlat };
}

/** Bandeau horizontal Top 10 (rangs 4–10), comme `#top10` sur le site. */
export function Top10Strip({ rows }: Props) {
  return (
    <ScrollView
      horizontal
      showsHorizontalScrollIndicator={false}
      contentContainerStyle={styles.row}
      decelerationRate="fast"
      snapToInterval={148}
      snapToAlignment="start">
      {rows.map((row) => {
        const arrow = arrowFor(row.delta);
        const initials = row.initials ?? String(row.name || '?').slice(0, 2).toUpperCase();
        const score = Math.round(Number(row.score || 0));
        return (
          <Link key={row.id} href={`/influencer/${encodeURIComponent(row.id)}`} asChild>
            <Pressable style={styles.card}>
              <Text style={styles.rank}>{row.rank}</Text>
              <View style={[styles.arrow, arrow.tone]}>
                <Text style={styles.arrowText}>{arrow.glyph}</Text>
              </View>
              <View style={styles.avatar}>
                {row.photoUrl ? (
                  <Image source={{ uri: row.photoUrl }} style={styles.photo} />
                ) : (
                  <Text style={styles.initials}>{initials}</Text>
                )}
              </View>
              <Text style={styles.name} numberOfLines={2}>
                {row.name}
              </Text>
              <Text style={styles.score}>{score}/100</Text>
            </Pressable>
          </Link>
        );
      })}
    </ScrollView>
  );
}

const styles = StyleSheet.create({
  row: {
    gap: 10,
    paddingRight: 8,
    paddingBottom: 4,
  },
  card: {
    width: 138,
    borderWidth: 1,
    borderColor: Pass50.line,
    borderRadius: 18,
    backgroundColor: '#0b0e0b',
    padding: 10,
    position: 'relative',
  },
  rank: {
    position: 'absolute',
    left: 10,
    top: 8,
    zIndex: 2,
    fontSize: 20,
    fontWeight: '900',
    color: Pass50.text,
  },
  arrow: {
    position: 'absolute',
    right: 8,
    top: 8,
    zIndex: 2,
    minWidth: 27,
    height: 27,
    borderRadius: 999,
    alignItems: 'center',
    justifyContent: 'center',
    borderWidth: 1,
    backgroundColor: 'rgba(5,7,5,.82)',
  },
  arrowUp: { borderColor: 'rgba(183,255,0,.45)' },
  arrowDown: { borderColor: 'rgba(255,75,75,.55)' },
  arrowFlat: { borderColor: 'rgba(183,255,0,.25)' },
  arrowText: {
    color: Pass50.text,
    fontWeight: '900',
    fontSize: 13,
  },
  avatar: {
    height: 88,
    borderRadius: 14,
    overflow: 'hidden',
    backgroundColor: '#121612',
    alignItems: 'center',
    justifyContent: 'center',
    marginBottom: 8,
  },
  photo: { width: '100%', height: '100%' },
  initials: { color: '#d7dfd4', fontWeight: '900', fontSize: 22 },
  name: {
    color: Pass50.text,
    fontWeight: '900',
    fontSize: 13,
    minHeight: 34,
  },
  score: {
    marginTop: 4,
    color: Pass50.text,
    fontWeight: '900',
    fontSize: 20,
  },
});
