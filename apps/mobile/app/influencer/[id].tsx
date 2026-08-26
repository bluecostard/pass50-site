import { useLocalSearchParams } from 'expo-router';
import { StyleSheet, Text, View } from 'react-native';

import { Pass50 } from '@/constants/Colors';

export default function InfluencerScreen() {
  const { id } = useLocalSearchParams<{ id: string }>();

  return (
    <View style={styles.root}>
      <Text style={styles.title}>Fiche influenceur</Text>
      <Text style={styles.id}>{id}</Text>
      <Text style={styles.meta}>
        Écran natif à brancher sur la fiche FI / profil détaillé. Le classement ouvre déjà cette
        vue en modal.
      </Text>
    </View>
  );
}

const styles = StyleSheet.create({
  root: {
    flex: 1,
    backgroundColor: Pass50.bg,
    padding: 20,
    gap: 10,
  },
  title: {
    color: Pass50.text,
    fontSize: 24,
    fontWeight: '900',
  },
  id: {
    color: Pass50.lime,
    fontSize: 16,
    fontWeight: '800',
  },
  meta: {
    color: Pass50.muted,
    lineHeight: 20,
  },
});
