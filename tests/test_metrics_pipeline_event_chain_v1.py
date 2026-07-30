from pathlib import Path
import unittest

ROOT = Path(__file__).resolve().parents[1]
P1 = (ROOT / '.github' / 'workflows' / 'metrics-top50-2h.yml').read_text(encoding='utf-8')
RANKING = (ROOT / '.github' / 'workflows' / 'metrics-ranking-experimental.yml').read_text(encoding='utf-8')
SIMULATION = (ROOT / '.github' / 'workflows' / 'metrics-ranking-publication-simulation.yml').read_text(encoding='utf-8')


class MetricsPipelineEventChainV1Tests(unittest.TestCase):
    def test_p1_runs_immediately_after_its_main_change_and_keeps_schedule(self):
        self.assertIn("cron: '7 */2 * * *'", P1)
        self.assertIn('workflow_dispatch:', P1)
        self.assertIn("- '.github/workflows/metrics-top50-2h.yml'", P1)
        self.assertIn('actions: write', P1)
        self.assertIn('statuses: write', P1)

    def test_p1_dispatches_ranking_only_after_an_empty_queue(self):
        self.assertIn("steps.collection.outputs.remaining == '0'", P1)
        self.assertIn('/actions/workflows/metrics-ranking-experimental.yml/dispatches', P1)
        self.assertIn("--arg ref \"main\"", P1)
        self.assertIn('pass50/metrics-p1', P1)
        self.assertIn('app_state 0', P1)

    def test_ranking_keeps_schedule_and_dispatches_simulation(self):
        self.assertIn("cron: '57 */2 * * *'", RANKING)
        self.assertIn('workflow_dispatch:', RANKING)
        self.assertIn('actions: write', RANKING)
        self.assertIn('statuses: write', RANKING)
        self.assertIn("steps.ranking.outputs.reason != ''", RANKING)
        self.assertIn('/actions/workflows/metrics-ranking-publication-simulation.yml/dispatches', RANKING)
        self.assertIn('pass50/experimental-ranking', RANKING)
        self.assertIn('ranking-experimental-result.json', RANKING)

    def test_simulation_remains_read_only_and_dispatchable(self):
        self.assertIn('workflow_dispatch:', SIMULATION)
        self.assertIn('.publication.publicationEnabled == false', SIMULATION)
        self.assertIn('.publication.automaticPublicationEnabled == false', SIMULATION)
        self.assertIn('.scope.publicStateWrites == 0', SIMULATION)
        self.assertIn('pass50/publication-simulation', SIMULATION)

    def test_chain_never_contains_a_public_write_path(self):
        combined = P1 + RANKING + SIMULATION
        for forbidden in (
            'INSERT INTO app_state',
            'UPDATE app_state',
            'DELETE FROM app_state',
            'REPLACE INTO app_state',
            'data-publish.php',
            'p50_de_publish_score_pipeline',
        ):
            self.assertNotIn(forbidden, combined)


if __name__ == '__main__':
    unittest.main()
