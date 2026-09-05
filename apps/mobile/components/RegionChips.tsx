import { Pressable, ScrollView, StyleSheet, Text } from 'react-native';

import { Pass50 } from '@/constants/Colors';

export type RankingRegion = 'ALL' | 'CI' | 'DIASPORA';

const REGIONS: { key: RankingRegion; label: string }[] = [
  { key: 'ALL', label: 'TOUS' },
  { key: 'CI', label: "🇨🇮  CÔTE D'IVOIRE" },
  { key: 'DIASPORA', label: '🌍  DIASPORA' },
];

type Props = {
  value: RankingRegion;
  onChange: (region: RankingRegion) => void;
};

/** Filtres zone — même logique que index.html (ALL / CI / DIASPORA). */
export function RegionChips({ value, onChange }: Props) {
  return (
    <ScrollView horizontal showsHorizontalScrollIndicator={false} contentContainerStyle={styles.row}>
      {REGIONS.map((region) => {
        const active = region.key === value;
        return (
          <Pressable
            key={region.key}
            onPress={() => onChange(region.key)}
            style={[styles.chip, active && styles.chipActive]}>
            <Text style={[styles.chipText, active && styles.chipTextActive]}>{region.label}</Text>
          </Pressable>
        );
      })}
    </ScrollView>
  );
}

const styles = StyleSheet.create({
  row: {
    gap: 8,
    paddingVertical: 2,
  },
  chip: {
    borderWidth: 1,
    borderColor: Pass50.line,
    borderRadius: 999,
    paddingHorizontal: 14,
    paddingVertical: 10,
    backgroundColor: '#0a0d0a',
  },
  chipActive: {
    backgroundColor: Pass50.lime,
    borderColor: Pass50.lime,
  },
  chipText: {
    color: Pass50.text,
    fontWeight: '900',
    fontSize: 11,
    letterSpacing: 0.4,
  },
  chipTextActive: {
    color: '#050705',
  },
});
