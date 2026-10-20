import { useLocalSearchParams } from 'expo-router';
import { useCallback, useEffect, useState } from 'react';
import {
  ActivityIndicator,
  Image,
  RefreshControl,
  ScrollView,
  StyleSheet,
  Text,
  View,
} from 'react-native';

import { Pass50 } from '@/constants/Colors';
import { pass50Api } from '@/src/api/client';
import { findInfluencerInRanking, RANKING_PERIODS } from '@/src/ranking/lookup';
import type { InfluencerProfile } from '@/src/ranking/lookup';
import type { RankingPeriod } from '@/src/types';

function deltaLabel(delta?: number) {
  const n = Number(delta) || 0;
  if (n > 0) return { text: `+${n}`, color: Pass50.lime };
  if (n < 0) return { text: String(n), color: Pass50.red };
  return { text: '=', color: '#c5cdc2' };
}

function ScoreGrid({ profile }: { profile: InfluencerProfile }) {
  return (
    <View style={styles.scoreGrid}>
      {RANKING_PERIODS.map((period) => {
        const score = profile.scoresByPeriod[period];
        const rank = profile.ranksByPeriod[period];
        return (
          <View key={period} style={styles.scoreCell}>
            <Text style={styles.scorePeriod}>{period}</Text>
            <Text style={styles.scoreValue}>
              {score !== undefined ? Number(score).toFixed(1) : '—'}
            </Text>
            <Text style={styles.scoreRank}>
              {rank !== undefined ? `#${rank}` : 'Hors top'}
            </Text>
          </View>
        );
      })}
    </View>
  );
}

export default function InfluencerScreen() {
  const { id } = useLocalSearchParams<{ id: string }>();
  const profileId = decodeURIComponent(String(id ?? ''));
  const [profile, setProfile] = useState<InfluencerProfile | null>(null);
  const [loading, setLoading] = useState(true);
  const [error, setError] = useState('');

  const load = useCallback(async () => {
    setLoading(true);
    setError('');
    try {
      const ranking = await pass50Api.ranking();
      setProfile(findInfluencerInRanking(ranking, profileId));
    } catch (err) {
      setError(err instanceof Error ? err.message : 'Profil indisponible');
      setProfile(null);
    } finally {
      setLoading(false);
    }
  }, [profileId]);

  useEffect(() => {
    load();
  }, [load]);

  if (loading && !profile) {
    return (
      <View style={styles.center}>
        <ActivityIndicator color={Pass50.lime} size="large" />
      </View>
    );
  }

  if (!profile) {
    return (
      <View style={styles.center}>
        <Text style={styles.errorTitle}>Profil introuvable</Text>
        <Text style={styles.errorMeta}>
          {error || `Aucune fiche classée pour « ${profileId} ».`}
        </Text>
      </View>
    );
  }

  const delta = deltaLabel(profile.delta);
  const initials = profile.initials ?? String(profile.name || '?').slice(0, 2).toUpperCase();
  const primaryPeriod: RankingPeriod = '24H';
  const primaryRank = profile.ranksByPeriod[primaryPeriod];
  const primaryScore = profile.scoresByPeriod[primaryPeriod] ?? profile.score;

  return (
    <ScrollView
      style={styles.root}
      contentContainerStyle={styles.content}
      refreshControl={<RefreshControl refreshing={loading} onRefresh={load} tintColor={Pass50.lime} />}>
      <View style={styles.hero}>
        <View style={styles.avatar}>
          {profile.photoUrl ? (
            <Image source={{ uri: profile.photoUrl }} style={styles.photo} />
          ) : (
            <Text style={styles.initials}>{initials}</Text>
          )}
        </View>
        <View style={styles.heroCopy}>
          <Text style={styles.name}>{profile.name}</Text>
          {profile.handle ? <Text style={styles.handle}>{profile.handle}</Text> : null}
          <Text style={styles.meta}>
            {[profile.category, profile.region].filter(Boolean).join(' · ')}
          </Text>
        </View>
      </View>

      <View style={styles.panel}>
        <Text style={styles.eyebrow}>SCORE {primaryPeriod}</Text>
        <View style={styles.primaryScoreRow}>
          <Text style={styles.primaryScore}>{Number(primaryScore || 0).toFixed(1)}</Text>
          <View style={styles.primaryMeta}>
            {primaryRank !== undefined ? (
              <Text style={styles.primaryRank}>#{primaryRank} sur 50</Text>
            ) : null}
            <Text style={[styles.delta, { color: delta.color }]}>{delta.text}</Text>
          </View>
        </View>
      </View>

      <View style={styles.panel}>
        <Text style={styles.eyebrow}>TOUTES LES PÉRIODES</Text>
        <ScoreGrid profile={profile} />
      </View>

      {profile.badges?.length ? (
        <View style={styles.panel}>
          <Text style={styles.eyebrow}>BADGES</Text>
          <View style={styles.badges}>
            {profile.badges.map((badge) => (
              <View key={badge} style={styles.badge}>
                <Text style={styles.badgeText}>{badge}</Text>
              </View>
            ))}
          </View>
        </View>
      ) : null}
    </ScrollView>
  );
}

