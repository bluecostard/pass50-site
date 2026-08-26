export type RankingPeriod = '2H' | '24H' | '48H' | '7J' | '15J';

export type RankingRow = {
  id: string;
  rank: number;
  name: string;
  handle?: string;
  category?: string;
  score: number;
  delta?: number;
  photoUrl?: string;
  initials?: string;
};

export type PublicRanking = {
  ok?: boolean;
  contract?: string;
  periods?: Partial<Record<RankingPeriod, RankingRow[]>>;
  publishedAt?: string;
};

export type FeedItem = {
  title?: string;
  name?: string;
  profileName?: string;
  profileId?: string;
  platform?: string;
  url?: string;
  thumbnailUrl?: string;
  badge?: string;
};

export type PublicFeed = {
  ok?: boolean;
  trends?: FeedItem[];
  news?: FeedItem[];
};

export type LiveStream = {
  profileId: string;
  platform: string;
  title?: string;
  url?: string;
  viewers?: number;
  lastConfirmedAt?: string;
  profileName?: string;
};

export type LiveStatus = {
  ok?: boolean;
  liveStreams?: LiveStream[];
  radar?: Record<string, unknown>;
};

export type AppBootstrap = {
  ok?: boolean;
  authenticated?: boolean;
  user?: {
    id: string;
    email?: string;
    role?: string;
    name?: string;
  } | null;
  endpoints?: Record<string, string>;
};
