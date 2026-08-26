import AsyncStorage from '@react-native-async-storage/async-storage';

import type { AppBootstrap, LiveStatus, PublicFeed, PublicRanking } from '@/src/types';

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
  login: (email: string, password: string) =>
    apiFetch<{ token?: string; user?: unknown }>('login.php', {
      method: 'POST',
      body: { email, password },
    }),
};
