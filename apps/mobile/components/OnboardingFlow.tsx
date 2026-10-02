import { useRouter } from 'expo-router';
import { useCallback, useState } from 'react';
import {
  Pressable,
  StyleSheet,
  Text,
  View,
  useWindowDimensions,
} from 'react-native';
import { useSafeAreaInsets } from 'react-native-safe-area-context';

import { Pass50 } from '@/constants/Colors';
import { ONBOARDING_SLIDES, OnboardingSlide } from '@/src/onboarding/slides';
import { markOnboardingComplete } from '@/src/onboarding/storage';

const PODIUM = [
  { rank: '2', name: 'Costard', score: '88.4' },
  { rank: '1', name: 'Blue', score: '92.1', first: true },
  { rank: '3', name: 'Compagnie', score: '84.7' },
];

const FINAL_ITEMS = ['Observe.', 'Vote.', 'Pronostique.', 'Réagis.'];

function SlideVisual({ slide }: { slide: OnboardingSlide }) {
  if (slide.type === 'welcome') {
    return (
      <View style={styles.welcomeBlock}>
        <View style={styles.flag}>
          <View style={[styles.flagStripe, { backgroundColor: '#f77f00' }]} />
          <View style={[styles.flagStripe, { backgroundColor: '#fff' }]} />
          <View style={[styles.flagStripe, { backgroundColor: '#009e60' }]} />
        </View>
        <Text style={styles.welcomeBrand}>
          PASS<Text style={styles.lime}>50</Text>
        </Text>
      </View>
    );
  }
  if (slide.type === 'ranking') {
    return (
      <View style={styles.podium}>
        {PODIUM.map((item) => (
          <View key={item.name} style={[styles.podiumCard, item.first && styles.podiumFirst]}>
            <Text style={styles.podiumRank}>#{item.rank}</Text>
            <View style={[styles.podiumAvatar, item.first && styles.podiumAvatarFirst]}>
              <Text style={styles.podiumInitial}>{item.name.slice(0, 1)}</Text>
            </View>
            <Text style={styles.podiumName}>{item.name}</Text>
            <Text style={styles.podiumScore}>{item.score}</Text>
          </View>
        ))}
      </View>
    );
  }
  if (slide.type === 'bet' && slide.chips) {
    return (
      <View style={styles.chips}>
        {slide.chips.map((chip) => (
          <View key={chip.label} style={[styles.chip, CHIP_TONE_STYLES[chip.tone]]}>
            <Text style={styles.chipIcon}>{chip.icon}</Text>
            <View style={styles.chipCopy}>
              <Text style={styles.chipLabel}>{chip.label}</Text>
              <Text style={styles.chipDetail}>{chip.detail}</Text>
            </View>
          </View>
        ))}
      </View>
    );
  }
  if (slide.type === 'coules') {
    return (
      <View style={styles.coulesChart}>
        <Text style={styles.coulesLabel}>↘ DYNAMIQUE EN BAISSE</Text>
      </View>
    );
  }
  return (
    <View style={styles.finalList}>
      {FINAL_ITEMS.map((item) => (
        <View key={item} style={styles.finalRow}>
          <View style={styles.finalDot} />
          <Text style={styles.finalText}>{item}</Text>
        </View>
      ))}
    </View>
  );
}

