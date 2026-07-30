from pathlib import Path
import unittest


ROOT = Path(__file__).resolve().parents[1]
READINESS = (ROOT / 'api' / 'metrics-ranking-readiness-core.php').read_text(encoding='utf-8')
CRON = (ROOT / 'api' / 'metrics-ranking-cron.php').read_text(encoding='utf-8')
DIAGNOSTIC = (ROOT / 'api' / 'metrics-diagnostic.php').read_text(encoding='utf-8')
WORKFLOW = (ROOT / '.github' / 'workflows' / 'metrics-ranking-experimental.yml').read_text(encoding='utf-8')


class MetricsRankingReadinessV1Tests(unittest.TestCase):
    def test_gate_covers_collection_and_data_states(self):
        for reason in (
            'schema_missing', 'p1_not_observed', 'p1_stale', 'collection_pending',
            'no_usable_captures', 'no_new_captures', 'ready_with_partial_failures',
        ):
            self.assertIn(reason, READINESS)
        self.assertIn("priority=50", READINESS)
        self.assertIn("quality_status='usable'", READINESS)
        self.assertIn('latestRankingFinishedAt', READINESS)

    def test_cron_checks_readiness_before_calculation(self):
        self.assertIn("metrics-ranking-readiness-core.php", CRON)
        readiness_position = CRON.index('p50_mrr_readiness')
        calculate_position = CRON.index('p50_mr_calculate_if_due')
        self.assertLess(readiness_position, calculate_position)
        self.assertIn("if(empty($readiness['ready']))", CRON)
        self.assertIn("'readiness'=>$readiness", CRON)

    def test_diagnostic_exposes_read_only_status(self):
        self.assertIn("['rankingReadiness']", DIAGNOSTIC)
        self.assertIn('p50_mrr_readiness', DIAGNOSTIC)
        combined = READINESS + DIAGNOSTIC
        for forbidden in ('UPDATE app_state', 'INSERT INTO app_state', 'data-publish.php', 'publicPublication\'=>true'):
            self.assertNotIn(forbidden, combined)

    def test_workflow_reports_technical_skip_reasons(self):
        for token in ('readiness.state', 'readiness.p1.activeJobs', 'readiness.p1.failedJobs', 'no_new_captures', 'collection_pending'):
            self.assertIn(token, WORKFLOW)
        self.assertIn("cron: '57 */2 * * *'", WORKFLOW)
        self.assertNotIn('publish', WORKFLOW.lower())


if __name__ == '__main__':
    unittest.main()
