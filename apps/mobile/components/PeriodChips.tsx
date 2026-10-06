import { Pressable, ScrollView, StyleSheet, Text } from 'react-native';

import { Pass50 } from '@/constants/Colors';
import { RankingPeriod } from '@/src/types';

const PERIODS: { key: RankingPeriod; label: string }[] = [
  { key: '2H', label: '2 H' },
  { key: '24H', label: '24 H' },
  { key: '48H', label: '48 H' },
  { key: '7J', label: '7 JOURS' },
  { key: '15J', label: '15 JOURS' },
];

type Props = {
  value: RankingPeriod;
  onChange: (period: RankingPeriod) => void;
};

/** Chips période — mêmes libellés que le site mobile. */
export function PeriodChips({ value, onChange }: Props) {
  return (
    <ScrollView horizontal showsHorizontalScrollIndicator={false} contentContainerStyle={styles.row}>
      {PERIODS.map((period) => {
        const active = period.key === value;
        return (
          <Pressable
            key={period.key}
            onPress={() => onChange(period.key)}
            style={[styles.chip, active && styles.chipActive]}>
            <Text style={[styles.chipText, active && styles.chipTextActive]}>{period.label}</Text>
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
    borderRadius: 14,
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
    fontSize: 12,
    letterSpacing: 0.3,
  },
  chipTextActive: {
    color: '#050705',
  },
});
