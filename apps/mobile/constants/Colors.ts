/** PASS50 mobile — identité sombre + lime */
export const Pass50 = {
  bg: '#050705',
  panel: '#0d110d',
  line: '#293129',
  text: '#f6f8f4',
  muted: '#9da79b',
  lime: '#b7ff00',
  red: '#ff4b4b',
  danger: '#ffb4b4',
} as const;

export default {
  light: {
    text: Pass50.text,
    background: Pass50.bg,
    tint: Pass50.lime,
    tabIconDefault: Pass50.muted,
    tabIconSelected: Pass50.lime,
  },
  dark: {
    text: Pass50.text,
    background: Pass50.bg,
    tint: Pass50.lime,
    tabIconDefault: Pass50.muted,
    tabIconSelected: Pass50.lime,
  },
};
