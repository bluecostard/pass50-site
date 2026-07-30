from pathlib import Path
import unittest

ROOT = Path(__file__).resolve().parents[1]
ENDPOINT = (ROOT / 'api' / 'metrics-ranking-publication-simulate-cron.php').read_text(encoding='utf-8')
WORKFLOW = (ROOT / '.github' / 'workflows' / 'metrics-ranking-publication-simulation.yml').read_text(encoding='utf-8')


class MetricsRankingPublicationSimulationCronV1Tests(unittest.TestCase):
    def test_endpoint_is_hmac_authenticated_and_strict(self):
        self.assertIn("$_SERVER['REQUEST_METHOD']!=='POST'", ENDPOINT)
        self.assertIn('HTTP_X_P50_TIMESTAMP', ENDPOINT)
        self.assertIn('HTTP_X_P50_SIGNATURE', ENDPOINT)
        self.assertIn('p50_mo_verify_cron_signature', ENDPOINT)
        self.assertIn("$keys!==['action','dispatchId','period']", ENDPOINT)
        self.assertIn("($input['action']??null)!=='simulate'", ENDPOINT)
        self.assertIn('array_key_exists($period,p50_mr_periods())', ENDPOINT)

    def test_endpoint_only_runs_read_only_simulation(self):
        self.assertIn('p50_mrp_simulate(db(),$period,100)', ENDPOINT)
        forbidden = (
            'INSERT INTO app_state',
            'UPDATE app_state',
            'DELETE FROM app_state',
            'REPLACE INTO app_state',
            'p50_de_save_public_state',
            'p50_de_save_state',
        )
        for token in forbidden:
            self.assertNotIn(token, ENDPOINT)

    def test_workflow_runs_after_experimental_cycle_and_can_be_dispatched(self):
        self.assertIn("cron: '12 1-23/2 * * *'", WORKFLOW)
        self.assertIn('workflow_dispatch:', WORKFLOW)
        self.assertIn('metrics-ranking-publication-simulate-cron.php', WORKFLOW)
        self.assertIn('PASS50_METRICS_CRON_URL', WORKFLOW)
        self.assertIn('PASS50_METRICS_CRON_SECRET', WORKFLOW)
        self.assertIn('openssl dgst -sha256 -hmac', WORKFLOW)

    def test_workflow_waits_for_ionos_deployment_on_main_push(self):
        self.assertIn('GITHUB_EVENT_NAME', WORKFLOW)
        self.assertIn('tentative $attempt/15', WORKFLOW)
        self.assertIn('sleep 20', WORKFLOW)
        self.assertIn('[ "$http_code" = "404" ]', WORKFLOW)
        self.assertIn('[ "$http_code" = "503" ]', WORKFLOW)

    def test_workflow_enforces_zero_public_writes(self):
        self.assertIn('.publication.publicationEnabled == false', WORKFLOW)
        self.assertIn('.publication.automaticPublicationEnabled == false', WORKFLOW)
        self.assertIn('.publication.appStateWriteAttempted == false', WORKFLOW)
        self.assertIn('.scope.publicStateWrites == 0', WORKFLOW)
        self.assertIn('Écritures app_state : `0`', WORKFLOW)

    def test_full_report_is_archived(self):
        self.assertIn('actions/upload-artifact@v4', WORKFLOW)
        self.assertIn('publication-simulation.json', WORKFLOW)
        self.assertIn('retention-days: 30', WORKFLOW)


if __name__ == '__main__':
    unittest.main()
