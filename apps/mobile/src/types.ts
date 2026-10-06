export type RankingPeriod = '2H' | '24H' | '48H' | '7J' | '15J';

export type RankingRow = {
  id: string;
  rank: number;
  name: string;
  handle?: string;
  category?: string;
  region?: string;
  score: number;
  scores?: Partial<Record<RankingPeriod, number>>;
  delta?: number;
  photoUrl?: string;
  initials?: string;
  badges?: string[];
  photoStatus?: string;
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

export type PronoOption = {
  key: string;
  label: string;
  odd: number;
  payout?: number;
  voteCount?: number;
  votePercent?: number;
};

export type PronoMyVote = {
  optionKey: string;
  oddLocked: number;
  stakeLocked?: number;
  potentialPayout?: number;
};

export type PronoQuestion = {
  id: string;
  title: string;
  context?: string;
  coverPhoto?: string;
  theme?: string;
  themeLabel?: string;
  options: PronoOption[];
  stake?: number;
  totalVotes?: number;
  closesAt?: string;
  measureAt?: string | null;
  myVote?: PronoMyVote | null;
  statusPublished?: boolean;
};

export type PronoFeed = {
  ok?: boolean;
  auth?: boolean;
  disclaimer?: string;
  balance?: { balance: number; streak: number; floor?: number };
  themes?: Array<{ key: string; label: string; hint?: string }>;
  items?: PronoQuestion[];
};

export type CoulesCandidateRaw = {
  profileId: string;
  decline: number;
  currentAverage: number;
  previousAverage: number;
  previousPeak: number;
  currentRank: number;
};

export type CoulesHistory = {
  ok?: boolean;
  status?: 'ready' | 'insufficient_history' | 'no_confirmed_decline' | string;
  candidates?: CoulesCandidateRaw[];
  daysCollected?: number;
  requiredDays?: number;
};

export type CoulesPoll = {
  ok?: boolean;
  totals?: Record<string, number>;
  myVote?: string | null;
};
