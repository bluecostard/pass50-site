import AsyncStorage from '@react-native-async-storage/async-storage';

import type {
  AppBootstrap,
  CoulesHistory,
  CoulesPoll,
  LiveStatus,
  PronoFeed,
  PublicFeed,
  PublicRanking,
} from '@/src/types';

const TOKEN_KEY = 'pass50_api_token';
const API_BASE = (process.env.EXPO_PUBLIC_API_BASE ?? 'https://pass50.store/api/').replace(/\/?$/, '/');

export async function getToken(): Promise<string> {
  try {
    return (await AsyncStorage.getItem(TOKEN_KEY)) ?? '';
  } catch {
    return '';
  }
}

export async function setToken(token: string): Promise<void> {
  if (!token) {
    await AsyncStorage.removeItem(TOKEN_KEY);
    return;
  }
  await AsyncStorage.setItem(TOKEN_KEY, token);
}

export async function clearToken(): Promise<void> {
  await AsyncStorage.removeItem(TOKEN_KEY);
}

type ApiOptions = {
  method?: string;
  body?: unknown;
  auth?: boolean;
};

export async function apiFetch<T>(path: string, options: ApiOptions = {}): Promise<T> {
  const headers: Record<string, string> = { Accept: 'application/json' };
  if (options.body !== undefined) headers['Content-Type'] = 'application/json';
  if (options.auth !== false) {
    const token = await getToken();
    if (token) headers.Authorization = `Bearer ${token}`;
  }
  const response = await fetch(API_BASE + path.replace(/^\/?api\//, ''), {
    method: options.method ?? 'GET',
    headers,
    body: options.body !== undefined ? JSON.stringify(options.body) : undefined,
  });
  let data: unknown = null;
  try {
    data = await response.json();
  } catch {
    data = null;
  }
  if (!response.ok) {
    let message = `HTTP ${response.status}`;
    if (data && typeof data === 'object' && data !== null && 'error' in data) {
      message = String((data as { error: unknown }).error);
    }
    throw new Error(message);
  }
  return data as T;
}

export const pass50Api = {
  bootstrap: () => apiFetch<AppBootstrap>('app-bootstrap.php'),
  ranking: () => apiFetch<PublicRanking>('public-ranking.php'),
  feed: (period: string) =>
    apiFetch<PublicFeed>(`public-feed.php?period=${encodeURIComponent(period)}&newsLimit=18`),
  live: () => apiFetch<LiveStatus>('live-status.php?mode=status'),
  pronoFeed: () => apiFetch<PronoFeed>('prono-feed.php'),
  pronoVote: (questionId: string, optionKey: string) =>
    apiFetch<{ ok?: boolean; message?: string; balance?: PronoFeed['balance']; oddLocked?: number; potentialPayout?: number; stakeLocked?: number; totalVotes?: number; tallies?: Array<{ key: string; count: number; percent: number }> }>(
      'prono-vote.php',
      { method: 'POST', body: { questionId, optionKey } },
    ),
  coulesHistory: () => apiFetch<CoulesHistory>('coules-history.php', { auth: false }),
  coulesPoll: (pollKey: string) =>
    apiFetch<CoulesPoll>(`coules.php?poll=${encodeURIComponent(pollKey)}`),
  coulesVote: (pollKey: string, profileId: string) =>
    apiFetch<{ ok?: boolean }>('coules.php', {
      method: 'POST',
      body: { pollKey, profileId },
    }),
  login: (email: string, password: string) =>
    apiFetch<{ token?: string; user?: unknown }>('login.php', {
      method: 'POST',
      body: { email, password },
    }),
};
