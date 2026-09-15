import * as WebBrowser from 'expo-web-browser';
import { Image, Pressable, StyleSheet, Text, View } from 'react-native';

import { Pass50 } from '@/constants/Colors';
import { FeedItem } from '@/src/types';

type Props = {
  item: FeedItem;
};

export function FeedCard({ item }: Props) {
  const title = item.title || 'Publication';
  const name = item.name || item.profileName || item.profileId || '';
  const url = item.url || '';

  return (
    <View style={styles.card}>
      {item.thumbnailUrl ? (
        <Image source={{ uri: item.thumbnailUrl }} style={styles.thumb} />
      ) : null}
      <View style={styles.body}>
        <Text style={styles.kicker}>
          {item.platform || 'PASS50'} · {name}
          {item.badge ? ` · ${item.badge}` : ''}
        </Text>
        <Text style={styles.title}>{title}</Text>
        {url ? (
          <Pressable style={styles.btn} onPress={() => WebBrowser.openBrowserAsync(url)}>
            <Text style={styles.btnText}>Ouvrir</Text>
          </Pressable>
        ) : null}
      </View>
    </View>
  );
}

const styles = StyleSheet.create({
  card: {
    borderWidth: 1,
    borderColor: Pass50.line,
    borderRadius: 18,
    backgroundColor: Pass50.panel,
    overflow: 'hidden',
  },
  thumb: {
    width: '100%',
    height: 180,
  },
  body: {
    padding: 14,
    gap: 8,
  },
  kicker: {
    color: Pass50.muted,
    fontSize: 11,
    fontWeight: '800',
  },
  title: {
    color: Pass50.text,
    fontSize: 17,
    fontWeight: '800',
    lineHeight: 22,
  },
  btn: {
    alignSelf: 'flex-start',
    borderWidth: 1,
    borderColor: Pass50.line,
    borderRadius: 12,
    paddingHorizontal: 12,
    paddingVertical: 8,
  },
  btnText: {
    color: Pass50.text,
    fontWeight: '900',
    fontSize: 12,
  },
});
