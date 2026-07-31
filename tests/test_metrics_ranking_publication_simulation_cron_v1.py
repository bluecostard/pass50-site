from pathlib import Path
import unittest

ROOT = Path(__file__).resolve().parents[1]
ENDPOINT = (ROOT / 'api' / 'metrics-ranking-publication-simulate-cron.php').read_text(encoding='utf-8')
WORKFLOW = (ROOT / '.github' / 'workflows' / 'metrics-ranking-publication-simulation.yml').read_text(encoding='utf-8')
DEPLOYMENT_WORKFLOW = (ROOT / '.github' / 'workflows' / 'metrics-publication-period-deployment.yml').read_text(encoding='utf-8')
PERIOD_CORE = (ROOT / 'api' / 'metrics-ranking-publication-period-core.php').read_text(encoding='utf-8')


class MetricsRankingPublicationSimulationCronV1Tests(unittest.TestCase):
    def test_endpoint_is_hmac_authenticated_and_strict(self):
        self.assertIn("$_SERVER['REQUEST_METHOD']!=='POST'", ENDPOINT)
        self.assertIn('HTTP_X_P50_TIMESTAMP', ENDPOINT)
        self.assertIn('HTTP_X_P50_SIGNATURE', ENDPOINT)
        self.assertIn('p50_mo_verify_cron_signature', ENDPOINT)
        self.assertIn("in_array($action,['probe','simulate'],true)", ENDPOINT)
        self.assertIn("$keys!==['action','dispatchId']", ENDPOINT)
        self.assertIn("$keys!==['action','dispatchId','period']", ENDPOINT)
        self.assertIn("$period!=='AUTO'&&!array_key_exists($period,p50_mr_periods())", ENDPOINT)

    def test_probe_is_read_only_and_exposes_exact_contracts(self):
        self.assertIn("if($action==='probe')", ENDPOINT)
        self.assertIn("'contract'=>P50_MRPA_PERIOD_SELECTION_VERSION", ENDPOINT)
        self.assertIn("'exitDiagnosticsContract'=>P50_MRPA_EXIT_DIAGNOSTICS_VERSION", ENDPOINT)
        self.assertIn("'readOnly'=>true,'publicStateWrites'=>0", ENDPOINT)
        probe_block = ENDPOINT[ENDPOINT.index("if($action==='probe')"):ENDPOINT.index("if($keys!==['action','dispatchId','period'])")]
        self.assertNotIn('db()', probe_block)
        self.assertNotIn('p50_mrph_store', probe_block)

    def test_endpoint_only_runs_read_only_simulation(self):
        self.assertIn('p50_mrpa_simulate($pdo,$period,100)', ENDPOINT)
        self.assertIn('p50_mrph_store($pdo,$result,$dispatchId)', ENDPOINT)
        self.assertIn("p50_mrph_stability($pdo,$selectedPeriod", ENDPOINT)
        forbidden = ('INSERT INTO app_state','UPDATE app_state','DELETE FROM app_state','REPLACE INTO app_state','p50_de_save_public_state','p50_de_save_state')
        for token in forbidden:self.assertNotIn(token, ENDPOINT + PERIOD_CORE)

    def test_period_selection_keeps_thresholds_and_falls_back_only_when_empty(self):
        self.assertIn("return ['2H','24H','48H','7J','15J']", PERIOD_CORE)
        self.assertIn('requested_period_classable', PERIOD_CORE)
        self.assertIn('requested_period_empty_fallback', PERIOD_CORE)
        self.assertIn('classable=1 AND rank_position IS NOT NULL AND score IS NOT NULL', PERIOD_CORE)
        self.assertIn("$selectedPeriod=(string)$selection['selectedPeriod']", PERIOD_CORE)
        self.assertIn('p50_mrp_simulate($pdo,$selectedPeriod', PERIOD_CORE)
        self.assertIn('p50_mrp_experimental_rows($pdo,$selectedPeriod)', PERIOD_CORE)
        self.assertNotIn('coverage_below_45', PERIOD_CORE)
        self.assertNotIn('confidence_below_55', PERIOD_CORE)

    def test_deployment_workflow_waits_for_both_contracts_before_dispatch(self):
        self.assertIn("'{action:\"probe\",dispatchId:$dispatchId}'", DEPLOYMENT_WORKFLOW)
        self.assertIn('.contract == "PUBSIM-PERIOD-V1.0"', DEPLOYMENT_WORKFLOW)
        self.assertIn('.exitDiagnosticsContract == "PUBSIM-EXIT-DIAG-V1.0"', DEPLOYMENT_WORKFLOW)
        self.assertIn('.readOnly == true', DEPLOYMENT_WORKFLOW)
        self.assertIn('.publicStateWrites == 0', DEPLOYMENT_WORKFLOW)
        self.assertIn('tentative $attempt/30', DEPLOYMENT_WORKFLOW)
        self.assertIn('metrics-ranking-publication-simulation.yml/dispatches', DEPLOYMENT_WORKFLOW)
        self.assertIn('pass50/publication-period-deployment', DEPLOYMENT_WORKFLOW)

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

    def test_workflow_normalizes_transport_failures(self):
        self.assertIn('set +e', WORKFLOW)
        self.assertIn('curl_status=$?', WORKFLOW)
        self.assertIn('if [ "$curl_status" -ne 0 ]', WORKFLOW)
        self.assertIn('http_code="000"', WORKFLOW)

    def test_workflow_enforces_zero_public_writes(self):
        self.assertIn('.publication.publicationEnabled == false', WORKFLOW)
        self.assertIn('.publication.automaticPublicationEnabled == false', WORKFLOW)
        self.assertIn('.publication.appStateWriteAttempted == false', WORKFLOW)
        self.assertIn('.scope.publicStateWrites == 0', WORKFLOW)
        self.assertIn('.history.publicStateWrites == 0', WORKFLOW)
        self.assertIn('Écritures app_state : `0`', WORKFLOW)

    def test_workflow_publishes_only_sanitized_commit_status(self):
        self.assertIn('statuses: write', WORKFLOW)
        self.assertIn('id: simulation', WORKFLOW)
        self.assertIn('if: ${{ always() }}', WORKFLOW)
        self.assertIn('pass50/publication-simulation', WORKFLOW)
        self.assertIn('audit #${audit_id}', WORKFLOW)
        self.assertIn('app_state 0', WORKFLOW)
        self.assertIn('steps.simulation.outputs.distinct_runs', WORKFLOW)
        self.assertIn('/statuses/${GITHUB_SHA}', WORKFLOW)

    def test_full_report_is_archived(self):
        self.assertIn('actions/upload-artifact@v4', WORKFLOW)
        self.assertIn('publication-simulation.json', WORKFLOW)
        self.assertIn('retention-days: 30', WORKFLOW)


if __name__ == '__main__':unittest.main()