const styles = StyleSheet.create({
  root: {
    flex: 1,
    backgroundColor: Pass50.bg,
  },
  content: {
    padding: 20,
    gap: 14,
    paddingBottom: 40,
  },
  center: {
    flex: 1,
    backgroundColor: Pass50.bg,
    alignItems: 'center',
    justifyContent: 'center',
    padding: 24,
    gap: 8,
  },
  hero: {
    flexDirection: 'row',
    gap: 14,
    alignItems: 'center',
  },
  avatar: {
    width: 88,
    height: 88,
    borderRadius: 24,
    overflow: 'hidden',
    backgroundColor: '#0b0e0b',
    borderWidth: 1,
    borderColor: 'rgba(183,255,0,.28)',
    alignItems: 'center',
    justifyContent: 'center',
  },
  photo: {
    width: '100%',
    height: '100%',
  },
  initials: {
    fontWeight: '900',
    fontSize: 24,
    color: '#d7dfd4',
  },
  heroCopy: {
    flex: 1,
    minWidth: 0,
    gap: 4,
  },
  name: {
    color: Pass50.text,
    fontSize: 24,
    fontWeight: '900',
  },
  handle: {
    color: Pass50.lime,
    fontSize: 14,
    fontWeight: '800',
  },
  meta: {
    color: Pass50.muted,
    fontSize: 12,
    lineHeight: 18,
  },
  panel: {
    borderWidth: 1,
    borderColor: Pass50.line,
    borderRadius: 18,
    backgroundColor: Pass50.panel,
    padding: 16,
    gap: 10,
  },
  eyebrow: {
    color: Pass50.lime,
    fontSize: 11,
    fontWeight: '900',
    letterSpacing: 0.8,
  },
  primaryScoreRow: {
    flexDirection: 'row',
    alignItems: 'flex-end',
    justifyContent: 'space-between',
  },
  primaryScore: {
    color: Pass50.text,
    fontSize: 42,
    fontWeight: '900',
    letterSpacing: -1,
  },
  primaryMeta: {
    alignItems: 'flex-end',
    gap: 4,
  },
  primaryRank: {
    color: Pass50.muted,
    fontWeight: '800',
    fontSize: 13,
  },
  delta: {
    fontWeight: '900',
    fontSize: 16,
  },
  scoreGrid: {
    flexDirection: 'row',
    flexWrap: 'wrap',
    gap: 8,
  },
  scoreCell: {
    width: '31%',
    minWidth: 96,
    flexGrow: 1,
    borderWidth: 1,
    borderColor: Pass50.line,
    borderRadius: 14,
    backgroundColor: '#090c09',
    padding: 10,
    gap: 4,
  },
  scorePeriod: {
    color: Pass50.muted,
    fontSize: 11,
    fontWeight: '900',
  },
  scoreValue: {
    color: Pass50.text,
    fontSize: 20,
    fontWeight: '900',
  },
  scoreRank: {
    color: Pass50.lime,
    fontSize: 11,
    fontWeight: '800',
  },
  badges: {
    flexDirection: 'row',
    flexWrap: 'wrap',
    gap: 8,
  },
  badge: {
    borderWidth: 1,
    borderColor: 'rgba(183,255,0,.24)',
    borderRadius: 999,
    paddingHorizontal: 12,
    paddingVertical: 6,
    backgroundColor: '#0a0e0a',
  },
  badgeText: {
    color: Pass50.text,
    fontSize: 12,
    fontWeight: '800',
  },
  errorTitle: {
    color: Pass50.text,
    fontSize: 18,
    fontWeight: '900',
  },
  errorMeta: {
    color: Pass50.muted,
    textAlign: 'center',
    lineHeight: 20,
  },
});
