import { Ionicons } from '@expo/vector-icons';
import * as WebBrowser from 'expo-web-browser';
import { Image, Pressable, Share, StyleSheet, Text, View } from 'react-native';

import { Pass50 } from '@/constants/Colors';
import { FeedItem } from '@/src/types';

type Props = {
  item: FeedItem;
  index: number;
};

/** Carte contenu tendance — style site / TOP 5 CONTENUS TENDANCE. */
export function TrendContentCard({ item, index }: Props) {
  const title = item.title || 'Publication';
  const name = item.name || item.profileName || item.profileId || '';
  const platform = item.platform || 'PASS50';
  const url = item.url || '';
  const badge = (item.badge || '').toUpperCase();
  const showHot = badge === 'HOT' || index === 0;

  const open = () => {
    if (url) void WebBrowser.openBrowserAsync(url);
  };

  const onShare = async () => {
    try {
      await Share.share({
        message: `${title} — ${name} · ${platform}${url ? `\n${url}` : ''}`,
        url: url || undefined,
      });
    } catch {
      // ignore cancel
    }
  };

  return (
    <Pressable style={styles.card} onPress={open}>
      <View style={styles.media}>
        {item.thumbnailUrl ? (
          <Image source={{ uri: item.thumbnailUrl }} style={styles.thumb} />
        ) : (
          <View style={[styles.thumb, styles.thumbFallback]} />
        )}
        <View style={styles.rankBadge}>
          <Text style={styles.rankText}>{index + 1}</Text>
        </View>
        {showHot ? (
          <View style={styles.hot}>
            <Text style={styles.hotText}>HOT</Text>
          </View>
        ) : null}
        <Pressable
          style={styles.shareFab}
          onPress={onShare}
          hitSlop={8}
          accessibilityRole="button"
          accessibilityLabel="Partager">
          <Ionicons name="share-outline" size={18} color="#050705" />
        </Pressable>
      </View>
      <View style={styles.body}>
        <Text style={styles.meta} numberOfLines={1}>
          {name}
          {name ? ' · ' : ''}
          {platform}
        </Text>
        <Text style={styles.title} numberOfLines={2}>
          {title}
        </Text>
      </View>
    </Pressable>
  );
}

const styles = StyleSheet.create({
  card: {
    borderRadius: 18,
    borderWidth: 1,
    borderColor: Pass50.line,
    backgroundColor: Pass50.panel,
    overflow: 'hidden',
    marginBottom: 12,
  },
  media: {
    position: 'relative',
  },
  thumb: {
    width: '100%',
    height: 210,
    backgroundColor: '#0c0f0c',
  },
  thumbFallback: {
    backgroundColor: '#121612',
  },
  rankBadge: {
    position: 'absolute',
    top: 12,
    left: 12,
    width: 36,
    height: 36,
    borderRadius: 18,
    backgroundColor: Pass50.lime,
    alignItems: 'center',
    justifyContent: 'center',
  },
  rankText: {
    color: '#050705',
    fontWeight: '900',
    fontSize: 16,
  },
  hot: {
    position: 'absolute',
    top: 12,
    right: 12,
    paddingHorizontal: 10,
    paddingVertical: 5,
    borderRadius: 999,
    backgroundColor: 'rgba(8,10,8,.88)',
    borderWidth: 1,
    borderColor: 'rgba(255,157,29,.55)',
  },
  hotText: {
    color: Pass50.orange,
    fontWeight: '900',
    fontSize: 11,
    letterSpacing: 0.6,
  },
  shareFab: {
    position: 'absolute',
    right: 14,
    bottom: -18,
    width: 44,
    height: 44,
    borderRadius: 22,
    backgroundColor: Pass50.lime,
    alignItems: 'center',
    justifyContent: 'center',
    zIndex: 3,
  },
  body: {
    paddingHorizontal: 14,
    paddingTop: 22,
    paddingBottom: 14,
    gap: 4,
  },
  meta: {
    color: Pass50.lime,
    fontWeight: '800',
    fontSize: 13,
  },
  title: {
    color: Pass50.text,
    fontWeight: '900',
    fontSize: 18,
    lineHeight: 22,
  },
});
