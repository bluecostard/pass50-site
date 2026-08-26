import { Pressable, ScrollView, StyleSheet, Text, View } from 'react-native';

import { Pass50 } from '@/constants/Colors';
import { RankingPeriod } from '@/src/types';

const PERIODS: RankingPeriod[] = ['2H', '24H', '48H', '7J', '15J'];

type Props = {
  value: RankingPeriod;
  onChange: (period: RankingPeriod) => void;
};

export function PeriodChips({ value, onChange }: Props) {
  return (
    <ScrollView horizontal showsHorizontalScrollIndicator={false} contentContainerStyle={styles.row}>
      {PERIODS.map((period) => {
        const active = period === value;
        return (
          <Pressable
            key={period}
            onPress={() => onChange(period)}
            style={[styles.chip, active && styles.chipActive]}>
            <Text style={[styles.chipText, active && styles.chipTextActive]}>{period}</Text>
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
    paddingHorizontal: 13,
    paddingVertical: 9,
    backgroundColor: '#0a0d0a',
  },
  chipActive: {
    backgroundColor: Pass50.lime,
    borderColor: Pass50.lime,
  },
  chipText: {
    color: Pass50.text,
    fontWeight: '900',
    fontSize: 13,
  },
  chipTextActive: {
    color: Pass50.bg,
  },
});
