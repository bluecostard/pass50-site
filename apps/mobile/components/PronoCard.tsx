import { Pressable, StyleSheet, Text, View } from 'react-native';

import { Pass50 } from '@/constants/Colors';
import type { PronoQuestion } from '@/src/types';

function fmtOdd(value?: number) {
  const n = Number(value) || 0;
  return n >= 10 ? n.toFixed(1) : n.toFixed(2);
}

function closesLabel(iso?: string) {
  if (!iso) return '';
  const ms = new Date(iso).getTime() - Date.now();
  if (ms <= 0) return 'Fermé';
  const hours = Math.floor(ms / 3600000);
  if (hours < 24) return `Ferme dans ${hours}h`;
  const days = Math.floor(hours / 24);
  return `Ferme dans ${days}j`;
}

type Props = {
  item: PronoQuestion;
  onVote?: (questionId: string, optionKey: string) => void;
  voting?: boolean;
  disabled?: boolean;
};

export function PronoCard({ item, onVote, voting, disabled }: Props) {
  const voted = Boolean(item.myVote);

  return (
    <View style={styles.card}>
      {item.themeLabel ? <Text style={styles.theme}>{item.themeLabel}</Text> : null}
      <Text style={styles.title}>{item.title}</Text>
      {item.context ? <Text style={styles.context}>{item.context}</Text> : null}
      <Text style={styles.meta}>
        Mise {item.stake ?? 100} pts · {closesLabel(item.closesAt)}
        {item.totalVotes ? ` · ${item.totalVotes} votes` : ''}
      </Text>

      <View style={styles.options}>
        {(item.options ?? []).map((option) => {
          const selected = item.myVote?.optionKey === option.key;
          return (
            <Pressable
              key={option.key}
              disabled={disabled || voting || voted}
              onPress={() => onVote?.(item.id, option.key)}
              style={[
                styles.option,
                selected && styles.optionSelected,
                voted && !selected && styles.optionMuted,
              ]}>
              <View style={styles.optionCopy}>
                <Text style={styles.optionLabel}>{option.label}</Text>
                {option.votePercent !== undefined && option.votePercent > 0 ? (
                  <Text style={styles.optionPercent}>{Math.round(option.votePercent)} %</Text>
                ) : null}
              </View>
              <View style={styles.optionOdds}>
                <Text style={styles.odd}>{fmtOdd(option.odd)}</Text>
                <Text style={styles.payout}>+{option.payout ?? Math.round((item.stake ?? 100) * option.odd)}</Text>
              </View>
            </Pressable>
          );
        })}
      </View>

      {voted && item.myVote ? (
        <Text style={styles.voted}>
          Prono validé · cote {fmtOdd(item.myVote.oddLocked)}
          {item.myVote.potentialPayout ? ` · +${item.myVote.potentialPayout} pts si correct` : ''}
        </Text>
      ) : null}
    </View>
  );
}

const styles = StyleSheet.create({
  card: {
    borderWidth: 1,
    borderColor: Pass50.line,
    borderRadius: 18,
    backgroundColor: Pass50.panel,
    padding: 16,
    gap: 10,
  },
  theme: {
    color: Pass50.lime,
    fontSize: 11,
    fontWeight: '900',
    letterSpacing: 0.8,
  },
  title: {
    color: Pass50.text,
    fontSize: 17,
    fontWeight: '900',
    lineHeight: 22,
  },
  context: {
    color: Pass50.muted,
    fontSize: 13,
    lineHeight: 19,
  },
  meta: {
    color: '#8f998d',
    fontSize: 11,
    fontWeight: '800',
  },
  options: { gap: 8, marginTop: 4 },
  option: {
    flexDirection: 'row',
    alignItems: 'center',
    justifyContent: 'space-between',
    borderWidth: 1,
    borderColor: Pass50.line,
    borderRadius: 14,
    backgroundColor: '#090c09',
    paddingHorizontal: 12,
    paddingVertical: 11,
    gap: 10,
  },
  optionSelected: {
    borderColor: Pass50.lime,
    backgroundColor: 'rgba(183,255,0,.08)',
  },
  optionMuted: { opacity: 0.55 },
  optionCopy: { flex: 1, minWidth: 0 },
  optionLabel: { color: Pass50.text, fontWeight: '800', fontSize: 14 },
  optionPercent: { color: Pass50.muted, fontSize: 11, marginTop: 2, fontWeight: '800' },
  optionOdds: { alignItems: 'flex-end' },
  odd: { color: Pass50.lime, fontWeight: '900', fontSize: 16 },
  payout: { color: Pass50.muted, fontSize: 11, fontWeight: '800', marginTop: 2 },
  voted: {
    color: '#c9d4c0',
    fontSize: 12,
    fontWeight: '800',
    lineHeight: 18,
  },
});
