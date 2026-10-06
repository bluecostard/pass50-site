import type { CoulesCandidateRaw } from '@/src/types';

export function coulesPollKey(candidates: CoulesCandidateRaw[]): string {
  return candidates
    .map((item) => item.profileId)
    .sort()
    .join('__') || 'aucun_duel';
}

export function coulesVoteShares(
  candidates: CoulesCandidateRaw[],
  totals: Record<string, number>,
): Record<string, number> {
  const total = candidates.reduce((sum, item) => sum + Number(totals[item.profileId] || 0), 0);
  const shares: Record<string, number> = {};
  candidates.forEach((item) => {
    const count = Number(totals[item.profileId] || 0);
    shares[item.profileId] = total ? Math.round((count / total) * 100) : 0;
  });
  return shares;
}
