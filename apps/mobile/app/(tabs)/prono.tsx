import { useRouter } from 'expo-router';
import { useCallback, useEffect, useMemo, useState } from 'react';
import { Alert, Pressable, ScrollView, StyleSheet, Text, View } from 'react-native';

import { CoulesDuelCard, CoulesDuelProfile } from '@/components/CoulesDuelCard';
import { PronoCard } from '@/components/PronoCard';
import { ScreenShell } from '@/components/ScreenShell';
import { Pass50 } from '@/constants/Colors';
import { pass50Api } from '@/src/api/client';
import { coulesPollKey, coulesVoteShares } from '@/src/coules/poll';
import { findInfluencerInRanking } from '@/src/ranking/lookup';
import type { CoulesCandidateRaw, PronoFeed, PronoQuestion, PublicRanking } from '@/src/types';

type Mode = 'prono' | 'coules';

function enrichCoulesCandidates(
  raw: CoulesCandidateRaw[],
  ranking: PublicRanking | null,
  totals: Record<string, number>,
): CoulesDuelProfile[] {
  const shares = coulesVoteShares(raw, totals);
  return raw.map((item) => {
    const profile = findInfluencerInRanking(ranking, item.profileId);
    return {
      id: item.profileId,
      name: profile?.name ?? item.profileId,
      initials: profile?.initials,
      photoUrl: profile?.photoUrl,
      decline: item.decline,
      currentRank: item.currentRank,
      share: shares[item.profileId],
      voteCount: totals[item.profileId] ?? 0,
    };
  });
}

export default function PronoScreen() {
  const router = useRouter();
  const [mode, setMode] = useState<Mode>('prono');
  const [loading, setLoading] = useState(true);
  const [voting, setVoting] = useState(false);
  const [error, setError] = useState('');
  const [themeFilter, setThemeFilter] = useState('all');

  const [prono, setProno] = useState<PronoFeed | null>(null);
  const [ranking, setRanking] = useState<PublicRanking | null>(null);
  const [coulesStatus, setCoulesStatus] = useState('');
  const [coulesRaw, setCoulesRaw] = useState<CoulesCandidateRaw[]>([]);
  const [coulesTotals, setCoulesTotals] = useState<Record<string, number>>({});
  const [coulesMyVote, setCoulesMyVote] = useState<string | null>(null);

  const pollKey = useMemo(() => coulesPollKey(coulesRaw), [coulesRaw]);
  const coulesCandidates = useMemo(
    () => enrichCoulesCandidates(coulesRaw, ranking, coulesTotals),
    [coulesRaw, ranking, coulesTotals],
  );

  const loadCoulesPoll = useCallback(async (key: string) => {
    if (!key || key === 'aucun_duel') return;
    const poll = await pass50Api.coulesPoll(key);
    setCoulesTotals(poll.totals ?? {});
    setCoulesMyVote(poll.myVote ?? null);
  }, []);

  const load = useCallback(async () => {
    setLoading(true);
    setError('');
    try {
      const [feed, rank, history] = await Promise.all([
        pass50Api.pronoFeed(),
        pass50Api.ranking(),
        pass50Api.coulesHistory(),
      ]);
      setProno(feed);
      setRanking(rank);
      setCoulesStatus(history.status ?? '');
      setCoulesRaw(history.candidates ?? []);
      if ((history.candidates ?? []).length >= 2) {
        const key = coulesPollKey(history.candidates ?? []);
        await loadCoulesPoll(key);
      } else {
        setCoulesTotals({});
        setCoulesMyVote(null);
      }
    } catch (err) {
      setError(err instanceof Error ? err.message : 'Paris indisponibles');
    } finally {
      setLoading(false);
    }
  }, [loadCoulesPoll]);

  useEffect(() => {
    load();
  }, [load]);

  const themes = prono?.themes ?? [];
  const items = (prono?.items ?? []).filter(
    (item) => themeFilter === 'all' || item.theme === themeFilter,
  );
  const balance = prono?.balance?.balance ?? 0;
  const authenticated = Boolean(prono?.auth);

  function requireAuth(action: string): boolean {
    if (authenticated) return true;
    Alert.alert('Connexion requise', `Connecte-toi dans Compte pour ${action}.`, [
      { text: 'Annuler', style: 'cancel' },
      { text: 'Compte', onPress: () => router.push('/(tabs)/profile') },
    ]);
    return false;
  }

  async function onPronoVote(questionId: string, optionKey: string) {
    if (!requireAuth('parier')) return;
    setVoting(true);
    try {
      const result = await pass50Api.pronoVote(questionId, optionKey);
      setProno((current) => {
        if (!current) return current;
        const nextItems = (current.items ?? []).map((item) => {
          if (item.id !== questionId) return item;
          const option = item.options.find((opt) => opt.key === optionKey);
          return {
            ...item,
            myVote: {
              optionKey,
              oddLocked: result.oddLocked ?? option?.odd ?? 0,
              stakeLocked: result.stakeLocked,
              potentialPayout: result.potentialPayout,
            },
            totalVotes: result.totalVotes ?? item.totalVotes,
            options: item.options.map((opt) => {
              const tally = result.tallies?.find((row) => row.key === opt.key);
              if (!tally) return opt;
              return { ...opt, voteCount: tally.count, votePercent: tally.percent };
            }),
          } satisfies PronoQuestion;
        });
        return {
          ...current,
          balance: result.balance ?? current.balance,
          items: nextItems,
        };
      });
      Alert.alert('Prono enregistré', result.message ?? 'Sans argent réel.');
    } catch (err) {
      Alert.alert('Erreur', err instanceof Error ? err.message : 'Vote impossible');
    } finally {
      setVoting(false);
    }
  }

  async function onCoulesVote(profileId: string) {
    if (!requireAuth('voter aux Coulés')) return;
    if (coulesMyVote === profileId) return;
    setVoting(true);
    try {
      await pass50Api.coulesVote(pollKey, profileId);
      await loadCoulesPoll(pollKey);
      Alert.alert('Vote enregistré', coulesMyVote ? 'Vote modifié.' : 'Merci pour ton vote !');
    } catch (err) {
      Alert.alert('Erreur', err instanceof Error ? err.message : 'Vote impossible');
    } finally {
      setVoting(false);
    }
  }

  return (
    <ScreenShell
      title={mode === 'prono' ? 'Parie sur l’actualité' : 'Les Coulés'}
      subtitle={
        mode === 'prono'
          ? `${items.length} pronos ouverts · ${Math.round(balance)} pts`
          : 'Qui mousse moins ? Ça va se savoir 🌊'
      }
      refreshing={loading}
      onRefresh={load}>
      <View style={styles.modeTabs}>
        <Pressable
          style={[styles.modeTab, mode === 'prono' && styles.modeTabActive]}
          onPress={() => setMode('prono')}>
          <Text style={[styles.modeTabText, mode === 'prono' && styles.modeTabTextActive]}>
            Pronostics
          </Text>
        </Pressable>
        <Pressable
          style={[styles.modeTab, mode === 'coules' && styles.modeTabActive]}
          onPress={() => setMode('coules')}>
          <Text style={[styles.modeTabText, mode === 'coules' && styles.modeTabTextActive]}>
            Coulés
          </Text>
        </Pressable>
      </View>

      {error && !prono ? (
        <View style={styles.panel}>
          <Text style={styles.error}>{error}</Text>
        </View>
      ) : null}

      {mode === 'prono' ? (
        <>
          <Text style={styles.disclaimer}>
            {prono?.disclaimer ?? 'Sans argent réel — cotes en points PASS50.'}
          </Text>
          {themes.length ? (
            <ScrollView horizontal showsHorizontalScrollIndicator={false} contentContainerStyle={styles.chips}>
              <Pressable
                style={[styles.chip, themeFilter === 'all' && styles.chipActive]}
                onPress={() => setThemeFilter('all')}>
                <Text style={[styles.chipText, themeFilter === 'all' && styles.chipTextActive]}>Tous</Text>
              </Pressable>
              {themes.map((theme) => (
                <Pressable
                  key={theme.key}
                  style={[styles.chip, themeFilter === theme.key && styles.chipActive]}
                  onPress={() => setThemeFilter(theme.key)}>
                  <Text style={[styles.chipText, themeFilter === theme.key && styles.chipTextActive]}>
                    {theme.label}
                  </Text>
                </Pressable>
              ))}
            </ScrollView>
          ) : null}
          {items.length ? (
            items.map((item) => (
              <PronoCard
                key={item.id}
                item={item}
                onVote={onPronoVote}
                voting={voting}
                disabled={!authenticated}
              />
            ))
          ) : (
            <View style={styles.panel}>
              <Text style={styles.empty}>
                {loading ? 'Chargement…' : 'Aucun prono ouvert pour le moment.'}
              </Text>
            </View>
          )}
        </>
      ) : (
        <>
          {coulesCandidates.length >= 2 ? (
            <CoulesDuelCard
              candidates={coulesCandidates}
              myVote={coulesMyVote}
              onVote={onCoulesVote}
              voting={voting}
              disabled={!authenticated}
            />
          ) : (
            <View style={styles.panel}>
              <Text style={styles.empty}>
                {loading
                  ? 'Chargement…'
                  : coulesStatus === 'insufficient_history'
                    ? 'Historique insuffisant — le duel arrive bientôt.'
                    : 'Aucun duel coulé confirmé pour le moment.'}
              </Text>
            </View>
          )}
          {!authenticated ? (
            <Text style={styles.loginHint}>Connecte-toi pour voter aux Coulés.</Text>
          ) : null}
        </>
      )}
    </ScreenShell>
  );
}

