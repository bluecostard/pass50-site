import { useCallback, useEffect, useState } from 'react';
import { Pressable, StyleSheet, Text, TextInput, View } from 'react-native';

import { ScreenShell } from '@/components/ScreenShell';
import { Pass50 } from '@/constants/Colors';
import { clearToken, pass50Api, setToken } from '@/src/api/client';
import { AppBootstrap } from '@/src/types';

export default function ProfileScreen() {
  const [bootstrap, setBootstrap] = useState<AppBootstrap | null>(null);
  const [email, setEmail] = useState('');
  const [password, setPassword] = useState('');
  const [loading, setLoading] = useState(true);
  const [authLoading, setAuthLoading] = useState(false);
  const [error, setError] = useState('');

  const load = useCallback(async () => {
    setLoading(true);
    try {
      setBootstrap(await pass50Api.bootstrap());
    } catch (err) {
      setError(err instanceof Error ? err.message : 'Session indisponible');
    } finally {
      setLoading(false);
    }
  }, []);

  useEffect(() => {
    load();
  }, [load]);

  async function onLogin() {
    setAuthLoading(true);
    setError('');
    try {
      const result = await pass50Api.login(email.trim(), password);
      if (!result.token) throw new Error('Connexion refusée');
      await setToken(result.token);
      await load();
    } catch (err) {
      setError(err instanceof Error ? err.message : 'Connexion impossible');
    } finally {
      setAuthLoading(false);
    }
  }

  async function onLogout() {
    await clearToken();
    setPassword('');
    await load();
  }

  const user = bootstrap?.user;

  return (
    <ScreenShell title="Compte" subtitle="Membre PASS50" refreshing={loading} onRefresh={load}>
      <Text style={styles.eyebrow}>PROFIL</Text>
      <View style={styles.panel}>
        {user ? (
          <>
            <Text style={styles.label}>Connecté</Text>
            <Text style={styles.value}>{user.email || user.name || user.id}</Text>
            <Text style={styles.meta}>Rôle · {user.role || 'member'}</Text>
            <Pressable style={styles.btnDanger} onPress={onLogout}>
              <Text style={styles.btnDangerText}>Se déconnecter</Text>
            </Pressable>
          </>
        ) : (
          <>
            <Text style={styles.label}>Connexion</Text>
            <TextInput
              style={styles.input}
              placeholder="E-mail"
              placeholderTextColor={Pass50.muted}
              autoCapitalize="none"
              keyboardType="email-address"
              value={email}
              onChangeText={setEmail}
            />
            <TextInput
              style={styles.input}
              placeholder="Mot de passe"
              placeholderTextColor={Pass50.muted}
              secureTextEntry
              value={password}
              onChangeText={setPassword}
            />
            <Pressable style={styles.btn} onPress={onLogin} disabled={authLoading}>
              <Text style={styles.btnText}>{authLoading ? 'Connexion…' : 'Se connecter'}</Text>
            </Pressable>
          </>
        )}
        {error ? <Text style={styles.error}>{error}</Text> : null}
      </View>
      <View style={styles.panel}>
        <Text style={styles.label}>Client natif</Text>
        <Text style={styles.meta}>
          PASS50 Mobile · Expo · API pass50.store · mode=status pour les lives
        </Text>
      </View>
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
    gap: 10,
  },
  label: {
    color: Pass50.muted,
    fontSize: 12,
    fontWeight: '800',
  },
  value: {
    color: Pass50.text,
    fontSize: 18,
    fontWeight: '900',
  },
  meta: {
    color: Pass50.muted,
    fontSize: 12,
    lineHeight: 18,
  },
  input: {
    borderWidth: 1,
    borderColor: Pass50.line,
    borderRadius: 12,
    backgroundColor: '#090c09',
    color: Pass50.text,
    paddingHorizontal: 13,
    paddingVertical: 12,
    fontSize: 15,
  },
  btn: {
    backgroundColor: Pass50.lime,
    borderRadius: 12,
    paddingVertical: 12,
    alignItems: 'center',
  },
  btnText: {
    color: Pass50.bg,
    fontWeight: '900',
  },
  btnDanger: {
    borderWidth: 1,
    borderColor: '#6f2b2b',
    borderRadius: 12,
    paddingVertical: 12,
    alignItems: 'center',
  },
  btnDangerText: {
    color: Pass50.danger,
    fontWeight: '900',
  },
  error: {
    color: Pass50.danger,
    fontSize: 12,
  },
});
