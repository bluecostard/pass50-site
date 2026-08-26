import { useCallback, useEffect, useState } from 'react';
import { StyleSheet, Text, View } from 'react-native';

import { LiveCard } from '@/components/LiveCard';
import { ScreenShell } from '@/components/ScreenShell';
import { Pass50 } from '@/constants/Colors';
import { pass50Api } from '@/src/api/client';
import { LiveStatus } from '@/src/types';

export default function LiveScreen() {
  const [data, setData] = useState<LiveStatus | null>(null);
  const [loading, setLoading] = useState(true);
  const [error, setError] = useState('');

  const load = useCallback(async () => {
    setLoading(true);
    setError('');
    try {
      setData(await pass50Api.live());
    } catch (err) {
      setError(err instanceof Error ? err.message : 'Live indisponible');
    } finally {
      setLoading(false);
    }
  }, []);

  useEffect(() => {
    load();
    const timer = setInterval(load, 20000);
    return () => clearInterval(timer);
  }, [load]);

  const lives = (data?.liveStreams ?? []).filter((item) => item?.url);

  return (
    <ScreenShell
      title="Directs"
      subtitle="Mis à jour toutes les 20 s · lecture seule"
      refreshing={loading}
      onRefresh={load}>
      <Text style={styles.eyebrow}>RADAR LIVE</Text>
      {error && !data ? (
        <View style={styles.panel}>
          <Text style={styles.error}>{error}</Text>
        </View>
      ) : null}
      {lives.length ? (
        lives.map((live, index) => (
          <LiveCard key={`${live.profileId}-${live.platform}-${index}`} live={live} />
        ))
      ) : (
        <View style={styles.panel}>
          <Text style={styles.empty}>
            {loading ? 'Chargement…' : 'Aucun direct détecté pour le moment.'}
          </Text>
        </View>
      )}
    </ScreenShell>
  );
}

const styles = StyleSheet.create({
  eyebrow: {
    color: Pass50.lime,
    fontSize: 11,
    fontWeight: '900',
    letterSpacing: 0.8,
  },
  panel: {
    borderWidth: 1,
    borderColor: Pass50.line,
    borderRadius: 18,
    backgroundColor: Pass50.panel,
    padding: 16,
  },
  empty: { color: Pass50.muted, lineHeight: 20 },
  error: { color: Pass50.danger },
});
