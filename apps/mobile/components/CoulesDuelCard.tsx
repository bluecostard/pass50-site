import { Image, Pressable, StyleSheet, Text, View } from 'react-native';

import { Pass50 } from '@/constants/Colors';

export type CoulesDuelProfile = {
  id: string;
  name: string;
  initials?: string;
  photoUrl?: string;
  decline: number;
  currentRank: number;
  share?: number;
  voteCount?: number;
};

type Props = {
  candidates: CoulesDuelProfile[];
  myVote?: string | null;
  onVote?: (profileId: string) => void;
  voting?: boolean;
  disabled?: boolean;
};

function CandidateCard({
  profile,
  selected,
  onPress,
  disabled,
}: {
  profile: CoulesDuelProfile;
  selected: boolean;
  onPress?: () => void;
  disabled?: boolean;
}) {
  const initials = profile.initials ?? profile.name.slice(0, 2).toUpperCase();

  return (
    <Pressable
      disabled={disabled}
      onPress={onPress}
      style={[styles.candidate, selected && styles.candidateSelected]}>
      <View style={styles.avatar}>
        {profile.photoUrl ? (
          <Image source={{ uri: profile.photoUrl }} style={styles.photo} />
        ) : (
          <Text style={styles.initials}>{initials}</Text>
        )}
      </View>
      <Text style={styles.name} numberOfLines={2}>
        {profile.name}
      </Text>
      <Text style={styles.decline}>↘ {profile.decline.toFixed(1)} %</Text>
      <Text style={styles.rank}>Rang actuel · #{profile.currentRank}</Text>
      {profile.share !== undefined ? (
        <Text style={styles.share}>{profile.share} % des votes</Text>
      ) : null}
      {selected ? <Text style={styles.myVoteTag}>Mon vote</Text> : null}
    </Pressable>
  );
}

export function CoulesDuelCard({ candidates, myVote, onVote, voting, disabled }: Props) {
  if (candidates.length < 2) return null;

  return (
    <View style={styles.card}>
      <Text style={styles.eyebrow}>DUEL DU MOMENT</Text>
      <Text style={styles.title}>Qui est le plus coulé ?</Text>
      <Text style={styles.subtitle}>Vote unique par compte · modifiable</Text>
      <View style={styles.row}>
        <CandidateCard
          profile={candidates[0]}
          selected={myVote === candidates[0].id}
          disabled={disabled || voting}
          onPress={() => onVote?.(candidates[0].id)}
        />
        <Text style={styles.vs}>VS</Text>
        <CandidateCard
          profile={candidates[1]}
          selected={myVote === candidates[1].id}
          disabled={disabled || voting}
          onPress={() => onVote?.(candidates[1].id)}
        />
      </View>
    </View>
  );
}

const styles = StyleSheet.create({
  card: {
    borderWidth: 1,
    borderColor: 'rgba(212,175,55,.28)',
    borderRadius: 18,
    backgroundColor: '#0b0d0a',
    padding: 16,
    gap: 8,
  },
  eyebrow: {
    color: '#f0d27a',
    fontSize: 11,
    fontWeight: '900',
    letterSpacing: 1,
  },
  title: {
    color: Pass50.text,
    fontSize: 20,
    fontWeight: '900',
  },
  subtitle: {
    color: Pass50.muted,
    fontSize: 12,
    marginBottom: 6,
  },
  row: {
    flexDirection: 'row',
    alignItems: 'stretch',
    gap: 8,
  },
  vs: {
    alignSelf: 'center',
    color: '#f0d27a',
    fontWeight: '900',
    fontSize: 13,
  },
  candidate: {
    flex: 1,
    borderWidth: 1,
    borderColor: Pass50.line,
    borderRadius: 16,
    backgroundColor: Pass50.panel,
    padding: 12,
    alignItems: 'center',
    gap: 6,
  },
  candidateSelected: {
    borderColor: '#f0d27a',
    backgroundColor: 'rgba(240,210,122,.08)',
  },
  avatar: {
    width: 64,
    height: 64,
    borderRadius: 18,
    overflow: 'hidden',
    backgroundColor: '#0b0e0b',
    alignItems: 'center',
    justifyContent: 'center',
    borderWidth: 1,
    borderColor: 'rgba(240,210,122,.24)',
  },
  photo: { width: '100%', height: '100%' },
  initials: { color: Pass50.text, fontWeight: '900', fontSize: 18 },
  name: {
    color: Pass50.text,
    fontWeight: '900',
    fontSize: 13,
    textAlign: 'center',
    textTransform: 'uppercase',
  },
  decline: { color: Pass50.red, fontWeight: '900', fontSize: 14 },
  rank: { color: Pass50.muted, fontSize: 10, fontWeight: '800', textAlign: 'center' },
  share: { color: Pass50.lime, fontSize: 12, fontWeight: '900' },
  myVoteTag: {
    marginTop: 4,
    color: '#f0d27a',
    fontSize: 10,
    fontWeight: '900',
    letterSpacing: 0.6,
  },
});
