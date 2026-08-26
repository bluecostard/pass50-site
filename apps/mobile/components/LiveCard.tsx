import * as WebBrowser from 'expo-web-browser';
import { Pressable, StyleSheet, Text, View } from 'react-native';

import { Pass50 } from '@/constants/Colors';
import { LiveStream } from '@/src/types';

type Props = {
  live: LiveStream;
};

export function LiveCard({ live }: Props) {
  const name = live.profileName || live.profileId;
  const url = live.url || '';

  return (
    <Pressable
      style={styles.card}
      onPress={() => {
        if (url) WebBrowser.openBrowserAsync(url);
      }}>
      <View style={styles.dot} />
      <View style={styles.body}>
        <Text style={styles.name}>{name}</Text>
        <Text style={styles.platform}>EN DIRECT · {live.platform}</Text>
        <Text style={styles.title} numberOfLines={2}>
          {live.title || 'Direct en cours'}
        </Text>
      </View>
      <Text style={styles.cta}>→</Text>
    </Pressable>
  );
}

const styles = StyleSheet.create({
  card: {
    flexDirection: 'row',
    alignItems: 'center',
    gap: 12,
    borderWidth: 1,
    borderColor: Pass50.line,
    borderRadius: 18,
    backgroundColor: Pass50.panel,
    padding: 14,
  },
  dot: {
    width: 10,
    height: 10,
    borderRadius: 5,
    backgroundColor: Pass50.red,
  },
  body: {
    flex: 1,
    minWidth: 0,
  },
  name: {
    color: Pass50.text,
    fontWeight: '900',
    fontSize: 16,
  },
  platform: {
    color: Pass50.muted,
    fontSize: 11,
    fontWeight: '800',
    marginTop: 2,
  },
  title: {
    color: Pass50.muted,
    fontSize: 12,
    marginTop: 4,
  },
  cta: {
    color: Pass50.lime,
    fontWeight: '900',
    fontSize: 20,
  },
});