export function OnboardingFlow() {
  const router = useRouter();
  const insets = useSafeAreaInsets();
  const { width } = useWindowDimensions();
  const [index, setIndex] = useState(0);
  const slide = ONBOARDING_SLIDES[index];

  const finish = useCallback(async () => {
    await markOnboardingComplete();
    router.replace('/(tabs)');
  }, [router]);

  const advance = useCallback(() => {
    if (index >= ONBOARDING_SLIDES.length - 1) {
      finish();
      return;
    }
    setIndex((value) => value + 1);
  }, [finish, index]);

  const retreat = useCallback(() => {
    setIndex((value) => Math.max(0, value - 1));
  }, []);

  return (
    <View style={[styles.root, { paddingTop: insets.top, paddingBottom: insets.bottom }]}>
      <View style={styles.progress}>
        {ONBOARDING_SLIDES.map((_, i) => (
          <View key={i} style={[styles.progressBar, i <= index && styles.progressBarActive]} />
        ))}
      </View>

      <View style={styles.top}>
        <View>
          <Text style={styles.stepTitle}>{slide.step}</Text>
          <Text style={styles.stepHint}>{slide.stepHint}</Text>
        </View>
        <Pressable onPress={finish} style={styles.skip}>
          <Text style={styles.skipText}>Passer</Text>
        </Pressable>
      </View>

      <View style={styles.content}>
        <Pressable style={[styles.hit, { width: width * 0.28 }]} onPress={retreat} />
        <View style={styles.contentInner}>
          <Text style={styles.eyebrow}>{slide.eyebrow}</Text>
          <Text style={styles.title}>{slide.title}</Text>
          <Text style={styles.body}>{slide.body}</Text>
          {slide.note ? <Text style={styles.note}>{slide.note}</Text> : null}
          <SlideVisual slide={slide} />
        </View>
        <Pressable style={[styles.hit, styles.hitRight, { width: width * 0.28 }]} onPress={advance} />
      </View>

      <View style={styles.footer}>
        <View style={styles.dots}>
          {ONBOARDING_SLIDES.map((_, i) => (
            <View key={i} style={[styles.dot, i === index && styles.dotActive]} />
          ))}
        </View>
        <Pressable style={styles.primary} onPress={advance}>
          <Text style={styles.primaryText}>{slide.primary}</Text>
        </Pressable>
      </View>
    </View>
  );
}

