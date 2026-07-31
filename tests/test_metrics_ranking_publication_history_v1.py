from pathlib import Path
import unittest

ROOT = Path(__file__).resolve().parents[1]
CORE = (ROOT / 'api' / 'metrics-ranking-publication-history-core.php').read_text(encoding='utf-8')
CRON = (ROOT / 'api' / 'metrics-ranking-publication-simulate-cron.php').read_text(encoding='utf-8')
ADMIN = (ROOT / 'api' / 'metrics-ranking-publication-history.php').read_text(encoding='utf-8')
WORKFLOW = (ROOT / '.github' / 'workflows' / 'metrics-ranking-publication-simulation.yml').read_text(encoding='utf-8')


class MetricsRankingPublicationHistoryV1Tests(unittest.TestCase):
    def test_history_schema_is_separate_and_idempotent(self):
        self.assertIn('p50_metric_publication_simulations', CORE)
        self.assertIn('UNIQUE KEY uq_p50_mrph_dispatch_id(dispatch_id)', CORE)
        self.assertIn('ON DUPLICATE KEY UPDATE id=LAST_INSERT_ID(id)', CORE)
        self.assertIn('public_state_writes SMALLINT UNSIGNED NOT NULL DEFAULT 0', CORE)

    def test_history_rejects_any_public_write(self):
        self.assertIn("($publication['mode']??null)!=='simulation'", CORE)
        self.assertIn("$scope['publicStateWrites']??-1", CORE)
        self.assertIn("(int)$stored['public_state_writes']!==0", CORE)
        for token in ('INSERT INTO app_state', 'UPDATE app_state', 'DELETE FROM app_state', 'REPLACE INTO app_state'):
            self.assertNotIn(token, CORE)
            self.assertNotIn(token, CRON)
            self.assertNotIn(token, ADMIN)

    def test_stability_requires_three_distinct_runs(self):
        self.assertIn('P50_MRPH_MIN_DISTINCT_CYCLES=3', CORE)
        self.assertIn('function p50_mrph_distinct_recent', CORE)
        self.assertIn("'run:'.$runUuid", CORE)
        self.assertIn("p50_mrph_distinct_recent($history,$sampleSize)", CORE)
        self.assertIn("'rawObservedReports'=>count($history)", CORE)
        self.assertIn("'distinct_experimental_runs'", CORE)
        self.assertIn("'state'=>$state", CORE)
        self.assertIn("'controlledPublicationEligible'=>$state==='ready'", CORE)
        self.assertIn("'automaticPublicationEligible'=>false", CORE)

    def test_empty_history_is_collecting_not_failed(self):
        self.assertIn("$freshStatus=$latestAgeHours===null?'wait'", CORE)
        self.assertIn("$state=$blocked?'blocked':($waiting?'collecting'", CORE)

    def test_cron_stores_then_evaluates_selected_period(self):
        self.assertIn("require __DIR__.'/metrics-ranking-publication-history-core.php'", CRON)
        self.assertIn("p50_mrph_store($pdo,$result,$dispatchId)", CRON)
        self.assertIn("$selectedPeriod=(string)($result['selectedPeriod']??'2H')", CRON)
        self.assertIn("p50_mrph_stability($pdo,$selectedPeriod,P50_MRPH_MIN_DISTINCT_CYCLES)", CRON)

    def test_admin_history_requires_privileged_session(self):
        self.assertIn("require_method('GET')", ADMIN)
        self.assertIn("require_role($user,'owner','admin')", ADMIN)
        self.assertIn("'publicStateWrites'=>0", ADMIN)

    def test_workflow_reports_history_without_enabling_automatic_publication(self):
        self.assertIn('.history.publicStateWrites == 0', WORKFLOW)
        self.assertIn('.stability.automaticPublicationEligible == false', WORKFLOW)
        self.assertIn('.stability.distinctExperimentalRuns', WORKFLOW)
        self.assertIn('Éligible au passage public contrôlé', WORKFLOW)
        self.assertIn('Publication automatique : `désactivée`', WORKFLOW)


if __name__ == '__main__':
    unittest.main()
