import type { PublicRanking, RankingPeriod, RankingRow } from '@/src/types';

export const RANKING_PERIODS: RankingPeriod[] = ['2H', '24H', '48H', '7J', '15J'];

export type InfluencerProfile = RankingRow & {
  ranksByPeriod: Partial<Record<RankingPeriod, number>>;
  scoresByPeriod: Partial<Record<RankingPeriod, number>>;
};

export function findInfluencerInRanking(
  data: PublicRanking | null,
  id: string,
): InfluencerProfile | null {
  if (!data?.periods || !id) return null;

  let base: RankingRow | null = null;
  const ranksByPeriod: Partial<Record<RankingPeriod, number>> = {};
  const scoresByPeriod: Partial<Record<RankingPeriod, number>> = {};

  for (const period of RANKING_PERIODS) {
    const row = (data.periods[period] ?? []).find((entry) => entry.id === id);
    if (!row) continue;
    ranksByPeriod[period] = row.rank;
    scoresByPeriod[period] = row.score;
    if (!base) base = row;
  }

  if (!base) return null;

  return {
    ...base,
    scores: base.scores ?? scoresByPeriod,
    ranksByPeriod,
    scoresByPeriod,
  };
}