const styles = StyleSheet.create({
  root: {
    flex: 1,
    backgroundColor: Pass50.bg,
  },
  progress: {
    flexDirection: 'row',
    gap: 5,
    paddingHorizontal: 18,
    paddingTop: 8,
  },
  progressBar: {
    flex: 1,
    height: 3,
    borderRadius: 999,
    backgroundColor: '#2a3229',
  },
  progressBarActive: {
    backgroundColor: Pass50.lime,
  },
  top: {
    flexDirection: 'row',
    justifyContent: 'space-between',
    alignItems: 'flex-start',
    paddingHorizontal: 18,
    paddingTop: 12,
  },
  stepTitle: { color: Pass50.text, fontWeight: '900', fontSize: 13 },
  stepHint: { color: Pass50.muted, fontSize: 11, marginTop: 2 },
  skip: {
    borderWidth: 1,
    borderColor: 'rgba(183,255,0,.16)',
    borderRadius: 999,
    paddingHorizontal: 13,
    paddingVertical: 9,
  },
  skipText: { color: '#cbd3c8', fontWeight: '800', fontSize: 13 },
  content: {
    flex: 1,
    position: 'relative',
    justifyContent: 'center',
  },
  contentInner: {
    paddingHorizontal: 28,
    zIndex: 1,
  },
  hit: {
    position: 'absolute',
    top: 0,
    bottom: 0,
    left: 0,
    zIndex: 2,
  },
  hitRight: {
    left: undefined,
    right: 0,
  },
  eyebrow: {
    color: Pass50.lime,
    fontSize: 11,
    fontWeight: '900',
    letterSpacing: 1.2,
    marginBottom: 10,
  },
  title: {
    color: Pass50.text,
    fontSize: 30,
    fontWeight: '900',
    lineHeight: 34,
    letterSpacing: -0.5,
  },
  body: {
    color: '#b8c1b5',
    fontSize: 16,
    lineHeight: 24,
    marginTop: 16,
  },
  note: {
    color: '#dce5d9',
    fontWeight: '800',
    marginTop: 12,
    lineHeight: 20,
  },
  footer: {
    paddingHorizontal: 24,
    paddingBottom: 16,
    gap: 14,
  },
  dots: {
    flexDirection: 'row',
    justifyContent: 'center',
    gap: 7,
  },
  dot: {
    width: 22,
    height: 5,
    borderRadius: 999,
    backgroundColor: '#323a31',
  },
  dotActive: {
    backgroundColor: Pass50.lime,
  },
  primary: {
    backgroundColor: Pass50.lime,
    borderRadius: 999,
    paddingVertical: 15,
    alignItems: 'center',
  },
  primaryText: {
    color: Pass50.bg,
    fontWeight: '900',
    fontSize: 15,
  },
  welcomeBlock: { marginTop: 24, gap: 16 },
  flag: {
    flexDirection: 'row',
    width: 76,
    height: 48,
    borderRadius: 8,
    overflow: 'hidden',
  },
  flagStripe: { flex: 1 },
  welcomeBrand: {
    fontSize: 48,
    fontWeight: '900',
    color: Pass50.text,
    letterSpacing: -2,
  },
  lime: { color: Pass50.lime },
  podium: {
    flexDirection: 'row',
    alignItems: 'flex-end',
    gap: 8,
    marginTop: 22,
  },
  podiumCard: {
    flex: 1,
    borderWidth: 1,
    borderColor: '#334032',
    borderRadius: 16,
    padding: 10,
    alignItems: 'center',
    backgroundColor: '#080b08',
  },
  podiumFirst: {
    borderColor: Pass50.lime,
    paddingVertical: 14,
  },
  podiumRank: { color: '#98a395', fontSize: 12, fontWeight: '900' },
  podiumAvatar: {
    width: 46,
    height: 46,
    borderRadius: 23,
    marginVertical: 6,
    backgroundColor: '#1b241a',
    borderWidth: 1,
    borderColor: 'rgba(183,255,0,.28)',
    alignItems: 'center',
    justifyContent: 'center',
  },
  podiumAvatarFirst: { width: 56, height: 56, borderRadius: 28, borderColor: Pass50.lime },
  podiumInitial: { color: Pass50.text, fontWeight: '900', fontSize: 18 },
  podiumName: { fontSize: 11, fontWeight: '900', color: Pass50.text, textTransform: 'uppercase' },
  podiumScore: { color: Pass50.lime, fontWeight: '900', fontSize: 22, marginTop: 4 },
  chips: { gap: 9, marginTop: 18 },
  chip: {
    flexDirection: 'row',
    alignItems: 'center',
    gap: 12,
    padding: 13,
    borderRadius: 13,
    backgroundColor: '#0a0e0a',
    borderWidth: 1,
    borderColor: Pass50.lime,
  },
  chip_buzz: { borderColor: Pass50.lime },
  chip_up: { borderColor: '#1ee5ff' },
  chip_down: { borderColor: Pass50.red },
  chip_top: { borderColor: '#a66cff' },
  chipIcon: { fontSize: 18, width: 28, textAlign: 'center' },
  chipCopy: { flex: 1 },
  chipLabel: { color: Pass50.text, fontWeight: '900' },
  chipDetail: { color: Pass50.muted, fontSize: 12, marginTop: 2 },
  coulesChart: {
    height: 120,
    marginTop: 20,
    borderWidth: 1,
    borderColor: '#432222',
    borderRadius: 16,
    backgroundColor: 'rgba(255,75,75,.06)',
    justifyContent: 'flex-end',
    padding: 14,
  },
  coulesLabel: { color: '#ff6565', fontWeight: '900', fontSize: 11 },
  finalList: { gap: 12, marginTop: 22 },
  finalRow: { flexDirection: 'row', alignItems: 'center', gap: 12 },
  finalDot: {
    width: 42,
    height: 42,
    borderRadius: 21,
    borderWidth: 1,
    borderColor: Pass50.lime,
  },
  finalText: { color: Pass50.text, fontSize: 20, fontWeight: '900' },
});

const CHIP_TONE_STYLES = {
  buzz: styles.chip_buzz,
  up: styles.chip_up,
  down: styles.chip_down,
  top: styles.chip_top,
} as const;