const styles = StyleSheet.create({
  modeTabs: {
    flexDirection: 'row',
    gap: 6,
    padding: 5,
    borderWidth: 1,
    borderColor: 'rgba(183,255,0,.18)',
    borderRadius: 16,
    backgroundColor: '#0b0f0b',
  },
  modeTab: {
    flex: 1,
    borderRadius: 12,
    paddingVertical: 11,
    alignItems: 'center',
  },
  modeTabActive: {
    backgroundColor: Pass50.lime,
  },
  modeTabText: {
    color: '#aab3a7',
    fontWeight: '900',
    fontSize: 13,
  },
  modeTabTextActive: {
    color: Pass50.bg,
  },
  disclaimer: {
    color: Pass50.muted,
    fontSize: 11,
    lineHeight: 16,
  },
  chips: { gap: 8, paddingVertical: 2 },
  chip: {
    borderWidth: 1,
    borderColor: Pass50.line,
    borderRadius: 999,
    paddingHorizontal: 13,
    paddingVertical: 9,
    backgroundColor: '#0a0d0a',
  },
  chipActive: { backgroundColor: Pass50.lime, borderColor: Pass50.lime },
  chipText: { color: Pass50.text, fontWeight: '900', fontSize: 12 },
  chipTextActive: { color: Pass50.bg },
  panel: {
    borderWidth: 1,
    borderColor: Pass50.line,
    borderRadius: 18,
    backgroundColor: Pass50.panel,
    padding: 16,
  },
  empty: { color: Pass50.muted, lineHeight: 20 },
  error: { color: Pass50.danger },
  loginHint: {
    color: Pass50.muted,
    fontSize: 12,
    textAlign: 'center',
    fontWeight: '800',
  },
});
