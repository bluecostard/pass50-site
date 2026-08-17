import textwrap
import unittest
from pathlib import Path

ROOT = Path(__file__).resolve().parents[1]
INDEX = (ROOT / "index.html").read_text(encoding="utf-8")
STATE = (ROOT / "api/state.php").read_text(encoding="utf-8")
APPLY = (ROOT / "api/metrics-ranking-publication-apply-core.php").read_text(encoding="utf-8")


class ScoredProfilesEnterRankingV1Tests(unittest.TestCase):
    def test_public_ranking_uses_period_score_not_census_flags(self):
        self.assertIn(
            "function isClassableProfile(p){return Boolean(p&&p.alive!==false)&&hasPeriodScore(p);}",
            INDEX,
        )
        self.assertIn("p50_de_restore_scored_classability($data)", STATE)
        self.assertIn("p50_de_restore_scored_classability($state)", APPLY)

    def test_kawaii_with_score_enters_ranking_despite_census_flags(self):
        script = textwrap.dedent(
            r"""
            const vm = require('vm');
            const fs = require('fs');
            const source = fs.readFileSync('index.html', 'utf8');
            const start = source.indexOf('function hasPeriodScore');
            const end = source.indexOf('function coulesCandidates');
            if (start < 0 || end < 0) throw new Error('ranking helpers missing');
            const context = {
              ui: { period: '24H' },
              db: {
                profiles: [
                  { id: 'kawaii-nanami', name: 'Kawaii Nanami', alive: true, eligible: false, classable: false, scores: { '24H': 71.5, '2H': 73.4 } },
                  { id: 'bebe-nicapol', name: 'Bébé Nicapol', alive: true, eligible: true, classable: false, scores: { '24H': 38.4 } },
                  { id: 'empty', name: 'Sans score', alive: true, eligible: true, classable: true, scores: {} }
                ]
              }
            };
            context.window = context;
            vm.createContext(context);
            vm.runInContext(source.slice(start, end) + '; function regionEligible(){return true} function score(p){return Number(p.scores[ui.period]||0)}', context);
            const ranked = context.ranking().map(p => p.id);
            if (!ranked.includes('kawaii-nanami')) throw new Error('Kawaii Nanami still excluded');
            if (!ranked.includes('bebe-nicapol')) throw new Error('Bébé Nicapol still excluded');
            if (ranked.includes('empty')) throw new Error('empty score entered ranking');
            const complete = context.completeRanking();
            if (complete.findIndex(p => p.id === 'kawaii-nanami') > 1) throw new Error('Kawaii remains in the recensement tail');
            """
        )
        import subprocess
        subprocess.run(["node", "-e", script], cwd=ROOT, check=True, capture_output=True, text=True)


if __name__ == "__main__":
    unittest.main()
